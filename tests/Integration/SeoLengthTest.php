<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Ai\AiClientFactory;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentService;
use Fastsite\Seo\SeoLengthService;
use Fastsite\Seo\SeoLimits;
use Fastsite\Seo\SeoRewriter;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Google/Yoast length checker end-to-end on a real site DB: scan flags
 * out-of-envelope titles/descriptions, fix rewrites them (canned AI via the
 * transport seam) or truncates without AI, and every write flows through
 * ContentService (content_hash moves → translations go stale and re-sync).
 */
final class SeoLengthTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private ContentService $content;
    private DbalContentRepository $contentRepo;
    private Site $site;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-seo-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $sites = new SiteService(
            new DbalSiteRepository($this->databases, $clock),
            $this->databases,
            $migrator,
            $clock,
            new NullLogger(),
            $appRoot,
        );
        $this->site = $sites->create('seotest', 'SEO Test', 'https://seo.example.com');

        $this->contentRepo = new DbalContentRepository($this->databases, $clock);
        $this->content = new ContentService($this->contentRepo, new ContentSanitizer(), $clock, new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock));
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

    private function service(?SeoRewriter $rewriter = null): SeoLengthService
    {
        return new SeoLengthService(
            $this->contentRepo,
            $this->content,
            new StubAiFactory($rewriter ?? new SeoRewriter('', '', '')),
            new NullLogger(),
        );
    }

    public function testScanFlagsLongShortAndMissing(): void
    {
        $longTitle = 'The complete and utterly exhaustive guide to building extremely fast static websites this year';
        $goodDesc = str_repeat('A solid description sentence. ', 5); // ~150 chars
        $this->content->create($this->site, [
            'type' => 'post', 'title' => $longTitle, 'status' => 'published',
            'body_html' => '<p>Body.</p>', 'excerpt' => trim(mb_substr($goodDesc, 0, 150)),
        ]);
        $this->content->create($this->site, [
            'type' => 'post', 'title' => 'Fits well within every limit set', 'status' => 'draft',
            'body_html' => '<p>Body.</p>', 'excerpt' => 'Too short.',
        ]);
        $this->content->create($this->site, [
            'type' => 'page', 'title' => 'Ok', 'status' => 'published',
            'body_html' => '<p>Body.</p>',
        ]);

        $issues = $this->service()->scan($this->site);
        $kinds = array_map(static fn (array $i): string => $i['kind'], $issues);
        sort($kinds);
        self::assertSame(['description-missing', 'description-short', 'title-long', 'title-short'], $kinds);
    }

    public function testFixUsesAiRewriteAndEnforcesLimitsAnyway(): void
    {
        $c = $this->content->create($this->site, [
            'type' => 'post', 'title' => 'The complete and utterly exhaustive guide to building extremely fast static websites this year',
            'status' => 'published', 'body_html' => '<p>Static sites are fast.</p>',
        ]);
        $hashBefore = $this->contentRepo->findById('seotest', $c->id)?->contentHash;

        // The "model" answers with an over-long title — the service must not
        // trust its counting and truncates to the limit.
        $rewriter = new SeoRewriter('http://x', 'key', 'model', 5, static fn (): array => [
            'choices' => [['message' => ['content' => json_encode([
                'title' => 'Fast static websites: the practical guide that is itself still slightly too long',
                'description' => 'Learn how fully static builds reach a perfect PageSpeed score: zero JavaScript, inlined CSS, responsive images and instant loads on any connection.',
            ], JSON_THROW_ON_ERROR)]]],
        ]);

        $result = $this->service($rewriter)->fix($this->site);
        self::assertSame(1, $result['fixed']);

        $row = $this->contentRepo->findById('seotest', $c->id);
        self::assertNotNull($row);
        self::assertLessThanOrEqual(SeoLimits::TITLE_MAX, mb_strlen($row->seoTitle));
        self::assertGreaterThanOrEqual(SeoLimits::DESC_MIN, mb_strlen($row->seoDescription));
        self::assertLessThanOrEqual(SeoLimits::DESC_MAX, mb_strlen($row->seoDescription));
        self::assertSame('The complete and utterly exhaustive guide to building extremely fast static websites this year', $row->title, 'The visible title is never touched — only the seo_* overrides.');
        self::assertNotSame($hashBefore, $row->contentHash, 'content_hash moves so translations of the meta go stale.');

        // Second run: everything fits now.
        self::assertSame([], $this->service($rewriter)->scan($this->site));
    }

    public function testFixWithoutAiTruncatesLongButSkipsShort(): void
    {
        $long = $this->content->create($this->site, [
            'type' => 'post', 'title' => 'Another endlessly meandering headline that keeps going far past the snippet cutoff point',
            'status' => 'published', 'body_html' => '<p>B.</p>',
            'excerpt' => str_repeat('Words that make this description run far beyond the envelope. ', 4),
        ]);
        $short = $this->content->create($this->site, [
            'type' => 'post', 'title' => 'This one is only too short here', 'status' => 'published',
            'body_html' => '<p>B.</p>', 'excerpt' => 'Tiny.',
        ]);

        $result = $this->service()->fix($this->site); // unconfigured rewriter
        self::assertSame(1, $result['fixed']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $result['failed'], 'No AI to fail — a skip, not a failure.');
        self::assertFalse($result['ai'], 'The report says AI was unavailable.');

        $fixedRow = $this->contentRepo->findById('seotest', $long->id);
        self::assertNotNull($fixedRow);
        self::assertLessThanOrEqual(SeoLimits::TITLE_MAX, mb_strlen($fixedRow->seoTitle));
        self::assertLessThanOrEqual(SeoLimits::DESC_MAX, mb_strlen($fixedRow->seoDescription));

        $skippedRow = $this->contentRepo->findById('seotest', $short->id);
        self::assertNotNull($skippedRow);
        self::assertSame('', $skippedRow->seoDescription, 'Too-short needs the AI writer — left for it.');
    }

    public function testFixSingleRowLeavesOtherFlaggedRowsUntouched(): void
    {
        // The progress dialog drives one fix($site, $id) call per flagged
        // row — a call for row A must never write row B.
        $a = $this->content->create($this->site, [
            'type' => 'post', 'title' => 'Another endlessly meandering headline that keeps going far past the snippet cutoff point',
            'status' => 'published', 'body_html' => '<p>A.</p>',
            'excerpt' => str_repeat('Words that make this description run far beyond the envelope. ', 4),
        ]);
        $b = $this->content->create($this->site, [
            'type' => 'post', 'title' => 'A second headline that also rambles well beyond the sixty character snippet cutoff',
            'status' => 'published', 'body_html' => '<p>B.</p>',
            'excerpt' => str_repeat('More words that push this description far beyond the envelope. ', 4),
        ]);

        $result = $this->service()->fix($this->site, $a->id);
        self::assertSame(1, $result['fixed']);
        self::assertCount(1, $result['items']);
        self::assertSame($a->id, $result['items'][0]['id']);

        $rowB = $this->contentRepo->findById('seotest', $b->id);
        self::assertNotNull($rowB);
        self::assertSame('', $rowB->seoTitle, 'Row B stays untouched until its own call.');
    }

    public function testConfiguredAiThatErrorsCountsAsFailedWithTheReason(): void
    {
        $this->content->create($this->site, [
            'type' => 'post', 'title' => 'This one is only too short here', 'status' => 'published',
            'body_html' => '<p>B.</p>', 'excerpt' => 'Tiny.',
        ]);

        $rewriter = new SeoRewriter('http://x', 'key', 'model', 5, static fn (): array => throw new \RuntimeException('gateway returned HTTP 401'));
        $result = $this->service($rewriter)->fix($this->site);

        self::assertSame(0, $result['fixed']);
        self::assertSame(1, $result['failed'], 'A configured AI that errors is a FAILURE, not a silent skip.');
        self::assertTrue($result['ai']);
        self::assertStringContainsString('gateway returned HTTP 401', $result['items'][0]['result'], 'The reason travels on the report.');
    }
}

/** Minimal factory stub: only the SEO rewriter matters here. */
final class StubAiFactory implements AiClientFactory
{
    public function __construct(private readonly SeoRewriter $rewriter)
    {
    }

    public function translatorForSite(Site $site): \Fastsite\Translation\Translator
    {
        return new \Fastsite\Translation\NullTranslator();
    }

    public function factCheckerForSite(Site $site): \Fastsite\FactCheck\FactChecker
    {
        return new \Fastsite\FactCheck\NullFactChecker();
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

    public function seoRewriterForSite(Site $site): SeoRewriter
    {
        return $this->rewriter;
    }

    public function translatorConfiguredForSite(Site $site): bool
    {
        return false;
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
