<?php

declare(strict_types=1);

namespace Fastsite\Ops;

use Fastsite\Backup\Storage\StorageClient;
use Fastsite\Media\ImageOptimizer;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Site\Repository\SiteRepository;
use Predis\Client;
use Symfony\Component\Process\Process;

/**
 * Plain-English pipeline health check (bin/fastsite doctor). Every check
 * yields OK / WARN / FAIL with a verdict a human can act on; the CLI exits
 * non-zero when anything FAILs. Follows an earlier in-house doctor.
 */
final class DoctorService
{
    public function __construct(
        private readonly Client $redis,
        private readonly SiteDatabaseManager $databases,
        private readonly SiteRepository $sites,
        private readonly Clock $clock,
        private readonly StorageClient $storage,
        private readonly ImageOptimizer $optimizer,
        private readonly JobQueue $jobQueue,
        private readonly \Fastsite\Publish\Service\FtpPublisher $ftp,
        private readonly \Fastsite\Form\Service\FormService $forms,
        private readonly BuildInfoService $buildInfo,
        private readonly string $appSecret,
    ) {
    }

    /**
     * @return list<array{status: string, name: string, detail: string}>
     */
    public function run(): array
    {
        $checks = [];

        $checks[] = $this->appSecret !== ''
            ? self::ok('app-secret', 'APP_SECRET is set.')
            : self::fail('app-secret', 'APP_SECRET is empty — sessions and encrypted secrets cannot work.');

        try {
            $this->redis->ping();
            $checks[] = self::ok('redis', 'Redis answers PING.');
        } catch (\Throwable $e) {
            $checks[] = self::fail('redis', 'Redis unreachable: ' . $e->getMessage() . ' — queue and scheduler are down.');
        }

        $checks[] = $this->integrity('engine.db', fn () => $this->databases->engine());

        $free = @disk_free_space($this->databases->dataPath());
        if ($free === false) {
            $checks[] = self::warn('disk', 'Could not read free space for ' . $this->databases->dataPath());
        } elseif ($free < 200 * 1024 * 1024) {
            $checks[] = self::fail('disk', sprintf('Only %.0f MB free on the data volume — builds and backups will fail.', $free / 1048576));
        } elseif ($free < 1024 * 1024 * 1024) {
            $checks[] = self::warn('disk', sprintf('%.1f GB free on the data volume — getting tight.', $free / 1073741824));
        } else {
            $checks[] = self::ok('disk', sprintf('%.1f GB free on the data volume.', $free / 1073741824));
        }

        $node = new Process(['node', '--version']);
        $node->run();
        $checks[] = $node->isSuccessful()
            ? self::ok('node', 'Node ' . trim($node->getOutput()) . ' available (component SSR).')
            : self::warn('node', 'Node not found — React component rendering (M8) will not work.');

        // What is ACTUALLY deployed. A 200 from /healthz cannot tell a fresh
        // container from an old one kept alive after a failed build, and the
        // libraries an advisory names (libvips, aom, libheif, librsvg) live
        // inside sharp's prebuilt binary where nothing else reports them.
        $build = $this->buildInfo->info();
        $checks[] = $build['sharp'] !== null
            ? self::ok('build', $this->buildInfo->summary())
            : self::warn('build', 'Could not read the image libraries: ' . ($build['sharp_error'] ?? 'unknown') . ' — image processing is probably broken, and a dependency bump cannot be verified from here.');

        $checks[] = $this->optimizer->isConfigured()
            ? self::ok('image-optimizer', 'Image optimization: ' . $this->optimizer->describe() . '.')
            : self::warn('image-optimizer', 'ShortPixel not configured — images optimized by sharp only (set SHORTPIXEL_API_KEY to enable).');

        // Shell snapshots and shell backups are zip archives. A runtime built
        // without ext-zip has no ZipArchive class at all, which surfaced as a
        // dashboard 500 on "Snapshot now" and — because a full backup logs a
        // failed part and carries on — as shell backups that never once ran.
        $checks[] = \extension_loaded('zip')
            ? self::ok('php-zip', 'PHP zip extension loaded (shell snapshots + shell backups).')
            : self::fail('php-zip', 'PHP zip extension missing — ZipArchive is undefined, so shell snapshots and shell backups cannot run. Install ext-zip in the runtime image.');

        // Which transfer protocols this build of libcurl actually speaks.
        // SFTP needs libssh2/libssh compiled in, which not every distro's
        // package includes — and the alternative to reporting it here is every
        // publish on an ftp site failing with an opaque "unsupported protocol"
        // that points at nothing. Reported, never assumed.
        $protocols = \Fastsite\Publish\Service\CurlFtpTransport::supportedProtocols();
        $transfer = array_values(array_intersect(['ftp', 'ftps', 'sftp'], $protocols));
        $checks[] = match (true) {
            $transfer === [] => self::warn('transfer-protocols', 'libcurl speaks none of ftp/ftps/sftp — sites publishing to their own host cannot upload at all.'),
            \count($transfer) < 3 => self::warn('transfer-protocols', 'libcurl speaks ' . implode(', ', $transfer) . ' but NOT ' . implode(', ', array_diff(['ftp', 'ftps', 'sftp'], $transfer)) . ' — a site set to a missing protocol cannot publish.'),
            default => self::ok('transfer-protocols', 'libcurl speaks ftp, ftps and sftp (publishing to a customer\'s own host).'),
        };

        $checks = [...$checks, ...$this->jobChecks(), ...$this->backupChecks(), ...$this->siteChecks()];

        return $checks;
    }

