<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Queue\JobQueue;
use Fastsite\Site\Service\SiteService;
use Fastsite\Translation\Service\TranslationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/{site}/translations/action — add-language, remove-language,
 * retry-translations.
 *
 * Retry is a real reset, not just "enqueue again": failed rows go back to
 * 'pending' (their stale error messages clear immediately), every parked
 * translate job for the site is cancelled and removed from Redis, and ONE
 * fresh job (full attempt budget) starts right away. Before this, the dedupe
 * key handed the click a backoff-parked twin that could be one failure away
 * from dead-lettering — retry then silently did nothing.
 */
final class TranslationsActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly SiteService $sites,
        private readonly JobQueue $queue,
        private readonly PublishTrigger $publish,
        private readonly TranslationService $translations,
        private readonly \Fastsite\Translation\TranslationLog $log,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $base = '/sites/' . $site->handle . '/translations';

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'add-language':
                    $locales = $this->sites->addLocale($site->handle, (string) ($body['locale'] ?? ''));
                    $uuid = $this->queue->enqueue('translate_content', $site->handle, ['trigger' => 'dashboard'], 'low', 'translate:' . $site->handle);
                    $this->queue->promoteNow($uuid);

                    return $this->dash->redirect($base, 'Locales now: ' . implode(', ', $locales) . '. Translation queued.');

                case 'remove-language':
                    $locale = (string) ($body['locale'] ?? '');
                    try {
                        $locales = $this->sites->removeLocale($site->handle, $locale);
                    } catch (\InvalidArgumentException | \RuntimeException $e) {
                        return $this->dash->redirect($base, '', $e->getMessage());
                    }
                    // Rebuild + publish so the /{locale}/ tree drops from the live site.
                    $this->publish->trigger($site, 'publish: language removed (' . $locale . ')', 'dashboard');

                    return $this->dash->redirect($base, 'Removed ' . $locale . '. Locales now: ' . (implode(', ', $locales) ?: 'primary only') . '. Rebuild + publish queued.');

                case 'retry-translations':
                    $reset = $this->translations->resetFailed($site);
                    // Both kinds of stuck job go: pending twins (parked in
                    // backoff) AND zombie "running" rows whose worker was
                    // killed mid-run — those otherwise show "Translating
                    // now" for hours while nothing happens.
                    $cancelled = $this->queue->cancelPending('translate_content', $site->handle)
                        + $this->queue->cancelStaleRunning('translate_content', $site->handle);
                    $uuid = $this->queue->enqueue('translate_content', $site->handle, ['trigger' => 'dashboard-retry'], 'low', 'translate:' . $site->handle);
                    $this->queue->promoteNow($uuid);

                    $note = 'Translation restarted';
                    if ($reset > 0) {
                        $note .= ' — ' . $reset . ' failed item' . ($reset > 1 ? 's' : '') . ' reset to queued';
                    }
                    if ($cancelled > 0) {
                        $note .= ', ' . $cancelled . ' stuck job' . ($cancelled > 1 ? 's' : '') . ' cleared';
                    }

                    return $this->dash->redirect($base, $note . '. Items translate in chunks in the background; the counts below update as they finish (refresh to follow), then the site republishes.');

                case 'retranslate-all':
                    // The blunt instrument for "every translation is wrong
                    // but none of them look stale" — the state a changed
                    // translation rule leaves behind, where source_hash still
                    // matches so nothing would ever re-run on its own.
                    $reset = $this->translations->resetAll($site);
                    $this->queue->cancelPending('translate_content', $site->handle);
                    $this->queue->cancelStaleRunning('translate_content', $site->handle);
                    $uuid = $this->queue->enqueue('translate_content', $site->handle, ['trigger' => 'dashboard-retranslate-all'], 'low', 'translate:' . $site->handle);
                    $this->queue->promoteNow($uuid);

                    return $this->dash->redirect($base, 'Re-translating everything — ' . $reset . ' language version' . ($reset === 1 ? '' : 's') . ' queued to regenerate. This runs in chunks and can take a while; the counts below follow along.');

                case 'clear-log':
                    $cleared = $this->log->clear($site->handle);

                    return $this->dash->redirect($base, 'Translation log cleared (' . $cleared . ' entr' . ($cleared === 1 ? 'y' : 'ies') . ').');

                case 'throughput':
                    $updated = $this->sites->update($site->handle, [
                        'translate_concurrency' => (int) ($body['translate_concurrency'] ?? 4),
                        'translate_chunk_size' => (int) ($body['translate_chunk_size'] ?? 10),
                    ]);

                    return $this->dash->redirect($base, 'Translation speed saved — ' . $updated->translateConcurrency . ' at a time, ' . $updated->translateChunkSize . ' per chunk. It applies to the next chunk.');

                default:
                    return $this->dash->redirect($base, '', 'Unknown action.');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect($base, '', $e->getMessage());
        }
    }
}
