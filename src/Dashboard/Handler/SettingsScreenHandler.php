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
 * GET /sites/{site}/settings — screen 4: site details, components, shell
 * file tree (+ inline editor via ?file=), snapshots. Languages/translations
 * live on their own tab (TranslationsScreenHandler).
 */
final class SettingsScreenHandler implements RequestHandlerInterface
{
    /**
     * The screen is tabbed because it had grown past a thousand lines of
     * markup, and the tabs are SERVER-SIDE rather than show/hide: the page
     * genuinely gets smaller instead of merely looking smaller, and the
     * shell editor's CodeMirror only loads on the tab that uses it.
     *
     * Order is the order they render in the nav.
     */
    public const TABS = ['general', 'seo', 'publishing', 'email', 'scripts', 'redirects', 'errors', 'shell'];

    public const TAB_LABELS = [
        'general' => 'General',
        'seo' => 'SEO',
        'publishing' => 'Publishing',
        'email' => 'Email',
        'scripts' => 'Scripts',
        'redirects' => 'Redirects',
        // Next to Redirects on purpose: a 410 rule and a 410 page are the same
        // decision seen from two sides, and both carry the same per-mode
        // caveat about what a CDN can actually serve.
        'errors' => 'Error pages',
        'shell' => 'Shell',
    ];

