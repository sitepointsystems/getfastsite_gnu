<?php

declare(strict_types=1);

namespace Fastsite\Site\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\SiteScript;

/**
 * User-managed header/footer scripts. Trusted, owner-curated content — the
 * `code` is injected verbatim at build (a bare URL becomes a <script src>
 * with the chosen strategy). A site with any enabled script opts out of the
 * zero-JS / 100-PageSpeed guarantee for that site (build relaxes CSP + audit).
 */
final class SiteScriptService
{
    private const LOCATIONS = ['head', 'body_open', 'body_end'];
    private const STRATEGIES = ['blocking', 'async', 'defer'];

    /** Same cap as component hooks: a targeting rule, not a sitemap. */
    private const MAX_SLUGS = 200;

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return list<SiteScript>
     */
    public function list(string $handle, bool $onlyEnabled = false): array
    {
        $sql = 'SELECT * FROM site_scripts';
        if ($onlyEnabled) {
            $sql .= ' WHERE enabled = 1';
        }
        $sql .= ' ORDER BY location, priority, id';

        return array_map(SiteScript::fromRow(...), $this->databases->site($handle)->fetchAllAssociative($sql));
    }

    /** True when any script is enabled — the signal to relax CSP + PageAudit. */
    public function hasEnabled(string $handle): bool
    {
        try {
            return (bool) $this->databases->site($handle)->fetchOne('SELECT 1 FROM site_scripts WHERE enabled = 1 LIMIT 1');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    public function save(string $handle, array $input): SiteScript
    {
        $location = (string) ($input['location'] ?? 'head');
        if (!\in_array($location, self::LOCATIONS, true)) {
            throw new \InvalidArgumentException('location must be one of: ' . implode(', ', self::LOCATIONS));
        }
        $strategy = (string) ($input['strategy'] ?? 'defer');
        if (!\in_array($strategy, self::STRATEGIES, true)) {
            throw new \InvalidArgumentException('strategy must be one of: ' . implode(', ', self::STRATEGIES));
        }
        $code = (string) ($input['code'] ?? '');
        if (mb_strlen($code) > 100_000) {
            throw new \InvalidArgumentException('Script is too large (100 KB limit).');
        }

        // Read once: `$input['enabled'] ?? true` only guards the FIRST operand —
        // the later !== comparisons hit the missing key and warn on every save
        // that omits it.
        $enabled = $input['enabled'] ?? true;

        $target = (string) ($input['target'] ?? 'all');
        if (!\in_array($target, SiteScript::TARGETS, true)) {
            throw new \InvalidArgumentException('target must be one of: ' . implode(', ', SiteScript::TARGETS));
        }

        $fields = [
            'label' => mb_substr(trim((string) ($input['label'] ?? '')), 0, 200),
            'location' => $location,
            'strategy' => $strategy,
            'priority' => (int) ($input['priority'] ?? 100),
            'code' => $code,
            'enabled' => ($enabled && $enabled !== '0' && $enabled !== 'false') ? 1 : 0,
            'target' => $target,
            'include_slugs' => json_encode(self::slugList($input['include_slugs'] ?? []), JSON_THROW_ON_ERROR),
            'exclude_slugs' => json_encode(self::slugList($input['exclude_slugs'] ?? []), JSON_THROW_ON_ERROR),
            'updated_at' => $this->clock->iso(),
        ];

        $db = $this->databases->site($handle);
        $id = (int) ($input['id'] ?? 0);
        if ($id > 0 && $db->fetchOne('SELECT 1 FROM site_scripts WHERE id = ?', [$id]) !== false) {
            $db->update('site_scripts', $fields, ['id' => $id]);
        } else {
            $fields['created_at'] = $this->clock->iso();
            $db->insert('site_scripts', $fields);
            $id = (int) $db->lastInsertId();
        }

        $row = $db->fetchAssociative('SELECT * FROM site_scripts WHERE id = ?', [$id]);
        \assert($row !== false);

        return SiteScript::fromRow($row);
    }

    public function delete(string $handle, int $id): void
    {
        $this->databases->site($handle)->executeStatement('DELETE FROM site_scripts WHERE id = ?', [$id]);
    }

    /**
     * Rendered HTML per injection location (enabled only, in order). A bare URL
     * becomes a <script src> with async/defer; anything else is normalized (see
     * normalize) and injected.
     *
     * @return array{head: string, body_open: string, body_end: string}
     */
    public function renderByLocation(string $handle): array
    {
        return self::renderList($this->list($handle, true));
    }

    /**
     * The blocks that belong on ONE page, given its content type and PRIMARY
     * slug. Pass the already-loaded enabled scripts — the build loads them
     * once and calls this per distinct (type, slug), so a 200-post site renders
     * the bundle a handful of times rather than two hundred.
     *
     * @param list<SiteScript> $scripts enabled scripts, in injection order
     * @return array{head: string, body_open: string, body_end: string}
     */
    public static function renderFor(array $scripts, string $type, string $slug): array
    {
        return self::renderList(array_values(array_filter(
            $scripts,
            static fn (SiteScript $s): bool => $s->appliesTo($type, $slug),
        )));
    }

    /**
     * @param list<SiteScript> $scripts
     * @return array{head: string, body_open: string, body_end: string}
     */
    private static function renderList(array $scripts): array
    {
        $out = ['head' => '', 'body_open' => '', 'body_end' => ''];
        foreach ($scripts as $script) {
            $out[$script->location] .= self::render($script) . "\n";
        }

        return $out;
    }

    /**
     * Preview-staleness fingerprint.
     *
     * This used to be computed inline in `PreviewServeHandler` as a hash of
     * `renderByLocation()` — the rendered BYTES. That was correct while a
     * script either applied everywhere or not at all, and became wrong the
     * moment targeting existed: changing a script's target changes which pages
     * carry it and changes no byte of the bundle, so preview would have kept
     * serving the old tree. That is the preview-staleness bug CLAUDE.md
     * already records five instances of; this is where the sixth would have
     * been.
     *
     * Enabled rows only, and `enabled` itself is not in the SELECT — disabling
     * a script removes it from the set, which is the change.
     */
    public function signature(string $handle): string
    {
        try {
            $rows = $this->databases->site($handle)->fetchAllAssociative(
                'SELECT id, location, strategy, priority, code, target, include_slugs, exclude_slugs
                 FROM site_scripts WHERE enabled = 1 ORDER BY location, priority, id',
            );

            return $rows === [] ? '' : md5(json_encode($rows, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Normalize a slug list from any transport shape: an array, or a comma/
     * newline-separated string (the dashboard form). Slashes are trimmed so
     * "/terms/" and "terms" match the same page.
     *
     * Lifted unchanged from ComponentHookService — the two features share one
     * targeting vocabulary, so they must share its parsing too, or "/Pricing/"
     * would mean one thing to a hook and another to a script.
     *
     * @return list<string>
     */
    private static function slugList(mixed $raw): array
    {
        if (\is_string($raw)) {
            $raw = preg_split('/[,\n]/', $raw) ?: [];
        }
        if (!\is_array($raw)) {
            throw new \InvalidArgumentException('Slug lists must be an array or a comma-separated string.');
        }

        $out = [];
        foreach ($raw as $slug) {
            if (!\is_string($slug) && !is_numeric($slug)) {
                continue;
            }
            // Whitespace first, then slashes: " /Pricing/ " has to reach
            // `pricing`, and one trim cannot do both.
            $slug = strtolower(trim(trim((string) $slug), '/'));
            if ($slug !== '' && !\in_array($slug, $out, true)) {
                $out[] = $slug;
            }
        }
        if (\count($out) > self::MAX_SLUGS) {
            throw new \InvalidArgumentException('At most ' . self::MAX_SLUGS . ' slugs per list.');
        }

        return $out;
    }

    private static function render(SiteScript $script): string
    {
        $code = trim($script->code);
        // A bare URL (no markup) → a <script src> honoring the strategy.
        if (self::isBareUrl($code)) {
            $attr = $script->strategy === 'async' ? ' async' : ($script->strategy === 'defer' ? ' defer' : '');

            return '<script src="' . htmlspecialchars($code, ENT_QUOTES) . '"' . $attr . '></script>';
        }

        // Otherwise inject the author's markup, with any loose JavaScript given
        // the <script> tags it needs to run.
        return self::normalize($code);
    }

    /** A pasted URL with no markup around it — the auto-`<script src>` case. */
    private static function isBareUrl(string $code): bool
    {
        return $code !== '' && !str_contains($code, '<') && preg_match('#^https?://\S+$#', $code) === 1;
    }

    /**
     * True when the build has to add `<script>` tags to make this snippet run —
     * i.e. it carries JavaScript outside any element. Drives the honest note on
     * the Site-Settings Scripts card, so the author sees that the code they
     * saved is not quite the code that ships.
     */
    public static function needsWrapping(string $code): bool
    {
        $code = trim($code);
        if ($code === '' || self::isBareUrl($code)) {
            return false;
        }

        return self::normalize($code) !== $code;
    }

    /**
     * Elements whose body is raw text, NOT markup. This is the whole reason
     * this pass is a scanner rather than a regex: inside a script, `<` and `>`
     * are comparison operators (`for (i = 0; i < n; i++)`), and inside a style
     * they are child combinators — treating either as a tag corrupts the code.
     */
    private const RAW_TEXT_ELEMENTS = ['script', 'style', 'textarea', 'title'];

    /** Elements with no closing tag — they never open a nesting level. */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * Give loose JavaScript the `<script>` tags it lost, leaving every element
     * the author actually wrote exactly as written.
     *
     * The engine used to inject this field verbatim unless it was a bare URL,
     * so a snippet pasted without its tags — or, worse, a real
     * `<script src=…></script>` loader followed by a bare inline IIFE — was
     * emitted as naked JavaScript into every page: the code never ran, and the
     * source text rendered into the document. It looked like the script was
     * "not working" while the actual failure was one level down in the HTML.
     *
     * The unit of "leave it alone" is the whole ELEMENT, not the tag. Consuming
     * tag-by-tag makes every text node in the snippet look loose, so
     * `<noscript>Please enable JavaScript</noscript>` and a consent banner's
     * visible copy came back wrapped in `<script>` — the text vanished from the
     * page and invalid inline scripts shipped in its place, on every page. So
     * an opening tag consumes through its matching close (counting nested tags
     * of the same name), and only text OUTSIDE every element is wrapped. Pure
     * JavaScript with no markup at all is the degenerate case of that rule.
     *
     * Idempotent: re-running it over its own output changes nothing, because
     * what it emitted is now a complete element.
     */
    public static function normalize(string $code): string
    {
        $length = \strlen($code);
        $out = '';
        $text = '';
        $i = 0;

        while ($i < $length) {
            $lt = strpos($code, '<', $i);
            if ($lt === false) {
                $text .= substr($code, $i);
                break;
            }
            $text .= substr($code, $i, $lt - $i);

            $end = self::elementEnd($code, $lt);
            if ($end === null) {
                // Not a tag — a comparison operator in JavaScript.
                $text .= '<';
                $i = $lt + 1;
                continue;
            }
            $out .= self::wrapLoose($text) . substr($code, $lt, $end - $lt);
            $text = '';
            $i = $end;
        }

        return $out . self::wrapLoose($text);
    }

    /**
     * A run of text that sat outside every element: JavaScript that lost its
     * tags, so it gets them back. Whitespace-only runs are layout and are
     * emitted as-is, and the whitespace around real code stays OUTSIDE the
     * wrapper so the block still reads the way the author laid it out.
     */
    private static function wrapLoose(string $text): string
    {
        $core = trim($text);
        if ($core === '') {
            return $text;
        }
        $lead = substr($text, 0, \strlen($text) - \strlen(ltrim($text)));
        $trail = substr($text, \strlen(rtrim($text)));

        return $lead . '<script>' . $core . '</script>' . $trail;
    }

    /**
     * The offset just past the whole ELEMENT starting at `$pos` (its body and
     * closing tag included), or null when the `<` there does not begin a tag.
     */
    private static function elementEnd(string $s, int $pos): ?int
    {
        $length = \strlen($s);
        if ($pos + 1 >= $length) {
            return null;
        }
        $next = $s[$pos + 1];

        if ($next === '!') {
            if (substr($s, $pos, 4) === '<!--') {
                $end = strpos($s, '-->', $pos + 4);

                return $end === false ? $length : $end + 3;
            }
            $end = strpos($s, '>', $pos);

            return $end === false ? $length : $end + 1;
        }

        // A stray closing tag: consume the tag itself, nothing more.
        if ($next === '/') {
            return self::tagNameAt($s, $pos + 2) !== '' ? self::tagEnd($s, $pos) : null;
        }

        $tag = self::tagNameAt($s, $pos + 1);
        if ($tag === '') {
            return null; // `i<a.length` — a comparison, not a tag.
        }

        $end = self::tagEnd($s, $pos);
        if (\in_array($tag, self::RAW_TEXT_ELEMENTS, true)) {
            // Its body is text, not markup: `<` and `>` inside are operators.
            return self::rawTextEnd($s, $end, $tag);
        }
        if (\in_array($tag, self::VOID_ELEMENTS, true) || str_ends_with(rtrim(substr($s, $pos, $end - $pos), '>'), '/')) {
            return $end;
        }

        return self::closeAfter($s, $end, $tag);
    }

    /**
     * The tag NAME at `$pos` (just past the `<` or `</`), lowercased — or ''
     * when what follows is not a tag name.
     *
     * The boundary check is what keeps minified JavaScript intact: a tag name
     * must be followed by whitespace, `>` or `/`, so `i<a.length` and `if(x<y)`
     * are read as comparisons rather than as `<a …>` and `<y>` elements. Before
     * this, minified code — the exact thing people paste — was cut into
     * syntactically broken fragments with its tail rendered onto the page.
     */
    private static function tagNameAt(string $s, int $pos): string
    {
        $length = \strlen($s);
        if ($pos >= $length || !ctype_alpha($s[$pos])) {
            return '';
        }
        $j = $pos;
        while ($j < $length && (ctype_alnum($s[$j]) || $s[$j] === '-')) {
            ++$j;
        }
        if ($j >= $length) {
            return '';
        }
        $after = $s[$j];
        if ($after !== '>' && $after !== '/' && !ctype_space($after)) {
            return '';
        }

        return strtolower(substr($s, $pos, $j - $pos));
    }

    /**
     * End of the element opened before `$from`, counting nested tags of the
     * same name so an inner `<div>` cannot close an outer one. An unclosed
     * element takes the rest of the block, which is what a browser does too.
     */
    private static function closeAfter(string $s, int $from, string $tag): int
    {
        $length = \strlen($s);
        $depth = 1;
        $i = $from;
        while ($i < $length) {
            $lt = strpos($s, '<', $i);
            if ($lt === false) {
                return $length;
            }
            if ($s[$lt + 1] === '/' && self::tagNameAt($s, $lt + 2) === $tag) {
                $end = self::tagEnd($s, $lt);
                if (--$depth === 0) {
                    return $end;
                }
                $i = $end;
                continue;
            }
            if (self::tagNameAt($s, $lt + 1) === $tag) {
                $end = self::tagEnd($s, $lt);
                if (!str_ends_with(rtrim(substr($s, $lt, $end - $lt), '>'), '/')) {
                    ++$depth;
                }
                $i = $end;
                continue;
            }
            $i = $lt + 1;
        }

        return $length;
    }

    /** End of the tag opening at `$pos`, honouring quoted attribute values. */
    private static function tagEnd(string $s, int $pos): int
    {
        $length = \strlen($s);
        $quote = '';
        for ($i = $pos + 1; $i < $length; ++$i) {
            $c = $s[$i];
            if ($quote !== '') {
                if ($c === $quote) {
                    $quote = '';
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                continue;
            }
            if ($c === '>') {
                return $i + 1;
            }
        }

        return $length;
    }

    /** End of a raw-text element's closing tag, searching from `$from`. */
    private static function rawTextEnd(string $s, int $from, string $tag): int
    {
        $length = \strlen($s);
        $needle = '</' . $tag;
        $i = $from;
        while (true) {
            $at = stripos($s, $needle, $i);
            if ($at === false) {
                return $length; // Unclosed — the rest of the block is its body.
            }
            $after = $at + \strlen($needle);
            // "</scriptish" is not the closing tag.
            if ($after < $length && preg_match('/[\s>\/]/', $s[$after]) !== 1) {
                $i = $after;
                continue;
            }
            $end = strpos($s, '>', $at);

            return $end === false ? $length : $end + 1;
        }
    }
}
