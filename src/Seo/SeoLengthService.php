<?php

declare(strict_types=1);

namespace Fastsite\Seo;

use Fastsite\Ai\AiClientFactory;
use Fastsite\Content\Dto\Content;
use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Content\Service\ContentService;
use Fastsite\Site\Dto\Site;
use Psr\Log\LoggerInterface;

/**
 * The Google/Yoast snippet-length checker: identifies titles and meta
 * descriptions outside the {@see SeoLimits} envelope and rewrites them to
 * fit. What is checked is what the build ships — the effective SEO title
 * (seo_title, falling back to the title) and description (seo_description,
 * falling back to the excerpt). Fixes are written to the seo_* override
 * fields only, so the visible title/excerpt are never touched; every write
 * goes through ContentService::update (revision snapshot + content_hash, so
 * translations of the meta go stale and re-sync).
 *
 * Rewrites use the site's AI credential ({@see SeoRewriter}); without one,
 * over-limit values are still fixed by a word-boundary truncate — too-short
 * ones are only reported (padding needs a writer, human or AI).
 */
final class SeoLengthService
{
    public function __construct(
        private readonly ContentRepository $content,
        private readonly ContentService $contentService,
        private readonly AiClientFactory $ai,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Every content row whose effective title/description falls outside the
     * limits. Kinds: title-long, title-short, description-long,
     * description-short, description-missing. body_excerpt gives a rewriting
     * agent enough context to craft replacements without fetching each row.
     *
     * @return list<array{id: int, type: string, slug: string, status: string, field: string, kind: string, text: string, length: int, limit: int, body_excerpt: string}>
     */
    public function scan(Site $site): array
    {
        $issues = [];
        foreach ($this->allContent($site) as $row) {
            $rowIssues = $this->issuesFor($row);
            if ($rowIssues === []) {
                continue;
            }
            $excerpt = mb_substr(trim((string) preg_replace('/\s+/', ' ', strip_tags($row->bodyHtml))), 0, 240);
            foreach ($rowIssues as $issue) {
                $issues[] = [
                    'id' => $row->id,
                    'type' => $row->type,
                    'slug' => $row->slug,
                    'status' => $row->status,
                ] + $issue + ['body_excerpt' => $excerpt];
            }
        }

        return $issues;
    }

    /**
     * Rewrites every flagged row (or one, when $onlyId is given) to fit the
     * limits. Returns a per-item report; `ai` says whether a rewriter was
     * actually available (without one only over-long values can change), and
     * `failed` counts rows where a CONFIGURED rewriter errored and truncation
     * couldn't help either — those are retryable, unlike `skipped`.
     *
     * $onAiProgress is handed to the rewriter ({@see SeoRewriter::onProgress})
     * — cumulative response bytes while an AI answer streams, so an
     * interactive caller (the dashboard's progress dialog) can show real
     * movement during the call instead of a stalled bar.
     *
     * @param (\Closure(int): void)|null $onAiProgress
     * @return array{fixed: int, skipped: int, failed: int, ai: bool, items: list<array{id: int, slug: string, result: string, title?: string, description?: string}>}
     */
    public function fix(Site $site, ?int $onlyId = null, ?\Closure $heartbeat = null, ?\Closure $onAiProgress = null): array
    {
        $rewriter = $this->ai->seoRewriterForSite($site);
        $rewriter->onProgress($onAiProgress);
        $aiConfigured = $rewriter->isConfigured();
        if (!$aiConfigured) {
            $this->logger->warning('SEO fix running WITHOUT AI — no OpenRouter credential on the site and no endpointr gateway; only over-long values can be truncated', ['site' => $site->handle]);
        }
        $items = [];
        $fixed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($this->allContent($site) as $row) {
            if ($onlyId !== null && $row->id !== $onlyId) {
                continue;
            }
            $issues = $this->issuesFor($row);
            if ($issues === []) {
                continue;
            }
            if ($heartbeat !== null) {
                $heartbeat();
            }

            $title = $row->seoTitle !== '' ? $row->seoTitle : $row->title;
            $description = $row->seoDescription !== '' ? $row->seoDescription : $row->excerpt;

            $aiError = null;
            $rewritten = $rewriter->rewrite($title, $description, strip_tags($row->bodyHtml));
            if ($rewritten !== null) {
                // The model is asked to count, but never trusted to: anything
                // still over the line is truncated at a word boundary.
                $newTitle = SeoLimits::truncate($rewritten['title'] !== '' ? $rewritten['title'] : $title, SeoLimits::TITLE_MAX);
                $newDescription = SeoLimits::truncate($rewritten['description'] !== '' ? $rewritten['description'] : $description, SeoLimits::DESC_MAX);
            } else {
                if ($aiConfigured) {
                    // AI was supposed to answer and didn't — that must never
                    // disappear into a silent skip.
                    $aiError = $rewriter->lastError() ?? 'unknown error';
                    $this->logger->warning('SEO AI rewrite failed — falling back to truncation', ['site' => $site->handle, 'id' => $row->id, 'slug' => $row->slug, 'error' => $aiError]);
                }
                // No AI (or a failed call): truncation fixes the too-long
                // side; too-short needs a writer, so those rows end the run
                // as `failed` (AI errored — retryable) or `skipped` (no AI
                // to ask) — with the reason on the report, never silently.
                $newTitle = SeoLimits::truncate($title, SeoLimits::TITLE_MAX);
                $newDescription = SeoLimits::truncate($description, SeoLimits::DESC_MAX);
                if ($newTitle === trim($title) && $newDescription === trim($description)) {
                    if ($aiConfigured) {
                        $failed++;
                        $items[] = ['id' => $row->id, 'slug' => $row->slug, 'result' => 'failed (AI rewrite: ' . $aiError . ')'];
                    } else {
                        $skipped++;
                        $items[] = ['id' => $row->id, 'slug' => $row->slug, 'result' => 'skipped (too short — needs the AI rewriter; add an OpenRouter key on /account and select it in Site Settings)'];
                    }
                    continue;
                }
            }

            if ($newTitle === $title && $newDescription === $description) {
                $skipped++;
                $items[] = ['id' => $row->id, 'slug' => $row->slug, 'result' => 'unchanged'];
                continue;
            }

            $this->contentService->update($site, $row->id, [
                'seo_title' => $newTitle,
                'seo_description' => $newDescription,
            ], 'seo-fix');
            $fixed++;
            // An over-long row that truncation rescued still reports "fixed"
            // — but if the AI call behind it FAILED, say so on the row. Not
            // saying it is what made a total AI outage look like a partial
            // one: every over-long row read "fixed" while every too-short row
            // read "failed", so the same broken call presented as "roughly
            // half of them error".
            $items[] = ['id' => $row->id, 'slug' => $row->slug, 'result' => $aiError === null ? 'fixed' : 'fixed by truncation only (AI rewrite: ' . $aiError . ')', 'title' => $newTitle, 'description' => $newDescription];
            $this->logger->info('SEO lengths fixed', ['site' => $site->handle, 'id' => $row->id, 'slug' => $row->slug]);
        }

        return ['fixed' => $fixed, 'skipped' => $skipped, 'failed' => $failed, 'ai' => $aiConfigured, 'items' => $items];
    }

    /**
     * @return list<array{field: string, kind: string, text: string, length: int, limit: int}>
     */
    private function issuesFor(Content $row): array
    {
        $title = trim($row->seoTitle !== '' ? $row->seoTitle : $row->title);
        $description = trim($row->seoDescription !== '' ? $row->seoDescription : $row->excerpt);
        $issues = [];

        $titleLen = mb_strlen($title);
        if ($titleLen > SeoLimits::TITLE_MAX) {
            $issues[] = ['field' => 'title', 'kind' => 'title-long', 'text' => $title, 'length' => $titleLen, 'limit' => SeoLimits::TITLE_MAX];
        } elseif ($titleLen > 0 && $titleLen < SeoLimits::TITLE_MIN) {
            $issues[] = ['field' => 'title', 'kind' => 'title-short', 'text' => $title, 'length' => $titleLen, 'limit' => SeoLimits::TITLE_MIN];
        }

        $descLen = mb_strlen($description);
        if ($descLen === 0) {
            $issues[] = ['field' => 'description', 'kind' => 'description-missing', 'text' => '', 'length' => 0, 'limit' => SeoLimits::DESC_MIN];
        } elseif ($descLen > SeoLimits::DESC_MAX) {
            $issues[] = ['field' => 'description', 'kind' => 'description-long', 'text' => $description, 'length' => $descLen, 'limit' => SeoLimits::DESC_MAX];
        } elseif ($descLen < SeoLimits::DESC_MIN) {
            $issues[] = ['field' => 'description', 'kind' => 'description-short', 'text' => $description, 'length' => $descLen, 'limit' => SeoLimits::DESC_MIN];
        }

        return $issues;
    }

    /**
     * @return list<Content>
     */
    private function allContent(Site $site): array
    {
        /** @var array{items: list<Content>} $result */
        $result = $this->content->listFiltered($site->handle, null, null, 1, 100000);

        return $result['items'];
    }
}
