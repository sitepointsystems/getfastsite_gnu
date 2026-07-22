<?php

declare(strict_types=1);

namespace Fastsite\Component\Service;

use Fastsite\Component\Dto\Component;
use Fastsite\Component\Repository\ComponentRepository;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Component intake. Two types:
 *  - html:  a fragment with {{var}} (escaped) / {{{var}}} (raw) shortcodes,
 *           substituted at render time. No compile.
 *  - react: a React component (default export) bundled at upload by esbuild
 *           into a standalone .cjs (react + react-dom/server included) that
 *           exports render(props). SSR'd to static HTML at build time.
 *
 * Uploads are linted: client-only React hooks/handlers WARN (they no-op in
 * static output); Next.js and Node built-in imports are REJECTED (Next has
 * no static story here; a Node import would be an escape from the render
 * sandbox).
 */
final class ComponentService
{
    private const HANDLE_PATTERN = '/^[a-z0-9][a-z0-9-]{1,62}$/';
    private const MAX_SOURCE_BYTES = 512 * 1024;

    /** Imports that must be rejected outright. */
    private const FORBIDDEN_IMPORTS = [
        'next/', 'fs', 'node:fs', 'child_process', 'node:child_process',
        'net', 'node:net', 'http', 'node:http', 'https', 'node:https',
        'os', 'node:os', 'process', 'node:process', 'vm', 'node:vm', 'worker_threads',
    ];

    public function __construct(
        private readonly ComponentRepository $repository,
        private readonly SiteDatabaseManager $databases,
        private readonly LoggerInterface $logger,
        private readonly string $appRoot,
    ) {
    }

