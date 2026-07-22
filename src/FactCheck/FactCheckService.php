<?php

declare(strict_types=1);

namespace Fastsite\FactCheck;

use Fastsite\Ai\AiClientFactory;
use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Content\Service\ContentService;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;

/**
 * Fact-checking of content. The UI uses this on demand — either report-only
 * (checkContent) or report + auto-correct (autoCorrectContent). The API/MCP
 * write paths call checkAndCorrectBody so a fact-check-enabled site always
 * publishes corrected content. Gated by the site's fact_check_enabled flag
 * (opt-in, spends AI credits). Verdicts fixed by auto-correct: contradicted
 * AND unverified.
 */
final class FactCheckService
{
    /** Verdicts the auto-correct rewrites. */
    private const CORRECTABLE = ['contradicted', 'unverified'];

    public function __construct(
        private readonly AiClientFactory $ai,
        private readonly ContentRepository $content,
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
        private readonly ContentService $contentService,
    ) {
    }

    public function isConfigured(Site $site): bool
    {
        return $this->ai->factCheckerConfiguredForSite($site);
    }

    /** The fact-check model that will run for this site ('' when unconfigured). */
    public function model(Site $site): string
    {
        return $this->ai->factCheckModelForSite($site);
    }

    /**
     * Fact-check a content row and persist the report. Returns the stored
     * array (report + checked_at).
     *
     * @return array<string, mixed>
     */
    public function checkContent(Site $site, int $id): array
    {
        if (!$site->factCheckEnabled) {
            throw new FactCheckException('Fact-checking is disabled for this site — enable it in Site settings.');
        }
        $row = $this->content->findById($site->handle, $id)
            ?? throw new FactCheckException('Content #' . $id . ' not found.');

        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($row->bodyHtml), ENT_QUOTES | ENT_HTML5)));
        $report = $this->ai->factCheckerForSite($site)->check($text, $row->title);

        $stored = $report->toArray() + ['checked_at' => $this->clock->iso()];
        try {
            $this->databases->site($site->handle)->executeStatement(
                'UPDATE content SET fact_check = ? WHERE id = ?',
                [json_encode($stored, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $id],
            );
        } catch (\Throwable) {
            // Persistence is best-effort — the report is still returned.
        }

        return $stored;
    }

    /**
     * Fact-check raw body HTML and apply corrections (contradicted + unverified
     * claims), WITHOUT persisting. Used by the API/MCP write paths to correct
     * content before it is stored. Fail-safe: returns the original body if the
     * checker/corrector isn't configured or finds nothing to fix.
     *
     * @return array{report: array<string, mixed>, body: string, changed: bool, corrected: int}
     */
    public function checkAndCorrectBody(Site $site, string $title, string $bodyHtml): array
    {
        $checker = $this->ai->factCheckerForSite($site);
        if (!$checker->isConfigured() || trim($bodyHtml) === '') {
            return ['report' => [], 'body' => $bodyHtml, 'changed' => false, 'corrected' => 0];
        }

        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($bodyHtml), ENT_QUOTES | ENT_HTML5)));
        $report = $checker->check($text, $title);
        $stored = $report->toArray() + ['checked_at' => $this->clock->iso()];

        $fixable = array_values(array_filter(
            $report->claims,
            static fn (array $c): bool => \in_array($c['verdict'] ?? '', self::CORRECTABLE, true),
        ));
        if ($fixable === []) {
            return ['report' => $stored, 'body' => $bodyHtml, 'changed' => false, 'corrected' => 0];
        }

        $corrected = $this->ai->correctorForSite($site)->rewrite($bodyHtml, $title, $fixable);
        $changed = trim($corrected) !== trim($bodyHtml) && trim($corrected) !== '';

        return [
            'report' => $stored,
            'body' => $changed ? $corrected : $bodyHtml,
            'changed' => $changed,
            'corrected' => $changed ? \count($fixable) : 0,
        ];
    }

    /**
     * UI auto-correct: fact-check a stored row, apply corrections to its body
     * via ContentService (re-sanitized + snapshotted + rebuild-triggered by the
     * caller), and persist the report. Returns the report + what changed.
     *
     * @return array{report: array<string, mixed>, changed: bool, corrected: int}
     */
    public function autoCorrectContent(Site $site, int $id, string $actor = 'dashboard'): array
    {
        if (!$site->factCheckEnabled) {
            throw new FactCheckException('Fact-checking is disabled for this site — enable it in Site settings.');
        }
        $row = $this->content->findById($site->handle, $id)
            ?? throw new FactCheckException('Content #' . $id . ' not found.');

        $result = $this->checkAndCorrectBody($site, $row->title, $row->bodyHtml);
        if ($result['changed']) {
            $this->contentService->update($site, $id, ['body_html' => $result['body']], $actor);
        }
        if ($result['report'] !== []) {
            try {
                $this->databases->site($site->handle)->executeStatement(
                    'UPDATE content SET fact_check = ? WHERE id = ?',
                    [json_encode($result['report'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $id],
                );
            } catch (\Throwable) {
                // Best-effort — the report is still returned.
            }
        }

        return ['report' => $result['report'], 'changed' => $result['changed'], 'corrected' => $result['corrected']];
    }

    /**
     * The stored report for a content row, or null if never checked.
     *
     * @return array<string, mixed>|null
     */
    public function storedReport(Site $site, int $id): ?array
    {
        try {
            $json = $this->databases->site($site->handle)->fetchOne('SELECT fact_check FROM content WHERE id = ?', [$id]);
            if (!\is_string($json) || $json === '') {
                return null;
            }
            $decoded = json_decode($json, true);

            return \is_array($decoded) ? $decoded : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
