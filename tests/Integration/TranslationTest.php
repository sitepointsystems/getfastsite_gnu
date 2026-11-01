<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\BuildService;
use Fastsite\Build\Service\MediaRenderer;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use Fastsite\Ai\AiClientFactory;
use Fastsite\FactCheck\FactChecker;
use Fastsite\FactCheck\NullFactChecker;
use Fastsite\Translation\Repository\DbalTranslationRepository;
use Fastsite\Translation\Service\TranslationService;
use Fastsite\Translation\Translator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * i18n: translation staleness/manual/missing rules + the per-locale build
 * with hreflang. Uses a deterministic fake translator (no network).
 */
final class TranslationTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private ContentService $content;
    private TranslationService $translations;
    private DbalTranslationRepository $transRepo;
    private BuildService $build;
    private DbalSiteRepository $siteRepo;
    private Site $site;
    private FakeTranslator $translator;
    private SiteService $sites;
    private DbalContentRepository $contentRepo;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-i18n-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->siteRepo = new DbalSiteRepository($this->databases, $clock);
        $sites = new SiteService($this->siteRepo, $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->sites = $sites;
        $sites->create('i18ntest', 'i18n Test', 'https://i.example.com');
        $sites->addLocale('i18ntest', 'da');

        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $this->contentRepo = $contentRepo;
        $this->transRepo = new DbalTranslationRepository($this->databases, $clock);
        $this->translator = new FakeTranslator();
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock, new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock));
        $this->translations = new TranslationService($contentRepo, $this->transRepo, new FakeAiClientFactory($this->translator), new ContentSanitizer(), $this->databases, $clock, new NullLogger(), new \Fastsite\Translation\TranslationLog($this->databases, $clock), rateLimitPauseSeconds: 0);
        $componentRenderer = new \Fastsite\Build\Service\ComponentRenderer(new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock), $this->databases, new NullLogger(), \dirname(__DIR__, 2));
        $mediaRepo = new DbalMediaRepository($this->databases, $clock);
        $this->build = new BuildService($contentRepo, $this->databases, $clock, new NullLogger(), new MediaRenderer($mediaRepo), $this->transRepo, $componentRenderer, new \Fastsite\Build\Figlet\FigletRenderer(), new \Fastsite\Build\Service\FileRenderer($mediaRepo, $this->databases, new NullLogger(), $appRoot), $mediaRepo, new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock), new \Fastsite\Build\Service\HtaccessBuilder(), new \Fastsite\Component\Service\ComponentHookService($this->databases, $clock, new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock)), new \Fastsite\Form\Service\FormRenderer(new \Fastsite\Form\Service\FormService($this->databases, $clock), "https://engine.test"));

        $site = $this->siteRepo->findByHandle('i18ntest');
        \assert($site !== null);
        $this->site = $site;
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (is_dir($this->dataPath)) {
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
    }

    private function post(string $title, string $body): int
    {
        return $this->content->create($this->site, ['type' => 'post', 'title' => $title, 'body_html' => $body, 'status' => 'published'])->id;
    }

    public function testTranslatesMissingThenSkipsWhenCurrent(): void
    {
        $this->post('Hello world', '<p>Body.</p>');

        $first = $this->translations->syncSite($this->site);
        self::assertSame(1, $first['translated']);

        // Nothing changed → skipped, no new translation calls.
        $callsBefore = $this->translator->calls;
        $second = $this->translations->syncSite($this->site);
        self::assertSame(1, $second['skipped']);
        self::assertSame(0, $second['translated']);
        self::assertSame($callsBefore, $this->translator->calls);
    }

    public function testEditingSourceRetranslatesOnlyThatItem(): void
    {
        $a = $this->post('Alpha', '<p>A.</p>');
        $this->post('Beta', '<p>B.</p>');
        $this->translations->syncSite($this->site);

        $callsBefore = $this->translator->calls;
        // Edit only Alpha → its source_hash changes, Beta stays current.
        $this->content->update($this->site, $a, ['title' => 'Alpha edited']);

        $result = $this->translations->syncSite($this->site);
        self::assertSame(1, $result['translated']); // only Alpha
        self::assertSame(1, $result['skipped']);    // Beta
        self::assertGreaterThan($callsBefore, $this->translator->calls);
    }

    public function testMaxItemsChunksAndResumesViaStaleness(): void
    {
        $this->post('One', '<p>1</p>');
        $this->post('Two', '<p>2</p>');
        $this->post('Three', '<p>3</p>');

        // Budget of 2 attempted items → stops early, reports incomplete.
        $first = $this->translations->syncSite($this->site, null, 2);
        self::assertFalse($first['complete']);
        self::assertSame(2, $first['translated']);

        // The follow-up chunk skips the finished items (free) and completes.
        $second = $this->translations->syncSite($this->site, null, 2);
        self::assertTrue($second['complete']);
        self::assertSame(1, $second['translated']);
        self::assertSame(2, $second['skipped']);

        // An uncapped run over a current site is a complete no-op pass.
        $third = $this->translations->syncSite($this->site);
        self::assertTrue($third['complete']);
        self::assertSame(3, $third['skipped']);
    }

    public function testManualTranslationIsNeverOverwritten(): void
    {
        $id = $this->post('Keep me', '<p>x</p>');
        $this->translations->syncSite($this->site);

        // Mark the da translation manual with custom text.
        $this->transRepo->upsert('i18ntest', $id, 'da', ['slug' => 'manuelt', 'title' => 'Bevar mig', 'status' => 'manual', 'source_hash' => 'anything']);

        $this->content->update($this->site, $id, ['title' => 'Keep me changed']);
        $result = $this->translations->syncSite($this->site);

        self::assertSame(1, $result['skipped']);
        $t = $this->transRepo->find('i18ntest', $id, 'da');
        self::assertNotNull($t);
        self::assertSame('Bevar mig', $t->title); // untouched
        self::assertSame('manual', $t->status);
    }

    public function testBuildEmitsPerLocaleTreesWithHreflang(): void
    {
        $this->syncShell();
        $this->post('A post', '<p>Content here.</p>');
        $this->translations->syncSite($this->site);

        $this->build->build($this->site);
        $buildDir = $this->dataPath . '/sites/i18ntest/build';

        self::assertFileExists($buildDir . '/blog/a-post/index.html');
        self::assertFileExists($buildDir . '/da/blog/da-a-post/index.html');
        self::assertFileExists($buildDir . '/sitemap.xml');
        self::assertFileExists($buildDir . '/sitemap-da.xml');

        $da = (string) file_get_contents($buildDir . '/da/blog/da-a-post/index.html');
        self::assertStringContainsString('<html lang="da"', $da);
        self::assertStringContainsString('hreflang="en"', $da);
        self::assertStringContainsString('hreflang="da"', $da);
        self::assertStringContainsString('hreflang="x-default"', $da);
        self::assertStringContainsString('[DA]', $da); // translated marker
    }

    public function testMissingTranslationIsAbsentForThatLocale(): void
    {
        $this->syncShell();
        $translated = $this->post('Translated', '<p>x</p>');
        $untranslated = $this->post('Untranslated', '<p>y</p>');

        // Only translate the first post; leave the second untranslated.
        $this->translations->syncSite($this->site);
        // Now "forget" the second post's translation to simulate a failure.
        $this->databases->site('i18ntest')->executeStatement(
            'DELETE FROM content_translations WHERE content_id = ?',
            [$untranslated],
        );

        $this->build->build($this->site);
        $buildDir = $this->dataPath . '/sites/i18ntest/build';

        // The translated post has a Danish page; the untranslated one does not.
        self::assertFileExists($buildDir . '/da/blog/da-translated/index.html');
        self::assertDirectoryDoesNotExist($buildDir . '/da/blog/da-untranslated');

        // The untranslated post's ENGLISH page has NO da hreflang alternate.
        $en = (string) file_get_contents($buildDir . '/blog/untranslated/index.html');
        self::assertStringNotContainsString('hreflang="da"', $en);
    }

    public function testStructuredFragmentsAreTranslatedAndReSanitized(): void
    {
        $id = $this->content->create($this->site, [
            'type' => 'post',
            'title' => 'With fragments',
            'body_html' => '<p>body</p>',
            'tldr' => '<p>short version</p>',
            'intro' => '<p>lead</p>',
            'status' => 'published',
        ])->id;

        $this->translations->syncSite($this->site);

        $t = $this->transRepo->find('i18ntest', $id, 'da');
        self::assertNotNull($t);
        self::assertStringContainsString('[DA]', (string) $t->tldr);
        self::assertStringContainsString('[DA]', (string) $t->intro);
        self::assertNull($t->conclusion, 'an empty source fragment stays empty');

        // A prompt-injected model could return <script> in a translated
        // fragment exactly like in a body — both must be re-sanitized.
        $this->translator->injectScript = true;
        $injected = $this->content->create($this->site, [
            'type' => 'post',
            'title' => 'Injected fragments',
            'body_html' => '<p>body</p>',
            'tldr' => '<p>clean summary</p>',
            'status' => 'published',
        ])->id;

        $this->translations->syncSite($this->site);

        $ti = $this->transRepo->find('i18ntest', $injected, 'da');
        self::assertNotNull($ti);
        self::assertStringNotContainsString('<script', (string) $ti->tldr);
        self::assertStringNotContainsString('alert', (string) $ti->tldr);
    }

    public function testFragmentLessContentWithACurrentTranslationStaysSkipped(): void
    {
        // The mass-retranslation guard, end-to-end: content created WITHOUT
        // fragments keeps its pre-feature hash, so its stored translation
        // stays current across syncs — the fields' existence alone must
        // never stale anything.
        $this->post('Untouched', '<p>x</p>');
        $this->translations->syncSite($this->site);

        $calls = $this->translator->calls;
        $result = $this->translations->syncSite($this->site);
        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['translated']);
        self::assertSame($calls, $this->translator->calls);
    }

    public function testTranslatedBodyIsReSanitized(): void
    {
        // A prompt-injected model could return <script> in the translated
        // body; it must be stripped before storage (it renders via |raw).
        $this->translator->injectScript = true;
        $id = $this->post('Injected', '<p>clean source</p>');

        $this->translations->syncSite($this->site);

        $t = $this->transRepo->find('i18ntest', $id, 'da');
        self::assertNotNull($t);
        self::assertStringNotContainsString('<script', $t->bodyHtml);
        self::assertStringNotContainsString('alert', $t->bodyHtml);
    }

    public function testTranslatedPageSlugAvoidsReservedAndLocaleCodes(): void
    {
        // A page whose translated title slugifies to a reserved root slug
        // ("blog") or a locale code must be bumped, or it collides with the
        // per-locale directory and is dropped at build time.
        $this->translator->fixedTitle = 'blog';
        $id = $this->content->create($this->site, ['type' => 'page', 'title' => 'Services', 'body_html' => '<p>x</p>', 'status' => 'published'])->id;

        $this->translations->syncSite($this->site);

        $t = $this->transRepo->find('i18ntest', $id, 'da');
        self::assertNotNull($t);
        self::assertNotSame('blog', $t->slug);
        self::assertStringStartsWith('blog-', $t->slug);
    }

    public function testResetFailedRequeuesFailedRowsAndClearsAllErrors(): void
    {
        // A failed item plus a live-but-stale item carrying a refresh error:
        // the explicit retry reset puts the failed row back to 'pending' and
        // wipes every lingering error, so the dashboard shows a clean slate
        // the moment Retry is clicked.
        $this->translator->failContaining = ['Broken'];
        $failedId = $this->post('Broken', '<p>x</p>');
        $result = $this->translations->syncSite($this->site);
        self::assertSame(1, $result['failed']);

        $staleId = $this->post('Fine', '<p>y</p>');
        $this->translations->syncSite($this->site); // Fine translates; Broken keeps failing
        $this->content->update($this->site, $staleId, ['title' => 'Fine edited']);
        $this->translator->failAll = true;
        $this->translations->syncSite($this->site); // stale refresh fails → error recorded, page kept

        $rows = fn () => $this->databases->site('i18ntest')
            ->fetchAllAssociative('SELECT content_id, status, error FROM content_translations ORDER BY content_id');
        foreach ($rows() as $r) {
            self::assertNotNull($r['error'], 'both rows carry an error before the reset');
        }

        self::assertSame(1, $this->translations->resetFailed($this->site));

        foreach ($rows() as $r) {
            self::assertNull($r['error'], 'reset clears every lingering error');
            if ((int) $r['content_id'] === $failedId) {
                self::assertSame('pending', $r['status']);
            }
            if ((int) $r['content_id'] === $staleId) {
                self::assertSame('translated', $r['status'], 'the live stale page is never torn down');
            }
        }

        // With the gateway back, the reset rows translate on the next sync.
        $this->translator->failAll = false;
        $this->translator->failContaining = [];
        $after = $this->translations->syncSite($this->site);
        self::assertSame(2, $after['translated']);
        self::assertSame(0, $after['failed']);
    }

    public function testStaleRowFailureKeepsPageAndRecordsWhy(): void
    {
        $id = $this->post('Live page', '<p>x</p>');
        $this->translations->syncSite($this->site);

        $this->content->update($this->site, $id, ['title' => 'Live page edited']);
        $this->translator->failAll = true;
        $result = $this->translations->syncSite($this->site);
        self::assertSame(1, $result['failed']);

        $row = $this->databases->site('i18ntest')->fetchAssociative(
            'SELECT status, error, title FROM content_translations WHERE content_id = ?',
            [$id],
        );
        self::assertNotFalse($row);
        self::assertSame('translated', $row['status'], 'the old translation stays published');
        self::assertStringContainsString('gateway down', (string) $row['error'], 'the refresh failure is visible');

        // A later successful refresh clears the error again.
        $this->translator->failAll = false;
        $this->translations->syncSite($this->site);
        $row = $this->databases->site('i18ntest')->fetchAssociative(
            'SELECT status, error FROM content_translations WHERE content_id = ?',
            [$id],
        );
        self::assertNotFalse($row);
        self::assertNull($row['error']);
    }

    public function testPreviouslyFailedItemsDoNotStarveFreshWork(): void
    {
        // Eight permanently-failing items already marked failed, two fresh
        // untranslated posts. The failed rows must sort LAST, so the fresh
        // posts translate; the run then stops gracefully (complete=false)
        // when the failing tail trips the breaker — it must NOT throw, or
        // the successes' build+publish chain would be lost with them.
        $badIds = [];
        for ($i = 1; $i <= 8; ++$i) {
            $badIds[] = $this->post('Reject ' . $i, '<p>x</p>');
        }
        foreach ($badIds as $n => $id) {
            $this->transRepo->upsert('i18ntest', $id, 'da', [
                'slug' => 'reject-' . ($n + 1), 'status' => 'failed', 'source_hash' => '', 'error' => 'old error',
            ]);
        }
        $goodA = $this->post('Fresh one', '<p>a</p>');
        $goodB = $this->post('Fresh two', '<p>b</p>');
        $this->translator->failContaining = ['Reject'];

        $result = $this->translations->syncSite($this->site);

        self::assertSame(2, $result['translated'], 'fresh items translate before the failing tail');
        self::assertSame(8, $result['failed']);
        self::assertFalse($result['complete'], 'the breaker stops the chunk gracefully after successes');
        foreach ([$goodA, $goodB] as $id) {
            self::assertSame('translated', $this->transRepo->find('i18ntest', $id, 'da')?->status);
        }
    }

    public function testStaleRowsThatFailToRefreshDoNotStarveFreshWork(): void
    {
        // THE stale-starvation bug. A stale row whose refresh fails keeps
        // status='translated' on purpose (the live page is never torn down)
        // and records only an `error` — so the old "failed items go last"
        // ordering, which looked at status='failed', could not see it. Eight
        // such rows sorted FIRST in every chunk, tripped the breaker with
        // zero successes, killed the job, and the stale count sat there
        // forever no matter how often Retry was clicked.
        $stale = [];
        for ($i = 1; $i <= 8; ++$i) {
            $stale[] = $this->post('Rot ' . $i, '<p>x</p>');
        }
        $this->translations->syncSite($this->site);

        // Make them stale AND make their refresh fail permanently.
        foreach ($stale as $n => $id) {
            $this->content->update($this->site, $id, ['title' => 'Rot ' . ($n + 1) . ' edited']);
        }
        $this->translator->failContaining = ['Rot'];
        try {
            // Every item failing with nothing to show for it IS a dead
            // gateway as far as this run can tell, so it aborts — but the
            // per-row errors are recorded before it does, which is what the
            // next run's ordering reads.
            $this->translations->syncSite($this->site);
        } catch (\Fastsite\Translation\TranslationException) {
        }
        foreach ($stale as $id) {
            self::assertNotNull($this->transRepo->find('i18ntest', $id, 'da')?->error);
        }

        // Now add fresh work. It must translate rather than starve behind the
        // eight known-bad rows.
        $freshA = $this->post('Healthy one', '<p>a</p>');
        $freshB = $this->post('Healthy two', '<p>b</p>');

        $result = $this->translations->syncSite($this->site);

        self::assertSame(2, $result['translated'], 'fresh work runs before the known-bad tail');
        foreach ([$freshA, $freshB] as $id) {
            self::assertSame('translated', $this->transRepo->find('i18ntest', $id, 'da')?->status);
        }
        foreach ($stale as $id) {
            self::assertSame('translated', $this->transRepo->find('i18ntest', $id, 'da')?->status, 'the live stale page is kept');
        }
    }

    public function testChunkThatExactlyFillsFromOneLocaleStillReportsMoreWork(): void
    {
        // The chunk cap decides "is there more after this run?" by comparing
        // the plan against the cap. When the FIRST locale happens to supply
        // exactly the cap, the remaining locales must still be seen — or the
        // run reports the site complete, chains build+publish, and strands
        // every other language until something unrelated triggers a sync.
        $this->sites->addLocale('i18ntest', 'de');
        $site = $this->siteRepo->findByHandle('i18ntest');
        self::assertNotNull($site);
        $this->post('One', '<p>1</p>');
        $this->post('Two', '<p>2</p>');

        // 2 posts × 2 locales = 4 units; a cap of exactly 2 is the trap.
        $first = $this->translations->syncSite($site, null, 2);

        self::assertSame(2, $first['translated']);
        self::assertFalse($first['complete'], 'the second locale is still outstanding');

        $second = $this->translations->syncSite($site, null, 2);
        self::assertSame(2, $second['translated']);
        self::assertTrue($second['complete']);
    }

    public function testResetContentForcesAnUpToDateTranslationToRerun(): void
    {
        // The sanitize/verbatim recovery path: the stored translation is
        // WRONG but perfectly current (source_hash matches), so nothing is
        // stale and no sync would ever touch it again.
        $target = $this->post('Widget page', '<p>x</p>');
        $other = $this->post('Untouched', '<p>y</p>');
        $this->translations->syncSite($this->site);
        self::assertSame(2, $this->translations->syncSite($this->site)['skipped'], 'both are current');

        self::assertSame(1, $this->translations->resetContent($this->site, $target));

        $result = $this->translations->syncSite($this->site);
        self::assertSame(1, $result['translated'], 'only the reset page re-translates');
        self::assertSame(1, $result['skipped'], 'the other page is untouched');
        self::assertSame('translated', $this->transRepo->find('i18ntest', $target, 'da')?->status);
        self::assertNotSame('', $this->transRepo->find('i18ntest', $other, 'da')?->sourceHash, 'the other page keeps its source hash');
    }

    public function testResetContentKeepsManualTranslationsAndSlugs(): void
    {
        $id = $this->post('Hand made', '<p>x</p>');
        $this->translations->syncSite($this->site);
        $slug = $this->transRepo->find('i18ntest', $id, 'da')?->slug;
        self::assertNotNull($slug);

        // A manual translation is authored, not derived — a reset must not
        // schedule it for overwrite.
        $manual = $this->post('Also hand made', '<p>y</p>');
        $this->translations->syncSite($this->site);
        $this->transRepo->upsert('i18ntest', $manual, 'da', ['status' => 'manual', 'title' => 'Mit eget']);

        self::assertSame(1, $this->translations->resetAll($this->site), 'only the non-manual row resets');

        $this->translations->syncSite($this->site);
        self::assertSame('Mit eget', $this->transRepo->find('i18ntest', $manual, 'da')?->title);
        self::assertSame($slug, $this->transRepo->find('i18ntest', $id, 'da')?->slug, 'the locale slug stays stable');
    }

    public function testShortFieldsTravelInASingleBatchedCall(): void
    {
        // Four short strings used to cost four round trips. They now share
        // one 'fields' call, so a fully-populated post is 2 calls, not 5.
        $this->content->create($this->site, [
            'type' => 'post', 'status' => 'published', 'title' => 'Batched',
            'excerpt' => 'A summary.', 'body_html' => '<p>Body.</p>',
            'seo_title' => 'SEO title', 'seo_description' => 'SEO description.',
        ]);

        $result = $this->translations->syncSite($this->site);

        self::assertSame(1, $result['translated']);
        self::assertSame(2, $this->translator->calls, 'one fields call + one body call');
        $row = $this->transRepo->find('i18ntest', 1, 'da');
        self::assertNotNull($row);
        self::assertSame('[DA] Batched', $row->title);
        self::assertSame('[DA] A summary.', $row->excerpt);
        self::assertSame('[DA] SEO title', $row->seoTitle);
        self::assertSame('[DA] SEO description.', $row->seoDescription);
    }

    public function testMalformedFieldsResponseFallsBackToPerFieldCalls(): void
    {
        // A model that answers the batched call with prose must not store
        // garbage or fail the page — it falls back to the pre-batching
        // one-call-per-field path.
        $this->content->create($this->site, [
            'type' => 'post', 'status' => 'published', 'title' => 'Chatty',
            'excerpt' => 'A summary.', 'body_html' => '<p>Body.</p>',
        ]);
        $this->translator->malformedFields = true;

        $result = $this->translations->syncSite($this->site);

        self::assertSame(1, $result['translated']);
        $row = $this->transRepo->find('i18ntest', 1, 'da');
        self::assertNotNull($row);
        self::assertSame('[DA] Chatty', $row->title, 'the fallback produced a real translation');
        self::assertSame('[DA] A summary.', $row->excerpt);
    }

    public function testParallelTranslatorRunsAWholeChunkThroughOneBatch(): void
    {
        // With a batching translator the chunk's calls go out together — the
        // point of the whole exercise. Every unit must still be applied
        // correctly from the batch's keyed results.
        $parallel = new FakeParallelTranslator();
        $service = new TranslationService(
            $this->contentRepo,
            $this->transRepo,
            new FakeAiClientFactory($parallel),
            new ContentSanitizer(),
            $this->databases,
            new SystemClock(),
            new NullLogger(),
            new \Fastsite\Translation\TranslationLog($this->databases, new SystemClock()),
            rateLimitPauseSeconds: 0,
        );
        for ($i = 1; $i <= 5; ++$i) {
            $this->post('Batch ' . $i, '<p>b' . $i . '</p>');
        }

        $result = $service->syncSite($this->site, null, null, 4);

        self::assertSame(5, $result['translated']);
        self::assertTrue($result['complete']);
        self::assertSame(1, $parallel->batches, 'the whole chunk went out as ONE batch');
        self::assertSame(10, $parallel->requests, '5 items × (fields + body)');
        for ($i = 1; $i <= 5; ++$i) {
            self::assertSame('[DA] Batch ' . $i, $this->transRepo->find('i18ntest', $i, 'da')?->title);
        }
    }

    public function testParallelBatchKeepsSuccessesWhenSomeUnitsFail(): void
    {
        // One dead item in a batch must never discard the batch's finished
        // work — per-request failures come back as values, not as a throw.
        $parallel = new FakeParallelTranslator();
        $parallel->failContaining = ['Doomed'];
        $service = new TranslationService(
            $this->contentRepo,
            $this->transRepo,
            new FakeAiClientFactory($parallel),
            new ContentSanitizer(),
            $this->databases,
            new SystemClock(),
            new NullLogger(),
            new \Fastsite\Translation\TranslationLog($this->databases, new SystemClock()),
            rateLimitPauseSeconds: 0,
        );
        $good = $this->post('Healthy', '<p>a</p>');
        $bad = $this->post('Doomed', '<p>b</p>');

        $result = $service->syncSite($this->site, null, null, 4);

        self::assertSame(1, $result['translated']);
        self::assertSame(1, $result['failed']);
        self::assertSame('translated', $this->transRepo->find('i18ntest', $good, 'da')?->status);
        self::assertSame('failed', $this->transRepo->find('i18ntest', $bad, 'da')?->status);
    }

    public function testParallelBatchWithNoSuccessesAbortsLoudly(): void
    {
        // Same verdict as the sequential path: nothing to show for the run
        // means the gateway is down, so the job must back off rather than
        // report a quiet success.
        $parallel = new FakeParallelTranslator();
        $parallel->failAll = true;
        $service = new TranslationService(
            $this->contentRepo,
            $this->transRepo,
            new FakeAiClientFactory($parallel),
            new ContentSanitizer(),
            $this->databases,
            new SystemClock(),
            new NullLogger(),
            new \Fastsite\Translation\TranslationLog($this->databases, new SystemClock()),
            rateLimitPauseSeconds: 0,
        );
        for ($i = 1; $i <= 9; ++$i) {
            $this->post('Doomed ' . $i, '<p>x</p>');
        }

        $this->expectException(\Fastsite\Translation\TranslationException::class);
        $service->syncSite($this->site, null, null, 4);
    }

    public function testEveryCallIsLoggedWithWhatTheModelReturned(): void
    {
        // The whole point of the log: a user must be able to SEE the model's
        // actual answer, and its actual error, without shelling into a box.
        $log = new \Fastsite\Translation\TranslationLog($this->databases, new SystemClock());
        $this->post('Logged page', '<p>Body.</p>');

        $this->translations->syncSite($this->site);
        $rows = $log->recent('i18ntest');

        self::assertCount(2, $rows, 'one fields call + one body call');
        $fields = array_values(array_filter($rows, static fn (array $r): bool => $r['field'] === 'fields'))[0];
        self::assertSame('ok', $fields['status']);
        self::assertSame('da', $fields['locale']);
        self::assertSame('Logged page', $fields['title']);
        self::assertStringContainsString('[DA] Logged page', $fields['response'], 'the raw model answer is stored');
        self::assertStringContainsString('Logged page', $fields['request'], 'so is what we sent');
    }

    public function testFailuresLogTheProvidersOwnError(): void
    {
        $log = new \Fastsite\Translation\TranslationLog($this->databases, new SystemClock());
        $this->post('Doomed page', '<p>x</p>');
        $this->translator->failAll = true;

        try {
            $this->translations->syncSite($this->site);
        } catch (\Fastsite\Translation\TranslationException) {
        }

        $rows = $log->recent('i18ntest');
        self::assertNotSame([], $rows);
        self::assertSame('failed', $rows[0]['status']);
        self::assertStringContainsString('gateway down', $rows[0]['error'], 'the provider error is readable in the UI');
    }

    public function testAllFailedBatchFallsBackToSequentialInsteadOfParkingForever(): void
    {
        // A batch that yields nothing is the signature of a batching problem,
        // not proof the gateway is down. Believing it killed the run and
        // parked the job, and every retry did the same thing forever. The
        // sequential retry is what turns that loop into a slow success.
        $parallel = new FlakyBatchTranslator();
        $service = new TranslationService(
            $this->contentRepo,
            $this->transRepo,
            new FakeAiClientFactory($parallel),
            new ContentSanitizer(),
            $this->databases,
            new SystemClock(),
            new NullLogger(),
            new \Fastsite\Translation\TranslationLog($this->databases, new SystemClock()),
            rateLimitPauseSeconds: 0,
        );
        for ($i = 1; $i <= 3; ++$i) {
            $this->post('Item ' . $i, '<p>x</p>');
        }

        $result = $service->syncSite($this->site, null, null, 4);

        self::assertSame(1, $parallel->batches, 'the concurrent attempt ran once');
        self::assertSame(3, $result['translated'], 'and the sequential retry rescued every item');
        self::assertTrue($result['complete']);
        self::assertSame(0, $result['failed']);
    }

    public function testSequentialFallbackIsSkippedWhenTheBatchWasRateLimited(): void
    {
        // A rate limit is the provider telling us exactly what is wrong.
        // Immediately replaying the same units one at a time would spend the
        // budget it just asked us to stop spending.
        $parallel = new FakeParallelTranslator();
        $parallel->rateLimitAll = true;
        $service = new TranslationService(
            $this->contentRepo,
            $this->transRepo,
            new FakeAiClientFactory($parallel),
            new ContentSanitizer(),
            $this->databases,
            new SystemClock(),
            new NullLogger(),
            new \Fastsite\Translation\TranslationLog($this->databases, new SystemClock()),
            rateLimitPauseSeconds: 0,
        );
        $this->post('One', '<p>a</p>');
        $this->post('Two', '<p>b</p>');

        $result = $service->syncSite($this->site, null, null, 4);

        self::assertTrue($result['rate_limited']);
        self::assertFalse($result['complete']);
        self::assertSame(0, $result['translated']);
        $rows = (int) $this->databases->site('i18ntest')->fetchOne('SELECT COUNT(*) FROM content_translations');
        self::assertSame(0, $rows, 'rate-limited items are never branded');
    }

    public function testSyncAttachesMidStreamHeartbeatToStreamAwareTranslators(): void
    {
        // The job's heartbeat must reach the translator so a long streaming
        // call ticks WHILE bytes arrive — that is what lets the stream
        // ceiling exceed the 600s reclaim threshold without false reclaims.
        $this->post('Long page', '<p>x</p>');

        $ticks = 0;
        $this->translations->syncSite($this->site, function () use (&$ticks): void {
            ++$ticks;
        });

        self::assertNotNull($this->translator->streamTick, 'heartbeat attached to the translator');
        ($this->translator->streamTick)();
        self::assertGreaterThan(0, $ticks, 'the attached tick reaches the job heartbeat');
    }

    public function testTransportFailuresAbortFastWithoutBrandingRows(): void
    {
        // Gateway unreachable (connect refused / silent wire): the run must
        // stop after TWO doomed items — not eight — and must not brand any
        // row 'failed' (the items were never actually rejected; they stay
        // missing and translate normally once the gateway is back).
        for ($i = 1; $i <= 5; ++$i) {
            $this->post('Waiting ' . $i, '<p>x</p>');
        }
        $this->translator->failTransport = true;

        try {
            $this->translations->syncSite($this->site);
            self::fail('expected the dead-gateway abort');
        } catch (\Fastsite\Translation\TranslationException) {
        }

        self::assertSame(2, $this->translator->calls, 'two transport probes, then abort');
        $rows = (int) $this->databases->site('i18ntest')->fetchOne('SELECT COUNT(*) FROM content_translations');
        self::assertSame(0, $rows, 'transport failures never brand rows failed');

        // Gateway back → everything translates as plain missing work.
        $this->translator->failTransport = false;
        $result = $this->translations->syncSite($this->site);
        self::assertSame(5, $result['translated']);
    }

    public function testRateLimitPausesOnceThenRecovers(): void
    {
        // One 429/rate_limit response: the run pauses (0s in tests), retries
        // the SAME item, and completes — no rows branded, nothing parked.
        $this->post('First', '<p>a</p>');
        $this->post('Second', '<p>b</p>');
        $this->translator->rateLimitNextCalls = 1;

        $result = $this->translations->syncSite($this->site);

        self::assertTrue($result['complete']);
        self::assertSame(2, $result['translated']);
        self::assertFalse($result['rate_limited']);
    }

    public function testPersistentRateLimitParksTheRunWithoutBrandingRows(): void
    {
        // The provider keeps answering rate_limit: after the one in-run
        // pause+retry the run stops and reports rate_limited so the job
        // handler parks the follow-up with a delay — items are never marked
        // failed (they were never rejected) and no further calls are made.
        $this->post('Alpha', '<p>a</p>');
        $this->post('Beta', '<p>b</p>');
        $this->translator->rateLimitNextCalls = 99;

        $result = $this->translations->syncSite($this->site);

        self::assertFalse($result['complete']);
        self::assertTrue($result['rate_limited']);
        self::assertSame(2, $this->translator->calls, 'first attempt + one post-pause retry, then stop');
        $rows = (int) $this->databases->site('i18ntest')->fetchOne('SELECT COUNT(*) FROM content_translations');
        self::assertSame(0, $rows, 'rate-limited items are never branded failed');
    }

    public function testCircuitBreakerAbortsZeroSuccessRuns(): void
    {
        // Every attempt failing with no successes = the gateway is down —
        // abort loudly so the job backs off instead of grinding through
        // hundreds of doomed calls.
        for ($i = 1; $i <= 9; ++$i) {
            $this->post('Doomed ' . $i, '<p>x</p>');
        }
        $this->translator->failAll = true;

        $this->expectException(\Fastsite\Translation\TranslationException::class);
        $this->translations->syncSite($this->site);
    }

    public function testPublishTriggerRoutesMultilingualThroughTranslateOnlyWhenWorkExists(): void
    {
        $queue = new \Fastsite\Shared\Queue\JobQueue($this->databases, $this->redisClient(), new SystemClock());
        $trigger = new \Fastsite\Build\Service\PublishTrigger($queue, $this->translations);

        $typeOf = fn (string $uuid): string => (string) $this->databases->engine()
            ->fetchOne('SELECT type FROM jobs WHERE uuid = ?', [$uuid]);

        // Multilingual site (has 'da') with an untranslated post → translate.
        $this->post('Needs work', '<p>Body.</p>');
        self::assertSame('translate_content', $typeOf($trigger->trigger($this->site, 'msg', 'test')));

        // Everything current → straight to the build lane; an empty translate
        // job must not occupy a translate slot.
        $this->translations->syncSite($this->site);
        $this->translations->syncShellStrings($this->site);
        self::assertSame('build_full', $typeOf($trigger->trigger($this->site, 'msg', 'test')));

        // Editing the source makes its translation stale → translate again
        // (absorbed into the still-pending translate twin from above).
        $ids = $this->databases->site('i18ntest')->fetchFirstColumn('SELECT id FROM content');
        $this->content->update($this->site, (int) $ids[0], ['title' => 'Edited — stale now']);
        self::assertSame('translate_content', $typeOf($trigger->trigger($this->site, 'msg', 'test')));
    }

    public function testHasPendingWorkTracksContentAndShellStaleness(): void
    {
        // A fresh site ships a shell whose i18n strings are untranslated for
        // the extra locale → pending until the shell strings sync.
        self::assertTrue($this->translations->hasPendingWork($this->site));
        $this->translations->syncShellStrings($this->site);
        self::assertFalse($this->translations->hasPendingWork($this->site));

        // A published post with no translation → pending; synced → current.
        $id = $this->post('Pending', '<p>Body.</p>');
        self::assertTrue($this->translations->hasPendingWork($this->site));
        $this->translations->syncSite($this->site);
        self::assertFalse($this->translations->hasPendingWork($this->site));

        // A source edit re-stales exactly that item.
        $this->content->update($this->site, $id, ['title' => 'Edited']);
        self::assertTrue($this->translations->hasPendingWork($this->site));
    }

    private function redisClient(): \Predis\Client
    {
        $redis = new \Predis\Client('tcp://127.0.0.1:6379/15');
        $redis->flushdb();

        return $redis;
    }

    public function testSwitcherIsNotInjectedWithoutAMarker(): void
    {
        $this->syncShell();
        // A custom shell with a bare nav: no marker, no data-fs-langnav of its
        // own. The old engine auto-appended the dropdown into this nav — now
        // placement is strictly the designer's call, so nothing may appear.
        file_put_contents(
            $this->dataPath . '/sites/i18ntest/shell/templates/partials/header.html.twig',
            '<header><nav aria-label="Main"><a href="/">Home</a></nav></header>',
        );

        $this->post('A post', '<p>Content here.</p>');
        $this->translations->syncSite($this->site);
        $this->build->build($this->site);

        $html = (string) file_get_contents($this->dataPath . '/sites/i18ntest/build/blog/a-post/index.html');
        // The persistence script's querySelector mentions the attribute, so
        // assert on the rendered ELEMENT, which must not exist.
        self::assertStringNotContainsString('<details data-fs-langnav', $html, 'no marker → no switcher, anywhere');
        self::assertStringNotContainsString('fs:languages', $html);
        // The head hreflang alternates are untouched by this — SEO stays intact.
        self::assertStringContainsString('hreflang="da"', $html);
    }

    public function testMarkerPlacementIsHonored(): void
    {
        $this->syncShell();
        // The agent puts the marker in a footer wrapper — nowhere near a nav.
        // The dropdown must render exactly there.
        file_put_contents(
            $this->dataPath . '/sites/i18ntest/shell/templates/partials/header.html.twig',
            '<header><nav aria-label="Main"><a href="/">Home</a></nav></header>'
            . '<div id="lang-here"><!--fs:languages--></div>',
        );

        $this->post('A post', '<p>Content here.</p>');
        $this->translations->syncSite($this->site);
        $this->build->build($this->site);

        $html = (string) file_get_contents($this->dataPath . '/sites/i18ntest/build/blog/a-post/index.html');
        self::assertStringContainsString('id="lang-here"><details data-fs-langnav', $html, 'dropdown replaces the marker in place');
        self::assertStringNotContainsString('fs:languages', $html, 'marker consumed');
        // The links come from the hreflang cluster, so the TRANSLATED slug is
        // linked — a same-path guess across locale trees would have missed it.
        self::assertStringContainsString('href="/da/blog/da-a-post/" hreflang="da"', $html);
    }

    private function syncShell(): void
    {
        // Copy the default shell into the test site so templates exist.
        $source = \dirname(__DIR__, 2) . '/templates/shell-default';
        $target = $this->dataPath . '/sites/i18ntest/shell';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($it as $item) {
            \assert($item instanceof \SplFileInfo);
            $rel = substr($item->getPathname(), \strlen($source) + 1);
            $dest = $target . '/' . str_replace('\\', '/', $rel);
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0770, true);
                }
            } else {
                copy($item->getPathname(), $dest);
            }
        }
    }

    /**
     * The regression this feature exists for: a body stored VERBATIM shipped
     * intact in the primary locale but was re-sanitized on the way out of
     * translation, so every translated page lost the interactive markup —
     * <button>/<input>/<template>, svg and framework attributes — and rendered
     * a husk (a real site's pricing calculator came out as an empty shell in
     * all nine locales while English worked).
     */
    public function testVerbatimBodyKeepsInteractiveMarkupThroughTranslation(): void
    {
        $body = '<div class="calc" x-data="calc()" x-cloak>'
            . '<button @click="plan = 1" :class="{ on: plan }">Personal</button>'
            . '<input type="range" min="1" x-model.number="sites">'
            . '<template x-for="t in tiers"><span x-text="t"></span></template>'
            . '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>'
            . '</div>';
        $id = $this->content->create($this->site, [
            'type' => 'post', 'title' => 'Pricing', 'body_html' => $body,
            'status' => 'published', 'sanitize' => false,
        ])->id;

        self::assertSame(1, $this->translations->syncSite($this->site)['translated']);

        $row = $this->transRepo->find($this->site->handle, $id, 'da');
        self::assertNotNull($row);
        foreach (['<button', '<input', '<template', '<svg', 'x-data', 'x-model.number', '@click', ':class'] as $needle) {
            self::assertStringContainsString($needle, $row->bodyHtml, $needle . ' must survive translation');
        }
        // Exactly one verbatim wrapper — never nested by the round trip.
        self::assertSame(1, substr_count($row->bodyHtml, ContentService::VERBATIM_OPEN));
        self::assertTrue(ContentService::isVerbatim($row->bodyHtml));
    }

    /** A sanitizing row keeps the old guarantee: model output is scrubbed. */
    public function testSanitizedBodyStillStripsInjectedScriptFromTranslation(): void
    {
        $id = $this->post('Normal', '<p>Body.</p>');
        $this->translator->injectScript = true;

        $this->translations->syncSite($this->site);

        $row = $this->transRepo->find($this->site->handle, $id, 'da');
        self::assertNotNull($row);
        self::assertStringNotContainsString('<script', $row->bodyHtml);
    }

    /**
     * sanitize_content=false makes verbatim the SITE default: no per-write
     * flag, and it holds through translation too.
     */
    public function testSiteWideVerbatimAppliesToIngestAndTranslation(): void
    {
        $this->sites->update('i18ntest', ['sanitize_content' => false]);
        $site = $this->siteRepo->findByHandle('i18ntest');
        \assert($site !== null);
        self::assertFalse($site->sanitizeContent);

        // No 'sanitize' key anywhere — the site setting alone decides.
        $id = $this->content->create($site, [
            'type' => 'post', 'title' => 'Widget', 'status' => 'published',
            'body_html' => '<div class="w"><button @click="go()">Go</button></div>',
        ])->id;

        $stored = $this->contentRepo->findById($site->handle, $id);
        self::assertNotNull($stored);
        self::assertTrue(ContentService::isVerbatim($stored->bodyHtml));
        self::assertStringContainsString('<button', $stored->bodyHtml);

        self::assertSame(1, $this->translations->syncSite($site)['translated']);
        $row = $this->transRepo->find($site->handle, $id, 'da');
        self::assertNotNull($row);
        self::assertStringContainsString('<button', $row->bodyHtml);
        self::assertStringContainsString('@click', $row->bodyHtml);
    }
}

