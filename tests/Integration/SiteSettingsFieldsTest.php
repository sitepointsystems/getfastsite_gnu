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

    public function testHandlerForwardsEveryFieldTheSettingsFormPosts(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $template = (string) file_get_contents($appRoot . '/templates/engine/settings.html.twig');
        $handler = (string) file_get_contents($appRoot . '/src/Dashboard/Handler/SettingsActionHandler.php');

        // Isolate the site-save form: from its action marker to the next </form>.
        $start = strpos($template, 'value="site-save"');
        self::assertNotFalse($start, 'site-save form not found in settings.html.twig');
        $end = strpos($template, '</form>', $start);
        self::assertNotFalse($end);
        $form = substr($template, $start, $end - $start);

        // Literal field names only — Twig-interpolated ones (name="{{ p.name }}")
        // are covered by the brandpicker keys already in the handler.
        preg_match_all('/name="([a-z][a-z0-9_]*)"/', $form, $m);
        $posted = array_unique($m[1]);
        self::assertContains('ai_credential_id', $posted, 'the AI key select vanished from the form');

        $ignored = ['action'];
        foreach ($posted as $field) {
            if (\in_array($field, $ignored, true)) {
                continue;
            }
            self::assertStringContainsString(
                "'" . $field . "'",
                $handler,
                sprintf('Settings form posts "%s" but SettingsActionHandler never reads it — the value is silently dropped.', $field),
            );
        }
    }
}
