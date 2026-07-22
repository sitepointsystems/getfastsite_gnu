<?php

declare(strict_types=1);

namespace Fastsite\Build\Figlet;

/**
 * A tiny, self-contained FIGlet-style renderer with one bundled block font
 * (uppercase A–Z, 0–9, space, and a few punctuation marks, height 6). It is
 * deterministic and offline — no shell-out, no network — which is what the
 * robots.txt banner needs at build time.
 *
 * Output is guaranteed printable ASCII (32–126) and, via render(), width-
 * capped and centered so it renders identically in any monospace font.
 */
final class FigletRenderer
{
    private const HEIGHT = 6;

    /**
     * Each glyph is HEIGHT rows of equal width. A clean, uniform block face
     * in the spirit of the classic FIGlet "Standard" font.
     *
     * @var array<string, list<string>>
     */
    private const FONT = [
        ' ' => ['   ', '   ', '   ', '   ', '   ', '   '],
        '-' => ['      ', '      ', ' ____ ', '|____|', '      ', '      '],
        '.' => ['   ', '   ', '   ', '   ', ' _ ', '|_|'],
        "'" => [' _ ', '(_)', '   ', '   ', '   ', '   '],
        '!' => [' _ ', '| |', '| |', '|_|', '(_)', '   '],
        '&' => ['        ', '  ___   ', ' ( _ )  ', ' / _ \\/\\', ' \\___/\\/', '        '],
        ':' => ['   ', ' _ ', '(_)', ' _ ', '(_)', '   '],
        'A' => ['    _    ', '   / \\   ', '  / _ \\  ', ' / ___ \\ ', '/_/   \\_\\', '         '],
        'B' => [' ____  ', '| __ ) ', '|  _ \\ ', '| |_) |', '|____/ ', '       '],
        'C' => ['  ____ ', ' / ___|', '| |    ', '| |___ ', ' \\____|', '       '],
        'D' => [' ____  ', '|  _ \\ ', '| | | |', '| |_| |', '|____/ ', '       '],
        'E' => [' _____ ', '| ____|', '|  _|  ', '| |___ ', '|_____|', '       '],
        'F' => [' _____ ', '|  ___|', '| |_   ', '|  _|  ', '|_|    ', '       '],
        'G' => ['  ____ ', ' / ___|', '| |  _ ', '| |_| |', ' \\____|', '       '],
        'H' => [' _   _ ', '| | | |', '| |_| |', '|  _  |', '|_| |_|', '       '],
        'I' => [' ___ ', '|_ _|', ' | | ', ' | | ', '|___|', '     '],
        'J' => ['     _ ', '     | |', '  _  | |', ' | |_| |', '  \\___/ ', '        '],
        'K' => [' _  _   ', '| |/ /  ', '| \' /   ', '| . \\   ', '|_|\\_\\  ', '        '],
        'L' => [' _     ', '| |    ', '| |    ', '| |___ ', '|_____|', '       '],
        'M' => [' __  __ ', '|  \\/  |', '| |\\/| |', '| |  | |', '|_|  |_|', '        '],
        'N' => [' _   _ ', '| \\ | |', '|  \\| |', '| |\\  |', '|_| \\_|', '       '],
        'O' => ['  ___  ', ' / _ \\ ', '| | | |', '| |_| |', ' \\___/ ', '       '],
        'P' => [' ____  ', '|  _ \\ ', '| |_) |', '|  __/ ', '|_|    ', '       '],
        'Q' => ['  ___  ', ' / _ \\ ', '| | | |', '| |_| |', ' \\__\\_\\', '       '],
        'R' => [' ____  ', '|  _ \\ ', '| |_) |', '|  _ < ', '|_| \\_\\', '       '],
        'S' => [' ____  ', '/ ___| ', '\\___ \\ ', ' ___) |', '|____/ ', '       '],
        'T' => [' _____ ', '|_   _|', '  | |  ', '  | |  ', '  |_|  ', '       '],
        'U' => [' _   _ ', '| | | |', '| | | |', '| |_| |', ' \\___/ ', '       '],
        'V' => ['__     __', '\\ \\   / /', ' \\ \\ / / ', '  \\ V /  ', '   \\_/   ', '         '],
        'W' => ['__        __', '\\ \\      / /', ' \\ \\ /\\ / / ', '  \\ V  V /  ', '   \\_/\\_/   ', '            '],
        'X' => ['__  __', '\\ \\/ /', ' \\  / ', ' /  \\ ', '/_/\\_\\', '      '],
        'Y' => ['__   __', '\\ \\ / /', ' \\ V / ', '  | |  ', '  |_|  ', '       '],
        'Z' => [' _____', '|__  /', '  / / ', ' / /_ ', '/____|', '      '],
        '0' => ['  ___  ', ' / _ \\ ', '| | | |', '| |_| |', ' \\___/ ', '       '],
        '1' => [' _ ', '/ |', '| |', '| |', '|_|', '   '],
        '2' => [' ____  ', '|___ \\ ', '  __) |', ' / __/ ', '|_____|', '       '],
        '3' => [' _____ ', '|___ / ', '  |_ \\ ', ' ___) |', '|____/ ', '       '],
        '4' => [' _  _   ', '| || |  ', '| || |_ ', '|__   _|', '   |_|  ', '        '],
        '5' => [' ____  ', '| ___| ', '|___ \\ ', ' ___) |', '|____/ ', '       '],
        '6' => ['  __   ', ' / /_  ', '| \'_ \\ ', '| (_) |', ' \\___/ ', '       '],
        '7' => [' _____ ', '|___  |', '   / / ', '  / /  ', ' /_/   ', '       '],
        '8' => ['  ___  ', ' ( _ ) ', ' / _ \\ ', '| (_) |', ' \\___/ ', '       '],
        '9' => ['  ___  ', ' / _ \\ ', '| (_) |', ' \\__, |', '   /_/ ', '       '],
    ];

    private const FALLBACK = '?';

    /**
     * Renders $text as ASCII art rows (no centering/wrapping). Unknown
     * characters fall back to '?'; lowercase is upcased (the font is caps).
     *
     * @return list<string>
     */
    public function renderRows(string $text): array
    {
        $rows = array_fill(0, self::HEIGHT, '');
        foreach (str_split(strtoupper($text)) as $char) {
            $glyph = self::FONT[$char] ?? (self::FONT[self::FALLBACK] ?? self::FONT[' ']);
            for ($r = 0; $r < self::HEIGHT; ++$r) {
                $rows[$r] .= $glyph[$r];
            }
        }

        // Trim wholly-blank leading/trailing rows for a tighter banner.
        while ($rows !== [] && trim($rows[0]) === '') {
            array_shift($rows);
        }
        while ($rows !== [] && trim((string) end($rows)) === '') {
            array_pop($rows);
        }

        return array_values($rows);
    }

    /**
     * Renders $text centered within $width columns. Guarantees every output
     * line is printable ASCII and no wider than $width (glyphs that would
     * overflow are left-aligned + hard-truncated rather than wrapped).
     *
     * @return list<string>
     */
    public function renderCentered(string $text, int $width = 78): array
    {
        $out = [];
        foreach ($this->renderRows($text) as $row) {
            $row = self::asciiOnly($row);
            if (mb_strlen($row) > $width) {
                $row = substr($row, 0, $width);
            }
            $pad = intdiv(max(0, $width - \strlen($row)), 2);
            $out[] = str_repeat(' ', $pad) . $row;
        }

        return $out;
    }

    private static function asciiOnly(string $s): string
    {
        return preg_replace('/[^\x20-\x7E]/', ' ', $s) ?? $s;
    }
}
