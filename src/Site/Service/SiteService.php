<?php

declare(strict_types=1);

namespace Fastsite\Site\Service;

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
    ) {
    }

    public function create(
        string $handle,
        string $name,
        string $baseUrl = '',
        string $githubRepo = '',
        string $primaryLocale = 'en',
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

        return $site;
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

        if (\array_key_exists('serve_mode', $fields)) {
            $mode = (string) $fields['serve_mode'];
            if (!\in_array($mode, ['git', 'direct'], true)) {
                throw new \InvalidArgumentException('serve_mode must be "git" or "direct".');
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
            $write['serve_mode'] = $mode;
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

        if (\array_key_exists('fact_check_enabled', $fields)) {
            $val = $fields['fact_check_enabled'];
            $write['fact_check_enabled'] = ($val && $val !== '0' && $val !== 'false') ? 1 : 0;
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

        return $fresh;
    }

    /**
     * Whether a change needs a rebuild+publish: base URL and name are baked
     * into canonical/OG/sitemap/llms.txt and the robots banner.
     *
     * @param array<string, mixed> $fields
     */
    public static function changeNeedsRebuild(Site $before, Site $after): bool
    {
        return $before->baseUrl !== $after->baseUrl
            || $before->name !== $after->name
            || $before->faviconMediaId !== $after->faviconMediaId
            || $before->logoHeaderMediaId !== $after->logoHeaderMediaId
            || $before->logoFooterMediaId !== $after->logoFooterMediaId
            || $before->metaDescription !== $after->metaDescription
            || $before->socialImageMediaId !== $after->socialImageMediaId;
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
