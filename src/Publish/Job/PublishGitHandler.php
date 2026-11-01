<?php

declare(strict_types=1);

namespace Fastsite\Publish\Job;

use Fastsite\Publish\Service\GitPublisher;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Shared\Queue\SiteLock;
use Fastsite\Site\Repository\SiteRepository;

final class PublishGitHandler implements JobHandler
{
    public function __construct(
        private readonly GitPublisher $publisher,
        private readonly SiteRepository $sites,
        private readonly SiteLock $lock,
        private readonly SiteDatabaseManager $databases,
    ) {
    }

    public static function type(): string
    {
        return 'publish_git';
    }

    public function run(Job $job, JobContext $context): void
    {
        $handle = $job->siteHandle ?? throw new \RuntimeException('publish_git needs a site.');
        $site = $this->sites->findByHandle($handle)
            ?? throw new \RuntimeException('Unknown site: ' . $handle);

        // Sites that don't reach their audience through a git remote and have
        // no repo configured skip this step entirely: a direct-serve site goes
        // live at build time (build/ IS the tree DirectServeGate serves) and an
        // ftp site goes live when PublishFtpHandler uploads it. Either mode
        // WITH a repo still pushes: the per-publish git history is kept and the
        // site can flip back to CDN mode at any time. Every trigger path (API,
        // webhook, shell edit, scheduler, translate chain) converges here, so
        // this one check covers them all.
        if (\in_array($site->serveMode, ['direct', 'ftp'], true) && $site->githubRepo === '') {
            return;
        }

        $context->progress('Pushing build to git…');
        $result = $this->lock->withLock($handle, fn (): array => $this->publisher->publish(
            $site,
            (string) ($job->payload['message'] ?? 'publish: full build'),
            static fn () => $context->heartbeat(),
        ));

        if ($result['commit'] !== '') {
            // Stamp the most recent completed build for this site.
            $this->databases->engine()->executeStatement(
                "UPDATE builds SET commit_sha = ?
                 WHERE id = (SELECT id FROM builds WHERE site_handle = ? AND status = 'done'
                             ORDER BY id DESC LIMIT 1)",
                [$result['commit'], $handle],
            );
        }
    }
}
