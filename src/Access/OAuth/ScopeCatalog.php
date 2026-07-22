<?php

declare(strict_types=1);

namespace Fastsite\Access\OAuth;

/**
 * Human-readable descriptions of each scope for the consent screen.
 */
final class ScopeCatalog
{
    private const DESCRIPTIONS = [
        'sites:read' => 'See your sites and their settings',
        'sites:write' => 'Change site settings (branding, base URL, repo, redirects, scripts)',
        'content:read' => 'Read your posts and pages',
        'content:write' => 'Create and edit posts and pages',
        'media:read' => 'View your media library',
        'media:write' => 'Upload images, video, and files',
        'components:write' => 'Create and update components',
        'shell:read' => 'Read your site templates and theme',
        'shell:write' => 'Design your sites (edit templates and theme)',
        'translations:manage' => 'Manage translations and languages',
        'publish' => 'Publish your sites',
        'jobs:read' => 'View build and job status',
        'backup:read' => 'View backups',
        'backup:run' => 'Create backups',
        'backup:restore' => 'Restore backups',
        'admin' => 'Full administrative access to everything',
    ];

    /**
     * The full scope→description map, in display order (for the key-create
     * multiselect and the consent screen).
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::DESCRIPTIONS;
    }

    /**
     * @param list<string> $scopes
     * @return list<string>
     */
    public static function describe(array $scopes): array
    {
        return array_values(array_map(
            static fn (string $s): string => self::DESCRIPTIONS[$s] ?? $s,
            $scopes,
        ));
    }
}
