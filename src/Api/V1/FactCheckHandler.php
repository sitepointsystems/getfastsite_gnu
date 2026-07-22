<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\FactCheck\FactCheckException;
use Fastsite\FactCheck\FactCheckService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/sites/{site}/content/{id}/fact-check (scope content:read) —
 * fact-check a post/page with the web-grounded Perplexity model and return the
 * report (also persisted on the content row). Requires fact-checking enabled
 * for the site.
 */
final class FactCheckHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly FactCheckService $factCheck,
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
        $id = (int) $request->getAttribute('id');

        try {
            $report = $this->factCheck->checkContent($site, $id);
        } catch (FactCheckException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $this->audit->log($access, 'content.fact_check', $handle, 'content:' . $id, [], ApiHelpers::clientIp($request));

        return ApiResponse::success($report);
    }
}
