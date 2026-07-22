<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Team\Service\TeamService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /invite/{token} — accept a team invite. Behind SessionAuth (the `web`
 * group), so an unauthenticated visitor is bounced to /login first and returns
 * here after signing in / signing up.
 */
final class InviteAcceptHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly TeamService $teams,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->dash->user($request);
        $token = (string) $request->getAttribute('token');

        try {
            $teamName = $this->teams->accept($token, (int) $user['id']);
        } catch (\InvalidArgumentException $e) {
            return $this->dash->redirect('/teams', '', $e->getMessage());
        }

        return $this->dash->redirect('/teams', 'You joined "' . $teamName . '".');
    }
}