    /**
     * Creates or updates a component. React components are compiled here so
     * a compile error surfaces at upload, not at build.
     *
     * @param array<string, mixed> $input {type, source_code, css?, variables_schema?}
     */
    public function upsert(Site $site, string $handle, array $input): Component
    {
        $handle = strtolower(trim($handle));
        if (preg_match(self::HANDLE_PATTERN, $handle) !== 1) {
            throw new \InvalidArgumentException('Invalid component handle — lowercase letters, digits and dashes only.');
        }

        $type = (string) ($input['type'] ?? '');
        if (!\in_array($type, ['html', 'react'], true)) {
            throw new \InvalidArgumentException('Component type must be "html" or "react".');
        }

        $source = (string) ($input['source_code'] ?? '');
        if ($source === '' || \strlen($source) > self::MAX_SOURCE_BYTES) {
            throw new \InvalidArgumentException('Component source is empty or exceeds 512 KB.');
        }

        $css = (string) ($input['css'] ?? '');
        $variablesSchema = $input['variables_schema'] ?? [];
        if (!\is_array($variablesSchema)) {
            $variablesSchema = [];
        }

        $warnings = $this->lint($type, $source);
        $sourceHash = hash('sha256', $type . "\x1f" . $source);

        $id = $this->repository->upsert($site->handle, $handle, [
            'type' => $type,
            'source_code' => $source,
            'css' => $css,
            'variables_schema' => json_encode($variablesSchema, JSON_THROW_ON_ERROR),
            'source_hash' => $sourceHash,
            'warnings' => json_encode(array_values($warnings), JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'error' => null,
        ]);

        if ($type === 'html') {
            $this->repository->upsert($site->handle, $handle, ['bundle_hash' => $sourceHash, 'status' => 'ready']);
        } else {
            $this->compileReact($site, $id, $handle, $source);
        }

        $component = $this->repository->findByHandle($site->handle, $handle);
        \assert($component !== null);

        return $component;
    }

    /**
     * Every component for a site (dashboard listing).
     *
     * @return list<Component>
     */
    public function list(Site $site): array
    {
        return $this->repository->all($site->handle);
    }

    /**
     * Removes a component, its cached renders, and any compiled react bundle.
     */
    public function delete(Site $site, string $handle): void
    {
        $handle = strtolower(trim($handle));
        $this->repository->delete($site->handle, $handle);

        $bundle = $this->databases->sitePath($site->handle) . '/components/' . $handle . '.cjs';
        if (is_file($bundle)) {
            @unlink($bundle);
        }
    }

    /**
     * @return list<string>
     */
    private function lint(string $type, string $source): array
    {
        $warnings = [];

        // Reject dangerous imports (both types — html could embed a script,
        // but the sanitizer strips those; react is bundled so imports matter).
        if (preg_match_all('/(?:import\s[^;]*from\s*|require\s*\(\s*)["\']([^"\']+)["\']/', $source, $m) > 0) {
            foreach ($m[1] as $import) {
                foreach (self::FORBIDDEN_IMPORTS as $forbidden) {
                    if ($import === $forbidden || ($forbidden[-1] === '/' && str_starts_with($import, $forbidden))) {
                        throw new \InvalidArgumentException(sprintf('Import "%s" is not allowed in components.', $import));
                    }
                }
            }
        }

        // A static import allowlist is bypassable by computed/dynamic access
        // (require('f'+'s'), import(x), eval, Function, globalThis.process).
        // A component renders trusted JS on the BUILD HOST, so reject these
        // escape primitives outright — components have no legitimate need for
        // dynamic module loading or raw eval.
        foreach ([
            '/\beval\s*\(/i' => 'eval()',
            '/\bnew\s+Function\s*\(/i' => 'new Function()',
            '/\bimport\s*\(/i' => 'dynamic import()',
            '/\brequire\s*\(\s*[^"\')]/i' => 'computed require()',
            '/\bprocess\b/i' => 'process access',
            '/\bglobalThis\b/i' => 'globalThis access',
            '/\bchild_process\b/i' => 'child_process',
            '/\b__proto__\b/' => '__proto__ gadget',
            '/\bconstructor\s*\[/i' => 'constructor gadget',
        ] as $pattern => $label) {
            if (preg_match($pattern, $source) === 1) {
                throw new \InvalidArgumentException(sprintf('Component uses a forbidden primitive (%s) — not allowed for security reasons.', $label));
            }
        }

        if ($type === 'react') {
            foreach (['useState', 'useEffect', 'useReducer', 'useRef', 'onClick', 'onChange', 'onSubmit'] as $clientApi) {
                if (preg_match('/\b' . $clientApi . '\b/', $source) === 1) {
                    $warnings[] = sprintf('%s will no-op in static output (components render server-side, no client JS is shipped).', $clientApi);
                }
            }
        }

        return $warnings;
    }

    private function compileReact(Site $site, int $id, string $handle, string $source): void
    {
        $componentsDir = $this->databases->sitePath($site->handle) . '/components';
        if (!is_dir($componentsDir) && !mkdir($componentsDir, 0770, true) && !is_dir($componentsDir)) {
            throw new \RuntimeException('Could not create components directory.');
        }
        $outFile = $componentsDir . '/' . $handle . '.cjs';

        $jobFile = tempnam(sys_get_temp_dir(), 'fscompile');
        file_put_contents($jobFile, json_encode(['source' => $source, 'outFile' => $outFile], JSON_THROW_ON_ERROR));

        try {
            $process = new Process(['node', $this->appRoot . '/bin/compile-component.mjs', $jobFile], $this->appRoot, null, null, 60);
            $process->run();
            $result = json_decode($process->getOutput(), true);

            if (!\is_array($result) || ($result['ok'] ?? false) !== true) {
                $error = \is_array($result) ? (string) ($result['error'] ?? 'unknown') : trim($process->getErrorOutput());
                $this->repository->upsert($site->handle, $handle, ['status' => 'failed', 'error' => 'Compile failed: ' . $error]);
                throw new \InvalidArgumentException('Component compile failed: ' . $error);
            }

            $this->repository->upsert($site->handle, $handle, [
                'bundle_hash' => (string) $result['bundleHash'],
                'status' => 'ready',
                'error' => null,
            ]);
        } finally {
            @unlink($jobFile);
        }
    }
}
