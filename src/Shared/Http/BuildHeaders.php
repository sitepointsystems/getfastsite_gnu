<?php

declare(strict_types=1);

namespace Fastsite\Shared\Http;

/**
 * The build's generated `_headers` file, turned into response headers.
 *
 * `_headers` is a CDN convention (Cloudflare Pages / Netlify): it is an inert
 * text file that means nothing until whoever serves the tree reads it. So
 * EVERY engine surface that serves a build must run its responses through
 * here. A surface that skips it answers without the security headers and the
 * scripts-mode CSP the site was built with — and then a shell that violates
 * the policy (Alpine and friends need `unsafe-eval`, which no mode grants)
 * works there and breaks on the real deployment, with the failure visible
 * only in a console nobody opens.
 *
 * Format (engine-generated, {@see \Fastsite\Build\Service\BuildService::headers}):
 * a `/pattern` line opens a rule, indented `Name: value` lines attach to it;
 * `*` is a wildcard. All matching rules apply; later wins per name.
 */
final class BuildHeaders
{
    /** @var array<string, array{int, list<array{string, list<array{string, string}>}>}> file => [mtime, rules] */
    private array $cache = [];

    /**
     * @param string $rootReal    realpath of the served tree (the dir holding `_headers`)
     * @param string $requestPath path WITHIN that tree, leading slash included — not the
     *                            public URL, which may sit under a base path (preview does)
     *
     * @return array<string, string>
     */
    public function for(string $rootReal, string $requestPath): array
    {
        $out = [];
        foreach ($this->rules($rootReal . \DIRECTORY_SEPARATOR . '_headers') as [$pattern, $pairs]) {
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '$#';
            if (preg_match($regex, $requestPath) === 1) {
                foreach ($pairs as [$name, $value]) {
                    $out[$name] = $value;
                }
            }
        }

        return $out;
    }

    /**
     * Parsed rules for one `_headers`, re-read when the file changes. Keyed by
     * path: one process serves many trees (direct-serve hosts, previews).
     *
     * @return list<array{string, list<array{string, string}>}>
     */
    private function rules(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }

        $mtime = (int) filemtime($file);
        $cached = $this->cache[$file] ?? null;
        if ($cached === null || $cached[0] !== $mtime) {
            $cached = [$mtime, $this->parse((string) file_get_contents($file))];
            $this->cache[$file] = $cached;
        }

        return $cached[1];
    }

    /**
     * @return list<array{string, list<array{string, string}>}>
     */
    private function parse(string $content): array
    {
        $rules = [];
        $current = null;
        foreach (preg_split('/\r\n|\n/', $content) ?: [] as $line) {
            if ($line !== '' && $line[0] === '/') {
                if ($current !== null) {
                    $rules[] = $current;
                }
                $current = [trim($line), []];
                continue;
            }
            $trimmed = trim($line);
            if ($current !== null && $trimmed !== '' && str_contains($trimmed, ':')) {
                [$name, $value] = explode(':', $trimmed, 2);
                $current[1][] = [trim($name), trim($value)];
            }
        }
        if ($current !== null) {
            $rules[] = $current;
        }

        return $rules;
    }
}
