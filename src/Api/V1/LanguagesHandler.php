<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Site\Service\SiteService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Languages REST surface (#2), scope `translations:manage`:
 *   POST   /api/v1/sites/{site}/languages          add {locale} + queue translation
 *   DELETE /api/v1/sites/{site}/languages/{locale}  remove + delete translations + republish
 *
 * Mirrors the MCP add_language/remove_language tools and the settings screen.
 */
final class LanguagesHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteService $siteService,
        private readonly JobQueue $queue,
        private readonly PublishTrigger $publish,
        private readonly AuditLogger $audit,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);

        if (strtoupper($request->getMethod()) === 'DELETE') {
            $locale = (string) $request->getAttribute('locale');
            try {
                $locales = $this->siteService->removeLocale($handle, $locale);
            } catch (\InvalidArgumentException $e) {
                return ApiResponse::error($e->getMessage(), 422);
            } catch (\RuntimeException $e) {
                return ApiResponse::error($e->getMessage(), 404);
            }
            $this->publish->trigger($site, 'publish: language removed (api)', 'api');
            $this->audit->log($access, 'language.remove', $handle, 'locale:' . $locale, [], ApiHelpers::clientIp($request));

            return ApiResponse::success(['locales' => $locales]);
        }

        // POST — add a locale + queue auto-translation.
        $body = ApiHelpers::jsonBody($request);
        try {
            $locales = $this->siteService->addLocale($handle, (string) ($body['locale'] ?? ''));
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }
        $uuid = $this->queue->enqueue('translate_content', $handle, ['trigger' => 'api'], 'default', 'translate:' . $handle);
        $this->audit->log($access, 'language.add', $handle, 'locale:' . ($body['locale'] ?? ''), [], ApiHelpers::clientIp($request));

        return ApiResponse::success(['locales' => $locales, 'translation_job' => $uuid], 201);
    }
}
