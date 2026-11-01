<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Team\Service\TeamService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** POST /teams/action — create a team, invite by email, remove a member. */
final class TeamsActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly TeamService $teams,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $user = $this->dash->user($request);
        $uid = (int) $user['id'];

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'team-create':
                    $id = $this->teams->create($uid, (string) ($body['name'] ?? ''));

                    return $this->dash->redirect('/teams?team=' . $id, 'Team created.');

                case 'team-invite':
                    $teamId = (int) ($body['team_id'] ?? 0);
                    $token = $this->teams->invite($teamId, (string) ($body['email'] ?? ''), $uid);

                    // The link is shown once, straight from this response.
                    return $this->dash->redirect('/teams?team=' . $teamId . '&invite_token=' . $token, 'Invite created — share the link below.');

                case 'invite-accept':
                    $team = $this->teams->acceptForUser((int) ($body['invite_id'] ?? 0), $uid, (string) $user['email']);

                    return $this->dash->redirect('/teams', 'You joined "' . $team . '".');

                case 'team-remove':
                    $teamId = (int) ($body['team_id'] ?? 0);
                    $this->teams->removeMember($teamId, (int) ($body['user_id'] ?? 0), $uid);

                    return $this->dash->redirect('/teams?team=' . $teamId, 'Member removed.');

                default:
                    return $this->dash->redirect('/teams', '', 'Unknown action.');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect('/teams', '', $e->getMessage());
        }
    }
}
