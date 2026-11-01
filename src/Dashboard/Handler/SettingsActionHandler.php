<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Dashboard\Dash;
use Fastsite\Publish\Service\CloudflarePagesService;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shell\Service\ShellService;
use Fastsite\Site\Service\RedirectService;
use Fastsite\Site\Service\SiteScriptService;
use Fastsite\Site\Service\SiteService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/{site}/settings/action — site-save, shell-save,
 * shell-snapshot, shell-restore, Cloudflare go-live. Shell writes go through
 * ShellService (all its guards + coalesced rebuild apply unchanged).
 * Language/translation actions live on the Translations tab
 * (TranslationsActionHandler).
 */
final class SettingsActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly ShellService $shell,
        private readonly SiteService $sites,
        private readonly PublishTrigger $publish,
        private readonly SiteScriptService $scripts,
        private readonly RedirectService $redirects,
        private readonly CloudflarePagesService $cloudflare,
        private readonly \Fastsite\Publish\Service\FtpPublisher $ftp,
        private readonly TokenCipher $cipher,
        private readonly \Fastsite\Team\Service\TeamService $teams,
        private readonly \Fastsite\Workspace\Service\WorkspaceService $workspaces,
        private readonly \Fastsite\Form\Service\FormMailer $mailer,
        private readonly \Fastsite\Content\Service\ContentService $content,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $user = $this->dash->user($request);
        $actor = 'user:' . $user['id'];
        // Every settings form posts the tab it was rendered on, so a save
        // returns you to it. Without this each save would land on General,
        // which is worse than the single long page the tabs replaced.
        $tab = (string) ($body['tab'] ?? '');
        $base = '/sites/' . $site->handle . '/settings'
            . (\in_array($tab, SettingsScreenHandler::TABS, true) ? '?tab=' . $tab : '');

        // A bulk action on the Suggested-redirects list returns you to the SAME
        // filtered view. Landing back on the unfiltered list after dismissing
        // forty /media/ paths means finding the rest of them by hand again,
        // which defeats the point of filtering in the first place.
        $missingView = $base;
        $keepQ = trim((string) ($body['q'] ?? ''));
        $keepSort = (string) ($body['sort'] ?? '');
        if ($keepQ !== '') {
            $missingView .= (str_contains($missingView, '?') ? '&' : '?') . 'q=' . rawurlencode($keepQ);
        }
        if ($keepSort !== '' && $keepSort !== 'hits' && \in_array($keepSort, RedirectService::MISSING_SORTS, true)) {
            $missingView .= (str_contains($missingView, '?') ? '&' : '?') . 'sort=' . $keepSort;
        }

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'site-save':
                    // Repo / base URL decide WHERE content gets published, so
                    // these need admin or the site's owner (a plain site
                    // editor controls the shell, but must not redirect the
                    // publish target).
                    if (!$this->dash->canManageSite($user, $site)) {
                        return $this->dash->redirect($base, '', 'Only administrators or the site owner can change site settings.');
                    }
                    // A non-admin can only attach the site to a team they
                    // belong to (the select only offers those, but verify).
                    $teamId = (int) ($body['team_id'] ?? 0);
                    if ($teamId > 0 && ($user['role'] ?? '') !== 'admin' && !$this->teams->isMember($teamId, (int) $user['id'])) {
                        return $this->dash->redirect($base, '', 'You can only attach the site to a team you belong to.');
                    }
                    // Moving the site into a workspace requires access to the
                    // TARGET workspace — a hard 403, same as REST/MCP.
                    $workspaceId = (int) ($body['workspace_id'] ?? 0);
                    if ($workspaceId > 0 && $workspaceId !== ($site->workspaceId ?? 0) && !$this->workspaces->canAccess((int) $user['id'], $workspaceId)) {
                        return $this->dash->forbidden('You do not have access to that workspace.');
                    }
                    $updated = $this->sites->update($site->handle, [
                        'name' => (string) ($body['name'] ?? $site->name),
                        'base_url' => (string) ($body['base_url'] ?? $site->baseUrl),
                        'github_repo' => (string) ($body['github_repo'] ?? $site->githubRepo),
                        'github_branch' => (string) ($body['github_branch'] ?? $site->githubBranch),
                        'media_public_base' => (string) ($body['media_public_base'] ?? $site->mediaPublicBase),
                        'status' => (string) ($body['status'] ?? $site->status),
                        'favicon_media_id' => $body['favicon_media_id'] ?? null,
                        'logo_header_media_id' => $body['logo_header_media_id'] ?? null,
                        'logo_footer_media_id' => $body['logo_footer_media_id'] ?? null,
                        'social_image_media_id' => $body['social_image_media_id'] ?? null,
                        'canonical_host' => (string) ($body['canonical_host'] ?? $site->canonicalHost),
                        'github_credential_id' => $body['github_credential_id'] ?? null,
                        'ai_credential_id' => $body['ai_credential_id'] ?? null,
                        'team_id' => $body['team_id'] ?? null,
                        'workspace_id' => $body['workspace_id'] ?? null,
                        'serve_mode' => (string) ($body['serve_mode'] ?? $site->serveMode),
                        // Blank = leave the saved key unchanged; the clear box wipes it.
                        'shortpixel_key' => ($body['shortpixel_clear'] ?? '') !== '' ? '__clear__' : (string) ($body['shortpixel_key'] ?? ''),
                        'image_compression' => (string) ($body['image_compression'] ?? $site->imageCompression),
                        'fact_check_enabled' => $body['fact_check_enabled'] ?? '0',
                        // Unchecked checkboxes are absent from the POST — an
                        // explicit '0' is what turns sanitization OFF, so the
                        // trusted-port choice is never made by omission.
                        'sanitize_content' => $body['sanitize_content'] ?? '0',
                        'image_max_width' => $body['image_max_width'] ?? $site->imageMaxWidth,
                        'logo_max_width' => $body['logo_max_width'] ?? $site->logoMaxWidth,
                    ]);

                    $note = 'Site settings saved.';
                    if ($updated->githubRepo !== $site->githubRepo) {
                        $note .= ' Repo changed — the local clone was reset, so the next publish targets the new remote.';
                    }
                    // Canonical/OG/sitemap/llms.txt and the robots banner bake
                    // in the base URL + name, so republish when those change.
                    if (SiteService::changeNeedsRebuild($site, $updated)) {
                        $this->publish->trigger($updated, 'publish: site settings changed', 'dashboard');
                        // serve_mode is part of the output signature (an ftp
                        // site additionally ships a generated .htaccess), so a
                        // mode flip arrives here rather than in a branch of its
                        // own. The operator still needs to be told what the new
                        // mode actually does.
                        $note .= $updated->serveMode !== $site->serveMode
                            ? match ($updated->serveMode) {
                                'direct' => ' Now serving directly from this server — rebuild queued.',
                                'ftp' => ' Now publishing to your own web host over ' . strtoupper($updated->ftpProtocol) . ' — rebuild + upload queued.',
                                default => ' Back to Git → CDN — rebuild + push queued.',
                            }
                            : ' Rebuild + publish queued (canonical URLs changed).';
                    }

                    return $this->dash->redirect($base, $note);

                case 'development':
                    // Its own action rather than a checkbox on site-save: this
                    // is the switch that decides whether the site exists as far
                    // as Google is concerned, and it should never ride along
                    // with an unrelated save (or be flipped by a stale form
                    // that happened to omit the field).
                    if (!$this->dash->canManageSite($user, $site)) {
                        return $this->dash->redirect($base, '', 'Only administrators or the site owner can change the development status.');
                    }
                    $updated = $this->sites->update($site->handle, [
                        'development' => (string) ($body['development'] ?? '1'),
                    ]);
                    if (SiteService::changeNeedsRebuild($site, $updated)) {
                        $this->publish->trigger($updated, 'publish: development status changed', 'dashboard');
                    }

                    return $this->dash->redirect($base, $updated->development
                        ? 'Development mode ON — rebuild + publish queued. Every page will ship noindex, nofollow until you go live.'
                        : 'Site is now LIVE — rebuild + publish queued. The noindex comes off every page and search engines may index it.');

                case 'publish-all-media':
                    // Its own action for the same reason as development: it
                    // decides what physically lands in dist, and it must never
                    // ride along with an unrelated save.
                    if (!$this->dash->canManageSite($user, $site)) {
                        return $this->dash->redirect($base, '', 'Only administrators or the site owner can change what the library publishes.');
                    }
                    $updated = $this->sites->update($site->handle, [
                        'publish_all_media' => (string) ($body['publish_all_media'] ?? '0'),
                    ]);
                    // Republishing is not a side effect here, it IS the fix:
                    // nothing else gets an unreferenced file onto the CDN.
                    if (SiteService::changeNeedsRebuild($site, $updated)) {
                        $this->publish->trigger($updated, 'publish: media library publishing changed', 'dashboard');
                    }

                    return $this->dash->redirect($base, $updated->publishAllMedia
                        ? 'The whole library will be published — rebuild + publish queued. Every image and file gets a working public URL once it deploys.'
                        : 'Back to publishing only what pages embed — rebuild + publish queued. Unused files will be removed from the live site.');

                case 'seo-save':
                    // Title composition + the default description are baked
                    // into every rendered page, so same authority as site-save.
                    if (!$this->dash->canManageSite($user, $site)) {
                        return $this->dash->redirect($base, '', 'Only administrators or the site owner can change SEO settings.');
                    }
                    $updated = $this->sites->update($site->handle, [
                        // Unchecked checkboxes are absent from the POST body.
                        'seo_title_suffix' => $body['seo_title_suffix'] ?? '0',
                        'seo_title_separator' => (string) ($body['seo_title_separator'] ?? $site->seoTitleSeparator),
                        'meta_description' => (string) ($body['meta_description'] ?? $site->metaDescription),
                        'cta_tracking' => $body['cta_tracking'] ?? '0',
                        // Structured data: the graph is in every page's head.
                        'structured_data' => $body['structured_data'] ?? '0',
                        'schema_type' => (string) ($body['schema_type'] ?? $site->schemaType),
                        // Textarea → one URL per line; SiteService splits and
                        // validates them into the stored JSON list.
                        'schema_same_as' => (string) ($body['schema_same_as'] ?? implode("\n", $site->sameAs)),
                    ]);
                    if (SiteService::changeNeedsRebuild($site, $updated)) {
                        $this->publish->trigger($updated, 'publish: seo settings changed', 'dashboard');

                        return $this->dash->redirect($base, 'SEO settings saved — rebuild + publish queued (every page embeds them).');
                    }

                    return $this->dash->redirect($base, 'SEO settings saved.');

                case 'permalinks-save':
                    if (!$this->dash->canManageSite($user, $site)) {
                        return $this->dash->redirect($base, '', 'Only administrators or the site owner can change permalinks.');
                    }
                    $updated = $this->sites->update($site->handle, [
                        'post_path_pattern' => (string) ($body['post_path_pattern'] ?? $site->postPathPattern),
                        'category_path_pattern' => (string) ($body['category_path_pattern'] ?? $site->categoryPathPattern),
                        'archive_per_page' => (int) ($body['archive_per_page'] ?? $site->archivePerPage),
                    ]);
                    if (SiteService::changeNeedsRebuild($site, $updated)) {
                        $this->publish->trigger($updated, 'publish: permalinks changed', 'dashboard');

                        return $this->dash->redirect($base, 'Permalinks saved — every moved URL now 301s to its new home, and the site republishes.');
                    }

                    return $this->dash->redirect($base, 'Permalinks unchanged.');

                case 'ftp-save':
                case 'ftp-test':
                    // Where the site publishes to is a publish-target change,
                    // same bar as the repo and the base URL.
                    if (!$this->dash->canManageSite($user, $site)) {
                        return $this->dash->redirect($base, '', 'Only administrators or the site owner can change the publishing target.');
                    }

                    $updated = $this->sites->update($site->handle, [
                        'ftp_protocol' => (string) ($body['ftp_protocol'] ?? $site->ftpProtocol),
                        'ftp_host' => (string) ($body['ftp_host'] ?? $site->ftpHost),
                        'ftp_port' => (int) ($body['ftp_port'] ?? $site->ftpPort),
                        'ftp_username' => (string) ($body['ftp_username'] ?? $site->ftpUsername),
                        'ftp_remote_path' => (string) ($body['ftp_remote_path'] ?? $site->ftpRemotePath),
                        // Unchecked checkboxes are absent from the POST, so an
                        // explicit '0' is what turns verification OFF — the
                        // relaxation is never made by omission.
                        'ftp_verify_cert' => $body['ftp_verify_cert'] ?? '0',
                        // Blank leaves the stored secret alone; the clear box
                        // wipes it — the same rule the Cloudflare token uses,
                        // because neither is ever rendered back into the form.
                        'ftp_password' => ($body['ftp_password_clear'] ?? '') !== '' ? '__clear__' : (string) ($body['ftp_password'] ?? ''),
                        'ftp_key' => ($body['ftp_key_clear'] ?? '') !== '' ? '__clear__' : (string) ($body['ftp_key'] ?? ''),
                    ]);

                    // Testing runs against what was just typed, not against a
                    // half-saved earlier attempt — so the reported result is
                    // always the result for the settings now on screen.
                    $probe = $this->ftp->test($updated);
                    if (!$probe['ok']) {
                        return $this->dash->redirect($base, '', 'Saved, but the connection failed: ' . $probe['note']);
                    }
                    if ((string) ($body['action'] ?? '') === 'ftp-test') {
                        return $this->dash->redirect($base, 'Connection OK. ' . $probe['note']);
                    }

                    // Activating is explicit: saving credentials must not
                    // silently move a live site off its current CDN.
                    if (($body['ftp_activate'] ?? '') === '') {
                        return $this->dash->redirect($base, 'Connection OK. Settings saved — tick "publish to this host" to make it the live target.');
                    }

                    $activated = $this->sites->update($site->handle, ['serve_mode' => 'ftp']);
                    $this->publish->trigger($activated, 'publish: ftp target configured', 'dashboard');

                    return $this->dash->redirect($base, 'Connection OK. Publishing to ' . $probe['note'] . ' Rebuild + upload queued.');

                case 'smtp-save':
                case 'smtp-test':
                    // The mail account is a credential for the customer's own
                    // mailbox, so it sits behind the same bar as the publish
                    // target rather than being editable by every collaborator.
                    if (!$this->dash->canManageSite($user, $site)) {
                        return $this->dash->redirect($base, '', 'Only administrators or the site owner can change the mail settings.');
                    }

                    $updated = $this->sites->update($site->handle, [
                        'smtp_host' => (string) ($body['smtp_host'] ?? $site->smtpHost),
                        'smtp_port' => (int) ($body['smtp_port'] ?? $site->smtpPort),
                        'smtp_encryption' => (string) ($body['smtp_encryption'] ?? $site->smtpEncryption),
                        'smtp_username' => (string) ($body['smtp_username'] ?? $site->smtpUsername),
                        'smtp_from_email' => (string) ($body['smtp_from_email'] ?? $site->smtpFromEmail),
                        'smtp_from_name' => (string) ($body['smtp_from_name'] ?? $site->smtpFromName),
                        // Unchecked checkboxes are absent from a POST, so the
                        // explicit '0' is what turns verification off — the
                        // relaxation is never made by omission.
                        'smtp_verify_cert' => $body['smtp_verify_cert'] ?? '0',
                        // Blank keeps the stored password; the clear box wipes
                        // it. It is never rendered back into the form.
                        'smtp_password' => ($body['smtp_password_clear'] ?? '') !== ''
                            ? '__clear__'
                            : (string) ($body['smtp_password'] ?? ''),
                    ]);

                    if ((string) ($body['action'] ?? '') === 'smtp-save') {
                        return $this->dash->redirect($base, 'Mail settings saved.');
                    }

                    // Testing runs against what was just typed, so the result
                    // always describes the settings now on screen. A real
                    // message, not just a login: a provider can accept the
                    // credentials and still refuse to deliver as that sender.
                    $to = trim((string) ($body['smtp_test_to'] ?? '')) !== ''
                        ? (string) $body['smtp_test_to']
                        : (string) $user['email'];

                    try {
                        $this->mailer->sendTest($updated, $to);
                    } catch (\Fastsite\Mail\MailException $e) {
                        return $this->dash->redirect(
                            $base,
                            '',
                            'Saved, but the test failed at ' . ($e->phase !== '' ? $e->phase : 'send')
                                . ': ' . $e->getMessage() . ' — ' . $e->hint(),
                        );
                    }

                    return $this->dash->redirect($base, 'Saved. A test email is on its way to ' . $to . '.');

                case 'cf-pages-setup':
                    if (!$this->dash->canManageSite($user, $site)) {
                        return $this->dash->redirect($base, '', 'Only administrators or the site owner can run the Cloudflare setup.');
                    }

                    if (($body['cf_clear'] ?? '') !== '') {
                        $this->sites->update($site->handle, ['cf_api_token' => '__clear__']);

                        return $this->dash->redirect($base, 'Cloudflare API token removed.');
                    }

                    // A freshly pasted token wins; blank falls back to the
                    // stored one so re-runs don't require re-pasting.
                    $token = trim((string) ($body['cf_api_token'] ?? ''));
                    if ($token === '' && $site->cfApiTokenEnc !== null) {
                        $token = (string) $this->cipher->decrypt($site->cfApiTokenEnc);
                    }
                    if ($token === '') {
                        return $this->dash->redirect($base, '', 'Paste a Cloudflare API token first (My Profile → API Tokens).');
                    }

                    $result = $this->cloudflare->setup($site, $token);

                    // Persist what the run established: the working token and
                    // the discovered account id (both survive for re-runs).
                    $store = [];
                    if (($body['cf_api_token'] ?? '') !== '' && ($result['steps'][0]['ok'] ?? false)) {
                        $store['cf_api_token'] = $token;
                    }
                    if ($result['account_id'] !== '') {
                        $store['cf_account_id'] = $result['account_id'];
                    }
                    if ($store !== []) {
                        $this->sites->update($site->handle, $store);
                    }

                    // Fresh content in the repo so Cloudflare has a deploy to run.
                    if ($result['ok']) {
                        $this->publish->trigger($site, 'publish: cloudflare pages setup', 'cf-setup');
                    }

                    $summary = implode(' · ', array_map(
                        static fn (array $s): string => ($s['ok'] ? '✓ ' : '✗ ') . $s['note'],
                        $result['steps'],
                    ));

                    return $result['ok']
                        ? $this->dash->redirect($base, 'Cloudflare Pages: ' . $summary . ' · Publish queued — live when the deploy lands.')
                        : $this->dash->redirect($base, '', 'Cloudflare Pages: ' . $summary);

                case 'cf-account-id':
                    // Manual entry for users who go the click-through route
                    // (no API token): with the id saved, the Go-live card can
                    // deep-link straight to their account's Pages screens
                    // instead of the generic :account placeholder links.
                    if (!$this->dash->canManageSite($user, $site)) {
                        return $this->dash->redirect($base, '', 'Only administrators or the site owner can change Cloudflare settings.');
                    }
                    $updated = $this->sites->update($site->handle, ['cf_account_id' => (string) ($body['cf_account_id'] ?? '')]);

                    return $this->dash->redirect($base, $updated->cfAccountId !== null
                        ? 'Cloudflare account ID saved — the Go-live links below now open your account directly.'
                        : 'Cloudflare account ID cleared.');

                // add-language / remove-language / retry-translations moved
                // to TranslationsActionHandler (the Translations tab).

                case 'shell-save':
                    $path = (string) ($body['path'] ?? '');
                    // Browsers submit textarea content as CRLF; shell files
                    // are LF by convention. Normalize so a no-op save doesn't
                    // rewrite every line (manifest churn, spurious sha change).
                    $content = str_replace(["\r\n", "\r"], "\n", (string) ($body['content'] ?? ''));
                    $this->shell->writeFile($site, $path, $content, $actor);

                    // $base may already carry ?tab=shell, so this joins rather
                    // than assumes it is opening the query string.
                    return $this->dash->redirect(
                        $base . (str_contains($base, '?') ? '&' : '?') . 'file=' . rawurlencode($path),
                        'Saved ' . $path . ' — rebuild queued.',
                    );

                case 'shell-snapshot':
                    $rev = $this->shell->snapshot($site, (string) ($body['note'] ?? 'dashboard snapshot'), $actor);

                    return $this->dash->redirect($base, 'Snapshot created: revision ' . $rev . '.');

                case 'shell-delete':
                    $rev = (int) ($body['rev'] ?? 0);
                    $this->shell->deleteRevision($site, $rev, $actor);

                    return $this->dash->redirect($base, 'Snapshot ' . $rev . ' deleted.');

                case 'shell-restore':
                    $rev = (int) ($body['rev'] ?? 0);
                    $this->shell->restore($site, $rev, $actor);

                    return $this->dash->redirect($base, 'Shell restored to revision ' . $rev . ' — rebuild queued.');

                case 'script-save':
                    $this->scripts->save($site->handle, [
                        'id' => (int) ($body['id'] ?? 0),
                        'label' => (string) ($body['label'] ?? ''),
                        'location' => (string) ($body['location'] ?? 'head'),
                        'strategy' => (string) ($body['strategy'] ?? 'defer'),
                        'priority' => (int) ($body['priority'] ?? 100),
                        'code' => (string) ($body['code'] ?? ''),
                        'enabled' => isset($body['enabled']),
                        // Slug lists arrive as comma-separated strings here and
                        // as arrays over REST/MCP; the service normalises both
                        // (see SiteScriptService::slugList).
                        'target' => (string) ($body['target'] ?? 'all'),
                        'include_slugs' => (string) ($body['include_slugs'] ?? ''),
                        'exclude_slugs' => (string) ($body['exclude_slugs'] ?? ''),
                    ]);
                    $this->publish->trigger($site, 'publish: site scripts changed', 'dashboard');

                    return $this->dash->redirect($base, 'Script saved — rebuild + publish queued. Note: scripts trade away the 100 PageSpeed guarantee for this site.');

                case 'script-delete':
                    $this->scripts->delete($site->handle, (int) ($body['id'] ?? 0));
                    $this->publish->trigger($site, 'publish: site script removed', 'dashboard');

                    return $this->dash->redirect($base, 'Script deleted — rebuild + publish queued.');

                case 'error-page-create':
                    // Creates the row and hands you straight to the editor —
                    // there is no second form here. An error page is an
                    // ordinary page, so it is edited where pages are edited;
                    // a parallel editor on this tab would be a second content
                    // pipeline to keep in step with the real one.
                    $status = (int) ($body['status'] ?? 0);
                    try {
                        $row = $this->content->create($site, [
                            'type' => 'page',
                            'status' => 'published',
                            'error_status' => $status,
                            'title' => self::errorPageTitle($status),
                            'body_html' => '<p>Edit this page to say whatever you want here.</p>',
                        ], 'dashboard');
                    } catch (\InvalidArgumentException $e) {
                        return $this->dash->redirect($base, '', $e->getMessage());
                    }
                    $this->publish->trigger($site, 'publish: ' . $status . ' page created', 'dashboard');

                    return $this->dash->redirect(
                        '/sites/' . $site->handle . '/content?edit=' . $row->id,
                        'Created your ' . $status . ' page — edit it here. Rebuild + publish queued.',
                    );

                case 'error-page-detach':
                    // Deliberately NOT a delete. The row keeps its content and
                    // becomes an ordinary page again, so "I did not mean that"
                    // costs nothing — and a 404 that detaches falls back to the
                    // shell's built-in page rather than leaving the site with
                    // no 404 at all.
                    $id = (int) ($body['id'] ?? 0);
                    $this->content->update($site, $id, ['error_status' => null], 'dashboard');
                    $this->publish->trigger($site, 'publish: error page detached', 'dashboard');

                    return $this->dash->redirect($base, 'That page is an ordinary page again — rebuild + publish queued.');

                case 'redirect-save':
                    $saved = $this->redirects->save($site->handle, (string) ($body['from_path'] ?? ''), (string) ($body['to_path'] ?? ''), (int) ($body['status'] ?? 301));
                    $this->publish->trigger($site, 'publish: redirect added', 'dashboard');
                    if ($saved['status'] === RedirectService::GONE) {
                        return $this->dash->redirect($base, $site->serveMode === 'direct'
                            ? $saved['from_path'] . ' now returns 410 Gone — rebuild + publish queued.'
                            : $saved['from_path'] . ' marked 410 Gone — but this site publishes to a CDN, where _redirects cannot return 410, so it will serve the 404 page. Switch Serving to "Direct from this server" for a real 410.');
                    }

                    return $this->dash->redirect($base, 'Redirect saved — rebuild + publish queued.');

                case 'canonical-host':
                    // The host-level www↔apex rule. Not a redirects-table row —
                    // it is a site setting the build (canonicals) and, in
                    // direct mode, DirectServeGate both read. It moves the
                    // site's public host, so it needs the same authority as
                    // the other site settings.
                    if (!$this->dash->canManageSite($user, $site)) {
                        return $this->dash->redirect($base, '', 'Only administrators or the site owner can change the canonical host.');
                    }
                    $after = $this->sites->update($site->handle, ['canonical_host' => (string) ($body['canonical_host'] ?? 'apex')]);
                    $this->publish->trigger($site, 'publish: canonical host changed', 'dashboard');

                    return $this->dash->redirect($base, $after->canonicalHost === 'www'
                        ? 'Canonical host is now www — the apex 301s to it. Rebuild + publish queued.'
                        : 'Canonical host is now the apex — www 301s to it. Rebuild + publish queued.');

                case 'redirect-delete':
                    $this->redirects->delete($site->handle, (int) ($body['id'] ?? 0));
                    $this->publish->trigger($site, 'publish: redirect removed', 'dashboard');

                    return $this->dash->redirect($base, 'Redirect deleted — rebuild + publish queued.');

                case 'missing-resolve':
                    $status = (int) ($body['status'] ?? 301);
                    $this->redirects->resolveMissing($site->handle, (int) ($body['id'] ?? 0), (string) ($body['to_path'] ?? ''), $status);
                    $this->publish->trigger($site, 'publish: redirect from 404', 'dashboard');

                    return $this->dash->redirect($base, $status === RedirectService::GONE
                        ? 'Marked 410 Gone — rebuild + publish queued.'
                        : 'Redirect created from the 404 — rebuild + publish queued.');

                case 'missing-clear':
                    $this->redirects->clearMissing($site->handle, (int) ($body['id'] ?? 0));

                    return $this->dash->redirect($base, 'Dismissed.');

                case 'missing-clear-bulk':
                    $cleared = $this->redirects->clearMissingMany($site->handle, (array) ($body['ids'] ?? []));
                    // No publish: dismissing only stops a suggestion being
                    // suggested. No rule is written and no page changes.
                    return $this->dash->redirect($missingView, $cleared === 0
                        ? 'Nothing selected.'
                        : sprintf('Dismissed %d suggestion%s.', $cleared, $cleared === 1 ? '' : 's'));

                case 'missing-gone-bulk':
                    $gone = $this->redirects->resolveMissingMany($site->handle, (array) ($body['ids'] ?? []), RedirectService::GONE);
                    if ($gone === 0) {
                        return $this->dash->redirect($missingView, 'Nothing selected.');
                    }
                    // ONE publish for the whole batch, not one per path.
                    $this->publish->trigger($site, 'publish: ' . $gone . ' paths marked 410', 'dashboard');

                    return $this->dash->redirect($missingView, sprintf(
                        'Marked %d path%s 410 Gone — rebuild + publish queued.',
                        $gone,
                        $gone === 1 ? '' : 's',
                    ));

                default:
                    return $this->dash->redirect($base, '', 'Unknown action.');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect($base, '', $e->getMessage());
        }
    }

    /**
     * The starting title for a newly created error page.
     *
     * Plain-language rather than the RFC reason phrase: the title ends up in
     * the visitor's `<title>` bar, and "Too Many Requests" is jargon where
     * "Too many requests" at least reads like a sentence. The owner renames it
     * in the editor they are redirected to anyway — this only has to be
     * better than an empty field.
     */
    private static function errorPageTitle(int $status): string
    {
        return match ($status) {
            401 => 'Sign in required',
            403 => 'No access to this page',
            404 => 'Page not found',
            410 => 'This page is gone',
            429 => 'Too many requests',
            451 => 'Unavailable for legal reasons',
            default => 'Error ' . $status,
        };
    }
}
