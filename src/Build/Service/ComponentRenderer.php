<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

use Fastsite\Component\Dto\Component;
use Fastsite\Component\Repository\ComponentRepository;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Expands `<div data-fs-component="handle" data-values='{…}'></div>`
 * placeholders in body HTML into STATIC markup at build time:
 *  - html components: {{var}} (escaped) / {{{var}}} (raw) substitution
 *  - react components: server-side render via a batched Node child
 *
 * Every render is cached by sha256(bundle_hash + values + locale), so a
 * component reused across pages/locales renders at most once per distinct
 * (values, locale). The component's CSS is collected for the page head.
 * No client JS is ever emitted.
 */
final class ComponentRenderer
{
    private const PLACEHOLDER = '/<div\b[^>]*\bdata-fs-component="([a-z0-9-]+)"[^>]*><\/div>/i';

    public function __construct(
        private readonly ComponentRepository $repository,
        private readonly SiteDatabaseManager $databases,
        private readonly LoggerInterface $logger,
        private readonly string $appRoot,
    ) {
    }

    /**
     * @return array{html: string, css: string, warnings: list<string>}
     */
    public function expand(string $site, string $html, string $locale): array
    {
        if (stripos($html, 'data-fs-component') === false) {
            return ['html' => $html, 'css' => '', 'warnings' => []];
        }

        // Pass 1: collect placeholders in order.
        $placeholders = [];
        if (preg_match_all(self::PLACEHOLDER, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $i => $m) {
                $placeholders[] = [
                    'full' => $m[0][0],
                    'handle' => $m[1][0],
                    'values' => $this->extractValues($m[0][0]),
                    'index' => $i,
                ];
            }
        }
        if ($placeholders === []) {
            return ['html' => $html, 'css' => '', 'warnings' => []];
        }

        $components = $this->repository->loadMany($site, array_values(array_unique(array_column($placeholders, 'handle'))));

        $warnings = [];
        $cssParts = [];
        $rendered = [];      // index => html
        $reactBatch = [];    // id => {bundlePath, props, cacheKey, componentId}

        foreach ($placeholders as $p) {
            $component = $components[$p['handle']] ?? null;
            if ($component === null || $component->status !== 'ready') {
                $warnings[] = 'Unknown or unbuilt component: ' . $p['handle'];
                $rendered[$p['index']] = '';
                continue;
            }
            if ($component->css !== '') {
                $cssParts[$component->id] = $component->css;
            }

            $cacheKey = hash('sha256', $component->bundleHash . "\x1f" . json_encode($p['values']) . "\x1f" . $locale);
            $cached = $this->repository->cachedRender($site, $cacheKey);
            if ($cached !== null) {
                $rendered[$p['index']] = $cached;
                continue;
            }

            if ($component->type === 'html') {
                $out = $this->renderHtml($component, $p['values']);
                $this->repository->storeRender($site, $cacheKey, $component->id, $out);
                $rendered[$p['index']] = $out;
            } else {
                $reactBatch[$p['index']] = [
                    'bundlePath' => $this->databases->sitePath($site) . '/components/' . $component->handle . '.cjs',
                    'props' => $p['values'],
                    'cacheKey' => $cacheKey,
                    'componentId' => $component->id,
                ];
            }
        }

        // Pass 2: batch-render the uncached React components in one Node call.
        if ($reactBatch !== []) {
            $results = $this->renderReactBatch($site, $reactBatch);
            foreach ($reactBatch as $index => $req) {
                if (isset($results[$index]['html'])) {
                    $out = (string) $results[$index]['html'];
                    $this->repository->storeRender($site, $req['cacheKey'], $req['componentId'], $out);
                    $rendered[$index] = $out;
                } else {
                    $warnings[] = 'Component render failed (' . $placeholders[$index]['handle'] . '): ' . (string) ($results[$index]['error'] ?? 'unknown');
                    $rendered[$index] = '';
                }
            }
        }

        // Replace each placeholder (left to right) with its rendered output.
        // Track offset so identical placeholders don't double-replace.
        $out = $html;
        $offset = 0;
        foreach ($placeholders as $p) {
            $replacement = $this->stripActiveContent($rendered[$p['index']] ?? '', $warnings);
            $pos = strpos($out, $p['full'], $offset);
            if ($pos !== false) {
                $out = substr_replace($out, $replacement, $pos, \strlen($p['full']));
                $offset = $pos + \strlen($replacement);
            }
        }

        return ['html' => $out, 'css' => implode("\n", $cssParts), 'warnings' => $warnings];
    }

