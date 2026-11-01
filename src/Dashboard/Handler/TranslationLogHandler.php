<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Http\ApiResponse;
use Fastsite\Translation\TranslationLog;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/translations/log — the live tail behind the Translations
 * screen's log panel: the newest translation calls (what was sent, what the
 * model actually answered, how long it took, the provider's own error) plus
 * the current job state, so one poll refreshes the whole panel.
 *
 * `after` returns only rows newer than the newest one already on screen,
 * which is what keeps a 2s poll cheap.
 */
final class TranslationLogHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly TranslationLog $log,
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return ApiResponse::error('Not found', 404);
        }

        $query = $request->getQueryParams();
        $after = max(0, (int) ($query['after'] ?? 0));

        return ApiResponse::success([
            'rows' => $this->log->recent($site->handle, 60, $after),
            'job' => $this->jobState($site->handle),
        ]);
    }

    /**
     * Live translate-job state for the panel header — including the error and
     * attempt count of the most recent FAILED attempt, which is the thing a
     * park-and-retry loop needs to explain about itself.
     *
     * @return array<string, mixed>
     */
    private function jobState(string $handle): array
    {
        try {
            $db = $this->databases->engine();
            $row = $db->fetchAssociative(
                "SELECT status, attempts, error, run_after, heartbeat_at FROM jobs
                 WHERE type = 'translate_content' AND site_handle = ?
                 ORDER BY COALESCE(finished_at, started_at, created_at) DESC LIMIT 1",
                [$handle],
            );
        } catch (\Throwable) {
            return ['status' => '', 'attempts' => 0, 'error' => '', 'retry_at' => ''];
        }
        if ($row === false) {
            return ['status' => 'none', 'attempts' => 0, 'error' => '', 'retry_at' => ''];
        }

        $runAfter = (string) ($row['run_after'] ?? '');

        return [
            'status' => (string) $row['status'],
            'attempts' => (int) ($row['attempts'] ?? 0),
            'error' => (string) ($row['error'] ?? ''),
            'retry_at' => $runAfter !== '' && $runAfter > $this->clock->iso() ? substr($runAfter, 11, 5) . ' UTC' : '',
        ];
    }
}
