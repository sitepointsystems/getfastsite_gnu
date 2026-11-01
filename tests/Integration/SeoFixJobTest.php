<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Ai\AiClientFactory;
use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Seo\Job\SeoFixHandler;
use Fastsite\Seo\SeoLengthService;
use Fastsite\Seo\SeoRewriter;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\JobHandlerRegistry;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Shared\Queue\JobRunner;
use Fastsite\Shared\Queue\SiteLock;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use Fastsite\Translation\Repository\DbalTranslationRepository;
use Fastsite\Translation\Service\TranslationService;
use Predis\Client;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The AI SEO length fix END-TO-END through the queue: the seo_fix job runs,
 * rewrites the flagged rows, and the republish it queues goes through
 * PublishTrigger — a multilingual site whose meta translations just went
 * stale must get translate→build→publish, a single-language site goes
 * straight to build→publish. A direct build here would ship the OLD
 * translated titles and skip the translate step entirely.
 */
final class SeoFixJobTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private Client $redis;
    private JobQueue $queue;
    private SiteService $sites;
    private ContentService $content;
    private DbalContentRepository $contentRepo;
    private SystemClock $clock;

    protected function setUp(): void
    {
        try {
            $this->redis = new Client('tcp://127.0.0.1:6379/15');
            $this->redis->ping();
        } catch (\Throwable) {
            self::markTestSkipped('No Redis on 127.0.0.1:6379 — queue tests skipped.');
        }
        $this->redis->flushdb();

        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-seofix-' . bin2hex(random_bytes(6));
        $this->clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($this->clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->sites = new SiteService(
            new DbalSiteRepository($this->databases, $this->clock),
            $this->databases,
            $migrator,
            $this->clock,
            new NullLogger(),
            $appRoot,
        );
        $this->contentRepo = new DbalContentRepository($this->databases, $this->clock);
        $this->content = new ContentService($this->contentRepo, new ContentSanitizer(), $this->clock, new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $this->clock));
        $this->queue = new JobQueue($this->databases, $this->redis, $this->clock);
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
        foreach ($it as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dataPath);
    }

    /** Runs one queued job through the real worker with the seo_fix handler. */
    private function runSeoFix(string $handle, ?AiClientFactory $ai = null): void
    {
        $ai ??= new TruncateOnlyAiFactory();
        $seo = new SeoLengthService($this->contentRepo, $this->content, $ai, new NullLogger());
        $translations = new TranslationService(
            $this->contentRepo,
            new DbalTranslationRepository($this->databases, $this->clock),
            $ai,
            new ContentSanitizer(),
            $this->databases,
            $this->clock,
            new NullLogger(),
            new \Fastsite\Translation\TranslationLog($this->databases, $this->clock),
        );
        $handler = new SeoFixHandler(
            $seo,
            new DbalSiteRepository($this->databases, $this->clock),
            new SiteLock($this->redis),
            new PublishTrigger($this->queue, $translations),
            new NullLogger(),
        );
        $runner = new JobRunner($this->queue, new JobHandlerRegistry([$handler]), $this->databases, $this->clock, new NullLogger());
        $runner->work(maxRuntimeSeconds: 15, once: true, queues: ['high', 'default', 'low']);
    }

    /** @return list<array<string, mixed>> */
    private function jobs(): array
    {
        return $this->databases->engine()->fetchAllAssociative(
            'SELECT type, queue, status, chain FROM jobs ORDER BY id',
        );
    }

    private function longTitlePost(\Fastsite\Site\Dto\Site $site): int
    {
        return $this->content->create($site, [
            'type' => 'post',
            'title' => 'The complete and utterly exhaustive guide to building extremely fast static websites this year',
            'excerpt' => 'A reasonably sized meta description that fits within the Google and Yoast limits, long enough to pass the minimum length check for descriptions.',
            'body_html' => '<p>Body text for context.</p>',
            'status' => 'published',
        ])->id;
    }

    public function testSingleLanguageFixQueuesBuildWithPublishChain(): void
    {
        $site = $this->sites->create('seomono', 'SEO Mono', 'https://mono.example.com');
        $this->longTitlePost($site);

        $uuid = $this->queue->enqueue('seo_fix', 'seomono', ['trigger' => 'test'], 'default', 'seo-fix:seomono');
        $this->queue->promoteNow($uuid);
        $this->runSeoFix('seomono');

        $jobs = $this->jobs();
        self::assertSame('done', $jobs[0]['status']);
        self::assertSame('build_full', $jobs[1]['type']);
        self::assertSame('build', $jobs[1]['queue'], 'routed onto the build lane');
        self::assertStringContainsString('publish_git', (string) $jobs[1]['chain']);

        $row = $this->databases->site('seomono')->fetchAssociative('SELECT seo_title FROM content LIMIT 1');
        self::assertNotFalse($row);
        self::assertNotSame('', (string) $row['seo_title'], 'the fix wrote the seo_title override');
    }

    public function testMultilingualFixRoutesThroughTranslateLane(): void
    {
        $site = $this->sites->create('seomulti', 'SEO Multi', 'https://multi.example.com');
        $this->sites->addLocale('seomulti', 'da');
        $site = $this->sites->update('seomulti', []); // reload with locales
        $this->longTitlePost($site);

        $uuid = $this->queue->enqueue('seo_fix', 'seomulti', ['trigger' => 'test'], 'default', 'seo-fix:seomulti');
        $this->queue->promoteNow($uuid);
        $this->runSeoFix('seomulti');

        $jobs = $this->jobs();
        self::assertSame('done', $jobs[0]['status']);
        self::assertSame('translate_content', $jobs[1]['type'],
            'multilingual + stale/missing meta translations must refresh translations before building');
        self::assertSame('translate', $jobs[1]['queue'], 'routed onto the translate lane');
    }

    public function testConfiguredAiThatErrorsFailsTheJobVisibly(): void
    {
        $site = $this->sites->create('seofail', 'SEO Fail', 'https://fail.example.com');
        // A too-short excerpt: truncation can't help, only an AI rewrite —
        // which is configured but errors on every call.
        $this->content->create($site, [
            'type' => 'post', 'title' => 'This one is only too short here', 'status' => 'published',
            'body_html' => '<p>B.</p>', 'excerpt' => 'Tiny.',
        ]);

        $uuid = $this->queue->enqueue('seo_fix', 'seofail', ['trigger' => 'test'], 'default', 'seo-fix:seofail');
        $this->queue->promoteNow($uuid);
        $this->runSeoFix('seofail', new BrokenAiFactory());

        $row = $this->databases->engine()->fetchAssociative(
            'SELECT status, error, run_after FROM jobs WHERE uuid = ?', [$uuid],
        );
        self::assertNotFalse($row);
        // A failed attempt parks as pending-with-backoff (dead at max
        // attempts) — the point is it must NOT end 'done' having silently
        // changed nothing, and the reason must sit on the job row.
        self::assertSame('pending', $row['status'], 'A configured-but-erroring AI must fail the attempt, never end the job "done" having changed nothing.');
        self::assertNotNull($row['run_after'], 'parked for a retry with backoff');
        self::assertStringContainsString('AI rewrite failed', (string) $row['error']);
        self::assertStringContainsString('gateway is down', (string) $row['error'], 'The underlying reason lands on the job row.');
    }
}

