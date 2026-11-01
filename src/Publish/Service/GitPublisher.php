<?php

declare(strict_types=1);

namespace Fastsite\Publish\Service;

use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Fs\Dirs;
use Fastsite\Site\Dto\Site;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Syncs a site's build/ output into its dist repo clone and pushes.
 * The engine is the sole writer of that repo. Plain push only — non-FF is
 * a loud failure, never an automatic force-push (plan §git publish).
 */
final class GitPublisher
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly LoggerInterface $logger,
        private readonly SecretScanner $secrets,
        private readonly string $authorName,
        private readonly string $authorEmail,
        private readonly string $githubToken = '',
        private readonly ?GithubCredentialService $credentials = null,
    ) {
    }

    /**
     * @param \Closure(): void|null $tick progress heartbeat for the queue watchdog
     * @return array{committed: bool, pushed: bool, commit: string}
     */
    public function publish(Site $site, string $message, ?\Closure $tick = null): array
    {
        $tick ??= static function (): void {};
        $sitePath = $this->databases->sitePath($site->handle);
        $buildDir = $sitePath . '/build';
        $repoDir = $sitePath . '/repo';

        if (!is_dir($buildDir)) {
            throw new \RuntimeException(sprintf('Site "%s" has no build output — run build first.', $site->handle));
        }

        $this->ensureRepo($site, $repoDir);
        $tick();
        $this->syncTree($buildDir, $repoDir);
        $tick();

        // Never push secrets into a public dist repo — the prior IP-leak
        // lesson. Scan the staged working tree BEFORE the commit.
        $this->secrets->assertNoSecrets($repoDir);
        $tick();

        $this->git($site, $repoDir, ['add', '-A']);
        $tick();

        $dirty = $this->git($site, $repoDir, ['status', '--porcelain'])->getOutput() !== '';
        $committed = false;
        if ($dirty) {
            $this->git($site, $repoDir, ['commit', '-m', $message]);
            $committed = true;
        }
        $tick();

        // Push whenever a remote exists, even with no new commit — a prior
        // run may have committed and then failed the push, and "nothing
        // changed" must never strand that commit locally.
        $pushed = false;
        if ($this->hasRemote($site, $repoDir) && $this->headSha($site, $repoDir) !== '') {
            $this->git($site, $repoDir, ['push', 'origin', $site->githubBranch]);
            $pushed = true;
        }

        $sha = $this->headSha($site, $repoDir);
        $this->logger->info('Published', ['site' => $site->handle, 'commit' => $sha, 'committed' => $committed, 'pushed' => $pushed]);

        return ['committed' => $committed, 'pushed' => $pushed, 'commit' => $sha];
    }

    private function ensureRepo(Site $site, string $repoDir): void
    {
        if (is_dir($repoDir . '/.git')) {
            return;
        }

        if (!is_dir($repoDir)) {
            mkdir($repoDir, 0770, true);
        }

        if ($site->githubRepo !== '') {
            $clone = $this->git($site, \dirname($repoDir), ['clone', '--branch', $site->githubBranch, '--single-branch', $site->githubRepo, $repoDir], allowFailure: true);
            if (!$clone->isSuccessful()) {
                // Only fall back to init when the remote is REACHABLE but the
                // branch/history doesn't exist (fresh repo). A transport or
                // auth failure must fail loudly — init here would create an
                // unrelated history whose pushes are rejected forever.
                $probe = $this->git($site, \dirname($repoDir), ['ls-remote', $site->githubRepo], allowFailure: true);
                if (!$probe->isSuccessful()) {
                    throw new \RuntimeException(sprintf(
                        'Cannot reach remote %s — fix connectivity/credentials and re-run publish. Clone error: %s',
                        $site->githubRepo,
                        trim($clone->getErrorOutput()),
                    ));
                }
                $this->git($site, $repoDir, ['init', '-b', $site->githubBranch]);
                $this->git($site, $repoDir, ['remote', 'add', 'origin', $site->githubRepo]);
            }
        } else {
            $this->git($site, $repoDir, ['init', '-b', $site->githubBranch]);
        }

        $this->git($site, $repoDir, ['config', 'user.name', $this->authorName]);
        $this->git($site, $repoDir, ['config', 'user.email', $this->authorEmail]);
    }

    /**
     * Mirrors $source into $target (minus .git): copies new/changed files,
     * removes files that no longer exist in the build.
     */
    private function syncTree(string $source, string $target): void
    {
        $sourceFiles = $this->secrets->relativeFiles($source);
        $targetFiles = $this->secrets->relativeFiles($target, skipGit: true);

        foreach ($sourceFiles as $relative) {
            $from = $source . '/' . $relative;
            $to = $target . '/' . $relative;
            $dir = \dirname($to);
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            if (!is_file($to) || md5_file($from) !== md5_file($to)) {
                copy($from, $to);
            }
        }

        foreach (array_diff($targetFiles, $sourceFiles) as $stale) {
            unlink($target . '/' . $stale);
        }

        Dirs::pruneEmpty($target);
    }

    private function hasRemote(Site $site, string $repoDir): bool
    {
        return trim($this->git($site, $repoDir, ['remote'])->getOutput()) !== '';
    }

    private function headSha(Site $site, string $repoDir): string
    {
        $process = $this->git($site, $repoDir, ['rev-parse', '--short', 'HEAD'], allowFailure: true);

        return $process->isSuccessful() ? trim($process->getOutput()) : '';
    }

    /**
     * @param list<string> $args
     */
    private function git(Site $site, string $cwd, array $args, bool $allowFailure = false): Process
    {
        $env = ['GIT_TERMINAL_PROMPT' => '0']; // never hang on a credential prompt

        $deployKey = $this->databases->dataPath() . '/keys/' . $site->handle;
        if (is_file($deployKey)) {
            $env['GIT_SSH_COMMAND'] = sprintf(
                'ssh -i "%s" -o IdentitiesOnly=yes -o StrictHostKeyChecking=accept-new',
                $deployKey,
            );
        }

        // HTTPS push auth via a GitHub token: inject a github.com-scoped
        // Authorization header through git's env-config (GIT_CONFIG_*) so the
        // token is NEVER written to .git/config or passed on the command line
        // (argv is world-readable via ps). Scoped to github.com, so it can
        // never leak to another remote host. The site's own credential (#1)
        // wins; the global GITHUB_TOKEN is the fallback.
        $token = $this->credentials?->tokenForSite($site) ?? $this->githubToken;
        if ($token !== '') {
            $auth = base64_encode('x-access-token:' . $token);
            $env['GIT_CONFIG_COUNT'] = '1';
            $env['GIT_CONFIG_KEY_0'] = 'http.https://github.com/.extraheader';
            $env['GIT_CONFIG_VALUE_0'] = 'AUTHORIZATION: basic ' . $auth;
        }

        $process = new Process(['git', ...$args], $cwd, $env, null, 300);
        $process->run();

        if (!$allowFailure && !$process->isSuccessful()) {
            throw new \RuntimeException(sprintf(
                'git %s failed in %s: %s',
                implode(' ', $args),
                $cwd,
                trim($process->getErrorOutput() . "\n" . $process->getOutput()),
            ));
        }

        return $process;
    }
}
