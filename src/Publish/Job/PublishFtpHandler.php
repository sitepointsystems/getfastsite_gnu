<?php

declare(strict_types=1);

namespace Fastsite\Publish\Job;

use Fastsite\Publish\Service\FtpPublisher;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Shared\Queue\SiteLock;
use Fastsite\Site\Repository\SiteRepository;

final class PublishFtpHandler implements JobHandler
{
    public function __construct(
        private readonly FtpPublisher $publisher,
        private readonly SiteRepository $sites,
        private readonly SiteLock $lock,
    ) {
    }

    public static function type(): string
    {
        return 'publish_ftp';
    }

    public function run(Job $job, JobContext $context): void
    {
        $handle = $job->siteHandle ?? throw new \RuntimeException('publish_ftp needs a site.');
        $site = $this->sites->findByHandle($handle)
            ?? throw new \RuntimeException('Unknown site: ' . $handle);

        // The step is only chained for ftp sites, but a mode flip between
        // enqueue and run is entirely possible (the chain outlives the click),
        // and uploading to a host the site no longer publishes to would be
        // worse than doing nothing.
        if ($site->serveMode !== 'ftp') {
            return;
        }

        $context->progress('Uploading build to the site\'s own host…');
        $result = $this->lock->withLock($handle, fn (): array => $this->publisher->publish(
            $site,
            static fn () => $context->heartbeat(),
        ));

        $context->progress(sprintf(
            'Uploaded %d file(s), skipped %d unchanged, removed %d.',
            $result['uploaded'],
            $result['skipped'],
            $result['deleted'],
        ));
    }
}
