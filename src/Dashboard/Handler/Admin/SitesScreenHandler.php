<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler\Admin;

use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/** GET /admin/sites — screen 7: sites registry + webhook integrations. */
final class SitesScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly SiteRepository $sites,
        private readonly SiteDatabaseManager $databases,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->dash->render($request, 'admin-sites.html.twig', $this->context($request));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function context(ServerRequestInterface $request, array $extra = []): array
    {
        $engine = $this->databases->engine();

        return $extra + [
            'title' => 'Sites & webhooks — fastsite',
            'sites' => $this->sites->all(),
            'integrations' => $engine->fetchAllAssociative(
                'SELECT id, slug, site_handle, mapper, active, created_at FROM webhook_integrations ORDER BY id',
            ),
            'deliveries' => $engine->fetchAllAssociative(
                'SELECT d.id, i.slug, d.delivery_uuid, d.status, d.result, d.error, d.created_at
                 FROM webhook_deliveries d JOIN webhook_integrations i ON i.id = d.integration_id
                 ORDER BY d.id DESC LIMIT 25',
            ),
            'new_secret' => null,
        ];
    }
}
