<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

use Fastsite\Media\Dto\Media;
use Fastsite\Media\Dto\MediaVariant;
use Fastsite\Media\Repository\MediaRepository;

/**
 * Expands body HTML at build time: every <img data-media-id> becomes a
 * <picture> with AVIF/WebP srcsets, explicit width/height (no CLS), sizes,
 * and lazy/async loading. The page's LCP image — the featured image, whether
 * rendered as featured_html or authored in the body, else the first
 * hero-sized body image — is emitted eager with a high fetch priority and
 * contributes a <link rel=preload>.
 *
 * This is where the PageSpeed-100 image contract is produced; PageAudit
 * asserts the same variant set the sharp pipeline guarantees.
 */
final class MediaRenderer
{
    /** Layout sizes hint — content column caps around 768px on this shell. */
    private const SIZES = '(max-width: 768px) 100vw, 768px';

    /** Card thumbnails sit in a grid — roughly one column on phones, ~360px in the grid. */
    private const CARD_SIZES = '(max-width: 700px) 100vw, 360px';

    /**
     * Source width from which an image counts as hero-sized for the
     * auto-priority pass: wide enough that it is almost certainly a hero /
     * full-column visual rather than an icon or inline illustration.
     */
    private const PRIORITY_MIN_WIDTH = 1000;

    public function __construct(private readonly MediaRepository $repository)
    {
    }

    /**
     * When $featuredMediaId is set, the FIRST body occurrence of that image
     * renders eager + fetchpriority=high and yields the returned preload —
     * a hero authored in the body is the page's LCP no matter whether the
     * shell also renders featured_html, and Lighthouse fails a lazy LCP
     * outright. With no featured id and $autoPriority on (the main body,
     * never fragments), the first hero-sized image in document order is
     * prioritised instead: it is the LCP in the overwhelming majority of
     * layouts, and a wrong guess on a zero-JS page costs only an early fetch
     * of an image the page ships anyway. An explicit featured_media_id
     * always overrides the guess.
     *
     * @return array{html: string, preload: string|null, media_ids: list<int>}
     */
    public function expandBody(string $site, string $html, ?int $featuredMediaId = null, bool $autoPriority = false): array
    {
        $ids = $this->collectMediaIds($html);
        if ($ids === []) {
            return ['html' => $html, 'preload' => null, 'media_ids' => []];
        }

        $media = $this->repository->loadManyWithVariants($site, $ids);

        $dropped = false;
        $priority = null; // at most ONE image per body carries fetchpriority=high
        $expanded = preg_replace_callback(
            '/<img\b[^>]*\bdata-media-id="(\d+)"[^>]*>/i',
            function (array $m) use ($media, $featuredMediaId, $autoPriority, &$dropped, &$priority): string {
                $id = (int) $m[1];
                $item = $media[$id] ?? null;
                if ($item === null || $item->status !== 'ready') {
                    $dropped = true;

                    return ''; // dangling reference — drop it rather than ship a broken img
                }

                $eager = false;
                if ($priority === null) {
                    $eager = $featuredMediaId !== null
                        ? $id === $featuredMediaId
                        : ($autoPriority && ($item->width ?? 0) >= self::PRIORITY_MIN_WIDTH);
                    if ($eager) {
                        $priority = $item;
                    }
                }

                return $this->picture($item, eager: $eager);
            },
            $html,
        ) ?? $html;

        if ($dropped) {
            // Remove figures left empty by a dropped dangling image.
            $expanded = preg_replace('/<figure\b[^>]*>\s*(?:<figcaption\b[^>]*>.*?<\/figcaption>)?\s*<\/figure>/is', '', $expanded) ?? $expanded;
        }

        // Only media that actually rendered should be reported as used.
        $rendered = [];
        foreach ($media as $id => $item) {
            if ($item->status === 'ready') {
                $rendered[] = $id;
            }
        }

        return [
            'html' => $expanded,
            'preload' => $priority !== null ? $this->preloadLink($priority) : null,
            'media_ids' => $rendered,
        ];
    }

