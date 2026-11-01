<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * What the PUBLIC flavour ships, and what it must not.
 *
 * `scripts/deploy-public.sh` is the ONE definition of "public" — it trims both
 * the Docker build (`FLAVOR=public`) and the GPL-3.0 source export published
 * as a scrubbed snapshot. Two trimmers would drift, and the drift would be
 * invisible until something proprietary or private appeared in a public repo,
 * which cannot be un-published.
 *
 * The assertions read the SHELL COMMANDS, not the comments, for the same
 * reason `AiRoutingTest` does: a comment is free to discuss a file it no
 * longer deletes.
 */
final class PublicFlavourTest extends TestCase
{
    public function testThePublicBuildShipsNoSites(): void
    {
        $public = $this->commands('deploy-public.sh');

        // The marketing seeder authors getfastsite.com's own pages. A product
        // must not ship its vendor's marketing copy, and a public install must
        // start with zero sites.
        self::assertStringContainsString('rm -f src/Site/Service/MarketingSeeder.php', $public);
        self::assertStringContainsString('rm -f tests/Integration/MarketingSeederTest.php', $public);
        // Used by nothing but the seeder — it is that one site's design.
        self::assertStringContainsString('rm -rf templates/shell-marketing', $public);
        // Documents how getfastsite.com itself is deployed, and names the
        // seeder — a doc describing a feature the build removed is worse
        // than no doc.
        self::assertStringContainsString('rm -f docs/domain-split.md', $public);
    }

    /**
     * The starter theme is NOT a site. SiteService installs it into every new
     * site, so trimming it would leave a blank install unable to build
     * anything — the feature would be gone, not merely empty.
     */
    public function testTheDefaultShellSurvivesBothTrimmers(): void
    {
        foreach (['deploy-public.sh', 'deploy-private.sh'] as $script) {
            self::assertStringNotContainsString(
                'templates/shell-default',
                $this->commands($script),
                $script . ' must not remove the starter theme — a new site installs it and cannot build without it',
            );
        }
    }

    /**
     * The seeder is removable only because nothing requires it. If a new hard
     * reference appears, the trimmed build fatals on boot — and the public
     * Docker image is where that would be discovered.
     */
    public function testNothingDependsOnTheSeederExceptItsOwnSurfaces(): void
    {
        $root = \dirname(__DIR__, 2);
        $allowed = [
            'src/Site/Service/MarketingSeeder.php',   // itself
            'tests/Integration/MarketingSeederTest.php', // its test (trimmed too)
            'tests/Integration/PublicFlavourTest.php',   // this file
            'src/Mcp/McpTools.php',                   // nullable dependency + omitted tool
            'config/services.php',                    // wired behind class_exists
            'bin/fastsite',                           // command guarded by class_exists
            'scripts/deploy-public.sh',               // the trimmer
            'scripts/export-public.sh',               // verifies the trim took effect
            'docs/domain-split.md',                   // site-specific doc, trimmed too
        ];

        $offenders = [];
        foreach ($this->trackedFiles($root) as $rel) {
            if (\in_array($rel, $allowed, true)) {
                continue;
            }
            $path = $root . '/' . $rel;
            if (!is_file($path)) {
                continue;
            }
            $raw = (string) file_get_contents($path);
            if (str_contains($raw, 'MarketingSeeder') || str_contains($raw, 'shell-marketing')) {
                $offenders[] = $rel;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'these reference the marketing seeder or its theme, which the public trimmer deletes — '
            . 'guard the reference with class_exists or add it to the allowed list',
        );
    }

    /** Non-comment lines only: what the shell actually RUNS. */
    private function commands(string $script): string
    {
        $raw = (string) file_get_contents(\dirname(__DIR__, 2) . '/scripts/' . $script);
        $lines = array_filter(
            explode("\n", $raw),
            static fn (string $l): bool => !str_starts_with(ltrim($l), '#'),
        );

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function trackedFiles(string $root): array
    {
        $out = [];
        exec('cd /d ' . escapeshellarg(str_replace('/', '\\', $root)) . ' && git ls-files', $out);
        if ($out === []) {
            self::markTestSkipped('git is not available to list tracked files');
        }

        return array_values(array_filter(array_map('trim', $out), static fn (string $l): bool => $l !== ''));
    }
}
