<?php

declare(strict_types=1);

namespace Fastsite\Api\V1\Shell;

use Fastsite\Access\AccessContext;
use Fastsite\Access\Service\AuditLogger;
use Fastsite\Api\V1\ApiHelpers;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Shell\Service\ShellNotFoundException;
use Fastsite\Shell\Service\ShellService;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ShellRestoreHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly ShellService $shell,
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
        $revNo = (int) $request->getAttribute('rev');

        try {
            $this->shell->restore($site, $revNo, 'api_key:' . $access->apiKeyId);
        } catch (ShellNotFoundException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        }

        $this->audit->log($access, 'shell.restore', $handle, 'rev:' . $revNo, [], ApiHelpers::clientIp($request));

        return ApiResponse::success(['restored' => $revNo]);
    }
}
