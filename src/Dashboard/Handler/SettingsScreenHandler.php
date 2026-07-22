<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Authoring\AuthoringGuideService;
use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Publish\Service\CloudflarePagesService;
use Fastsite\Publish\Service\GithubCredentialService;
use Fastsite\Shell\Service\ShellService;
use Fastsite\Site\Service\RedirectService;
use Fastsite\Site\Service\SiteScriptService;
use Fastsite\Team\Service\TeamService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/settings — screen 4: languages, components, shell file
 * tree (+ inline editor via ?file=), snapshots.
 */
final class SettingsScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly ShellService $shell,
        private readonly SiteDatabaseManager $databases,
        private readonly AuthoringGuideService $authoring,
        private readonly SiteScriptService $scripts,
        private readonly RedirectService $redirects,
        private readonly GithubCredentialService $githubCreds,
        private readonly TeamService $teams,
        private readonly \Fastsite\Ai\AiClientFactory $ai,
        private readonly \Fastsite\Ai\AiCredentialService $aiCreds,
        // Whether a global GITHUB_TOKEN exists (bool only — the token itself
        // never reaches a template). The "Publish with" select's fallback
        // option must not pretend a token exists when the env var is empty.
        private readonly bool $globalTokenConfigured = false,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $query = $request->getQueryParams();

        $editFile = null;
        $editContent = '';
        if (isset($query['file'])) {
            try {
                $editFile = (string) $query['file'];
                $editContent = $this->shell->readFile($site, $editFile);
            } catch (\Throwable) {
                $editFile = null;
            }
        }

        $components = [];
        $revisions = [];
        $images = [];
        try {
            $components = $this->databases->site($site->handle)->fetchAllAssociative(
                'SELECT handle, type, status FROM components ORDER BY handle',
            );
        } catch (\Throwable) {
            // Optional panel — never blank the screen.
        }
        try {
            // Image choices for the favicon / logo pickers.
            $images = $this->databases->site($site->handle)->fetchAllAssociative(
                "SELECT id, original_name, alt FROM media
                 WHERE kind = 'image' AND status = 'ready' AND deleted_at IS NULL
                 ORDER BY id DESC LIMIT 200",
            );
        } catch (\Throwable) {
            // Optional panel — never blank the screen.
        }
        try {
            $revisions = $this->databases->site($site->handle)->fetchAllAssociative(
                'SELECT rev_no, note, created_by, created_at FROM shell_revisions WHERE deleted_at IS NULL ORDER BY rev_no DESC LIMIT 10',
            );
        } catch (\Throwable) {
            // Optional panel — never blank the screen.
        }

        // Per-locale translation status so the user can SEE that auto-translate
        // is working (or why it isn't) — a common "is this broken?" question.
        $translationStatus = [];
        if ($site->locales !== []) {
            try {
                $db = $this->databases->site($site->handle);
                $totalPublished = (int) $db->fetchOne(
                    "SELECT COUNT(*) FROM content WHERE status = 'published' AND type IN ('post', 'page') AND deleted_at IS NULL",
                );
                $byLocale = [];
                foreach ($db->fetchAllAssociative(
                    "SELECT ct.locale AS locale,
                        SUM(CASE WHEN ct.status = 'failed' THEN 1 ELSE 0 END) AS failed,
                        SUM(CASE WHEN ct.status = 'manual' THEN 1 ELSE 0 END) AS manual,
                        SUM(CASE WHEN ct.status = 'translated' AND ct.source_hash = c.content_hash THEN 1 ELSE 0 END) AS translated,
                        SUM(CASE WHEN ct.status = 'translated' AND ct.source_hash != c.content_hash THEN 1 ELSE 0 END) AS stale
                     FROM content_translations ct JOIN content c ON c.id = ct.content_id
                     WHERE c.status = 'published' AND c.deleted_at IS NULL
                     GROUP BY ct.locale",
                ) as $r) {
                    $byLocale[(string) $r['locale']] = $r;
                }
                // Latest error message per locale, so a "Failed" badge can say
                // WHY (e.g. missing/invalid AI key, gateway HTTP error).
                $errors = [];
                foreach ($db->fetchAllAssociative(
                    "SELECT locale, error FROM content_translations
                     WHERE status = 'failed' AND error IS NOT NULL AND error != ''
                     ORDER BY updated_at DESC",
                ) as $e) {
                    $errors[(string) $e['locale']] ??= (string) $e['error'];
                }
                foreach ($site->locales as $loc) {
                    $r = $byLocale[$loc] ?? [];
                    $done = (int) ($r['translated'] ?? 0) + (int) ($r['stale'] ?? 0) + (int) ($r['manual'] ?? 0) + (int) ($r['failed'] ?? 0);
                    $translationStatus[$loc] = [
                        'translated' => (int) ($r['translated'] ?? 0),
                        'stale' => (int) ($r['stale'] ?? 0),
                        'manual' => (int) ($r['manual'] ?? 0),
                        'failed' => (int) ($r['failed'] ?? 0),
                        'missing' => max(0, $totalPublished - $done),
                        'total' => $totalPublished,
                        'error' => $errors[$loc] ?? '',
                    ];
                }
            } catch (\Throwable) {
                // Optional panel — never blank the screen.
            }
        }

        return $this->dash->render($request, 'settings.html.twig', [
            'title' => $site->name . ' settings — fastsite',
            'site' => $site,
            'translation_status' => $translationStatus,
            'translation_failed' => array_sum(array_map(static fn (array $s): int => $s['failed'], $translationStatus)),
            'translator_configured' => $this->ai->translatorConfiguredForSite($site),
            'ai_credentials' => $this->aiCreds->listAll(),
            'locale_add_codes' => \Fastsite\Shared\Locales::codes([$site->primaryLocale, ...$site->locales]),
            'files' => $this->shell->listFiles($site),
            'edit_file' => $editFile,
            'edit_content' => $editContent,
            'components' => $components,
            'revisions' => $revisions,
            'images' => $images,
            'scripts' => $this->scripts->list($site->handle),
            'redirects' => $this->redirects->list($site->handle),
            'missing' => $this->redirects->listMissing($site->handle),
            'github_creds' => $this->githubCreds->listAll(),
            'global_token_configured' => $this->globalTokenConfigured,
            // git@ repos push over SSH: the token select is irrelevant there —
            // the per-site deploy key on the data volume is the mechanism.
            'deploy_key_exists' => is_file($this->databases->dataPath() . '/keys/' . $site->handle),
            'user_teams' => $this->teams->listForUser((int) $this->dash->user($request)['id']),
            // The Pages project name the auto-setup would create — lets the
            // Go-live card deep-link to that project's Domains screen.
            'cf_project' => CloudflarePagesService::projectName($site->handle),
            'guide_rules' => $this->authoring->rules(),
        ]);
    }
}
