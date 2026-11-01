<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

use function DI\autowire;
use function DI\factory;
use function DI\get;

/**
 * DI definitions. Autowiring is on; only interfaces, scalars, and services
 * with non-trivial construction are wired explicitly.
 */
return static function (ContainerBuilder $builder, array $config): void {
    $builder->useAutowiring(true);

    $builder->addDefinitions($config);

    $builder->addDefinitions([
        Clock::class => autowire(SystemClock::class),

        LoggerInterface::class => factory(static function () use ($config): LoggerInterface {
            $logDir = $config['app.log_path'];
            if (!is_dir($logDir)) {
                mkdir($logDir, 0770, true);
            }
            $logger = new Logger('fastsite');
            $level = $config['app.env'] === 'local' ? Level::Debug : Level::Info;
            $logger->pushHandler(new StreamHandler($logDir . '/fastsite.log', $level));

            return $logger;
        }),

        TokenCipher::class => factory(static fn (): TokenCipher => new TokenCipher($config['app.secret'])),

        SiteDatabaseManager::class => factory(
            static fn (): SiteDatabaseManager => new SiteDatabaseManager($config['app.data_path'])
        ),

        Fastsite\Site\Repository\SiteRepository::class => autowire(Fastsite\Site\Repository\DbalSiteRepository::class),
        Fastsite\Content\Repository\ContentRepository::class => autowire(Fastsite\Content\Repository\DbalContentRepository::class),
        Fastsite\Media\Repository\MediaRepository::class => autowire(Fastsite\Media\Repository\DbalMediaRepository::class),
        Fastsite\Translation\Repository\TranslationRepository::class => autowire(Fastsite\Translation\Repository\DbalTranslationRepository::class),
        Fastsite\Component\Repository\ComponentRepository::class => autowire(Fastsite\Component\Repository\DbalComponentRepository::class),
        Fastsite\Category\Repository\CategoryRepository::class => autowire(Fastsite\Category\Repository\DbalCategoryRepository::class),

        Fastsite\Ops\BuildInfoService::class => autowire()
            ->constructorParameter('appRoot', $config['app.root']),
        Fastsite\Component\Service\ComponentService::class => autowire()
            ->constructorParameter('appRoot', $config['app.root']),
        Fastsite\Build\Service\ComponentRenderer::class => autowire()
            ->constructorParameter('appRoot', $config['app.root']),
        Fastsite\Build\Service\FileRenderer::class => autowire()
            ->constructorParameter('appRoot', $config['app.root']),

        // AI clients are resolved PER SITE at run time (translation via the
        // owner's endpointr gateway when configured, else the site's own
        // user-paid OpenRouter credential; fact-check always via that
        // credential). The endpointr keys are the only .env-held AI config;
        // the OpenRouter key lives in the per-account ai_credentials vault.
        Fastsite\Ai\AiClientFactory::class => factory(
            static fn (\Psr\Container\ContainerInterface $c): Fastsite\Ai\AiClientFactory => new Fastsite\Ai\DefaultAiClientFactory(
                $c->get(Fastsite\Ai\AiCredentialService::class),
                (string) ($config['endpointr.url'] ?? ''),
                (string) ($config['endpointr.key'] ?? ''),
                (string) ($config['ai.default_base_url'] ?? 'https://openrouter.ai/api'),
                (string) ($config['ai.default_model'] ?? 'openai/gpt-4o-mini'),
                (string) ($config['ai.factcheck_model'] ?? 'perplexity/sonar'),
                (string) ($config['ai.research_model'] ?? 'perplexity/sonar'),
                (int) ($config['ai.translate_timeout'] ?? 420),
                (string) ($config['ai.translate_model'] ?? 'google/gemini-2.5-flash-lite'),
            )
        ),

        Fastsite\Site\Service\SiteService::class => autowire()
            ->constructorParameter('appRoot', $config['app.root'])
            // Optional params — php-di won't autowire them. Without the cipher
            // the per-site ShortPixel key save is a SILENT no-op; without the
            // content service a new site gets no editable 404 row and quietly
            // falls back to the shell's built-in page. Both asserted by
            // ContainerWiringTest.
            ->constructorParameter('cipher', get(TokenCipher::class))
            ->constructorParameter('content', get(Fastsite\Content\Service\ContentService::class))
            // Without it a permalink change moves every post URL and leaves no
            // 301 behind. Asserted by ContainerWiringTest.
            ->constructorParameter('permalinks', get(Fastsite\Category\Service\PermalinkMover::class)),

        // Optional param — php-di won't autowire it. Without it a PURGE
        // (the one content write a level-based reconciler can never detect,
        // because it deletes the very row whose timestamp would signal it)
        // queues no build, and the purged page stays live on the CDN forever.
        // Asserted by ContainerWiringTest.
        Fastsite\Content\Service\ContentService::class => autowire()
            ->constructorParameter('publish', get(Fastsite\Build\Service\PublishTrigger::class))
            // Without it a write that sends a category is refused. Asserted by
            // ContainerWiringTest.
            ->constructorParameter('categories', get(Fastsite\Category\Repository\CategoryRepository::class)),

        // Optional param: a category write touches no content row, so the
        // reconciler never sees it and it has to republish itself.
        Fastsite\Category\Service\CategoryService::class => autowire()
            ->constructorParameter('publish', get(Fastsite\Build\Service\PublishTrigger::class)),

        Fastsite\Build\Service\BuildService::class => autowire()
            ->constructorParameter('categories', get(Fastsite\Category\Repository\CategoryRepository::class)),

        Fastsite\Site\Service\MarketingSeeder::class => autowire()
            ->constructorParameter('appRoot', $config['app.root']),

        Fastsite\Publish\Service\GitPublisher::class => autowire()
            ->constructorParameter('authorName', $config['git.author_name'])
            ->constructorParameter('authorEmail', $config['git.author_email'])
            ->constructorParameter('githubToken', $config['git.token'])
            ->constructorParameter('credentials', get(Fastsite\Publish\Service\GithubCredentialService::class)),

        Predis\Client::class => factory(static fn (): Predis\Client => new Predis\Client($config['redis.url'])),

        Fastsite\Backup\Storage\StorageClient::class => factory(
            static function () use ($config): Fastsite\Backup\Storage\StorageClient {
                $r2 = new Fastsite\Backup\Storage\S3Client(
                    $config['r2.endpoint'],
                    $config['r2.bucket'],
                    $config['r2.access_key'],
                    $config['r2.secret_key'],
                );
                if ($r2->isConfigured()) {
                    return $r2;
                }

                return new Fastsite\Backup\Storage\LocalStorageClient($config['app.data_path'] . '/backups-store');
            }
        ),

        Fastsite\Shared\Queue\JobHandlerRegistry::class => factory(
            static fn (\Psr\Container\ContainerInterface $c): Fastsite\Shared\Queue\JobHandlerRegistry => new Fastsite\Shared\Queue\JobHandlerRegistry([
                $c->get(Fastsite\Build\Job\BuildFullHandler::class),
                $c->get(Fastsite\Publish\Job\PublishGitHandler::class),
                $c->get(Fastsite\Publish\Job\PublishFtpHandler::class),
                $c->get(Fastsite\Webhook\Job\ProcessWebhookHandler::class),
                $c->get(Fastsite\Translation\Job\TranslateContentHandler::class),
                $c->get(Fastsite\Media\Job\OptimizeMediaHandler::class),
                $c->get(Fastsite\Backup\Job\BackupSiteHandler::class),
                $c->get(Fastsite\Backup\Job\BackupEngineHandler::class),
                $c->get(Fastsite\Backup\Job\RestoreSiteHandler::class),
                $c->get(Fastsite\Backup\Job\PruneBackupsHandler::class),
                $c->get(Fastsite\Seo\Job\SeoFixHandler::class),
                $c->get(Fastsite\Form\Job\SendFormEmailHandler::class),
                $c->get(Fastsite\Form\Job\PurgeSpamHandler::class),
            ])
        ),

        Fastsite\Webhook\Mapper\WebhookMapperRegistry::class => factory(
            static fn (\Psr\Container\ContainerInterface $c): Fastsite\Webhook\Mapper\WebhookMapperRegistry => new Fastsite\Webhook\Mapper\WebhookMapperRegistry([
                $c->get(Fastsite\Webhook\Mapper\AutoSeoMapper::class),
                // Built-in system webhooks (#3): add a post/page/media/component.
                $c->get(Fastsite\Webhook\Mapper\CreatePostMapper::class),
                $c->get(Fastsite\Webhook\Mapper\CreatePageMapper::class),
                $c->get(Fastsite\Webhook\Mapper\ImportMediaMapper::class),
                $c->get(Fastsite\Webhook\Mapper\UpsertComponentMapper::class),
            ])
        ),

        Fastsite\Ops\DoctorService::class => autowire()
            ->constructorParameter('appSecret', $config['app.secret']),

        Fastsite\Http\EngineView::class => autowire()
            ->constructorParameter('appRoot', $config['app.root']),
        Fastsite\Serve\DirectServeGate::class => autowire()
            ->constructorParameter('appUrl', $config['app.url']),

        // Mail. The SMTP client is hand-rolled behind an interface for the
        // same reason CfTransport and FtpTransport are: everything above this
        // line is testable without a mail server.
        Fastsite\Mail\MailTransport::class => autowire(Fastsite\Mail\SmtpTransport::class),

        // The form action baked into every published page points at the
        // engine's public origin, so APP_URL has to be the real one in
        // production — the same requirement the OAuth issuer already has.
        Fastsite\Form\Service\FormRenderer::class => autowire()
            ->constructorParameter('engineBaseUrl', $config['app.url']),

        // Form spam defence, in two layers that cover different attacks.
        //
        // The MX resolver is a seam so SpamScorer's tests never touch DNS; the
        // captcha verifier is the CfTransport pattern again, so the submit
        // path can be tested against canned verdicts without Cloudflare.
        Fastsite\Form\Spam\MxResolver::class => autowire(Fastsite\Form\Spam\DnsMxResolver::class),
        Fastsite\Form\Spam\CaptchaVerifier::class => autowire(Fastsite\Form\Spam\TurnstileVerifier::class),

        // `cipher` is OPTIONAL on this constructor, and php-di does not
        // autowire optional params — without this line the Turnstile secret
        // silently never decrypts, every verification comes back
        // inconclusive, and the challenge quietly does nothing at all while
        // appearing to be on. Exactly the ShortPixel-key bug below.
        // ContainerWiringTest asserts it arrives.
        Fastsite\Form\Handler\FormSubmitHandler::class => autowire()
            ->constructorParameter('cipher', get(TokenCipher::class)),

        Fastsite\Dashboard\Handler\SettingsScreenHandler::class => autowire()
            ->constructorParameter('globalTokenConfigured', ($config['git.token'] ?? '') !== '')
            ->constructorParameter('engineShortpixelConfigured', ($config['shortpixel.key'] ?? '') !== ''),
        Fastsite\Dashboard\Handler\WebhooksScreenHandler::class => autowire()
            ->constructorParameter('appUrl', $config['app.url']),
        Fastsite\Dashboard\Handler\WebhooksActionHandler::class => autowire()
            ->constructorParameter('appUrl', $config['app.url']),
        Fastsite\Api\V1\WebhooksApiHandler::class => autowire()
            ->constructorParameter('appUrl', $config['app.url']),
        Fastsite\Mcp\McpTools::class => autowire()
            // The public export deletes the marketing seeder, so resolve it
            // only when the class is actually present.
            ->constructorParameter(
                'marketingSeeder',
                class_exists(Fastsite\Site\Service\MarketingSeeder::class)
                    ? get(Fastsite\Site\Service\MarketingSeeder::class)
                    : null,
            )
            ->constructorParameter('appUrl', $config['app.url']),

        Fastsite\Publish\Service\CfTransport::class => autowire(Fastsite\Publish\Service\CurlCfTransport::class),

        // The publish transport for `ftp` sites. Same seam as CfTransport: an
        // interface so FtpPublisher's mirror algorithm — where the "never
        // delete what we did not upload" guarantee lives — is tested against a
        // fake rather than a real server.
        Fastsite\Publish\Service\FtpTransport::class => autowire(Fastsite\Publish\Service\CurlFtpTransport::class),

        Fastsite\Access\OAuth\Handler\MetadataHandler::class => autowire()
            ->constructorParameter('appUrl', $config['app.url']),
        Fastsite\Mcp\McpAuthMiddleware::class => autowire()
            ->constructorParameter('appUrl', $config['app.url']),

        Fastsite\Media\Service\MediaService::class => autowire()
            ->constructorParameter('appRoot', $config['app.root'])
            ->constructorParameter('shortpixelLossy', (int) ($config['shortpixel.lossy'] ?? 1))
            // Optional params — php-di won't autowire them (see Dash below).
            // Without the cipher a stored per-site ShortPixel key never decrypts.
            ->constructorParameter('ai', get(Fastsite\Ai\AiClientFactory::class))
            ->constructorParameter('cipher', get(TokenCipher::class))
            ->constructorParameter('clock', get(Clock::class)),

        Fastsite\Media\Service\SafeUrlFetcher::class => autowire(),

        // appUrl is what the minted upload_url is built from, so a deployment
        // left on the localhost default hands out URLs nobody can reach — the
        // same APP_URL dependency the OAuth issuer and form actions have.
        Fastsite\Media\Service\UploadTicketService::class => autowire()
            ->constructorParameter('appUrl', $config['app.url']),

        Fastsite\Media\ImageOptimizer::class => factory(static function (\Psr\Container\ContainerInterface $c) use ($config): Fastsite\Media\ImageOptimizer {
            if (($config['shortpixel.key'] ?? '') === '') {
                return new Fastsite\Media\NullImageOptimizer($c->get(Psr\Log\LoggerInterface::class));
            }

            return new Fastsite\Media\ShortPixelOptimizer(
                $config['shortpixel.key'],
                $c->get(Psr\Log\LoggerInterface::class),
                (int) ($config['shortpixel.lossy'] ?? 1),
            );
        }),

        Fastsite\Dashboard\Dash::class => autowire()
            ->constructorParameter('appSecret', $config['app.secret'])
            // Optional constructor params are NOT autowired by php-di — the
            // avatar service must be wired explicitly or the sidebar circle
            // silently never shows the uploaded image.
            ->constructorParameter('avatars', get(Fastsite\Identity\Service\AvatarService::class)),
    ]);
};
