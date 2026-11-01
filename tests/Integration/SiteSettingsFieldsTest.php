<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The Site Settings screen posts a form; SettingsActionHandler hand-copies each
 * field into SiteService::update. That hand-copied list silently drifted once:
 * the form offered an "AI key" select and SiteService could write
 * ai_credential_id, but the handler never forwarded it, so the choice looked
 * saved and was gone on reload. These tests pin both halves of that path.
 */
final class SiteSettingsFieldsTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private DbalSiteRepository $repo;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-settings-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->repo = new DbalSiteRepository($this->databases, $clock);
        $this->sites = new SiteService(
            $this->repo,
            $this->databases,
            $migrator,
            $clock,
            new NullLogger(),
            $appRoot,
        );
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (!is_dir($this->dataPath)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->dataPath);
    }

    public function testAiCredentialIdSurvivesAnUpdateAndAReload(): void
    {
        $this->sites->create('aisite', 'AI Site', 'https://ai.example.com');

        $this->sites->update('aisite', ['ai_credential_id' => 7]);
        // Re-read from the DB rather than trusting the returned DTO — the bug
        // was invisible until reload.
        self::assertSame(7, $this->repo->findByHandle('aisite')?->aiCredentialId);

        // The "— none —" option submits an empty string; that must clear it.
        $this->sites->update('aisite', ['ai_credential_id' => '']);
        self::assertNull($this->repo->findByHandle('aisite')?->aiCredentialId);
    }

    public function testCfAccountIdAcceptsBareIdOrPastedDashboardUrl(): void
    {
        $this->sites->create('cfsite', 'CF Site', 'https://cf.example.com');

        // Bare id, any case → stored lowercase.
        $this->sites->update('cfsite', ['cf_account_id' => 'C793936F7A24E3F4FA082DFD6F4673F4']);
        self::assertSame('c793936f7a24e3f4fa082dfd6f4673f4', $this->repo->findByHandle('cfsite')?->cfAccountId);

        // A whole dashboard URL — the guide tells users to paste it verbatim.
        $this->sites->update('cfsite', ['cf_account_id' => 'https://dash.cloudflare.com/1234567890abcdef1234567890abcdef/pages/new/provider/github']);
        self::assertSame('1234567890abcdef1234567890abcdef', $this->repo->findByHandle('cfsite')?->cfAccountId);

        // Blank clears.
        $this->sites->update('cfsite', ['cf_account_id' => '   ']);
        self::assertNull($this->repo->findByHandle('cfsite')?->cfAccountId);

        // Anything without a 32-hex token is rejected loudly, not stored mangled.
        $this->expectException(\InvalidArgumentException::class);
        $this->sites->update('cfsite', ['cf_account_id' => 'my-cloudflare-account']);
    }

    public function testSeoTitleSettingsPersistAndValidate(): void
    {
        $this->sites->create('seosite', 'SEO Site', 'https://seo.example.com');

        // Defaults: suffix on, em dash — the behavior every site shipped with.
        $before = $this->repo->findByHandle('seosite');
        self::assertTrue($before?->seoTitleSuffix);
        self::assertSame('—', $before?->seoTitleSeparator);

        // The SEO card's toggle-off + separator choice survive a reload.
        $after = $this->sites->update('seosite', ['seo_title_suffix' => '0', 'seo_title_separator' => '|']);
        $fresh = $this->repo->findByHandle('seosite');
        self::assertFalse($fresh?->seoTitleSuffix);
        self::assertSame('|', $fresh?->seoTitleSeparator);
        self::assertSame('My post', $fresh?->pageTitle('My post'));
        // Every page <title> embeds the choice, so the change must republish.
        self::assertTrue(SiteService::changeNeedsRebuild($before, $after));

        $this->sites->update('seosite', ['seo_title_suffix' => '1']);
        self::assertSame('My post | SEO Site', $this->repo->findByHandle('seosite')?->pageTitle('My post'));

        // The separator lands verbatim in every <title> — free text is rejected.
        $this->expectException(\InvalidArgumentException::class);
        $this->sites->update('seosite', ['seo_title_separator' => 'brought to you by']);
    }

    /**
     * The settings screen is tabbed, so its forms live in per-tab partials
     * rather than one file. These tests read markup rather than rendering, so
     * they have to follow the split — and reading the whole directory means a
     * future re-slice cannot quietly hide a form from them either.
     */
    private static function settingsMarkup(string $appRoot): string
    {
        $markup = (string) file_get_contents($appRoot . '/templates/engine/settings.html.twig');
        foreach (glob($appRoot . '/templates/engine/settings/*.twig') ?: [] as $partial) {
            $markup .= "\n" . (string) file_get_contents($partial);
        }

        return $markup;
    }

    public function testHandlerForwardsEveryFieldTheSettingsFormPosts(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $template = self::settingsMarkup($appRoot);
        $handler = (string) file_get_contents($appRoot . '/src/Dashboard/Handler/SettingsActionHandler.php');

        // Both hand-copied forms: site-save and the SEO card's seo-save.
        foreach (['site-save', 'seo-save'] as $action) {
            // Isolate the form: from its action marker to the next </form>.
            $start = strpos($template, 'value="' . $action . '"');
            self::assertNotFalse($start, $action . ' form not found in settings.html.twig');
            $end = strpos($template, '</form>', $start);
            self::assertNotFalse($end);
            $form = substr($template, $start, $end - $start);

            // Literal field names only — Twig-interpolated ones (name="{{ p.name }}")
            // are covered by the brandpicker keys already in the handler.
            preg_match_all('/name="([a-z][a-z0-9_]*)"/', $form, $m);
            $posted = array_unique($m[1]);

            foreach ($posted as $field) {
                if ($field === 'action') {
                    continue;
                }
                self::assertStringContainsString(
                    "'" . $field . "'",
                    $handler,
                    sprintf('Settings form (%s) posts "%s" but SettingsActionHandler never reads it — the value is silently dropped.', $action, $field),
                );
            }
        }

        self::assertStringContainsString('name="ai_credential_id"', $template, 'the AI key select vanished from the form');
        self::assertStringContainsString('name="seo_title_suffix"', $template, 'the SEO title-suffix toggle vanished from the form');
    }

    /**
     * FTPS certificate verification: on by default, and only ever turned off
     * by an explicit value. A security relaxation must never happen because a
     * field was absent from a request.
     */
    public function testFtpCertificateVerificationDefaultsOnAndRoundTrips(): void
    {
        $site = $this->sites->create('certsite', 'Cert Site', 'https://cert.example.com');
        self::assertTrue($site->ftpVerifyCert, 'verification must default ON for a new site');

        $off = $this->sites->update('certsite', ['ftp_verify_cert' => '0']);
        self::assertFalse($off->ftpVerifyCert);
        self::assertFalse($this->repo->findByHandle('certsite')?->ftpVerifyCert, 'the choice must survive a reload');

        $on = $this->sites->update('certsite', ['ftp_verify_cert' => '1']);
        self::assertTrue($on->ftpVerifyCert);

        // An update that does not mention the field leaves it alone.
        $untouched = $this->sites->update('certsite', ['name' => 'Renamed']);
        self::assertTrue($untouched->ftpVerifyCert);
    }

    /** The decrypted target the transport sees must carry the choice. */
    public function testFtpTargetCarriesTheCertificateChoice(): void
    {
        $this->sites->create('certtarget', 'Cert Target', 'https://ct.example.com');
        $this->sites->update('certtarget', ['ftp_host' => 'files.example.com', 'ftp_username' => 'deploy']);

        $verifying = $this->repo->findByHandle('certtarget') ?? self::fail('site vanished');
        self::assertTrue(\Fastsite\Publish\Service\FtpTarget::fromSite($verifying)->verifyCertificate);

        $this->sites->update('certtarget', ['ftp_verify_cert' => '0']);
        $trusting = $this->repo->findByHandle('certtarget') ?? self::fail('site vanished');
        self::assertFalse(\Fastsite\Publish\Service\FtpTarget::fromSite($trusting)->verifyCertificate);
    }

    /**
     * The FTP target form, which shipped without an action attribute and so
     * posted to the screen's own GET-only URL — a bare 405 and the typed
     * credentials lost. Isolated by its own tag boundaries rather than by an
     * action marker, because its submit buttons carry the action and live at
     * the END of the form.
     */
    public function testFtpTargetFormPostsToTheActionEndpointAndEveryFieldIsRead(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $template = self::settingsMarkup($appRoot);
        $handler = (string) file_get_contents($appRoot . '/src/Dashboard/Handler/SettingsActionHandler.php');

        $hostAt = strpos($template, 'name="ftp_host"');
        self::assertNotFalse($hostAt, 'the FTP target form vanished from settings.html.twig');
        $open = strrpos(substr($template, 0, $hostAt), '<form');
        self::assertNotFalse($open);
        $close = strpos($template, '</form>', $hostAt);
        self::assertNotFalse($close);
        $form = substr($template, $open, $close - $open);

        self::assertStringContainsString(
            'action="/sites/{{ site.handle }}/settings/action"',
            $form,
            'The FTP form must name the action endpoint — without it the browser posts to the settings screen, which is GET-only, and the save dies with 405.',
        );

        // Both submit buttons must reach a case the handler actually handles.
        foreach (['ftp-save', 'ftp-test'] as $action) {
            self::assertStringContainsString('value="' . $action . '"', $form, 'the ' . $action . ' button vanished');
            self::assertStringContainsString("case '" . $action . "':", $handler, 'no handler case for ' . $action);
        }

        preg_match_all('/name="([a-z][a-z0-9_]*)"/', $form, $m);
        foreach (array_unique($m[1]) as $field) {
            if ($field === 'action') {
                continue;
            }
            self::assertStringContainsString(
                "'" . $field . "'",
                $handler,
                sprintf('The FTP form posts "%s" but SettingsActionHandler never reads it — the value is silently dropped.', $field),
            );
        }
    }
}
