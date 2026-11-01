<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Identity\Service\UserService;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Team\Service\TeamService;
use PHPUnit\Framework\TestCase;

/**
 * Invitee-facing invite surface: a logged-in user sees invites addressed to
 * their email on /teams (mail is not part of the engine, so the tokenized
 * link may never reach them) and can accept without the token.
 */
final class TeamInviteTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private TeamService $teams;
    private int $ownerId;
    private int $inviteeId;

    protected function setUp(): void
    {
        $this->dataPath = sys_get_temp_dir() . '/fastsite-team-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        (new Migrator($clock))->migrate($this->databases->engine(), \dirname(__DIR__, 2) . '/migrations/engine');

        $users = new UserService($this->databases, $clock);
        $this->ownerId = $users->createUser('jens@example.com', 'a-strong-password', 'Jens', 'user');
        $this->inviteeId = $users->createUser('invitee@example.com', 'a-strong-password', 'Invitee', 'user');
        $this->teams = new TeamService($this->databases, $clock);
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (is_dir($this->dataPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $item) {
                \assert($item instanceof \SplFileInfo);
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dataPath);
        }
    }

    public function testInviteeSeesPendingInviteAndAcceptsWithoutToken(): void
    {
        $teamId = $this->teams->create($this->ownerId, 'Content crew');
        $this->teams->invite($teamId, 'Invitee@Example.com', $this->ownerId);

        // Visible to the invitee by email, case-insensitively.
        $mine = $this->teams->invitesForEmail('INVITEE@example.com');
        self::assertCount(1, $mine);
        self::assertSame('Content crew', $mine[0]['team_name']);
        self::assertSame('jens@example.com', $mine[0]['invited_by_email']);
        // The invite link is shown to the invitee (clicking it accepts too).
        self::assertMatchesRegularExpression('/^[0-9a-f]{48}$/', (string) $mine[0]['token']);
        self::assertSame(1, $this->teams->pendingInviteCount('invitee@example.com'));

        // Accept by id — no token needed.
        $name = $this->teams->acceptForUser((int) $mine[0]['id'], $this->inviteeId, 'invitee@example.com');
        self::assertSame('Content crew', $name);
        self::assertTrue($this->teams->isMember($teamId, $this->inviteeId));

        // Gone from the pending list afterwards.
        self::assertSame([], $this->teams->invitesForEmail('invitee@example.com'));
        self::assertSame(0, $this->teams->pendingInviteCount('invitee@example.com'));
    }

    public function testAcceptForUserRejectsSomeoneElsesInvite(): void
    {
        $teamId = $this->teams->create($this->ownerId, 'Private crew');
        $this->teams->invite($teamId, 'other@example.com', $this->ownerId);
        $inviteId = (int) $this->databases->engine()->fetchOne('SELECT id FROM team_invites WHERE email = ?', ['other@example.com']);

        $this->expectException(\InvalidArgumentException::class);
        $this->teams->acceptForUser($inviteId, $this->inviteeId, 'invitee@example.com');
    }

    public function testExpiredInviteIsNotListed(): void
    {
        $teamId = $this->teams->create($this->ownerId, 'Old crew');
        $this->teams->invite($teamId, 'invitee@example.com', $this->ownerId);
        $this->databases->engine()->executeStatement(
            'UPDATE team_invites SET expires_at = ? WHERE email = ?',
            ['2000-01-01T00:00:00Z', 'invitee@example.com'],
        );

        self::assertSame([], $this->teams->invitesForEmail('invitee@example.com'));
        self::assertSame(0, $this->teams->pendingInviteCount('invitee@example.com'));
    }
}