    /**
     * Featured image markup + its preload link (eager, high priority).
     *
     * @return array{html: string, preload: string|null}
     */
    public function featured(string $site, int $mediaId): array
    {
        $media = $this->repository->loadManyWithVariants($site, [$mediaId]);
        $item = $media[$mediaId] ?? null;
        if ($item === null || $item->status !== 'ready') {
            return ['html' => '', 'preload' => null];
        }

        return ['html' => $this->picture($item, eager: true), 'preload' => $this->preloadLink($item)];
    }

    /**
     * Lazy featured-image markup for a listing card (blog index, home). Same
     * <picture> contract as body/featured images (AVIF/WebP + dims + alt, no
     * CLS) but always lazy/async and with a card-sized `sizes` hint — never a
     * preload, so it can't compete with the page's real LCP element.
     */
    public function thumbnail(string $site, int $mediaId): string
    {
        $media = $this->repository->loadManyWithVariants($site, [$mediaId]);
        $item = $media[$mediaId] ?? null;
        if ($item === null || $item->status !== 'ready') {
            return '';
        }

        return $this->picture($item, eager: false, sizes: self::CARD_SIZES);
    }

    private function picture(Media $media, bool $eager, string $sizes = self::SIZES): string
    {
        $byFormat = $this->variantsByFormat($media);
        $fallback = $this->fallbackVariant($media);
        if ($fallback === null) {
            return '';
        }

        $sources = '';
        foreach (['avif', 'webp'] as $format) {
            if (isset($byFormat[$format])) {
                $sources .= sprintf(
                    '<source type="image/%s" srcset="%s" sizes="%s">',
                    $format,
                    $this->srcset($byFormat[$format]),
                    $sizes,
                );
            }
        }

        $loading = $eager
            ? ' loading="eager" fetchpriority="high"'
            : ' loading="lazy" decoding="async"';

        $img = sprintf(
            '<img src="%s" width="%d" height="%d" alt="%s"%s>',
            $this->publicPath($fallback),
            $fallback->width,
            $fallback->height,
            htmlspecialchars($media->alt, ENT_QUOTES),
            $loading,
        );

        return '<picture>' . $sources . $img . '</picture>';
    }

    private function preloadLink(Media $media): ?string
    {
        $byFormat = $this->variantsByFormat($media);
        // Preload the smallest modern format the browser will pick.
        $format = isset($byFormat['avif']) ? 'avif' : (isset($byFormat['webp']) ? 'webp' : null);
        if ($format === null) {
            return null;
        }

        return sprintf(
            '<link rel="preload" as="image" type="image/%s" imagesrcset="%s" imagesizes="%s">',
            $format,
            $this->srcset($byFormat[$format]),
            self::SIZES,
        );
    }

    /**
     * @param list<MediaVariant> $variants
     */
    private function srcset(array $variants): string
    {
        usort($variants, static fn (MediaVariant $a, MediaVariant $b): int => $a->width <=> $b->width);

        return implode(', ', array_map(
            fn (MediaVariant $v): string => $this->publicPath($v) . ' ' . $v->width . 'w',
            $variants,
        ));
    }

    /**
     * @return array<string, list<MediaVariant>>
     */
    private function variantsByFormat(Media $media): array
    {
        $out = [];
        foreach ($media->variants as $v) {
            if ($v->purpose === 'responsive') {
                $out[$v->format][] = $v;
            }
        }

        return $out;
    }

    private function fallbackVariant(Media $media): ?MediaVariant
    {
        $best = null;
        foreach ($media->variants as $v) {
            if ($v->purpose === 'responsive' && \in_array($v->format, ['jpg', 'png'], true)) {
                if ($best === null || $v->width > $best->width) {
                    $best = $v;
                }
            }
        }

        return $best;
    }

    private function publicPath(MediaVariant $variant): string
    {
        // dist layout: /media/{id}/{filename}
        $parts = explode('/', str_replace('\\', '/', $variant->diskPath));

        return '/media/' . $parts[count($parts) - 2] . '/' . $parts[count($parts) - 1];
    }

    /**
     * @return list<int>
     */
    private function collectMediaIds(string $html): array
    {
        if (preg_match_all('/\bdata-media-id="(\d+)"/i', $html, $m) === false) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $m[1])));
    }
}
