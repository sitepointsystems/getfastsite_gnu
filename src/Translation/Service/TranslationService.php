<?php

declare(strict_types=1);

namespace Fastsite\Translation\Service;

use Fastsite\Content\Dto\Content;
use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Content\Service\ContentSanitizer;
use Fastsite\Content\Service\ContentService;
use Fastsite\Content\Service\Slugger;
use Fastsite\Ai\AiClientFactory;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Translation\Dto\ContentTranslation;
use Fastsite\Translation\ParallelTranslator;
use Fastsite\Translation\Repository\TranslationRepository;
use Fastsite\Translation\TranslationException;
use Fastsite\Translation\TranslationLog;
use Fastsite\Translation\TranslationPrompt;
use Fastsite\Translation\Translator;
use Psr\Log\LoggerInterface;

/**
 * Keeps per-locale translations of published content current. Staleness is
 * row-level: a translation whose source_hash != the source content's
 * content_hash is re-translated. A `manual` translation is never
 * overwritten. A translation that never succeeds stays absent, and the
 * build simply omits that page for that locale (no source-language
 * fallback — that would be duplicate content).
 */
final class TranslationService
{
    /**
     * Fields the translation covers must match ContentService::hash — the
     * five classic fields in source-hash order, plus the structured fragments
     * (intro/tldr/conclusion), which the hash appends only when at least one
     * is non-NULL (so pre-fragment rows keep their hashes and never go
     * spuriously stale).
     */
    public function __construct(
        private readonly ContentRepository $content,
        private readonly TranslationRepository $translations,
        private readonly AiClientFactory $ai,
        private readonly ContentSanitizer $sanitizer,
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly TranslationLog $log,
        // Once per run, a rate-limited item gets ONE in-run pause of this
        // long before its single retry (tests pass 0). Default not wired in
        // DI — php-di leaves optional scalars at their default.
        private readonly int $rateLimitPauseSeconds = 30,
    ) {
    }

    /**
     * If a run gets this many CONSECUTIVE failures, the gateway is presumed
     * dead — stop rather than grind through hundreds of doomed calls while
     * holding the translate worker. With zero successes the run aborts loudly
     * (the job backs off and retries later); after at least one success it
     * stops gracefully as an incomplete chunk, so the work already done is
     * kept and the follow-up chunk re-probes the gateway with fresh items
     * first (the just-failed ones are now status='failed' and sort last).
     */
    private const DEAD_GATEWAY_FAILURES = 8;

    /**
     * TRANSPORT failures (no HTTP response at all — connect refused, silent
     * wire, token exchange down) say the gateway itself is unreachable, not
     * that one item was rejected. Two in a row is proof enough; keeping the
     * probe this short matters because each doomed call can burn its whole
     * curl budget before failing.
     */
    private const DEAD_GATEWAY_TRANSPORT_FAILURES = 2;

    /**
     * Cheap "is there anything to translate?" check — the enqueue-time guard
     * PublishTrigger uses so a multilingual site with everything current goes
     * STRAIGHT to the build lane instead of occupying a translate slot with
     * an empty job. Mirrors syncOne's staleness rules (missing, or neither
     * manual nor translated-and-current — failed rows count as pending) plus
     * syncShellStrings' per-key staleness. Pure SQL + one JSON read: no AI
     * client, no writes.
     */
    public function hasPendingWork(Site $site): bool
    {
        if ($site->locales === []) {
            return false;
        }

        $db = $this->databases->site($site->handle);
        foreach ($site->locales as $locale) {
            if ($locale === $site->primaryLocale) {
                continue;
            }
            $pending = $db->fetchOne(
                "SELECT 1 FROM content c
                 LEFT JOIN content_translations t ON t.content_id = c.id AND t.locale = ?
                 WHERE c.type IN ('post', 'page') AND c.status = 'published' AND c.deleted_at IS NULL
                   AND (t.id IS NULL OR (t.status != 'manual' AND NOT (t.status = 'translated' AND t.source_hash = c.content_hash)))
                 LIMIT 1",
                [$locale],
            );
            if ($pending !== false) {
                return true;
            }
        }

        return $this->shellStringsPending($site);
    }

