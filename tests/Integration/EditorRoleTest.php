<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Dashboard\Dash;
use Fastsite\Http\EngineView;
use Fastsite\Identity\Service\SessionService;
use Fastsite\Identity\Service\UserService;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Repository\DbalSiteRepository;
use Fastsite\Site\Service\SiteService;
use Fastsite\Team\Service\TeamService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The editor role: self-serve site creators. The users.role CHECK is widened
 * via a writable_schema migration (a rebuild would have cascade-deleted every
 * child table); the creator of a site is recorded as its site_users owner.
 */
final class EditorRoleTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private UserService $users;
    private SiteService $sites;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-editor-' . bin2hex(random_bytes(6));

        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->users = new UserService($this->databases, $clock);
        $this->sites = new SiteService(
            new DbalSiteRepository($this->databases, $clock),
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

    public function testMigratedSchemaAcceptsEditorAndStillRejectsGarbage(): void
    {
        $id = $this->users->createUser('editor@example.com', 'a-strong-password', 'Ed', 'editor');
        self::assertSame(
            'editor',
            $this->databases->engine()->fetchOne('SELECT role FROM users WHERE id = ?', [$id]),
        );

        // The rewritten CHECK still guards: raw garbage must be rejected by SQLite.
        $this->expectException(\Throwable::class);
        $this->databases->engine()->executeStatement(
            "UPDATE users SET role = 'bogus' WHERE id = ?",
            [$id],
        );
    }

    public function testSetRoleValidatesAndPersists(): void
    {
        $id = $this->users->createUser('jens@example.com', 'a-strong-password', 'Jens', 'user');
        $this->users->setRole($id, 'editor');
        self::assertSame(
            'editor',
            $this->databases->engine()->fetchOne('SELECT role FROM users WHERE id = ?', [$id]),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->users->setRole($id, 'superuser');
    }

    public function testUpdateProfileNormalizesEmailAndRejectsDuplicates(): void
    {
        $id = $this->users->createUser('old@example.com', 'a-strong-password', 'Old', 'user');
        $this->users->createUser('taken@example.com', 'a-strong-password', 'Other', 'user');

        $this->users->updateProfile($id, ' New@Example.COM ', '  New Name ');
        $row = $this->databases->engine()->fetchAssociative('SELECT email, name FROM users WHERE id = ?', [$id]);
        self::assertSame(['email' => 'new@example.com', 'name' => 'New Name'], $row);

        $this->expectException(\RuntimeException::class);
        $this->users->updateProfile($id, 'taken@example.com', 'New Name');
    }

    public function testSiteCreateRecordsTheCreatorAsOwner(): void
    {
        $editorId = $this->users->createUser('editor@example.com', 'a-strong-password', 'Ed', 'editor');
        $site = $this->sites->create('edsite', 'Ed Site', 'https://ed.example.com', '', 'en', $editorId);

        self::assertSame(
            'owner',
            $this->databases->engine()->fetchOne(
                'SELECT role FROM site_users WHERE site_id = ? AND user_id = ?',
                [$site->id, $editorId],
            ),
        );
    }

    public function testSiteCreateWithoutOwnerAddsNoMembership(): void
    {
        $site = $this->sites->create('nobody', 'No Owner', 'https://n.example.com');
        self::assertFalse(
            $this->databases->engine()->fetchOne('SELECT 1 FROM site_users WHERE site_id = ?', [$site->id]),
        );
    }

    public function testDeleteUserCascadesButKeepsSites(): void
    {
        $clock = new SystemClock();
        $teams = new TeamService($this->databases, $clock);
        $engine = $this->databases->engine();

        $edId = $this->users->createUser('ed@example.com', 'a-strong-password', 'Ed', 'editor');
        $mateId = $this->users->createUser('mate@example.com', 'a-strong-password', 'Mate', 'user');

        $site = $this->sites->create('edsite', 'Ed Site', 'https://ed.example.com', '', 'en', $edId);
        $teamId = $teams->create($edId, 'Crew');
        $engine->insert('team_members', ['team_id' => $teamId, 'user_id' => $mateId, 'role' => 'member', 'created_at' => $clock->iso()]);
        $this->sites->update('edsite', ['team_id' => $teamId]);

        $this->users->deleteUser($edId);

        self::assertFalse($engine->fetchOne('SELECT 1 FROM users WHERE id = ?', [$edId]));
        self::assertFalse($engine->fetchOne('SELECT 1 FROM site_users WHERE user_id = ?', [$edId]));
        self::assertFalse($engine->fetchOne('SELECT 1 FROM teams WHERE id = ?', [$teamId]), 'Owned team cascades.');
        self::assertFalse($engine->fetchOne('SELECT 1 FROM team_members WHERE team_id = ?', [$teamId]));

        // The site survives, detached from the dead team, reachable by admins.
        $row = $engine->fetchAssociative('SELECT id, team_id FROM sites WHERE handle = ?', ['edsite']);
        self::assertSame($site->id, (int) $row['id']);
        self::assertNull($row['team_id']);

        // The other user is untouched.
        self::assertNotFalse($engine->fetchOne('SELECT 1 FROM users WHERE id = ?', [$mateId]));
    }

    public function testSiteAccessIsOwnershipOrTeamOnly(): void
    {
        $clock = new SystemClock();
        $teams = new TeamService($this->databases, $clock);
        // Redis client is constructed lazily and never touched by sitesFor.
        $dash = new Dash(
            new EngineView(\dirname(__DIR__, 2)),
            new SessionService($this->databases, new \Predis\Client(), $clock),
            new DbalSiteRepository($this->databases, $clock),
            $this->databases,
            $teams,
            new \Fastsite\Workspace\Service\WorkspaceService($this->databases, $clock),
            str_repeat('s', 32),
        );

        $ownerId = $this->users->createUser('owner@example.com', 'a-strong-password', 'Owner', 'editor');
        $mateId = $this->users->createUser('mate@example.com', 'a-strong-password', 'Mate', 'user');
        $legacyId = $this->users->createUser('legacy@example.com', 'a-strong-password', 'Legacy', 'user');

        $site = $this->sites->create('shared', 'Shared', 'https://s.example.com', '', 'en', $ownerId);

        // The teammate joins through a real invite; the site attaches to the team.
        $teamId = $teams->create($ownerId, 'Crew');
        $teams->invite($teamId, 'mate@example.com', $ownerId);
        $teams->acceptForUser(
            (int) $teams->invitesForEmail('mate@example.com')[0]['id'],
            $mateId,
            'mate@example.com',
        );
        $this->sites->update('shared', ['team_id' => $teamId]);

        // A legacy per-user "editor" grant row must no longer confer access.
        $this->databases->engine()->insert('site_users', [
            'site_id' => $site->id, 'user_id' => $legacyId, 'role' => 'editor', 'created_at' => $clock->iso(),
        ]);

        $handles = static fn (array $sites): array => array_map(static fn ($s) => $s->handle, $sites);
        self::assertSame(['shared'], $handles($dash->sitesFor(['id' => $ownerId, 'role' => 'editor'])));
        self::assertSame(['shared'], $handles($dash->sitesFor(['id' => $mateId, 'role' => 'user'])));
        self::assertSame([], $handles($dash->sitesFor(['id' => $legacyId, 'role' => 'user'])));

        // Settings stay owner/admin-only: team membership reads, never manages.
        self::assertTrue($dash->canManageSite(['id' => $ownerId, 'role' => 'editor'], $site));
        self::assertFalse($dash->canManageSite(['id' => $mateId, 'role' => 'user'], $site));
        self::assertTrue($dash->canManageSite(['id' => 999, 'role' => 'admin'], $site));
    }
}
