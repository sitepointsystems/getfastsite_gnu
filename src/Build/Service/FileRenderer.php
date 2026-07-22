<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

use Fastsite\Media\Repository\MediaRepository;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Files-library embedding at build time. Two authoring forms:
 *
 *  - `{{ filename }}` — the unified embed. Resolves the name against the files
 *    library (owner-curated, trusted) and dispatches by type:
 *      • .html/.htm → the file is inlined VERBATIM (NOT sanitized — it may be
 *        a calculator/widget that ships its own <script>/<style>). Wrapped in
 *        <div data-fs-embed> so PageAudit exempts its scripts.
 *      • .js/.mjs   → loaded via <script src="/files/…"> (also data-fs-embed).
 *      • anything else (pdf, zip, docx, jsx, …) → a crawlable download link.
 *
 *  - `<a data-file-id="N">label</a>` — an explicit download link to
 *    /files/{id}/{slug}.{ext}; a dangling ref unwraps to its label.
 *
 * Both return the file ids that resolved so the build copies exactly the
 * referenced originals into /files/ (referenced-only, like the media pipeline).
 */
final class FileRenderer
{
    private const REF = '/<a\b([^>]*?)\bdata-file-id="(\d+)"([^>]*)>(.*?)<\/a>/is';
    private const EMBED = '/\{\{\s*([^{}\r\n]+?)\s*\}\}/';

    /** @var array<string, string> in-process cache of compiled bundles by file sha */
    private array $clientBundles = [];

    public function __construct(
        private readonly MediaRepository $repository,
        private readonly SiteDatabaseManager $databases,
        private readonly LoggerInterface $logger,
        private readonly string $appRoot,
    ) {
    }

    /**
     * Expands `{{ filename }}` embeds against the files library.
     *
     * @return array{html: string, file_ids: list<int>}
     */
    public function expandEmbeds(string $site, string $html): array
    {
        if (!str_contains($html, '{{')) {
            return ['html' => $html, 'file_ids' => []];
        }

        $used = [];
        $out = preg_replace_callback(self::EMBED, function (array $m) use ($site, &$used): string {
            $ref = trim($m[1]);
            $file = $this->repository->findFileByRef($site, $ref);
            if ($file === null) {
                return ''; // unknown reference — drop it
            }
            $ext = strtolower($file->ext);

            // Trusted HTML widget (calculator, etc.) — inline VERBATIM.
            if (\in_array($ext, ['html', 'htm'], true)) {
                $path = $this->databases->sitePath($site) . '/' . $file->diskPath;
                $content = is_file($path) ? (string) file_get_contents($path) : '';

                return '<div data-fs-embed>' . $content . '</div>';
            }

            // JavaScript — load the emitted file as a script.
            if (\in_array($ext, ['js', 'mjs'], true)) {
                $used[$file->id] = true;

                return '<div data-fs-embed><script src="' . $this->publicPath($file->id, $file->slug, $file->ext) . '"></script></div>';
            }

            // React component (jsx/tsx) — compile to a browser bundle and mount
            // it interactively. On any compile error, fall through to a link.
            if (\in_array($ext, ['jsx', 'tsx'], true)) {
                $path = $this->databases->sitePath($site) . '/' . $file->diskPath;
                $source = is_file($path) ? (string) file_get_contents($path) : '';
                $bundle = $source !== '' ? $this->compileClient($file->sha256, $source) : null;
                if ($bundle !== null) {
                    return '<div data-fs-embed><div class="fs-widget"></div><script>' . $bundle . '</script></div>';
                }
                // else: fall through to a download link
            }

            // Everything else — a crawlable download link.
            $used[$file->id] = true;

            return sprintf(
                '<a href="%s" download>%s</a>',
                $this->publicPath($file->id, $file->slug, $file->ext),
                htmlspecialchars($file->originalName, ENT_QUOTES),
            );
        }, $html) ?? $html;

        return ['html' => $out, 'file_ids' => array_map('intval', array_keys($used))];
    }

