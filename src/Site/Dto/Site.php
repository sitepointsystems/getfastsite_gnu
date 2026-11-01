<?php

declare(strict_types=1);

namespace Fastsite\Site\Dto;

final readonly class Site
{
    /**
     * @param list<string> $locales additional locales (primary excluded)
     */
    public function __construct(
        public int $id,
        public string $handle,
        public string $name,
        public string $primaryDomain,
        public string $baseUrl,
        public string $primaryLocale,
        public array $locales,
        public string $postPathPattern,
        public string $pagePathPattern,
        public string $githubRepo,
        public string $githubBranch,
        public string $mediaPublicBase,
        public int $themeVersion,
        public string $status,
        public ?int $faviconMediaId = null,
        public ?int $logoHeaderMediaId = null,
        public ?int $logoFooterMediaId = null,
        public string $canonicalHost = 'apex',
        public ?int $githubCredentialId = null,
        public ?int $teamId = null,
        public ?string $shortpixelKeyEnc = null,
        public string $imageCompression = 'lossy',
        public bool $factCheckEnabled = false,
        public ?int $aiCredentialId = null,
        public ?string $deletedAt = null,
        public int $imageMaxWidth = 2048,
        public int $logoMaxWidth = 400,
        public string $metaDescription = '',
        public ?int $socialImageMediaId = null,
        public string $serveMode = 'git',
        public ?string $cfApiTokenEnc = null,
        public ?string $cfAccountId = null,
        public ?int $workspaceId = null,
        public bool $seoTitleSuffix = true,
        public string $seoTitleSeparator = '—',
        public bool $sanitizeContent = true,
        /**
         * GTM/analytics CTA attributes: the build annotates every link/button
         * with data-cta / data-cta-text / data-cta-language / data-cta-location
         * (CtaAnnotator). Inert markup — the zero-JS contract is untouched.
         */
        public bool $ctaTracking = true,
        /**
         * How many translation calls this site may keep in flight at once.
         * 1 = the old strictly-serial behaviour. Per site because the ceiling
         * is the site's own AI account: one customer's rate limit must not be
         * spent by another's backlog.
         */
        public int $translateConcurrency = 4,
        /** Translation items (content × locale) attempted per queued chunk. */
        public int $translateChunkSize = 10,
        /**
         * Schema.org JSON-LD: every page ships one @graph describing the site
         * entity, the page, its breadcrumb and (for posts) the article. Default
         * on — the kill switch exists for a site whose shell ships its own.
         */
        public bool $structuredData = true,
        /**
         * What the site IS as an entity: 'Organization' or 'Person'. A personal
         * brand is a Person, and Google resolves the two differently.
         */
        public string $schemaType = 'Organization',
        /**
         * Profile URLs that identify the same entity elsewhere (LinkedIn, X,
         * GitHub, Crunchbase…) — schema.org `sameAs`. This is what actually
         * drives entity resolution; a name and a URL alone rarely do.
         *
         * @var list<string>
         */
        public array $sameAs = [],
        /**
         * Where an `ftp` serve_mode site is published to: the customer's OWN
         * web host. Non-secrets stay plaintext so the settings screen can show
         * the target without decrypting anything; the password and the SSH key
         * are TokenCipher-encrypted (*_enc), like cf_api_token_enc.
         */
        public string $ftpProtocol = 'sftp',
        public string $ftpHost = '',
        /** 0 = the protocol's default port (21 for ftp/ftps, 22 for sftp). */
        public int $ftpPort = 0,
        public string $ftpUsername = '',
        public ?string $ftpPasswordEnc = null,
        public ?string $ftpKeyEnc = null,
        public string $ftpRemotePath = '',
        /**
         * Hash of the whole target tuple. When it changes the site points at a
         * different server, so the remote manifest describes a host we no longer
         * talk to and must be discarded rather than used to "delete orphans"
         * on a machine where those paths were never ours.
         */
        public string $ftpTargetKey = '',
        /**
         * Verify the FTPS certificate. ON by default and worth keeping on —
         * the password rides that connection. Turned off per site for a host
         * whose certificate is self-signed or issued for its own IP, which no
         * hostname can match and which describes most stock FTP servers.
         */
        public bool $ftpVerifyCert = true,
        /**
         * Development mode: every page this site publishes carries
         * `noindex, nofollow` — as a head <meta> on each page AND as an
         * X-Robots-Tag response header, which is what covers the PDFs and
         * images a meta tag cannot reach. OFF by default — a site nobody
         * remembers to un-hide is a launch that silently never happened, so
         * hiding is the decision someone has to be present for.
         *
         * Note what this deliberately does NOT do: `Disallow: /` in robots.txt.
         * Blocking the crawl means the crawler never SEES the noindex, and a
         * URL discovered from any external link still lands in the results as
         * a bare listing. To be absent from search you have to let them in and
         * then tell them not to index.
         */
        public bool $development = false,
        /**
         * Publish the WHOLE media library, not just what pages embed.
         *
         * Media ships referenced-only by default, which is right for page
         * weight and for the Cloudflare Pages file cap — but it means an item
         * nothing embeds has no public URL at all, and that is exactly the one
         * someone wants a link for (an email, an ad, another property).
         * Republishing never fixes that, because the build never copied the
         * file; only this does.
         *
         * Covers the Files library too: the same surprise with a PDF is the
         * same surprise.
         */
        public bool $publishAllMedia = false,
        /**
         * The account this site's form mail is relayed THROUGH. A published
         * site is static and can send nothing itself; the form posts to the
         * engine and the engine relays through these credentials.
         *
         * Per site, not per engine, because each customer sends as themselves:
         * mail leaving their own server keeps their SPF/DKIM alignment, and no
         * customer's leads ride on anyone else's sending reputation.
         *
         * smtp_from_email is deliberately NOT the visitor's address — almost
         * every provider rejects or silently SPF-fails a From it did not
         * authenticate. The visitor goes in Reply-To (see FormMailer).
         */
        public string $smtpHost = '',
        /** 0 = the default for the chosen encryption (25 / 465 / 587). */
        public int $smtpPort = 0,
        public string $smtpEncryption = 'starttls',
        public string $smtpUsername = '',
        public ?string $smtpPasswordEnc = null,
        public string $smtpFromEmail = '',
        public string $smtpFromName = '',
        /**
         * Verify the SMTP server's certificate. ON by default and worth
         * keeping on — the account password rides that connection. The per-site
         * opt-out exists for the same reason as ftpVerifyCert: a stock mail box
         * ships a self-signed certificate no hostname can match, and refusing
         * outright makes a large slice of real shared hosting unusable.
         */
        public bool $smtpVerifyCert = true,
        /**
         * How hard the content scorer is allowed to act on what it finds:
         * `off` (score nothing), `log` (score and record, notify anyway) or
         * `on` (hold anything flagged in the spam folder).
         *
         * `log` is why this is a string and not a bool. A filter that starts
         * blocking the day it ships is a filter whose first false positive is
         * a lead nobody ever knew about; log mode lets a site see what WOULD
         * have been held before it is allowed to hold anything.
         */
        public string $spamMode = 'on',
        /**
         * Days a flagged submission is kept before it deletes itself. Keeps
         * the spam folder from becoming another thing nobody empties, without
         * making a scoring bug unrecoverable. 0 = delete on sight.
         */
        public int $spamRetentionDays = 30,
        /**
         * Cloudflare Turnstile. The site key is PUBLIC by design — it ships in
         * the rendered page — so it is stored in plain text; the secret
         * follows the smtp/ftp password convention and is encrypted at rest.
         *
         * Turnstile is the optional second layer, not the first: it costs the
         * page JavaScript and flips its CSP, and it protects only the markup
         * the engine generates. The content scorer runs regardless, on every
         * path into the endpoint including the no-JS submit and bespoke forms
         * that post directly.
         */
        public string $turnstileSiteKey = '',
        public ?string $turnstileSecretEnc = null,
        /**
         * Where a category archive lives. `{slug}` is the category slug; page
         * 2 onward appends `page/{n}/`, the WordPress shape. Default
         * `/category/{slug}/`, WordPress's own, so a ported site keeps its
         * archive URLs without a redirect.
         */
        public string $categoryPathPattern = '/category/{slug}/',
        /** Posts per category archive page (WordPress defaults to 10). */
        public int $archivePerPage = 10,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        /** @var list<string> $locales */
        $locales = json_decode((string) $row['locales'], true) ?: [];
        /** @var list<string> $sameAs */
        $sameAs = array_values(array_filter(
            (array) (json_decode((string) ($row['schema_same_as'] ?? '[]'), true) ?: []),
            static fn (mixed $url): bool => \is_string($url) && $url !== '',
        ));

        return new self(
            id: (int) $row['id'],
            handle: (string) $row['handle'],
            name: (string) $row['name'],
            primaryDomain: (string) $row['primary_domain'],
            baseUrl: rtrim((string) $row['base_url'], '/'),
            primaryLocale: (string) $row['primary_locale'],
            locales: $locales,
            postPathPattern: (string) $row['post_path_pattern'],
            pagePathPattern: (string) $row['page_path_pattern'],
            githubRepo: (string) $row['github_repo'],
            githubBranch: (string) $row['github_branch'],
            mediaPublicBase: (string) $row['media_public_base'],
            themeVersion: (int) $row['theme_version'],
            status: (string) $row['status'],
            faviconMediaId: ($row['favicon_media_id'] ?? null) !== null ? (int) $row['favicon_media_id'] : null,
            logoHeaderMediaId: ($row['logo_header_media_id'] ?? null) !== null ? (int) $row['logo_header_media_id'] : null,
            logoFooterMediaId: ($row['logo_footer_media_id'] ?? null) !== null ? (int) $row['logo_footer_media_id'] : null,
            canonicalHost: (string) ($row['canonical_host'] ?? 'apex'),
            githubCredentialId: ($row['github_credential_id'] ?? null) !== null ? (int) $row['github_credential_id'] : null,
            teamId: ($row['team_id'] ?? null) !== null ? (int) $row['team_id'] : null,
            shortpixelKeyEnc: ($row['shortpixel_key_enc'] ?? null) !== null ? (string) $row['shortpixel_key_enc'] : null,
            imageCompression: (string) ($row['image_compression'] ?? 'lossy'),
            factCheckEnabled: (bool) ($row['fact_check_enabled'] ?? false),
            aiCredentialId: ($row['ai_credential_id'] ?? null) !== null ? (int) $row['ai_credential_id'] : null,
            deletedAt: ($row['deleted_at'] ?? null) !== null ? (string) $row['deleted_at'] : null,
            imageMaxWidth: (int) ($row['image_max_width'] ?? 2048) ?: 2048,
            logoMaxWidth: (int) ($row['logo_max_width'] ?? 400) ?: 400,
            metaDescription: (string) ($row['meta_description'] ?? ''),
            socialImageMediaId: ($row['social_image_media_id'] ?? null) !== null ? (int) $row['social_image_media_id'] : null,
            serveMode: (string) ($row['serve_mode'] ?? 'git'),
            cfApiTokenEnc: ($row['cf_api_token_enc'] ?? null) !== null ? (string) $row['cf_api_token_enc'] : null,
            cfAccountId: ($row['cf_account_id'] ?? null) !== null ? (string) $row['cf_account_id'] : null,
            workspaceId: ($row['workspace_id'] ?? null) !== null ? (int) $row['workspace_id'] : null,
            seoTitleSuffix: (bool) ($row['seo_title_suffix'] ?? true),
            seoTitleSeparator: (string) ($row['seo_title_separator'] ?? '—') ?: '—',
            sanitizeContent: (bool) ($row['sanitize_content'] ?? true),
            ctaTracking: (bool) ($row['cta_tracking'] ?? true),
            translateConcurrency: (int) ($row['translate_concurrency'] ?? 4) ?: 4,
            translateChunkSize: (int) ($row['translate_chunk_size'] ?? 10) ?: 10,
            structuredData: (bool) ($row['structured_data'] ?? true),
            schemaType: (string) ($row['schema_type'] ?? 'Organization') ?: 'Organization',
            sameAs: $sameAs,
            ftpProtocol: (string) ($row['ftp_protocol'] ?? 'sftp') ?: 'sftp',
            ftpHost: (string) ($row['ftp_host'] ?? ''),
            ftpPort: (int) ($row['ftp_port'] ?? 0),
            ftpUsername: (string) ($row['ftp_username'] ?? ''),
            ftpPasswordEnc: ($row['ftp_password_enc'] ?? null) !== null ? (string) $row['ftp_password_enc'] : null,
            ftpKeyEnc: ($row['ftp_key_enc'] ?? null) !== null ? (string) $row['ftp_key_enc'] : null,
            ftpRemotePath: (string) ($row['ftp_remote_path'] ?? ''),
            ftpTargetKey: (string) ($row['ftp_target_key'] ?? ''),
            ftpVerifyCert: (bool) ($row['ftp_verify_cert'] ?? true),
            development: (bool) ($row['development'] ?? false),
            publishAllMedia: (bool) ($row['publish_all_media'] ?? false),
            smtpHost: (string) ($row['smtp_host'] ?? ''),
            smtpPort: (int) ($row['smtp_port'] ?? 0),
            smtpEncryption: (string) ($row['smtp_encryption'] ?? 'starttls') ?: 'starttls',
            smtpUsername: (string) ($row['smtp_username'] ?? ''),
            smtpPasswordEnc: ($row['smtp_password_enc'] ?? null) !== null ? (string) $row['smtp_password_enc'] : null,
            smtpFromEmail: (string) ($row['smtp_from_email'] ?? ''),
            smtpFromName: (string) ($row['smtp_from_name'] ?? ''),
            smtpVerifyCert: (bool) ($row['smtp_verify_cert'] ?? true),
            spamMode: (string) ($row['spam_mode'] ?? 'on') ?: 'on',
            spamRetentionDays: (int) ($row['spam_retention_days'] ?? 30),
            turnstileSiteKey: (string) ($row['turnstile_site_key'] ?? ''),
            turnstileSecretEnc: ($row['turnstile_secret_enc'] ?? null) !== null
                ? (string) $row['turnstile_secret_enc']
                : null,
            categoryPathPattern: (string) ($row['category_path_pattern'] ?? '/category/{slug}/') ?: '/category/{slug}/',
            archivePerPage: max(1, (int) ($row['archive_per_page'] ?? 10)),
        );
    }

    /**
     * Whether a form on this site can present a Turnstile challenge. BOTH
     * halves are required: a site key with no secret renders a widget the
     * engine can never verify, which is worse than no widget at all — it looks
     * protected and is not.
     */
    public function turnstileConfigured(): bool
    {
        return $this->turnstileSiteKey !== '' && ($this->turnstileSecretEnc ?? '') !== '';
    }

    /**
     * Whether this site can attempt to send at all. The password is NOT part
     * of it — some relays authorise by IP, and a missing credential has to
     * surface as the server's own authentication error rather than as a silent
     * "not configured" skip that looks exactly like a form nobody submitted.
     */
    public function smtpConfigured(): bool
    {
        return $this->smtpHost !== '' && $this->smtpFromEmail !== '';
    }

    /**
     * Whether this site has enough of a target to attempt a publish. A password
     * or key is NOT required here — some hosts accept anonymous/keyless SFTP
     * agents, and a missing credential must surface as the transport's own
     * authentication error rather than as a silent "not configured" skip.
     */
    public function ftpConfigured(): bool
    {
        return $this->ftpHost !== '' && $this->ftpUsername !== '';
    }

    /**
     * The origin EVERY absolute URL the build emits must use — canonical, OG,
     * sitemaps, llms.txt, robots. base_url is always stored apex-normalized
     * (SiteService::normalizeBaseUrl); when the operator picked `www` as the
     * canonical host, the www. label goes back on here. Going through one
     * accessor is what keeps the host-level 301 and the <link rel=canonical>
     * from ever pointing at different hosts.
     */
    public function canonicalBaseUrl(): string
    {
        if ($this->baseUrl === '' || $this->canonicalHost !== 'www') {
            return $this->baseUrl;
        }
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        if (!\is_string($host) || $host === '' || str_starts_with(strtolower($host), 'www.')) {
            return $this->baseUrl;
        }

        return (string) preg_replace('/' . preg_quote($host, '/') . '/', 'www.' . $host, $this->baseUrl, 1);
    }

    /**
     * A rendered page's <title>/og:title: the page title plus the site-name
     * suffix ("My post — Site"), unless the SEO settings turned the suffix
     * off. Every engine-composed title goes through here so the toggle and
     * the separator choice apply uniformly; a content row's own seo_title
     * never does (it ships exactly as written).
     */
    public function pageTitle(string $title): string
    {
        if (!$this->seoTitleSuffix || $this->name === '') {
            return $title;
        }

        return $title . ' ' . $this->seoTitleSeparator . ' ' . $this->name;
    }

    /** The host visitors should end up on: primary_domain, or its www. form. */
    public function canonicalDomain(): string
    {
        if ($this->primaryDomain === '') {
            return '';
        }

        return $this->canonicalHost === 'www' && !str_starts_with(strtolower($this->primaryDomain), 'www.')
            ? 'www.' . $this->primaryDomain
            : $this->primaryDomain;
    }
}
