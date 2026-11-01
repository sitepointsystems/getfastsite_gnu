<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Site\Dto\Site;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;

/**
 * SEO title composition (Site::pageTitle): the per-site toggle + separator
 * behind the Site Settings SEO card. The default must stay byte-identical to
 * what every existing site shipped with (" — Site Name"), and the suffix must
 * vanish entirely when turned off.
 */
final class SiteSeoTitleTest extends TestCase
{
    private function site(bool $suffix = true, string $separator = '—', string $name = 'Demo'): Site
    {
        return new Site(
            1, 'demo', $name, 'demo.example.com', 'https://demo.example.com', 'en', [],
            '/blog/{slug}/', '/{slug}/', '', 'main', '', 1, 'active',
            seoTitleSuffix: $suffix,
            seoTitleSeparator: $separator,
        );
    }

    public function testDefaultMatchesHistoricalOutput(): void
    {
        self::assertSame('My first post — Demo', $this->site()->pageTitle('My first post'));
    }

    public function testSeparatorChoiceApplies(): void
    {
        self::assertSame('My first post | Demo', $this->site(separator: '|')->pageTitle('My first post'));
    }

    public function testSuffixOffShipsTheBareTitle(): void
    {
        self::assertSame('My first post', $this->site(suffix: false)->pageTitle('My first post'));
    }

    public function testEmptySiteNameNeverDanglesASeparator(): void
    {
        self::assertSame('My first post', $this->site(name: '')->pageTitle('My first post'));
    }

    public function testFromRowDefaultsForPreMigrationRows(): void
    {
        // A row without the new columns (older engine.db) must behave exactly
        // like today: suffix on, em dash.
        $site = Site::fromRow([
            'id' => 1, 'handle' => 'demo', 'name' => 'Demo', 'primary_domain' => 'demo.example.com',
            'base_url' => 'https://demo.example.com', 'primary_locale' => 'en', 'locales' => '[]',
            'post_path_pattern' => '/blog/{slug}/', 'page_path_pattern' => '/{slug}/',
            'github_repo' => '', 'github_branch' => 'main', 'media_public_base' => '',
            'theme_version' => 1, 'status' => 'active',
        ]);
        self::assertTrue($site->seoTitleSuffix);
        self::assertSame('T — Demo', $site->pageTitle('T'));
    }

    public function testEveryAllowedSeparatorComposes(): void
    {
        foreach (SiteService::TITLE_SEPARATORS as $sep) {
            self::assertSame('T ' . $sep . ' Demo', $this->site(separator: $sep)->pageTitle('T'));
        }
    }
}
