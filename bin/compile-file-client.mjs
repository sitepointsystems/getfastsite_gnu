#!/usr/bin/env node
// Bundles a jsx/tsx/ts React component from the files library into a
// self-contained BROWSER IIFE that mounts the default export into the element
// immediately preceding its own <script> tag. Used at build for a
// `{{ widget.jsx }}` embed — ships INTERACTIVE client JS (the files library is
// owner-curated/trusted). Self-contained React components only; Next.js
// runtime/routing imports (next/*) are not resolvable and fail to bundle
// (the PHP side then falls back to a download link).
//
// argv[2] = JSON job file: { source: "<jsx/tsx source>" }
// stdout (JSON): { ok: true, code, bundleHash } | { ok: false, error }
//
// Mirrors bin/compile-component.mjs (the SSR compiler) but targets the browser
// with react-dom/client instead of react-dom/server.

import esbuild from 'esbuild';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { createHash } from 'node:crypto';

// Temp entry files live outside the project, so bare imports (react,
// react-dom/client) can't resolve by walking up — point esbuild at the
// engine's node_modules explicitly (cwd is the app root, as for the SSR one).
const NODE_PATHS = [join(process.cwd(), 'node_modules')];

async function run(job) {
  const tmp = mkdtempSync(join(tmpdir(), 'fsfile-'));
  try {
    const componentFile = join(tmp, 'component.jsx');
    const entry = join(tmp, 'entry.jsx');
    writeFileSync(componentFile, job.source, 'utf8');
    // Entry mounts the component into the element just before this script,
    // so the embed needs no baked-in id (document.currentScript resolves it).
    writeFileSync(
      entry,
      [
        "import React from 'react';",
        "import { createRoot } from 'react-dom/client';",
        "import Component from './component.jsx';",
        '(function () {',
        '  var s = document.currentScript;',
        '  var el = s && s.previousElementSibling;',
        '  if (!el) return;',
        '  try { createRoot(el).render(React.createElement(Component)); }',
        "  catch (e) { el.textContent = ''; }",
        '})();',
      ].join('\n'),
      'utf8',
    );

    const result = await esbuild.build({
      entryPoints: [entry],
      bundle: true,
      format: 'iife',
      platform: 'browser',
      target: 'es2020',
      jsx: 'automatic',
      minify: true,
      write: false,
      logLevel: 'silent',
      nodePaths: NODE_PATHS,
      absWorkingDir: process.cwd(),
      define: { 'process.env.NODE_ENV': '"production"' },
    });

    const code = result.outputFiles[0].text;

    return { ok: true, code, bundleHash: createHash('sha256').update(code).digest('hex') };
  } finally {
    rmSync(tmp, { recursive: true, force: true });
  }
}

async function main() {
  try {
    const job = JSON.parse(readFileSync(process.argv[2], 'utf8'));
    process.stdout.write(JSON.stringify(await run(job)));
  } catch (err) {
    process.stdout.write(JSON.stringify({ ok: false, error: String(err && err.message ? err.message : err) }));
    process.exit(1);
  }
}

main();
