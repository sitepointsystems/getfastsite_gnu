<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Media\Repository\DbalMediaRepository;
use Fastsite\Media\Service\MediaService;
use Fastsite\Media\Service\SafeUrlFetcher;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Webhook\Mapper\AutoSeoMapper;
use Fastsite\Webhook\Service\WebhookService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Webhook integration registry, replay guard, and the AutoSeoMapper
 * create/update idempotency (with an unreachable image URL, which must be
 * dropped gracefully — never fail the article).
 */
final class WebhookTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private WebhookService $webhooks;
    private AutoSeoMapper $mapper;
    private DbalContentRepository $contentRepo;
    private Site $site;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-webhook-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');
        $this->databases->provisionSite('whtest');
        $migrator->migrate($this->databases->site('whtest'), $appRoot . '/migrations/site');

        $this->webhooks = new WebhookService($this->databases, new TokenCipher('test-secret'), $clock);

        $this->contentRepo = new DbalContentRepository($this->databases, $clock);
        $content = new ContentService($this->contentRepo, new ContentSanitizer(), $clock, new \Fastsite\Media\Repository\DbalMediaRepository($this->databases, $clock));
        $mediaRepo = new DbalMediaRepository($this->databases, $clock);
        $media = new MediaService($mediaRepo, $this->databases, new SafeUrlFetcher(), new NullLogger(), new \Fastsite\Media\NullImageOptimizer(new NullLogger()), $appRoot);
        $this->mapper = new AutoSeoMapper($content, $this->contentRepo, $media, new NullLogger());

        $this->site = new Site(1, 'whtest', 'Webhook Test', '', 'https://w.example.com', 'en', [], '/blog/{slug}/', '/{slug}/', '', 'main', '', 1, 'active');
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

    public function testIntegrationSecretRoundTrips(): void
    {
        $created = $this->webhooks->createIntegration('autoseo-x', 'whtest', 'autoseo');
        $found = $this->webhooks->findBySlug('autoseo-x');

        self::assertNotNull($found);
        self::assertSame($created['secret'], $found['secret']);
        self::assertSame('whtest', $found['site_handle']);
        self::assertTrue($found['active']);
    }

    public function testReplayGuardRejectsDuplicateDeliveryId(): void
    {
        $created = $this->webhooks->createIntegration('autoseo-x', 'whtest', 'autoseo');

        $first = $this->webhooks->recordDelivery($created['id'], 'del-1', 'e', [], '{}');
        $second = $this->webhooks->recordDelivery($created['id'], 'del-1', 'e', [], '{}');

        self::assertNotNull($first);
        self::assertNull($second); // replay
    }

    public function testAutoSeoCreatesThenUpdatesIdempotently(): void
    {
        $payload = [
            'id' => 'ext-99',
            'title' => 'First title',
            'body_html' => '<p>Body <a href="https://example.com/">link</a>.</p>'
                . '<img src="https://cdn.unreachable.invalid/x.jpg" alt="pic">',
            'status' => 'published',
        ];

        $noop = static function (): void {};
        $r1 = $this->mapper->process($this->site, $payload, $noop);
        self::assertStringContainsString('created', $r1);

        // Idempotent: same source id updates rather than duplicating.
        $payload['title'] = 'Updated title';
        $payload['author'] = 'Corrected Author';
        $r2 = $this->mapper->process($this->site, $payload, $noop);
        self::assertStringContainsString('updated', $r2);

        $count = (int) $this->databases->site('whtest')->fetchOne(
            "SELECT COUNT(*) FROM content WHERE source = 'autoseo' AND source_ref = 'ext-99'",
        );
        self::assertSame(1, $count);

        $post = $this->contentRepo->findBySource('whtest', 'autoseo', 'ext-99');
        self::assertNotNull($post);
        self::assertSame('Updated title', $post->title);
        // author_name updates on redelivery (was a silent no-op before).
        self::assertSame('Corrected Author', $post->authorName);
        // External link kept, unreachable image dropped (never wedges).
        self::assertStringContainsString('example.com', $post->bodyHtml);
        self::assertStringNotContainsString('cdn.unreachable', $post->bodyHtml);
    }

    public function testDecryptFailureFailsClosed(): void
    {
        // A secret written under a DIFFERENT APP_SECRET must not degrade to
        // an empty-key accept-anything gate — the integration becomes
        // unusable (findBySlug returns null) rather than forgeable.
        $clock = new SystemClock();
        $wrongKey = new WebhookService($this->databases, new TokenCipher('the-wrong-key'), $clock);
        $this->webhooks->createIntegration('autoseo-x', 'whtest', 'autoseo');

        self::assertNull($wrongKey->findBySlug('autoseo-x'));
    }

    public function testMissingIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->process($this->site, ['title' => 'no id'], static function (): void {});
    }

    public function testRealAutoSeoPayloadShapeIsNormalized(): void
    {
        // The field names the actual AutoSEO service sends (content_html,
        // metaDescription, heroImageUrl, publishedAt with milliseconds).
        $payload = [
            'event' => 'article.published',
            'id' => 4711,
            'title' => 'Native shape',
            'slug' => 'native-shape',
            'content_html' => '<p>Native body.</p>',
            'metaDescription' => 'A native meta description.',
            'heroImageUrl' => 'https://cdn.unreachable.invalid/hero.jpg',
            'heroImageAlt' => 'Hero alt',
            'languageCode' => 'en',
            'status' => 'published',
            'publishedAt' => '2026-08-10T12:34:56.000Z',
        ];

        $result = $this->mapper->process($this->site, $payload, static function (): void {});
        self::assertStringContainsString('created', $result);

        $post = $this->contentRepo->findBySource('whtest', 'autoseo', '4711');
        self::assertNotNull($post);
        self::assertStringContainsString('Native body', $post->bodyHtml);
        self::assertSame('A native meta description.', $post->excerpt);
        self::assertSame('A native meta description.', $post->seoDescription);
        // Milliseconds stripped into the strict ISO shape the engine requires.
        self::assertSame('2026-08-10T12:34:56Z', $post->publishedAt);
    }

    public function testTestEventIsSkippedNotPublished(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->process($this->site, ['event' => 'test'], static function (): void {});
    }

    public function testNonPrimaryLanguageDeliveryIsSkipped(): void
    {
        // The engine generates translations itself — a da/de/… delivery for
        // an en-primary site must be skipped, never published as a post.
        $this->expectException(\InvalidArgumentException::class);
        $this->mapper->process($this->site, [
            'id' => 4712,
            'title' => 'Dansk artikel',
            'content_html' => '<p>Dansk.</p>',
            'languageCode' => 'da',
        ], static function (): void {});
    }

    public function testSetSecretReplacesAndRoundTrips(): void
    {
        $created = $this->webhooks->createIntegration('autoseo-x', 'whtest', 'autoseo');
        $this->webhooks->setSecret($created['id'], 'whtest', 'aseo_wh_EXAMPLE00000000');

        $found = $this->webhooks->findBySlug('autoseo-x');
        self::assertNotNull($found);
        self::assertSame('aseo_wh_EXAMPLE00000000', $found['secret']);
    }

    public function testSetSecretRejectsShortValuesAndWrongSite(): void
    {
        $created = $this->webhooks->createIntegration('autoseo-x', 'whtest', 'autoseo');

        try {
            $this->webhooks->setSecret($created['id'], 'whtest', 'short');
            self::fail('short secret accepted');
        } catch (\InvalidArgumentException) {
        }

        try {
            $this->webhooks->setSecret($created['id'], 'other-site', 'aseo_wh_EXAMPLE00000000');
            self::fail('cross-site secret update accepted');
        } catch (\InvalidArgumentException) {
        }

        // Untouched by the failed attempts.
        $found = $this->webhooks->findBySlug('autoseo-x');
        self::assertSame($created['secret'], $found['secret']);
    }

    public function testDeliveryLifecycle(): void
    {
        $created = $this->webhooks->createIntegration('autoseo-x', 'whtest', 'autoseo');
        $id = $this->webhooks->recordDelivery($created['id'], 'del-1', 'e', [], '{"id":"a","title":"t"}');
        self::assertNotNull($id);

        $delivery = $this->webhooks->findDelivery($id);
        self::assertNotNull($delivery);
        self::assertSame('autoseo', $delivery['mapper']);
        self::assertSame('whtest', $delivery['site_handle']);

        $this->webhooks->markDelivery($id, 'processed', 'created post #1');
        $after = $this->webhooks->findDelivery($id);
        self::assertSame('processed', $after['status']);
    }
}
