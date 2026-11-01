<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Site\Service\SiteService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/action — site creation from the overview (admins + editors;
 * the creator is recorded as the site owner) and the UI-only trash lifecycle
 * from the overview card kebab + the Trash view: soft-delete, restore, and
 * permanent purge. There is deliberately NO API/MCP equivalent of deletion
 * (site deletion is dashboard-only). Access is scoped via Dash::anySiteFor
 * (admin or a member of the site).
 */
final class SitesManageActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly SiteService $sites,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $handle = trim((string) ($body['handle'] ?? ''));

        // Creation comes first — the site doesn't exist yet, so the access
        // lookup below can't apply. Admins and editors only.
        if ((string) ($body['action'] ?? '') === 'create-site') {
            $user = $this->dash->user($request);
            if (!\in_array($user['role'], ['admin', 'editor'], true)) {
                return $this->dash->redirect('/', '', 'Your account cannot create sites — ask an admin to make you an editor.');
            }
            try {
                $site = $this->sites->create(
                    $handle,
                    trim((string) ($body['name'] ?? '')) ?: $handle,
                    trim((string) ($body['url'] ?? '')),
                    '',
                    trim((string) ($body['locale'] ?? '')) ?: 'en',
                    $user['id'],
                );
            } catch (\InvalidArgumentException | \RuntimeException $e) {
                return $this->dash->redirect('/', '', $e->getMessage());
            }

            // A site created while a workspace is selected lands in it — the
            // cookie value is access-validated by selectedWorkspaceId.
            $workspaceId = $this->dash->selectedWorkspaceId($request);
            if ($workspaceId !== null) {
                $this->sites->update($site->handle, ['workspace_id' => $workspaceId]);
            }

            return $this->dash->redirect('/sites/' . $site->handle . '/settings', 'Site "' . $site->handle . '" created — shell scaffolded, DB migrated.');
        }

        $site = $this->dash->anySiteFor($request, $handle);
        if ($site === null) {
            return $this->dash->notFound();
        }

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'soft-delete-site':
                    $this->sites->softDelete($handle);

                    return $this->dash->redirect('/', $site->name . ' moved to trash.');

                case 'restore-site':
                    $this->sites->restore($handle);

                    return $this->dash->redirect('/?trash=1', $site->name . ' restored.');

                case 'purge-site':
                    // Guard: the operator must re-type the handle to confirm.
                    if (trim((string) ($body['confirm_handle'] ?? '')) !== $handle) {
                        return $this->dash->redirect('/?trash=1', '', 'Type the handle exactly to permanently delete.');
                    }
                    $this->sites->purge($handle);

                    return $this->dash->redirect('/?trash=1', $site->name . ' permanently deleted.');

                default:
                    return $this->dash->redirect('/', '', 'Unknown action.');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect('/?trash=1', '', $e->getMessage());
        }
    }
}
