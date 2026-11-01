<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

/**
 * Per-page schema.org JSON-LD, emitted as ONE @graph in ONE <script> block.
 *
 * Google reads a page's structured data as a set of ENTITIES, not as a pile of
 * independent blobs: an Article that names its publisher by @id, a WebPage that
 * declares which WebSite it belongs to, and one Organization (or Person) the
 * whole site resolves to. Emitting each type as its own disconnected script
 * says nothing about how they relate — which is precisely what entity
 * resolution, and increasingly AI search, needs. So every page ships a single
 * graph whose nodes cross-reference by @id, anchored on the site's canonical
 * origin so every locale resolves to the SAME organization and website entity.
 *
 * Deliberately NOT emitted, despite most "structured data best practice" posts
 * still recommending both:
 *  - WebSite.potentialAction / SearchAction (sitelinks searchbox) — Google
 *    retired the feature in November 2024 and ignores the markup.
 *  - FAQPage — rich results were withdrawn for all but government and health
 *    sites in August 2023.
 * Noise in a graph costs credibility on the parts that can still earn a rich
 * result, so unsupported markup is worse than no markup.
 *
 * Pure: no database, no filesystem, no Site DTO. Everything it needs is passed
 * in, so it is trivially testable and can never fail a build on an I/O error.
 *
 * @see https://developers.google.com/search/docs/appearance/structured-data/intro-structured-data
 */
final class JsonLdBuilder
{
    /**
     * Google's documented ceiling for Article.headline; a longer one is
     * flagged in Search Console. Trimmed at a word boundary — a headline cut
     * mid-word reads as broken data.
     */
    private const HEADLINE_MAX = 110;

    /**
     * Article subtypes. Author-supplied per-content JSON-LD that declares one
     * of these REPLACES the engine's own article node: two competing Article
     * entities describing one page is worse than either alone.
     */
    private const ARTICLE_TYPES = [
        'Article', 'BlogPosting', 'NewsArticle', 'TechArticle',
        'ScholarlyArticle', 'Report', 'AdvertiserContentArticle', 'SatiricalArticle',
    ];

