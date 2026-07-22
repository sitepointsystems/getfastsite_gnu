<?php

declare(strict_types=1);

namespace Fastsite\Content\Service;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes body HTML on every ingest path (API, webhook, MCP, admin, CLI).
 * Policy (plan §content model): fixed tag/attribute allowlist, style attr
 * always stripped, scripts/iframes/objects stripped with a WARN recorded on
 * the content row — content-borne issues never fail a publish.
 */
final class ContentSanitizer
{
    private readonly HtmlSanitizerConfig $config;
    private readonly HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig())
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->withMaxInputLength(2_000_000);

        // The full set of standard, non-interactive-scripting content tags.
        // Allowlist mode drops an unknown tag WITH its subtree, so anything a
        // normal article uses must be here (e.g. <dl>/<dt>/<dd> were missing and
        // silently ate their content). Interactive/script-bearing elements
        // (script, iframe, object, embed, form, input, button, style…) are
        // deliberately absent and stripped-with-WARN below.
        $blocks = [
            // Text + headings
            'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hgroup', 'br', 'hr', 'span', 'div', 'wbr',
            'strong', 'em', 'b', 'i', 'u', 's', 'small', 'mark', 'sub', 'sup', 'del', 'ins',
            'abbr', 'cite', 'q', 'kbd', 'samp', 'var', 'code', 'time', 'dfn', 'data', 'output',
            'ruby', 'rt', 'rp', 'bdi', 'bdo', 'address', 'pre', 'blockquote',
            // Lists
            'ul', 'ol', 'li', 'dl', 'dt', 'dd',
            // Tables
            'table', 'caption', 'colgroup', 'col', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td',
            // Figures + native disclosure
            'figure', 'figcaption', 'details', 'summary', 'picture',
            // Semantic sectioning — safe layout wrappers content bodies use.
            'section', 'article', 'nav', 'header', 'footer', 'main', 'aside',
        ];
        foreach ($blocks as $tag) {
            $config = $config->allowElement($tag, ['class']);
        }

        $config = $config
            ->allowElement('a', ['href', 'title', 'rel', 'class', 'download', 'data-file-id'])
            ->allowElement('img', ['src', 'alt', 'width', 'height', 'class', 'loading', 'decoding', 'data-media-id'])
            ->allowElement('video', ['poster', 'width', 'height', 'controls', 'playsinline', 'preload', 'class'])
            ->allowElement('audio', ['controls', 'preload', 'class'])
            ->allowElement('source', ['src', 'type', 'srcset', 'media', 'sizes'])
            // Functional attributes that carry meaning (not styling).
            ->allowAttribute('id', '*')       // in-page anchor targets
            ->allowAttribute('title', '*')
            ->allowAttribute('lang', '*')
            ->allowAttribute('dir', '*')
            ->allowAttribute('scope', ['th'])
            ->allowAttribute('colspan', ['td', 'th'])
            ->allowAttribute('rowspan', ['td', 'th'])
            ->allowAttribute('span', ['col', 'colgroup'])
            ->allowAttribute('datetime', ['time', 'ins', 'del'])
            ->allowAttribute('cite', ['blockquote', 'q', 'ins', 'del'])
            ->allowAttribute('start', ['ol'])
            ->allowAttribute('reversed', ['ol'])
            ->allowAttribute('type', ['ol'])
            ->allowAttribute('value', ['li'])
            ->allowAttribute('open', ['details'])
            ->allowAttribute('data-fs-component', ['div'])
            ->allowAttribute('data-values', ['div']);

        $this->config = $config;
        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): SanitizedHtml
    {
        $warnings = [];
        foreach (['script' => 'inline script', 'iframe' => 'iframe embed', 'object' => 'object embed', 'embed' => 'embed element', 'form' => 'form element'] as $tag => $label) {
            $count = preg_match_all('/<' . $tag . '\b/i', $html);
            if ($count > 0) {
                $warnings[] = sprintf('Stripped %d %s(s) from body HTML.', $count, $label);
            }
        }

        if (preg_match('/\sstyle\s*=/i', $html) === 1) {
            $warnings[] = 'Stripped inline style attribute(s) from body HTML.';
        }

        $clean = $this->sanitizerFor($html)->sanitize($html);

        // Only images that go through the media pipeline (data-media-id) may
        // survive — those become <picture> with dimensions/alt at build time.
        // A bare <img> would ship un-optimised and fail the PageSpeed audit,
        // so strip it here (content must never wedge a build).
        $bare = preg_match_all('/<img\b(?![^>]*\bdata-media-id=)[^>]*>/i', $clean);
        if ($bare > 0) {
            $clean = preg_replace('/<img\b(?![^>]*\bdata-media-id=)[^>]*>/i', '', $clean) ?? $clean;
            // Drop figures left empty once their image is gone.
            $clean = preg_replace('/<figure\b[^>]*>\s*(?:<figcaption\b[^>]*>.*?<\/figcaption>)?\s*<\/figure>/is', '', $clean) ?? $clean;
            $warnings[] = sprintf('Stripped %d image(s) without a media reference — upload via the media API and use data-media-id.', $bare);
        }

        return new SanitizedHtml($clean, $warnings);
    }

    /**
     * data-* attributes are inert markup (no execution vector — values are
     * still entity-escaped by the sanitizer) and site JS shipped via trusted
     * file embeds hydrates against them, so they must NEVER be stripped.
     * Symfony's sanitizer has no wildcard attribute names, so allow exactly
     * the data-* names the input uses, on any element.
     */
    private function sanitizerFor(string $html): HtmlSanitizer
    {
        // Name charset = anything HTML allows in an attribute name; the
        // leading class also matches a closing quote so minified markup
        // (`class="x"data-id="1"`) is caught. Names found in plain text are
        // harmless false positives (allowing an unused name is a no-op).
        preg_match_all('/[\s"\'](data-[^\s"\'>\/=]+)/i', $html, $m);
        $names = array_unique(array_map(strtolower(...), $m[1]));
        if ($names === []) {
            return $this->sanitizer;
        }

        $config = $this->config;
        foreach ($names as $name) {
            $config = $config->allowAttribute($name, '*');
        }

        return new HtmlSanitizer($config);
    }
}
