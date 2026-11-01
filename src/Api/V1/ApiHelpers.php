<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Content\Dto\Content;
use Fastsite\Shared\SiteLinks;
use Fastsite\Site\Dto\Site;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Small shared helpers for the thin V1 handlers.
 */
final class ApiHelpers
{
    /**
     * @return array<string, mixed>
     * @throws \InvalidArgumentException on a non-empty body that isn't valid JSON
     */
    public static function jsonBody(ServerRequestInterface $request): array
    {
        $raw = trim((string) $request->getBody());
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('Request body is not valid JSON');
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function contentToArray(Content $content, ?Site $site = null): array
    {
        return [
            'id' => $content->id,
            'type' => $content->type,
            'slug' => $content->slug,
            'status' => $content->status,
            'title' => $content->title,
            'excerpt' => $content->excerpt,
            'body_html' => $content->bodyHtml,
            'featured_media_id' => $content->featuredMediaId,
            'intro' => $content->intro,
            'tldr' => $content->tldr,
            'conclusion' => $content->conclusion,
            'author_name' => $content->authorName,
            'source' => $content->source,
            'source_ref' => $content->sourceRef,
            'seo_title' => $content->seoTitle,
            'seo_description' => $content->seoDescription,
            'noindex' => $content->noindex,
            'error_status' => $content->errorStatus,
            'nofollow' => $content->nofollow,
            'json_ld' => $content->jsonLd,
            'warnings' => $content->warnings,
            'scheduled_at' => $content->scheduledAt,
            'published_at' => $content->publishedAt,
            'created_at' => $content->createdAt,
            'updated_at' => $content->updatedAt,
            'deleted_at' => $content->deletedAt,
            // Where it is published. The path comes from the site's own
            // post_path_pattern / page_path_pattern — never assume
            // "/blog/{slug}/". Null only when the caller had no site row to
            // resolve it against.
            'category' => $content->categorySlug,
            'path' => $site !== null ? SiteLinks::contentPath($site, $content->type, $content->slug, '', null, $content->categorySlug) : null,
            'url' => $site !== null ? SiteLinks::absolute($site, SiteLinks::contentPath($site, $content->type, $content->slug, '', null, $content->categorySlug)) : null,
        ];
    }

    public static function clientIp(ServerRequestInterface $request): string
    {
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }
}
