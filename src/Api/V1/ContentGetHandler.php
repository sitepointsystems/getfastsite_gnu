<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class ContentGetHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly ContentRepository $content,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $handle = (string) $request->getAttribute('site');
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            return ApiResponse::error('Unknown site: ' . $handle, 404);
        }

        $item = $this->content->findById($handle, (int) $request->getAttribute('id'));
        if ($item === null) {
            return ApiResponse::error('Content not found', 404);
        }

        return ApiResponse::success(ApiHelpers::contentToArray($item, $site));
    }
}
