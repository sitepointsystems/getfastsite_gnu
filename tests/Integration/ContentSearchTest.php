<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Free-text search behind the Content screen's search field.
 *
 * The interesting part is the LIKE escaping: the needle is user input that
 * lands in a pattern language, so `%`, `_` and `\` in a title or slug must
 * search for THEMSELVES. Unescaped, a slug containing `%` matches every row
 * and the field silently stops filtering.
 */
final class ContentSearchTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private ContentService $content;
    private DbalContentRepository $repo;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-cs-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->repo = new DbalContentRepository($this->databases, $clock);
        $this->sites = new SiteService(new DbalSiteRepository($this->databases, $clock), $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->content = new ContentService($this->repo, new ContentSanitizer(), $clock, new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock));
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (!is_dir($this->dataPath)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->dataPath);
    }

    /** @return list<string> */
    private function slugs(array $result): array
    {
        return array_map(static fn ($c) => $c->slug, $result['items']);
    }

    public function testSearchMatchesTitleSlugExcerptSeoAndBody(): void
    {
        $site = $this->sites->create('searchy', 'Searchy', 'https://searchy.example.com');

        $this->content->create($site, ['type' => 'page', 'title' => 'About the harbour', 'slug' => 'about', 'body_html' => '<p>Nothing special.</p>', 'status' => 'published']);
        $this->content->create($site, ['type' => 'page', 'title' => 'Contact', 'slug' => 'contact', 'excerpt' => 'Reach the harbour office', 'body_html' => '<p>Hi.</p>', 'status' => 'published']);
        $this->content->create($site, ['type' => 'page', 'title' => 'Pricing', 'slug' => 'pricing', 'body_html' => '<p>Berths at the harbour cost money.</p>', 'status' => 'published']);
        $this->content->create($site, ['type' => 'page', 'title' => 'Terms', 'slug' => 'terms', 'seo_description' => 'Harbour rules', 'body_html' => '<p>Legal.</p>', 'status' => 'published']);
        $this->content->create($site, ['type' => 'page', 'title' => 'Unrelated', 'slug' => 'unrelated', 'body_html' => '<p>Nothing.</p>', 'status' => 'published']);

        $found = $this->slugs($this->repo->listFiltered('searchy', 'page', null, 1, 25, 'harbour'));
        sort($found);
        self::assertSame(['about', 'contact', 'pricing', 'terms'], $found, 'search must cover title, excerpt, body and the SEO overrides');

        // Case-insensitive, and the total is the FILTERED count (the pager
        // would otherwise offer pages of results that do not exist).
        $result = $this->repo->listFiltered('searchy', 'page', null, 1, 25, 'HARBOUR');
        self::assertSame(4, $result['total']);

        self::assertSame(['unrelated'], $this->slugs($this->repo->listFiltered('searchy', 'page', null, 1, 25, 'unrelated')));
        self::assertSame([], $this->slugs($this->repo->listFiltered('searchy', 'page', null, 1, 25, 'nothing-matches-this')));
    }

    public function testSearchIsScopedToTypeAndSkippedWhenBlank(): void
    {
        $site = $this->sites->create('scoped', 'Scoped', 'https://scoped.example.com');

        $this->content->create($site, ['type' => 'post', 'title' => 'Launch notes', 'slug' => 'launch-notes', 'body_html' => '<p>x</p>', 'status' => 'published']);
        $this->content->create($site, ['type' => 'page', 'title' => 'Launch', 'slug' => 'launch', 'body_html' => '<p>x</p>', 'status' => 'published']);

        self::assertSame(['launch-notes'], $this->slugs($this->repo->listFiltered('scoped', 'post', null, 1, 25, 'launch')));
        self::assertSame(['launch'], $this->slugs($this->repo->listFiltered('scoped', 'page', null, 1, 25, 'launch')));

        // Blank/whitespace-only needles must not filter anything out.
        self::assertCount(1, $this->repo->listFiltered('scoped', 'page', null, 1, 25, '   ')['items']);
        self::assertCount(1, $this->repo->listFiltered('scoped', 'page', null, 1, 25, null)['items']);
    }

    public function testLikeWildcardsInTheNeedleAreLiteral(): void
    {
        $site = $this->sites->create('wildcard', 'Wildcard', 'https://wc.example.com');

        $this->content->create($site, ['type' => 'page', 'title' => 'Save 50% today', 'slug' => 'save-50', 'body_html' => '<p>x</p>', 'status' => 'published']);
        $this->content->create($site, ['type' => 'page', 'title' => 'Plain page', 'slug' => 'plain', 'body_html' => '<p>x</p>', 'status' => 'published']);

        // Unescaped, '%' is "match anything" and would return BOTH rows.
        self::assertSame(['save-50'], $this->slugs($this->repo->listFiltered('wildcard', 'page', null, 1, 25, '50%')));
        // '_' is LIKE's single-character wildcard — "p_ain" must not find "plain".
        self::assertSame([], $this->slugs($this->repo->listFiltered('wildcard', 'page', null, 1, 25, 'p_ain')));
        self::assertSame(['plain'], $this->slugs($this->repo->listFiltered('wildcard', 'page', null, 1, 25, 'plain')));
    }

    public function testTrashListingIsSearchableToo(): void
    {
        $site = $this->sites->create('trashy', 'Trashy', 'https://trashy.example.com');

        $keep = $this->content->create($site, ['type' => 'page', 'title' => 'Old campaign', 'slug' => 'old-campaign', 'body_html' => '<p>x</p>', 'status' => 'published']);
        $other = $this->content->create($site, ['type' => 'page', 'title' => 'Retired notice', 'slug' => 'retired', 'body_html' => '<p>x</p>', 'status' => 'published']);
        $this->repo->softDelete('trashy', $keep->id);
        $this->repo->softDelete('trashy', $other->id);

        $matched = array_map(static fn ($c) => $c->slug, $this->repo->listTrashed('trashy', 'page', 'campaign'));
        self::assertSame(['old-campaign'], $matched);
        self::assertCount(2, $this->repo->listTrashed('trashy', 'page'));
    }
}
