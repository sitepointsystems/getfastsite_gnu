<?php

declare(strict_types=1);

namespace Fastsite\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every POST form in the dashboard must name the endpoint it posts to.
 *
 * Dashboard screens are GET-only routes; the writes live on a sibling
 * `…/action` route. A form without an `action` attribute posts to the CURRENT
 * URL instead, which has no POST route — so the submit dies with a bare 405 and
 * the user loses what they typed. It is invisible in review (the markup looks
 * fine), invisible to a template-render test (the form renders happily), and it
 * only bites the moment someone actually presses the button.
 *
 * Shipped exactly once, on the FTP target form.
 */
final class DashboardFormActionTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function templates(): array
    {
        $files = glob(\dirname(__DIR__, 2) . '/templates/engine/*.twig') ?: [];
        self::assertNotEmpty($files);

        return array_map(static fn (string $f): array => [$f], $files);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function testEveryPostFormNamesItsAction(string $file): void
    {
        $html = (string) file_get_contents($file);
        preg_match_all('/<form\b[^>]*>/i', $html, $matches);

        $offenders = [];
        foreach ($matches[0] as $tag) {
            // `method="dialog"` is a native <dialog> close form, not a request.
            if (preg_match('/method\s*=\s*"dialog"/i', $tag) === 1) {
                continue;
            }
            // A GET form posts nothing and self-targeting it is legitimate.
            if (preg_match('/method\s*=\s*"post"/i', $tag) !== 1) {
                continue;
            }
            if (preg_match('/\baction\s*=\s*"/i', $tag) === 1) {
                continue;
            }
            $offenders[] = trim($tag);
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                "POST form(s) in %s have no action attribute, so they post to the screen's own GET-only "
                . 'URL and the submit fails with 405.',
                basename($file),
            ),
        );
    }
}