    /** Whether any shell UI string is missing or stale for any extra locale. */
    private function shellStringsPending(Site $site): bool
    {
        $primaryFile = $this->databases->sitePath($site->handle) . '/shell/i18n/' . $site->primaryLocale . '.json';
        if (!is_file($primaryFile)) {
            return false;
        }
        $source = json_decode((string) file_get_contents($primaryFile), true);
        if (!\is_array($source) || $source === []) {
            return false;
        }

        $rows = $this->databases->site($site->handle)->fetchAllAssociative(
            "SELECT ref, locale, source_hash, status FROM string_translations WHERE scope = 'shell.ui'",
        );
        $existing = [];
        foreach ($rows as $row) {
            $existing[$row['locale'] . "\0" . $row['ref']] = $row;
        }

        foreach ($site->locales as $locale) {
            if ($locale === $site->primaryLocale) {
                continue;
            }
            foreach ($source as $key => $value) {
                $row = $existing[$locale . "\0" . (string) $key] ?? null;
                if ($row === null) {
                    return true;
                }
                $current = $row['status'] === 'manual'
                    || ($row['status'] === 'translated' && $row['source_hash'] === hash('sha256', (string) $value));
                if (!$current) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Translates published content into every configured locale that is
     * missing or stale. Returns [translated, failed, skipped, complete].
     *
     * $heartbeat, when supplied, is invoked after each item so a long but
     * healthy run keeps its job's heartbeat fresh and is never falsely
     * reclaimed as a dead worker.
     *
     * $maxItems caps how many items are actually ATTEMPTED (translated or
     * failed — skips are DB-only and free) in this call. When the cap stops
     * the run early, `complete` is false and the caller re-enqueues a
     * follow-up chunk; staleness makes the next chunk resume exactly where
     * this one stopped. This is the fairness valve: a 71-page × 11-locale
     * site must not hold the translate worker for hours.
     *
     * @param  ?callable  $heartbeat  called after each item (no args)
     * @param  ?int  $maxItems  max attempted items this run (null = no cap)
     * @return array{translated: int, failed: int, skipped: int, complete: bool, rate_limited: bool}
     */
    public function syncSite(Site $site, ?callable $heartbeat = null, ?int $maxItems = null, ?int $concurrency = null): array
    {
        $translator = $this->ai->translatorForSite($site);
        // Mid-stream heartbeats: a large page can stream for many minutes;
        // without ticks WHILE bytes arrive, the long call either had to be
        // capped below the 600s reclaim threshold (killing slow-but-alive
        // generations) or risked false reclaim of a healthy worker.
        if ($translator instanceof \Fastsite\Translation\StreamProgressAware && $heartbeat !== null) {
            $translator->onStreamProgress(\Closure::fromCallable($heartbeat));
        }

        [$units, $skipped] = $this->plan($site, $maxItems);
        $pending = \count($units);
        $units = $maxItems !== null ? \array_slice($units, 0, $maxItems) : $units;
        // `complete` means "nothing left after this run", so it must account
        // for work the cap trimmed off — not just for how this run ended.
        $moreAfterThisRun = $pending > \count($units);

        $concurrency = $concurrency ?? $site->translateConcurrency;
        $parallel = $translator instanceof ParallelTranslator && $concurrency > 1 && \count($units) > 1;

        if ($parallel) {
            // A batch that produced NOTHING is the signature of a problem with
            // batching itself — a gateway that won't take concurrent streams,
            // an auth quirk, a bug in the pool — not proof the gateway is
            // down. Believing it killed the run, parked the job, and every
            // retry did the same thing forever. So before declaring anything
            // dead, re-run the SAME units the old way, one at a time. If the
            // sequential pass works, the site translates (slower) instead of
            // looping; if it also fails, its own breaker gives the honest
            // verdict. Rate limits are excluded — there the provider told us
            // exactly what is wrong and hammering it sequentially is worse.
            $outcome = $this->runParallel($site, $units, $translator, $concurrency, $heartbeat, throwWhenDead: false);
            if ($outcome['translated'] === 0 && $outcome['failed'] > 0 && !$outcome['rate_limited']) {
                $this->logger->warning('Parallel translation produced no successes — retrying this chunk sequentially', [
                    'site' => $site->handle, 'units' => \count($units), 'concurrency' => $concurrency,
                ]);
                $this->log->record($site->handle, '-', 'batch', 'failed', format: 'diagnostic', error: sprintf(
                    'All %d call(s) in the concurrent batch failed (concurrency %d). Falling back to one-at-a-time for this chunk. '
                    . 'If the sequential rows below succeed, the gateway does not accept concurrent requests — lower "At a time" to 1.',
                    $outcome['failed'],
                    $concurrency,
                ));
                $outcome = $this->runSequential($site, $units, $translator, $heartbeat);
            }
        } else {
            $outcome = $this->runSequential($site, $units, $translator, $heartbeat);
        }

        $outcome['skipped'] = $skipped;
        $outcome['complete'] = $outcome['complete'] && !$moreAfterThisRun;

        return $outcome;
    }

    /**
     * Everything a chunk will do, decided before a single model call: which
     * (content × locale) pairs need work, in what order, and what to ask for.
     *
     * ORDER IS THE FAIRNESS RULE. Any pair whose last attempt recorded an
     * error sorts to the BACK. The old version only demoted rows literally
     * marked status='failed' — but a STALE row whose refresh fails keeps
     * status='translated' on purpose (so the live page is never torn down)
     * and records only an `error`. Those rows were invisible to the demotion,
     * sorted first in every chunk, tripped the dead-gateway breaker with zero
     * successes, and killed the job — so a handful of permanently-failing
     * stale pages starved every translatable item behind them, forever, and
     * no amount of clicking Retry ever moved the stale count.
     *
     * @return array{0: list<array{item: Content, locale: string, existing: ?ContentTranslation, requests: array<string, array{content: string, from: string, to: string, format: string}>}>, 1: int}
     *         [units needing work (fresh first, known-bad last), items skipped as current]
     */
    private function plan(Site $site, ?int $maxItems): array
    {
        $fresh = [];
        $known = [];
        $skipped = 0;

        $content = [];
        foreach (['post', 'page'] as $type) {
            foreach ($this->content->listPublished($site->handle, $type) as $item) {
                $content[] = $item;
            }
        }

        foreach ($site->locales as $locale) {
            if ($locale === $site->primaryLocale) {
                continue;
            }
            // ONE query per locale instead of a find() per item — the skip
            // scan used to cost a round trip per (content × locale) on every
            // chunk, which on a large site dwarfed the translation itself.
            $existingByContent = $this->translations->allByLocale($site->handle, $locale);

            foreach ($content as $item) {
                $existing = $existingByContent[$item->id] ?? null;
                if ($existing !== null && $existing->status === 'manual') {
                    ++$skipped; // never touch a manually-authored translation
                    continue;
                }
                if ($existing !== null && $existing->status === 'translated' && $existing->sourceHash === $item->contentHash) {
                    ++$skipped; // current — nothing to do
                    continue;
                }

                $unit = [
                    'item' => $item,
                    'locale' => $locale,
                    'existing' => $existing,
                    'requests' => $this->requestsFor($site, $item, $locale),
                ];
                if ($existing?->error !== null) {
                    $known[] = $unit;
                } else {
                    $fresh[] = $unit;
                }
            }

            // Enough fresh work to fill the chunk already: stop planning.
            // Strictly GREATER than the cap, not equal — the caller decides
            // "is there more after this run?" by comparing the plan against
            // the cap, so a plan that stopped at exactly the cap while more
            // locales were unscanned would report the site complete and
            // strand every remaining locale until something else happened to
            // trigger a sync. One extra locale scanned is a cheap price.
            if ($maxItems !== null && \count($fresh) > $maxItems) {
                break;
            }
        }

        return [[...$fresh, ...$known], $skipped];
    }

    /**
     * The model calls one (content × locale) pair needs, keyed by field.
     *
     * The short metadata travels as ONE 'fields' request rather than four:
     * title, excerpt, seo_title and seo_description are tiny strings whose
     * per-call latency completely dominated their token cost.
     *
     * @return array<string, array{content: string, from: string, to: string, format: string}>
     */
    private function requestsFor(Site $site, Content $item, string $locale): array
    {
        $make = static fn (string $content, string $format): array => [
            'content' => $content,
            'from' => $site->primaryLocale,
            'to' => $locale,
            'format' => $format,
        ];

        $requests = ['fields' => $make(
            (string) json_encode($this->plainFields($item), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            TranslationPrompt::FORMAT_FIELDS,
        )];
        if ($item->bodyHtml !== '') {
            $requests['body'] = $make($item->bodyHtml, TranslationPrompt::FORMAT_HTML);
        }
        foreach (['intro' => $item->intro, 'tldr' => $item->tldr, 'conclusion' => $item->conclusion] as $key => $source) {
            if ($source !== null && $source !== '') {
                $requests[$key] = $make($source, TranslationPrompt::FORMAT_HTML);
            }
        }

        return $requests;
    }

    /**
     * One unit at a time, stopping the moment the gateway looks dead. This is
     * the path for translators that cannot batch (and the single-unit case),
     * and it keeps the original per-item circuit-breaker semantics exactly.
     *
     * @param  list<array{item: Content, locale: string, existing: ?ContentTranslation, requests: array<string, array{content: string, from: string, to: string, format: string}>}>  $units
     * @return array{translated: int, failed: int, skipped: int, complete: bool, rate_limited: bool}
     */
    private function runSequential(Site $site, array $units, Translator $translator, ?callable $heartbeat): array
    {
        $translated = 0;
        $failed = 0;
        $consecutiveFailures = 0;
        $consecutiveTransportFailures = 0;
        $pausedForRateLimit = false;

        foreach ($units as $unit) {
            $result = $this->applyOne($site, $unit, $this->callSequential($site, $unit, $translator, $heartbeat), $translator);

            // Rate limit: the item was never rejected — the provider asked us
            // to slow down, and hammering the next item would make it worse.
            // Once per run: pause briefly and retry the SAME item. Still
            // limited → stop the chunk and report it, so the job handler
            // parks the follow-up with a real delay.
            if ($result === 'failed-ratelimit' && !$pausedForRateLimit) {
                $pausedForRateLimit = true;
                $this->rateLimitPause($heartbeat);
                $result = $this->applyOne($site, $unit, $this->callSequential($site, $unit, $translator, $heartbeat), $translator);
            }
            if ($result === 'failed-ratelimit') {
                $this->logger->warning('Translation rate-limited — parking the run', ['site' => $site->handle, 'locale' => $unit['locale']]);

                return ['translated' => $translated, 'failed' => $failed, 'skipped' => 0, 'complete' => false, 'rate_limited' => true];
            }

            $translated += $result === 'translated' ? 1 : 0;
            $failed += $result === 'translated' ? 0 : 1;
            if ($result === 'translated') {
                $consecutiveFailures = 0;
                $consecutiveTransportFailures = 0;
            } else {
                ++$consecutiveFailures;
                // A non-transport failure means the gateway DID respond — it
                // is reachable, so the fast transport breaker resets.
                $consecutiveTransportFailures = $result === 'failed-transport'
                    ? $consecutiveTransportFailures + 1
                    : 0;
            }

            if ($heartbeat !== null) {
                $heartbeat();
            }

            // Circuit-breakers: this many failures in a row means the gateway
            // (or the key) is rejecting everything right now — and
            // consecutive TRANSPORT failures (no HTTP response at all) prove
            // it even faster, before hours of doomed calls.
            $gatewayDead = $consecutiveFailures >= self::DEAD_GATEWAY_FAILURES
                || $consecutiveTransportFailures >= self::DEAD_GATEWAY_TRANSPORT_FAILURES;
            if ($gatewayDead) {
                $this->abortOrStop($translated, $failed);

                // Successes earlier in the run: keep them, stop this chunk
                // gracefully. The follow-up chunk starts with the remaining
                // fresh items and re-probes the gateway.
                return ['translated' => $translated, 'failed' => $failed, 'skipped' => 0, 'complete' => false, 'rate_limited' => false];
            }
        }

        return ['translated' => $translated, 'failed' => $failed, 'skipped' => 0, 'complete' => true, 'rate_limited' => false];
    }

    /**
     * The whole chunk's calls in flight at once, then every unit applied.
     *
     * The circuit-breakers keep their MEANING but not their mechanism: with
     * requests overlapping there is no "consecutive" any more, so the run is
     * judged on its totals — zero successes plus a dead-gateway-sized pile of
     * failures still aborts loudly, and any rate limit still parks the chunk.
     * A batch that produced successes always keeps them.
     *
     * @param  list<array{item: Content, locale: string, existing: ?ContentTranslation, requests: array<string, array{content: string, from: string, to: string, format: string}>}>  $units
     * @return array{translated: int, failed: int, skipped: int, complete: bool, rate_limited: bool}
     */
    private function runParallel(
        Site $site,
        array $units,
        ParallelTranslator $translator,
        int $concurrency,
        ?callable $heartbeat,
        bool $throwWhenDead = true,
    ): array {
        $flat = [];
        foreach ($units as $i => $unit) {
            foreach ($unit['requests'] as $field => $request) {
                $flat[$i . '#' . $field] = $request;
            }
        }

        $started = microtime(true);
        $responses = $translator->translateAll($flat, $concurrency);
        $batchMs = (int) round((microtime(true) - $started) * 1000);
        if ($heartbeat !== null) {
            $heartbeat();
        }
        $this->logBatch($site, $units, $responses, $batchMs);

        // A rate limit mid-batch means the provider wants us to back off, so
        // retrying the limited units immediately would be the wrong move —
        // but the batch's finished work is still good. Pause once, retry only
        // the limited units, then park whatever is still limited.
        if ($this->anyRateLimited($responses)) {
            $retry = [];
            foreach ($units as $i => $unit) {
                foreach (array_keys($unit['requests']) as $field) {
                    if (($responses[$i . '#' . $field] ?? null) instanceof TranslationException
                        && $responses[$i . '#' . $field]->rateLimited) {
                        $retry += array_combine(
                            array_map(static fn (string $f): string => $i . '#' . $f, array_keys($unit['requests'])),
                            array_values($unit['requests']),
                        );
                        break;
                    }
                }
            }
            $this->rateLimitPause($heartbeat);
            $started = microtime(true);
            $retried = $translator->translateAll($retry, $concurrency);
            foreach ($retried as $key => $value) {
                $responses[$key] = $value;
            }
            if ($heartbeat !== null) {
                $heartbeat();
            }
            $this->logBatch($site, $units, $retried, (int) round((microtime(true) - $started) * 1000));
        }

        $translated = 0;
        $failed = 0;
        $transportFailed = 0;
        $rateLimited = false;

        foreach ($units as $i => $unit) {
            $results = [];
            foreach (array_keys($unit['requests']) as $field) {
                $results[$field] = $responses[$i . '#' . $field] ?? new TranslationException('no response for ' . $field, transport: true);
            }
            $result = $this->applyOne($site, $unit, $results, $translator);

            match ($result) {
                'translated' => ++$translated,
                'failed-ratelimit' => $rateLimited = true,
                'failed-transport' => ++$transportFailed,
                default => null,
            };
            $failed += $result === 'translated' ? 0 : 1;
            if ($heartbeat !== null) {
                $heartbeat();
            }
        }

        if ($rateLimited) {
            $this->logger->warning('Translation rate-limited — parking the run', ['site' => $site->handle, 'translated' => $translated]);

            return ['translated' => $translated, 'failed' => $failed, 'skipped' => 0, 'complete' => false, 'rate_limited' => true];
        }

        $gatewayDead = $failed >= self::DEAD_GATEWAY_FAILURES
            || $transportFailed >= self::DEAD_GATEWAY_TRANSPORT_FAILURES;
        if ($gatewayDead) {
            // When the caller has a sequential fallback left to try, an
            // all-failed batch is a hypothesis, not a verdict — let it re-run
            // the units one at a time before anything is declared dead.
            if ($throwWhenDead) {
                $this->abortOrStop($translated, $failed);
            }

            return ['translated' => $translated, 'failed' => $failed, 'skipped' => 0, 'complete' => false, 'rate_limited' => false];
        }

        return ['translated' => $translated, 'failed' => $failed, 'skipped' => 0, 'complete' => true, 'rate_limited' => false];
    }

    /**
     * Logs every call in a finished batch. Concurrent calls have no
     * meaningful individual duration, so each row carries the BATCH's elapsed
     * time and is flagged `batched` — the screen labels it as such rather than
     * implying each call took that long.
     *
     * @param  list<array{item: Content, locale: string, existing: ?ContentTranslation, requests: array<string, array{content: string, from: string, to: string, format: string}>}>  $units
     * @param  array<string, string|TranslationException>  $responses
     */
    private function logBatch(Site $site, array $units, array $responses, int $batchMs): void
    {
        foreach ($units as $i => $unit) {
            foreach ($unit['requests'] as $field => $request) {
                $value = $responses[$i . '#' . $field] ?? null;
                if ($value === null) {
                    continue; // not part of this batch (a targeted rate-limit retry)
                }
                $this->logCall(
                    $site,
                    $unit,
                    (string) $field,
                    $request,
                    // logCall measures from a start time; hand it one that
                    // reproduces the batch elapsed.
                    microtime(true) - ($batchMs / 1000),
                    response: $value instanceof TranslationException ? '' : $value,
                    error: $value instanceof TranslationException ? $value : null,
                    batched: true,
                );
            }
        }
    }

    /**
     * The dead-gateway verdict shared by both executors: with nothing to show
     * for the run, throw so the job backs off and retries later; with
     * successes banked, return so they keep their build+publish chain.
     */
    private function abortOrStop(int $translated, int $failed): void
    {
        if ($translated === 0) {
            throw new TranslationException(
                'Translation aborted: ' . $failed . ' failures with no successes — gateway appears unavailable.',
            );
        }
    }

    /** @param array<string, string|TranslationException> $responses */
    private function anyRateLimited(array $responses): bool
    {
        foreach ($responses as $value) {
            if ($value instanceof TranslationException && $value->rateLimited) {
                return true;
            }
        }

        return false;
    }

    private function rateLimitPause(?callable $heartbeat): void
    {
        if ($heartbeat !== null) {
            $heartbeat();
        }
        if ($this->rateLimitPauseSeconds > 0) {
            sleep($this->rateLimitPauseSeconds);
        }
        if ($heartbeat !== null) {
            $heartbeat();
        }
    }

    /**
     * Runs a unit's requests one after another, stopping at the FIRST
     * failure — the remaining fields of an item whose title just failed are
     * certain to fail the same way, and burning their calls only slows the
     * dead-gateway verdict down.
     *
     * @param  array<string, array{content: string, from: string, to: string, format: string}>  $requests
     * @return array<string, string|TranslationException>
     */
    private function callSequential(Site $site, array $unit, Translator $translator, ?callable $heartbeat): array
    {
        $out = [];
        foreach ($unit['requests'] as $field => $request) {
            $started = microtime(true);
            try {
                $answer = $translator->translate($request['content'], $request['from'], $request['to'], $request['format']);
                $out[$field] = $answer;
                $this->logCall($site, $unit, (string) $field, $request, $started, response: $answer);
            } catch (TranslationException $e) {
                $out[$field] = $e;
                $this->logCall($site, $unit, (string) $field, $request, $started, error: $e);

                return $out;
            }
            if ($heartbeat !== null) {
                $heartbeat();
            }
        }

        return $out;
    }

    /**
     * One row in the site's translation log — what we asked, what came back
     * (verbatim, clipped), how long it took, and the provider's own error when
     * there was one. This is the only place a user can SEE what the model
     * actually returns; the app logger writes to a file inside the container.
     *
     * @param  array{item: Content, locale: string, existing: ?ContentTranslation, requests: array<string, array{content: string, from: string, to: string, format: string}>}  $unit
     * @param  array{content: string, from: string, to: string, format: string}  $request
     */
    private function logCall(
        Site $site,
        array $unit,
        string $field,
        array $request,
        float $startedAt,
        string $response = '',
        ?TranslationException $error = null,
        bool $batched = false,
    ): void {
        $status = 'ok';
        if ($error !== null) {
            $status = $error->rateLimited ? 'rate_limited' : ($error->transport ? 'transport' : 'failed');
        }

        $this->log->record(
            $site->handle,
            $unit['locale'],
            $field,
            $status,
            contentId: $unit['item']->id,
            title: $unit['item']->title,
            format: $request['format'],
            request: $request['content'],
            response: $response,
            error: $error?->getMessage(),
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            batched: $batched,
        );
    }

    /**
     * Explicit user retry: put every failed translation back to 'pending'
     * (clearing its error) and wipe lingering refresh errors recorded on
     * still-live stale rows, so the dashboard shows a clean slate the moment
     * Retry is clicked instead of day-old error messages. The rows still
     * count as pending work (syncSite attempts pending/missing/stale alike).
     * Returns how many failed rows were reset.
     */
    public function resetFailed(Site $site): int
    {
        $db = $this->databases->site($site->handle);
        $reset = (int) $db->executeStatement(
            "UPDATE content_translations SET status = 'pending', error = NULL, source_hash = '', updated_at = ?
             WHERE status = 'failed'",
            [$this->clock->iso()],
        );
        $db->executeStatement('UPDATE content_translations SET error = NULL WHERE error IS NOT NULL');

        return $reset;
    }

    /**
     * Translates the shell UI strings (nav labels, "read more", …) from the
     * primary locale's shell/i18n/{primary}.json into shell/i18n/{locale}.json
     * for each locale, with per-key staleness (string_translations). The
     * build reads those files, so a Danish page gets Danish nav.
     *
     * $heartbeat, when supplied, is invoked after each translated key so a
     * large string table keeps the job's heartbeat fresh.
     *
     * @param  ?callable  $heartbeat  called after each key (no args)
     */
    public function syncShellStrings(Site $site, ?callable $heartbeat = null): void
    {
        $shellDir = $this->databases->sitePath($site->handle) . '/shell/i18n';
        $primaryFile = $shellDir . '/' . $site->primaryLocale . '.json';
        if (!is_file($primaryFile)) {
            return;
        }
        $source = json_decode((string) file_get_contents($primaryFile), true);
        if (!\is_array($source) || $source === []) {
            return;
        }

        $db = $this->databases->site($site->handle);
        $translator = $this->ai->translatorForSite($site);
        if ($translator instanceof \Fastsite\Translation\StreamProgressAware && $heartbeat !== null) {
            $translator->onStreamProgress(\Closure::fromCallable($heartbeat));
        }

        foreach ($site->locales as $locale) {
            if ($locale === $site->primaryLocale) {
                continue;
            }
            $out = [];
            foreach ($source as $key => $value) {
                $value = (string) $value;
                $hash = hash('sha256', $value);
                $existing = $db->fetchAssociative(
                    'SELECT value, source_hash, status FROM string_translations WHERE scope = ? AND ref = ? AND locale = ?',
                    ['shell.ui', (string) $key, $locale],
                );

                if ($existing !== false && ($existing['status'] === 'manual' || ($existing['status'] === 'translated' && $existing['source_hash'] === $hash))) {
                    $out[$key] = (string) $existing['value'];
                    continue;
                }

                $started = microtime(true);
                try {
                    $translated = $translator->translate($value, $site->primaryLocale, $locale, 'text');
                    $this->log->record(
                        $site->handle, $locale, 'shell:' . $key, 'ok',
                        format: 'text', request: $value, response: $translated,
                        durationMs: (int) round((microtime(true) - $started) * 1000),
                    );
                } catch (TranslationException $e) {
                    $this->log->record(
                        $site->handle, $locale, 'shell:' . $key,
                        $e->rateLimited ? 'rate_limited' : ($e->transport ? 'transport' : 'failed'),
                        format: 'text', request: $value, error: $e->getMessage(),
                        durationMs: (int) round((microtime(true) - $started) * 1000),
                    );
                    // Gateway unreachable: abort the whole sync instead of
                    // grinding a doomed call per key and then writing a
                    // wholesale source-language file — the job retries when
                    // the gateway is back.
                    if ($e->transport) {
                        throw $e;
                    }
                    $out[$key] = $value; // fall back to source string for this key
                    $this->logger->warning('Shell string translation failed', ['site' => $site->handle, 'key' => $key, 'locale' => $locale, 'error' => $e->getMessage()]);
                    if ($heartbeat !== null) {
                        $heartbeat();
                    }
                    continue;
                }

                $db->executeStatement(
                    'INSERT INTO string_translations (scope, ref, locale, source_hash, value, status, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (scope, ref, locale) DO UPDATE
                     SET source_hash = excluded.source_hash, value = excluded.value, status = excluded.status, updated_at = excluded.updated_at',
                    ['shell.ui', (string) $key, $locale, $hash, $translated, 'translated', $this->clock->iso()],
                );
                $out[$key] = $translated;

                if ($heartbeat !== null) {
                    $heartbeat();
                }
            }

            file_put_contents($shellDir . '/' . $locale . '.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * Turns one unit's raw model output into a stored translation — or into
     * the right KIND of failure. Pure bookkeeping: it never calls a model, so
     * both executors share every trust and failure rule.
     *
     * @param  array{item: Content, locale: string, existing: ?ContentTranslation, requests: array<string, array{content: string, from: string, to: string, format: string}>}  $unit
     * @param  array<string, string|TranslationException>  $results  by field
     * @return 'translated'|'failed'|'failed-transport'|'failed-ratelimit'
     */
    private function applyOne(Site $site, array $unit, array $results, Translator $translator): string
    {
        $item = $unit['item'];
        $locale = $unit['locale'];
        $existing = $unit['existing'];

        foreach ($results as $value) {
            if ($value instanceof TranslationException) {
                return $this->recordFailure($site, $item, $locale, $existing, $value);
            }
        }

        // Verbatim is a property of the CONTENT, not of one write: a body
        // stored under a trusted port (per-row sanitize:false, or the
        // site-wide default) must survive translation intact. Sanitizing it
        // here silently deleted from every translated page exactly what
        // verbatim mode exists to keep — interactive markup, <template>,
        // <button>/<input>, svg and framework attributes — so the primary
        // locale rendered a working page and all others rendered a husk.
        // Model output is untrusted on a SANITIZING site (a prompt-injected
        // source could make it emit <script>), so it is re-sanitized exactly
        // like ingest before being stored and rendered |raw. On trusted
        // content the source could already carry scripts, so re-wrapping
        // without the allowlist adds no new authority.
        $trust = fn (string $html, string $source): string => !$site->sanitizeContent || ContentService::isVerbatim($source)
            ? ContentService::wrapVerbatim($html)
            : $this->sanitizer->sanitize($html)->html;

        $fields = $this->decodeFields((string) $results['fields'], array_keys($this->plainFields($item)));
        if ($fields === null) {
            // The model ignored the JSON shape. Rather than store garbage or
            // fail a page over formatting, fall back to one call per field —
            // slower, but exactly the pre-batching behavior.
            $fields = $this->translateFieldsIndividually($translator, $site, $item, $locale);
            if ($fields instanceof TranslationException) {
                return $this->recordFailure($site, $item, $locale, $existing, $fields);
            }
        }

        $body = isset($results['body']) ? $trust((string) $results['body'], $item->bodyHtml) : '';
        // Structured fragments are HTML like the body — same trust rule,
        // decided per fragment so a verbatim intro is not flattened by a
        // sanitized body (ingest stores each under its own wrapper).
        $fragments = [];
        foreach (['intro' => $item->intro, 'tldr' => $item->tldr, 'conclusion' => $item->conclusion] as $key => $source) {
            $fragments[$key] = isset($results[$key]) && $source !== null && $source !== ''
                ? $trust((string) $results[$key], $source)
                : null;
        }

        // Keep an existing (non-manual) slug stable across re-translation.
        $slug = $existing !== null && $existing->slug !== '' ? $existing->slug : $this->localeSlug($site, $locale, $item, $fields['title']);

        $this->translations->upsert($site->handle, $item->id, $locale, [
            'slug' => $slug,
            'title' => $fields['title'],
            'excerpt' => $fields['excerpt'] ?? '',
            'body_html' => $body,
            'seo_title' => $fields['seo_title'] ?? '',
            'seo_description' => $fields['seo_description'] ?? '',
            'intro' => $fragments['intro'],
            'tldr' => $fragments['tldr'],
            'conclusion' => $fragments['conclusion'],
            'source_hash' => $item->contentHash,
            'status' => 'translated',
            'error' => null,
        ]);

        return 'translated';
    }

    /**
     * @return 'failed'|'failed-transport'|'failed-ratelimit'
     */
    private function recordFailure(Site $site, Content $item, string $locale, ?ContentTranslation $existing, TranslationException $e): string
    {
        $this->logger->warning('Translation failed', ['site' => $site->handle, 'content' => $item->id, 'locale' => $locale, 'error' => $e->getMessage()]);

        // Rate limit: nothing item-specific happened and the caller
        // pauses/parks — never brand the row, never record an error.
        if ($e->rateLimited) {
            return 'failed-ratelimit';
        }
        $failure = $e->transport ? 'failed-transport' : 'failed';

        // A live (translated) page that is merely stale must NOT be torn down
        // by a transient upstream blip — keep the old translation published;
        // it stays stale (source_hash unchanged) and retries next sync. Only
        // mark failed when there is no usable page yet. Still record WHY the
        // refresh failed (error only — status and source_hash untouched) so
        // the dashboard can show a current reason, and so the next chunk
        // sorts this known-bad row behind fresh work.
        if ($existing !== null && $existing->status === 'translated') {
            $this->translations->upsert($site->handle, $item->id, $locale, ['error' => $e->getMessage()]);

            return $failure;
        }

        // A transport failure says NOTHING about this item — the gateway was
        // unreachable. Don't brand the row 'failed' (that would sort it
        // behind everything on future runs); leave it missing/pending so it
        // translates normally once the gateway is back.
        if (!$e->transport) {
            $this->translations->upsert($site->handle, $item->id, $locale, [
                'slug' => $existing->slug ?? $this->localeSlug($site, $locale, $item, ''),
                'status' => 'failed',
                'error' => $e->getMessage(),
                'source_hash' => '',
            ]);
        }

        return $failure;
    }

    /**
     * The short metadata fields that travel together in the 'fields' request.
     * Title is always present; the rest only when the source has them.
     *
     * @return array<string, string>
     */
    private function plainFields(Content $item): array
    {
        $fields = ['title' => $item->title];
        foreach (['excerpt' => $item->excerpt, 'seo_title' => $item->seoTitle, 'seo_description' => $item->seoDescription] as $key => $value) {
            if ($value !== '') {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    /**
     * Reads the model's 'fields' answer, or null when it isn't the object we
     * asked for. Lenient on purpose — models like to wrap JSON in prose or
     * fences — but strict about the one thing that matters: a title must come
     * back, since it is what the row and its slug are built from.
     *
     * @param  list<string>  $expected
     * @return array<string, string>|null
     */
    private function decodeFields(string $raw, array $expected): ?array
    {
        $raw = TranslationPrompt::stripCodeFences($raw);
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (!\is_array($decoded)) {
            return null;
        }

        $out = [];
        foreach ($expected as $key) {
            $value = $decoded[$key] ?? null;
            if (!\is_scalar($value)) {
                return null;
            }
            // These render escaped, but strip any tags the model introduced
            // so they stay plain text.
            $out[$key] = trim(strip_tags((string) $value));
        }

        return ($out['title'] ?? '') !== '' ? $out : null;
    }

    /**
     * The pre-batching path, kept as the fallback for a model that won't
     * return the JSON object: one call per field.
     *
     * @return array<string, string>|TranslationException
     */
    private function translateFieldsIndividually(Translator $translator, Site $site, Content $item, string $locale): array|TranslationException
    {
        $this->logger->warning('Translation fields batch was not valid JSON — falling back to per-field calls', [
            'site' => $site->handle, 'content' => $item->id, 'locale' => $locale,
        ]);

        $out = [];
        try {
            foreach ($this->plainFields($item) as $key => $value) {
                $out[$key] = trim(strip_tags(
                    $translator->translate($value, $site->primaryLocale, $locale, TranslationPrompt::FORMAT_TEXT),
                ));
            }
        } catch (TranslationException $e) {
            return $e;
        }

        return $out;
    }

    /**
     * Forces ONE page to re-translate into every locale on the next sync, by
     * clearing the stored source_hash so every non-manual row reads as stale.
     *
     * The reason this has to exist: a translation can be WRONG while being
     * perfectly current. When the verbatim rule changed, every already-stored
     * translation of a trusted page was a sanitized husk whose source_hash
     * still matched its source — so nothing was stale, nothing re-translated,
     * and the only way back was editing the source page to move its hash.
     *
     * Manual translations are never touched (they are authored, not derived).
     * Returns how many locale rows were reset.
     */
    public function resetContent(Site $site, int $contentId): int
    {
        return (int) $this->databases->site($site->handle)->executeStatement(
            "UPDATE content_translations
             SET source_hash = '', status = CASE WHEN status = 'failed' THEN 'pending' ELSE status END,
                 error = NULL, updated_at = ?
             WHERE content_id = ? AND status != 'manual'",
            [$this->clock->iso(), $contentId],
        );
    }

    /**
     * {@see resetContent} for the whole site — every non-manual translation
     * re-derives from its source on the next sync. The blunt instrument for
     * the state a changed translation rule leaves behind, where every stored
     * translation is wrong and none of them read as stale.
     */
    public function resetAll(Site $site): int
    {
        return (int) $this->databases->site($site->handle)->executeStatement(
            "UPDATE content_translations
             SET source_hash = '', status = CASE WHEN status = 'failed' THEN 'pending' ELSE status END,
                 error = NULL, updated_at = ?
             WHERE status != 'manual'",
            [$this->clock->iso()],
        );
    }

    private function localeSlug(Site $site, string $locale, Content $item, string $translatedTitle): string
    {
        $base = Slugger::slugify($translatedTitle !== '' ? $translatedTitle : $item->slug);
        // A translated PAGE slug must dodge the reserved root slugs (blog,
        // locale codes, …) exactly like the primary path, or it collides
        // with the per-locale directory and is dropped at build time.
        $reserved = $item->type === 'page' ? ContentService::reservedPageSlugs($site) : [];
        $candidate = $base;
        $n = 2;
        while (
            \in_array($candidate, $reserved, true)
            || $this->translations->slugTaken($site->handle, $locale, $candidate, $item->id)
        ) {
            $candidate = $base . '-' . $n;
            ++$n;
        }

        return $candidate;
    }
}
