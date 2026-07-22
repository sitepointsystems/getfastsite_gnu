<?php

declare(strict_types=1);

namespace Fastsite\Tests\Integration;

use Fastsite\Build\Service\ComponentRenderer;
use Fastsite\Component\Repository\DbalComponentRepository;
use Fastsite\Component\Service\ComponentService;
use Fastsite\Shared\Clock\SystemClock;
use Fastsite\Shared\Database\Migrator;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Components → static HTML. HTML shortcode substitution + React SSR (needs
 * node + the installed toolchain; those tests skip when node is absent).
 */
final class ComponentTest extends TestCase
{
    private string $dataPath;
    private string $appRoot;
    private SiteDatabaseManager $databases;
    private ComponentService $service;
    private DbalComponentRepository $repository;
    private ComponentRenderer $renderer;
    private Site $site;

    protected function setUp(): void
    {
        $this->appRoot = \dirname(__DIR__, 2);
        $this->dataPath = sys_get_temp_dir() . '/fastsite-cmp-' . bin2hex(random_bytes(6));
        $clock = new SystemClock();
        $this->databases = new SiteDatabaseManager($this->dataPath);
        $migrator = new Migrator($clock);
        $migrator->migrate($this->databases->engine(), $this->appRoot . '/migrations/engine');
        $this->databases->provisionSite('cmptest');
        $migrator->migrate($this->databases->site('cmptest'), $this->appRoot . '/migrations/site');

        $this->repository = new DbalComponentRepository($this->databases, $clock);
        $this->service = new ComponentService($this->repository, $this->databases, new NullLogger(), $this->appRoot);
        $this->renderer = new ComponentRenderer($this->repository, $this->databases, new NullLogger(), $this->appRoot);
        $this->site = new Site(1, 'cmptest', 'Cmp Test', '', 'https://c.example.com', 'en', ['da'], '/blog/{slug}/', '/{slug}/', '', 'main', '', 1, 'active');
    }

    protected function tearDown(): void
    {
        $this->databases->closeAll();
        if (is_dir($this->dataPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dataPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $item) {
                \assert($item instanceof \SplFileInfo);
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->dataPath);
        }
    }

    private function nodeAvailable(): bool
    {
        return is_file($this->appRoot . '/node_modules/esbuild/package.json')
            && is_file($this->appRoot . '/node_modules/react-dom/server.js');
    }

    public function testHtmlComponentSubstitutesShortcodes(): void
    {
        $this->service->upsert($this->site, 'banner', [
            'type' => 'html',
            'source_code' => '<div class="banner"><h2>{{title}}</h2><p>{{{body}}}</p></div>',
        ]);

        $body = '<p>before</p><div data-fs-component="banner" data-values="' . htmlspecialchars('{"title":"Hi <b>x</b>","body":"<em>raw ok</em>"}', ENT_QUOTES) . '"></div><p>after</p>';
        $result = $this->renderer->expand('cmptest', $body, 'en');

        // {{title}} is escaped, {{{body}}} is raw.
        self::assertStringContainsString('Hi &lt;b&gt;x&lt;/b&gt;', $result['html']);
        self::assertStringContainsString('<em>raw ok</em>', $result['html']);
        self::assertStringNotContainsString('data-fs-component', $result['html']);
    }