    /**
     * @return array{html: string, file_ids: list<int>}
     */
    public function expandBody(string $site, string $html): array
    {
        if (stripos($html, 'data-file-id') === false) {
            return ['html' => $html, 'file_ids' => []];
        }

        $ids = $this->collectIds($html);
        if ($ids === []) {
            return ['html' => $html, 'file_ids' => []];
        }

        $media = $this->repository->loadManyWithVariants($site, $ids);

        $used = [];
        $out = preg_replace_callback(
            self::REF,
            function (array $m) use ($media, &$used): string {
                $id = (int) $m[2];
                $item = $media[$id] ?? null;
                $inner = $m[4];
                if ($item === null || $item->kind !== 'file' || $item->status !== 'ready') {
                    return $inner; // dangling — unwrap to the label, ship no broken link
                }
                $used[$id] = true;
                $attrs = $this->cleanAttrs($m[1] . $m[3]);

                return sprintf(
                    '<a href="%s" download%s>%s</a>',
                    $this->publicPath($item->id, $item->slug, $item->ext),
                    $attrs === '' ? '' : ' ' . $attrs,
                    $inner,
                );
            },
            $html,
        ) ?? $html;

        return ['html' => $out, 'file_ids' => array_map('intval', array_keys($used))];
    }

    /**
     * Ready file-kind originals to copy into dist, keyed by id.
     *
     * @param list<int> $ids
     * @return array<int, array{slug: string, ext: string, diskPath: string}>
     */
    public function filesForCopy(string $site, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach ($this->repository->loadManyWithVariants($site, $ids) as $id => $item) {
            if ($item->kind === 'file' && $item->status === 'ready') {
                $out[$id] = ['slug' => $item->slug, 'ext' => $item->ext, 'diskPath' => $item->diskPath];
            }
        }

        return $out;
    }

    public function publicPath(int $id, string $slug, string $ext): string
    {
        return '/files/' . $id . '/' . rawurlencode($slug) . '.' . $ext;
    }

    /**
     * Compiles a jsx/tsx source into a self-contained browser IIFE (esbuild,
     * react-dom/client). Cached in-process by file sha so a widget reused
     * across pages compiles once. Returns null on any failure (caller links).
     */
    private function compileClient(string $sha, string $source): ?string
    {
        if (isset($this->clientBundles[$sha])) {
            return $this->clientBundles[$sha];
        }

        $jobFile = tempnam(sys_get_temp_dir(), 'fsclient');
        file_put_contents($jobFile, json_encode(['source' => $source], JSON_THROW_ON_ERROR));

        try {
            $process = new Process(['node', $this->appRoot . '/bin/compile-file-client.mjs', $jobFile], $this->appRoot, [], null, 60);
            $process->run();
            $result = json_decode($process->getOutput(), true);

            if (!\is_array($result) || ($result['ok'] ?? false) !== true || !isset($result['code'])) {
                $error = \is_array($result) ? (string) ($result['error'] ?? 'unknown') : trim($process->getErrorOutput());
                $this->logger->warning('File embed: jsx/tsx compile failed — falling back to a download link', ['error' => $error]);

                return null;
            }

            return $this->clientBundles[$sha] = (string) $result['code'];
        } finally {
            @unlink($jobFile);
        }
    }

    /** Strip data-file-id and any author href; keep class/rel/title/etc. */
    private function cleanAttrs(string $attrs): string
    {
        $attrs = preg_replace('/\sdata-file-id="\d+"/i', '', $attrs) ?? $attrs;
        $attrs = preg_replace('/\shref\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $attrs) ?? $attrs;
        $attrs = preg_replace('/\sdownload(\s*=\s*("[^"]*"|\'[^\']*\'))?/i', '', $attrs) ?? $attrs;

        return trim($attrs);
    }

    /**
     * @return list<int>
     */
    private function collectIds(string $html): array
    {
        if (preg_match_all('/\bdata-file-id="(\d+)"/i', $html, $m) === false) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $m[1])));
    }
}
