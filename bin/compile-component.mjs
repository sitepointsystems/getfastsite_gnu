#!/usr/bin/env node
// Bundles a React component into a self-contained CommonJS module that
// exports a render(props) function. Invoked once per component at upload.
//
// argv[2] = path to a JSON job file:
//   { source: "<jsx/tsx source>", outFile: "<abs .cjs path>" }
//
// stdout (JSON): { ok: true, bundleHash } | { ok: false, error }
//
// The component's default export is a React component; the emitted bundle
// wraps it with react-dom/server.renderToStaticMarkup so the PHP side only
// calls render(props) and gets static HTML. No client runtime is emitted.

import esbuild from 'esbuild';
import { readFileSync, writeFileSync, mkdtempSync, rmSync } from 'node:fs';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { createHash } from 'node:crypto';

// The temp entry files live outside the project, so bare imports (react,
// react-dom/server) can't be resolved by walking up — point esbuild at the
// engine's node_modules explicitly. process.cwd() is the app root (the PHP
// side runs node with cwd = appRoot).
const NODE_PATHS = [join(process.cwd(), 'node_modules')];

async function run(job) {
  const tmp = mkdtempSync(join(tmpdir(), 'fscmp-'));
  try {
    const entry = join(tmp, 'entry.jsx');
    const componentFile = join(tmp, 'component.jsx');
    writeFileSync(componentFile, job.source, 'utf8');
    // Entry wraps the user component with the static renderer.
    writeFileSync(
      entry,
      [
        "import React from 'react';",
        "import { renderToStaticMarkup } from 'react-dom/server';",
        "import Component from './component.jsx';",
        'export function render(props) {',
        '  return renderToStaticMarkup(React.createElement(Component, props || {}));',
        '}',
      ].join('\n'),
      'utf8',
    );

    const result = await esbuild.build({
      entryPoints: [entry],
      bundle: true,
      format: 'cjs',
      platform: 'node',
      target: 'node18',
      jsx: 'automatic',
      minify: true,
      write: false,
      logLevel: 'silent',
      nodePaths: NODE_PATHS,
      absWorkingDir: process.cwd(),
      // React + react-dom/server are bundled IN so the .cjs is standalone.
      define: { 'process.env.NODE_ENV': '"production"' },
    });

    const code = result.outputFiles[0].text;
    writeFileSync(job.outFile, code, 'utf8');
    const bundleHash = createHash('sha256').update(code).digest('hex');

    return { ok: true, bundleHash };
  } finally {
    rmSync(tmp, { recursive: true, force: true });
  }
}

async function main() {
  const jobPath = process.argv[2];
  try {
    const job = JSON.parse(readFileSync(jobPath, 'utf8'));
    process.stdout.write(JSON.stringify(await run(job)));
  } catch (err) {
    process.stdout.write(JSON.stringify({ ok: false, error: String(err && err.message ? err.message : err) }));
    process.exit(1);
  }
}

main();