final class FakeTranslator implements Translator, \Fastsite\Translation\StreamProgressAware
{
    public ?\Closure $streamTick = null;
    public int $calls = 0;
    public bool $injectScript = false;
    public ?string $fixedTitle = null;
    public bool $failAll = false;
    public bool $failTransport = false;
    /** The next N translate() calls throw a rate-limited error, then recover. */
    public int $rateLimitNextCalls = 0;
    /** Makes the batched 'fields' call answer with prose instead of JSON. */
    public bool $malformedFields = false;
    /** @var list<string> content substrings that make translate() throw */
    public array $failContaining = [];

    public function translate(string $content, string $fromLocale, string $toLocale, string $format = 'html'): string
    {
        ++$this->calls;
        if ($this->failTransport) {
            throw new \Fastsite\Translation\TranslationException('gateway unreachable (fake)', transport: true);
        }
        if ($this->rateLimitNextCalls > 0) {
            --$this->rateLimitNextCalls;
            throw new \Fastsite\Translation\TranslationException('stream error: rate_limit', rateLimited: true);
        }
        if ($this->failAll) {
            throw new \Fastsite\Translation\TranslationException('gateway down (fake)');
        }
        foreach ($this->failContaining as $needle) {
            if (str_contains($content, $needle)) {
                throw new \Fastsite\Translation\TranslationException('provider rejected item (fake)');
            }
        }
        if ($format === 'html') {
            if ($this->injectScript) {
                return $content . '<script>alert(1)</script>';
            }

            return preg_replace_callback('/>([^<]+)</', static fn (array $m): string => '>[' . strtoupper($toLocale) . '] ' . trim($m[1]) . '<', $content) ?? $content;
        }

        // The batched short-metadata call: a JSON object in, the same keys
        // with translated values out.
        if ($format === \Fastsite\Translation\TranslationPrompt::FORMAT_FIELDS) {
            if ($this->malformedFields) {
                return 'Sure! Here are your translations.';
            }
            /** @var array<string, string> $fields */
            $fields = json_decode($content, true);
            $out = [];
            foreach ($fields as $key => $value) {
                $out[$key] = $key === 'title' && $this->fixedTitle !== null
                    ? $this->fixedTitle
                    : '[' . strtoupper($toLocale) . '] ' . $value;
            }

            return (string) json_encode($out, JSON_UNESCAPED_UNICODE);
        }

        // Text fields: a fixed title forces a deterministic slug for the
        // reserved-slug test; otherwise mark with the locale.
        if ($this->fixedTitle !== null) {
            return $this->fixedTitle;
        }

        return '[' . strtoupper($toLocale) . '] ' . $content;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function onStreamProgress(?\Closure $tick): void
    {
        $this->streamTick = $tick;
    }
}

/**
 * A translator that batches, so the parallel executor is exercised. Records
 * how many batches it was handed and how many requests each carried — the two
 * numbers that say whether a chunk really went out together.
 */
final class FakeParallelTranslator implements Translator, \Fastsite\Translation\ParallelTranslator
{
    public int $batches = 0;
    public int $requests = 0;
    public bool $failAll = false;
    public bool $rateLimitAll = false;
    /** @var list<string> content substrings that make a request fail */
    public array $failContaining = [];

