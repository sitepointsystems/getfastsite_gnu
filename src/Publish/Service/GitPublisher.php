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

        // Never push secrets into a public dist repo — the conzent IP-leak
        // lesson. Scan the staged working tree BEFORE the commit.
        $this->assertNoSecrets($repoDir);
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
        $sourceFiles = $this->relativeFiles($source);
        $targetFiles = $this->relativeFiles($target, skipGit: true);

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

    /**
     * @return list<string>
     */
    private function relativeFiles(string $root, bool $skipGit = false): array
    {
        if (!is_dir($root)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $f): bool => !($skipGit && $f->getFilename() === '.git'),
            ),
        );

        foreach ($iterator as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Pre-push secret guard (the conzent IP-leak lesson). A PEM private key
     * is unambiguous and ALWAYS blocks the publish. The shape-based patterns
     * (API keys, AWS ids, env assignments) block only on engine-generated
     * deploy artifacts (_headers, _redirects, robots.txt, sitemaps) where a
     * secret is a pure pipeline bug — on RENDERED CONTENT PAGES they only
     * WARN, because a blog post may legitimately mention "APP_SECRET=" or an
     * fs_-shaped string and content must never wedge the pipeline.
     */
    private function assertNoSecrets(string $repoDir): void
    {
        // Unencrypted + encrypted PEM private-key headers; never legitimate
        // in shipped output, so this one blocks everywhere.
        $pemPattern = '/-----BEGIN (?:RSA |EC |OPENSSH |DSA |PGP |ENCRYPTED |SSH2 ENCRYPTED )?PRIVATE KEY-----/';
        // Anchor-free so a minified/embedded assignment is still caught; the
        // 8+ char value cuts noise. Applied only to non-content files.
        $shapePatterns = [
            'fastsite API key' => '/fs_[A-Za-z0-9]{8}_[A-Za-z0-9]{16,}/',
            'AWS access key id' => '/\bAKIA[0-9A-Z]{16}\b/',
            'env secret assignment' => '/(?:APP_SECRET|R2_SECRET_KEY|ENDPOINTR_KEY|GITHUB_TOKEN|REDIS_URL)\s*[=:]\s*["\']?\S{8,}/i',
        ];
        // AWS publishes this exact key in its own docs; never a real secret.
        $awsExample = 'AKIAIOSFODNN7EXAMPLE';

        foreach ($this->relativeFiles($repoDir, skipGit: true) as $relative) {
            $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
            if (\in_array($ext, ['avif', 'webp', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'woff2', 'woff', 'ico', 'mp4', 'webm', 'zip', 'pdf'], true)) {
                continue; // binary/media can't carry a readable secret
            }
            $content = (string) @file_get_contents($repoDir . '/' . $relative);
            if ($content === '') {
                continue;
            }

            if (preg_match($pemPattern, $content) === 1) {
                throw new \RuntimeException(sprintf(
                    'Pre-push secret scan aborted the publish: private key material in build output "%s". Nothing was pushed.',
                    $relative,
                ));
            }

            $isContentPage = $ext === 'html' || $ext === 'htm';
            foreach ($shapePatterns as $label => $pattern) {
                if ($label === 'AWS access key id' && str_contains($content, $awsExample)) {
                    continue; // documented example, not a real key
                }
                if (preg_match($pattern, $content) !== 1) {
                    continue;
                }
                if ($isContentPage) {
                    // Content must never wedge the pipeline — log and move on.
                    $this->logger->warning('Pre-push secret scan: suspicious string on a content page (published anyway)', [
                        'file' => $relative,
                        'pattern' => $label,
                    ]);
                    continue;
                }
                throw new \RuntimeException(sprintf(
                    'Pre-push secret scan aborted the publish: possible %s in engine output "%s". Nothing was pushed.',
                    $label,
                    $relative,
                ));
            }
        }
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
