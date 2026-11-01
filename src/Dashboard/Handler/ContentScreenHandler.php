<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Dashboard\Dash;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/content — screen 2: posts/pages tabs with per-locale
 * translation status; ?edit={id} or ?new=1 opens the editor panel.
 */
final class ContentScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly ContentRepository $content,
        private readonly SiteDatabaseManager $databases,
        private readonly \Fastsite\FactCheck\FactCheckService $factCheck,
        private readonly \Fastsite\Seo\SeoLengthService $seoLengths,
        private readonly \Fastsite\Ai\AiClientFactory $ai,
        private readonly \Fastsite\Category\Repository\CategoryRepository $categories,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $query = $request->getQueryParams();
        $type = ($query['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $trash = isset($query['trash']);
        $page = max(1, (int) ($query['page'] ?? 1));
        // Free-text filter over title/slug/excerpt/SEO/body — the fields you
        // remember a page by when the list is longer than one screen.
        $search = trim((string) ($query['q'] ?? ''));
        $result = $trash
            ? ['items' => $this->content->listTrashed($site->handle, $type, $search), 'total' => 0]
            : $this->content->listFiltered($site->handle, $type, null, $page, 25, $search);

        // Per-locale translation status per row: locale => translated|stale|missing|manual.
        $translations = [];
        if ($site->locales !== []) {
            try {
                foreach ($this->databases->site($site->handle)->fetchAllAssociative(
                    'SELECT ct.content_id, ct.locale, ct.status, ct.source_hash, c.content_hash
                     FROM content_translations ct JOIN content c ON c.id = ct.content_id',
                ) as $row) {
                    $state = (string) $row['status'];
                    if ($state === 'translated' && (string) $row['source_hash'] !== (string) $row['content_hash']) {
                        $state = 'stale';
                    }
                    $translations[(int) $row['content_id']][(string) $row['locale']] = $state;
                }
            } catch (\Throwable) {
                // Translation table trouble must not hide the content list.
            }
        }

        $editing = null;
        $factCheckReport = null;
        if (isset($query['edit'])) {
            $editing = $this->content->findById($site->handle, (int) $query['edit']);
            if ($editing !== null) {
                $factCheckReport = $this->factCheck->storedReport($site, $editing->id);
            }
        }

        // Component handles for the editor's "Insert component" menu (#14).
        $components = [];
        try {
            $components = $this->databases->site($site->handle)->fetchAllAssociative(
                "SELECT handle FROM components WHERE status = 'ready' ORDER BY handle",
            );
        } catch (\Throwable) {
            // Optional — never blank the editor.
        }

        // Post categories: the editor's select and the Categories card.
        $categories = [];
        $categoryCounts = [];
        $editingCategory = null;
        try {
            $categories = $this->categories->all($site->handle);
            $categoryCounts = $this->categories->publishedPostCounts($site->handle);
            if (($query['category'] ?? '') !== '') {
                $editingCategory = $this->categories->findBySlug($site->handle, (string) $query['category']);
            }
        } catch (\Throwable) {
            // A site DB not yet migrated must not blank the content list.
        }

        return $this->dash->render($request, 'content.html.twig', [
            'categories' => $categories,
            'category_counts' => $categoryCounts,
            'editing_category' => $editingCategory,
            'title' => $site->name . ' content — fastsite',
            'site' => $site,
            'type' => $type,
            'items' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'pages' => (int) ceil($result['total'] / 25),
            'search' => $search,
            'translations' => $translations,
            'editing' => $editing,
            'creating' => isset($query['new']),
            'components' => $components,
            'fact_check_report' => $factCheckReport,
            'fact_check_configured' => $this->factCheck->isConfigured($site),
            'fact_check_model' => $this->factCheck->model($site),
            // Google/Yoast snippet-length audit (static scan, no AI call).
            'seo_issues' => $this->seoLengths->scan($site),
            'seo_ai' => $this->ai->seoRewriterForSite($site)->isConfigured(),
            'fact_check_show' => ($query['fc'] ?? '') === 'report' && $factCheckReport !== null,
        ]);
    }
}
