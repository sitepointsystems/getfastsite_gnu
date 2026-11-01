<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Identity\Service\AvatarService;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /account/avatar — the logged-in user's own profile image (sidebar
 * circle). Session-gated like every dashboard route; the ?v= mtime the
 * sidebar appends busts the private cache after a re-upload.
 */
final class AvatarServeHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly AvatarService $avatars,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->dash->user($request);
        $path = $this->avatars->pathFor((int) $user['id']);
        if ($path === null) {
            return $this->dash->notFound();
        }

        return new Response(200, [
            'Content-Type' => AvatarService::mimeFor($path),
            'Cache-Control' => 'private, max-age=86400',
        ], (string) file_get_contents($path));
    }
}
