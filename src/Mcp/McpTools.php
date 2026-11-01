<?php

declare(strict_types=1);

namespace Fastsite\Mcp;

use Fastsite\Access\AccessContext;
use Fastsite\Authoring\AuthoringGuideService;
use Fastsite\Backup\Service\BackupService;
use Fastsite\Build\Service\BuildService;
use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Component\Service\ComponentService;
use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Content\Service\ContentService;
use Fastsite\Content\Service\DuplicateContentException;
use Fastsite\Media\Service\MediaService;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\SiteLinks;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Shell\Service\ShellService;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;
use Fastsite\Site\Service\SiteService;

/**
 * Builds the MCP tool set. Each tool maps to an engine service and declares
 * the scope it needs; site-scoped tools additionally check the OAuth user's
 * membership. This is how Claude Desktop drives the engine.
 */
final class McpTools
{
    /** One description for the `category` field on every post write. */
    private const CATEGORY_FIELD = 'Posts only. The post\'s ONE category, by slug (see list_categories; create it first with upsert_category). It drives the archive the post is listed in and the Home > Category > post breadcrumb, and when the site\'s post_path_pattern carries {category} it is part of the post\'s URL — moving a published post to another category then leaves a 301 behind. An unknown slug is rejected, never dropped.';

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteService $siteService,
        // Nullable because the PUBLIC export ships no sites and the trimmer
        // deletes this service outright (scripts/deploy-public.sh). Wired
        // explicitly in config/services.php behind class_exists — php-di does
        // not autowire an optional param, and an optional one before the
        // required $appUrl below is a PHP deprecation.
        private readonly ?\Fastsite\Site\Service\MarketingSeeder $marketingSeeder,
        private readonly \Fastsite\Site\Service\SiteScriptService $siteScripts,
        private readonly \Fastsite\Site\Service\RedirectService $redirects,
        private readonly \Fastsite\Publish\Service\GithubCredentialService $githubCreds,
        private readonly \Fastsite\Team\Service\TeamService $teams,
        private readonly \Fastsite\Webhook\Service\WebhookService $webhooks,
        private readonly \Fastsite\Webhook\Mapper\WebhookMapperRegistry $webhookMappers,
        private readonly ContentService $content,
        private readonly ContentRepository $contentRepo,
        private readonly MediaService $media,
        private readonly ComponentService $components,
        private readonly \Fastsite\Component\Service\ComponentHookService $componentHooks,
        private readonly ShellService $shell,
        private readonly PublishTrigger $publish,
        private readonly BuildService $build,
        private readonly JobQueue $queue,
        private readonly SiteDatabaseManager $databases,
        private readonly BackupService $backups,
        private readonly AuthoringGuideService $authoring,
        private readonly \Fastsite\Build\Service\PageAuditLog $auditLog,
        private readonly \Fastsite\FactCheck\FactCheckService $factCheck,
        private readonly \Fastsite\Research\ResearchService $research,
        private readonly \Fastsite\Workspace\Service\WorkspaceService $workspaces,
        private readonly \Fastsite\Seo\SeoLengthService $seoLengths,
        private readonly \Fastsite\Translation\Service\TranslationService $translations,
        private readonly \Fastsite\Form\Service\FormService $forms,
        private readonly \Fastsite\Form\Service\FormMailer $mailer,
        private readonly \Fastsite\Form\Spam\SpamScorer $scorer,
        private readonly \Fastsite\Media\Service\UploadTicketService $uploadTickets,
        private readonly \Fastsite\Category\Service\CategoryService $categories,
        private readonly \Fastsite\Ops\BuildInfoService $buildInfo,
        private readonly string $appUrl,
    ) {
    }

    /**
     * @return list<McpTool>
     */
    public function all(): array
    {
        return [
            $this->tool('get_authoring_guide', 'READ THIS FIRST. The rules for designing a site shell + authoring content so pages hit 100/100/100/100 PageSpeed and are SEO/a11y-clean. Pass a site to get its primary language, locales, and embed syntax.', [
                'site' => ['type' => 'string', 'description' => 'Optional site handle for site-specific guidance.'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = null;
                if (($handle = (string) ($args['site'] ?? '')) !== '') {
                    $site = $this->site($a, $args); // resolves + access-checks
                }

                return $this->authoring->guide($site);
            }),

            $this->tool('get_language_switcher', 'The language-switcher widget for a multilingual site. NOTHING is inserted automatically — you decide where it goes. Returns the placement markers the build expands into the engine\'s zero-JS dropdown, the exact HTML that dropdown renders, and a customizable Twig variant, so you can put the switcher precisely where the design wants it.', [
                'site' => ['type' => 'string', 'description' => 'Site handle (its locales drive the rendered example).'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $locales = [$site->primaryLocale, ...$site->locales];

                // The literal widget the build renders (same code path), with
                // this site's real locales. Per page the links differ — here
                // each locale points at its home — so this is a faithful
                // preview of markup + styling, not paste-into-shell HTML.
                $sample = [];
                foreach ($locales as $loc) {
                    $sample[$loc] = $loc === $site->primaryLocale ? '/' : '/' . $loc . '/';
                }
                $rendered = \count($sample) >= 2
                    ? \Fastsite\Build\Service\LangSwitcher::render($sample, $site->primaryLocale)
                    : '';

                return [
                    'locales' => $locales,
                    'will_render' => \count($locales) >= 2,
                    'markers' => [
                        'comment' => '<!--fs:languages-->',
                        'element' => '<li data-fs-languages></li>',
                        'how' => 'Paste EITHER marker into a shell template (shell_put_file) exactly where the switcher should appear — typically inside the header <nav>. At build the comment is replaced by the dropdown below; the element form keeps its wrapper tag and gets the dropdown inside it (so <li data-fs-languages></li> stays a valid menu item). No marker = no switcher: the engine never auto-inserts it.',
                    ],
                    'rendered_html' => $rendered,
                    'custom_twig' => <<<'TWIG'
                        {% if page.hreflang is defined and page.hreflang|length > 1 %}
                        <details class="lang-switcher" data-fs-langnav>
                          <summary aria-label="Language">
                            {% if site.flags[site.locale] is defined %}<img src="{{ site.flags[site.locale] }}" alt="" width="20" height="15">{% endif %}<span>{{ site.locale_names[site.locale]|default(site.locale|upper) }}</span><span class="caret" aria-hidden="true">&#9662;</span>
                          </summary>
                          <div class="lang-panel">
                            {% for alt in page.hreflang %}
                              {% if alt.hreflang != 'x-default' %}
                              <a href="{{ alt.href }}" hreflang="{{ alt.hreflang }}"{% if alt.hreflang == site.locale %} aria-current="true"{% endif %}>
                                {% if site.flags[alt.hreflang] is defined %}<img src="{{ site.flags[alt.hreflang] }}" alt="" width="20" height="15">{% endif %}<span>{{ site.locale_names[alt.hreflang]|default(alt.hreflang|upper) }}</span>
                              </a>
                              {% endif %}
                            {% endfor %}
                          </div>
                        </details>
                        {% endif %}
                        TWIG,
                    'notes' => [
                        'Marker route: nothing visual is hardcoded. The trigger inherits the surrounding nav\'s typography and text color, and EVERY visual default (spacing, radius, shadow, panel surface, hover and current-item tints) ships at zero specificity via :where() — so any plain selector in your shell CSS overrides it, no !important needed (e.g. .fs-lp{...}, .fs-lp a:hover{...}, .fs-lp a[aria-current]{...}, details[data-fs-langnav]>summary{...}). For theming without selectors, set the tokens --fs-lang-bg / --fs-lang-fg / --fs-lang-border / --fs-lang-tint anywhere (e.g. :root in tokens.css); unthemed, the panel falls back to the UA\'s native-control surface (Canvas/CanvasText). Links cover only the locale variants actually built for each page — a missing translation is simply absent.',
                        'Custom route: paste the Twig into a shell template and style .lang-switcher/.lang-panel yourself. Keep the data-fs-langnav attribute and real <a hreflang> links — the build detects the switcher by it, and the automatic language-persistence script (fs_lang cookie, injected site-wide on multilingual sites) binds to it. You never write that script yourself.',
                        'Fewer than 2 built locale variants of a page = no switcher on that page, marker or not.',
                    ],
                ];
            }),

            $this->tool('list_sites', 'List the sites you can manage.', [], 'sites:read',
                fn (AccessContext $a): array => ['sites' => array_map(
                    static fn (Site $s): array => ['handle' => $s->handle, 'name' => $s->name, 'base_url' => $s->baseUrl, 'primary_locale' => $s->primaryLocale, 'locales' => $s->locales, 'status' => $s->status, 'development' => $s->development, 'workspace_id' => $s->workspaceId],
                    array_filter($this->sites->all(), fn (Site $s): bool => $this->canAccess($a, $s->handle)),
                )]),

            $this->tool('list_workspaces', 'List the workspaces you can access (customer grouping — a site belongs to at most one workspace; move a site via update_site workspace_id).', [], 'sites:read',
                fn (AccessContext $a): array => ['workspaces' => array_map(
                    static fn (array $w): array => ['id' => $w['id'], 'name' => $w['name'], 'team_id' => $w['team_id'], 'site_count' => $w['site_count']],
                    $this->workspaces->listForUser($a->userId),
                )]),

            $this->tool('create_workspace', 'Create a workspace (admins and editors; the creator becomes its owner). Optionally attach a team you belong to so its members can use the workspace.', [
                'name' => ['type' => 'string'],
                'team_id' => ['type' => 'integer', 'description' => 'Optional team to share the workspace with.'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $role = (string) $this->databases->engine()->fetchOne('SELECT role FROM users WHERE id = ?', [$a->userId]);
                if (!\in_array($role, ['admin', 'editor'], true)) {
                    throw new McpToolException('Your account cannot create workspaces — ask an admin to make you an editor.');
                }
                $teamId = (int) ($args['team_id'] ?? 0);
                if ($teamId > 0 && $role !== 'admin' && !$this->teams->isMember($teamId, $a->userId)) {
                    throw new McpToolException('403: you can only attach a workspace to a team you belong to.');
                }
                try {
                    $id = $this->workspaces->create($a->userId, (string) ($args['name'] ?? ''), $teamId > 0 ? $teamId : null);
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }
                $workspace = $this->workspaces->find($id);
                \assert($workspace !== null);

                return ['id' => $workspace['id'], 'name' => $workspace['name'], 'team_id' => $workspace['team_id']];
            }),

            $this->tool('check_seo_lengths', 'Check every post/page against the Google/Yoast snippet limits (title 30–60 chars, meta description 120–156). Returns each offending row with the current text, the problem kind (title-long/short, description-long/short/missing) and a body excerpt for context. TO FIX THEM YOURSELF (recommended — you write better copy than a truncation): rewrite each and call update_post with {site, id, seo_title, seo_description} respecting the limits, then publish_site once. Alternatively fix_seo_lengths does it engine-side.', [
                'site' => ['type' => 'string'],
            ], 'content:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $issues = $this->seoLengths->scan($site);

                return [
                    'issues' => $issues,
                    'count' => \count($issues),
                    'limits' => ['title_min' => \Fastsite\Seo\SeoLimits::TITLE_MIN, 'title_max' => \Fastsite\Seo\SeoLimits::TITLE_MAX, 'description_min' => \Fastsite\Seo\SeoLimits::DESC_MIN, 'description_max' => \Fastsite\Seo\SeoLimits::DESC_MAX],
                    'how_to_fix' => $issues === [] ? 'Nothing to fix.' : 'For each issue: write a new SEO title (30-60 chars, keyword near the front) and/or meta description (120-156 chars, one or two natural sentences) in the content\'s language, then call update_post {site, id, seo_title, seo_description}. When done, call publish_site once. Count characters carefully. Or call fix_seo_lengths to have the engine rewrite them (uses the site\'s AI key, else truncates).',
                ];
            }),

            $this->tool('fix_seo_lengths', 'Rewrite the flagged SEO titles/descriptions to fit the Google/Yoast limits (AI when the site has an AI key, word-boundary truncation otherwise) and republish. Runs as a background job; pass id to fix a single content row. Poll get_build_status / list_jobs.', [
                'site' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'description' => 'Optional content id — fix only this row.'],
            ], 'content:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $payload = ['trigger' => 'mcp'];
                if ((int) ($args['id'] ?? 0) > 0) {
                    $payload['id'] = (int) $args['id'];
                } else {
                    // A full-site fix supersedes every queued seo_fix (parked
                    // backoff twins included) — cancel them so the fresh job
                    // gets a full attempt budget instead of absorbing into a
                    // twin one failure from dead-lettering.
                    $this->queue->cancelPending('seo_fix', $site->handle);
                }
                // Single-row fixes get their own dedupe key so two different
                // rows queued back-to-back don't coalesce into one job.
                $uuid = $this->queue->enqueue('seo_fix', $site->handle, $payload, 'default', 'seo-fix:' . $site->handle . (isset($payload['id']) ? ':' . $payload['id'] : ''));
                $this->queue->promoteNow($uuid);

                return ['job' => $uuid];
            }),

            $this->tool('fact_check', 'Fact-check a post or page with a web-grounded Perplexity model — verifies prices, stats, and product/expert claims against live sources. Requires fact-checking enabled in the site settings. Returns a summary + per-claim verdicts (supported/contradicted/unverified) with sources; also persisted on the content row.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer', 'description' => 'Content id (post or page).'],
            ], 'content:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    return $this->factCheck->checkContent($site, (int) ($args['id'] ?? 0));
                } catch (\Fastsite\FactCheck\FactCheckException $e) {
                    throw new McpToolException($e->getMessage());
                }
            }),

            $this->tool('research', 'Research a topic BEFORE writing about it — a web-grounded Perplexity model returns a structured markdown research brief (overview, key facts with figures, notable products/brands, pros/cons, common questions, angles, and cited sources) to write the article from. Use when the user asks to "research this topic" or research a subject before drafting. Uses the site\'s Research model (default perplexity/sonar); requires an OpenRouter credential selected on the site.', [
                'site' => ['type' => 'string'], 'topic' => ['type' => 'string', 'description' => 'The topic / question to research, e.g. "coffee machines with a built-in cacao maker".'],
            ], 'content:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    return $this->research->research($site, (string) ($args['topic'] ?? ''));
                } catch (\Fastsite\Research\ResearchException $e) {
                    throw new McpToolException($e->getMessage());
                }
            }),

            $this->tool('create_site', 'Create a new site (admins and editors; the creator becomes the site owner). Provisions its database, scaffolds the default shell, and registers it. The handle is immutable — it is the identity on every transport and the on-disk path. The site is LIVE and indexable from the start; if it should stay out of search while you build it, set update_site development=true.', [
                'handle' => ['type' => 'string', 'description' => 'Lowercase handle, e.g. "blog". Immutable identity + path.'],
                'name' => ['type' => 'string'],
                'base_url' => ['type' => 'string', 'description' => 'https://apex.example.com — a leading www. is stripped to the apex.'],
                'github_repo' => ['type' => 'string', 'description' => 'Optional dist repo, e.g. git@github.com:you/site-dist.git.'],
                'primary_locale' => ['type' => 'string', 'default' => 'en'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $role = (string) $this->databases->engine()->fetchOne('SELECT role FROM users WHERE id = ?', [$a->userId]);
                if (!\in_array($role, ['admin', 'editor'], true)) {
                    throw new McpToolException('Your account cannot create sites — ask an admin to make you an editor.');
                }
                try {
                    $site = $this->siteService->create(
                        (string) ($args['handle'] ?? ''),
                        (string) ($args['name'] ?? ($args['handle'] ?? '')),
                        (string) ($args['base_url'] ?? ''),
                        (string) ($args['github_repo'] ?? ''),
                        (string) ($args['primary_locale'] ?? 'en'),
                        $a->userId,
                    );
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['handle' => $site->handle, 'name' => $site->name, 'base_url' => $site->baseUrl, 'primary_locale' => $site->primaryLocale, 'status' => $site->status, 'development' => $site->development, 'note' => 'This site is LIVE: everything you publish is indexable by search engines straight away. To keep it out of search while you build it, call update_site development=true.'];
            }),

            // Absent from tools/list entirely in the public export, which ships
            // no sites: a tool that is advertised and then cannot run is worse
            // than one that was never offered.
            ...($this->marketingSeeder === null ? [] : [$this->tool('seed_getfastsite', 'Stand up getfastsite.com — the fastsite marketing site — inside the engine (admin, dogfood). Creates the site if missing, installs the marketing theme, authors the marketing pages + launch post, and builds. Idempotent. Then set its github_repo + a GitHub credential and publish.', [], 'admin', function (AccessContext $a, array $args): array {
                $seeder = $this->marketingSeeder;
                if ($seeder === null) {
                    throw new McpToolException('The marketing seeder is not part of this build.');
                }

                try {
                    $log = $seeder->seed(true);
                } catch (\Throwable $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['log' => $log];
            })]),

            $this->tool('update_site', 'Update site settings (name, base_url, github repo/branch, media_public_base, status, favicon/logo media ids). Only provided keys change; republishes when the built output is affected.', [
                'site' => ['type' => 'string'],
                'name' => ['type' => 'string'], 'base_url' => ['type' => 'string'],
                'github_repo' => ['type' => 'string'], 'github_branch' => ['type' => 'string'],
                'media_public_base' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['active', 'paused', 'archived']],
                'favicon_media_id' => ['type' => 'integer'], 'logo_header_media_id' => ['type' => 'integer'], 'logo_footer_media_id' => ['type' => 'integer'],
                'social_image_media_id' => ['type' => 'integer', 'description' => 'Media id used as the default og:image / twitter:image share card.'],
                'meta_description' => ['type' => 'string', 'description' => 'Fallback meta description for the home page, blog index and 404.'],
                'canonical_host' => [
                    'type' => 'string',
                    'enum' => ['apex', 'www'],
                    'description' => 'The ONE host the site answers on: "apex" (example.com, default) or "www" (www.example.com). Drives every canonical/OG/sitemap URL, and the host-level 301 the other form gets — performed by the engine when serve_mode=direct, and by a Cloudflare zone Redirect Rule when serve_mode=git (a CDN _redirects file cannot match hosts).',
                ],
                'github_credential_id' => ['type' => 'integer'],
                'serve_mode' => ['type' => 'string', 'enum' => ['git', 'direct', 'ftp'], 'description' => 'How the site goes live: "git" pushes the build to the repo a CDN watches; "direct" serves it straight from this engine by domain; "ftp" uploads every publish to the CUSTOMER\'S OWN web host over FTP/FTPS/SFTP, so the site keeps running even if this engine does not. "ftp" needs ftp_host + ftp_username set (in the same call is fine).'],
                'ftp_protocol' => ['type' => 'string', 'enum' => ['ftp', 'ftps', 'sftp'], 'description' => 'Transfer protocol for serve_mode=ftp. Default "sftp". Use "ftps" for shared hosting with explicit TLS; plain "ftp" sends the password in the clear and should be a last resort.'],
                'ftp_host' => ['type' => 'string', 'description' => 'Hostname or IP of the customer\'s web host. A pasted "sftp://host:2222/" URL is accepted and normalized. Private/loopback/reserved addresses are refused.'],
                'ftp_port' => ['type' => 'integer', 'description' => 'Port, or 0 (default) for the protocol default: 21 for ftp/ftps, 22 for sftp.'],
                'ftp_username' => ['type' => 'string'],
                'ftp_password' => ['type' => 'string', 'description' => 'Stored encrypted and never returned. Omit or send "" to keep the saved one; "__clear__" removes it.'],
                'ftp_key' => ['type' => 'string', 'description' => 'SFTP private key (PEM), as an alternative to a password. Stored encrypted and never returned. "__clear__" removes it.'],
                'ftp_remote_path' => ['type' => 'string', 'description' => 'Directory on the host that serves the domain, e.g. "/public_html". Empty = the login directory. Only files fastsite itself uploaded are ever deleted there, so an existing site in that directory is not destroyed.'],
                'ftp_verify_cert' => ['type' => 'boolean', 'description' => 'FTPS only, default true: verify the host\'s TLS certificate. Set false ONLY for a host whose certificate is self-signed or issued for its own IP (the default on most FTP servers, and unverifiable by anyone). The connection stays encrypted; what is lost is proof of who is on the other end, and the password travels over it. SFTP has no certificate and needs no exception.'],
                'shortpixel_key' => ['type' => 'string', 'description' => 'Per-site ShortPixel API key (stored encrypted). "__clear__" removes it.'],
                'image_compression' => ['type' => 'string', 'enum' => ['lossy', 'glossy', 'lossless'], 'description' => 'Image compression level: ShortPixel mode + served variant quality. lossy = smallest (default), glossy = visually lossless, lossless = pixel-faithful.'],
                'fact_check_enabled' => ['type' => 'boolean'],
                'workspace_id' => ['type' => 'integer', 'description' => 'Move the site into a workspace (see list_workspaces; you need access to the target). 0 or null removes it from its workspace.'],
                'seo_title_suffix' => ['type' => 'boolean', 'description' => 'Append the site name to every engine-composed page <title>/og:title ("My post — Site"). Default true; a content row\'s own seo_title always ships as written.'],
                'seo_title_separator' => ['type' => 'string', 'enum' => SiteService::TITLE_SEPARATORS, 'description' => 'Character joining the title and the site name.'],
                'sanitize_content' => ['type' => 'boolean', 'description' => 'Site-wide content trust level (default true = sanitize). Set false for a TRUSTED PORT: every ingest path then stores body HTML verbatim by default (inline styles/scripts/iframes/interactive markup kept, exempt from the zero-JS/PageSpeed audit) AND translations keep it verbatim instead of re-sanitizing. A per-write `sanitize` still overrides this for one call.'],
                'cta_tracking' => ['type' => 'boolean', 'description' => 'GTM CTA click tracking (default true): the build annotates EVERY link and button with data-cta (stable snake_case id, identical across locales), data-cta-text (the visible text), data-cta-language (the page\'s locale) and data-cta-location (header/navigation/footer/hero/…). Explicitly authored data-cta* attributes always win. Inert markup — zero JS ships.'],
                'translate_concurrency' => ['type' => 'integer', 'description' => 'How many translation AI calls may run at once for this site (1-16, default 4). The main lever on how long a full translation takes; raise it until the provider rate-limits (the run then backs off on its own).'],
                'translate_chunk_size' => ['type' => 'integer', 'description' => 'How many page-language pairs one queued translation chunk attempts before handing the worker to the next site (1-200, default 10).'],
                'structured_data' => ['type' => 'boolean', 'description' => 'Schema.org JSON-LD (default true): every page ships ONE @graph cross-referenced by @id — Organization/Person + WebSite + WebPage (CollectionPage on the blog index) + BreadcrumbList + BlogPosting/Person/ImageObject on posts. Injected into the rendered head, so it works on every shell. Turn off only for a site whose shell emits its own markup.'],
                'publish_all_media' => ['type' => 'boolean', 'description' => 'Publish the site\'s WHOLE media + files library, not just what pages embed (default OFF). Media ships REFERENCED-ONLY, so an item nothing embeds has no public URL at all and its url 404s — republishing never fixes that, because the build never copies the file. Turn this on when library items need public URLs of their own (an image for an email, an ad, another site). Costs dist size: every image is ~10 renditions. Flipping it republishes.'],
                'development' => ['type' => 'boolean', 'description' => 'Development mode (default OFF — new sites are live and indexable). While on, EVERY page publishes with <meta name="robots" content="noindex, nofollow"> plus an X-Robots-Tag header, and robots.txt drops its sitemap pointers — the site can be published and shared but can never appear in search. Flipping it either way republishes the whole site.'],
                'schema_type' => ['type' => 'string', 'enum' => SiteService::SCHEMA_TYPES, 'description' => 'What the site IS as an entity: "Organization" (default) or "Person" for a personal brand. Google resolves the two differently, so this is a real choice rather than a label. On a Person site a post whose author name matches the site name reuses the site entity instead of minting a second Person.'],
                'schema_same_as' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'schema.org sameAs: absolute profile URLs identifying the same entity elsewhere (LinkedIn, X, GitHub, Crunchbase, Wikipedia…). This is the field that actually drives entity resolution — name and url alone rarely do. Max 25; a non-absolute URL is rejected.'],
                'smtp_host' => ['type' => 'string', 'description' => 'SMTP server for this site\'s form notifications. A pasted "smtps://mail.example.com:465" is accepted and split. Without SMTP a form still COLLECTS and stores every submission — it just emails nobody, which looks healthy from every other angle.'],
                'smtp_port' => ['type' => 'integer', 'description' => '0 = the default for the chosen encryption (25 / 465 / 587).'],
                'smtp_encryption' => ['type' => 'string', 'enum' => SiteService::SMTP_ENCRYPTIONS, 'description' => '"starttls" (587, default), "tls" (implicit, 465) or "none" (25 — the password rides that connection, so never over the public internet).'],
                'smtp_username' => ['type' => 'string'],
                'smtp_password' => ['type' => 'string', 'description' => 'Stored encrypted and never returned. Omit or send "" to keep the saved one; "__clear__" removes it. NOT trimmed — a provider-issued app password may contain spaces. Microsoft 365 and Google Workspace both need more than the account password (app password behind 2FA; SMTP AUTH is off by default on new M365 tenants).'],
                'smtp_from_email' => ['type' => 'string', 'description' => 'The From address. Deliberately NOT the visitor\'s — almost every provider rejects or silently SPF-fails a From it did not authenticate. The visitor goes in Reply-To.'],
                'smtp_from_name' => ['type' => 'string'],
                'smtp_verify_cert' => ['type' => 'boolean', 'description' => 'Verify the mail server\'s TLS certificate (default true). Turn off only for a self-signed internal relay: the connection stays encrypted, what is given up is proof of who is on the other end.'],
                'spam_mode' => ['type' => 'string', 'enum' => SiteService::SPAM_MODES, 'description' => 'Form spam guard: "on" (default) holds flagged submissions in the spam folder and never emails them, "log" scores and records a verdict but still emails everything, "off" scores nothing. Run "log" for a week before "on" — it shows what WOULD have been held without the first false positive being a real lead that silently vanished.'],
                'spam_retention_days' => ['type' => 'integer', 'description' => 'How long a held submission is kept before it deletes itself (0-3650, default 30). 0 = delete on sight, which leaves a scoring mistake no trace to be found in.'],
                'turnstile_site_key' => ['type' => 'string', 'description' => 'Cloudflare Turnstile site key (public — it ships in the rendered page). Optional: a site that does not use Cloudflare leaves this empty and keeps the content scorer, which works on its own. The challenge is then enabled per form via upsert_form turnstile.'],
                'post_path_pattern' => ['type' => 'string', 'description' => 'Where posts live. Default "/blog/{slug}/". Tokens: {slug} (required, once) and {category} (optional, the post\'s category slug — a post with no category drops that segment). "/{category}/{slug}/" is the WordPress "category + postname" shape, for keeping a ported site\'s URLs exactly. Changing it moves every published post and records a 301 for each old URL. Lowercase letters, digits, "-" and "/" only; must start and end with "/".'],
                'category_path_pattern' => ['type' => 'string', 'description' => 'Where category archives live. Default "/category/{slug}/" (WordPress\'s own). Page 2 onward appends page/{n}/. Needs a fixed segment besides {slug}. Changing it records 301s for every archive page.'],
                'archive_per_page' => ['type' => 'integer', 'description' => 'Posts per category archive page, 1-100 (default 10, like WordPress). A shorter archive 301s its vanished pages to page 1.'],
                'turnstile_secret' => ['type' => 'string', 'description' => 'Turnstile secret key. Stored encrypted and never returned; omit or "" keeps the saved one, "__clear__" removes it. BOTH halves are required or no challenge renders at all.'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $before = $this->site($a, $args);
                $fields = array_intersect_key($args, array_flip(['name', 'base_url', 'github_repo', 'github_branch', 'media_public_base', 'status', 'favicon_media_id', 'logo_header_media_id', 'logo_footer_media_id', 'social_image_media_id', 'meta_description', 'canonical_host', 'github_credential_id', 'team_id', 'workspace_id', 'shortpixel_key', 'image_compression', 'fact_check_enabled', 'serve_mode', 'seo_title_suffix', 'seo_title_separator', 'sanitize_content', 'cta_tracking', 'development', 'publish_all_media', 'translate_concurrency', 'translate_chunk_size', 'structured_data', 'schema_type', 'schema_same_as', 'ftp_protocol', 'ftp_host', 'ftp_port', 'ftp_username', 'ftp_password', 'ftp_key', 'ftp_remote_path', 'ftp_verify_cert', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name', 'smtp_verify_cert', 'spam_mode', 'spam_retention_days', 'turnstile_site_key', 'turnstile_secret', 'post_path_pattern', 'category_path_pattern', 'archive_per_page']));
                // Moving the site into a workspace requires access to the
                // TARGET workspace — the same 403 the dashboard and REST give.
                if (\array_key_exists('workspace_id', $fields)) {
                    $target = $fields['workspace_id'];
                    $targetId = ($target === null || $target === '' || (int) $target <= 0) ? null : (int) $target;
                    if ($targetId !== null && $targetId !== $before->workspaceId && !$this->workspaces->canAccess($a->userId, $targetId)) {
                        throw new McpToolException('403: you do not have access to workspace ' . $targetId . '.');
                    }
                }
                try {
                    $after = $this->siteService->update($before->handle, $fields);
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }
                if (SiteService::changeNeedsRebuild($before, $after)) {
                    $this->publish->trigger($after, 'publish: site settings changed (mcp)', 'mcp');
                }

                return ['handle' => $after->handle, 'name' => $after->name, 'base_url' => $after->baseUrl, 'status' => $after->status, 'development' => $after->development, 'publish_all_media' => $after->publishAllMedia, 'favicon_media_id' => $after->faviconMediaId, 'logo_header_media_id' => $after->logoHeaderMediaId, 'logo_footer_media_id' => $after->logoFooterMediaId, 'post_path_pattern' => $after->postPathPattern, 'category_path_pattern' => $after->categoryPathPattern, 'archive_per_page' => $after->archivePerPage];
            }),

            $this->tool('list_categories', 'A site\'s post categories: slug, name, the archive url, and how many published posts each has (an archive page exists only for a category with at least one).', [
                'site' => ['type' => 'string'],
            ], 'content:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $counts = $this->databases->site($site->handle)->fetchAllKeyValue(
                    "SELECT category_id, COUNT(*) FROM content WHERE type = 'post' AND status = 'published' AND deleted_at IS NULL AND category_id IS NOT NULL GROUP BY category_id",
                );

                return [
                    'post_path_pattern' => $site->postPathPattern,
                    'category_path_pattern' => $site->categoryPathPattern,
                    'archive_per_page' => $site->archivePerPage,
                    'categories' => array_map(fn ($c): array => $this->categorySummary($site, $c, (int) ($counts[$c->id] ?? 0)), $this->categories->list($site)),
                ];
            }),

            $this->tool('upsert_category', 'Create a post category, or update it. The slug is one URL segment; it names the archive (/category/{slug}/ by default, page 2 at …/page/2/) and, when post_path_pattern carries {category}, every post URL in it. On an UPDATE, passing new_slug renames it: the archive and those posts move, and a 301 is left behind for every old URL. Republishes.', [
                'site' => ['type' => 'string'],
                'slug' => ['type' => 'string', 'description' => 'The category to create or update.'],
                'name' => ['type' => 'string', 'description' => 'Display name — the archive H1 and the breadcrumb label. Required on create.'],
                'new_slug' => ['type' => 'string', 'description' => 'Rename the slug (update only). Moves URLs; 301s are recorded.'],
                'description_html' => ['type' => 'string', 'description' => 'Intro shown on page 1 of the archive. Sanitized like body HTML. "" clears.'],
                'seo_title' => ['type' => 'string', 'description' => 'Archive page 1 <title>, 30-60 characters. Empty = name + site suffix.'],
                'seo_description' => ['type' => 'string', 'description' => 'Archive meta description, 120-156 characters. Empty = the description text, else the site default.'],
            ], 'content:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $input = array_intersect_key($args, array_flip(['name', 'description_html', 'seo_title', 'seo_description']));
                if (($args['new_slug'] ?? '') !== '') {
                    $input['slug'] = (string) $args['new_slug'];
                }
                try {
                    $r = $this->categories->upsert($site, (string) ($args['slug'] ?? ''), $input);
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return $this->categorySummary($site, $r['category'], null) + ['created' => $r['created'], 'redirects_recorded' => $r['redirects']];
            }),

            $this->tool('delete_category', 'Delete a post category. Refused while any post (draft or published) is still in it — deleting it would move those posts\' URLs. Move them with update_post first. Republishes.', [
                'site' => ['type' => 'string'], 'slug' => ['type' => 'string'],
            ], 'content:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $this->categories->delete($site, (string) ($args['slug'] ?? ''));
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['slug' => (string) ($args['slug'] ?? ''), 'deleted' => true];
            }),

            $this->tool('get_site_audit', 'What the engine found wrong with this site on its LAST BUILD — failures that blocked publishing, and warnings that shipped anyway. CALL THIS AFTER YOU EDIT A SHELL OR PUBLISH: it catches exactly the damage an agent does and a human never sees, including a canonical pointing at the wrong host (duplicate content), a favicon in a format Google Search cannot render (the icon looks perfect in the browser tab and the search result shows a blank square months later), a missing meta description, links that are not crawlable, and pages over the byte budget. An empty result with no `at` timestamp means the site has not been built since auditing started recording — publish once, then read it again.', [
                'site' => ['type' => 'string'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);

                return $this->auditLog->latest($site->handle);
            }),

            $this->tool('get_build_info', 'What this ENGINE INSTANCE is actually running: the image build stamp, PHP and node versions, and sharp\'s version together with every native library it links — libvips, aom, libheif, librsvg. CALL THIS TO CHECK WHETHER A DEPLOY LANDED, and before believing any claim about which dependency version is live. It exists because a health check answering 200 cannot tell a fresh container from an old one an orchestrator kept alive after a failed build, and because deducing a library version from its OUTPUT does not work: AVIF bytes track the libvips thread count (aom parallelises by tile), so identical libraries encode differently on machines with different core counts. Read the versions; never infer them.', [
            ], 'sites:read', function (AccessContext $a, array $args): array {
                return $this->buildInfo->info();
            }),

            $this->tool('list_scripts', 'List a site\'s header/footer scripts (#13).', [
                'site' => ['type' => 'string'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);

                return ['scripts' => array_map(static fn ($s): array => ['id' => $s->id, 'label' => $s->label, 'location' => $s->location, 'strategy' => $s->strategy, 'priority' => $s->priority, 'enabled' => $s->enabled, 'target' => $s->target, 'include_slugs' => $s->includeSlugs, 'exclude_slugs' => $s->excludeSlugs], $this->siteScripts->list($site->handle))];
            }),

            $this->tool('get_script', 'One header/footer script with its FULL source code — the read half of upsert_script (list_scripts lists without source). Without this the MCP surface is write-only: editing a script means retyping it from scratch, which is how a working tag pack gets destroyed by a one-line change. Same split as list_components / get_component.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $id = (int) ($args['id'] ?? 0);
                foreach ($this->siteScripts->list($site->handle) as $script) {
                    if ($script->id === $id) {
                        return [
                            'id' => $script->id, 'label' => $script->label, 'location' => $script->location,
                            'strategy' => $script->strategy, 'priority' => $script->priority,
                            'enabled' => $script->enabled, 'code' => $script->code,
                            'target' => $script->target, 'include_slugs' => $script->includeSlugs,
                            'exclude_slugs' => $script->excludeSlugs,
                            'needs_wrapping' => \Fastsite\Site\Service\SiteScriptService::needsWrapping($script->code),
                        ];
                    }
                }

                throw new McpToolException('Unknown script id: ' . $id);
            }),

            $this->tool('upsert_script', 'Create/update a header/footer script. WARNING: any enabled script opts the site out of the 100 PageSpeed guarantee. Pass an id to update. Republishes.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'], 'label' => ['type' => 'string'],
                'location' => ['type' => 'string', 'enum' => ['head', 'body_open', 'body_end']],
                'strategy' => ['type' => 'string', 'enum' => ['blocking', 'async', 'defer']],
                'priority' => ['type' => 'integer'], 'code' => ['type' => 'string'], 'enabled' => ['type' => 'boolean'],
                'target' => ['type' => 'string', 'enum' => \Fastsite\Site\Dto\SiteScript::TARGETS, 'description' => 'Which pages the script lands on: "all" (default - EVERY page, including the blog index and error pages), "posts", or "pages". Narrow further with include_slugs/exclude_slugs. Pages it does not target keep a zero-JS CSP and their 100 PageSpeed score, so targeting is how you avoid spending the zero-JS guarantee site-wide for a tag on one landing page.'],
                'include_slugs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only these PRIMARY-locale slugs get the script (empty = every targeted page). Primary slugs, so one rule covers every language variant. The home page is "home", the blog index "blog", an error page its status ("404").'],
                'exclude_slugs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'These slugs never get it (e.g. ["home","blog"]). Wins over include_slugs and over target.'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $s = $this->siteScripts->save($site->handle, $args);
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }
                $this->publish->trigger($site, 'publish: site script changed (mcp)', 'mcp');

                return ['id' => $s->id, 'location' => $s->location, 'strategy' => $s->strategy, 'enabled' => $s->enabled];
            }),

            $this->tool('delete_script', 'Delete a header/footer script. Republishes.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $this->siteScripts->delete($site->handle, (int) ($args['id'] ?? 0));
                $this->publish->trigger($site, 'publish: site script removed (mcp)', 'mcp');

                return ['id' => (int) ($args['id'] ?? 0), 'deleted' => true];
            }),

            $this->tool('list_webhooks', 'List a site\'s webhooks and the available built-in mappers (#3).', [
                'site' => ['type' => 'string'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);

                return ['webhooks' => $this->webhooks->listForSite($site->handle), 'available_mappers' => $this->webhookMappers->keys()];
            }),

            $this->tool('create_webhook', 'Create a site webhook pointing at a built-in mapper (create_post/create_page/import_media/upsert_component/autoseo). Returns the endpoint + signing secret.', [
                'site' => ['type' => 'string'], 'slug' => ['type' => 'string'], 'mapper' => ['type' => 'string'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $mapper = (string) ($args['mapper'] ?? '');
                if (!$this->webhookMappers->has($mapper)) {
                    throw new McpToolException('Unknown mapper. Available: ' . implode(', ', $this->webhookMappers->keys()));
                }
                try {
                    $r = $this->webhooks->createIntegration((string) ($args['slug'] ?? ''), $site->handle, $mapper, $a->userId);
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                // Absolute: the sender configuring this URL lives outside
                // this deployment, so a relative path is useless to it.
                return ['id' => $r['id'], 'endpoint' => rtrim($this->appUrl, '/') . '/webhooks/' . $r['slug'], 'secret' => $r['secret']];
            }),

            $this->tool('webhook_deliveries', 'Recent deliveries for a site webhook.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);

                return ['deliveries' => $this->webhooks->deliveriesForSite((int) ($args['id'] ?? 0), $site->handle)];
            }),

            $this->tool('list_teams', 'List the teams you own or belong to.', [], 'sites:read',
                fn (AccessContext $a): array => ['teams' => $this->teams->listForUser($a->userId)]),

            $this->tool('create_team', 'Create a team you own (to share sites with collaborators).', [
                'name' => ['type' => 'string'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                try {
                    $id = $this->teams->create($a->userId, (string) ($args['name'] ?? ''));
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['id' => $id, 'name' => (string) ($args['name'] ?? '')];
            }),

            $this->tool('invite_member', 'Invite someone to a team by email. Returns the invite link to share.', [
                'team_id' => ['type' => 'integer'], 'email' => ['type' => 'string'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                try {
                    $token = $this->teams->invite((int) ($args['team_id'] ?? 0), (string) ($args['email'] ?? ''), $a->userId);
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['invite_path' => '/invite/' . $token];
            }),

            $this->tool('accept_invite', 'Accept a team invite by its token.', [
                'token' => ['type' => 'string'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                try {
                    $team = $this->teams->accept((string) ($args['token'] ?? ''), $a->userId);
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['joined' => $team];
            }),

            $this->tool('list_github_credentials', 'List your saved GitHub credentials (labels only — tokens are never returned).', [], 'sites:write',
                fn (AccessContext $a): array => ['credentials' => $this->githubCreds->listForUser($a->userId)]),

            $this->tool('add_github_credential', 'Save a GitHub personal access token (stored encrypted; used to publish sites). Returns its id for update_site github_credential_id.', [
                'label' => ['type' => 'string'], 'token' => ['type' => 'string'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                try {
                    $id = $this->githubCreds->add($a->userId, (string) ($args['label'] ?? ''), (string) ($args['token'] ?? ''));
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['id' => $id, 'label' => (string) ($args['label'] ?? '')];
            }),

            $this->tool('delete_github_credential', 'Delete one of your saved GitHub credentials.', [
                'id' => ['type' => 'integer'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $this->githubCreds->delete((int) ($args['id'] ?? 0), $a->userId);

                return ['id' => (int) ($args['id'] ?? 0), 'deleted' => true];
            }),

            $this->tool('list_redirects', 'List a site\'s redirect rules (3xx and 410 Gone) and captured 404s (#15).', [
                'site' => ['type' => 'string'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);

                return ['redirects' => $this->redirects->list($site->handle), 'missing_paths' => $this->redirects->listMissing($site->handle)];
            }),

            $this->tool('upsert_redirect', 'Create/update a rule for a path: a redirect to to_path, or status 410 to mark it permanently Gone (no to_path). Republishes.', [
                'site' => ['type' => 'string'],
                'from_path' => ['type' => 'string', 'description' => 'Source path, root-absolute (e.g. "/old-post/"). A full URL is reduced to its path.'],
                'to_path' => ['type' => 'string', 'description' => 'Target path. Required for a 3xx; ignored (stored empty) for 410.'],
                'status' => [
                    'type' => 'integer',
                    'enum' => [301, 302, 307, 308, 410],
                    'default' => 301,
                    'description' => '410 = Gone: the URL is permanently removed with no successor (deindexes faster than a 404). Served as a real 410 when serve_mode=direct; on a CDN (serve_mode=git) _redirects cannot express 410, so the path falls through to the site\'s 404 page.',
                ],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $saved = $this->redirects->save($site->handle, (string) ($args['from_path'] ?? ''), (string) ($args['to_path'] ?? ''), (int) ($args['status'] ?? 301));
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }
                $this->publish->trigger($site, 'publish: redirect changed (mcp)', 'mcp');

                // Echo the row AS STORED (normalized path, coerced status), and
                // say plainly when a 410 will not survive the CDN.
                return $saved + [
                    'saved' => true,
                    'note' => $saved['status'] === \Fastsite\Site\Service\RedirectService::GONE && $site->serveMode !== 'direct'
                        ? 'Stored. This site publishes to a CDN, where _redirects cannot return 410 — the path will serve the site 404 page. Switch serve_mode to "direct" for a real 410.'
                        : '',
                ];
            }),

            $this->tool('delete_redirect', 'Delete a redirect rule by id. Republishes.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $this->redirects->delete($site->handle, (int) ($args['id'] ?? 0));
                $this->publish->trigger($site, 'publish: redirect removed (mcp)', 'mcp');

                return ['id' => (int) ($args['id'] ?? 0), 'deleted' => true];
            }),

            $this->tool('list_posts', 'List posts and pages on a site, newest first — each with its LIVE url, excerpt and dates, so a listing rarely needs a follow-up get_post. Up to 200 per call (per_page); the response carries total_pages so you know how many calls remain. Includes DRAFTS unless you pass status.', [
                'site' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['post', 'page'], 'description' => 'Restrict to posts or pages. Omit for both.'],
                'status' => ['type' => 'string', 'description' => 'published | draft | scheduled. OMIT AND YOU GET DRAFTS TOO — pass "published" whenever you are reading the live site.'],
                'search' => ['type' => 'string', 'description' => 'Match against title and slug.'],
                'page' => ['type' => 'integer', 'description' => 'Page number from 1.'],
                'per_page' => ['type' => 'integer', 'description' => 'Items per page, 1-200 (default 50).'],
                'category' => ['type' => 'string', 'description' => 'Only posts in this category (its slug, see list_categories).'],
            ], 'content:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $page = max(1, (int) ($args['page'] ?? 1));
                $perPage = min(200, max(1, (int) ($args['per_page'] ?? 50)));
                $search = trim((string) ($args['search'] ?? ''));
                try {
                    $categoryId = ($args['category'] ?? '') !== '' ? $this->categories->resolve($site, $args['category']) : null;
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }
                $r = $this->contentRepo->listFiltered($site->handle, $args['type'] ?? null, $args['status'] ?? null, $page, $perPage, $search !== '' ? $search : null, $categoryId);

                return [
                    'total' => $r['total'],
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => $r['total'] > 0 ? (int) ceil($r['total'] / $perPage) : 0,
                    'items' => array_map(fn ($c): array => [
                        'id' => $c->id,
                        'type' => $c->type,
                        'slug' => $c->slug,
                        'title' => $c->title,
                        'status' => $c->status,
                        'url' => SiteLinks::absolute($site, SiteLinks::contentPath($site, $c->type, $c->slug, '', null, $c->categorySlug)),
                        'path' => SiteLinks::contentPath($site, $c->type, $c->slug, '', null, $c->categorySlug),
                        'category' => $c->categorySlug,
                        'excerpt' => $c->excerpt,
                        'published_at' => $c->publishedAt,
                        'scheduled_at' => $c->scheduledAt,
                        'updated_at' => $c->updatedAt,
                        'created_at' => $c->createdAt,
                        'featured_media_id' => $c->featuredMediaId,
                        'noindex' => $c->noindex,
                        // Non-null = this row IS the site's page for that HTTP
                        // status, so it lives at /{status}.html rather than at
                        // the url/path above.
                        'error_status' => $c->errorStatus,
                    ], $r['items']),
                ];
            }),

            $this->tool('get_post', 'Get one post/page by id — the full body HTML plus its live url, dates, SEO fields and featured image (with that image\'s url). body_html can be large; list_posts already carries the url, excerpt and dates if that is all you need.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'content:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $c = $this->contentRepo->findById($site->handle, (int) ($args['id'] ?? 0)) ?? throw new McpToolException('Content not found.');

                return [
                    'id' => $c->id, 'type' => $c->type, 'slug' => $c->slug, 'status' => $c->status,
                    'url' => SiteLinks::absolute($site, SiteLinks::contentPath($site, $c->type, $c->slug, '', null, $c->categorySlug)),
                    'path' => SiteLinks::contentPath($site, $c->type, $c->slug, '', null, $c->categorySlug),
                    'category' => $c->categorySlug,
                    'title' => $c->title, 'excerpt' => $c->excerpt, 'body_html' => $c->bodyHtml,
                    'intro' => $c->intro, 'tldr' => $c->tldr, 'conclusion' => $c->conclusion,
                    'seo_title' => $c->seoTitle, 'seo_description' => $c->seoDescription,
                    'author_name' => $c->authorName, 'source' => $c->source, 'source_ref' => $c->sourceRef,
                    'published_at' => $c->publishedAt, 'scheduled_at' => $c->scheduledAt,
                    'updated_at' => $c->updatedAt, 'created_at' => $c->createdAt,
                    'featured_media_id' => $c->featuredMediaId,
                    'featured_image' => $c->featuredMediaId !== null ? $this->mediaSummary($site, $c->featuredMediaId) : null,
                    'noindex' => $c->noindex, 'nofollow' => $c->nofollow, 'error_status' => $c->errorStatus,
                    'json_ld' => $c->jsonLd, 'warnings' => $c->warnings,
                ];
            }),

            $this->tool('create_post', 'Create a post. Call get_authoring_guide first. Body is HTML; alt is required on any image.', $this->contentSchema('post'), 'content:write',
                fn (AccessContext $a, array $args): array => $this->createContent($a, $args, 'post')),

            $this->tool('create_page', 'Create a page. Call get_authoring_guide first. Body is HTML.', $this->contentSchema('page'), 'content:write',
                fn (AccessContext $a, array $args): array => $this->createContent($a, $args, 'page')),

            $this->tool('update_post', 'Update a post/page by id. Only provided fields change.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'], 'title' => ['type' => 'string'],
                'body_html' => ['type' => 'string'], 'excerpt' => ['type' => 'string'], 'status' => ['type' => 'string'],
                'slug' => ['type' => 'string'], 'seo_title' => ['type' => 'string'], 'seo_description' => ['type' => 'string'],
                'intro' => ['type' => 'string', 'description' => 'Intro/lead HTML fragment — the shell renders it as the styled lead. Empty string clears.'],
                'tldr' => ['type' => 'string', 'description' => 'TL;DR summary HTML fragment — the shell renders it as a styled box; do NOT also bake it into body_html. Empty string clears.'],
                'conclusion' => ['type' => 'string', 'description' => 'Conclusion HTML fragment — rendered as a closing section by the shell. Empty string clears.'],
                'featured_media_id' => ['type' => 'integer', 'description' => 'Media id of the page\'s featured/LCP image (from upload_media_*). The build renders it eager + fetchpriority=high — including where it appears in the body — and preloads it in the head. 0 or null clears it; a dangling id is rejected.'],
                'noindex' => ['type' => 'boolean', 'description' => 'Hide from search engines (meta robots + excluded from sitemap.xml/llms.txt).'],
                'nofollow' => ['type' => 'boolean', 'description' => 'Ask crawlers not to follow this page\'s links.'],
                'category' => ['type' => 'string', 'description' => self::CATEGORY_FIELD . ' Null or "" takes the post out of its category.'],
                'error_status' => ['type' => 'integer', 'description' => 'OPTIONAL. Makes this page the site\'s error page for an HTTP status — one of 401, 403, 404, 410, 429, 451. It then renders to /{status}.html instead of its slug path, is forced noindex, and is kept out of sitemap.xml, llms.txt and every on-site listing. Which codes are actually SERVED depends on the serving mode: all of them on a customer\'s own Apache host, 404 and 410 in direct mode, and 404 ALONE on Cloudflare Pages — the rest are built and published but never returned there. One page per status; null clears it and the page becomes ordinary again.'],
                'json_ld' => ['type' => 'object', 'description' => 'Page-specific schema.org data (Product, Event, HowTo, …), merged into the page graph the engine emits. Null or {} clears it. Do not re-declare WebPage/WebSite/Organization/BreadcrumbList — those are already there.'],
                'sanitize' => ['type' => 'boolean', 'description' => 'Overrides the site-wide sanitize_content setting for THIS write. Set false to store the body VERBATIM (trusted port — keeps inline styles/scripts/iframes and interactive markup; exempt from the zero-JS/PageSpeed audit, and kept verbatim through translation too). Only applies when body_html is provided.'],
            ], 'content:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $hook = $this->factCheckBeforeStore($site, $args);
                $args = $hook['args'];
                try {
                    $c = $this->content->update($site, (int) ($args['id'] ?? 0), $args, 'oauth:' . $a->userId);
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                $out = ['id' => $c->id, 'slug' => $c->slug, 'status' => $c->status, 'warnings' => $c->warnings];
                if ($hook['fact_check'] !== null) {
                    $out['fact_check'] = $hook['fact_check'];
                }

                return $out;
            }),

            $this->tool('delete_content', 'Soft-delete a post/page (moves to trash — reversible). The site rebuilds without it on its own within a minute or two; call publish_site to take the page down right now.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'content:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $c = $this->content->softDelete($site, (int) ($args['id'] ?? 0), 'oauth:' . $a->userId);
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['id' => $c->id, 'slug' => $c->slug, 'status' => $c->status, 'deleted_at' => $c->deletedAt];
            }),

            $this->tool('restore_content', 'Restore a trashed post/page. Fails if a live item now uses its slug.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'content:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $c = $this->content->restore($site, (int) ($args['id'] ?? 0));
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['id' => $c->id, 'slug' => $c->slug, 'status' => $c->status];
            }),

            $this->tool('purge_content', 'Permanently delete a post/page and its revisions. Irreversible. Republishes the site, so a page that was live comes off it.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'content:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $this->content->purge($site, (int) ($args['id'] ?? 0));
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['id' => (int) ($args['id'] ?? 0), 'purged' => true];
            }),

            $this->tool('list_trash', 'List soft-deleted (trashed) content, optionally filtered by type.', [
                'site' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['post', 'page']],
            ], 'content:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $type = \in_array($args['type'] ?? null, ['post', 'page'], true) ? (string) $args['type'] : null;
                $items = array_map(
                    static fn ($c): array => ['id' => $c->id, 'type' => $c->type, 'slug' => $c->slug, 'title' => $c->title, 'deleted_at' => $c->deletedAt],
                    $this->content->listTrashed($site, $type),
                );

                return ['items' => $items, 'total' => \count($items)];
            }),

            $this->tool('list_media', 'List a site\'s media library, newest first — each item with the live url of the file itself (and, with include_variants, every avif/webp/jpg rendition and its width). Up to 200 per call. NOTE: media ships REFERENCED-ONLY, so a url is where the file lives once some page embeds it; an unused item is not on the CDN. Set trashed=true for the trash.', [
                'site' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => ['image', 'video', 'file'], 'description' => 'Restrict to one kind. Omit for all.'],
                'page' => ['type' => 'integer', 'description' => 'Page number from 1.'],
                'per_page' => ['type' => 'integer', 'description' => 'Items per page, 1-200 (default 50).'],
                'include_variants' => ['type' => 'boolean', 'description' => 'Add every rendition (format/width/url) per image. Off by default — an image has around ten.'],
                'trashed' => ['type' => 'boolean'],
            ], 'media:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $page = max(1, (int) ($args['page'] ?? 1));
                $perPage = min(200, max(1, (int) ($args['per_page'] ?? 50)));
                $kind = \in_array($args['kind'] ?? null, ['image', 'video', 'file'], true) ? [(string) $args['kind']] : null;
                $withVariants = (bool) ($args['include_variants'] ?? false);
                $r = ($args['trashed'] ?? false)
                    ? $this->media->listTrashed($site->handle, $page, $perPage, $kind)
                    : $this->media->list($site->handle, $page, $perPage, $kind);

                return [
                    'total' => $r['total'],
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => $r['total'] > 0 ? (int) ceil($r['total'] / $perPage) : 0,
                    'items' => array_map(function ($m) use ($site, $withVariants): array {
                        $item = $this->mediaSummary($site, $m);
                        $item['bytes'] = $m->bytes;
                        $item['decorative'] = $m->decorative;
                        $item['optimized_at'] = $m->optimizedAt;
                        $item['deleted_at'] = $m->deletedAt;
                        if ($withVariants) {
                            $item['variants'] = SiteLinks::mediaVariants($site, $m);
                        }

                        return $item;
                    }, $r['items']),
                ];
            }),

            $this->tool('upload_media_from_url', 'Import an image from a URL (SSRF-guarded). See get_authoring_guide for recommended image sizes per role (logo/featured/in-content). Provide alt when you know it; omitted alt is generated automatically (vision AI when the site has an AI key, else derived from the filename).', [
                'site' => ['type' => 'string'], 'url' => ['type' => 'string'], 'alt' => ['type' => 'string'], 'decorative' => ['type' => 'boolean'],
            ], 'media:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $m = $this->media->importFromUrl($site, (string) ($args['url'] ?? ''), (string) ($args['alt'] ?? ''), (bool) ($args['decorative'] ?? false));
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return $this->mediaSummary($site, $m->id) ?? [];
            }),

            $this->tool('create_upload_ticket', 'THE PREFERRED WAY TO UPLOAD A FILE YOU HOLD. Mints a single-use, short-lived HTTPS URL that takes the raw bytes of one file and ingests it into this site\'s media library through the same pipeline a dashboard upload uses — variants, ShortPixel, auto alt, and the usual <img data-media-id> embed. Use it WHENEVER you can make an HTTP request: a shell, a code sandbox, fetch() in a browser, or by handing the URL to a person to run. The bytes never pass through your own output, so there is no practical size limit here — only the real per-type ceilings (15 MB image, 100 MB generic file, 500 MB video). That is the difference that matters: upload_media_from_base64 caps out around 100 KB of FILE, because its base64 is an argument of the call that you have to write out token by token, and it fails by truncating rather than erroring. Redeem this with exactly one request: curl --upload-file photo.jpg "<upload_url>". The response to that PUT carries the new media id. Valid 30 minutes, usable once, and scoped to this site alone. ONLY if you cannot make an HTTP request at all: use upload_media_from_url when the file is already hosted somewhere, or upload_media_from_base64 for something genuinely small.', [
                'site' => ['type' => 'string'],
                'filename' => ['type' => 'string', 'description' => 'Optional. Best left out: the type is then sniffed from the bytes, which is more reliable than a name you guessed. Supply it only when the real filename matters, e.g. a .pdf whose name becomes the download label.'],
                'alt' => ['type' => 'string', 'description' => 'Alt text for an image, applied when the bytes arrive. Omitted alt is generated automatically.'],
                'decorative' => ['type' => 'boolean'],
                'max_bytes' => ['type' => 'integer', 'description' => 'Optional lower ceiling for this one ticket. Cannot raise the per-type limits.'],
            ], 'media:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $ticket = $this->uploadTickets->create(
                    $site->handle,
                    $a->actorType,
                    $a->userId,
                    (string) ($args['filename'] ?? ''),
                    (string) ($args['alt'] ?? ''),
                    (bool) ($args['decorative'] ?? false),
                    isset($args['max_bytes']) ? (int) $args['max_bytes'] : null,
                );
                // The url already carries the token; handing back the bare
                // token as well would just duplicate a secret in the reply.
                unset($ticket['token']);

                return $ticket + [
                    'how' => 'PUT the raw file bytes to upload_url, exactly once: curl --upload-file <your file> "' . $ticket['upload_url'] . '"',
                    'then' => 'That PUT answers with the media id, its url and its <img data-media-id> embed. Nothing else is needed.',
                ];
            }),

            $this->tool('upload_media_from_base64', 'FALLBACK ONLY — reach for create_upload_ticket instead whenever you can make an HTTP request, because it carries no practical size limit and this does. Upload media you already HOLD, as base64 bytes, for the case where you cannot make a request at all: the binary sibling of upload_media_from_url, which is the better choice again when the file is already reachable at a URL. Same library, same variant pipeline, same <img data-media-id> embed. SIZE IS THE BINDING CONSTRAINT AND IT IS NOT THE SERVICE CEILING: the base64 is an ARGUMENT OF THIS CALL, so you have to write it out token by token, and a file costs about 1.4x its own size in base64 — which exceeds any model output limit long before it reaches the 15 MB the service would accept. Keep the FILE under roughly 100 KB (an icon, an SVG, a small generated thumbnail). For a photo or anything larger, host it and use upload_media_from_url, or POST the raw bytes as multipart to /api/v1/sites/{site}/media, which never routes them through a model at all. And if your only copy is an attachment your runtime hands you as an IMAGE rather than as readable bytes, you cannot use this tool for it: you can see the picture but not read the file. Get a URL instead of emitting a guess.', [
                'site' => ['type' => 'string'],
                'data' => ['type' => 'string', 'description' => 'The file content, base64-encoded. A full "data:<mime>;base64,…" URI is accepted too, and line breaks are ignored.'],
                'filename' => ['type' => 'string', 'description' => 'Original filename with its extension, e.g. "hero.png" — the extension selects the pipeline. Optional: the type is sniffed from the bytes when omitted.'],
                'alt' => ['type' => 'string'],
                'decorative' => ['type' => 'boolean'],
            ], 'media:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $m = $this->media->ingestBase64($site, (string) ($args['data'] ?? ''), (string) ($args['filename'] ?? ''), (string) ($args['alt'] ?? ''), (bool) ($args['decorative'] ?? false));
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return $this->mediaSummary($site, $m->id) ?? [];
            }),

            $this->tool('crop_media', 'Crop and/or resize an image NON-DESTRUCTIVELY. The uploaded original is never modified — the rect is stored and re-applied every time variants are built — so this is re-runnable and fully reversible (send clear=true for the full picture back). Every page already embedding the id follows the new framing with nothing to re-embed, because the emitted filenames move. Use it to reframe a hero, cut a share card, or cap an oversized image\'s width.', [
                'site' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'description' => 'Image media id, from list_media.'],
                'x' => ['type' => 'number', 'description' => 'Left edge as a FRACTION of the original, 0..1. The rect is a fraction of the ORIGINAL image, not of the currently cropped view — so re-cropping never compounds. Divide your pixel values by the width/height list_media reports for an uncropped image.'],
                'y' => ['type' => 'number', 'description' => 'Top edge as a fraction of the original, 0..1.'],
                'w' => ['type' => 'number', 'description' => 'Width as a fraction of the original, 0..1. Required for a crop; omit x/y/w/h entirely to resize only.'],
                'h' => ['type' => 'number', 'description' => 'Height as a fraction of the original, 0..1.'],
                'resize_width' => ['type' => 'integer', 'description' => 'Cap the largest shipped file at this many pixels, applied AFTER the crop. Never enlarges. Omit or 0 for no cap.'],
                'clear' => ['type' => 'boolean', 'description' => 'Remove the stored crop and width cap, restoring the full uploaded image. Nothing was ever lost, so this always works.'],
            ], 'media:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $id = (int) ($args['id'] ?? 0);
                $clear = (bool) ($args['clear'] ?? false);

                $crop = null;
                if (!$clear && (float) ($args['w'] ?? 0) > 0 && (float) ($args['h'] ?? 0) > 0) {
                    $crop = [
                        'x' => (float) ($args['x'] ?? 0),
                        'y' => (float) ($args['y'] ?? 0),
                        'w' => (float) $args['w'],
                        'h' => (float) $args['h'],
                    ];
                }

                try {
                    $item = $this->media->transform($site, $id, $crop, $clear ? null : (int) ($args['resize_width'] ?? 0));
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }
                $this->publish->trigger($site, 'publish: image #' . $id . ' reframed (mcp)', 'mcp-crop');

                return ($this->mediaSummary($site, $item->id) ?? []) + [
                    'crop' => $item->sharpTransform()['crop'] ?? null,
                    'resize_width' => $item->resizeWidth,
                    'revision' => $item->revision,
                    'note' => 'The original is untouched — call again with clear=true to restore the full image. Rebuild + publish queued.',
                ];
            }),

            $this->tool('replace_media', 'Replace the CONTENT behind an existing media id, keeping the id, the name and the alt — every page already embedding it shows the new file, with nothing to re-embed. This is how you update a logo, a photo or a downloadable file after it has been used; uploading a new item would leave every page pointing at the old one. Provide EITHER `url` (SSRF-guarded import) or `data` (base64 bytes you hold). The kind cannot change (an image must stay an image) and a file keeps its extension (it decides how the build renders the embed), and the site republishes automatically.', [
                'site' => ['type' => 'string'],
                'id' => ['type' => 'integer', 'description' => 'The media id to replace, from list_media.'],
                'url' => ['type' => 'string', 'description' => 'Fetch the replacement from this URL.'],
                'data' => ['type' => 'string', 'description' => 'The replacement content, base64-encoded (or a "data:<mime>;base64,…" URI). Use instead of url when you hold the bytes. Carries the same limit as upload_media_from_base64 — this base64 is an argument of this call, so keep the file under roughly 100 KB and use `url` (or the REST multipart replace route) for anything bigger.'],
                'filename' => ['type' => 'string', 'description' => 'The replacement\'s filename, e.g. "logo.png" — used ONLY to determine its type (the item keeps its own name, because that is what {{ filename }} embeds resolve on). Optional; the type is otherwise taken from the URL or the item itself.'],
                'alt' => ['type' => 'string', 'description' => 'Optional new alt text. Omitted keeps the existing alt (it usually still describes the picture); an item whose alt is empty gets one generated.'],
            ], 'media:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $id = (int) ($args['id'] ?? 0);
                $url = trim((string) ($args['url'] ?? ''));
                $data = trim((string) ($args['data'] ?? ''));
                $alt = trim((string) ($args['alt'] ?? '')) !== '' ? (string) $args['alt'] : null;
                $filename = (string) ($args['filename'] ?? '');
                try {
                    if ($data !== '') {
                        $m = $this->media->replaceFromBase64($site, $id, $data, $filename, $alt);
                    } elseif ($url !== '') {
                        $m = $this->media->replaceFromUrl($site, $id, $url, $alt, null, $filename);
                    } else {
                        throw new McpToolException('Provide either `url` or `data` (base64) with the replacement content.');
                    }
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }
                $this->publish->trigger($site, 'publish: media #' . $m->id . ' replaced', 'mcp-media-replace');

                // The url MOVED: /media/* is immutable-cached for a year, so a
                // replaced item's public filename carries a revision suffix.
                return ($this->mediaSummary($site, $m->id) ?? []) + [
                    'revision' => $m->revision,
                    'note' => 'Every page embedding this id now serves the new file; rebuild + publish queued.',
                ];
            }),

            $this->tool('delete_media', 'Soft-delete a media item (moves to trash — reversible). Already-referenced media keeps rendering until purged.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'media:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $m = $this->media->softDelete($site, (int) ($args['id'] ?? 0));
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['id' => $m->id, 'kind' => $m->kind, 'deleted_at' => $m->deletedAt];
            }),

            $this->tool('restore_media', 'Restore a trashed media item.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'media:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $m = $this->media->restore($site, (int) ($args['id'] ?? 0));
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['id' => $m->id, 'kind' => $m->kind];
            }),

            $this->tool('purge_media', 'Permanently delete a media item, its variants, and its files on disk. Irreversible.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'media:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $this->media->purge($site, (int) ($args['id'] ?? 0));
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['id' => (int) ($args['id'] ?? 0), 'purged' => true];
            }),

            $this->tool('upsert_component', 'Create/update a component (html or react). React compiles at upload. Set verbatim:true to ship the rendered output AS AUTHORED — scripts, iframes and event handlers survive instead of being stripped (the output is wrapped in the data-fs-embed trusted-region marker and the site CSP relaxes to let it run). Omitting verbatim on an update keeps the stored flag.', [
                'site' => ['type' => 'string'], 'handle' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['html', 'react']],
                'source_code' => ['type' => 'string'], 'css' => ['type' => 'string'],
                'variables_schema' => ['type' => 'object', 'description' => 'Optional JSON schema of the data-values the component expects.'],
                'verbatim' => ['type' => 'boolean', 'description' => 'Ship output as authored (scripts and all). Default false; omitted on update = unchanged.'],
            ], 'components:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $c = $this->components->upsert($site, (string) ($args['handle'] ?? ''), $args);
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }
                // A referenced component appears on pages whose content rows
                // never move — republish or the live site keeps the old render.
                $republished = $this->componentHooks->referencing($site->handle, $c->handle)
                    || $this->components->referencedByContent($site, $c->handle);
                if ($republished) {
                    $this->publish->trigger($site, 'publish: referenced component changed (mcp)', 'mcp');
                }

                return ['handle' => $c->handle, 'type' => $c->type, 'status' => $c->status, 'verbatim' => $c->verbatim, 'warnings' => $c->warnings, 'republished' => $republished, 'use_in_body' => '<div data-fs-component="' . $c->handle . '" data-values="{}"></div>'];
            }),

            $this->tool('list_forms', 'Forms this site can embed in a page, with submission counts and whether SMTP is set up. A form is the ONE interactive thing a static site gets: the page posts to the engine, which emails the site owner and stores the submission. Ships zero JavaScript.', [
                'site' => ['type' => 'string'],
            ], 'forms:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $counts = $this->forms->counts($site->handle);

                return [
                    'smtp_configured' => $site->smtpConfigured(),
                    'note' => $site->smtpConfigured()
                        ? null
                        : 'No SMTP account is set for this site, so submissions are stored but nobody is emailed. Set it on Site Settings.',
                    'spam_mode' => $site->spamMode,
                    'turnstile_configured' => $site->turnstileConfigured(),
                    'forms' => array_map(
                        static fn (\Fastsite\Form\Dto\Form $f): array => \Fastsite\Api\V1\FormsHandler::toArray($f)
                            + [
                                'submissions' => $counts[$f->id]['total'] ?? 0,
                                'failed' => $counts[$f->id]['failed'] ?? 0,
                                'held_as_spam' => $counts[$f->id]['spam'] ?? 0,
                            ],
                        $this->forms->list($site->handle),
                    ),
                ];
            }),

            $this->tool('upsert_form', 'Create or update a form. `fields` is a list of {name, type, label, required, placeholder, options, wide}; type is one of text/email/tel/url/number/date/textarea/select/checkbox. `recipients` is who gets the notification and is stored server-side — it is never read from the submitted page, so a form can never be used as an open relay. The first email field becomes the notification Reply-To. Republishes the site, because the markup is generated at build.', [
                'site' => ['type' => 'string'], 'handle' => ['type' => 'string'],
                'name' => ['type' => 'string'], 'recipients' => ['type' => 'string', 'description' => 'Comma separated addresses.'],
                'subject' => ['type' => 'string', 'description' => 'Notification subject; {field_name} inserts a submitted value.'],
                'fields' => ['type' => 'array', 'description' => 'Field definitions, in the order they should appear.'],
                'submit_label' => ['type' => 'string'], 'success_path' => ['type' => 'string', 'description' => 'A path on this site to redirect to after a successful send.'],
                'success_message' => ['type' => 'string'], 'consent_text' => ['type' => 'string', 'description' => 'Non-empty adds a required consent checkbox with this label.'],
                'messages' => ['type' => 'object', 'description' => 'Optional overrides for the engine\'s own replies to a visitor (validation errors, and the sent / too-many / not-found pages). The engine ALREADY answers in the site\'s language from a built-in pack, so this is for matching the site\'s VOICE, or for a locale with no pack. Keys: required, email, url, number, choice, consent, error_title, sent_title, sent_body, throttled_title, throttled_body, notfound_title, notfound_body, back. {field} and {consent} are the placeholders. Omit the key entirely to leave stored wording untouched.'],
                'enabled' => ['type' => 'boolean'],
                'turnstile' => ['type' => 'boolean', 'description' => 'Show a Cloudflare Turnstile challenge on this form (default off). Needs turnstile_site_key + turnstile_secret on the site or NO challenge renders at all. It costs the page a third-party script and relaxes its CSP, so it is the escalation for a form actually being hit — the content spam scorer runs on every form regardless, including the no-JS path and bespoke forms this markup never reaches. OMITTING this key keeps the stored value, so a partial update cannot silently drop the challenge off a form under attack.'],
            ], 'forms:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $form = $this->forms->save($site->handle, $args);
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }
                $this->publish->trigger($site, 'publish: form "' . $form->handle . '" updated (mcp)', 'mcp');

                return \Fastsite\Api\V1\FormsHandler::toArray($form) + ['republished' => true];
            }),

            $this->tool('delete_form', 'Delete a form and its stored submissions. Pages embedding it render nothing there, so the site republishes.', [
                'site' => ['type' => 'string'], 'handle' => ['type' => 'string'],
            ], 'forms:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $form = $this->forms->find($site->handle, (string) ($args['handle'] ?? ''));
                if ($form === null) {
                    throw new McpToolException('Unknown form: ' . (string) ($args['handle'] ?? ''));
                }
                $this->forms->delete($site->handle, $form->id);
                $this->publish->trigger($site, 'publish: form deleted (mcp)', 'mcp');

                return ['deleted' => $form->handle, 'republished' => true];
            }),

            $this->tool('list_form_submissions', 'What people have sent through a form, newest first, with the delivery status, any send error, and the spam score the content guard gave it. This is where "the email never arrived" gets answered — the submission is stored before any mail is attempted, so a broken SMTP config delays notifications without ever losing a lead, and a submission the guard held is in `folder: "spam"` rather than gone.', [
                'site' => ['type' => 'string'], 'handle' => ['type' => 'string'],
                'limit' => ['type' => 'integer'], 'offset' => ['type' => 'integer'],
                'folder' => ['type' => 'string', 'enum' => ['inbox', 'spam', 'all'], 'description' => 'Default "inbox" = everything except submissions the spam guard held. "spam" = only those. "all" = both.'],
            ], 'forms:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $form = $this->forms->find($site->handle, (string) ($args['handle'] ?? ''));
                if ($form === null) {
                    throw new McpToolException('Unknown form: ' . (string) ($args['handle'] ?? ''));
                }

                $folder = (string) ($args['folder'] ?? 'inbox');
                if (!\in_array($folder, ['inbox', 'spam', 'all'], true)) {
                    $folder = 'inbox';
                }

                return [
                    'form' => $form->handle,
                    'folder' => $folder,
                    'submissions' => array_map(
                        \Fastsite\Api\V1\FormsHandler::submissionToArray(...),
                        $this->forms->submissions($site->handle, $form->id, (int) ($args['limit'] ?? 50), (int) ($args['offset'] ?? 0), $folder),
                    ),
                ];
            }),

            $this->tool('retry_form_sends', 'Re-queue notifications that FAILED to send — the recovery path after fixing SMTP. The submissions were never lost, only undelivered, so this turns "the mail config was wrong for a week" into a caught-up inbox rather than a week of leads nobody saw. Omit `handle` to retry every form on the site.', [
                'site' => ['type' => 'string'],
                'handle' => ['type' => 'string', 'description' => 'Optional: just this form.'],
            ], 'forms:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $formId = null;
                if (($args['handle'] ?? '') !== '') {
                    $form = $this->forms->find($site->handle, (string) $args['handle']);
                    if ($form === null) {
                        throw new McpToolException('Unknown form: ' . (string) $args['handle']);
                    }
                    $formId = $form->id;
                }

                $ids = $this->forms->retryFailed($site->handle, $formId);
                foreach ($ids as $id) {
                    $this->queue->enqueue(type: 'send_form_email', siteHandle: $site->handle, payload: ['submission_id' => $id]);
                }

                return ['requeued' => \count($ids), 'submission_ids' => $ids];
            }),

            $this->tool('release_form_spam', 'Take submissions OUT of the spam folder and send their notifications — the correction path for a false positive. Omit both `submission_id` and `handle` to release everything held on the site. The spam score and its reasons are kept on the row, because a released submission is a labelled mistake and the only evidence of why the scorer got it wrong.', [
                'site' => ['type' => 'string'],
                'submission_id' => ['type' => 'integer', 'description' => 'Optional: release just this one.'],
                'handle' => ['type' => 'string', 'description' => 'Optional: release everything held on this form.'],
            ], 'forms:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $formId = null;
                if (($args['handle'] ?? '') !== '') {
                    $form = $this->forms->find($site->handle, (string) $args['handle']);
                    if ($form === null) {
                        throw new McpToolException('Unknown form: ' . (string) $args['handle']);
                    }
                    $formId = $form->id;
                }

                $one = (int) ($args['submission_id'] ?? 0);
                $ids = $this->forms->releaseSpam($site->handle, $one > 0 ? $one : null, $formId);
                foreach ($ids as $id) {
                    $this->queue->enqueue(type: 'send_form_email', siteHandle: $site->handle, payload: ['submission_id' => $id]);
                }

                return ['released' => \count($ids), 'submission_ids' => $ids];
            }),

            $this->tool('mark_form_spam', 'Move submissions INTO the spam folder by hand — the judgement the scorer could not make, for when a human has reviewed a batch and concluded they are all bots. They are then never emailed, and delete themselves after the site\'s retention window (or immediately via purge). Narrow with `status` (e.g. "failed" = everything that never sent) or `submission_id`; `status` is what makes a bulk call safe, because "everything that failed to send" is a set someone can actually have checked.', [
                'site' => ['type' => 'string'],
                'submission_id' => ['type' => 'integer', 'description' => 'Optional: just this one.'],
                'handle' => ['type' => 'string', 'description' => 'Optional: just this form.'],
                'status' => ['type' => 'string', 'enum' => ['pending', 'sent', 'failed'], 'description' => 'Optional: only submissions currently in this state.'],
            ], 'forms:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $formId = null;
                if (($args['handle'] ?? '') !== '') {
                    $form = $this->forms->find($site->handle, (string) $args['handle']);
                    if ($form === null) {
                        throw new McpToolException('Unknown form: ' . (string) $args['handle']);
                    }
                    $formId = $form->id;
                }

                $one = (int) ($args['submission_id'] ?? 0);
                $status = trim((string) ($args['status'] ?? ''));
                $ids = $this->forms->markSpamWhere($site->handle, $one > 0 ? $one : null, $formId, $status !== '' ? $status : null);

                return ['marked' => \count($ids), 'submission_ids' => $ids];
            }),

            $this->tool('purge_form_spam', 'Delete submissions sitting in the spam folder. `days` is how old a held submission must be (0 = empty it now, which is what you want after reviewing a batch). Held submissions delete themselves on the site\'s retention window anyway; this is the "I have looked at these and they are all bots" shortcut. Only ever touches rows already marked spam — it cannot delete an inbox lead.', [
                'site' => ['type' => 'string'],
                'days' => ['type' => 'integer', 'description' => 'Default 0 = delete everything currently held.'],
            ], 'forms:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $days = \array_key_exists('days', $args) ? (int) $args['days'] : 0;

                return ['deleted' => $this->forms->purgeSpam($site->handle, $days), 'older_than_days' => $days];
            }),

            $this->tool('rescore_form_spam', 'Re-run the spam scorer over STORED submissions and report what it would decide, WITHOUT changing anything unless `apply` is true. This is how the guard gets calibrated honestly: run it over submissions a human has already judged and compare. Returns each submission\'s score, the reasons, and whether it would be held. With `apply: true` it writes the scores and moves newly-flagged rows into the spam folder — never out of it, and never a submission already marked sent.', [
                'site' => ['type' => 'string'],
                'handle' => ['type' => 'string', 'description' => 'Optional: just this form.'],
                'apply' => ['type' => 'boolean', 'description' => 'Default false (a dry run).'],
            ], 'forms:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $formId = null;
                if (($args['handle'] ?? '') !== '') {
                    $form = $this->forms->find($site->handle, (string) $args['handle']);
                    if ($form === null) {
                        throw new McpToolException('Unknown form: ' . (string) $args['handle']);
                    }
                    $formId = $form->id;
                }

                $apply = (bool) ($args['apply'] ?? false);
                $rows = $this->forms->rescore(
                    $site->handle,
                    fn (\Fastsite\Form\Dto\Form $f, array $v, string $l): \Fastsite\Form\Spam\SpamVerdict
                        => $this->scorer->score($f, $v, $l),
                    $formId,
                    $apply,
                );

                $wouldHold = array_values(array_filter($rows, static fn (array $r): bool => $r['would_hold']));

                return [
                    'applied' => $apply,
                    'scanned' => \count($rows),
                    'would_hold' => \count($wouldHold),
                    'threshold' => \Fastsite\Form\Spam\SpamScorer::THRESHOLD,
                    'rows' => $rows,
                ];
            }),

            $this->tool('send_test_email', 'Send a real test message through this site\'s SMTP settings, to prove form notifications will arrive. Reports the phase any failure happened in (connect / greeting / starttls / auth / envelope) rather than a generic error.', [
                'site' => ['type' => 'string'], 'to' => ['type' => 'string', 'description' => 'Where to send the test.'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $this->mailer->sendTest($site, (string) ($args['to'] ?? ''));
                } catch (\Fastsite\Mail\MailException $e) {
                    throw new McpToolException(
                        'Failed at ' . ($e->phase !== '' ? $e->phase : 'send') . ': ' . $e->getMessage() . ' — ' . $e->hint(),
                    );
                }

                return ['sent' => true, 'to' => (string) ($args['to'] ?? ''), 'via' => $site->smtpHost];
            }),

            $this->tool('get_component', 'One component with its FULL source code, css and variables schema — the read half of upsert_component (list_components lists without source).', [
                'site' => ['type' => 'string'], 'handle' => ['type' => 'string'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $c = $this->components->get($site, (string) ($args['handle'] ?? ''));
                if ($c === null) {
                    throw new McpToolException('Unknown component: ' . (string) ($args['handle'] ?? ''));
                }

                return [
                    'handle' => $c->handle, 'type' => $c->type, 'status' => $c->status,
                    'verbatim' => $c->verbatim, 'warnings' => $c->warnings, 'error' => $c->error,
                    'source_code' => $c->sourceCode, 'css' => $c->css,
                    'variables_schema' => json_decode($c->variablesSchema, true) ?: new \stdClass(),
                    'use_in_body' => '<div data-fs-component="' . $c->handle . '" data-values="{}"></div>',
                ];
            }),

            $this->tool('list_component_hooks', 'List a site\'s component hooks — build-time rules that inject a component (typically a CTA) into targeted pages without editing content.', [
                'site' => ['type' => 'string'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);

                return ['hooks' => array_map(static fn ($h): array => [
                    'id' => $h->id, 'label' => $h->label, 'component_handle' => $h->componentHandle,
                    'values' => $h->values === [] ? new \stdClass() : $h->values,
                    'position' => $h->position, 'paragraph' => $h->paragraph, 'target' => $h->target,
                    'include_slugs' => $h->includeSlugs, 'exclude_slugs' => $h->excludeSlugs,
                    'priority' => $h->priority, 'enabled' => $h->enabled,
                ], $this->componentHooks->list($site->handle))];
            }),

            $this->tool('upsert_component_hook', 'Create/update a component hook: inject an existing component into every targeted page at build time (a CTA after the first paragraph of all posts, a banner at the bottom of every page, …) WITHOUT changing stored content. Pass an id to update. target scopes by type; exclude_slugs keeps it off specific pages (the home page\'s slug is "home"); include_slugs (optional) limits it to listed pages. Slugs are PRIMARY-locale slugs, so one rule covers every language variant; the page\'s locale is passed to the component as a `locale` value. Republishes.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'], 'label' => ['type' => 'string'],
                'component_handle' => ['type' => 'string', 'description' => 'Handle of an existing component (see list_components).'],
                'values' => ['type' => 'object', 'description' => 'data-values passed to the component (same on every page/locale).'],
                'position' => ['type' => 'string', 'enum' => ['top', 'after_paragraph', 'middle', 'bottom'], 'description' => 'Where in the body it lands. after_paragraph uses `paragraph` (1-based, counts top-level paragraphs; clamps to bottom when the page has fewer). Default bottom.'],
                'paragraph' => ['type' => 'integer', 'description' => 'For position=after_paragraph: insert after this paragraph (1 = after the first).'],
                'target' => ['type' => 'string', 'enum' => ['all', 'posts', 'pages'], 'description' => 'Which content type gets it. Default posts.'],
                'include_slugs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Only these primary slugs get the hook (empty = every targeted page).'],
                'exclude_slugs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'These primary slugs never get it (e.g. ["home","terms"]). Wins over include.'],
                'priority' => ['type' => 'integer', 'description' => 'Order when several hooks land at the same position (lower first, default 100).'],
                'enabled' => ['type' => 'boolean'],
            ], 'components:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $h = $this->componentHooks->save($site->handle, $args);
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }
                $this->publish->trigger($site, 'publish: component hook changed (mcp)', 'mcp');

                return ['id' => $h->id, 'component_handle' => $h->componentHandle, 'position' => $h->position, 'paragraph' => $h->paragraph, 'target' => $h->target, 'enabled' => $h->enabled];
            }),

            $this->tool('delete_component_hook', 'Delete a component hook (the component itself is kept). Republishes.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'components:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $this->componentHooks->delete($site->handle, (int) ($args['id'] ?? 0));
                $this->publish->trigger($site, 'publish: component hook removed (mcp)', 'mcp');

                return ['id' => (int) ($args['id'] ?? 0), 'deleted' => true];
            }),

            $this->tool('delete_component', 'Delete a component (removes its row, cached renders, and compiled bundle).', [
                'site' => ['type' => 'string'], 'handle' => ['type' => 'string'],
            ], 'components:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $referenced = $this->componentHooks->referencing($site->handle, (string) ($args['handle'] ?? ''))
                    || $this->components->referencedByContent($site, (string) ($args['handle'] ?? ''));
                try {
                    $this->components->delete($site, (string) ($args['handle'] ?? ''));
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }
                if ($referenced) {
                    $this->publish->trigger($site, 'publish: referenced component removed (mcp)', 'mcp');
                }

                return ['handle' => (string) ($args['handle'] ?? ''), 'deleted' => true, 'republished' => $referenced];
            }),

            $this->tool('shell_list_files', 'List a site\'s shell files (templates, tokens, i18n).', [
                'site' => ['type' => 'string'],
            ], 'shell:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);

                return ['files' => $this->shell->listFiles($site)];
            }),

            $this->tool('shell_get_file', 'Read one shell file (raw content).', [
                'site' => ['type' => 'string'], 'path' => ['type' => 'string'],
            ], 'shell:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    return ['path' => (string) $args['path'], 'content' => $this->shell->readFile($site, (string) ($args['path'] ?? ''))];
                } catch (\Throwable $e) {
                    throw new McpToolException($e->getMessage());
                }
            }),

            $this->tool('shell_put_file', 'Write a shell file (validated + triggers a rebuild). This is how you design a site.', [
                'site' => ['type' => 'string'], 'path' => ['type' => 'string'], 'content' => ['type' => 'string'],
            ], 'shell:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $this->shell->writeFile($site, (string) ($args['path'] ?? ''), (string) ($args['content'] ?? ''), 'oauth:' . $a->userId);
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['path' => (string) $args['path'], 'status' => 'written', 'rebuild' => 'queued'];
            }),

            $this->tool('shell_snapshot', 'Snapshot the current shell (revision you can restore).', [
                'site' => ['type' => 'string'], 'note' => ['type' => 'string'],
            ], 'shell:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);

                return ['revision' => $this->shell->snapshot($site, (string) ($args['note'] ?? ''), 'oauth:' . $a->userId)];
            }),

            $this->tool('shell_restore', 'Restore a shell revision.', [
                'site' => ['type' => 'string'], 'revision' => ['type' => 'integer'],
            ], 'shell:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $this->shell->restore($site, (int) ($args['revision'] ?? 0), 'oauth:' . $a->userId);
                } catch (\Throwable $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['restored' => (int) $args['revision']];
            }),

            $this->tool('add_language', 'Add a locale to a site and queue auto-translation.', [
                'site' => ['type' => 'string'], 'locale' => ['type' => 'string'],
            ], 'translations:manage', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $locales = $this->siteService->addLocale($site->handle, (string) ($args['locale'] ?? ''));
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }
                $uuid = $this->queue->enqueue('translate_content', $site->handle, ['trigger' => 'mcp'], 'low', 'translate:' . $site->handle);
                $this->queue->promoteNow($uuid);

                return ['locales' => $locales, 'translation_job' => $uuid];
            }),

            $this->tool('remove_language', 'Remove a locale from a site: deletes its translations + the /{locale}/ pages, then rebuilds and publishes. The primary locale cannot be removed.', [
                'site' => ['type' => 'string'], 'locale' => ['type' => 'string'],
            ], 'translations:manage', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $locales = $this->siteService->removeLocale($site->handle, (string) ($args['locale'] ?? ''));
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }
                $this->publish->trigger($site, 'publish: language removed (mcp)', 'mcp');

                return ['locales' => $locales];
            }),

            $this->tool('translate_site', 'Refresh translations for all locales (failed items are reset and re-attempted), then publish.', [
                'site' => ['type' => 'string'],
            ], 'translations:manage', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                // Same reset semantics as the dashboard Retry: failed rows
                // back to pending, parked twins AND zombie stale-running
                // rows cancelled, ONE fresh job with a full attempt budget
                // (a dedupe-absorbed twin could be one failure from
                // dead-lettering).
                $reset = $this->translations->resetFailed($site);
                $cancelled = $this->queue->cancelPending('translate_content', $site->handle)
                    + $this->queue->cancelStaleRunning('translate_content', $site->handle);
                $uuid = $this->queue->enqueue('translate_content', $site->handle, ['trigger' => 'mcp'], 'low', 'translate:' . $site->handle);
                $this->queue->promoteNow($uuid);

                return ['translation_job' => $uuid, 'failed_reset' => $reset, 'stuck_jobs_cleared' => $cancelled];
            }),

            $this->tool(
                'retranslate',
                'Force content to be translated again from scratch, even when its translations are already up to date. '
                . 'Pass content_id for one page, or omit it to reset the whole site. Use this when translations are WRONG '
                . 'rather than out of date — an up-to-date translation is never re-run on its own. Manual translations are kept.',
                ['site' => ['type' => 'string'], 'content_id' => ['type' => 'integer']],
                'translations:manage',
                function (AccessContext $a, array $args): array {
                    $site = $this->site($a, $args);
                    $contentId = (int) ($args['content_id'] ?? 0);
                    $reset = $contentId > 0
                        ? $this->translations->resetContent($site, $contentId)
                        : $this->translations->resetAll($site);

                    // A site-wide reset supersedes whatever is parked, so
                    // clear the twins first; a single page just joins the
                    // existing queue.
                    $cancelled = 0;
                    if ($contentId === 0) {
                        $cancelled = $this->queue->cancelPending('translate_content', $site->handle)
                            + $this->queue->cancelStaleRunning('translate_content', $site->handle);
                    }
                    $uuid = $this->queue->enqueue('translate_content', $site->handle, ['trigger' => 'mcp-retranslate'], 'low', 'translate:' . $site->handle);
                    $this->queue->promoteNow($uuid);

                    return [
                        'translation_job' => $uuid,
                        'scope' => $contentId > 0 ? 'content:' . $contentId : 'site',
                        'rows_reset' => $reset,
                        'stuck_jobs_cleared' => $cancelled,
                    ];
                },
            ),

            $this->tool('publish_site', 'Build and publish a site (returns a job id to poll).', [
                'site' => ['type' => 'string'], 'message' => ['type' => 'string'],
            ], 'publish', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $uuid = $this->publish->trigger($site, trim((string) ($args['message'] ?? '')) ?: 'publish: mcp request', 'mcp');

                return ['job' => $uuid];
            }),

            $this->tool('preview_site', 'Build a relative-path preview of a site (no deploy) and return where to view it. Synchronous.', [
                'site' => ['type' => 'string'],
            ], 'publish', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $this->build->buildPreview($site);

                return ['preview' => 'built', 'view' => '/sites/' . $site->handle . '/preview/'];
            }),

            $this->tool('get_build_status', 'Get the status of a job (build/publish/translate) by uuid.', [
                'uuid' => ['type' => 'string'],
            ], 'jobs:read', function (AccessContext $a, array $args): array {
                $row = $this->databases->engine()->fetchAssociative(
                    'SELECT uuid, type, status, attempts, error, site_handle, created_at, finished_at FROM jobs WHERE uuid = ?',
                    [(string) ($args['uuid'] ?? '')],
                );
                // A job belongs to a site — don't leak another site's job
                // status/error to a user who can't access that site. Also
                // return "not found" for unknown ids (no uuid enumeration).
                if ($row === false || ($row['site_handle'] !== null && !$this->canAccess($a, (string) $row['site_handle']))) {
                    throw new McpToolException('Job not found.');
                }
                unset($row['site_handle']);

                return $row;
            }),

            $this->tool('list_jobs', 'List recent jobs for a site.', [
                'site' => ['type' => 'string'], 'limit' => ['type' => 'integer'],
            ], 'jobs:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $rows = $this->databases->engine()->fetchAllAssociative(
                    'SELECT uuid, type, status, created_at FROM jobs WHERE site_handle = ? ORDER BY id DESC LIMIT ?',
                    [$site->handle, min(50, max(1, (int) ($args['limit'] ?? 10)))],
                );

                return ['jobs' => $rows];
            }),

            $this->tool('list_components', 'List a site\'s components (handle/type/status/verbatim/error — get_component returns the full source).', [
                'site' => ['type' => 'string'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $rows = $this->databases->site($site->handle)->fetchAllAssociative('SELECT handle, type, status, verbatim, error FROM components ORDER BY handle');

                return ['components' => array_map(static fn (array $r): array => [
                    'handle' => (string) $r['handle'],
                    'type' => (string) $r['type'],
                    'status' => (string) $r['status'],
                    'verbatim' => (bool) $r['verbatim'],
                    'error' => $r['error'] !== null && $r['error'] !== '' ? (string) $r['error'] : null,
                ], $rows)];
            }),

            $this->tool('backup_site', 'Snapshot a site to backup storage (returns a job to poll).', [
                'site' => ['type' => 'string'], 'kind' => ['type' => 'string', 'enum' => ['full', 'db', 'media', 'shell'], 'default' => 'full'],
            ], 'backup:run', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $kind = (string) ($args['kind'] ?? 'full');
                if (!\in_array($kind, ['full', 'db', 'media', 'shell'], true)) {
                    throw new McpToolException('kind must be full|db|media|shell');
                }
                $uuid = $this->queue->enqueue('backup_site', $site->handle, ['trigger' => 'mcp', 'kind' => $kind], 'low', 'backup:' . $kind . ':' . $site->handle);

                return ['job' => $uuid];
            }),

            $this->tool('list_backups', 'List a site\'s recorded backups (newest first).', [
                'site' => ['type' => 'string'], 'limit' => ['type' => 'integer'],
            ], 'backup:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $rows = array_map(
                    static fn (array $r): array => ['id' => (int) $r['id'], 'kind' => $r['kind'], 'storage_key' => $r['storage_key'], 'size_bytes' => (int) $r['size_bytes'], 'trigger' => $r['trigger_source'], 'created_at' => $r['created_at']],
                    $this->backups->list($site->handle, min(50, max(1, (int) ($args['limit'] ?? 20)))),
                );

                return ['backups' => $rows];
            }),

            // Site-only restore (engine restore is CLI-first disaster recovery,
            // deliberately NOT exposed over MCP — plan §backup/restore).
            $this->tool('restore_backup', 'Restore a site from backup (latest DB unless db_key given), then rebuild+publish. Returns a job to poll.', [
                'site' => ['type' => 'string'], 'db_key' => ['type' => 'string'], 'media' => ['type' => 'boolean'], 'shell' => ['type' => 'boolean'],
            ], 'backup:restore', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $payload = ['trigger' => 'mcp'];
                if (isset($args['db_key']) && (string) $args['db_key'] !== '') {
                    $payload['db_key'] = (string) $args['db_key'];
                }
                if (($args['media'] ?? false) === true) {
                    $payload['media'] = true;
                }
                if (($args['shell'] ?? false) === true) {
                    $payload['shell'] = true;
                }
                $uuid = $this->queue->enqueue('restore_site', $site->handle, $payload, 'high');

                return ['job' => $uuid];
            }),
        ];
    }

    /**
     * The shape every read surface hands back for one media item: what it is,
     * where it LIVES (site-relative and absolute), and how to embed it.
     *
     * A caller used to get an id, a width and a height, and no way at all to
     * reach the file — the public filename carries the item's slug and the
     * variant's width, neither of which is derivable from the id, so the only
     * route to a URL was scraping a rendered page.
     *
     * Takes an id (loads it) or an already-loaded Media. A missing id returns
     * null rather than throwing: a dangling featured_media_id must not turn a
     * readable post into an error.
     *
     * @return array<string, mixed>|null
     */
    private function mediaSummary(Site $site, int|\Fastsite\Media\Dto\Media $media): ?array
    {
        $m = \is_int($media) ? $this->media->findWithVariants($site->handle, $media) : $media;
        if ($m === null) {
            return null;
        }

        $path = SiteLinks::mediaPath($m);
        $embed = match ($m->kind) {
            'image' => '<img data-media-id="' . $m->id . '" alt="' . htmlspecialchars($m->alt) . '">',
            'file' => '{{ ' . $m->originalName . ' }}',
            default => null,
        };

        return [
            'id' => $m->id,
            'kind' => $m->kind,
            'original_name' => $m->originalName,
            'mime' => $m->mime,
            'alt' => $m->alt,
            'width' => $m->width,
            'height' => $m->height,
            'status' => $m->status,
            // null while an upload is still processing, and for video (accepted,
            // but the pipeline generates no renditions for it yet).
            'path' => $path,
            'url' => $path === null ? null : SiteLinks::absolute($site, $path),
            'use_in_body' => $embed,
        ];
    }

    /**
     * One category as every MCP read hands it back — with its live archive
     * url, so nobody rebuilds it from the pattern by hand.
     *
     * @return array<string, mixed>
     */
    private function categorySummary(Site $site, \Fastsite\Category\Dto\Category $c, ?int $published): array
    {
        $path = SiteLinks::categoryPath($site, $c->slug);
        $out = [
            'id' => $c->id,
            'slug' => $c->slug,
            'name' => $c->name,
            'description_html' => $c->descriptionHtml,
            'seo_title' => $c->seoTitle,
            'seo_description' => $c->seoDescription,
            'path' => $path,
            'url' => SiteLinks::absolute($site, $path),
        ];
        if ($published !== null) {
            $out['published_posts'] = $published;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $props
     * @param \Closure(AccessContext, array<string, mixed>): (string|array<string, mixed>) $handler
     */
    private function tool(string $name, string $description, array $props, ?string $scope, \Closure $handler): McpTool
    {
        return new McpTool($name, $description, [
            'type' => 'object',
            'properties' => $props === [] ? new \stdClass() : $props,
        ], $scope, $handler);
    }

    /**
     * @return array<string, mixed>
     */
    private function contentSchema(string $type): array
    {
        $category = $type === 'post'
            ? ['category' => ['type' => 'string', 'description' => self::CATEGORY_FIELD]]
            : [];

        return $category + [
            'site' => ['type' => 'string', 'description' => 'Site handle'],
            'title' => ['type' => 'string'],
            'body_html' => ['type' => 'string', 'description' => 'Body HTML (see get_authoring_guide). Sanitized by default; images should use <img data-media-id="N" alt="..."> from upload_media_from_url.'],
            'sanitize' => ['type' => 'boolean', 'description' => 'Overrides the site-wide sanitize_content setting for THIS write. Set false to store the body VERBATIM (trusted port — keeps inline styles/scripts/iframes and interactive markup; exempt from the zero-JS/PageSpeed audit, and kept verbatim through translation too).'],
            'intro' => ['type' => 'string', 'description' => 'Optional intro/lead HTML fragment. The shell renders it as the styled lead above the body — do NOT repeat it in body_html. Sanitized + auto-translated like the body.'],
            'tldr' => ['type' => 'string', 'description' => 'Optional TL;DR summary HTML fragment (e.g. one <p> or a <ul>). The shell renders it as a styled summary box — do NOT bake a summary box into body_html.'],
            'conclusion' => ['type' => 'string', 'description' => 'Optional conclusion HTML fragment. The shell renders it as a closing section after the body.'],
            'excerpt' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['draft', 'published'], 'default' => 'draft'],
            'slug' => ['type' => 'string', 'description' => 'URL slug. May be a nested path ("help/getting-started") to preserve a ported URL structure.'],
            'seo_title' => ['type' => 'string', 'description' => 'Snippet title — 30–60 characters (Google truncates ~60). Falls back to title when empty.'],
            'seo_description' => ['type' => 'string', 'description' => 'Meta description — 120–156 characters. Falls back to the excerpt when empty.'],
            'featured_media_id' => ['type' => 'integer', 'description' => 'Media id of the page\'s featured/LCP image (from upload_media_*). The build renders it eager + fetchpriority=high — including where it appears in the body — and preloads it in the head. 0 or null clears it; a dangling id is rejected. Without it, the first hero-sized (≥1000px) body image is prioritised automatically.'],
            'noindex' => ['type' => 'boolean', 'default' => false, 'description' => 'Hide from search engines: emits <meta name="robots" content="noindex"> and leaves the page out of sitemap.xml + llms.txt.'],
            'nofollow' => ['type' => 'boolean', 'default' => false, 'description' => 'Ask crawlers not to follow this page\'s links (meta robots nofollow).'],
            'error_status' => ['type' => 'integer', 'description' => 'OPTIONAL. Makes this page the site\'s error page for an HTTP status — one of 401, 403, 404, 410, 429, 451. It then renders to /{status}.html instead of its slug path, is forced noindex, and is kept out of sitemap.xml, llms.txt and every on-site listing. Which codes are actually SERVED depends on the serving mode: all of them on a customer\'s own Apache host, 404 and 410 in direct mode, and 404 ALONE on Cloudflare Pages — the rest are built and published but never returned there. One page per status; null clears it and the page becomes ordinary again.'],
            'json_ld' => ['type' => 'object', 'description' => 'OPTIONAL page-specific schema.org data — a Product, Event, HowTo, Recipe, LocalBusiness. Pass the bare node ({"@type":"Product","name":"…"}), an array of nodes, or a {"@graph":[…]} document; @context is optional. It is MERGED INTO the page graph the engine already emits (Organization/Person + WebSite + WebPage + BreadcrumbList, plus BlogPosting on posts) — do NOT re-declare those, and never put a <script type="application/ld+json"> block in body_html. A node declaring its own Article/BlogPosting/NewsArticle type replaces the engine\'s article node.'],
        ];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function createContent(AccessContext $a, array $args, string $type): array
    {
        $site = $this->site($a, $args);
        $args['type'] = $type;
        $args['source'] = 'mcp';
        $hook = $this->factCheckBeforeStore($site, $args);
        $args = $hook['args'];
        try {
            $c = $this->content->create($site, $args, 'oauth:' . $a->userId);
        } catch (DuplicateContentException | \InvalidArgumentException $e) {
            throw new McpToolException($e->getMessage());
        }

        $out = ['id' => $c->id, 'type' => $c->type, 'slug' => $c->slug, 'status' => $c->status, 'warnings' => $c->warnings];
        if ($hook['fact_check'] !== null) {
            $out['fact_check'] = $hook['fact_check'];
        }

        return $out;
    }

    /**
     * When the site opts into fact-checking, verify + auto-correct the incoming
     * body BEFORE it is stored (contradicted + unverified claims), so the API/MCP
     * path publishes corrected content. Fail-safe: leaves the body unchanged if
     * unconfigured or nothing needs fixing.
     *
     * @param array<string, mixed> $args
     * @return array{args: array<string, mixed>, fact_check: array<string, mixed>|null}
     */
    private function factCheckBeforeStore(Site $site, array $args): array
    {
        if (!$site->factCheckEnabled || trim((string) ($args['body_html'] ?? '')) === '') {
            return ['args' => $args, 'fact_check' => null];
        }
        $res = $this->factCheck->checkAndCorrectBody($site, (string) ($args['title'] ?? ''), (string) $args['body_html']);
        if ($res['changed']) {
            $args['body_html'] = $res['body'];
        }

        return ['args' => $args, 'fact_check' => [
            'changed' => $res['changed'],
            'corrected' => $res['corrected'],
            'report' => $res['report'],
        ]];
    }

    /**
     * @param array<string, mixed> $args
     */
    private function site(AccessContext $a, array $args): Site
    {
        $handle = (string) ($args['site'] ?? '');
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            throw new McpToolException('Unknown site: ' . $handle);
        }
        if (!$this->canAccess($a, $handle)) {
            throw new McpToolException('You do not have access to site: ' . $handle);
        }

        return $site;
    }

    private function canAccess(AccessContext $a, string $handle): bool
    {
        // THE access model (mirrors Dash::scopeToUser): admin → every site;
        // everyone else only as the site's OWNER or via the site's team.
        $role = $this->databases->engine()->fetchOne('SELECT role FROM users WHERE id = ?', [$a->userId]);
        if ($role === 'admin') {
            return true;
        }
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            return false;
        }

        return (bool) $this->databases->engine()->fetchOne(
            "SELECT 1 FROM sites s WHERE s.handle = ? AND (
                s.id IN (SELECT site_id FROM site_users WHERE user_id = ? AND role = 'owner')
                OR (s.team_id IS NOT NULL AND s.team_id IN (SELECT team_id FROM team_members WHERE user_id = ?))
             )",
            [$handle, $a->userId, $a->userId],
        );
    }
}