    public function testForbiddenImportRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->upsert($this->site, 'evil', [
            'type' => 'react',
            'source_code' => "import fs from 'fs'; export default function E(){ return null; }",
        ]);
    }

    public function testNextImportRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->upsert($this->site, 'nextcmp', [
            'type' => 'react',
            'source_code' => "import Image from 'next/image'; export default function N(){ return null; }",
        ]);
    }

    public function testClientHooksWarn(): void
    {
        if (!$this->nodeAvailable()) {
            self::markTestSkipped('node/esbuild not installed.');
        }
        $c = $this->service->upsert($this->site, 'counter', [
            'type' => 'react',
            'source_code' => "import {useState} from 'react'; export default function C(){ const [n]=useState(0); return <span>{n}</span>; }",
        ]);

        self::assertSame('ready', $c->status);
        self::assertNotSame([], $c->warnings);
        self::assertStringContainsString('useState', implode(' ', $c->warnings));
    }

    public function testReactComponentRendersStaticInTwoLocales(): void
    {
        if (!$this->nodeAvailable()) {
            self::markTestSkipped('node/esbuild not installed.');
        }
        $c = $this->service->upsert($this->site, 'greet', [
            'type' => 'react',
            'source_code' => "export default function G({name='world'}){ return <p className=\"greet\">Hello {name}</p>; }",
        ]);
        self::assertSame('ready', $c->status);

        $body = '<div data-fs-component="greet" data-values="' . htmlspecialchars('{"name":"fastsite"}', ENT_QUOTES) . '"></div>';

        $en = $this->renderer->expand('cmptest', $body, 'en');
        self::assertStringContainsString('<p class="greet">Hello fastsite</p>', $en['html']);
        self::assertStringNotContainsString('data-fs-component', $en['html']);
        // Zero client runtime.
        self::assertStringNotContainsString('<script', $en['html']);

        // Second locale: renders too, under its own cache key.
        $da = $this->renderer->expand('cmptest', $body, 'da');
        self::assertStringContainsString('<p class="greet">Hello fastsite</p>', $da['html']);

        // Two distinct cache entries (one per locale).
        $count = (int) $this->databases->site('cmptest')->fetchOne('SELECT COUNT(*) FROM component_renders');
        self::assertSame(2, $count);
    }

    public function testRenderCacheAvoidsRecompute(): void
    {
        $this->service->upsert($this->site, 'static', ['type' => 'html', 'source_code' => '<b>fixed</b>']);
        $body = '<div data-fs-component="static"></div>';

        $this->renderer->expand('cmptest', $body, 'en');
        $this->renderer->expand('cmptest', $body, 'en');

        // Same (component, values, locale) → one cache row, reused.
        self::assertSame(1, (int) $this->databases->site('cmptest')->fetchOne('SELECT COUNT(*) FROM component_renders'));
    }

    public function testUnknownComponentDroppedWithWarning(): void
    {
        $result = $this->renderer->expand('cmptest', '<div data-fs-component="ghost"></div>', 'en');

        self::assertStringNotContainsString('data-fs-component', $result['html']);
        self::assertNotSame([], $result['warnings']);
    }

    /**
     * @return list<array{string}>
     */
    public static function dangerousSources(): array
    {
        return [
            ["export default function E(){ eval('1'); return null; }"],
            ["export default function E(){ new Function('return 1')(); return null; }"],
            ["const m = 'fs'; export default function E(){ import(m); return null; }"],
            ["export default function E(){ const r = require; r('child_process'); return null; }"],
            ["export default function E(){ return globalThis.process.env; }"],
            ["export default function E(){ return ({}).__proto__; }"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('dangerousSources')]
    public function testForbiddenPrimitivesRejected(string $source): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->upsert($this->site, 'danger', ['type' => 'react', 'source_code' => $source]);
    }

    public function testComponentScriptOutputIsStripped(): void
    {
        // An html component that emits a <script> must never ship it (zero-JS
        // invariant) and must not wedge the build — it's stripped with a WARN.
        $this->service->upsert($this->site, 'sneaky', [
            'type' => 'html',
            'source_code' => '<div>hi</div><script>alert(1)</script><b onclick="x()">c</b>',
        ]);

        $result = $this->renderer->expand('cmptest', '<div data-fs-component="sneaky"></div>', 'en');

        self::assertStringContainsString('<div>hi</div>', $result['html']);
        self::assertStringNotContainsString('<script', $result['html']);
        self::assertStringNotContainsString('onclick', $result['html']);
        self::assertNotSame([], $result['warnings']);
    }
}
