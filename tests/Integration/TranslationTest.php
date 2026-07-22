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
        $sites->create('i18ntest', 'i18n Test', 'https://i.example.com');
        $sites->addLocale('i18ntest', 'da');

        $contentRepo = new DbalContentRepository($this->databases, $clock);
        $this->transRepo = new DbalTranslationRepository($this->databases, $clock);
        $this->translator = new FakeTranslator();
        $this->content = new ContentService($contentRepo, new ContentSanitizer(), $clock);
        $this->translations = new TranslationService($contentRepo, $this->transRepo, new FakeAiClientFactory($this->translator), new ContentSanitizer(), $this->databases, $clock, new NullLogger());
        $componentRenderer = new \Fastsite\Build\Service\ComponentRenderer(new \Fastsite\Component\Repository\DbalComponentRepository($this->databases, $clock), $this->databases, new NullLogger(), \dirname(__DIR__, 2));
        $mediaRepo = new DbalMediaRepository($this->databases, $clock);
        $this->build = new BuildService($contentRepo, $this->databases, $clock, new NullLogger(), new MediaRenderer($mediaRepo), $this->transRepo, $componentRenderer, new \Fastsite\Build\Figlet\FigletRenderer(), new \Fastsite\Build\Service\FileRenderer($mediaRepo, $this->databases, new NullLogger(), $appRoot), $mediaRepo, new \Fastsite\Site\Service\SiteScriptService($this->databases, $clock));

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

    public function testPublishTriggerRoutesMultilingualThroughTranslate(): void
    {
        $queue = new \Fastsite\Shared\Queue\JobQueue($this->databases, $this->redisClient(), new SystemClock());
        $trigger = new \Fastsite\Build\Service\PublishTrigger($queue);

        // Multilingual site (has 'da') → translate_content, not build_full.
        $trigger->trigger($this->site, 'msg', 'test');
        $type = $this->databases->engine()->fetchOne('SELECT type FROM jobs ORDER BY id DESC LIMIT 1');
        self::assertSame('translate_content', $type);
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
}

final class FakeTranslator implements Translator
{
    public int $calls = 0;
    public bool $injectScript = false;
    public ?string $fixedTitle = null;

    public function translate(string $content, string $fromLocale, string $toLocale, string $format = 'html'): string
    {
        ++$this->calls;
        if ($format === 'html') {
            if ($this->injectScript) {
                return $content . '<script>alert(1)</script>';
            }

            return preg_replace_callback('/>([^<]+)</', static fn (array $m): string => '>[' . strtoupper($toLocale) . '] ' . trim($m[1]) . '<', $content) ?? $content;
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