    /**
     * Enforces the zero-JS invariant on component output: strips any
     * <script> (inline OR src), iframe/object/embed, on* event handlers, and
     * javascript: URLs. A component that tried to ship active content is
     * neutralised with a warning rather than wedging the build (PageAudit
     * FAILs on <script>) or shipping JS.
     *
     * @param list<string> $warnings
     */
    private function stripActiveContent(string $html, array &$warnings): string
    {
        $before = $html;
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
        $html = preg_replace('#<script\b[^>]*/?>#i', '', $html) ?? $html;
        $html = preg_replace('#<(?:iframe|object|embed)\b[^>]*>.*?</(?:iframe|object|embed)>#is', '', $html) ?? $html;
        $html = preg_replace('#<(?:iframe|object|embed)\b[^>]*/?>#i', '', $html) ?? $html;
        $html = preg_replace('/\son[a-z]+\s*=\s*"[^"]*"/i', '', $html) ?? $html;
        $html = preg_replace("/\son[a-z]+\s*=\s*'[^']*'/i", '', $html) ?? $html;
        $html = preg_replace('/\shref\s*=\s*(["\'])\s*javascript:[^"\']*\1/i', ' href="#"', $html) ?? $html;

        if ($html !== $before) {
            $warnings[] = 'A component emitted active content (script/iframe/event handler) — stripped to keep the output static.';
        }

        return $html;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function renderHtml(Component $component, array $values): string
    {
        // {{{var}}} = raw, {{var}} = HTML-escaped. Unknown vars become empty.
        $out = preg_replace_callback('/\{\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}\}/', static function (array $m) use ($values): string {
            return (string) ($values[$m[1]] ?? '');
        }, $component->sourceCode) ?? $component->sourceCode;

        return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/', static function (array $m) use ($values): string {
            return htmlspecialchars((string) ($values[$m[1]] ?? ''), ENT_QUOTES);
        }, $out) ?? $out;
    }

    /**
     * @param array<int, array<string, mixed>> $batch
     * @return array<int, array<string, mixed>>
     */
    private function renderReactBatch(string $site, array $batch): array
    {
        $renders = [];
        foreach ($batch as $index => $req) {
            $renders[] = ['id' => $index, 'bundlePath' => $req['bundlePath'], 'props' => $req['props']];
        }

        $jobFile = tempnam(sys_get_temp_dir(), 'fsrender');
        file_put_contents($jobFile, json_encode(['renders' => $renders], JSON_THROW_ON_ERROR));

        try {
            // Defense in depth for the render sandbox:
            //  - env=[] : no secrets reach the render.
            //  - --permission --allow-fs-read : Node denies child_process,
            //    fs-write, and worker_threads by default, so even if the
            //    upload lint is bypassed a component's render() cannot spawn
            //    a shell or write files on the build host. (POSIX only — the
            //    permission model's glob path-matching misbehaves with
            //    Windows drive-letter paths, and prod runs on alpine.)
            //  - 60s wall-clock : a runaway render can't hang the worker.
            // (__proto__ pollution is blocked at the upload lint instead —
            // --disable-proto=throw would break react-dom, which reads
            // Object.prototype.__proto__ internally.)
            $flags = [];
            if (\PHP_OS_FAMILY !== 'Windows') {
                $flags = ['--permission', '--allow-fs-read=*'];
            }
            $process = new Process([
                'node',
                ...$flags,
                $this->appRoot . '/bin/render-component.mjs',
                $jobFile,
            ], $this->appRoot, [], null, 60);
            $process->run();
            $decoded = json_decode($process->getOutput(), true);

            $out = [];
            if (\is_array($decoded) && isset($decoded['results']) && \is_array($decoded['results'])) {
                foreach ($decoded['results'] as $r) {
                    $out[(int) $r['id']] = $r;
                }
            } else {
                $this->logger->error('Component batch render failed', ['site' => $site, 'stderr' => $process->getErrorOutput()]);
            }

            return $out;
        } finally {
            @unlink($jobFile);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractValues(string $tag): array
    {
        if (preg_match('/\bdata-values="([^"]*)"/i', $tag, $m) !== 1) {
            return [];
        }
        $decoded = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);

        return \is_array($decoded) ? $decoded : [];
    }
}