    public function translateAll(array $requests, int $concurrency = 4): array
    {
        ++$this->batches;
        $this->requests += \count($requests);

        $out = [];
        foreach ($requests as $key => $req) {
            try {
                $out[$key] = $this->translate($req['content'], $req['from'], $req['to'], $req['format']);
            } catch (\Fastsite\Translation\TranslationException $e) {
                $out[$key] = $e;
            }
        }

        return $out;
    }

    public function translate(string $content, string $fromLocale, string $toLocale, string $format = 'html'): string
    {
        if ($this->rateLimitAll) {
            throw new \Fastsite\Translation\TranslationException('rate_limit (fake)', rateLimited: true);
        }
        if ($this->failAll) {
            throw new \Fastsite\Translation\TranslationException('gateway down (fake)');
        }
        foreach ($this->failContaining as $needle) {
            if (str_contains($content, $needle)) {
                throw new \Fastsite\Translation\TranslationException('provider rejected item (fake)');
            }
        }

        if ($format === \Fastsite\Translation\TranslationPrompt::FORMAT_FIELDS) {
            /** @var array<string, string> $fields */
            $fields = json_decode($content, true);

            return (string) json_encode(
                array_map(static fn (string $v): string => '[' . strtoupper($toLocale) . '] ' . $v, $fields),
                JSON_UNESCAPED_UNICODE,
            );
        }
        if ($format === 'html') {
            return preg_replace_callback('/>([^<]+)</', static fn (array $m): string => '>[' . strtoupper($toLocale) . '] ' . trim($m[1]) . '<', $content) ?? $content;
        }

        return '[' . strtoupper($toLocale) . '] ' . $content;
    }

