<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Research\ResearchException;
use Fastsite\Research\ResearchService;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /api/v1/sites/{site}/research (scope content:read) — research a topic
 * with the web-grounded Perplexity model and return a markdown research brief
 * to write an article from. Body: {topic}. Uses the site's Research model.
 */
final class ResearchHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly ResearchService $research,
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

        $body = (array) ($request->getParsedBody() ?? []);
        $topic = trim((string) ($body['topic'] ?? ''));
        if ($topic === '') {
            return ApiResponse::error('A "topic" is required.', 422);
        }

        try {
            $result = $this->research->research($site, $topic);
        } catch (ResearchException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        $this->audit->log($access, 'content.research', $handle, 'topic', [], ApiHelpers::clientIp($request));

        return ApiResponse::success($result);
    }
}
