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
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        /** @var list<string> $locales */
        $locales = json_decode((string) $row['locales'], true) ?: [];

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
        );
    }
}
