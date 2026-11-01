<?php

declare(strict_types=1);

use Fastsite\Kernel\Route;

/**
 * Route table. Each entry: [method, path, handler class, middleware group, scope].
 * Handler classes implement Psr\Http\Server\RequestHandlerInterface.
 *
 * @return list<Route>
 */
return [
    new Route('GET', '/healthz', Fastsite\Http\HealthHandler::class, 'public'),

    // ── Dashboard (session + CSRF; /admin/* additionally role=admin) ──
    new Route('GET', '/login', Fastsite\Dashboard\Handler\LoginPageHandler::class, 'public'),
    new Route('POST', '/login', Fastsite\Dashboard\Handler\LoginActionHandler::class, 'auth'),
    new Route('POST', '/logout', Fastsite\Dashboard\Handler\LogoutHandler::class, 'web'),
    new Route('GET', '/', Fastsite\Dashboard\Handler\OverviewHandler::class, 'web'),
    new Route('POST', '/sites/action', Fastsite\Dashboard\Handler\SitesManageActionHandler::class, 'web'),
    // Workspace select/create/update/delete/move-site — POST only, no
    // query-string variant; selection persists in the fs_ws cookie.
    new Route('GET', '/account/avatar', Fastsite\Dashboard\Handler\AvatarServeHandler::class, 'web'),
    new Route('GET', '/workspaces', Fastsite\Dashboard\Handler\WorkspacesScreenHandler::class, 'web'),
    new Route('POST', '/workspaces/action', Fastsite\Dashboard\Handler\WorkspacesActionHandler::class, 'web'),
    new Route('GET', '/sites/{site}/content', Fastsite\Dashboard\Handler\ContentScreenHandler::class, 'web'),
    new Route('POST', '/sites/{site}/content/action', Fastsite\Dashboard\Handler\ContentActionHandler::class, 'web'),
    new Route('POST', '/sites/{site}/content/seo-fix', Fastsite\Dashboard\Handler\SeoFixDialogHandler::class, 'web'),
    new Route('GET', '/sites/{site}/media', Fastsite\Dashboard\Handler\MediaScreenHandler::class, 'web'),
    new Route('POST', '/sites/{site}/media/action', Fastsite\Dashboard\Handler\MediaActionHandler::class, 'web'),
    new Route('GET', '/sites/{site}/media/{id:\d+}/thumb', Fastsite\Dashboard\Handler\MediaServeHandler::class, 'web'),
    new Route('GET', '/sites/{site}/shell.css', Fastsite\Dashboard\Handler\ShellCssHandler::class, 'web'),
    new Route('GET', '/sites/{site}/card.png', Fastsite\Dashboard\Handler\SiteCardServeHandler::class, 'web'),
    new Route('GET', '/sites/{site}/files', Fastsite\Dashboard\Handler\FilesScreenHandler::class, 'web'),
    new Route('POST', '/sites/{site}/files/action', Fastsite\Dashboard\Handler\FilesActionHandler::class, 'web'),
    new Route('GET', '/sites/{site}/components', Fastsite\Dashboard\Handler\ComponentsScreenHandler::class, 'web'),
    new Route('POST', '/sites/{site}/components/action', Fastsite\Dashboard\Handler\ComponentsActionHandler::class, 'web'),
    new Route('GET', '/sites/{site}/forms', Fastsite\Dashboard\Handler\FormsScreenHandler::class, 'web'),
    new Route('POST', '/sites/{site}/forms/action', Fastsite\Dashboard\Handler\FormsActionHandler::class, 'web'),
    new Route('GET', '/sites/{site}/translations', Fastsite\Dashboard\Handler\TranslationsScreenHandler::class, 'web'),
    new Route('POST', '/sites/{site}/translations/action', Fastsite\Dashboard\Handler\TranslationsActionHandler::class, 'web'),
    new Route('GET', '/sites/{site}/translations/log', Fastsite\Dashboard\Handler\TranslationLogHandler::class, 'web'),
    new Route('GET', '/sites/{site}/audit', Fastsite\Dashboard\Handler\AuditScreenHandler::class, 'web'),
    new Route('GET', '/sites/{site}/settings', Fastsite\Dashboard\Handler\SettingsScreenHandler::class, 'web'),
    new Route('POST', '/sites/{site}/settings/action', Fastsite\Dashboard\Handler\SettingsActionHandler::class, 'web'),
    new Route('GET', '/sites/{site}/webhooks', Fastsite\Dashboard\Handler\WebhooksScreenHandler::class, 'web'),
    new Route('POST', '/sites/{site}/webhooks/action', Fastsite\Dashboard\Handler\WebhooksActionHandler::class, 'web'),
    new Route('GET', '/sites/{site}/preview', Fastsite\Dashboard\Handler\PreviewServeHandler::class, 'web'),
    new Route('GET', '/sites/{site}/preview/{path:.*}', Fastsite\Dashboard\Handler\PreviewServeHandler::class, 'web'),
    new Route('GET', '/account', Fastsite\Dashboard\Handler\AccountScreenHandler::class, 'web'),
    new Route('POST', '/account/action', Fastsite\Dashboard\Handler\AccountActionHandler::class, 'web'),
    new Route('GET', '/teams', Fastsite\Dashboard\Handler\TeamsScreenHandler::class, 'web'),
    new Route('POST', '/teams/action', Fastsite\Dashboard\Handler\TeamsActionHandler::class, 'web'),
    new Route('GET', '/invite/{token}', Fastsite\Dashboard\Handler\InviteAcceptHandler::class, 'web'),
    new Route('GET', '/admin/users', Fastsite\Dashboard\Handler\Admin\UsersScreenHandler::class, 'web_admin'),
    new Route('POST', '/admin/users/action', Fastsite\Dashboard\Handler\Admin\UsersActionHandler::class, 'web_admin'),
    new Route('GET', '/admin/sites', Fastsite\Dashboard\Handler\Admin\SitesScreenHandler::class, 'web_admin'),
    new Route('POST', '/admin/sites/action', Fastsite\Dashboard\Handler\Admin\SitesActionHandler::class, 'web_admin'),
    new Route('GET', '/admin/ops', Fastsite\Dashboard\Handler\Admin\OpsScreenHandler::class, 'web_admin'),
    new Route('POST', '/admin/ops/action', Fastsite\Dashboard\Handler\Admin\OpsActionHandler::class, 'web_admin'),
    new Route('GET', '/admin/access', Fastsite\Dashboard\Handler\Admin\AccessScreenHandler::class, 'web_admin'),
    new Route('POST', '/admin/access/action', Fastsite\Dashboard\Handler\Admin\AccessActionHandler::class, 'web_admin'),

    // ── REST v1 (X-API-Key auth, ApiResponse envelope) ────────────────
    new Route('GET', '/api/v1/build', Fastsite\Api\V1\BuildInfoHandler::class, 'api', 'sites:read'),
    new Route('GET', '/api/v1/authoring-guide', Fastsite\Api\V1\AuthoringGuideHandler::class, 'api', 'sites:read'),
    new Route('GET', '/api/v1/sites/{site}/authoring-guide', Fastsite\Api\V1\AuthoringGuideHandler::class, 'api', 'sites:read'),
    new Route('GET', '/api/v1/sites/{site}/audit', Fastsite\Api\V1\AuditHandler::class, 'api', 'sites:read'),
    new Route('GET', '/api/v1/sites', Fastsite\Api\V1\SitesListHandler::class, 'api', 'sites:read'),
    new Route('GET', '/api/v1/workspaces', Fastsite\Api\V1\WorkspacesListHandler::class, 'api', 'sites:read'),
    new Route('POST', '/api/v1/workspaces', Fastsite\Api\V1\WorkspaceCreateHandler::class, 'api', 'sites:write'),
    new Route('POST', '/api/v1/sites', Fastsite\Api\V1\SiteCreateHandler::class, 'api', 'admin'),
    new Route('PATCH', '/api/v1/sites/{site}', Fastsite\Api\V1\SiteUpdateHandler::class, 'api', 'sites:write'),
    new Route('GET', '/api/v1/sites/{site}/content', Fastsite\Api\V1\ContentListHandler::class, 'api', 'content:read'),
    new Route('POST', '/api/v1/sites/{site}/content', Fastsite\Api\V1\ContentCreateHandler::class, 'api', 'content:write'),
    new Route('GET', '/api/v1/sites/{site}/content/trash', Fastsite\Api\V1\ContentTrashHandler::class, 'api', 'content:read'),
    new Route('GET', '/api/v1/sites/{site}/content/{id:\d+}', Fastsite\Api\V1\ContentGetHandler::class, 'api', 'content:read'),
    new Route('PATCH', '/api/v1/sites/{site}/content/{id:\d+}', Fastsite\Api\V1\ContentUpdateHandler::class, 'api', 'content:write'),
    new Route('DELETE', '/api/v1/sites/{site}/content/{id:\d+}', Fastsite\Api\V1\ContentDeleteHandler::class, 'api', 'content:write'),
    new Route('POST', '/api/v1/sites/{site}/content/{id:\d+}/restore', Fastsite\Api\V1\ContentRestoreHandler::class, 'api', 'content:write'),
    new Route('DELETE', '/api/v1/sites/{site}/content/{id:\d+}/purge', Fastsite\Api\V1\ContentPurgeHandler::class, 'api', 'content:write'),
    new Route('POST', '/api/v1/sites/{site}/content/{id:\d+}/fact-check', Fastsite\Api\V1\FactCheckHandler::class, 'api', 'content:read'),
    new Route('GET', '/api/v1/sites/{site}/categories', Fastsite\Api\V1\CategoriesHandler::class, 'api', 'content:read'),
    new Route('GET', '/api/v1/sites/{site}/categories/{slug}', Fastsite\Api\V1\CategoriesHandler::class, 'api', 'content:read'),
    new Route('PUT', '/api/v1/sites/{site}/categories/{slug}', Fastsite\Api\V1\CategoriesHandler::class, 'api', 'content:write'),
    new Route('DELETE', '/api/v1/sites/{site}/categories/{slug}', Fastsite\Api\V1\CategoriesHandler::class, 'api', 'content:write'),
    new Route('POST', '/api/v1/sites/{site}/research', Fastsite\Api\V1\ResearchHandler::class, 'api', 'content:read'),
    new Route('GET', '/api/v1/sites/{site}/media', Fastsite\Api\V1\MediaListHandler::class, 'api', 'media:read'),
    new Route('POST', '/api/v1/sites/{site}/media', Fastsite\Api\V1\MediaUploadHandler::class, 'api_upload', 'media:write'),
    new Route('POST', '/api/v1/sites/{site}/media/{id:\d+}/replace', Fastsite\Api\V1\MediaReplaceHandler::class, 'api_upload', 'media:write'),
    new Route('POST', '/api/v1/sites/{site}/media/{id:\d+}/crop', Fastsite\Api\V1\MediaCropHandler::class, 'api', 'media:write'),
    new Route('DELETE', '/api/v1/sites/{site}/media/{id:\d+}', Fastsite\Api\V1\MediaDeleteHandler::class, 'api', 'media:write'),
    new Route('POST', '/api/v1/sites/{site}/media/{id:\d+}/restore', Fastsite\Api\V1\MediaRestoreHandler::class, 'api', 'media:write'),
    new Route('DELETE', '/api/v1/sites/{site}/media/{id:\d+}/purge', Fastsite\Api\V1\MediaPurgeHandler::class, 'api', 'media:write'),
    new Route('POST', '/api/v1/sites/{site}/upload-tickets', Fastsite\Api\V1\UploadTicketHandler::class, 'api', 'media:write'),

    // ── Components (html/react → static) ──────────────────────────────
    new Route('GET', '/api/v1/sites/{site}/components', Fastsite\Api\V1\ComponentsHandler::class, 'api', 'sites:read'),
    new Route('GET', '/api/v1/sites/{site}/components/{handle}', Fastsite\Api\V1\ComponentsHandler::class, 'api', 'sites:read'),
    new Route('PUT', '/api/v1/sites/{site}/components/{handle}', Fastsite\Api\V1\ComponentUpsertHandler::class, 'api', 'components:write'),
    new Route('DELETE', '/api/v1/sites/{site}/components/{handle}', Fastsite\Api\V1\ComponentsHandler::class, 'api', 'components:write'),
    // Component hooks: build-time injection rules (CTA into targeted pages).
    new Route('GET', '/api/v1/sites/{site}/component-hooks', Fastsite\Api\V1\ComponentHooksHandler::class, 'api', 'sites:read'),
    // Forms. The submissions route is declared BEFORE the {handle} one so the
    // literal segment wins the match rather than being read as a form handle.
    new Route('GET', '/api/v1/sites/{site}/forms', Fastsite\Api\V1\FormsHandler::class, 'api', 'forms:read'),
    new Route('GET', '/api/v1/sites/{site}/forms/{handle}/submissions', Fastsite\Api\V1\FormsHandler::class, 'api', 'forms:read'),
    // Submission recovery — retry failed sends, release a false positive out
    // of the spam folder, rescore, empty the folder. Declared BEFORE the
    // /forms/{handle} routes: fast-route would otherwise match "submissions"
    // as a form handle and 404 on a form nobody ever created.
    new Route('POST', '/api/v1/sites/{site}/forms/submissions/{op}', Fastsite\Api\V1\FormSubmissionsActionHandler::class, 'api', 'forms:write'),
    new Route('GET', '/api/v1/sites/{site}/forms/{handle}', Fastsite\Api\V1\FormsHandler::class, 'api', 'forms:read'),
    new Route('PUT', '/api/v1/sites/{site}/forms/{handle}', Fastsite\Api\V1\FormsHandler::class, 'api', 'forms:write'),
    new Route('DELETE', '/api/v1/sites/{site}/forms/{handle}', Fastsite\Api\V1\FormsHandler::class, 'api', 'forms:write'),

    new Route('POST', '/api/v1/sites/{site}/component-hooks', Fastsite\Api\V1\ComponentHooksHandler::class, 'api', 'components:write'),
    new Route('DELETE', '/api/v1/sites/{site}/component-hooks/{id:\d+}', Fastsite\Api\V1\ComponentHooksHandler::class, 'api', 'components:write'),

    // ── Shell files (how agents design a site) ────────────────────────
    new Route('GET', '/api/v1/sites/{site}/shell/files', Fastsite\Api\V1\Shell\ShellListHandler::class, 'api', 'shell:read'),
    new Route('POST', '/api/v1/sites/{site}/shell/snapshot', Fastsite\Api\V1\Shell\ShellSnapshotHandler::class, 'api', 'shell:write'),
    new Route('POST', '/api/v1/sites/{site}/shell/restore/{rev:\d+}', Fastsite\Api\V1\Shell\ShellRestoreHandler::class, 'api', 'shell:write'),
    new Route('GET', '/api/v1/sites/{site}/shell/file/{path:.+}', Fastsite\Api\V1\Shell\ShellGetHandler::class, 'api', 'shell:read'),
    new Route('PUT', '/api/v1/sites/{site}/shell/file/{path:.+}', Fastsite\Api\V1\Shell\ShellPutHandler::class, 'api_upload', 'shell:write'),
    new Route('DELETE', '/api/v1/sites/{site}/shell/file/{path:.+}', Fastsite\Api\V1\Shell\ShellDeleteHandler::class, 'api', 'shell:write'),
    new Route('GET', '/api/v1/sites/{site}/redirects', Fastsite\Api\V1\RedirectsHandler::class, 'api', 'sites:read'),
    new Route('POST', '/api/v1/sites/{site}/redirects', Fastsite\Api\V1\RedirectsHandler::class, 'api', 'sites:write'),
    new Route('DELETE', '/api/v1/sites/{site}/redirects/{id:\d+}', Fastsite\Api\V1\RedirectsHandler::class, 'api', 'sites:write'),
    new Route('GET', '/api/v1/sites/{site}/scripts', Fastsite\Api\V1\ScriptsHandler::class, 'api', 'sites:read'),
    new Route('POST', '/api/v1/sites/{site}/scripts', Fastsite\Api\V1\ScriptsHandler::class, 'api', 'sites:write'),
    new Route('DELETE', '/api/v1/sites/{site}/scripts/{id:\d+}', Fastsite\Api\V1\ScriptsHandler::class, 'api', 'sites:write'),
    new Route('POST', '/api/v1/sites/{site}/languages', Fastsite\Api\V1\LanguagesHandler::class, 'api', 'translations:manage'),
    new Route('DELETE', '/api/v1/sites/{site}/languages/{locale}', Fastsite\Api\V1\LanguagesHandler::class, 'api', 'translations:manage'),
    new Route('GET', '/api/v1/sites/{site}/webhooks', Fastsite\Api\V1\WebhooksApiHandler::class, 'api', 'sites:read'),
    new Route('POST', '/api/v1/sites/{site}/webhooks', Fastsite\Api\V1\WebhooksApiHandler::class, 'api', 'sites:write'),
    new Route('GET', '/api/v1/sites/{site}/webhooks/{id:\d+}/deliveries', Fastsite\Api\V1\WebhooksApiHandler::class, 'api', 'sites:read'),
    new Route('DELETE', '/api/v1/sites/{site}/webhooks/{id:\d+}', Fastsite\Api\V1\WebhooksApiHandler::class, 'api', 'sites:write'),
    new Route('POST', '/api/v1/sites/{site}/publish', Fastsite\Api\V1\PublishHandler::class, 'api', 'publish'),
    new Route('GET', '/api/v1/jobs/{uuid}', Fastsite\Api\V1\JobGetHandler::class, 'api', 'jobs:read'),

    // ── Inbound webhooks (HMAC-authenticated, no API key) ─────────────
    // GET/HEAD = the unauthenticated liveness ack sender-side endpoint
    // checkers probe with (AutoSEO verifies with a GET and expects 200).
    new Route('POST', '/webhooks/{integration}', Fastsite\Webhook\WebhookHandler::class, 'webhook'),
    new Route('GET', '/webhooks/{integration}', Fastsite\Webhook\WebhookHandler::class, 'webhook'),
    new Route('HEAD', '/webhooks/{integration}', Fastsite\Webhook\WebhookHandler::class, 'webhook'),

    // ── 404 beacon (unauthenticated, rate-limited; #15C) ──────────────
    new Route('POST', '/collect/{site}/404', Fastsite\Api\V1\Collect404Handler::class, 'webhook'),

    // ── Published sites' contact forms ────────────────────────────────
    // The one dynamic endpoint a static site has. A native <form> on the
    // published page posts here cross-origin: no CORS needed (a normal form
    // submission is not preflighted) and no CSP change (the build emits no
    // form-action directive, which has no default-src fallback), so the
    // published page still ships zero JavaScript. Short path because it is
    // baked into every rendered form's action attribute.
    new Route('POST', '/f/{site}/{form}', Fastsite\Form\Handler\FormSubmitHandler::class, 'form'),

    // ── Upload tickets ────────────────────────────────────────────────────
    // PUT /u/{token} redeems a single-use ticket with the raw bytes of one
    // file. The ticket carries the site, the actor and the scope it was minted
    // under, so there is no auth middleware here and there must not be: the
    // body is deliberately just bytes. Short path because the whole point is
    // that it fits in a one-line curl an agent can be told to run. Lives under
    // `location /` in nginx, so it inherits the 512m server cap rather than
    // the JSON API's.
    new Route('PUT', '/u/{token}', Fastsite\Media\Handler\TicketUploadHandler::class, 'upload'),
    new Route('POST', '/u/{token}', Fastsite\Media\Handler\TicketUploadHandler::class, 'upload'),

    // ── OAuth 2.1 authorization server ────────────────────────────────
    new Route('GET', '/.well-known/oauth-authorization-server', Fastsite\Access\OAuth\Handler\MetadataHandler::class, 'public'),
    new Route('GET', '/.well-known/oauth-protected-resource', Fastsite\Access\OAuth\Handler\MetadataHandler::class, 'public'),
    new Route('POST', '/oauth/register', Fastsite\Access\OAuth\Handler\RegisterHandler::class, 'auth'),
    new Route('GET', '/oauth/authorize', Fastsite\Access\OAuth\Handler\AuthorizeHandler::class, 'public'),
    new Route('POST', '/oauth/authorize', Fastsite\Access\OAuth\Handler\ConsentHandler::class, 'auth'),
    new Route('POST', '/oauth/login', Fastsite\Access\OAuth\Handler\LoginHandler::class, 'auth'),
    new Route('POST', '/oauth/token', Fastsite\Access\OAuth\Handler\TokenHandler::class, 'auth'),
    new Route('POST', '/oauth/revoke', Fastsite\Access\OAuth\Handler\RevokeHandler::class, 'auth'),

    // ── MCP server (OAuth bearer) ─────────────────────────────────────
    new Route('POST', '/mcp', Fastsite\Mcp\McpHandler::class, 'mcp'),
];
