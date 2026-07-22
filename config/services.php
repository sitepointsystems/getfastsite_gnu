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
            )
        ),

        Fastsite\Site\Service\SiteService::class => autowire()
            ->constructorParameter('appRoot', $config['app.root']),

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
                $c->get(Fastsite\Webhook\Job\ProcessWebhookHandler::class),
                $c->get(Fastsite\Translation\Job\TranslateContentHandler::class),
                $c->get(Fastsite\Backup\Job\BackupSiteHandler::class),
                $c->get(Fastsite\Backup\Job\BackupEngineHandler::class),
                $c->get(Fastsite\Backup\Job\RestoreSiteHandler::class),
                $c->get(Fastsite\Backup\Job\PruneBackupsHandler::class),
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

        Fastsite\Dashboard\Handler\SettingsScreenHandler::class => autowire()
            ->constructorParameter('globalTokenConfigured', ($config['git.token'] ?? '') !== ''),

        Fastsite\Publish\Service\CfTransport::class => autowire(Fastsite\Publish\Service\CurlCfTransport::class),

        Fastsite\Access\OAuth\Handler\MetadataHandler::class => autowire()
            ->constructorParameter('appUrl', $config['app.url']),
        Fastsite\Mcp\McpAuthMiddleware::class => autowire()
            ->constructorParameter('appUrl', $config['app.url']),

        Fastsite\Media\Service\MediaService::class => autowire()
            ->constructorParameter('appRoot', $config['app.root'])
            ->constructorParameter('shortpixelLossy', (int) ($config['shortpixel.lossy'] ?? 1)),

        Fastsite\Media\Service\SafeUrlFetcher::class => autowire(),

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
            ->constructorParameter('appSecret', $config['app.secret']),
    ]);
};
