#!/usr/bin/env node
// Visual comparison engine (Playwright + pixelmatch) — a shared tool for
// building look-alikes and scoring 1:1 clones.
//
//   Capture a source (to design a look-alike from):
//     node bin/visual-compare.mjs capture <url> [--out=dir]
//       → full-page screenshots (desktop + mobile), rendered HTML, visible
//         text, and the link list. Read these to reproduce the design.
//
//   Compare old vs new (to score how far off a 1:1 clone is):
//     node bin/visual-compare.mjs compare <urlOld> <urlNew> [--out=dir]
//       → matched-viewport screenshots, a pixel-diff image + % mismatch, and a
//         visible-text similarity score. Lower mismatch = closer.
//
// Screenshots render in a real Chromium with a desktop UA, so Cloudflare-
// fronted origins that block plain fetches still capture.

import { chromium } from 'playwright';
import sharp from 'sharp';
import pixelmatch from 'pixelmatch';
import { mkdirSync, writeFileSync, existsSync } from 'node:fs';
import { join } from 'node:path';

const CHROME_FALLBACK = '/opt/pw-browsers/chromium-1194/chrome-linux/chrome';
const UA =
  'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
const VIEWPORTS = {
  desktop: { width: 1280, height: 800 },
  mobile: { width: 390, height: 844 },
};

function parseArgs(argv) {
  const positional = [];
  const opts = {};
  for (const a of argv) {
    if (a.startsWith('--')) {
      const [k, v] = a.slice(2).split('=');
      opts[k] = v ?? true;
    } else {
      positional.push(a);
    }
  }
  return { positional, opts };
}

async function launch() {
  try {
    return await chromium.launch({ headless: true });
  } catch {
    return await chromium.launch({ headless: true, executablePath: CHROME_FALLBACK });
  }
}

function slug(url) {
  return url.replace(/^https?:\/\//, '').replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '').slice(0, 60) || 'page';
}

async function capturePage(browser, url, outDir, label) {
  const result = { url, label, viewports: {}, title: '', text: '', links: [] };
  for (const [vp, size] of Object.entries(VIEWPORTS)) {
    const context = await browser.newContext({ viewport: size, userAgent: UA, deviceScaleFactor: 1 });
    const page = await context.newPage();
    try {
      await page.goto(url, { waitUntil: 'networkidle', timeout: 45000 });
    } catch {
      // networkidle can hang on long-polling sites — settle for DOM + a beat.
      await page.waitForTimeout(2500);
    }
    await page.waitForTimeout(600);
    const pngPath = join(outDir, `${label}-${vp}.png`);
    await page.screenshot({ path: pngPath, fullPage: true });
    result.viewports[vp] = { png: pngPath, ...size };
    if (vp === 'desktop') {
      result.title = await page.title();
      result.text = (await page.evaluate(() => document.body?.innerText || '')).replace(/\s+/g, ' ').trim();
      result.links = await page.evaluate(() =>
        Array.from(document.querySelectorAll('a[href]')).map((a) => a.getAttribute('href')).filter(Boolean),
      );
      writeFileSync(join(outDir, `${label}.html`), await page.content(), 'utf8');
    }
    await context.close();
  }
  writeFileSync(join(outDir, `${label}.json`), JSON.stringify(result, null, 2), 'utf8');
  return result;
}

// Decodes a PNG to RGBA, cropped to the top-left w x h.
function rgba(path, w, h) {
  return sharp(path).extract({ left: 0, top: 0, width: w, height: h }).ensureAlpha().raw().toBuffer();
}

// Pixel mismatch % over the overlapping region of two full-page shots.
async function pixelDiff(pathA, pathB, diffPath) {
  const [a, b] = await Promise.all([sharp(pathA).metadata(), sharp(pathB).metadata()]);
  const w = Math.min(a.width, b.width);
  const h = Math.min(a.height, b.height);
  const [ca, cb] = await Promise.all([rgba(pathA, w, h), rgba(pathB, w, h)]);
  const diff = Buffer.alloc(w * h * 4);
  const mismatched = pixelmatch(ca, cb, diff, w, h, { threshold: 0.1 });
  await sharp(diff, { raw: { width: w, height: h, channels: 4 } }).png().toFile(diffPath);
  return {
    mismatch_pct: +((mismatched / (w * h)) * 100).toFixed(2),
    compared: `${w}x${h}`,
    height_delta: Math.abs(a.height - b.height),
    diff_png: diffPath,
  };
}

// Jaccard similarity over word sets — a coarse content-parity signal.
function textSimilarity(a, b) {
  const set = (s) => new Set(s.toLowerCase().match(/[a-z0-9]+/g) || []);
  const sa = set(a);
  const sb = set(b);
  if (sa.size === 0 && sb.size === 0) return 1;
  let inter = 0;
  for (const w of sa) if (sb.has(w)) inter++;
  return +((inter / (sa.size + sb.size - inter)) * 100).toFixed(1);
}

async function main() {
  const { positional, opts } = parseArgs(process.argv.slice(2));
  const mode = positional[0];
  const outDir = opts.out || join(process.cwd(), 'var', 'visual-compare');
  mkdirSync(outDir, { recursive: true });
  const browser = await launch();

  try {
    if (mode === 'capture') {
      const url = positional[1];
      if (!url) throw new Error('usage: capture <url> [--out=dir]');
      const r = await capturePage(browser, url, outDir, slug(url));
      console.log(JSON.stringify({ ok: true, mode, out: outDir, title: r.title, links: r.links.length, text_chars: r.text.length, screenshots: Object.fromEntries(Object.entries(r.viewports).map(([k, v]) => [k, v.png])) }, null, 2));
    } else if (mode === 'compare') {
      const [urlA, urlB] = [positional[1], positional[2]];
      if (!urlA || !urlB) throw new Error('usage: compare <urlOld> <urlNew> [--out=dir]');
      const ra = await capturePage(browser, urlA, outDir, 'old');
      const rb = await capturePage(browser, urlB, outDir, 'new');
      const diffs = {};
      for (const vp of Object.keys(VIEWPORTS)) {
        diffs[vp] = await pixelDiff(ra.viewports[vp].png, rb.viewports[vp].png, join(outDir, `diff-${vp}.png`));
      }
      const report = {
        ok: true,
        mode,
        out: outDir,
        old: urlA,
        new: urlB,
        text_similarity_pct: textSimilarity(ra.text, rb.text),
        pixel: diffs,
      };
      writeFileSync(join(outDir, 'report.json'), JSON.stringify(report, null, 2), 'utf8');
      console.log(JSON.stringify(report, null, 2));
    } else {
      throw new Error('usage: visual-compare <capture|compare> …');
    }
  } finally {
    await browser.close();
  }
}

main().catch((err) => {
  console.error(JSON.stringify({ ok: false, error: String(err && err.message ? err.message : err) }));
  process.exit(1);
});
