<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Authoring\AuthoringGuideService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /api/v1/authoring-guide            — the platform authoring contract.
 * GET /api/v1/sites/{site}/authoring-guide — the same, with this site's
 * primary language, locales, and embed syntax injected. Scope sites:read.
 * The first thing an agent should fetch before designing a shell.
 */
final class AuthoringGuideHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly AuthoringGuideService $guide,
        private readonly SiteRepository $sites,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = $request->getAttribute('site');
        $site = null;

        if (\is_string($handle) && $handle !== '') {
            /** @var AccessContext $access */
            $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);
            $site = $this->sites->findByHandle($handle);
            // ScopeCheckMiddleware already pins {site} to the key's sites, but
            // don't leak the existence of a site the key can't see.
            if ($site === null || !$access->allowsSite($handle)) {
                return ApiResponse::error('Unknown site: ' . $handle, 404);
            }
        }

        return ApiResponse::success($this->guide->guide($site));
    }
}