    public function __construct(
        private readonly Dash $dash,
        private readonly ShellService $shell,
        private readonly SiteDatabaseManager $databases,
        private readonly AuthoringGuideService $authoring,
        private readonly SiteScriptService $scripts,
        private readonly RedirectService $redirects,
        private readonly GithubCredentialService $githubCreds,
        private readonly TeamService $teams,
        private readonly \Fastsite\Workspace\Service\WorkspaceService $workspaces,
        private readonly \Fastsite\Ai\AiCredentialService $aiCreds,
        private readonly \Fastsite\Content\Repository\ContentRepository $content,
        // Whether a global GITHUB_TOKEN exists (bool only — the token itself
        // never reaches a template). The "Publish with" select's fallback
        // option must not pretend a token exists when the env var is empty.
        private readonly bool $globalTokenConfigured = false,
        // Whether the engine-wide SHORTPIXEL_API_KEY exists (bool only). The
        // ShortPixel field must say WHICH key is in effect when the site has
        // none of its own, instead of just looking unconfigured.
        private readonly bool $engineShortpixelConfigured = false,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $query = $request->getQueryParams();

        // An unknown or absent tab falls back to General, so every existing
        // link to /settings keeps working rather than 404ing or blanking.
        $tab = (string) ($query['tab'] ?? '');
        if (!\in_array($tab, self::TABS, true)) {
            $tab = 'general';
        }
        // Opening a shell file IS the shell tab — a ?file= link from anywhere
        // else must not land on General with the editor invisible.
        if (isset($query['file'])) {
            $tab = 'shell';
        }

        $editFile = null;
        $editContent = '';
        if ($tab === 'shell' && isset($query['file'])) {
            try {
                $editFile = (string) $query['file'];
                $editContent = $this->shell->readFile($site, $editFile);
            } catch (\Throwable) {
                $editFile = null;
            }
        }

        // Each tab loads only what it renders. Every visit used to pay for
        // shell files, revisions, images, scripts, redirects, missing paths
        // and four credential/ownership lookups — for a page someone opened
        // to change one field.
        $components = [];
        $revisions = [];
        $images = [];
        if ($tab === 'general') {
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
        }
        if ($tab === 'seo') {
            try {
                $images = $this->databases->site($site->handle)->fetchAllAssociative(
                    "SELECT id, original_name, alt FROM media
                     WHERE kind = 'image' AND status = 'ready' AND deleted_at IS NULL
                     ORDER BY id DESC LIMIT 200",
                );
            } catch (\Throwable) {
                // Optional panel — never blank the screen.
            }
        }
        if ($tab === 'shell') {
            try {
                $revisions = $this->databases->site($site->handle)->fetchAllAssociative(
                    'SELECT rev_no, note, created_by, created_at FROM shell_revisions WHERE deleted_at IS NULL ORDER BY rev_no DESC LIMIT 10',
                );
            } catch (\Throwable) {
                // Optional panel — never blank the screen.
            }
        }

        // Languages + translation status moved to the dedicated Translations
        // screen (TranslationsScreenHandler).

        $scripts = $tab === 'scripts' ? $this->scripts->list($site->handle) : [];

        // The Suggested-redirects filter and sort ride in the URL exactly as
        // ?tab= does, so a filtered view survives a reload, a bookmark, and
        // the redirect back from a bulk action. An unknown sort falls back
        // rather than erroring — same rule as an unknown tab.
        $query = $request->getQueryParams();
        $missingQ = $tab === 'redirects' ? trim((string) ($query['q'] ?? '')) : '';
        $missingSort = (string) ($query['sort'] ?? 'hits');
        if (!\in_array($missingSort, \Fastsite\Site\Service\RedirectService::MISSING_SORTS, true)) {
            $missingSort = 'hits';
        }

        return $this->dash->render($request, 'settings.html.twig', [
            'title' => $site->name . ' settings — fastsite',
            'site' => $site,
            'can_manage' => $this->dash->canManageSite($this->dash->user($request), $site),
            'tab' => $tab,
            'tabs' => self::TAB_LABELS,
            'ai_credentials' => $tab === 'general' ? $this->aiCreds->listAll() : [],
            'files' => $tab === 'shell' ? $this->shell->listFiles($site) : [],
            'edit_file' => $editFile,
            'edit_content' => $editContent,
            'components' => $components,
            'revisions' => $revisions,
            'images' => $images,
            'scripts' => $scripts,
            // Which of them ship differently from how they were typed, because
            // the build had to wrap loose JavaScript in <script> tags for it to
            // run at all. Disclosed on the card rather than done silently.
            'scripts_wrapped' => array_values(array_map(
                static fn (\Fastsite\Site\Dto\SiteScript $s): int => $s->id,
                array_filter($scripts, static fn (\Fastsite\Site\Dto\SiteScript $s): bool => \Fastsite\Site\Service\SiteScriptService::needsWrapping($s->code)),
            )),
            'redirects' => $tab === 'redirects' ? $this->redirects->list($site->handle) : [],
            'missing' => $tab === 'redirects' ? $this->redirects->listMissing($site->handle, $missingQ, $missingSort) : [],
            'missing_q' => $missingQ,
            'missing_sort' => $missingSort,
            // The true number of matching rows, so "showing 500 of 1,284" can
            // be honest instead of the list silently stopping.
            'missing_total' => $tab === 'redirects' ? $this->redirects->countMissing($site->handle, $missingQ) : 0,
            // Keyed by status so the template can ask `error_pages[403]`
            // directly instead of scanning a list per row.
            'error_pages' => $tab === 'errors' ? $this->errorPages($site->handle) : [],
            'error_codes' => \Fastsite\Content\Dto\Content::CODES,
            'github_creds' => $tab === 'publishing' ? $this->githubCreds->listAll() : [],
            'global_token_configured' => $this->globalTokenConfigured,
            'engine_shortpixel_configured' => $this->engineShortpixelConfigured,
            // git@ repos push over SSH: the token select is irrelevant there —
            // the per-site deploy key on the data volume is the mechanism.
            'deploy_key_exists' => is_file($this->databases->dataPath() . '/keys/' . $site->handle),
            'user_teams' => $tab === 'general' ? $this->teams->listForUser((int) $this->dash->user($request)['id']) : [],
            'user_workspaces' => $tab === 'general' ? $this->workspaces->listForUser((int) $this->dash->user($request)['id']) : [],
            // When an admin filed the site into a workspace the owner cannot
            // access, the select is replaced by a locked note + hidden field
            // so saving the form cannot silently detach the site.
            'site_workspace_accessible' => $site->workspaceId === null
                || $this->workspaces->canAccess((int) $this->dash->user($request)['id'], $site->workspaceId),
            // The Pages project name the auto-setup would create — lets the
            // Go-live card deep-link to that project's Domains screen.
            'cf_project' => CloudflarePagesService::projectName($site->handle),
            'guide_rules' => $tab === 'general' ? $this->authoring->rules() : [],
        ]);
    }

    /**
     * This site's error pages keyed by status.
     *
     * Read through the repository rather than ContentService because the tab
     * only displays them — editing happens on the normal Content screen, which
     * is the point of error pages being content rows at all.
     *
     * @return array<int, \Fastsite\Content\Dto\Content>
     */
    private function errorPages(string $handle): array
    {
        $out = [];
        foreach ($this->content->listErrorPages($handle) as $row) {
            if ($row->errorStatus !== null) {
                $out[$row->errorStatus] = $row;
            }
        }

        return $out;
    }
}
