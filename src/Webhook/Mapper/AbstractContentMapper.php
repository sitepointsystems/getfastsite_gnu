<?php

declare(strict_types=1);

namespace Fastsite\Webhook\Mapper;

use Fastsite\Content\Service\ContentService;
use Fastsite\Site\Dto\Site;

/**
 * Built-in content webhook mapper (#3): a generic {title, body_html, …}
 * payload → a created post/page. Subclasses fix the type.
 */
abstract class AbstractContentMapper implements WebhookMapper
{
    public function __construct(protected readonly ContentService $content)
    {
    }

    abstract protected function type(): string;

    public function process(Site $site, array $payload, \Closure $heartbeat): string
    {
        $input = [
            'type' => $this->type(),
            'title' => (string) ($payload['title'] ?? ''),
            'slug' => (string) ($payload['slug'] ?? ''),
            'body_html' => (string) ($payload['body_html'] ?? ''),
            'excerpt' => (string) ($payload['excerpt'] ?? ''),
            'status' => (string) ($payload['status'] ?? 'published'),
            'seo_title' => (string) ($payload['seo_title'] ?? ''),
            'seo_description' => (string) ($payload['seo_description'] ?? ''),
            'author_name' => (string) ($payload['author'] ?? $payload['author_name'] ?? ''),
            'sanitize' => $payload['sanitize'] ?? true,
        ];
        if (isset($payload['source_ref'])) {
            $input['source'] = 'webhook';
            $input['source_ref'] = (string) $payload['source_ref'];
        }

        $content = $this->content->create($site, $input, 'webhook:' . static::key());

        return static::key() . ' → #' . $content->id . ' (' . $content->slug . ')';
    }
}
