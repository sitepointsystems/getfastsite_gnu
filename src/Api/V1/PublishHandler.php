<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PublishHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
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
        $input = ApiHelpers::jsonBody($request);
        $message = trim((string) ($input['message'] ?? '')) ?: 'publish: api request';

        $uuid = $this->publish->trigger($site, $message, 'api');

        $this->audit->log($access, 'site.publish', $handle, 'job:' . $uuid, [], ApiHelpers::clientIp($request));

        return ApiResponse::json(['success' => true, 'data' => ['job' => $uuid]], 202);
    }
}
