<?php

declare(strict_types=1);

namespace Fastsite\Api\V1\Shell;

use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Shell\Service\ShellNotFoundException;
use Fastsite\Shell\Service\ShellService;
use Fastsite\Site\Repository\SiteRepository;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ShellGetHandler implements RequestHandlerInterface
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

        try {
            $content = $this->shell->readFile($site, (string) $request->getAttribute('path'));
        } catch (ShellNotFoundException $e) {
            return ApiResponse::error($e->getMessage(), 404);
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        // Return the raw file bytes so agents round-trip content exactly.
        return new Response(200, ['Content-Type' => 'text/plain; charset=utf-8'], $content);
    }
}
