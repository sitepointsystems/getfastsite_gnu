<?php

declare(strict_types=1);

namespace Fastsite\Site\Service;

use Fastsite\Publish\Service\FtpTarget;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Log\LoggerInterface;

/**
 * Site lifecycle: registry row + on-disk provisioning (site.db, shell
 * scaffold, media/build/repo dirs).
 */
final class SiteService
{
    /**
     * Separators a site may pick for the "<title> — Site Name" suffix
     * (Site::pageTitle). Allowlisted: the separator lands verbatim in every
     * page <title>, so free text is not an option.
     */
    public const TITLE_SEPARATORS = ['—', '–', '-', '|', '·', ':'];

    /**
     * How the SMTP connection is encrypted. `starttls` (587) is the modern
     * default, `tls` is implicit TLS (465), `none` is plaintext (25) and is
     * offered only because some internal relays still need it — the password
     * rides that connection, so it should never be the choice over the public
     * internet.
     */
    public const SMTP_ENCRYPTIONS = ['none', 'tls', 'starttls'];

    /**
     * How far the form spam scorer is allowed to act. `log` is the one that
     * earns its place: it scores and records a verdict but notifies anyway, so
     * a site can see a week of what WOULD have been held before anything is
     * actually held. Shipping straight to `on` means the first false positive
     * is a real lead nobody ever finds out about.
     */
    public const SPAM_MODES = ['off', 'log', 'on'];

    /**
     * What a site is as a schema.org entity. Google resolves an Organization
     * and a Person differently, so this is a real choice, not a label — a
     * personal brand marked up as a company resolves to neither.
     */
    public const SCHEMA_TYPES = ['Organization', 'Person'];

