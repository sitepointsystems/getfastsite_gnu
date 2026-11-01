<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Build\Service\PageAuditLog;
use Fastsite\Dashboard\Dash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/audit — what PageAudit found on the last build.
 *
 * Deliberately a READ of what the build already computed, not a second
 * analyser. Re-running the checks here would mean re-rendering the pages to
 * inspect them, which duplicates the build's logic and guarantees the two
 * drift apart — and the day they disagree, this screen is the one that lies,
 * because the build is what actually ships. "Build & publish" in the site nav
 * is therefore the refresh button, and it is already there.
 */
final class AuditScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly PageAuditLog $auditLog,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        return $this->dash->render($request, 'audit.html.twig', [
            'title' => $site->name . ' audit — fastsite',
            'site' => $site,
            'audit' => $this->auditLog->latest($site->handle),
        ]);
    }
}
