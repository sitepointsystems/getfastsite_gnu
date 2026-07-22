<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Site\Service\SiteService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/action — UI-only site trash lifecycle from the overview card
 * kebab + the Trash view: soft-delete, restore, and permanent purge. There is
 * deliberately NO API/MCP equivalent (site deletion is dashboard-only). Access
 * is scoped via Dash::anySiteFor (admin or a member of the site).
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
