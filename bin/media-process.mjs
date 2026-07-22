#!/usr/bin/env node
// Media processing worker. Reads ONE job as JSON on argv[2] (a file path),
// writes the result manifest as JSON to stdout. Invoked per media item by
// the PHP MediaService via symfony/process.
//
// Job shape:
//   { original: "<abs path>", kind: "image", outDir: "<abs variants dir>",
//     slug: "hero", widths: [480,768,1024,1440,1920], og: true }
//
// Result shape:
//   { ok: true, width, height, variants: [{purpose,format,width,height,path,bytes}] }
//   { ok: false, error: "..." }

import sharp from 'sharp';
import { readFileSync, mkdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const RESPONSIVE_FORMATS = ['avif', 'webp'];
// Hard pixel ceiling well below sharp's ~268 MP default — a tiny highly
// compressible file (pixel bomb) must not blow up into ~13 heavy re-encodes.
const MAX_PIXELS = 40_000_000; // 40 MP (e.g. 7746x5164)

async function run(job) {
  mkdirSync(job.outDir, { recursive: true });

  const input = sharp(job.original, { failOn: 'error', limitInputPixels: MAX_PIXELS });
  const meta = await input.metadata();
  const srcWidth = meta.width ?? 0;
  const srcHeight = meta.height ?? 0;
  if (!srcWidth || !srcHeight) {
    throw new Error('could not read image dimensions');
  }
  if (srcWidth * srcHeight > MAX_PIXELS) {
    throw new Error(`image is ${srcWidth}x${srcHeight} (${Math.round((srcWidth * srcHeight) / 1e6)} MP) — over the ${MAX_PIXELS / 1e6} MP limit`);
  }

  const variants = [];
  // The largest asset we ship: the source width, but capped at the site's
  // configured maxWidth so a 4000px upload doesn't ship a 4000px file.
  const cap = Number.isFinite(job.maxWidth) && job.maxWidth > 0 ? job.maxWidth : srcWidth;
  const effectiveSrc = Math.min(srcWidth, cap);
  // Widths we actually generate: requested widths under the effective source
  // width, plus the effective source width itself (always a 1x asset).
  const widths = [...new Set(job.widths.filter((w) => w < effectiveSrc).concat(effectiveSrc))].sort(
    (a, b) => a - b,
  );

  for (const width of widths) {
    const height = Math.round((srcHeight / srcWidth) * width);
    // Re-encode strips EXIF/embedded payloads (rotate() bakes orientation).
    const base = sharp(job.original, { limitInputPixels: MAX_PIXELS }).rotate().resize({ width, withoutEnlargement: true });

    for (const format of RESPONSIVE_FORMATS) {
      const path = join(job.outDir, `${job.slug}-${width}.${format}`);
      await base.clone().toFormat(format, { quality: format === 'avif' ? 50 : 78 }).toFile(path);
      variants.push({ purpose: 'responsive', format, width, height, path, bytes: statSync(path).size });
    }

    // Original-format fallback at the largest generated width only.
    if (width === widths[widths.length - 1]) {
      const fallbackFormat = meta.format === 'png' ? 'png' : 'jpg';
      const path = join(job.outDir, `${job.slug}-${width}.${fallbackFormat}`);
      const encoder = fallbackFormat === 'png'
        ? base.clone().png({ compressionLevel: 9 })
        : base.clone().jpeg({ quality: 82, mozjpeg: true });
      await encoder.toFile(path);
      variants.push({ purpose: 'responsive', format: fallbackFormat, width, height, path, bytes: statSync(path).size });
    }
  }

  // Open-graph card: 1200x630 cover crop, jpg.
  if (job.og) {
    const path = join(job.outDir, `${job.slug}-og.jpg`);
    await sharp(job.original, { limitInputPixels: MAX_PIXELS }).rotate().resize({ width: 1200, height: 630, fit: 'cover' })
      .jpeg({ quality: 82, mozjpeg: true }).toFile(path);
    variants.push({ purpose: 'og', format: 'jpg', width: 1200, height: 630, path, bytes: statSync(path).size });
  }

  return { ok: true, width: srcWidth, height: srcHeight, variants };
}

async function main() {
  const jobPath = process.argv[2];
  if (!jobPath) {
    process.stdout.write(JSON.stringify({ ok: false, error: 'no job file given' }));
    process.exit(1);
  }
  try {
    const job = JSON.parse(readFileSync(jobPath, 'utf8'));
    const result = await run(job);
    process.stdout.write(JSON.stringify(result));
  } catch (err) {
    process.stdout.write(JSON.stringify({ ok: false, error: String(err && err.message ? err.message : err) }));
    process.exit(1);
  }
}

main();
