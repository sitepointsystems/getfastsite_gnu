<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Site\Dto\Site;
use Fastsite\Translation\Service\TranslationService;

/**
 * The one place that decides how a site gets rebuilt+published. On a
 * multilingual site WITH pending translation work it routes through
 * translate_content (refresh translations → build → publish); otherwise
 * straight to build → publish. Every content-changing trigger (API publish,
 * webhook, shell edit, scheduler) uses this so none can accidentally skip
 * translation. The pending-work check keeps empty translate jobs out of the
 * translate lane — a settings tweak on an all-current multilingual site must
 * not occupy a translate slot (or delay its own build behind one).
 */
final class PublishTrigger
{
    /**
     * Every job type that makes up the rebuild+publish pipeline. The scheduler
     * asks two questions of this list — "is a pipeline already in flight for
     * this site?" and "has this site's pipeline been dead-lettering?" — and
     * both answers are wrong if a publish step is missing from it.
     */
    public const PIPELINE_TYPES = ['translate_content', 'build_full', 'publish_git', 'publish_ftp'];

    public function __construct(
        private readonly JobQueue $queue,
        private readonly TranslationService $translations,
    ) {
    }

    /**
     * The publish steps chained after a build, in order. THE one place that
     * decides where a site's output goes — six call sites used to hardcode
     * `publish_git`, which meant a new deploy target had to be remembered in
     * six places or it would silently not happen on some trigger paths.
     *
     * An ftp site keeps its publish_git step: that handler already no-ops for
     * a non-git site with no repo, and an ftp site WITH a repo should still get
     * its git history, exactly as a direct-serve site with a repo does.
     *
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    public static function publishChain(Site $site, string $message): array
    {
        $chain = [['type' => 'publish_git', 'payload' => ['message' => $message]]];

        if ($site->serveMode === 'ftp') {
            $chain[] = ['type' => 'publish_ftp', 'payload' => ['message' => $message]];
        }

        return $chain;
    }

    public function trigger(Site $site, string $message, string $source): string
    {
        if ($site->locales !== [] && $this->needsTranslation($site)) {
            $uuid = $this->queue->enqueue(
                type: 'translate_content',
                siteHandle: $site->handle,
                payload: ['trigger' => $source, 'message' => $message],
                queue: 'low',
                dedupeKey: 'translate:' . $site->handle,
            );

            // Same reason as the build branch below: on a multilingual site
            // this is the branch a publish takes, and absorbing into a
            // backoff-parked twin would leave the click waiting out a delay of
            // up to fifteen minutes with nothing to show for it.
            $this->queue->promoteNow($uuid);

            return $uuid;
        }

        $uuid = $this->queue->enqueue(
            type: 'build_full',
            siteHandle: $site->handle,
            // Re-render every page rather than trusting build_manifest. Every
            // caller of this method is an explicit "publish now" — a content
            // save, a shell edit, a settings change, a webhook, someone
            // pressing the button — and a rebuild that skips its own output
            // because the bookkeeping said so is indistinguishable from one
            // that quietly did nothing.
            payload: ['trigger' => $source, 'force' => true],
            queue: 'high',
            dedupeKey: 'build:' . $site->handle,
            chain: self::publishChain($site, $message),
        );

        // A dedupe-absorbed enqueue returns the PENDING twin, whose payload was
        // written before this call (so it may not carry force) and which may be
        // parked on a backoff delay. Stamp the flag on and kick it, or the
        // rebuild someone just asked for silently becomes an older, incremental
        // one that runs whenever the backoff happens to expire.
        $this->queue->mergePayload($uuid, ['force' => true]);
        $this->queue->promoteNow($uuid);

        return $uuid;
    }

    private function needsTranslation(Site $site): bool
    {
        try {
            return $this->translations->hasPendingWork($site);
        } catch (\Throwable) {
            return true; // can't tell — route through translate; the job sorts it out
        }
    }
}
