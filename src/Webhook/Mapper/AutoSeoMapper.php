<?php

declare(strict_types=1);

namespace Fastsite\Webhook\Mapper;

use Fastsite\Content\Dto\Content;
use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Content\Service\ContentService;
use Fastsite\Media\Service\MediaService;
use Fastsite\Site\Dto\Site;
use Psr\Log\LoggerInterface;

/**
 * Maps an autoseo (or generic article-publishing) webhook payload into a
 * post. External images — featured and inline — are adopted through the
 * SSRF-guarded media pipeline and rewritten to data-media-id so the build
 * emits optimised <picture> markup. Idempotent on (source=autoseo,
 * source_ref=payload.id): a redelivery updates rather than duplicates.
 *
 * Alt derivation (critical for unattended publishing): an image without alt
 * inherits the article title, so ingestion never fails on the alt rule.
 *
 * Expected payload:
 *   { id, title, slug?, excerpt?, body_html, featured_image_url?, author?,
 *     seo_title?, seo_description?, status?, published_at? }
 */
final class AutoSeoMapper implements WebhookMapper
{
    private const SOURCE = 'autoseo';

    /** Bound the fetch amplification from one payload. */
    private const MAX_INLINE_IMAGES = 20;

    public function __construct(
        private readonly ContentService $content,
        private readonly ContentRepository $repository,
        private readonly MediaService $media,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function key(): string
    {
        return 'autoseo';
    }

    public function process(Site $site, array $payload, \Closure $heartbeat): string
    {
        $sourceRef = trim((string) ($payload['id'] ?? ''));
        if ($sourceRef === '') {
            throw new \InvalidArgumentException('autoseo payload is missing "id" (needed for idempotency).');
        }
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('autoseo payload is missing "title".');
        }

        $status = (string) ($payload['status'] ?? 'published');
        if (!\in_array($status, ['draft', 'published'], true)) {
            $status = 'published';
        }

        $body = (string) ($payload['body_html'] ?? '');
        $body = $this->adoptInlineImages($site, $body, $title, $heartbeat);

        $featuredMediaId = null;
        $featuredUrl = trim((string) ($payload['featured_image_url'] ?? ''));
        if ($featuredUrl !== '') {
            $heartbeat();
            $featuredMediaId = $this->adopt($site, $featuredUrl, $title);
        }

        $input = [
            'type' => 'post',
            'title' => $title,
            'excerpt' => (string) ($payload['excerpt'] ?? ''),
            'body_html' => $body,
            'status' => $status,
            'author_name' => (string) ($payload['author'] ?? ''),
            'seo_title' => (string) ($payload['seo_title'] ?? ''),
            'seo_description' => (string) ($payload['seo_description'] ?? ''),
            'source' => self::SOURCE,
            'source_ref' => $sourceRef,
        ];
        if (isset($payload['slug'])) {
            $input['slug'] = (string) $payload['slug'];
        }
        if (isset($payload['published_at'])) {
            $input['published_at'] = (string) $payload['published_at'];
        }
        if ($featuredMediaId !== null) {
            $input['featured_media_id'] = $featuredMediaId;
        }

        $existing = $this->repository->findBySource($site->handle, self::SOURCE, $sourceRef);
        if ($existing !== null) {
            // Don't resurrect fields the update-path treats as absent; the
            // create-shaped input maps cleanly onto update() keys.
            $this->content->update($site, $existing->id, $input, 'webhook:autoseo');

            return sprintf('updated post #%d (%s)', $existing->id, $existing->slug);
        }

        $created = $this->content->create($site, $input, 'webhook:autoseo');

        return sprintf('created post #%d (%s)', $created->id, $created->slug);
    }

    /**
     * Downloads every external inline <img src="http…"> and rewrites it to a
     * data-media-id reference. A failed adoption drops that image (the
     * sanitizer would strip it anyway) — one bad image never fails the post.
     */
    private function adoptInlineImages(Site $site, string $body, string $title, \Closure $heartbeat): string
    {
        $adopted = 0;

        return preg_replace_callback(
            '/<img\b[^>]*\bsrc="(https?:\/\/[^"]+)"[^>]*>/i',
            function (array $m) use ($site, $title, $heartbeat, &$adopted): string {
                // Bound fetch amplification: beyond the cap, drop the image
                // (the sanitizer would strip a bare <img> anyway).
                if ($adopted >= self::MAX_INLINE_IMAGES) {
                    return '';
                }
                ++$adopted;
                $heartbeat();

                $alt = $this->extractAlt($m[0]) ?: $title;
                $mediaId = $this->adopt($site, $m[1], $alt);

                return $mediaId !== null
                    ? sprintf('<img data-media-id="%d" alt="%s">', $mediaId, htmlspecialchars($alt, ENT_QUOTES))
                    : ''; // adoption failed — drop the broken image
            },
            $body,
        ) ?? $body;
    }

    private function adopt(Site $site, string $url, string $alt): ?int
    {
        try {
            $media = $this->media->importFromUrl($site, $url, $alt !== '' ? $alt : 'image');

            return $media->kind === 'image' ? $media->id : null;
        } catch (\Throwable $e) {
            $this->logger->warning('autoseo: image adoption failed', ['site' => $site->handle, 'url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function extractAlt(string $imgTag): string
    {
        if (preg_match('/\balt="([^"]*)"/i', $imgTag, $m) === 1) {
            return html_entity_decode($m[1]);
        }

        return '';
    }
}
