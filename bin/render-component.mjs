#!/usr/bin/env node
// Renders compiled component bundles to static HTML. Invoked ONCE per build
// with a batch of render requests, so Node starts up once per build rather
// than once per component.
//
// argv[2] = path to a JSON job file:
//   { renders: [ { id, bundlePath, props }, ... ] }
//
// stdout (JSON):
//   { results: [ { id, html } | { id, error }, ... ] }
//
// Bundles are required once and cached across the batch. renderToStaticMarkup
// is synchronous and emits NO client runtime. A per-render try/catch keeps one
// bad component from failing the whole batch (that component is dropped with a
// warning on the PHP side). The PHP caller runs this under a wall-clock
// timeout so a runaway render cannot hang the build worker indefinitely.

import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { pathToFileURL } from 'node:url';

const require = createRequire(pathToFileURL(process.cwd() + '/'));

function main() {
  const jobPath = process.argv[2];
  let job;
  try {
    job = JSON.parse(readFileSync(jobPath, 'utf8'));
  } catch (err) {
    process.stdout.write(JSON.stringify({ results: [], fatal: String(err) }));
    process.exit(1);
    return;
  }

  const cache = new Map();
  const results = [];

  for (const req of job.renders || []) {
    try {
      let mod = cache.get(req.bundlePath);
      if (!mod) {
        // Fresh require of the standalone bundle. delete cache first so a
        // rebuilt bundle at the same path is picked up.
        delete require.cache[require.resolve(req.bundlePath)];
        mod = require(req.bundlePath);
        cache.set(req.bundlePath, mod);
      }
      if (typeof mod.render !== 'function') {
        throw new Error('bundle does not export render()');
      }
      const html = mod.render(req.props || {});
      results.push({ id: req.id, html: String(html) });
    } catch (err) {
      results.push({ id: req.id, error: String(err && err.message ? err.message : err) });
    }
  }

  process.stdout.write(JSON.stringify({ results }));
}

main();
