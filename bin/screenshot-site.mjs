#!/usr/bin/env node
// Screenshot a built site's homepage into a card thumbnail (Playwright).
// Usage: node bin/screenshot-site.mjs <indexHtmlPath> <outPngPath>
// Best-effort: any failure exits non-zero and the caller simply skips the
// thumbnail. Works in the dev sandbox (bundled chromium via
// PLAYWRIGHT_BROWSERS_PATH) and the prod Alpine image (system chromium).
import { chromium } from 'playwright';
import sharp from 'sharp';
import { existsSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';

const [, , indexPath, outPath] = process.argv;
if (!indexPath || !outPath) {
  console.error('usage: screenshot-site.mjs <indexHtml> <outPng>');
  process.exit(2);
}
if (!existsSync(indexPath)) {
  console.error('index not found: ' + indexPath);
  process.exit(3);
}

const CANDIDATES = [
  process.env.CHROME_BIN,
  '/usr/bin/chromium-browser',
  '/usr/bin/chromium',
  '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
].filter(Boolean);

async function launch() {
  // Default first (dev sandbox resolves its own bundled chromium)…
  try {
    return await chromium.launch({ headless: true });
  } catch { /* fall through to explicit paths */ }
  for (const executablePath of CANDIDATES) {
    try {
      return await chromium.launch({ headless: true, executablePath });
    } catch { /* try next */ }
  }
  throw new Error('no usable chromium binary');
}

let browser;
try {
  browser = await launch();
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 750 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  await page.goto('file://' + indexPath, { waitUntil: 'load', timeout: 20000 }).catch(() => {});
  await page.waitForTimeout(400);
  const buf = await page.screenshot({ type: 'png', clip: { x: 0, y: 0, width: 1200, height: 750 } });
  mkdirSync(dirname(outPath), { recursive: true });
  await sharp(buf).resize(640, 400, { fit: 'cover', position: 'top' }).png({ compressionLevel: 9 }).toFile(outPath);
  console.log(JSON.stringify({ ok: true, out: outPath }));
} catch (e) {
  console.error('screenshot failed: ' + ((e && e.message) || e));
  process.exit(1);
} finally {
  if (browser) await browser.close().catch(() => {});
}