    public function isConfigured(): bool
    {
        return true;
    }
}

/**
 * Batching is broken, one-at-a-time works — the shape of a gateway that
 * refuses concurrent requests, or of a bug in the connection pool. Every
 * batched call fails; every sequential call succeeds.
 */
final class FlakyBatchTranslator implements Translator, \Fastsite\Translation\ParallelTranslator
{
    public int $batches = 0;

    public function translateAll(array $requests, int $concurrency = 4): array
    {
        ++$this->batches;

        return array_map(
            static fn (): \Fastsite\Translation\TranslationException => new \Fastsite\Translation\TranslationException(
                'concurrent request rejected (fake)',
                transport: true,
            ),
            $requests,
        );
    }

    public function translate(string $content, string $fromLocale, string $toLocale, string $format = 'html'): string
    {
        if ($format === \Fastsite\Translation\TranslationPrompt::FORMAT_FIELDS) {
            /** @var array<string, string> $fields */
            $fields = json_decode($content, true);

            return (string) json_encode(
                array_map(static fn (string $v): string => '[' . strtoupper($toLocale) . '] ' . $v, $fields),
                JSON_UNESCAPED_UNICODE,
            );
        }

        return '[' . strtoupper($toLocale) . '] ' . $content;
    }

    public function isConfigured(): bool
    {
        return true;
    }
}

/** Hands the TranslationService a fixed translator, no per-site resolution. */
final class FakeAiClientFactory implements AiClientFactory
{
    public function __construct(private readonly Translator $translator)
    {
    }

    public function translatorForSite(Site $site): Translator
    {
        return $this->translator;
    }

    public function factCheckerForSite(Site $site): FactChecker
    {
        return new NullFactChecker();
    }

    public function correctorForSite(Site $site): \Fastsite\FactCheck\ContentCorrector
    {
        return new \Fastsite\FactCheck\ContentCorrector('', '');
    }

    public function researcherForSite(Site $site): \Fastsite\Research\Researcher
    {
        return new \Fastsite\Research\NullResearcher();
    }

    public function altTextForSite(Site $site): \Fastsite\Media\AltTextGenerator
    {
        return new \Fastsite\Media\AltTextGenerator('', '', '');
    }

    public function seoRewriterForSite(Site $site): \Fastsite\Seo\SeoRewriter
    {
        return new \Fastsite\Seo\SeoRewriter('', '', '');
    }

    public function translatorConfiguredForSite(Site $site): bool
    {
        return $this->translator->isConfigured();
    }

    public function factCheckerConfiguredForSite(Site $site): bool
    {
        return false;
    }

    public function researcherConfiguredForSite(Site $site): bool
    {
        return false;
    }

    public function factCheckModelForSite(Site $site): string
    {
        return '';
    }
}
