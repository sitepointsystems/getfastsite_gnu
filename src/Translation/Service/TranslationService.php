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
use Fastsite\Translation\Repository\TranslationRepository;
use Fastsite\Translation\TranslationException;
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
    /** Fields the translation covers, in source-hash order (must match ContentService::hash). */
    public function __construct(
        private readonly ContentRepository $content,
        private readonly TranslationRepository $translations,
        private readonly AiClientFactory $ai,
        private readonly ContentSanitizer $sanitizer,
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * If a run gets this many failures with zero successes so far, the gateway
     * is presumed dead — abort loudly rather than grind through hundreds of
     * doomed calls while holding the only worker (the job then backs off and
     * retries later, resuming from where staleness left off).
     */
    private const DEAD_GATEWAY_FAILURES = 8;

    /**
     * Translates all published content into every configured locale that is
     * missing or stale. Returns [translated, failed, skipped] counts.
     *
     * $heartbeat, when supplied, is invoked after each item so a long but
     * healthy run keeps its job's heartbeat fresh and is never falsely
     * reclaimed as a dead worker.
     *
     * @param  ?callable  $heartbeat  called after each item (no args)
     * @return array{translated: int, failed: int, skipped: int}
     */
    public function syncSite(Site $site, ?callable $heartbeat = null): array
    {
        $translated = 0;
        $failed = 0;
        $skipped = 0;

        $translator = $this->ai->translatorForSite($site);

        foreach ($site->locales as $locale) {
            if ($locale === $site->primaryLocale) {
                continue;
            }
            foreach (['post', 'page'] as $type) {
                foreach ($this->content->listPublished($site->handle, $type) as $item) {
                    $result = $this->syncOne($site, $item, $locale, $translator, $heartbeat);
                    $translated += $result === 'translated' ? 1 : 0;
                    $failed += $result === 'failed' ? 1 : 0;
                    $skipped += $result === 'skipped' ? 1 : 0;

                    if ($heartbeat !== null) {
                        $heartbeat();
                    }

                    // Circuit-breaker: a run that has only ever failed, this
                    // many times, is hitting a dead gateway — stop and let the
                    // job retry later instead of monopolizing the worker.
                    if ($translated === 0 && $failed >= self::DEAD_GATEWAY_FAILURES) {
                        throw new TranslationException(
                            'Translation aborted: ' . $failed . ' consecutive failures with no successes — gateway appears unavailable.',
                        );
                    }
                }
            }
        }

        return ['translated' => $translated, 'failed' => $failed, 'skipped' => $skipped];
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

                try {
                    $translated = $translator->translate($value, $site->primaryLocale, $locale, 'text');
                } catch (TranslationException $e) {
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
     * $heartbeat, when supplied, is invoked after EACH field translation. An
     * item makes up to five sequential model calls (title/excerpt/body/seo);
     * ticking between them keeps the largest un-heartbeated gap to a single
     * call, so a slow large-page translation is never falsely reclaimed.
     *
     * @param  ?callable  $heartbeat  called after each field's translate() (no args)
     * @return 'translated'|'failed'|'skipped'
     */
    private function syncOne(Site $site, Content $item, string $locale, Translator $translator, ?callable $heartbeat = null): string
    {
        $existing = $this->translations->find($site->handle, $item->id, $locale);

        // Never touch a manually-authored translation.
        if ($existing !== null && $existing->status === 'manual') {
            return 'skipped';
        }
        // Current translation — nothing to do.
        if ($existing !== null && $existing->status === 'translated' && $existing->sourceHash === $item->contentHash) {
            return 'skipped';
        }

        $tick = static function () use ($heartbeat): void {
            if ($heartbeat !== null) {
                $heartbeat();
            }
        };

        try {
            $title = $translator->translate($item->title, $site->primaryLocale, $locale, 'text');
            $tick();
            $excerpt = $item->excerpt !== '' ? $translator->translate($item->excerpt, $site->primaryLocale, $locale, 'text') : '';
            $tick();
            $body = '';
            if ($item->bodyHtml !== '') {
                // The model output is UNTRUSTED (a prompt-injected source
                // could make it emit <script>), so re-sanitize it exactly
                // like ingest before it can be stored and rendered via |raw.
                $translatedBody = $translator->translate($item->bodyHtml, $site->primaryLocale, $locale, 'html');
                $tick();
                $body = $this->sanitizer->sanitize($translatedBody)->html;
            }
            // Titles/excerpts/seo are text and render escaped, but strip any
            // tags the model may have introduced so they stay plain text.
            $title = strip_tags($title);
            $excerpt = strip_tags($excerpt);
            $seoTitle = $item->seoTitle !== '' ? strip_tags($translator->translate($item->seoTitle, $site->primaryLocale, $locale, 'text')) : '';
            $tick();
            $seoDescription = $item->seoDescription !== '' ? strip_tags($translator->translate($item->seoDescription, $site->primaryLocale, $locale, 'text')) : '';
            $tick();
        } catch (TranslationException $e) {
            $this->logger->warning('Translation failed', ['site' => $site->handle, 'content' => $item->id, 'locale' => $locale, 'error' => $e->getMessage()]);

            // A live (translated) page that is merely stale must NOT be torn
            // down by a transient upstream blip — keep the old translation
            // published; it stays stale (source_hash unchanged) and retries
            // next sync. Only mark failed when there is no usable page yet.
            if ($existing !== null && $existing->status === 'translated') {
                return 'failed';
            }

            $this->translations->upsert($site->handle, $item->id, $locale, [
                'slug' => $existing->slug ?? $this->localeSlug($site, $locale, $item, ''),
                'status' => 'failed',
                'error' => $e->getMessage(),
                'source_hash' => '',
            ]);

            return 'failed';
        }

        // Keep an existing (non-manual) slug stable across re-translation.
        $slug = $existing !== null && $existing->slug !== '' ? $existing->slug : $this->localeSlug($site, $locale, $item, $title);

        $this->translations->upsert($site->handle, $item->id, $locale, [
            'slug' => $slug,
            'title' => $title,
            'excerpt' => $excerpt,
            'body_html' => $body,
            'seo_title' => $seoTitle,
            'seo_description' => $seoDescription,
            'source_hash' => $item->contentHash,
            'status' => 'translated',
            'error' => null,
        ]);

        return 'translated';
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
