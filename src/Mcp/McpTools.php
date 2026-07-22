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
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteService $siteService,
        private readonly \Fastsite\Site\Service\MarketingSeeder $marketingSeeder,
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
        private readonly ShellService $shell,
        private readonly PublishTrigger $publish,
        private readonly BuildService $build,
        private readonly JobQueue $queue,
        private readonly SiteDatabaseManager $databases,
        private readonly BackupService $backups,
        private readonly AuthoringGuideService $authoring,
        private readonly \Fastsite\FactCheck\FactCheckService $factCheck,
        private readonly \Fastsite\Research\ResearchService $research,
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
                    static fn (Site $s): array => ['handle' => $s->handle, 'name' => $s->name, 'base_url' => $s->baseUrl, 'primary_locale' => $s->primaryLocale, 'locales' => $s->locales, 'status' => $s->status],
                    array_filter($this->sites->all(), fn (Site $s): bool => $this->canAccess($a, $s->handle)),
                )]),

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

            $this->tool('create_site', 'Create a new site (admin). Provisions its database, scaffolds the default shell, and registers it. The handle is immutable — it is the identity on every transport and the on-disk path.', [
                'handle' => ['type' => 'string', 'description' => 'Lowercase handle, e.g. "blog". Immutable identity + path.'],
                'name' => ['type' => 'string'],
                'base_url' => ['type' => 'string', 'description' => 'https://apex.example.com — a leading www. is stripped to the apex.'],
                'github_repo' => ['type' => 'string', 'description' => 'Optional dist repo, e.g. git@github.com:you/site-dist.git.'],
                'primary_locale' => ['type' => 'string', 'default' => 'en'],
            ], 'admin', function (AccessContext $a, array $args): array {
                try {
                    $site = $this->siteService->create(
                        (string) ($args['handle'] ?? ''),
                        (string) ($args['name'] ?? ($args['handle'] ?? '')),
                        (string) ($args['base_url'] ?? ''),
                        (string) ($args['github_repo'] ?? ''),
                        (string) ($args['primary_locale'] ?? 'en'),
                    );
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['handle' => $site->handle, 'name' => $site->name, 'base_url' => $site->baseUrl, 'primary_locale' => $site->primaryLocale, 'status' => $site->status];
            }),

            $this->tool('seed_getfastsite', 'Stand up getfastsite.com — the fastsite marketing site — inside the engine (admin, dogfood). Creates the site if missing, installs the marketing theme, authors the marketing pages + launch post, and builds. Idempotent. Then set its github_repo + a GitHub credential and publish.', [], 'admin', function (AccessContext $a, array $args): array {
                try {
                    $log = $this->marketingSeeder->seed(true);
                } catch (\Throwable $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['log' => $log];
            }),

            $this->tool('update_site', 'Update site settings (name, base_url, github repo/branch, media_public_base, status, favicon/logo media ids). Only provided keys change; republishes when the built output is affected.', [
                'site' => ['type' => 'string'],
                'name' => ['type' => 'string'], 'base_url' => ['type' => 'string'],
                'github_repo' => ['type' => 'string'], 'github_branch' => ['type' => 'string'],
                'media_public_base' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['active', 'paused', 'archived']],
                'favicon_media_id' => ['type' => 'integer'], 'logo_header_media_id' => ['type' => 'integer'], 'logo_footer_media_id' => ['type' => 'integer'],
                'social_image_media_id' => ['type' => 'integer', 'description' => 'Media id used as the default og:image / twitter:image share card.'],
                'meta_description' => ['type' => 'string', 'description' => 'Fallback meta description for the home page, blog index and 404.'],
                'canonical_host' => ['type' => 'string', 'enum' => ['apex', 'www']], 'github_credential_id' => ['type' => 'integer'],
                'serve_mode' => ['type' => 'string', 'enum' => ['git', 'direct'], 'description' => 'How the site goes live: "git" pushes the build to the repo a CDN watches; "direct" serves it straight from this engine by domain.'],
                'shortpixel_key' => ['type' => 'string', 'description' => 'Per-site ShortPixel API key (stored encrypted). "__clear__" removes it.'],
                'fact_check_enabled' => ['type' => 'boolean'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $before = $this->site($a, $args);
                $fields = array_intersect_key($args, array_flip(['name', 'base_url', 'github_repo', 'github_branch', 'media_public_base', 'status', 'favicon_media_id', 'logo_header_media_id', 'logo_footer_media_id', 'social_image_media_id', 'meta_description', 'canonical_host', 'github_credential_id', 'team_id', 'shortpixel_key', 'fact_check_enabled', 'serve_mode']));
                try {
                    $after = $this->siteService->update($before->handle, $fields);
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }
                if (SiteService::changeNeedsRebuild($before, $after)) {
                    $this->publish->trigger($after, 'publish: site settings changed (mcp)', 'mcp');
                }

                return ['handle' => $after->handle, 'name' => $after->name, 'base_url' => $after->baseUrl, 'status' => $after->status, 'favicon_media_id' => $after->faviconMediaId, 'logo_header_media_id' => $after->logoHeaderMediaId, 'logo_footer_media_id' => $after->logoFooterMediaId];
            }),

            $this->tool('list_scripts', 'List a site\'s header/footer scripts (#13).', [
                'site' => ['type' => 'string'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);

                return ['scripts' => array_map(static fn ($s): array => ['id' => $s->id, 'label' => $s->label, 'location' => $s->location, 'strategy' => $s->strategy, 'priority' => $s->priority, 'enabled' => $s->enabled], $this->siteScripts->list($site->handle))];
            }),

            $this->tool('upsert_script', 'Create/update a header/footer script. WARNING: any enabled script opts the site out of the 100 PageSpeed guarantee. Pass an id to update. Republishes.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'], 'label' => ['type' => 'string'],
                'location' => ['type' => 'string', 'enum' => ['head', 'body_open', 'body_end']],
                'strategy' => ['type' => 'string', 'enum' => ['blocking', 'async', 'defer']],
                'priority' => ['type' => 'integer'], 'code' => ['type' => 'string'], 'enabled' => ['type' => 'boolean'],
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

                return ['id' => $r['id'], 'endpoint' => '/webhooks/' . $r['slug'], 'secret' => $r['secret']];
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

            $this->tool('list_redirects', 'List a site\'s redirects and captured 404s (#15).', [
                'site' => ['type' => 'string'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);

                return ['redirects' => $this->redirects->list($site->handle), 'missing_paths' => $this->redirects->listMissing($site->handle)];
            }),

            $this->tool('upsert_redirect', 'Create/update a redirect (from_path → to_path). Republishes.', [
                'site' => ['type' => 'string'], 'from_path' => ['type' => 'string'], 'to_path' => ['type' => 'string'], 'status' => ['type' => 'integer'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $this->redirects->save($site->handle, (string) ($args['from_path'] ?? ''), (string) ($args['to_path'] ?? ''), (int) ($args['status'] ?? 301));
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }
                $this->publish->trigger($site, 'publish: redirect changed (mcp)', 'mcp');

                return ['from_path' => (string) ($args['from_path'] ?? ''), 'to_path' => (string) ($args['to_path'] ?? ''), 'saved' => true];
            }),

            $this->tool('delete_redirect', 'Delete a redirect by id. Republishes.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'sites:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $this->redirects->delete($site->handle, (int) ($args['id'] ?? 0));
                $this->publish->trigger($site, 'publish: redirect removed (mcp)', 'mcp');

                return ['id' => (int) ($args['id'] ?? 0), 'deleted' => true];
            }),

            $this->tool('list_posts', 'List posts (or pages) on a site.', [
                'site' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['post', 'page']],
                'status' => ['type' => 'string'], 'page' => ['type' => 'integer'], 'per_page' => ['type' => 'integer'],
            ], 'content:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $r = $this->contentRepo->listFiltered($site->handle, $args['type'] ?? null, $args['status'] ?? null, max(1, (int) ($args['page'] ?? 1)), min(100, max(1, (int) ($args['per_page'] ?? 20))));

                return ['total' => $r['total'], 'items' => array_map(static fn ($c): array => ['id' => $c->id, 'type' => $c->type, 'slug' => $c->slug, 'title' => $c->title, 'status' => $c->status], $r['items'])];
            }),

            $this->tool('get_post', 'Get one post/page by id (includes body HTML).', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'],
            ], 'content:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $c = $this->contentRepo->findById($site->handle, (int) ($args['id'] ?? 0)) ?? throw new McpToolException('Content not found.');

                return ['id' => $c->id, 'type' => $c->type, 'slug' => $c->slug, 'status' => $c->status, 'title' => $c->title, 'excerpt' => $c->excerpt, 'body_html' => $c->bodyHtml, 'warnings' => $c->warnings];
            }),

            $this->tool('create_post', 'Create a post. Call get_authoring_guide first. Body is HTML; alt is required on any image.', $this->contentSchema('post'), 'content:write',
                fn (AccessContext $a, array $args): array => $this->createContent($a, $args, 'post')),

            $this->tool('create_page', 'Create a page. Call get_authoring_guide first. Body is HTML.', $this->contentSchema('page'), 'content:write',
                fn (AccessContext $a, array $args): array => $this->createContent($a, $args, 'page')),

            $this->tool('update_post', 'Update a post/page by id. Only provided fields change.', [
                'site' => ['type' => 'string'], 'id' => ['type' => 'integer'], 'title' => ['type' => 'string'],
                'body_html' => ['type' => 'string'], 'excerpt' => ['type' => 'string'], 'status' => ['type' => 'string'],
                'slug' => ['type' => 'string'], 'seo_title' => ['type' => 'string'], 'seo_description' => ['type' => 'string'],
                'sanitize' => ['type' => 'boolean', 'description' => 'Set false to store the body VERBATIM (trusted port — keeps inline styles/scripts/iframes; exempt from the zero-JS/PageSpeed audit). Only applies when body_html is provided.'],
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

            $this->tool('delete_content', 'Soft-delete a post/page (moves to trash — reversible). The next build removes its page.', [
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

            $this->tool('purge_content', 'Permanently delete a post/page and its revisions. Irreversible.', [
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

            $this->tool('list_media', 'List a site\'s media library (set trashed=true for the trash).', [
                'site' => ['type' => 'string'], 'page' => ['type' => 'integer'], 'trashed' => ['type' => 'boolean'],
            ], 'media:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $page = max(1, (int) ($args['page'] ?? 1));
                $r = ($args['trashed'] ?? false)
                    ? $this->media->listTrashed($site->handle, $page, 20)
                    : $this->media->list($site->handle, $page, 20);

                return ['total' => $r['total'], 'items' => array_map(static fn ($m): array => ['id' => $m->id, 'kind' => $m->kind, 'alt' => $m->alt, 'width' => $m->width, 'height' => $m->height, 'status' => $m->status, 'deleted_at' => $m->deletedAt], $r['items'])];
            }),

            $this->tool('upload_media_from_url', 'Import an image from a URL (SSRF-guarded). See get_authoring_guide for recommended image sizes per role (logo/featured/in-content). alt is required for images.', [
                'site' => ['type' => 'string'], 'url' => ['type' => 'string'], 'alt' => ['type' => 'string'], 'decorative' => ['type' => 'boolean'],
            ], 'media:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $m = $this->media->importFromUrl($site, (string) ($args['url'] ?? ''), (string) ($args['alt'] ?? ''), (bool) ($args['decorative'] ?? false));
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['id' => $m->id, 'kind' => $m->kind, 'width' => $m->width, 'height' => $m->height, 'status' => $m->status, 'use_in_body' => '<img data-media-id="' . $m->id . '" alt="' . htmlspecialchars($m->alt) . '">'];
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

            $this->tool('upsert_component', 'Create/update a component (html or react). React compiles at upload.', [
                'site' => ['type' => 'string'], 'handle' => ['type' => 'string'], 'type' => ['type' => 'string', 'enum' => ['html', 'react']],
                'source_code' => ['type' => 'string'], 'css' => ['type' => 'string'],
            ], 'components:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $c = $this->components->upsert($site, (string) ($args['handle'] ?? ''), $args);
                } catch (\InvalidArgumentException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['handle' => $c->handle, 'type' => $c->type, 'status' => $c->status, 'warnings' => $c->warnings, 'use_in_body' => '<div data-fs-component="' . $c->handle . '" data-values="{}"></div>'];
            }),

            $this->tool('delete_component', 'Delete a component (removes its row, cached renders, and compiled bundle).', [
                'site' => ['type' => 'string'], 'handle' => ['type' => 'string'],
            ], 'components:write', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                try {
                    $this->components->delete($site, (string) ($args['handle'] ?? ''));
                } catch (\InvalidArgumentException | \RuntimeException $e) {
                    throw new McpToolException($e->getMessage());
                }

                return ['handle' => (string) ($args['handle'] ?? ''), 'deleted' => true];
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
                $uuid = $this->queue->enqueue('translate_content', $site->handle, ['trigger' => 'mcp'], 'default', 'translate:' . $site->handle);

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

            $this->tool('translate_site', 'Refresh translations for all locales, then publish.', [
                'site' => ['type' => 'string'],
            ], 'translations:manage', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $uuid = $this->queue->enqueue('translate_content', $site->handle, ['trigger' => 'mcp'], 'default', 'translate:' . $site->handle);

                return ['translation_job' => $uuid];
            }),

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

            $this->tool('list_components', 'List a site\'s components.', [
                'site' => ['type' => 'string'],
            ], 'sites:read', function (AccessContext $a, array $args): array {
                $site = $this->site($a, $args);
                $rows = $this->databases->site($site->handle)->fetchAllAssociative('SELECT handle, type, status FROM components ORDER BY handle');

                return ['components' => $rows];
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
        return [
            'site' => ['type' => 'string', 'description' => 'Site handle'],
            'title' => ['type' => 'string'],
            'body_html' => ['type' => 'string', 'description' => 'Body HTML (see get_authoring_guide). Sanitized by default; images should use <img data-media-id="N" alt="..."> from upload_media_from_url.'],
            'sanitize' => ['type' => 'boolean', 'default' => true, 'description' => 'Set false to store the body VERBATIM (trusted port — keeps inline styles/scripts/iframes; exempt from the zero-JS/PageSpeed audit).'],
            'excerpt' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['draft', 'published'], 'default' => 'draft'],
            'slug' => ['type' => 'string', 'description' => 'URL slug. May be a nested path ("help/getting-started") to preserve a ported URL structure.'],
            'seo_title' => ['type' => 'string'],
            'seo_description' => ['type' => 'string'],
            'featured_media_id' => ['type' => 'integer'],
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
        // Admin users reach every site; others only their site_users rows.
        $role = $this->databases->engine()->fetchOne('SELECT role FROM users WHERE id = ?', [$a->userId]);
        if ($role === 'admin') {
            return true;
        }
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            return false;
        }

        // Direct site membership OR membership of the site's team (#2).
        return (bool) $this->databases->engine()->fetchOne(
            'SELECT 1 FROM sites s WHERE s.handle = ? AND (
                s.id IN (SELECT site_id FROM site_users WHERE user_id = ?)
                OR (s.team_id IS NOT NULL AND s.team_id IN (SELECT team_id FROM team_members WHERE user_id = ?))
             )',
            [$handle, $a->userId, $a->userId],
        );
    }
}
