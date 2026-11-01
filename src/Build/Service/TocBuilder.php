<?php

declare(strict_types=1);

namespace Fastsite\Build\Service;

use Fastsite\Content\Service\Slugger;

/**
 * Injects slugified `id` attributes into body <h2>/<h3> headings (hand-written
 * ids are respected) and extracts the table of contents the shell renders via
 * `content.toc`. Pure build-time transform on the fully-expanded body — no JS
 * ships from here; the optional scroll-spy is injected separately by
 * BuildService, and only into pages whose shell opted in with a
 * `data-fs-toc` nav.
 */
final class TocBuilder
{
    /**
     * @return array{html: string, toc: list<array{id: string, text: string, level: int}>}
     */
    public static function build(string $bodyHtml): array
    {
        if (stripos($bodyHtml, '<h2') === false && stripos($bodyHtml, '<h3') === false) {
            return ['html' => $bodyHtml, 'toc' => []];
        }

        $toc = [];

        // Seed with every id already present anywhere in the body (headings,
        // divs, hand-written anchors) so a generated heading id can never
        // collide with one further down the document.
        $used = [];
        if (preg_match_all('#\bid\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $bodyHtml, $existing) > 0) {
            foreach ([...$existing[1], ...$existing[2]] as $id) {
                if ($id !== '') {
                    $used[$id] = true;
                }
            }
        }

        $html = preg_replace_callback(
            // The attribute segment tolerates > inside quoted values.
            '#<h([23])((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>(.*?)</h\1\s*>#is',
            static function (array $m) use (&$toc, &$used): string {
                $text = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if ($text === '') {
                    return $m[0];
                }

                if (preg_match('#\bid\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#i', $m[2], $idMatch) === 1) {
                    $id = $idMatch[1] !== '' ? $idMatch[1] : (($idMatch[2] ?? '') !== '' ? $idMatch[2] : ($idMatch[3] ?? ''));
                    if ($id !== '') {
                        $toc[] = ['id' => $id, 'text' => $text, 'level' => (int) $m[1]];
                    }

                    return $m[0];
                }

                $base = Slugger::slugify($text);
                $id = $base;
                $n = 2;
                while (isset($used[$id])) {
                    $id = $base . '-' . $n;
                    ++$n;
                }
                $used[$id] = true;
                $toc[] = ['id' => $id, 'text' => $text, 'level' => (int) $m[1]];

                return '<h' . $m[1] . $m[2] . ' id="' . $id . '">' . $m[3] . '</h' . $m[1] . '>';
            },
            $bodyHtml,
        );

        return ['html' => $html ?? $bodyHtml, 'toc' => $toc];
    }
}
