<?php

declare(strict_types=1);

namespace Fastsite\Form\Job;

use Fastsite\Form\Service\FormService;
use Fastsite\Shared\Queue\Job;
use Fastsite\Shared\Queue\JobContext;
use Fastsite\Shared\Queue\JobHandler;
use Fastsite\Site\Repository\SiteRepository;
use Psr\Log\LoggerInterface;

/**
 * Deletes flagged submissions once they are past the site's retention window.
 *
 * This is the half of "auto-delete the spam" that makes the other half safe.
 * Deleting at flag time would mean a scoring bug in its first week leaves no
 * evidence — a real enquiry that scored wrong would be gone, and nobody would
 * ever know it had arrived. Holding for a window and then deleting gives the
 * mistake somewhere to be found, and still stops the spam folder becoming the
 * next thing nobody empties.
 *
 * Deliberately NOT under SiteLock: it writes only to the submissions table and
 * touches no site files, so taking the lock would park it behind every build.
 */
final class PurgeSpamHandler implements JobHandler
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly FormService $forms,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function type(): string
    {
        return 'purge_spam';
    }

    public function run(Job $job, JobContext $context): void
    {
        // No site on the payload = sweep every site, which is how the nightly
        // schedule fires it. A site handle narrows it to one.
        $handle = $job->siteHandle;
        $sites = $handle !== null
            ? array_filter([$this->sites->findByHandle($handle)])
            : $this->sites->all();

        $total = 0;
        foreach ($sites as $site) {
            if ($site->deletedAt !== null) {
                continue;
            }

            $context->heartbeat();

            $deleted = $this->forms->purgeSpam($site->handle, $site->spamRetentionDays);
            $total += $deleted;

            if ($deleted > 0) {
                $this->logger->info('Purged held spam submissions', [
                    'site' => $site->handle,
                    'deleted' => $deleted,
                    'retention_days' => $site->spamRetentionDays,
                ]);
            }
        }

        if ($total > 0) {
            $this->logger->info('Spam purge complete', ['deleted' => $total]);
        }
    }
}
