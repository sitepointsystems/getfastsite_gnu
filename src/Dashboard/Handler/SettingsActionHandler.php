<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Dashboard\Dash;
use Fastsite\Publish\Service\CloudflarePagesService;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Shell\Service\ShellService;
use Fastsite\Site\Service\RedirectService;
use Fastsite\Site\Service\SiteScriptService;
use Fastsite\Site\Service\SiteService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/{site}/settings/action — add-language, shell-save,
 * shell-snapshot, shell-restore. Shell writes go through ShellService (all
 * its guards + coalesced rebuild apply unchanged).
 */
final class SettingsActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly ShellService $shell,
        private readonly SiteService $sites,
        private readonly JobQueue $queue,
        private readonly PublishTrigger $publish,
        private readonly SiteScriptService $scripts,
        private readonly RedirectService $redirects,
        private readonly CloudflarePagesService $cloudflare,
        private readonly TokenCipher $cipher,
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
        $base = '/sites/' . $site->handle . '/settings';

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'site-save':
                    // Repo / base URL decide WHERE content gets published, so
                    // these are admin-only (a site editor already controls the
                    // shell, but must not redirect the publish target).
                    if (($user['role'] ?? '') !== 'admin') {
                        return $this->dash->redirect($base, '', 'Only administrators can change site settings.');
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
                        'meta_description' => (string) ($body['meta_description'] ?? $site->metaDescription),
                        'canonical_host' => (string) ($body['canonical_host'] ?? $site->canonicalHost),
                        'github_credential_id' => $body['github_credential_id'] ?? null,
                        'ai_credential_id' => $body['ai_credential_id'] ?? null,
                        'team_id' => $body['team_id'] ?? null,
                        'serve_mode' => (string) ($body['serve_mode'] ?? $site->serveMode),
                        // Blank = leave the saved key unchanged; the clear box wipes it.
                        'shortpixel_key' => ($body['shortpixel_clear'] ?? '') !== '' ? '__clear__' : (string) ($body['shortpixel_key'] ?? ''),
                        'fact_check_enabled' => $body['fact_check_enabled'] ?? '0',
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
                        $note .= ' Rebuild + publish queued (canonical URLs changed).';
                    } elseif ($updated->serveMode !== $site->serveMode) {
                        // Flipping the mode republishes so the new target is
                        // current: direct → the build tree is fresh for the
                        // gate; git → the repo catches up on anything that
                        // published while pushes were skipped.
                        $this->publish->trigger($updated, 'publish: serving mode changed', 'dashboard');
                        $note .= $updated->serveMode === 'direct'
                            ? ' Now serving directly from this server — rebuild queued.'
                            : ' Back to Git → CDN — rebuild + push queued.';
                    }

                    return $this->dash->redirect($base, $note);

                case 'cf-pages-setup':
                    if (($user['role'] ?? '') !== 'admin') {
                        return $this->dash->redirect($base, '', 'Only administrators can run the Cloudflare setup.');
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
                    if (($user['role'] ?? '') !== 'admin') {
                        return $this->dash->redirect($base, '', 'Only administrators can change Cloudflare settings.');
                    }
                    $updated = $this->sites->update($site->handle, ['cf_account_id' => (string) ($body['cf_account_id'] ?? '')]);

                    return $this->dash->redirect($base, $updated->cfAccountId !== null
                        ? 'Cloudflare account ID saved — the Go-live links below now open your account directly.'
                        : 'Cloudflare account ID cleared.');

                case 'add-language':
                    $locales = $this->sites->addLocale($site->handle, (string) ($body['locale'] ?? ''));
                    $this->queue->enqueue('translate_content', $site->handle, ['trigger' => 'dashboard'], 'default', 'translate:' . $site->handle);

                    return $this->dash->redirect($base, 'Locales now: ' . implode(', ', $locales) . '. Translation queued.');

                case 'retry-translations':
                    // Re-run translation for the site. syncOne treats a
                    // status='failed' row as needing translation (its source_hash
                    // is cleared), so this re-attempts the failed items (plus any
                    // stale/missing), then chains build+publish. Coalesced with
                    // any pending translate via the dedupe key.
                    $this->queue->enqueue('translate_content', $site->handle, ['trigger' => 'dashboard-retry'], 'default', 'translate:' . $site->handle);

                    return $this->dash->redirect($base, 'Translation re-queued — failed items will be retried, then the site republishes.');

                case 'remove-language':
                    $locale = (string) ($body['locale'] ?? '');
                    try {
                        $locales = $this->sites->removeLocale($site->handle, $locale);
                    } catch (\InvalidArgumentException | \RuntimeException $e) {
                        return $this->dash->redirect($base, '', $e->getMessage());
                    }
                    // Rebuild + publish so the /{locale}/ tree drops from the live site.
                    $this->publish->trigger($site, 'publish: language removed (' . $locale . ')', 'dashboard');

                    return $this->dash->redirect($base, 'Removed ' . $locale . '. Locales now: ' . (implode(', ', $locales) ?: 'primary only') . '. Rebuild + publish queued.');

                case 'shell-save':
                    $path = (string) ($body['path'] ?? '');
                    // Browsers submit textarea content as CRLF; shell files
                    // are LF by convention. Normalize so a no-op save doesn't
                    // rewrite every line (manifest churn, spurious sha change).
                    $content = str_replace(["\r\n", "\r"], "\n", (string) ($body['content'] ?? ''));
                    $this->shell->writeFile($site, $path, $content, $actor);

                    return $this->dash->redirect($base . '?file=' . rawurlencode($path), 'Saved ' . $path . ' — rebuild queued.');

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
                    ]);
                    $this->publish->trigger($site, 'publish: site scripts changed', 'dashboard');

                    return $this->dash->redirect($base, 'Script saved — rebuild + publish queued. Note: scripts trade away the 100 PageSpeed guarantee for this site.');

                case 'script-delete':
                    $this->scripts->delete($site->handle, (int) ($body['id'] ?? 0));
                    $this->publish->trigger($site, 'publish: site script removed', 'dashboard');

                    return $this->dash->redirect($base, 'Script deleted — rebuild + publish queued.');

                case 'redirect-save':
                    $this->redirects->save($site->handle, (string) ($body['from_path'] ?? ''), (string) ($body['to_path'] ?? ''), (int) ($body['status'] ?? 301));
                    $this->publish->trigger($site, 'publish: redirect added', 'dashboard');

                    return $this->dash->redirect($base, 'Redirect saved — rebuild + publish queued.');

                case 'redirect-delete':
                    $this->redirects->delete($site->handle, (int) ($body['id'] ?? 0));
                    $this->publish->trigger($site, 'publish: redirect removed', 'dashboard');

                    return $this->dash->redirect($base, 'Redirect deleted — rebuild + publish queued.');

                case 'missing-resolve':
                    $this->redirects->resolveMissing($site->handle, (int) ($body['id'] ?? 0), (string) ($body['to_path'] ?? ''), (int) ($body['status'] ?? 301));
                    $this->publish->trigger($site, 'publish: redirect from 404', 'dashboard');

                    return $this->dash->redirect($base, 'Redirect created from the 404 — rebuild + publish queued.');

                case 'missing-clear':
                    $this->redirects->clearMissing($site->handle, (int) ($body['id'] ?? 0));

                    return $this->dash->redirect($base, 'Dismissed.');

                default:
                    return $this->dash->redirect($base, '', 'Unknown action.');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect($base, '', $e->getMessage());
        }
    }
}
