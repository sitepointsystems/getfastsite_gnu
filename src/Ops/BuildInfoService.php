<?php

declare(strict_types=1);

namespace Fastsite\Ops;

use Symfony\Component\Process\Process;

/**
 * What is ACTUALLY running in this container.
 *
 * This exists because "did the deploy land?" had no cheap answer, and the
 * expensive answers were wrong. `/healthz` returning `{status: ok}` cannot
 * distinguish a fresh container from an OLD one the orchestrator kept alive
 * after a failed build — both answer 200 — so a routine security bump
 * (sharp 0.33.5 -> 0.35.5, for CVEs inherited from libvips/libheif/librsvg)
 * cost an afternoon of archaeology and produced a confidently wrong verdict.
 *
 * The specific trap, which is why this class reports rather than infers:
 * **encoded image bytes are not a version fingerprint.** AVIF output tracks
 * the libvips THREAD COUNT, because aom parallelises by tile — the same source
 * at the same quality encodes to 1350 bytes on one thread and 1823 on sixteen.
 * So output differs between any two machines with different core counts while
 * the libraries are identical, and small widths plateau (480px is fixed from
 * eight threads), which makes a naive comparison look controlled. Ask the
 * runtime what it loaded; never deduce it from what it produced.
 *
 * Everything here fails soft: a reporting surface that throws is worse than
 * one that says "I could not read this", because it takes the whole endpoint
 * down at exactly the moment someone is trying to diagnose a sick container.
 *
 * @phpstan-type BuildReport array{
 *     app: array{sha: string|null, built_at: string|null, flavor: string|null},
 *     php: string,
 *     node: string|null,
 *     sharp: array{version: string|null, libraries: array<string, string>}|null,
 *     sharp_error: string|null
 * }
 */
final class BuildInfoService
{
    /**
     * One node spawn is enough: these versions are immutable for the life of a
     * container.
     *
     * @var BuildReport|null
     */
    private ?array $cache = null;

    public function __construct(private readonly string $appRoot)
    {
    }

    /**
     * @return BuildReport
     */
    public function info(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $runtime = $this->runtime();

        return $this->cache = [
            'app' => $this->stamp(),
            'php' => PHP_VERSION,
            'node' => $runtime['node'],
            'sharp' => $runtime['sharp'],
            'sharp_error' => $runtime['error'],
        ];
    }

    /**
     * A one-line summary for doctor and logs.
     */
    public function summary(): string
    {
        $i = $this->info();
        $parts = ['PHP ' . $i['php']];
        if ($i['node'] !== null) {
            $parts[] = 'node ' . $i['node'];
        }
        if ($i['sharp'] !== null) {
            $libs = $i['sharp']['libraries'];
            $parts[] = 'sharp ' . ($i['sharp']['version'] ?? '?')
                . ' (libvips ' . ($libs['vips'] ?? '?')
                . ', aom ' . ($libs['aom'] ?? '?')
                . ', heif ' . ($libs['heif'] ?? '?')
                . ', rsvg ' . ($libs['rsvg'] ?? '?') . ')';
        }
        $stamp = $i['app'];
        if ($stamp['sha'] !== null && $stamp['sha'] !== '') {
            $parts[] = 'commit ' . substr($stamp['sha'], 0, 12);
        }
        if ($stamp['built_at'] !== null && $stamp['built_at'] !== '') {
            $parts[] = 'image built ' . $stamp['built_at'];
        }

        return implode(', ', $parts);
    }

    /**
     * The image stamp written at docker build time, so a container can say
     * WHEN it was built even when no commit sha was passed in. The timestamp
     * alone answers the question that actually came up: is this image newer
     * than the push I am waiting for, or did the old one survive?
     *
     * @return array{sha: string|null, built_at: string|null, flavor: string|null}
     */
    private function stamp(): array
    {
        $out = ['sha' => null, 'built_at' => null, 'flavor' => null];

        $file = $this->appRoot . '/var/build.json';
        if (is_file($file)) {
            $raw = @file_get_contents($file);
            if (\is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (\is_array($decoded)) {
                    foreach (['sha', 'built_at', 'flavor'] as $k) {
                        $v = $decoded[$k] ?? null;
                        if (\is_string($v) && $v !== '') {
                            $out[$k] = $v;
                        }
                    }
                }
            }
        }

        // Env wins only where the file said nothing, so a stamped image is
        // never overridden by a stale variable left in the environment.
        $out['sha'] ??= self::env('APP_BUILD_SHA');
        $out['built_at'] ??= self::env('APP_BUILT_AT');
        $out['flavor'] ??= self::env('FLAVOR');

        return $out;
    }

    private static function env(string $key): ?string
    {
        $v = $_ENV[$key] ?? getenv($key);

        return \is_string($v) && trim($v) !== '' ? trim($v) : null;
    }

    /**
     * ONE node spawn for the node version, sharp's version and every library
     * sharp links — which is the set an advisory is actually about (libvips,
     * libheif, librsvg, aom). sharp is required by ABSOLUTE PATH because
     * sharp 0.35 removed `./package.json` from its `exports`, so the obvious
     * `require('sharp/package.json')` throws ERR_PACKAGE_PATH_NOT_EXPORTED;
     * `.versions` carries its own version anyway.
     *
     * @return array{node: string|null, sharp: array{version: string|null, libraries: array<string, string>}|null, error: string|null}
     */
    private function runtime(): array
    {
        $modulePath = str_replace('\\', '/', $this->appRoot) . '/node_modules/sharp';
        $script = 'const out = { node: process.versions.node };'
            . ' try { out.sharp = require(' . json_encode($modulePath, JSON_THROW_ON_ERROR) . ').versions; }'
            . ' catch (e) { out.error = String((e && e.message) || e); }'
            . ' process.stdout.write(JSON.stringify(out));';

        try {
            $process = new Process(['node', '-e', $script], $this->appRoot, null, null, 20);
            $process->run();
        } catch (\Throwable $e) {
            return ['node' => null, 'sharp' => null, 'error' => 'could not run node: ' . $e->getMessage()];
        }

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput());

            return ['node' => null, 'sharp' => null, 'error' => $err === '' ? 'node exited non-zero' : $err];
        }

        $decoded = json_decode(trim($process->getOutput()), true);
        if (!\is_array($decoded)) {
            return ['node' => null, 'sharp' => null, 'error' => 'node returned no readable JSON'];
        }

        $node = \is_string($decoded['node'] ?? null) ? $decoded['node'] : null;
        $error = \is_string($decoded['error'] ?? null) ? $decoded['error'] : null;

        $sharp = null;
        if (\is_array($decoded['sharp'] ?? null)) {
            $libraries = [];
            $version = null;
            foreach ($decoded['sharp'] as $name => $value) {
                if (!\is_string($name) || !\is_scalar($value)) {
                    continue;
                }
                if ($name === 'sharp') {
                    $version = (string) $value;

                    continue;
                }
                $libraries[$name] = (string) $value;
            }
            ksort($libraries);
            $sharp = ['version' => $version, 'libraries' => $libraries];
        }

        return ['node' => $node, 'sharp' => $sharp, 'error' => $error];
    }
}
