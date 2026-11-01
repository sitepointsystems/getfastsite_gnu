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
use Fastsite\Workspace\Service\WorkspaceService;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Workspaces: the customer-separation layer. A site belongs to at most one
 * workspace; the dashboard filters its lists to the (cookie-)selected
 * workspace; access = admin | owner | attached-team member, enforced with a
 * 403 on every transport.
 */
final class WorkspaceTest extends TestCase
{
    private string $dataPath;
    private SiteDatabaseManager $databases;
    private UserService $users;
    private SiteService $sites;
    private WorkspaceService $workspaces;
    private TeamService $teams;
    private SystemClock $clock;

    protected function setUp(): void
    {
        $appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-workspace-' . bin2hex(random_bytes(6));

        $this->clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($this->clock);
        $migrator->migrate($this->databases->engine(), $appRoot . '/migrations/engine');

        $this->users = new UserService($this->databases, $this->clock);
        $this->teams = new TeamService($this->databases, $this->clock);
        $this->workspaces = new WorkspaceService($this->databases, $this->clock);
        $this->sites = new SiteService(
            new DbalSiteRepository($this->databases, $this->clock),
            $this->databases,
            $migrator,
            $this->clock,
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

    private function dash(): Dash
    {
        return new Dash(
            new EngineView(\dirname(__DIR__, 2)),
            new SessionService($this->databases, new \Predis\Client(), $this->clock),
            new DbalSiteRepository($this->databases, $this->clock),
            $this->databases,
            $this->teams,
            $this->workspaces,
            str_repeat('s', 32),
        );
    }

    public function testCreateListUpdateDelete(): void
    {
        $ownerId = $this->users->createUser('owner@example.com', 'a-strong-password', 'Owner', 'editor');

        $id = $this->workspaces->create($ownerId, "  Acme \x01 Corp  ");
        $workspace = $this->workspaces->find($id);
        self::assertNotNull($workspace);
        self::assertSame('Acme   Corp', $workspace['name'], 'Control chars stripped, trimmed.');

        $this->workspaces->update($id, 'Acme', null);
        self::assertSame('Acme', $this->workspaces->find($id)['name'] ?? null);

        $list = $this->workspaces->listForUser($ownerId);
        self::assertCount(1, $list);
        self::assertSame(0, $list[0]['site_count']);

        $this->workspaces->delete($id);
        self::assertNull($this->workspaces->find($id));

        $this->expectException(\InvalidArgumentException::class);
        $this->workspaces->create($ownerId, '   ');
    }

    public function testAccessIsAdminOwnerOrTeamOnly(): void
    {
        $adminId = $this->users->createUser('admin@example.com', 'a-strong-password', 'Admin', 'admin');
        $ownerId = $this->users->createUser('owner@example.com', 'a-strong-password', 'Owner', 'editor');
        $mateId = $this->users->createUser('mate@example.com', 'a-strong-password', 'Mate', 'user');
        $strangerId = $this->users->createUser('stranger@example.com', 'a-strong-password', 'Stranger', 'user');

        $teamId = $this->teams->create($ownerId, 'Crew');
        $this->databases->engine()->insert('team_members', [
            'team_id' => $teamId, 'user_id' => $mateId, 'role' => 'member', 'created_at' => $this->clock->iso(),
        ]);

        $id = $this->workspaces->create($ownerId, 'Customer A', $teamId);

        self::assertTrue($this->workspaces->canAccess($adminId, $id));
        self::assertTrue($this->workspaces->canAccess($ownerId, $id));
        self::assertTrue($this->workspaces->canAccess($mateId, $id), 'Team member reaches the workspace.');
        self::assertFalse($this->workspaces->canAccess($strangerId, $id), 'Stranger is refused → transports 403.');
        self::assertFalse($this->workspaces->canAccess($ownerId, 9999), 'Nonexistent workspace is never accessible.');

        // Manage (rename/delete/team) stays admin/owner-only.
        self::assertTrue($this->workspaces->canManage($adminId, $id));
        self::assertTrue($this->workspaces->canManage($ownerId, $id));
        self::assertFalse($this->workspaces->canManage($mateId, $id), 'Team members use, never reshape.');
        self::assertFalse($this->workspaces->canManage($strangerId, $id));

        // Detaching the team revokes the mate's access.
        $this->workspaces->update($id, 'Customer A', null);
        self::assertFalse($this->workspaces->canAccess($mateId, $id));

        // listForUser mirrors canAccess.
        self::assertSame([], $this->workspaces->listForUser($strangerId));
        self::assertCount(1, $this->workspaces->listForUser($adminId));
    }

    public function testSitesMoveBetweenWorkspacesAndDetachOnDelete(): void
    {
        $ownerId = $this->users->createUser('owner@example.com', 'a-strong-password', 'Owner', 'editor');
        $a = $this->workspaces->create($ownerId, 'Customer A');
        $b = $this->workspaces->create($ownerId, 'Customer B');

        $site = $this->sites->create('acme', 'Acme', 'https://acme.example.com', '', 'en', $ownerId);
        self::assertNull($site->workspaceId);

        $site = $this->sites->update('acme', ['workspace_id' => $a]);
        self::assertSame($a, $site->workspaceId);
        self::assertSame(1, $this->workspaces->listForUser($ownerId)[0]['site_count']);

        // Move between workspaces; clear with null/''/0 all mean "none".
        $site = $this->sites->update('acme', ['workspace_id' => $b]);
        self::assertSame($b, $site->workspaceId);
        $site = $this->sites->update('acme', ['workspace_id' => '']);
        self::assertNull($site->workspaceId);

        // Deleting a workspace detaches its sites, never touches them.
        $this->sites->update('acme', ['workspace_id' => $a]);
        $this->workspaces->delete($a);
        $row = $this->databases->engine()->fetchAssociative('SELECT workspace_id, deleted_at FROM sites WHERE handle = ?', ['acme']);
        self::assertNull($row['workspace_id']);
        self::assertNull($row['deleted_at']);
    }

    public function testDashFiltersVisibleSitesByCookieAndIgnoresForeignCookie(): void
    {
        $dash = $this->dash();
        $ownerId = $this->users->createUser('owner@example.com', 'a-strong-password', 'Owner', 'editor');
        $strangerId = $this->users->createUser('stranger@example.com', 'a-strong-password', 'Stranger', 'editor');

        $a = $this->workspaces->create($ownerId, 'Customer A');
        $this->sites->create('one', 'One', 'https://one.example.com', '', 'en', $ownerId);
        $this->sites->create('two', 'Two', 'https://two.example.com', '', 'en', $ownerId);
        $this->sites->update('one', ['workspace_id' => $a]);

        $request = static fn (int $userId, string $role, array $cookies): ServerRequest => (new ServerRequest('GET', '/'))
            ->withAttribute(\Fastsite\Dashboard\Middleware\SessionAuthMiddleware::USER_ATTRIBUTE, ['id' => $userId, 'email' => 'x@example.com', 'name' => 'X', 'role' => $role])
            ->withCookieParams($cookies);

        $handles = static fn (array $sites): array => array_map(static fn ($s) => $s->handle, $sites);

        // No cookie → all accessible sites.
        self::assertSame(['one', 'two'], $handles($dash->visibleSitesFor($request($ownerId, 'editor', []))));

        // Workspace selected → only its sites.
        self::assertSame(['one'], $handles($dash->visibleSitesFor($request($ownerId, 'editor', [Dash::WORKSPACE_COOKIE => (string) $a]))));
        self::assertSame($a, $dash->selectedWorkspaceId($request($ownerId, 'editor', [Dash::WORKSPACE_COOKIE => (string) $a])));

        // A cookie pointing at a workspace the user cannot access is ignored
        // (selection itself 403s in the action handler; a stale cookie must
        // never lock the UI or leak the other workspace's scoping).
        self::assertNull($dash->selectedWorkspaceId($request($strangerId, 'editor', [Dash::WORKSPACE_COOKIE => (string) $a])));
        self::assertSame([], $handles($dash->visibleSitesFor($request($strangerId, 'editor', [Dash::WORKSPACE_COOKIE => (string) $a]))));

        // Garbage cookie values are ignored too.
        self::assertNull($dash->selectedWorkspaceId($request($ownerId, 'editor', [Dash::WORKSPACE_COOKIE => 'abc'])));
    }

    public function testActionHandlerEnforces403AndSetsCookie(): void
    {
        $handler = new \Fastsite\Dashboard\Handler\WorkspacesActionHandler(
            $this->dash(),
            $this->workspaces,
            $this->sites,
            $this->teams,
        );

        $ownerId = $this->users->createUser('owner@example.com', 'a-strong-password', 'Owner', 'editor');
        $strangerId = $this->users->createUser('stranger@example.com', 'a-strong-password', 'Stranger', 'editor');
        $workspaceId = $this->workspaces->create($ownerId, 'Customer A');
        $this->sites->create('acme', 'Acme', 'https://acme.example.com', '', 'en', $ownerId);

        $post = static fn (int $userId, string $role, array $body): ServerRequest => (new ServerRequest('POST', '/workspaces/action'))
            ->withAttribute(\Fastsite\Dashboard\Middleware\SessionAuthMiddleware::USER_ATTRIBUTE, ['id' => $userId, 'email' => 'x@example.com', 'name' => 'X', 'role' => $role])
            ->withParsedBody($body);

        // Selecting a workspace you cannot access is a hard 403.
        $response = $handler->handle($post($strangerId, 'editor', ['action' => 'select', 'workspace' => (string) $workspaceId]));
        self::assertSame(403, $response->getStatusCode());

        // Selecting an accessible one redirects and persists the fs_ws cookie.
        $response = $handler->handle($post($ownerId, 'editor', ['action' => 'select', 'workspace' => (string) $workspaceId]));
        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString(Dash::WORKSPACE_COOKIE . '=' . $workspaceId, $response->getHeaderLine('Set-Cookie'));

        // "all" clears the cookie.
        $response = $handler->handle($post($ownerId, 'editor', ['action' => 'select', 'workspace' => 'all']));
        self::assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));

        // Moving a site into a workspace you cannot access is a 403 — even as
        // the site's owner.
        $foreignWs = $this->workspaces->create($strangerId, 'Customer B');
        $response = $handler->handle($post($ownerId, 'editor', ['action' => 'move-site', 'handle' => 'acme', 'workspace_id' => (string) $foreignWs]));
        self::assertSame(403, $response->getStatusCode());

        // Moving into your own workspace works; a non-manager of the site gets 403.
        $response = $handler->handle($post($ownerId, 'editor', ['action' => 'move-site', 'handle' => 'acme', 'workspace_id' => (string) $workspaceId]));
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            $workspaceId,
            (int) $this->databases->engine()->fetchOne('SELECT workspace_id FROM sites WHERE handle = ?', ['acme']),
        );

        // Rename/delete stay manager-only (a mere accessor gets 403).
        $response = $handler->handle($post($strangerId, 'editor', ['action' => 'delete', 'id' => (string) $workspaceId]));
        self::assertSame(403, $response->getStatusCode());
    }
}
