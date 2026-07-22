<?php

declare(strict_types=1);

namespace Fastsite\Webhook\Mapper;

use Fastsite\Media\Service\MediaService;
use Fastsite\Site\Dto\Site;

/**
 * Built-in system webhook: {url, alt?} → media/file imported from the URL
 * (SSRF-guarded). Covers both the media library (images) and files.
 */
final class ImportMediaMapper implements WebhookMapper
{
    public function __construct(private readonly MediaService $media)
    {
    }

    public static function key(): string
    {
        return 'import_media';
    }

    public function process(Site $site, array $payload, \Closure $heartbeat): string
    {
        $url = (string) ($payload['url'] ?? '');
        if ($url === '') {
            throw new \InvalidArgumentException('import_media requires a "url".');
        }
        $heartbeat();
        $item = $this->media->importFromUrl($site, $url, (string) ($payload['alt'] ?? ''), (bool) ($payload['decorative'] ?? false));

        return 'import_media → #' . $item->id . ' (' . $item->kind . ')';
    }
}
