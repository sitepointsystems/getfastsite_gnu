<?php

declare(strict_types=1);

namespace Fastsite\Tests\Build;

use Fastsite\Build\Service\FileRenderer;
use Fastsite\Media\Dto\Media;
use Fastsite\Media\Repository\MediaRepository;
use Fastsite\Shared\Database\SiteDatabaseManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * FileRenderer expands <a data-file-id> download links and {{ filename }}
 * embeds. Pure — an in-memory repo stub, no DB or Node (the DB manager is only
 * touched by html-file inlining, which the standalone harness covers).
 */
final class FileRendererTest extends TestCase
{
    private function db(): SiteDatabaseManager
    {
        return new SiteDatabaseManager(sys_get_temp_dir() . '/fastsite-fr-' . bin2hex(random_bytes(4)));
    }

    private function renderer(MediaRepository $repo): FileRenderer
    {
        return new FileRenderer($repo, $this->db(), new NullLogger(), \dirname(__DIR__, 2));
    }

    private function repo(Media ...$items): MediaRepository
    {
        $byId = [];
        foreach ($items as $m) {
            $byId[$m->id] = $m;
        }

        return new class ($byId) implements MediaRepository {
            /** @param array<int, Media> $byId */
            public function __construct(private array $byId)
            {
            }

            public function loadManyWithVariants(string $site, array $ids): array
            {
                $out = [];
                foreach ($ids as $id) {
                    if (isset($this->byId[$id])) {
                        $out[$id] = $this->byId[$id];
                    }
                }

                return $out;
            }

            public function findById(string $site, int $id): ?Media
            {
                return $this->byId[$id] ?? null;
            }

            public function findBySha(string $site, string $sha256): ?Media
            {
                return null;
            }

            public function findFileByRef(string $site, string $ref): ?Media
            {
                foreach ($this->byId as $m) {
                    if ($m->kind === 'file' && $m->status === 'ready'
                        && ($m->originalName === $ref || ($m->slug . '.' . $m->ext) === $ref || $m->slug === $ref)) {
                        return $m;
                    }
                }

                return null;
            }

            public function list(string $site, int $page, int $perPage, ?array $kinds = null): array
            {
                return ['items' => array_values($this->byId), 'total' => \count($this->byId)];
            }

            public function listTrashed(string $site, int $page, int $perPage, ?array $kinds = null): array
            {
                return ['items' => [], 'total' => 0];
            }

            public function findAnyById(string $site, int $id): ?Media
            {
                return $this->byId[$id] ?? null;
            }

            public function softDelete(string $site, int $id): void
            {
            }

            public function restore(string $site, int $id): void
            {
            }

            public function insert(string $site, array $fields): int
            {
                return 0;
            }

            public function update(string $site, int $id, array $fields): void
            {
            }

            public function delete(string $site, int $id): void
            {
            }

            public function replaceVariants(string $site, int $mediaId, iterable $variants): void
            {
            }
        };
    }

    private function file(int $id, string $slug, string $ext, string $status = 'ready'): Media
    {
        return new Media(
            id: $id,
            kind: 'file',
            originalName: $slug . '.' . $ext,
            slug: $slug,
            ext: $ext,
            mime: 'application/pdf',
            sha256: str_repeat('0', 64),
            bytes: 1234,
            width: null,
            height: null,
            alt: '',
            decorative: false,
            storage: 'git',
            diskPath: 'media/originals/2026/07/' . $id . '-' . $slug . '.' . $ext,
            status: $status,
        );
    }

    public function testExpandsFileLinkToDownloadHref(): void
    {
        $renderer = $this->renderer($this->repo($this->file(7, 'datasheet', 'pdf')));

        $result = $renderer->expandBody('s', '<p><a data-file-id="7" class="btn">Get the datasheet</a></p>');

        self::assertStringContainsString('href="/files/7/datasheet.pdf"', $result['html']);
        self::assertStringContainsString('download', $result['html']);
        self::assertStringContainsString('class="btn"', $result['html']);
        self::assertStringContainsString('Get the datasheet', $result['html']);
        self::assertStringNotContainsString('data-file-id', $result['html']);
        self::assertSame([7], $result['file_ids']);
    }

