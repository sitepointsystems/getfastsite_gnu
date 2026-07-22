<?php

declare(strict_types=1);

namespace Fastsite\Api\V1;

use Fastsite\Content\Dto\Content;
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
    public static function contentToArray(Content $content): array
    {
        return [
            'id' => $content->id,
            'type' => $content->type,
            'slug' => $content->slug,
            'status' => $content->status,
            'title' => $content->title,
            'excerpt' => $content->excerpt,
            'body_html' => $content->bodyHtml,
            'author_name' => $content->authorName,
            'source' => $content->source,
            'source_ref' => $content->sourceRef,
            'seo_title' => $content->seoTitle,
            'seo_description' => $content->seoDescription,
            'warnings' => $content->warnings,
            'scheduled_at' => $content->scheduledAt,
            'published_at' => $content->publishedAt,
            'created_at' => $content->createdAt,
            'updated_at' => $content->updatedAt,
            'deleted_at' => $content->deletedAt,
        ];
    }

    public static function clientIp(ServerRequestInterface $request): string
    {
        return (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
    }
}