    /**
     * HEX_TAG/HEX_AMP/HEX_APOS/HEX_QUOT keep a literal "</script>" inside any
     * string field from terminating the raw-text script element — titles and
     * excerpts are NOT HTML-sanitized, so this is the stored-XSS guard.
     * UNESCAPED_SLASHES/UNICODE are cosmetic and safe here precisely because
     * HEX_TAG has already neutralised '<'.
     */
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param string $origin canonical origin, NO trailing slash (https://example.com)
     * @param 'Organization'|'Person' $entityType what the site itself IS — a
     *   personal brand is a Person, and Google treats the two as different
     *   entity classes, not as a cosmetic label
     * @param list<string> $sameAs profile URLs identifying the entity elsewhere
     *   (LinkedIn, X, GitHub, Crunchbase…). This is the field that actually
     *   drives entity resolution; name + url alone rarely does
     * @param array{url: string, width: int, height: int, caption: string}|null $logo
     */
    public function __construct(
        private readonly string $origin,
        private readonly string $siteName,
        private readonly string $entityType = 'Organization',
        private readonly array $sameAs = [],
        private readonly string $siteDescription = '',
        private readonly ?array $logo = null,
    ) {
    }

    /**
     * The full graph for one rendered page, JSON-encoded, or null when there is
     * nothing to describe (no canonical URL — the 404 page).
     *
     * @param 'home'|'page'|'post'|'blog' $kind
     * @param string $url absolute canonical URL of THIS locale's variant
     * @param array{url: string, width: int, height: int, caption: string}|null $image featured image
     * @param list<array{name: string, url: string}> $breadcrumb home → … → this page
     * @param list<array{name: string, url: string}> $items blog-index listing entries
     * @param string|null $stored author-supplied per-content JSON-LD, merged in
     */
    public function graph(
        string $kind,
        string $url,
        string $title,
        string $locale,
        string $description = '',
        ?string $published = null,
        ?string $modified = null,
        string $author = '',
        ?array $image = null,
        array $breadcrumb = [],
        array $items = [],
        ?string $stored = null,
    ): ?string {
        if ($url === '') {
            return null;
        }

        $orgId = $this->origin . '/#organization';
        $siteId = $this->origin . '/#website';
        $pageId = $url . '#webpage';
        $imageId = $url . '#primaryimage';
        $crumbId = $url . '#breadcrumb';

        $title = self::text($title);
        $description = self::text($description);

        // The blog index is a listing, not a document about one thing.
        $page = [
            '@type' => $kind === 'blog' ? 'CollectionPage' : 'WebPage',
            '@id' => $pageId,
            'url' => $url,
            'name' => $title,
            'isPartOf' => ['@id' => $siteId],
            'inLanguage' => $locale,
        ];
        if ($description !== '') {
            $page['description'] = $description;
        }
        if (($published ?? '') !== '') {
            $page['datePublished'] = $published;
        }
        if (($modified ?? '') !== '') {
            $page['dateModified'] = $modified;
        }
        if ($image !== null) {
            $page['primaryImageOfPage'] = ['@id' => $imageId];
            $page['image'] = ['@id' => $imageId];
        }
        if (\count($breadcrumb) >= 2) {
            $page['breadcrumb'] = ['@id' => $crumbId];
        }
        if ($kind === 'blog' && $items !== []) {
            $page['mainEntity'] = self::itemList($items);
        }

        $article = null;
        $person = null;
        if ($kind === 'post') {
            $article = [
                '@type' => 'BlogPosting',
                '@id' => $url . '#article',
                'isPartOf' => ['@id' => $pageId],
                'mainEntityOfPage' => ['@id' => $pageId],
                'headline' => self::clamp($title, self::HEADLINE_MAX),
                'url' => $url,
                'inLanguage' => $locale,
                'publisher' => ['@id' => $orgId],
            ];
            if ($description !== '') {
                $article['description'] = $description;
            }
            if (($published ?? '') !== '') {
                $article['datePublished'] = $published;
            }
            if (($modified ?? '') !== '') {
                $article['dateModified'] = $modified;
            }
            if ($image !== null) {
                $article['image'] = ['@id' => $imageId];
            }
            if ($author !== '') {
                // On a personal-brand site the byline IS the site entity —
                // minting a second Person node for the same human would split
                // one identity across two @ids.
                if ($this->entityType === 'Person' && strcasecmp($author, $this->siteName) === 0) {
                    $article['author'] = ['@id' => $orgId];
                } else {
                    $personId = $this->origin . '/#/person/' . substr(sha1(mb_strtolower($author)), 0, 16);
                    $article['author'] = ['@id' => $personId];
                    $person = ['@type' => 'Person', '@id' => $personId, 'name' => self::text($author)];
                }
            }
        }

        // Author-supplied JSON-LD is merged as extra graph nodes rather than
        // replacing everything — a hand-written Product or Event belongs
        // ALONGSIDE the page/site/organization entities, not instead of them.
        $extra = self::storedNodes($stored);
        if ($article !== null && self::declaresArticle($extra)) {
            $article = null;
            $person = null;
        }

        $crumbs = null;
        if (\count($breadcrumb) >= 2) {
            $last = \count($breadcrumb) - 1;
            $elements = [];
            foreach (array_values($breadcrumb) as $i => $crumb) {
                $element = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => self::text($crumb['name'])];
                // Per Google's spec the final crumb is the current page and
                // carries no URL of its own.
                if ($i < $last && $crumb['url'] !== '') {
                    $element['item'] = $crumb['url'];
                }
                $elements[] = $element;
            }
            $crumbs = ['@type' => 'BreadcrumbList', '@id' => $crumbId, 'itemListElement' => $elements];
        }

        $nodes = array_values(array_filter([
            $this->entityNode($orgId),
            $this->logo !== null ? self::imageNode($this->origin . '/#logo', $this->logo) : null,
            $this->siteNode($siteId, $orgId, $locale),
            $page,
            $article,
            $person,
            $crumbs,
            $image !== null ? self::imageNode($imageId, $image) : null,
        ], static fn (?array $node): bool => $node !== null));

        foreach ($extra as $node) {
            $nodes[] = $node;
        }

        return json_encode(['@context' => 'https://schema.org', '@graph' => $nodes], self::FLAGS);
    }