    /**
     * @return list<array{status: string, name: string, detail: string}>
     */
    private function backupChecks(): array
    {
        $checks = [];

        $checks[] = $this->storage->isConfigured()
            ? self::ok('backup-store', 'Backup storage: ' . $this->storage->describe() . '.')
            : self::warn('backup-store', 'Backup storage not configured — falling back to a local directory (no offsite copy).');

        try {
            $latest = $this->databases->engine()->fetchOne(
                "SELECT MAX(created_at) FROM backups WHERE status = 'done'",
            );
            if ($latest === false || $latest === null) {
                $checks[] = self::warn('backup-age', 'No backups recorded yet — run `backup:engine` / `backup:site`, or wait for the nightly job.');
            } else {
                $ageHours = ($this->clock->now()->getTimestamp() - (new \DateTimeImmutable((string) $latest))->getTimestamp()) / 3600;
                $checks[] = $ageHours > 48
                    ? self::warn('backup-age', sprintf('Last backup was %.0f h ago — nightly backups may not be running.', $ageHours))
                    : self::ok('backup-age', sprintf('Last backup %.1f h ago.', $ageHours));
            }
        } catch (\Throwable $e) {
            $checks[] = self::warn('backup-age', 'Could not read backups table: ' . $e->getMessage());
        }

        // backup-age above is MAX() over every kind, so one healthy nightly db
        // snapshot keeps it green while another part has failed every night —
        // backupSite() deliberately logs a failed part and carries on, so the
        // job still reports done. Report per-kind coverage so a part that is
        // silently dead is actually visible.
        try {
            $latestByKind = $this->databases->engine()->fetchAllKeyValue(
                "SELECT kind, MAX(created_at) FROM backups WHERE status = 'done' AND scope = 'site' GROUP BY kind",
            );
            if ($latestByKind !== []) {
                $missing = array_values(array_filter(
                    ['db', 'media', 'shell'],
                    static fn (string $kind): bool => !isset($latestByKind[$kind]),
                ));
                $checks[] = $missing !== []
                    ? self::warn('backup-kinds', sprintf(
                        'No successful %s backup has ever been recorded, though other kinds are running — that part is failing silently (check the logs for "Backup part failed").',
                        implode(' / ', $missing),
                    ))
                    : self::ok('backup-kinds', 'Site backups cover db, media and shell.');
            }
        } catch (\Throwable $e) {
            $checks[] = self::warn('backup-kinds', 'Could not read backup kinds: ' . $e->getMessage());
        }

        return $checks;
    }

    /**
     * @return list<array{status: string, name: string, detail: string}>
     */
    private function jobChecks(): array
    {
        $db = $this->databases->engine();
        $checks = [];

        $dead = (int) $db->fetchOne("SELECT COUNT(*) FROM jobs WHERE status = 'dead'");
        $checks[] = $dead === 0
            ? self::ok('jobs-dead', 'No dead-lettered jobs.')
            : self::warn('jobs-dead', $dead . ' dead job(s) need a look (jobs:list --status=dead, then jobs:retry).');

        $staleCutoff = $this->clock->now()->sub(new \DateInterval('PT300S'))->format('Y-m-d\TH:i:s\Z');
        $stale = (int) $db->fetchOne("SELECT COUNT(*) FROM jobs WHERE status = 'running' AND heartbeat_at < ?", [$staleCutoff]);
        $checks[] = $stale === 0
            ? self::ok('jobs-stale', 'No stale running jobs.')
            : self::warn('jobs-stale', $stale . ' running job(s) with a stale heartbeat — the next sweep will reclaim them.');

        try {
            $byQueue = $db->fetchAllKeyValue(
                "SELECT queue, COUNT(*) FROM jobs WHERE status = 'pending' GROUP BY queue",
            );
        } catch (\Throwable) {
            $byQueue = [];
        }
        $backlog = array_sum(array_map(intval(...), $byQueue));
        $split = $byQueue === [] ? '' : ' (' . implode(', ', array_map(
            static fn (string $q, $n): string => $q . ': ' . $n,
            array_keys($byQueue),
            array_values($byQueue),
        )) . ')';
        $checks[] = $backlog < 50
            ? self::ok('jobs-backlog', $backlog . ' pending job(s)' . $split . '.')
            : self::warn('jobs-backlog', $backlog . ' pending jobs' . $split . ' — are the workers keeping up?');

        // A backlog with no live worker beacon on that lane means the lane's
        // worker process is down — not just busy.
        try {
            foreach ($byQueue as $queue => $count) {
                if ((int) $count > 0 && !$this->jobQueue->laneHasWorker((string) $queue)) {
                    $checks[] = self::warn(
                        'workers-alive',
                        sprintf('%d pending job(s) on "%s" but no worker has beaconed in 90s — is its queue:work process running?', (int) $count, (string) $queue),
                    );
                }
            }
        } catch (\Throwable $e) {
            $checks[] = self::warn('workers-alive', 'Could not check worker beacons: ' . $e->getMessage());
        }

        return $checks;
    }

