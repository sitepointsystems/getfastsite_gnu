<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use Fastsite\Build\Service\JsonLdBuilder;
use PHPUnit\Framework\TestCase;

final class JsonLdBuilderTest extends TestCase
{
    private function builder(
        string $entityType = 'Organization',
        array $sameAs = [],
        ?array $logo = null,
        string $name = 'Demo Site',
    ): JsonLdBuilder {
        return new JsonLdBuilder(
            origin: 'https://example.com',
            siteName: $name,
            entityType: $entityType,
            sameAs: $sameAs,
            siteDescription: 'A demo site.',
            logo: $logo,
        );
    }

    /**
     * @return array<string, array<string, mixed>> nodes keyed by @type
     */
    private function nodes(?string $json): array
    {
        self::assertIsString($json);
        $decoded = json_decode(html_entity_decode((string) $json, ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        self::assertIsArray($decoded);
        self::assertSame('https://schema.org', $decoded['@context']);

        $out = [];
        foreach ($decoded['@graph'] as $node) {
            $out[(string) $node['@type']] = $node;
        }

        return $out;
    }

    public function testEveryPageCarriesTheSiteEntityAndWebsite(): void
    {
        $nodes = $this->nodes($this->builder()->graph(
            kind: 'page',
            url: 'https://example.com/about/',
            title: 'About',
            locale: 'en',
        ));

        self::assertSame('https://example.com/#organization', $nodes['Organization']['@id']);
        self::assertSame('https://example.com/', $nodes['Organization']['url']);
        self::assertSame('https://example.com/#website', $nodes['WebSite']['@id']);
        // The website names its publisher BY REFERENCE — that link is the whole
        // point of shipping one graph instead of three loose blobs.
        self::assertSame(['@id' => 'https://example.com/#organization'], $nodes['WebSite']['publisher']);
        self::assertSame(['@id' => 'https://example.com/#website'], $nodes['WebPage']['isPartOf']);
        self::assertSame('en', $nodes['WebPage']['inLanguage']);
    }

    public function testPostCarriesAnArticleWiredToThePageAndPublisher(): void
    {
        $nodes = $this->nodes($this->builder()->graph(
            kind: 'post',
            url: 'https://example.com/blog/hello/',
            title: 'Hello world',
            locale: 'en',
            description: 'An intro.',
            published: '2026-08-01T09:00:00Z',
            modified: '2026-08-02T09:00:00Z',
            author: 'Ada Lovelace',
            breadcrumb: [
                ['name' => 'Home', 'url' => 'https://example.com/'],
                ['name' => 'Blog', 'url' => 'https://example.com/blog/'],
                ['name' => 'Hello world', 'url' => 'https://example.com/blog/hello/'],
            ],
        ));

        $article = $nodes['BlogPosting'];
        self::assertSame('https://example.com/blog/hello/#article', $article['@id']);
        self::assertSame(['@id' => 'https://example.com/blog/hello/#webpage'], $article['mainEntityOfPage']);
        self::assertSame(['@id' => 'https://example.com/#organization'], $article['publisher']);
        self::assertSame('2026-08-01T09:00:00Z', $article['datePublished']);
        self::assertSame('2026-08-02T09:00:00Z', $article['dateModified']);
        self::assertSame($nodes['Person']['@id'], $article['author']['@id']);
        self::assertSame('Ada Lovelace', $nodes['Person']['name']);
    }

    public function testBreadcrumbTrailOmitsTheUrlOfTheCurrentPage(): void
    {
        $nodes = $this->nodes($this->builder()->graph(
            kind: 'post',
            url: 'https://example.com/blog/hello/',
            title: 'Hello world',
            locale: 'en',
            breadcrumb: [
                ['name' => 'Home', 'url' => 'https://example.com/'],
                ['name' => 'Blog', 'url' => 'https://example.com/blog/'],
                ['name' => 'Hello world', 'url' => 'https://example.com/blog/hello/'],
            ],
        ));

        $items = $nodes['BreadcrumbList']['itemListElement'];
        self::assertCount(3, $items);
        self::assertSame(1, $items[0]['position']);
        self::assertSame('https://example.com/', $items[0]['item']);
        self::assertSame('https://example.com/blog/', $items[1]['item']);
        // Google's spec: the last crumb IS the current page and carries no URL.
        self::assertArrayNotHasKey('item', $items[2]);
        self::assertSame(['@id' => 'https://example.com/blog/hello/#breadcrumb'], $nodes['WebPage']['breadcrumb']);
    }

    public function testSingleCrumbEmitsNoBreadcrumbAtAll(): void
    {
        $nodes = $this->nodes($this->builder()->graph(
            kind: 'home',
            url: 'https://example.com/',
            title: 'Demo Site',
            locale: 'en',
            breadcrumb: [['name' => 'Home', 'url' => 'https://example.com/']],
        ));

        self::assertArrayNotHasKey('BreadcrumbList', $nodes);
        self::assertArrayNotHasKey('breadcrumb', $nodes['WebPage']);
    }

    public function testBlogIndexIsACollectionPageListingItsPosts(): void
    {
        $nodes = $this->nodes($this->builder()->graph(
            kind: 'blog',
            url: 'https://example.com/blog/',
            title: 'Blog',
            locale: 'en',
            items: [
                ['name' => 'First', 'url' => 'https://example.com/blog/first/'],
                ['name' => 'Second', 'url' => 'https://example.com/blog/second/'],
            ],
        ));

        self::assertArrayNotHasKey('WebPage', $nodes);
        $list = $nodes['CollectionPage']['mainEntity'];
        self::assertSame('ItemList', $list['@type']);
        self::assertSame(2, \count($list['itemListElement']));
        self::assertSame('https://example.com/blog/second/', $list['itemListElement'][1]['url']);
        self::assertSame(2, $list['itemListElement'][1]['position']);
    }

    public function testFeaturedImageBecomesOneReferencedImageObject(): void
    {
        $nodes = $this->nodes($this->builder()->graph(
            kind: 'post',
            url: 'https://example.com/blog/hello/',
            title: 'Hello',
            locale: 'en',
            image: ['url' => 'https://example.com/media/4/hero.jpg', 'width' => 1920, 'height' => 1080, 'caption' => 'A hero'],
        ));

        $imageId = 'https://example.com/blog/hello/#primaryimage';
        self::assertSame($imageId, $nodes['ImageObject']['@id']);
        self::assertSame(1920, $nodes['ImageObject']['width']);
        self::assertSame('A hero', $nodes['ImageObject']['caption']);
        // Page and article both point at the SAME node rather than repeating it.
        self::assertSame(['@id' => $imageId], $nodes['WebPage']['primaryImageOfPage']);
        self::assertSame(['@id' => $imageId], $nodes['BlogPosting']['image']);
    }

    public function testOrganizationLogoAndSameAs(): void
    {
        $nodes = $this->nodes($this->builder(
            sameAs: ['https://www.linkedin.com/company/demo', 'https://github.com/demo'],
            logo: ['url' => 'https://example.com/media/1/logo.png', 'width' => 400, 'height' => 120, 'caption' => 'Demo'],
        )->graph(kind: 'home', url: 'https://example.com/', title: 'Demo Site', locale: 'en'));

        self::assertSame(['@id' => 'https://example.com/#logo'], $nodes['Organization']['logo']);
        self::assertSame(['@id' => 'https://example.com/#logo'], $nodes['Organization']['image']);
        self::assertSame('https://example.com/#logo', $nodes['ImageObject']['@id']);
        self::assertSame(['https://www.linkedin.com/company/demo', 'https://github.com/demo'], $nodes['Organization']['sameAs']);
    }

    public function testPersonSiteHasNoLogoPropertyAndAbsorbsItsOwnByline(): void
    {
        $nodes = $this->nodes($this->builder(
            entityType: 'Person',
            logo: ['url' => 'https://example.com/media/1/me.png', 'width' => 400, 'height' => 400, 'caption' => ''],
            name: 'Ada Lovelace',
        )->graph(
            kind: 'post',
            url: 'https://example.com/blog/hello/',
            title: 'Hello',
            locale: 'en',
            author: 'ada lovelace',
        ));

        // schema.org puts `logo` on Organization only; a Person has `image`.
        self::assertArrayNotHasKey('logo', $nodes['Person']);
        self::assertSame(['@id' => 'https://example.com/#logo'], $nodes['Person']['image']);
        // The byline IS the site entity — a second Person node would split one
        // human across two @ids, which is the opposite of what sameAs is for.
        self::assertSame('https://example.com/#organization', $nodes['Person']['@id']);
        self::assertSame(['@id' => 'https://example.com/#organization'], $nodes['BlogPosting']['author']);
    }

    public function testHeadlineIsTrimmedToGooglesCeilingAtAWordBoundary(): void
    {
        $title = str_repeat('word ', 40); // 200 chars
        $nodes = $this->nodes($this->builder()->graph(
            kind: 'post',
            url: 'https://example.com/blog/long/',
            title: $title,
            locale: 'en',
        ));

        $headline = $nodes['BlogPosting']['headline'];
        self::assertLessThanOrEqual(110, mb_strlen($headline));
        self::assertStringEndsWith('word', $headline);
        // The full title still reaches the page node — only the headline is capped.
        self::assertSame(trim($title), $nodes['WebPage']['name']);
    }

    public function testStoredJsonLdIsMergedIntoTheGraph(): void
    {
        $nodes = $this->nodes($this->builder()->graph(
            kind: 'page',
            url: 'https://example.com/pricing/',
            title: 'Pricing',
            locale: 'en',
            stored: '{"@context":"https://schema.org","@type":"Product","name":"Widget"}',
        ));

        self::assertSame('Widget', $nodes['Product']['name']);
        // Merged ALONGSIDE, not instead of — the page is still described.
        self::assertArrayHasKey('WebPage', $nodes);
        self::assertArrayHasKey('Organization', $nodes);
        // The graph declares @context once; a node must not carry its own.
        self::assertArrayNotHasKey('@context', $nodes['Product']);
    }

    public function testStoredArticleReplacesTheGeneratedOne(): void
    {
        $nodes = $this->nodes($this->builder()->graph(
            kind: 'post',
            url: 'https://example.com/blog/hello/',
            title: 'Hello',
            locale: 'en',
            author: 'Ada Lovelace',
            stored: '{"@type":"NewsArticle","headline":"Hand written"}',
        ));

        // Two competing Article entities on one page is worse than either.
        self::assertArrayNotHasKey('BlogPosting', $nodes);
        self::assertSame('Hand written', $nodes['NewsArticle']['headline']);
        // …and the author node the engine would have minted goes with it.
        self::assertArrayNotHasKey('Person', $nodes);
    }

    public function testStoredGraphDocumentIsUnwrapped(): void
    {
        $nodes = $this->nodes($this->builder()->graph(
            kind: 'page',
            url: 'https://example.com/faq/',
            title: 'FAQ',
            locale: 'en',
            stored: '{"@context":"https://schema.org","@graph":[{"@type":"Event","name":"Launch"},{"@type":"Place","name":"Copenhagen"}]}',
        ));

        self::assertSame('Launch', $nodes['Event']['name']);
        self::assertSame('Copenhagen', $nodes['Place']['name']);
    }

    public function testBrokenStoredJsonLdIsIgnoredRatherThanFatal(): void
    {
        $json = $this->builder()->graph(
            kind: 'page',
            url: 'https://example.com/x/',
            title: 'X',
            locale: 'en',
            stored: 'not json at all {',
        );

        $nodes = $this->nodes($json);
        self::assertArrayHasKey('WebPage', $nodes);
    }

    public function testScriptBreakoutInEveryTextFieldIsNeutralised(): void
    {
        $json = (string) $this->builder(name: '</script><script>alert(1)</script>')->graph(
            kind: 'post',
            url: 'https://example.com/blog/x/',
            title: 'Title </script><script>alert(2)</script>',
            locale: 'en',
            description: 'Desc </script>',
            author: '</script>Eve',
            breadcrumb: [
                ['name' => 'Home </script>', 'url' => 'https://example.com/'],
                ['name' => 'X', 'url' => 'https://example.com/blog/x/'],
            ],
        );

        self::assertStringNotContainsString('<', $json);
        self::assertStringNotContainsString('>', $json);
    }

    public function testNoCanonicalUrlMeansNoGraph(): void
    {
        // The 404 page describes nothing and has no @id to hang a graph on.
        self::assertNull($this->builder()->graph(kind: 'page', url: '', title: 'Not found', locale: 'en'));
    }

    public function testDescriptionsAreFlattenedToPlainText(): void
    {
        $nodes = $this->nodes($this->builder()->graph(
            kind: 'page',
            url: 'https://example.com/x/',
            title: 'X',
            locale: 'en',
            description: "<p>Two   lines\nof <strong>markup</strong>.</p>",
        ));

        self::assertSame('Two lines of markup.', $nodes['WebPage']['description']);
    }
}
