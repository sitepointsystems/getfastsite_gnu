<?php

declare(strict_types=1);

namespace Fastsite\Api\V1\Shell;

use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Shell\Service\ShellService;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ShellListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly ShellService $shell,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        return ApiResponse::success(['files' => $this->shell->listFiles($site)]);
    }
}