    /**
     * @return list<array{status: string, name: string, detail: string}>
     */
    private function siteChecks(): array
    {
        $checks = [];
        $engine = $this->databases->engine();

        foreach ($this->sites->all() as $site) {
            $checks[] = $this->integrity($site->handle . '/site.db', fn () => $this->databases->site($site->handle));

            if ($site->githubRepo !== '') {
                try {
                    // Never hang on a credential prompt — fail fast instead.
                    $probe = new Process(
                        ['git', 'ls-remote', '--exit-code', $site->githubRepo, $site->githubBranch],
                        null,
                        ['GIT_TERMINAL_PROMPT' => '0', 'GIT_SSH_COMMAND' => 'ssh -oBatchMode=yes -oStrictHostKeyChecking=accept-new'],
                        null,
                        30,
                    );
                    $probe->run();
                    $checks[] = $probe->isSuccessful()
                        ? self::ok($site->handle . '/git', 'Remote reachable, branch exists.')
                        : self::warn($site->handle . '/git', 'Cannot reach ' . $site->githubRepo . ' — publishes will fail until fixed.');
                } catch (\Throwable $e) {
                    $checks[] = self::warn($site->handle . '/git', 'Remote probe failed: ' . $e->getMessage());
                }
            } elseif ($site->serveMode === 'git') {
                $checks[] = self::warn($site->handle . '/git', 'No GitHub repo configured — publishes stay local.');
            }

            // Only ftp sites have a target worth probing, and a probe opens a
            // real connection — never do that for a site that does not publish
            // this way. WARN, never FAIL: an unreachable customer host is an
            // operational problem to fix, not a broken engine.
            if ($site->serveMode === 'ftp') {
                try {
                    $probe = $this->ftp->test($site);
                    $checks[] = $probe['ok']
                        ? self::ok($site->handle . '/ftp', $probe['note'])
                        : self::warn($site->handle . '/ftp', 'Cannot publish to the site\'s own host — ' . $probe['note']);
                } catch (\Throwable $e) {
                    $checks[] = self::warn($site->handle . '/ftp', 'Upload target probe failed: ' . $e->getMessage());
                }
            }

            // Forms. Only reported when the site actually HAS one — a site with
            // no forms has nothing to say about mail. The check is deliberately
            // about undelivered submissions rather than about SMTP being blank:
            // a form quietly collecting leads that nobody is notified about is
            // the expensive failure here, and it looks perfectly healthy from
            // every other angle. Never a probe — that would open a connection
            // to a customer's mail server on every doctor run.
            try {
                $forms = $this->forms->list($site->handle);
                if ($forms !== []) {
                    $counts = $this->forms->counts($site->handle);
                    $failed = array_sum(array_column($counts, 'failed'));
                    $pending = array_sum(array_column($counts, 'pending'));

                    if (!$site->smtpConfigured()) {
                        $checks[] = self::warn(
                            $site->handle . '/forms',
                            \count($forms) . ' form(s) but no SMTP configured — submissions are stored, nobody is emailed.',
                        );
                    } elseif ($failed > 0) {
                        $checks[] = self::warn(
                            $site->handle . '/forms',
                            $failed . ' submission(s) failed to send — check the Forms screen, fix the mail settings, then Retry failed sends.',
                        );
                    } else {
                        $checks[] = self::ok(
                            $site->handle . '/forms',
                            \count($forms) . ' form(s) via ' . $site->smtpHost
                                . ($pending > 0 ? ', ' . $pending . ' queued' : '') . '.',
                        );
                    }
                }
            } catch (\Throwable $e) {
                $checks[] = self::warn($site->handle . '/forms', 'Form check failed: ' . $e->getMessage());
            }

            try {
                $failedMedia = (int) $this->databases->site($site->handle)->fetchOne(
                    "SELECT COUNT(*) FROM media WHERE status = 'failed'",
                );
                $checks[] = $failedMedia === 0
                    ? self::ok($site->handle . '/media', 'No failed media.')
                    : self::warn($site->handle . '/media', $failedMedia . ' media item(s) failed processing — check the media library.');
            } catch (\Throwable $e) {
                $checks[] = self::warn($site->handle . '/media', 'Could not read media table: ' . $e->getMessage());
            }

            try {
                $failedCmp = (int) $this->databases->site($site->handle)->fetchOne(
                    "SELECT COUNT(*) FROM components WHERE status = 'failed'",
                );
                $checks[] = $failedCmp === 0
                    ? self::ok($site->handle . '/components', 'No failed components.')
                    : self::warn($site->handle . '/components', $failedCmp . ' component(s) failed to compile — re-upload after fixing.');
            } catch (\Throwable $e) {
                $checks[] = self::warn($site->handle . '/components', 'Could not read components: ' . $e->getMessage());
            }

            if ($site->locales !== []) {
                try {
                    $failedTrans = (int) $this->databases->site($site->handle)->fetchOne(
                        "SELECT COUNT(*) FROM content_translations WHERE status = 'failed'",
                    );
                    $checks[] = $failedTrans === 0
                        ? self::ok($site->handle . '/i18n', \count($site->locales) . ' locale(s), no failed translations.')
                        : self::warn($site->handle . '/i18n', $failedTrans . ' translation(s) failed — run `translate ' . $site->handle . '` after checking ENDPOINTR_URL/KEY.');
                } catch (\Throwable $e) {
                    $checks[] = self::warn($site->handle . '/i18n', 'Could not read translations: ' . $e->getMessage());
                }
            }

            $buildDir = $this->databases->sitePath($site->handle) . '/build';
            if (is_dir($buildDir)) {
                $files = iterator_count(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($buildDir, \FilesystemIterator::SKIP_DOTS)));
                if ($files >= 18000) {
                    $checks[] = self::fail($site->handle . '/dist-count', $files . ' files in the build — at the Cloudflare Pages ~20k cap. Flip old media to R2.');
                } elseif ($files >= 15000) {
                    $checks[] = self::warn($site->handle . '/dist-count', $files . ' files in the build — approaching the CF Pages ~20k cap.');
                } else {
                    $checks[] = self::ok($site->handle . '/dist-count', $files . ' files in the build.');
                }
            }

            try {
                $lastBuild = $engine->fetchOne(
                    "SELECT started_at FROM builds WHERE site_handle = ? AND status = 'done' ORDER BY id DESC LIMIT 1",
                    [$site->handle],
                );
                $newest = $this->databases->site($site->handle)->fetchOne(
                    "SELECT MAX(updated_at) FROM content WHERE status = 'published' AND deleted_at IS NULL",
                );
                if ($newest !== false && $newest !== null && ($lastBuild === false || (string) $newest > (string) $lastBuild)) {
                    $checks[] = self::warn($site->handle . '/fresh', 'Published content is newer than the last completed build — a publish is due.');
                } else {
                    $checks[] = self::ok($site->handle . '/fresh', 'Deployed output is up to date with content.');
                }
            } catch (\Throwable $e) {
                $checks[] = self::warn($site->handle . '/fresh', 'Cannot determine freshness: ' . $e->getMessage());
            }
        }

        return $checks;
    }

    /**
     * @param \Closure(): \Doctrine\DBAL\Connection $open
     * @return array{status: string, name: string, detail: string}
     */
    private function integrity(string $label, \Closure $open): array
    {
        try {
            $result = (string) $open()->fetchOne('PRAGMA quick_check');

            return $result === 'ok'
                ? self::ok($label, 'Integrity check passed.')
                : self::fail($label, 'Integrity check reports: ' . $result);
        } catch (\Throwable $e) {
            return self::fail($label, 'Cannot open: ' . $e->getMessage());
        }
    }

    /** @return array{status: string, name: string, detail: string} */
    private static function ok(string $name, string $detail): array
    {
        return ['status' => 'OK', 'name' => $name, 'detail' => $detail];
    }

    /** @return array{status: string, name: string, detail: string} */
    private static function warn(string $name, string $detail): array
    {
        return ['status' => 'WARN', 'name' => $name, 'detail' => $detail];
    }

    /** @return array{status: string, name: string, detail: string} */
    private static function fail(string $name, string $detail): array
    {
        return ['status' => 'FAIL', 'name' => $name, 'detail' => $detail];
    }
}