/**
 * SEO fix without AI (truncate path); translator never resolved in these
 * tests — PublishTrigger's pending-work probe is pure SQL.
 */
class TruncateOnlyAiFactory implements AiClientFactory
{
    public function translatorForSite(\Fastsite\Site\Dto\Site $site): \Fastsite\Translation\Translator
    {
        throw new \RuntimeException('translator must not be resolved by the seo_fix job');
    }

    public function factCheckerForSite(\Fastsite\Site\Dto\Site $site): \Fastsite\FactCheck\FactChecker
    {
        return new \Fastsite\FactCheck\NullFactChecker();
    }

    public function correctorForSite(\Fastsite\Site\Dto\Site $site): \Fastsite\FactCheck\ContentCorrector
    {
        return new \Fastsite\FactCheck\ContentCorrector('', '');
    }

    public function researcherForSite(\Fastsite\Site\Dto\Site $site): \Fastsite\Research\Researcher
    {
        return new \Fastsite\Research\NullResearcher();
    }

    public function altTextForSite(\Fastsite\Site\Dto\Site $site): \Fastsite\Media\AltTextGenerator
    {
        return new \Fastsite\Media\AltTextGenerator('', '', '');
    }

    public function seoRewriterForSite(\Fastsite\Site\Dto\Site $site): SeoRewriter
    {
        return new SeoRewriter('', '', '');
    }

    public function translatorConfiguredForSite(\Fastsite\Site\Dto\Site $site): bool
    {
        return false;
    }

    public function factCheckerConfiguredForSite(\Fastsite\Site\Dto\Site $site): bool
    {
        return false;
    }

    public function researcherConfiguredForSite(\Fastsite\Site\Dto\Site $site): bool
    {
        return false;
    }

    public function factCheckModelForSite(\Fastsite\Site\Dto\Site $site): string
    {
        return '';
    }
}

/** SEO rewriter that is configured but errors on every call. */
final class BrokenAiFactory extends TruncateOnlyAiFactory
{
    public function seoRewriterForSite(\Fastsite\Site\Dto\Site $site): SeoRewriter
    {
        return new SeoRewriter('http://x', 'key', 'model', 5, static fn (): array => throw new \RuntimeException('gateway is down'));
    }
}