    public function testDanglingReferenceUnwrapsToLabel(): void
    {
        // Missing file → no broken/href-less link ships; the label survives.
        $renderer = $this->renderer($this->repo());

        $result = $renderer->expandBody('s', '<p><a data-file-id="99">Missing file</a></p>');

        self::assertStringNotContainsString('<a', $result['html']);
        self::assertStringContainsString('Missing file', $result['html']);
        self::assertSame([], $result['file_ids']);
    }

    public function testNotReadyFileIsDropped(): void
    {
        $renderer = $this->renderer($this->repo($this->file(3, 'wip', 'zip', 'pending')));

        $result = $renderer->expandBody('s', '<a data-file-id="3">soon</a>');

        self::assertStringNotContainsString('<a', $result['html']);
        self::assertSame([], $result['file_ids']);
    }

    public function testFilesForCopyReturnsReadyFilesOnly(): void
    {
        $renderer = $this->renderer($this->repo(
            $this->file(1, 'a', 'pdf'),
            $this->file(2, 'b', 'zip', 'pending'),
        ));

        $map = $renderer->filesForCopy('s', [1, 2]);

        self::assertArrayHasKey(1, $map);
        self::assertArrayNotHasKey(2, $map);
        self::assertSame('a', $map[1]['slug']);
        self::assertSame('pdf', $map[1]['ext']);
    }

    public function testNoFileRefsIsANoOp(): void
    {
        $renderer = $this->renderer($this->repo());
        $html = '<p>plain content</p>';

        $result = $renderer->expandBody('s', $html);

        self::assertSame($html, $result['html']);
        self::assertSame([], $result['file_ids']);
    }

    private function fileNamed(int $id, string $slug, string $ext): Media
    {
        return new Media(
            id: $id, kind: 'file', originalName: $slug . '.' . $ext, slug: $slug, ext: $ext,
            mime: 'application/octet-stream', sha256: str_repeat('0', 64), bytes: 10,
            width: null, height: null, alt: '', decorative: false, storage: 'git',
            diskPath: 'media/originals/2026/07/' . $id . '-' . $slug . '.' . $ext, status: 'ready',
        );
    }

    public function testEmbedJsBecomesScriptTag(): void
    {
        $renderer = $this->renderer($this->repo($this->fileNamed(4, 'calc', 'js')));

        $result = $renderer->expandEmbeds('s', '<p>{{ calc.js }}</p>');

        self::assertStringContainsString('data-fs-embed', $result['html']);
        self::assertStringContainsString('<script src="/files/4/calc.js"></script>', $result['html']);
        self::assertSame([4], $result['file_ids']);
    }

    public function testEmbedNonWebBecomesDownloadLink(): void
    {
        $renderer = $this->renderer($this->repo($this->fileNamed(5, 'guide', 'pdf')));

        $result = $renderer->expandEmbeds('s', 'See {{ guide.pdf }} for details.');

        self::assertStringContainsString('<a href="/files/5/guide.pdf" download>guide.pdf</a>', $result['html']);
        self::assertSame([5], $result['file_ids']);
    }

    public function testEmbedUnknownRefIsDropped(): void
    {
        $renderer = $this->renderer($this->repo());

        $result = $renderer->expandEmbeds('s', 'x {{ missing.html }} y');

        self::assertStringNotContainsString('missing', $result['html']);
        self::assertSame([], $result['file_ids']);
    }

    public function testEmbedResolvesBySlugOrFilename(): void
    {
        $renderer = $this->renderer($this->repo($this->fileNamed(6, 'promo', 'pdf')));

        self::assertSame([6], $renderer->expandEmbeds('s', '{{ promo }}')['file_ids']);
        self::assertSame([6], $renderer->expandEmbeds('s', '{{ promo.pdf }}')['file_ids']);
    }
}
