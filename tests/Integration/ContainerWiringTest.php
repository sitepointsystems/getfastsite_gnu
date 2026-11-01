<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Kernel\Application;
use PHPUnit\Framework\TestCase;

/**
 * php-di does NOT autowire optional (defaulted) constructor params — each one
 * must be wired explicitly in config/services.php or it is silently null and
 * the feature it powers no-ops (the per-site ShortPixel key save did exactly
 * that). This boots the REAL container and asserts every known optional
 * dependency actually arrives.
 */
final class ContainerWiringTest extends TestCase
{
    public function testOptionalConstructorParamsAreExplicitlyWired(): void
    {
        try {
            $container = Application::boot()->container();
        } catch (\Throwable $e) {
            self::markTestSkipped('Application cannot boot in this environment: ' . $e->getMessage());
        }

        $expectations = [
            [\Fastsite\Site\Service\SiteService::class, 'cipher'],      // ShortPixel key save encrypts through it
            [\Fastsite\Media\Service\MediaService::class, 'cipher'],    // per-site key decrypt for the optimizer
            [\Fastsite\Media\Service\MediaService::class, 'clock'],     // optimized_at timestamps
            [\Fastsite\Media\Service\MediaService::class, 'ai'],        // auto alt text
            [\Fastsite\Dashboard\Dash::class, 'avatars'],               // sidebar avatar
            [\Fastsite\Publish\Service\GitPublisher::class, 'credentials'], // per-account GitHub tokens
            [\Fastsite\Publish\Service\GitPublisher::class, 'secrets'],     // shared pre-publish secret scan
            [\Fastsite\Publish\Service\FtpPublisher::class, 'transport'],   // upload to the customer's own host
            [\Fastsite\Publish\Service\FtpPublisher::class, 'cipher'],      // decrypts the stored FTP password/key
            [\Fastsite\Build\Service\BuildService::class, 'htaccess'],      // .htaccess for ftp-published sites
            // Without this the Turnstile secret never decrypts, every
            // verification comes back inconclusive, and the challenge silently
            // does nothing while the dashboard reports it as on.
            [\Fastsite\Form\Handler\FormSubmitHandler::class, 'cipher'],
            // Without this a new site gets no editable 404 row and silently
            // falls back to the shell's built-in page.
            [\Fastsite\Site\Service\SiteService::class, 'content'],
            // Without this a PURGE queues no build. It is the one content
            // write the scheduler's level-based reconciler can never detect
            // (it deletes the row whose timestamp would signal it), so the
            // purged page stays live on the CDN with nothing to heal it.
            [\Fastsite\Content\Service\ContentService::class, 'publish'],
            // Without this a post write that sends a category is refused.
            [\Fastsite\Content\Service\ContentService::class, 'categories'],
            // Without this a permalink change moves every post URL and
            // records no 301 — every inbound link breaks silently.
            [\Fastsite\Site\Service\SiteService::class, 'permalinks'],
            // A category write touches no content row, so without this it
            // never reaches the live site.
            [\Fastsite\Category\Service\CategoryService::class, 'publish'],
            // Nullable rather than optional, because the PUBLIC trimmer deletes
            // the seeder outright. The guard that allows that must not disable
            // it HERE — a class_exists wiring that silently resolves to null in
            // the flavour that should have it is the AiRoutingTest mistake again.
            [\Fastsite\Mcp\McpTools::class, 'marketingSeeder'],
        ];

        foreach ($expectations as [$class, $prop]) {
            $service = $container->get($class);
            $value = (new \ReflectionProperty($service, $prop))->getValue($service);
            self::assertNotNull(
                $value,
                sprintf(
                    '%s::$%s is null — php-di does not autowire optional params; wire it with ->constructorParameter() in config/services.php.',
                    $class,
                    $prop,
                ),
            );
        }
    }
}
