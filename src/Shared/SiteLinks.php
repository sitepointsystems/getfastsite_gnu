<?php

declare(strict_types=1);

namespace Fastsite\Shared;

use Fastsite\Content\Dto\Content;
use Fastsite\Media\Dto\Media;
use Fastsite\Media\Dto\MediaVariant;
use Fastsite\Site\Dto\Site;

/**
 * Where a thing ends up on the live site.
 *
 * The build knows every one of these paths, but nothing else did: an API or
 * MCP caller got a slug and a media id and had to REBUILD the URL by hand —
 * "base_url + /blog/<slug>/", which is right only while a site keeps the
 * default post_path_pattern, and a media filename which is not derivable from
 * the id at all (it carries the slug and the variant width). The honest
 * answers live here, in one pure place both the build and the read surfaces
 * call, so a returned link can never disagree with the canonical tag.
 */
final class SiteLinks
{
    /**
     * A published page with this primary slug BECOMES the site root, so its
     * URL is `/`, never `/home/`. Mirrors BuildService::HOME_SLUG.
     */
    public const HOME_SLUG = 'home';

    /**
     * The site-relative path a content row is published at, in one locale.
     *
     * $slug is that LOCALE'S slug (translations carry their own); pass the
     * primary row's slug for the primary locale.
     *
     * $errorStatus set means the row IS an error page: it lands at the fixed
     * `/{status}.html` its serving mode looks for, not at its slug path. The
     * slug still exists and is still unique — it is just not what addresses
     * the page, the same way a page slugged `home` renders at `/`.
     */
    public static function contentPath(
        Site $site,
        string $type,
        string $slug,
        string $locale = '',
        ?int $errorStatus = null,
        ?string $category = null,
    ): string {
        $prefix = ($locale === '' || $locale === $site->primaryLocale) ? '' : '/' . $locale;

        if ($errorStatus !== null) {
            return $prefix . '/' . $errorStatus . '.html';
        }

        if ($type === 'page' && $slug === self::HOME_SLUG) {
            return $prefix . '/';
        }

        if ($type === 'post') {
            return $prefix . self::applyPattern($site->postPathPattern, $slug, $category);
        }

        return $prefix . str_replace('{slug}', $slug, $site->pagePathPattern);
    }

    /**
     * The same answer for a content row, reading its category off the row.
     * $slug overrides the row's own (a translation carries its own slug; the
     * category segment is shared by every locale).
     */
    public static function pathOf(Site $site, Content $content, string $locale = '', ?string $slug = null): string
    {
        return self::contentPath(
            $site,
            $content->type,
            $slug ?? $content->slug,
            $locale,
            $content->errorStatus,
            $content->type === 'post' ? $content->categorySlug : null,
        );
    }

    /**
     * A post pattern filled in. `{category}` is the post's category slug; a
     * post with NO category drops the whole segment, so `/{category}/{slug}/`
     * gives `/{slug}/` rather than a `//` or an invented "uncategorized".
     */
    public static function applyPattern(string $pattern, string $slug, ?string $category): string
    {
        if (str_contains($pattern, '{category}')) {
            $pattern = ($category === null || $category === '')
                ? (string) preg_replace('#\{category\}/?#', '', $pattern)
                : str_replace('{category}', $category, $pattern);
        }

        return str_replace('{slug}', $slug, $pattern);
    }

    /**
     * Where one page of a category archive lives. Page 1 is the pattern
     * itself; later pages append `page/{n}/`, WordPress's own shape, so a
     * ported site keeps its paginated archive URLs too.
     */
    public static function categoryPath(Site $site, string $categorySlug, int $page = 1, string $locale = ''): string
    {
        $prefix = ($locale === '' || $locale === $site->primaryLocale) ? '' : '/' . $locale;
        $path = $prefix . str_replace('{slug}', $categorySlug, $site->categoryPathPattern);

        return $page > 1 ? rtrim($path, '/') . '/page/' . $page . '/' : $path;
    }

    /**
     * Absolute on the site's CANONICAL origin — the same one canonical tags,
     * OG images and sitemaps carry. Falls back to the path when a site has no
     * base_url yet (a brand-new site), which is honest: there is no URL.
     */
    public static function absolute(Site $site, string $path): string
    {
        $base = $site->canonicalBaseUrl();

        return $base !== '' ? $base . $path : $path;
    }

    /**
     * The variant an `<img src>` actually resolves to: the widest responsive
     * jpg/png. Deliberately NOT the `-og` crop, for two reasons that are worth
     * keeping apart: a 1200x630 crop is the wrong thing to put in a plain
     * image link whatever ships, AND an og crop is only published when some
     * page references it as a share card (the site default, or a page featured
     * image) — for every other image, handing one out is still a link to a
     * 404. AVIF/WebP live in the srcset, not in a plain link.
     */
    public static function displayVariant(Media $media): ?MediaVariant
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

    /**
     * Site-relative path for a media item, or null when it has none: a video
     * (accepted, but no variants are generated yet) and an image still
     * processing have nothing on disk to link to.
     *
     * NOTE for callers: media ships REFERENCED-ONLY. An item nothing embeds
     * is pruned from dist, so this is the path it WILL have once a page uses
     * it — not proof that it is live right now.
     */
    public static function mediaPath(Media $media): ?string
    {
        if ($media->kind === 'file') {
            return '/files/' . $media->id . '/' . rawurlencode($media->publicSlug()) . '.' . $media->ext;
        }

        $variant = self::displayVariant($media);
        if ($variant === null) {
            return null;
        }

        return '/media/' . $media->id . '/' . self::filename($variant->diskPath);
    }

    public static function mediaUrl(Site $site, Media $media): ?string
    {
        $path = self::mediaPath($media);

        return $path === null ? null : self::absolute($site, $path);
    }

    /**
     * Every rendition the build ships for an image, widest first — the answer
     * to "give me the AVIF" without scraping a rendered page.
     *
     * @return list<array{format: string, width: int, height: int, bytes: int, path: string, url: string}>
     */
    public static function mediaVariants(Site $site, Media $media): array
    {
        $out = [];
        foreach ($media->variants as $v) {
            if ($v->purpose !== 'responsive') {
                continue; // og crops are share cards, not srcset entries
            }
            $path = '/media/' . $media->id . '/' . self::filename($v->diskPath);
            $out[] = [
                'format' => $v->format,
                'width' => $v->width,
                'height' => $v->height,
                'bytes' => $v->bytes,
                'path' => $path,
                'url' => self::absolute($site, $path),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['width'] <=> $a['width']);

        return $out;
    }

    /** Disk paths are stored with the host's separator; dist layout is not. */
    private static function filename(string $diskPath): string
    {
        $parts = explode('/', str_replace('\\', '/', $diskPath));

        return $parts[\count($parts) - 1];
    }
}