    /**
     * The one entity the whole site resolves to.
     *
     * @return array<string, mixed>
     */
    private function entityNode(string $id): array
    {
        $node = [
            '@type' => $this->entityType,
            '@id' => $id,
            'name' => self::text($this->siteName),
            'url' => $this->origin . '/',
        ];
        if ($this->siteDescription !== '') {
            $node['description'] = self::text($this->siteDescription);
        }
        if ($this->logo !== null) {
            $ref = ['@id' => $this->origin . '/#logo'];
            // schema.org puts `logo` on Organization only; a Person has `image`.
            if ($this->entityType === 'Organization') {
                $node['logo'] = $ref;
            }
            $node['image'] = $ref;
        }
        if ($this->sameAs !== []) {
            $node['sameAs'] = array_values($this->sameAs);
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function siteNode(string $id, string $orgId, string $locale): array
    {
        $node = [
            '@type' => 'WebSite',
            '@id' => $id,
            'url' => $this->origin . '/',
            'name' => self::text($this->siteName),
            'publisher' => ['@id' => $orgId],
            'inLanguage' => $locale,
        ];
        if ($this->siteDescription !== '') {
            $node['description'] = self::text($this->siteDescription);
        }

        return $node;
    }

    /**
     * @param array{url: string, width: int, height: int, caption: string} $image
     * @return array<string, mixed>
     */
    private static function imageNode(string $id, array $image): array
    {
        $node = [
            '@type' => 'ImageObject',
            '@id' => $id,
            'url' => $image['url'],
            'contentUrl' => $image['url'],
        ];
        if ($image['width'] > 0) {
            $node['width'] = $image['width'];
        }
        if ($image['height'] > 0) {
            $node['height'] = $image['height'];
        }
        if ($image['caption'] !== '') {
            $node['caption'] = self::text($image['caption']);
        }

        return $node;
    }

    /**
     * @param list<array{name: string, url: string}> $items
     * @return array<string, mixed>
     */
    private static function itemList(array $items): array
    {
        $elements = [];
        foreach (array_values($items) as $i => $item) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => self::text($item['name']),
                'url' => $item['url'],
            ];
        }

        return ['@type' => 'ItemList', 'itemListElement' => $elements];
    }

    /**
     * Unwrap author-supplied JSON-LD into bare graph nodes. Accepts a single
     * object, a list of objects, or a whole {"@context":…,"@graph":[…]}
     * document — every shape a hand-written or agent-written blob arrives in.
     * A per-node @context is dropped; the graph declares it once.
     *
     * @return list<array<string, mixed>>
     */
    private static function storedNodes(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }
        $decoded = json_decode($stored, true);
        if (!\is_array($decoded) || $decoded === []) {
            return [];
        }
        if (isset($decoded['@graph']) && \is_array($decoded['@graph'])) {
            $decoded = $decoded['@graph'];
        } elseif (!array_is_list($decoded)) {
            $decoded = [$decoded];
        }

        $nodes = [];
        foreach ($decoded as $node) {
            if (!\is_array($node) || $node === []) {
                continue;
            }
            unset($node['@context']);
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     */
    private static function declaresArticle(array $nodes): bool
    {
        foreach ($nodes as $node) {
            foreach ((array) ($node['@type'] ?? []) as $type) {
                if (\is_string($type) && \in_array($type, self::ARTICLE_TYPES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Structured-data values are plain text, not markup: a description lifted
     * from an excerpt can carry tags and newlines, and both are noise Google
     * strips anyway.
     */
    private static function text(string $value): string
    {
        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private static function clamp(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > 0 ? mb_substr($cut, 0, $space) : $cut);
    }
}
