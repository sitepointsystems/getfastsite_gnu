<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Content\Repository\DbalContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Removing a locale (#2A) deletes every translated artifact: content_translations
 * + string_translations rows and the shell/i18n/{locale}.json file, and the
 * primary locale can never be removed.
 */
final class SiteLocaleRemovalTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private SiteService $sites;
    private ContentService $content;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-locale-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->sites = new SiteService(new DbalSiteRepository($this->databases, $clock), $this->databases, $migrator, $clock, new NullLogger(), $appRoot);
        $this->content = new ContentService(new DbalContentRepository($this->databases, $clock), new ContentSanitizer(), $clock);
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

    public function testRemoveLocaleDeletesEveryTranslatedArtifact(): void
    {
        $site = $this->sites->create('ltest', 'Locale Test', 'https://l.example.com');
        $this->sites->addLocale('ltest', 'da');

        $post = $this->content->create($site, ['type' => 'post', 'title' => 'Hello', 'body_html' => '<p>x</p>', 'status' => 'published']);

        $db = $this->databases->site('ltest');
        $now = (new SystemClock())->iso();
        $db->insert('content_translations', [
            'content_id' => $post->id, 'locale' => 'da', 'slug' => 'hej', 'title' => 'Hej',
            'source_hash' => $post->contentHash, 'status' => 'translated', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->insert('string_translations', [
            'scope' => 'shell.ui', 'ref' => 'home', 'locale' => 'da',
            'source_hash' => 'abc', 'value' => 'Hjem', 'status' => 'translated', 'updated_at' => $now,
        ]);
        $i18n = $this->dataPath . '/sites/ltest/shell/i18n/da.json';
        if (!is_dir(\dirname($i18n))) {
            mkdir(\dirname($i18n), 0770, true);
        }
        file_put_contents($i18n, '{"home":"Hjem"}');

        $locales = $this->sites->removeLocale('ltest', 'da');

        self::assertSame([], $locales, 'locale dropped from the list');
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM content_translations WHERE locale = 'da'"));
        self::assertSame(0, (int) $db->fetchOne("SELECT COUNT(*) FROM string_translations WHERE locale = 'da'"));
        self::assertFileDoesNotExist($i18n);
    }

    public function testCannotRemovePrimaryLocale(): void
    {
        $this->sites->create('ltest2', 'Locale Test 2', 'https://l2.example.com');
        $this->expectException(\InvalidArgumentException::class);
        $this->sites->removeLocale('ltest2', 'en');
    }

    public function testRemoveUnknownLocaleIsNoop(): void
    {
        $this->sites->create('ltest3', 'Locale Test 3', 'https://l3.example.com');
        self::assertSame([], $this->sites->removeLocale('ltest3', 'de'));
    }
}
