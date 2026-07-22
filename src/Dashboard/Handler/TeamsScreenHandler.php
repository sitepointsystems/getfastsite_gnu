<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Team\Service\TeamService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /teams — the user's teams; ?team={id} opens one (members + invites).
 * Sites are attached to a team from the site's Settings screen.
 */
final class TeamsScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly TeamService $teams,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->dash->user($request);
        $teams = $this->teams->listForUser((int) $user['id']);

        $open = null;
        $members = [];
        $invites = [];
        $inviteLink = null;
        $selected = (int) ($request->getQueryParams()['team'] ?? 0);
        if ($selected > 0 && $this->teams->isMember($selected, (int) $user['id'])) {
            $open = $selected;
            $members = $this->teams->members($selected);
            $invites = $this->teams->pendingInvites($selected);
        }
        // A freshly-minted invite token is passed straight through (shown once).
        if (isset($request->getQueryParams()['invite_token'])) {
            $inviteLink = '/invite/' . (string) $request->getQueryParams()['invite_token'];
        }

        return $this->dash->render($request, 'teams.html.twig', [
            'title' => 'Teams — fastsite',
            'teams' => $teams,
            'open' => $open,
            'members' => $members,
            'invites' => $invites,
            'invite_link' => $inviteLink,
            'is_owner' => $open !== null && $this->teams->isOwner($open, (int) $user['id']),
            'me' => (int) $user['id'],
        ]);
    }
}
