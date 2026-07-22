<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Access\AccessContext;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class JobGetHandler implements RequestHandlerInterface
{
    public function __construct(private readonly SiteDatabaseManager $databases)
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $uuid = (string) $request->getAttribute('uuid');

        $row = $this->databases->engine()->fetchAssociative(
            'SELECT uuid, type, queue, site_handle, status, attempts, error, run_after,
                    started_at, finished_at, created_at
             FROM jobs WHERE uuid = ?',
            [$uuid],
        );
        if ($row === false) {
            return ApiResponse::error('Job not found', 404);
        }

        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);
        if ($row['site_handle'] !== null && !$access->allowsSite((string) $row['site_handle'])) {
            return ApiResponse::error('Job not found', 404);
        }

        return ApiResponse::success(['job' => $row]);
    }
}