    /**
     * Ceiling on `sameAs` profile URLs. A handful of real profiles is the
     * signal; a link dump is not, and every one of them ships on every page.
     */
    private const SAME_AS_MAX = 25;

    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteDatabaseManager $databases,
        private readonly Migrator $migrator,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $appRoot,
        // Nullable + defaulted so the many test fixtures that build SiteService
        // positionally keep working; php-di injects the real cipher in the app.
        private readonly ?\Fastsite\Shared\Crypto\TokenCipher $cipher = null,
        /**
         * Seeds the default 404 page on `create()`. Optional for the same
         * reason the cipher is — and like the cipher it is wired explicitly in
         * services.php and asserted by ContainerWiringTest, because php-di
         * does NOT autowire defaulted params: without that line a new site
         * would silently fall back to the shell's built-in 404 and the
         * "every site gets an editable one" promise would quietly not hold.
         */
        private readonly ?\Fastsite\Content\Service\ContentService $content = null,
        /**
         * Leaves the 301s behind when a permalink setting moves live URLs.
         * Optional and wired explicitly for the same reasons as $content; a
         * pattern change without it would break every inbound link, so it is
         * asserted by ContainerWiringTest too.
         */
        private readonly ?\Fastsite\Category\Service\PermalinkMover $permalinks = null,
    ) {
    }

    public function create(
        string $handle,
        string $name,
        string $baseUrl = '',
        string $githubRepo = '',
        string $primaryLocale = 'en',
        ?int $ownerUserId = null,
    ): Site {
        SiteDatabaseManager::assertHandle($handle);

        if ($this->sites->findByHandle($handle) !== null) {
            throw new \RuntimeException(sprintf('Site "%s" already exists.', $handle));
        }

        // Canonicalize to the apex host (strip a leading www.) so canonical,
        // OG, sitemap and llms.txt never emit the www. duplicate (§apex).
        $baseUrl = self::normalizeBaseUrl($baseUrl);
        // Site name flows into line-oriented build artifacts (robots banner,
        // llms.txt) — strip control chars at the source so nothing downstream
        // has to defend against an injected newline.
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $name));

        // Provision on disk FIRST — if this throws, no registry row exists
        // and site:create can simply be re-run (provisioning is idempotent).
        $db = $this->databases->provisionSite($handle);
        $applied = $this->migrator->migrate($db, $this->appRoot . '/migrations/site');
        $this->scaffoldShell($handle);

        $this->sites->insert([
            'handle' => $handle,
            'name' => $name,
            'base_url' => $baseUrl,
            'primary_domain' => $baseUrl !== '' ? (parse_url($baseUrl, PHP_URL_HOST) ?: '') : '',
            'primary_locale' => $primaryLocale,
            'github_repo' => $githubRepo,
        ]);

        $this->logger->info('Site created', ['handle' => $handle, 'migrations' => $applied]);

        $site = $this->sites->findByHandle($handle);
        \assert($site !== null);

        $this->seedErrorPage($site);

        // Record the creator as the site owner so non-admin creators (editors)
        // can reach the site through Dash::sitesFor / McpTools::canAccess.
        if ($ownerUserId !== null) {
            $this->databases->engine()->executeStatement(
                "INSERT INTO site_users (site_id, user_id, role, created_at) VALUES (?, ?, 'owner', ?)
                 ON CONFLICT (site_id, user_id) DO NOTHING",
                [$site->id, $ownerUserId, $this->clock->iso()],
            );
        }

        return $site;
    }

    /**
     * Gives a brand-new site an editable 404 page.
     *
     * Deliberately short copy. The body is auto-translated into every locale
     * the site later adds, so seeded boilerplate is boilerplate somebody pays
     * a model to translate — and it also takes over from the shell's own
     * `not_found` i18n string, which already ships in nine languages for free.
     * One sentence is the whole budget; anything longer belongs to whatever
     * the owner writes in its place.
     *
     * Never fatal. A site that exists with no 404 row still builds — the build
     * falls back to the shell template, which is exactly what every site did
     * before this feature — so a failure here must not undo a provisioned
     * site. It is logged and the site is returned.
     */
    private function seedErrorPage(Site $site): void
    {
        if ($this->content === null) {
            return;
        }

        try {
            $this->content->create($site, [
                'type' => 'page',
                'status' => 'published',
                'error_status' => 404,
                'title' => 'Page not found',
                'body_html' => '<p>That page does not exist. It may have moved, or the link may be wrong.</p>',
            ], 'system');
        } catch (\Throwable $e) {
            $this->logger->warning('Could not seed the default 404 page', [
                'site' => $site->handle,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Editable site settings. Only the keys present are written. The handle
     * is immutable (it's the identity on every API/MCP/webhook surface and
     * the on-disk path). Returns the refreshed Site.
     *
     * Changing github_repo resets the local clone so the next publish targets
     * the NEW remote — otherwise GitPublisher keeps pushing to the old one
     * (it only clones when repo/.git is absent).
     *
     * @param array<string, mixed> $fields
     */
    public function update(string $handle, array $fields): Site
    {
        $site = $this->sites->findByHandle($handle)
            ?? throw new \RuntimeException('Unknown site: ' . $handle);

        $write = [];

        if (\array_key_exists('name', $fields)) {
            $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $fields['name']));
            if ($name === '') {
                throw new \InvalidArgumentException('Site name cannot be empty.');
            }
            $write['name'] = mb_substr($name, 0, 200);
        }

        if (\array_key_exists('base_url', $fields)) {
            $baseUrl = self::normalizeBaseUrl((string) $fields['base_url']);
            if ($baseUrl !== '' && !preg_match('#^https?://[^\s/]+#i', $baseUrl)) {
                throw new \InvalidArgumentException('Base URL must start with http:// or https://');
            }
            $write['base_url'] = $baseUrl;
            $write['primary_domain'] = $baseUrl !== '' ? (parse_url($baseUrl, PHP_URL_HOST) ?: '') : '';
        }

        if (\array_key_exists('github_repo', $fields)) {
            $repo = trim((string) $fields['github_repo']);
            if ($repo !== '' && preg_match('#^(git@[\w.\-]+:[\w.\-/]+?(\.git)?|https://[\w.\-]+/[\w.\-/]+?(\.git)?)$#', $repo) !== 1) {
                throw new \InvalidArgumentException('Repo must look like git@github.com:you/site-dist.git or https://github.com/you/site-dist.git');
            }
            $write['github_repo'] = $repo;
        }

        if (\array_key_exists('github_branch', $fields)) {
            $branch = trim((string) $fields['github_branch']);
            $branch = $branch !== '' ? $branch : 'main';
            if (preg_match('#^[A-Za-z0-9._/\-]+$#', $branch) !== 1) {
                throw new \InvalidArgumentException('Invalid branch name.');
            }
            $write['github_branch'] = $branch;
        }

        if (\array_key_exists('media_public_base', $fields)) {
            $mediaBase = rtrim(trim((string) $fields['media_public_base']), '/');
            if ($mediaBase !== '' && !preg_match('#^https://[^\s/]+#i', $mediaBase)) {
                throw new \InvalidArgumentException('Media public base must be an https:// URL.');
            }
            $write['media_public_base'] = $mediaBase;
        }

        if (\array_key_exists('status', $fields)) {
            $status = (string) $fields['status'];
            if (!\in_array($status, ['active', 'paused', 'archived'], true)) {
                throw new \InvalidArgumentException('Status must be active, paused or archived.');
            }
            $write['status'] = $status;
        }

        // Site-level fallback <meta name="description"> for the pages the engine
        // generates itself (home, blog index, 404) — they have no content row to
        // take one from. Capped so a pasted article body cannot become the tag.
        if (\array_key_exists('meta_description', $fields)) {
            $description = trim(preg_replace('/\s+/', ' ', (string) $fields['meta_description']) ?? '');
            if (mb_strlen($description) > 320) {
                throw new \InvalidArgumentException('Meta description exceeds the maximum length of 320 characters.');
            }
            $write['meta_description'] = $description;
        }

        // Branding: a media id (>0) or null to clear. The build resolves each
        // to a /media/{id}/… URL and injects it into the shell context.
        // social_image_media_id is the default og:image / twitter:image.
        foreach (['favicon_media_id', 'logo_header_media_id', 'logo_footer_media_id', 'social_image_media_id'] as $brandField) {
            if (\array_key_exists($brandField, $fields)) {
                $val = $fields[$brandField];
                $write[$brandField] = ($val === null || $val === '' || (int) $val <= 0) ? null : (int) $val;
            }
        }

        if (\array_key_exists('canonical_host', $fields)) {
            $host = (string) $fields['canonical_host'];
            if (!\in_array($host, ['apex', 'www'], true)) {
                throw new \InvalidArgumentException('canonical_host must be "apex" or "www".');
            }
            $write['canonical_host'] = $host;
        }

        if (\array_key_exists('github_credential_id', $fields)) {
            $val = $fields['github_credential_id'];
            $write['github_credential_id'] = ($val === null || $val === '' || (int) $val <= 0) ? null : (int) $val;
        }

        if (\array_key_exists('ai_credential_id', $fields)) {
            $val = $fields['ai_credential_id'];
            $write['ai_credential_id'] = ($val === null || $val === '' || (int) $val <= 0) ? null : (int) $val;
        }

        if (\array_key_exists('team_id', $fields)) {
            $val = $fields['team_id'];
            $write['team_id'] = ($val === null || $val === '' || (int) $val <= 0) ? null : (int) $val;
        }

        // Workspace grouping (customer separation). Access to the TARGET
        // workspace is the calling transport's job (dashboard/REST/MCP all
        // 403 via WorkspaceService::canAccess before reaching here).
        if (\array_key_exists('workspace_id', $fields)) {
            $val = $fields['workspace_id'];
            $write['workspace_id'] = ($val === null || $val === '' || (int) $val <= 0) ? null : (int) $val;
        }

        if (\array_key_exists('serve_mode', $fields)) {
            $mode = (string) $fields['serve_mode'];
            if (!\in_array($mode, ['git', 'direct', 'ftp'], true)) {
                throw new \InvalidArgumentException('serve_mode must be "git", "direct", or "ftp".');
            }
            if ($mode === 'direct') {
                // Direct serving matches requests by Host header, so the site
                // must know its domain (from this update's base_url or the
                // stored one) before the mode can be switched on.
                $domain = isset($write['primary_domain'])
                    ? (string) $write['primary_domain']
                    : $site->primaryDomain;
                if ($domain === '') {
                    throw new \InvalidArgumentException('Set the base URL first — direct serving matches requests by domain.');
                }
            }
            if ($mode === 'ftp') {
                // Uploading needs somewhere to upload TO. Read from the INCOMING
                // $fields, not from $write — the ftp_* blocks below have not run
                // yet, and host + mode arriving in one submit is the normal case
                // (it is a single form).
                $host = \array_key_exists('ftp_host', $fields) ? trim((string) $fields['ftp_host']) : $site->ftpHost;
                $user = \array_key_exists('ftp_username', $fields) ? trim((string) $fields['ftp_username']) : $site->ftpUsername;
                if ($host === '' || $user === '') {
                    // Name the panel that actually does this. That panel saves
                    // the target AND switches the mode in one submit, so the
                    // Serving radio is the long way round when nothing is
                    // configured yet — and an error that only says "set the
                    // host first" without saying where is a dead end.
                    throw new \InvalidArgumentException('Set the FTP host and username first, in "Your own web host" under Go live below — that panel saves the target and switches the site over in one step.');
                }
            }
            $write['serve_mode'] = $mode;
        }

        // Where an `ftp` site publishes to. Non-secrets are plain columns so
        // the settings screen can show the target without decrypting; the
        // password and the SSH key follow the blank-keeps / __clear__-wipes
        // rule the Cloudflare token and ShortPixel key use below.
        if (\array_key_exists('ftp_protocol', $fields)) {
            $protocol = strtolower(trim((string) $fields['ftp_protocol']));
            if (!\in_array($protocol, FtpTarget::PROTOCOLS, true)) {
                throw new \InvalidArgumentException('ftp_protocol must be ftp, ftps, or sftp.');
            }
            $write['ftp_protocol'] = $protocol;
        }
        if (\array_key_exists('ftp_host', $fields)) {
            // A pasted "sftp://host:2222/" is normalized rather than rejected —
            // that is what hosting panels hand people.
            $host = trim((string) $fields['ftp_host']);
            if ($host !== '' && str_contains($host, '://')) {
                $parsed = parse_url($host);
                if (\is_array($parsed) && isset($parsed['host'])) {
                    $host = (string) $parsed['host'];
                    if (isset($parsed['port']) && !\array_key_exists('ftp_port', $fields)) {
                        $write['ftp_port'] = (int) $parsed['port'];
                    }
                }
            }
            $host = ltrim($host, '/');
            if ($host !== '' && preg_match('/^[A-Za-z0-9._:\[\]-]+$/', $host) !== 1) {
                throw new \InvalidArgumentException('That does not look like a hostname or IP address.');
            }
            $write['ftp_host'] = $host;
        }
        if (\array_key_exists('ftp_port', $fields)) {
            $port = (int) $fields['ftp_port'];
            if ($port < 0 || $port > 65535) {
                throw new \InvalidArgumentException('ftp_port must be between 0 and 65535 (0 = the protocol default).');
            }
            $write['ftp_port'] = $port;
        }
        if (\array_key_exists('ftp_username', $fields)) {
            $write['ftp_username'] = trim((string) $fields['ftp_username']);
        }
        if (\array_key_exists('ftp_remote_path', $fields)) {
            $write['ftp_remote_path'] = FtpTarget::normalizePath((string) $fields['ftp_remote_path']);
        }
        if (\array_key_exists('ftp_verify_cert', $fields)) {
            $val = $fields['ftp_verify_cert'];
            $write['ftp_verify_cert'] = ($val && $val !== '0' && $val !== 'false') ? 1 : 0;
        }
        if (\array_key_exists('ftp_password', $fields)) {
            $password = (string) $fields['ftp_password'];
            if (trim($password) === '__clear__') {
                $write['ftp_password_enc'] = null;
            } elseif ($password !== '' && $this->cipher !== null) {
                // NOT trimmed: a password may legitimately begin or end with a
                // space, and silently changing it would be an unexplainable
                // authentication failure.
                $write['ftp_password_enc'] = $this->cipher->encrypt($password);
            }
        }
        if (\array_key_exists('ftp_key', $fields)) {
            $key = (string) $fields['ftp_key'];
            if (trim($key) === '__clear__') {
                $write['ftp_key_enc'] = null;
            } elseif (trim($key) !== '' && $this->cipher !== null) {
                $write['ftp_key_enc'] = $this->cipher->encrypt($key);
            }
        }

        // SMTP: the account this site's form mail is relayed through. Same
        // shape as the FTP target — non-secrets plain so the settings screen
        // can show them, the password blank-keeps / __clear__-wipes.
        if (\array_key_exists('smtp_host', $fields)) {
            // "smtps://mail.example.com:465" is what hosting panels hand
            // people, so it is normalized rather than rejected.
            $host = trim((string) $fields['smtp_host']);
            if ($host !== '' && str_contains($host, '://')) {
                $parsed = parse_url($host);
                if (\is_array($parsed) && isset($parsed['host'])) {
                    $host = (string) $parsed['host'];
                    if (isset($parsed['port']) && !\array_key_exists('smtp_port', $fields)) {
                        $write['smtp_port'] = (int) $parsed['port'];
                    }
                }
            }
            $host = ltrim($host, '/');
            if ($host !== '' && preg_match('/^[A-Za-z0-9._:\[\]-]+$/', $host) !== 1) {
                throw new \InvalidArgumentException('That does not look like a hostname or IP address.');
            }
            $write['smtp_host'] = $host;
        }
        if (\array_key_exists('smtp_port', $fields)) {
            $port = (int) $fields['smtp_port'];
            if ($port < 0 || $port > 65535) {
                throw new \InvalidArgumentException('smtp_port must be between 0 and 65535 (0 = the default for the encryption).');
            }
            $write['smtp_port'] = $port;
        }
        if (\array_key_exists('smtp_encryption', $fields)) {
            $encryption = strtolower(trim((string) $fields['smtp_encryption']));
            if (!\in_array($encryption, self::SMTP_ENCRYPTIONS, true)) {
                throw new \InvalidArgumentException('smtp_encryption must be one of: ' . implode(', ', self::SMTP_ENCRYPTIONS));
            }
            $write['smtp_encryption'] = $encryption;
        }
        if (\array_key_exists('smtp_username', $fields)) {
            $write['smtp_username'] = trim((string) $fields['smtp_username']);
        }
        if (\array_key_exists('smtp_from_email', $fields)) {
            $from = trim((string) $fields['smtp_from_email']);
            if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('The sender address is not a valid email address.');
            }
            $write['smtp_from_email'] = $from;
        }
        if (\array_key_exists('smtp_from_name', $fields)) {
            $write['smtp_from_name'] = mb_substr(trim((string) $fields['smtp_from_name']), 0, 120);
        }
        if (\array_key_exists('smtp_verify_cert', $fields)) {
            $val = $fields['smtp_verify_cert'];
            $write['smtp_verify_cert'] = ($val && $val !== '0' && $val !== 'false') ? 1 : 0;
        }
        if (\array_key_exists('smtp_password', $fields)) {
            $password = (string) $fields['smtp_password'];
            if (trim($password) === '__clear__') {
                $write['smtp_password_enc'] = null;
            } elseif ($password !== '' && $this->cipher !== null) {
                // NOT trimmed, for the same reason as the FTP password: a
                // provider-generated app password may carry spaces, and
                // silently changing it is an unexplainable auth failure.
                $write['smtp_password_enc'] = $this->cipher->encrypt($password);
            }
        }

        // Spam guard. `spam_mode` is allowlisted rather than coerced, because
        // a typo silently becoming "off" would turn the guard off on a site
        // whose owner believes they just turned it on.
        if (\array_key_exists('spam_mode', $fields)) {
            $mode = strtolower(trim((string) $fields['spam_mode']));
            if (!\in_array($mode, self::SPAM_MODES, true)) {
                throw new \InvalidArgumentException('spam_mode must be one of: ' . implode(', ', self::SPAM_MODES));
            }
            $write['spam_mode'] = $mode;
        }
        if (\array_key_exists('spam_retention_days', $fields)) {
            $days = (int) $fields['spam_retention_days'];
            if ($days < 0 || $days > 3650) {
                throw new \InvalidArgumentException('spam_retention_days must be between 0 and 3650.');
            }
            $write['spam_retention_days'] = $days;
        }
        if (\array_key_exists('turnstile_site_key', $fields)) {
            // The site key is public — it ships in the page — so it is stored
            // as typed. Trimmed, because it is pasted from a dashboard and a
            // trailing newline would render a widget that never loads.
            $write['turnstile_site_key'] = mb_substr(trim((string) $fields['turnstile_site_key']), 0, 200);
        }
        if (\array_key_exists('turnstile_secret', $fields)) {
            $secret = trim((string) $fields['turnstile_secret']);
            if ($secret === '__clear__') {
                $write['turnstile_secret_enc'] = null;
            } elseif ($secret !== '' && $this->cipher !== null) {
                $write['turnstile_secret_enc'] = $this->cipher->encrypt($secret);
            }
        }

        // Cloudflare API token for the Pages auto-setup (plaintext in →
        // encrypted at rest; same blank-keeps / __clear__-wipes semantics as
        // the ShortPixel key below).
        if (\array_key_exists('cf_api_token', $fields)) {
            $token = trim((string) $fields['cf_api_token']);
            if ($token === '__clear__') {
                $write['cf_api_token_enc'] = null;
                $write['cf_account_id'] = null;
            } elseif ($token !== '' && $this->cipher !== null) {
                $write['cf_api_token_enc'] = $this->cipher->encrypt($token);
            }
        }
        if (\array_key_exists('cf_account_id', $fields)) {
            $val = trim((string) $fields['cf_account_id']);
            if ($val === '') {
                $write['cf_account_id'] = null;
            } elseif (preg_match('/\b([0-9a-f]{32})\b/i', $val, $m) === 1) {
                // Forgiving: a bare id or a pasted dashboard URL
                // (dash.cloudflare.com/<id>/...) both work.
                $write['cf_account_id'] = strtolower($m[1]);
            } else {
                throw new \InvalidArgumentException('That does not look like a Cloudflare account ID (32 hex characters) — copy it from any zone\'s Overview sidebar or the dashboard URL.');
            }
        }

        // Per-site ShortPixel key (plaintext in → encrypted at rest). Empty clears
        // it. A sentinel is never returned, so a blank submit must not wipe an
        // existing key: only act when the key is explicitly present AND non-blank,
        // or explicitly cleared via the '__clear__' marker.
        if (\array_key_exists('shortpixel_key', $fields)) {
            $key = trim((string) $fields['shortpixel_key']);
            if ($key === '__clear__') {
                $write['shortpixel_key_enc'] = null;
            } elseif ($key !== '' && $this->cipher !== null) {
                $write['shortpixel_key_enc'] = $this->cipher->encrypt($key);
            }
        }

        // ONE knob for how hard images are compressed: drives both the
        // ShortPixel mode and the sharp variant quality presets.
        if (\array_key_exists('image_compression', $fields)) {
            $level = strtolower(trim((string) $fields['image_compression']));
            if (!\in_array($level, ['lossy', 'glossy', 'lossless'], true)) {
                throw new \InvalidArgumentException('image_compression must be lossy, glossy, or lossless.');
            }
            $write['image_compression'] = $level;
        }

        if (\array_key_exists('fact_check_enabled', $fields)) {
            $val = $fields['fact_check_enabled'];
            $write['fact_check_enabled'] = ($val && $val !== '0' && $val !== 'false') ? 1 : 0;
        }

        // Site-wide content trust level. Off = every ingest path stores bodies
        // VERBATIM by default and translation keeps them that way, for a
        // trusted port whose pages ship their own scripts/interactive markup.
        if (\array_key_exists('sanitize_content', $fields)) {
            $val = $fields['sanitize_content'];
            $write['sanitize_content'] = ($val && $val !== '0' && $val !== 'false') ? 1 : 0;
        }

        // GTM CTA tracking: whether the build annotates every link/button
        // with the data-cta* attributes. Default on; the attributes are inert.
        if (\array_key_exists('cta_tracking', $fields)) {
            $val = $fields['cta_tracking'];
            $write['cta_tracking'] = ($val && $val !== '0' && $val !== 'false') ? 1 : 0;
        }

        // Development mode. On = every published page ships noindex, nofollow.
        // Flipping it is a build-output change (SiteService::outputSignature),
        // so turning it off republishes the whole site without the tag — going
        // live is this one switch, not a separate remember-to-rebuild step.
        if (\array_key_exists('development', $fields)) {
            $val = $fields['development'];
            $write['development'] = ($val && $val !== '0' && $val !== 'false') ? 1 : 0;
        }

        // Publish the whole media library, not just what pages embed. Also a
        // build-output change, so flipping it republishes — which is the
        // point: republishing is the ONLY thing that gets an unreferenced
        // file onto the CDN, and the user flipping this is asking for exactly
        // that.
        if (\array_key_exists('publish_all_media', $fields)) {
            $val = $fields['publish_all_media'];
            $write['publish_all_media'] = ($val && $val !== '0' && $val !== 'false') ? 1 : 0;
        }

        // Translation throughput. Concurrency is per site because the ceiling
        // is the site's OWN AI account — one customer's backlog must never
        // spend another's rate limit. Both are clamped rather than rejected:
        // these are performance dials, and a silly number should be pulled
        // back to a working one, not fail the whole settings save.
        if (\array_key_exists('translate_concurrency', $fields)) {
            $write['translate_concurrency'] = max(1, min(16, (int) $fields['translate_concurrency']));
        }
        if (\array_key_exists('translate_chunk_size', $fields)) {
            $write['translate_chunk_size'] = max(1, min(200, (int) $fields['translate_chunk_size']));
        }

        // SEO title composition: whether every engine-composed <title> gets
        // the site-name suffix, and which character joins them (Site::pageTitle).
        if (\array_key_exists('seo_title_suffix', $fields)) {
            $val = $fields['seo_title_suffix'];
            $write['seo_title_suffix'] = ($val && $val !== '0' && $val !== 'false') ? 1 : 0;
        }
        if (\array_key_exists('seo_title_separator', $fields)) {
            $sep = trim((string) $fields['seo_title_separator']);
            if (!\in_array($sep, self::TITLE_SEPARATORS, true)) {
                throw new \InvalidArgumentException('Title separator must be one of: ' . implode(' ', self::TITLE_SEPARATORS));
            }
            $write['seo_title_separator'] = $sep;
        }

        // Schema.org structured data. The graph ships on every page by default;
        // the toggle exists for a site whose shell emits its own markup.
        if (\array_key_exists('structured_data', $fields)) {
            $val = $fields['structured_data'];
            $write['structured_data'] = ($val && $val !== '0' && $val !== 'false') ? 1 : 0;
        }
        if (\array_key_exists('schema_type', $fields)) {
            $type = trim((string) $fields['schema_type']);
            if (!\in_array($type, self::SCHEMA_TYPES, true)) {
                throw new \InvalidArgumentException('schema_type must be one of: ' . implode(', ', self::SCHEMA_TYPES));
            }
            $write['schema_type'] = $type;
        }
        // sameAs accepts a JSON/PHP list (API, MCP) or the newline- or
        // comma-separated text a textarea posts — one storage shape, several
        // honest input shapes. Only absolute http(s) URLs survive: a relative
        // path or a bare handle identifies nothing to a search engine.
        if (\array_key_exists('schema_same_as', $fields)) {
            $raw = $fields['schema_same_as'];
            $candidates = \is_array($raw)
                ? $raw
                : (preg_split('/[\s,]+/', (string) $raw) ?: []);
            $urls = [];
            foreach ($candidates as $candidate) {
                $url = trim((string) $candidate);
                if ($url === '') {
                    continue;
                }
                if (!preg_match('#^https?://[^\s/?\#]+#i', $url)) {
                    throw new \InvalidArgumentException('Profile URLs must be absolute http(s) URLs — got: ' . $url);
                }
                $urls[$url] = true;
            }
            $urls = array_slice(array_keys($urls), 0, self::SAME_AS_MAX);
            $write['schema_same_as'] = json_encode(array_values($urls), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        // Permalinks. Validated rather than clamped: a malformed pattern would
        // put every post at a URL nobody chose, and the redirects recorded on
        // a change would point there too.
        if (\array_key_exists('post_path_pattern', $fields)) {
            $write['post_path_pattern'] = self::validatePattern((string) $fields['post_path_pattern'], 'post_path_pattern', ['{slug}', '{category}']);
        }
        if (\array_key_exists('category_path_pattern', $fields)) {
            $pattern = self::validatePattern((string) $fields['category_path_pattern'], 'category_path_pattern', ['{slug}']);
            // A bare `/{slug}/` would put every archive in the same namespace
            // as the site's pages, and the first category named like a page
            // would silently lose to it.
            if (trim(str_replace('{slug}', '', $pattern), '/') === '') {
                throw new \InvalidArgumentException('category_path_pattern needs a fixed segment, e.g. /category/{slug}/');
            }
            $write['category_path_pattern'] = $pattern;
        }
        if (\array_key_exists('archive_per_page', $fields)) {
            $write['archive_per_page'] = max(1, min(100, (int) $fields['archive_per_page']));
        }

        // Per-site image-size preferences (clamped to sane bounds).
        if (\array_key_exists('image_max_width', $fields)) {
            $w = (int) $fields['image_max_width'];
            $write['image_max_width'] = max(480, min(4096, $w > 0 ? $w : 2048));
        }
        if (\array_key_exists('logo_max_width', $fields)) {
            $w = (int) $fields['logo_max_width'];
            $write['logo_max_width'] = max(64, min(1024, $w > 0 ? $w : 400));
        }

        if ($write === []) {
            return $site;
        }

        $repoChanged = isset($write['github_repo']) && $write['github_repo'] !== $site->githubRepo;
        $write['updated_at'] = $this->clock->iso();
        $this->sites->update($site->id, $write);

        if ($repoChanged) {
            $this->resetRepoClone($handle);
        }

        $this->logger->info('Site updated', [
            'handle' => $handle,
            'fields' => array_keys($write),
            'repo_reset' => $repoChanged,
        ]);

        $fresh = $this->sites->findByHandle($handle);
        \assert($fresh !== null);

        // Every live URL a permalink change moved gets a 301, exactly as a
        // slug edit does. After the write, so the redirects are computed from
        // the stored values rather than from the raw input.
        if ($this->permalinks !== null
            && ($site->postPathPattern !== $fresh->postPathPattern
                || $site->categoryPathPattern !== $fresh->categoryPathPattern
                || $site->archivePerPage !== $fresh->archivePerPage)
        ) {
            $moved = $this->permalinks->siteChanged($site, $fresh);
            $this->logger->info('Permalinks changed', ['handle' => $handle, 'redirects' => $moved]);
        }

        return $fresh;
    }

    /**
     * A URL pattern: starts and ends with `/`, holds `{slug}` exactly once,
     * no token outside $tokens, and otherwise only lowercase URL-safe segment
     * characters. `{category}` at most once.
     *
     * @param list<string> $tokens
     */
    public static function validatePattern(string $pattern, string $field, array $tokens): string
    {
        $pattern = trim($pattern);
        if ($pattern === '' || $pattern[0] !== '/' || !str_ends_with($pattern, '/')) {
            throw new \InvalidArgumentException($field . ' must start and end with "/", e.g. /blog/{slug}/');
        }
        if (substr_count($pattern, '{slug}') !== 1) {
            throw new \InvalidArgumentException($field . ' must contain {slug} exactly once.');
        }
        if (substr_count($pattern, '{category}') > 1) {
            throw new \InvalidArgumentException($field . ' may contain {category} at most once.');
        }
        $rest = str_replace($tokens, '', $pattern);
        if (preg_match('#^[a-z0-9\-/]*$#', $rest) !== 1 || str_contains($pattern, '//')) {
            throw new \InvalidArgumentException($field . ' may only use lowercase letters, digits, "-", "/" and the tokens ' . implode(' ', $tokens) . '.');
        }

        return $pattern;
    }

    /**
     * Fingerprint of every site-row setting the BUILD bakes into its output.
     *
     * ONE list, used by two things that must never disagree: "did this change
     * need a republish?" and "is the preview tree stale?". They used to be
     * separate lists, and the preview one was missing almost all of it — so
     * saving a setting republished in the background while preview kept serving
     * the pre-change tree, and the only way to see the change was a throwaway
     * shell edit to bump theme_version. Add a build-affecting column here and
     * both surfaces pick it up.
     *
     * Still deliberately outside it: `redirects` rows. They only reach
     * `_redirects`, which preview does not act on.
     */
    public static function outputSignature(Site $site): string
    {
        return md5(implode("\x00", [
            // Canonical/OG/sitemap/llms.txt URLs and the robots banner.
            $site->baseUrl,
            $site->canonicalHost,
            $site->name,
            // Branding: favicon, logos, the share card.
            (string) $site->faviconMediaId,
            (string) $site->logoHeaderMediaId,
            (string) $site->logoFooterMediaId,
            (string) $site->socialImageMediaId,
            $site->metaDescription,
            // Title composition, in every page <title>/og:title.
            $site->seoTitleSuffix ? '1' : '0',
            $site->seoTitleSeparator,
            // CTA attributes, on every link/button of every page.
            $site->ctaTracking ? '1' : '0',
            // The JSON-LD graph, in every page's <head>.
            $site->structuredData ? '1' : '0',
            $site->schemaType,
            implode("\x1f", $site->sameAs),
            // `ftp` sites additionally emit a generated .htaccess, so the mode
            // genuinely changes the OUTPUT and not merely its destination.
            // Leaving it out would recreate the documented preview bug: the
            // staleness signature must name every input that changes the build,
            // or preview keeps serving a tree the settings no longer describe.
            $site->serveMode,
            // Development mode puts noindex/nofollow on every page and strips
            // the sitemap pointers out of robots.txt. Nothing changes a build
            // more completely, so preview must never keep serving the tree
            // from the other side of the switch.
            $site->development ? '1' : '0',
            // Decides WHICH media and files land in dist, so it changes the
            // built tree even though no page's HTML moves a byte.
            $site->publishAllMedia ? '1' : '0',
            // Permalinks: every post URL, every archive URL, every canonical.
            $site->postPathPattern,
            $site->categoryPathPattern,
            (string) $site->archivePerPage,
        ]));
    }

    /** Whether a change needs a rebuild+publish. */
    public static function changeNeedsRebuild(Site $before, Site $after): bool
    {
        return self::outputSignature($before) !== self::outputSignature($after);
    }

    /**
     * Soft-delete (UI-only trash): the site drops out of every dashboard read
     * but keeps its DB rows + on-disk tree, so it can be recovered.
     */
    public function softDelete(string $handle): void
    {
        $site = $this->sites->findAnyByHandle($handle)
            ?? throw new \RuntimeException('Unknown site: ' . $handle);
        if ($site->deletedAt !== null) {
            return;
        }
        $this->sites->softDelete($site->id);
        $this->logger->info('Site moved to trash', ['handle' => $handle]);
    }

    /** Recover a soft-deleted site. */
    public function restore(string $handle): void
    {
        $site = $this->sites->findAnyByHandle($handle)
            ?? throw new \RuntimeException('Unknown site: ' . $handle);
        $this->sites->restore($site->id);
        $this->logger->info('Site restored from trash', ['handle' => $handle]);
    }

    /**
     * Permanent delete: removes the registry row (site_users cascades) and the
     * entire on-disk tree. Only a trashed site may be purged (guard against
     * nuking a live site). Irreversible.
     */
    public function purge(string $handle): void
    {
        $site = $this->sites->findAnyByHandle($handle)
            ?? throw new \RuntimeException('Unknown site: ' . $handle);
        if ($site->deletedAt === null) {
            throw new \RuntimeException('Move the site to trash before deleting it permanently.');
        }
        // Release the cached DB handle first — an open connection blocks the
        // directory removal on Windows.
        $this->databases->closeSite($handle);
        $this->removeTree($this->databases->sitePath($handle));
        $this->sites->purge($site->id);
        $this->logger->warning('Site permanently deleted', ['handle' => $handle]);
    }

    /**
     * Removes the dist clone so the next publish clones the new remote.
     * The build output is regenerated, so nothing is lost.
     */
    private function resetRepoClone(string $handle): void
    {
        $this->removeTree($this->databases->sitePath($handle) . '/repo');
    }

    /** Recursively delete a directory, chmod-ing read-only .git objects first. */
    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            @chmod($item->getPathname(), 0777); // .git objects are read-only
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }

    /**
     * Strips a trailing slash and a leading `www.` host label so a site's
     * canonical origin is always the apex (no www. duplicate content).
     */
    public static function normalizeBaseUrl(string $baseUrl): string
    {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '') {
            return '';
        }
        $host = parse_url($baseUrl, PHP_URL_HOST);
        if (\is_string($host)) {
            if (str_starts_with(strtolower($host), 'www.')) {
                $apex = substr($host, 4);
                // Replace only the host segment, preserving scheme/port/path.
                $baseUrl = preg_replace('/' . preg_quote($host, '/') . '/', $apex, $baseUrl, 1) ?? $baseUrl;
            }

            return $baseUrl;
        }

        // No scheme: parse_url puts the host in the path. Strip a leading www.
        if (preg_match('/^www\./i', $baseUrl) === 1) {
            $baseUrl = substr($baseUrl, 4);
        }

        return $baseUrl;
    }

    /**
     * Adds a locale to a site (idempotent). Returns the new locale list.
     * The caller enqueues translate_content to populate it.
     *
     * @return list<string>
     */
    public function addLocale(string $handle, string $locale): array
    {
        $site = $this->sites->findByHandle($handle);
        if ($site === null) {
            throw new \RuntimeException('Unknown site: ' . $handle);
        }
        $locale = strtolower(trim($locale));
        if (preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $locale) !== 1) {
            throw new \InvalidArgumentException('Invalid locale code: ' . $locale);
        }
        if ($locale === $site->primaryLocale) {
            throw new \InvalidArgumentException('That is already the primary locale.');
        }

        if (\in_array($locale, $site->locales, true)) {
            return $site->locales;
        }

        $locales = [...$site->locales, $locale];
        $this->sites->update($site->id, ['locales' => json_encode($locales, JSON_THROW_ON_ERROR)]);

        return $locales;
    }

    /**
     * Removes a locale from a site and deletes ALL translated artifacts for it:
     * content_translations + string_translations rows, and the generated
     * shell/i18n/{locale}.json. The caller enqueues build_full→publish_git so
     * the published /{locale}/ tree disappears. Idempotent. Returns the new
     * locale list. The primary locale can never be removed.
     *
     * @return list<string>
     */
    public function removeLocale(string $handle, string $locale): array
    {
        $site = $this->sites->findByHandle($handle)
            ?? throw new \RuntimeException('Unknown site: ' . $handle);

        $locale = strtolower(trim($locale));
        if ($locale === $site->primaryLocale) {
            throw new \InvalidArgumentException('Cannot remove the primary locale.');
        }
        if (!\in_array($locale, $site->locales, true)) {
            return $site->locales; // already gone
        }

        $locales = array_values(array_filter($site->locales, static fn (string $l): bool => $l !== $locale));
        $this->sites->update($site->id, ['locales' => json_encode($locales, JSON_THROW_ON_ERROR)]);

        // Delete every translated artifact for the removed locale.
        $db = $this->databases->site($handle);
        foreach (['content_translations', 'string_translations'] as $table) {
            try {
                $db->executeStatement('DELETE FROM ' . $table . ' WHERE locale = ?', [$locale]);
            } catch (\Throwable) {
                // Table may be absent on an older site DB — nothing to clean.
            }
        }
        $i18n = $this->databases->sitePath($handle) . '/shell/i18n/' . $locale . '.json';
        if (is_file($i18n)) {
            @unlink($i18n);
        }

        $this->logger->info('Locale removed', ['handle' => $handle, 'locale' => $locale, 'locales' => $locales]);

        return $locales;
    }

    /**
     * Applies pending site migrations to every site (or one). Returns
     * handle => applied versions.
     *
     * @return array<string, list<string>>
     */
    public function migrateAll(?string $onlyHandle = null): array
    {
        $result = [];
        foreach ($this->sites->all() as $site) {
            if ($onlyHandle !== null && $site->handle !== $onlyHandle) {
                continue;
            }
            $result[$site->handle] = $this->migrator->migrate(
                $this->databases->site($site->handle),
                $this->appRoot . '/migrations/site',
            );
        }

        return $result;
    }

    /**
     * Copies the default shell into the site dir. Never overwrites — a shell
     * an agent has customised is site property.
     */
    private function scaffoldShell(string $handle): void
    {
        $source = $this->appRoot . '/templates/shell-default';
        $target = $this->databases->sitePath($handle) . '/shell';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);
            $relative = substr($item->getPathname(), \strlen($source) + 1);
            $dest = $target . '/' . str_replace('\\', '/', $relative);

            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0770, true);
                }
                continue;
            }

            if (!is_file($dest)) {
                $dir = \dirname($dest);
                if (!is_dir($dir)) {
                    mkdir($dir, 0770, true);
                }
                copy($item->getPathname(), $dest);
            }
        }
    }
}
