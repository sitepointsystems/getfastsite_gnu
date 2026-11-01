#!/usr/bin/env node
// Media processing worker. Reads ONE job as JSON on argv[2] (a file path),
// writes the result manifest as JSON to stdout. Invoked per media item by
// the PHP MediaService via symfony/process.
//
// Job shape:
//   { original: "<abs path>", kind: "image", outDir: "<abs variants dir>",
//     slug: "hero", widths: [480,768,1024,1440,1920], og: true,
//     transform: { crop: {x,y,w,h} | null, width: 1200 | null } | null }
//
// `transform` is the NON-DESTRUCTIVE crop/resize: the original on disk is
// never modified, so the rect is re-applied on every rebuild and stays
// re-drawable. crop is in FRACTIONS of the post-rotation source (0..1) —
// fractions survive any source, and only this file knows the real geometry.
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
  const storedWidth = meta.width ?? 0;
  const storedHeight = meta.height ?? 0;
  if (!storedWidth || !storedHeight) {
    throw new Error('could not read image dimensions');
  }
  if (storedWidth * storedHeight > MAX_PIXELS) {
    throw new Error(`image is ${storedWidth}x${storedHeight} (${Math.round((storedWidth * storedHeight) / 1e6)} MP) — over the ${MAX_PIXELS / 1e6} MP limit`);
  }

  // Everything below works in POST-ROTATION geometry, because every pipeline
  // here starts with .rotate() and a crop rect drawn in a browser is in the
  // orientation the viewer saw. EXIF orientations 5-8 carry a 90° turn, which
  // swaps the axes.
  const turned = (meta.orientation ?? 1) >= 5;
  const srcWidth = turned ? storedHeight : storedWidth;
  const srcHeight = turned ? storedWidth : storedHeight;

  // Non-destructive crop + resize. Clamped here rather than trusted: the
  // fractions arrive from a drag in a browser, and an off-by-one that asks
  // sharp for a pixel outside the image is a hard failure, not a soft one.
  const t = job.transform ?? null;
  let extract = null;
  let effWidth = srcWidth;
  let effHeight = srcHeight;
  if (t && t.crop) {
    const left = Math.min(Math.max(0, Math.round((t.crop.x ?? 0) * srcWidth)), srcWidth - 1);
    const top = Math.min(Math.max(0, Math.round((t.crop.y ?? 0) * srcHeight)), srcHeight - 1);
    const width = Math.max(1, Math.min(Math.round((t.crop.w ?? 1) * srcWidth), srcWidth - left));
    const height = Math.max(1, Math.min(Math.round((t.crop.h ?? 1) * srcHeight), srcHeight - top));
    extract = { left, top, width, height };
    effWidth = width;
    effHeight = height;
  }
  if (t && Number.isFinite(t.width) && t.width > 0 && t.width < effWidth) {
    effHeight = Math.max(1, Math.round((effHeight / effWidth) * t.width));
    effWidth = t.width;
  }

  /**
   * Rotated and cropped — ONE factory for every rendition, so a crop can
   * never apply to the responsive widths but get forgotten on the OG card
   * (which would ship a share image framed differently from the picture on
   * the page).
   *
   * The resize is deliberately NOT applied here. Every caller resizes anyway,
   * and a second .resize() on the same pipeline overwrites the first rather
   * than composing — so doing it twice would work only by sharp's last-one-
   * wins behaviour. The requested width is already honoured through effWidth,
   * which caps the whole width list below.
   */
  const source = () => {
    const s = sharp(job.original, { limitInputPixels: MAX_PIXELS }).rotate();
    return extract ? s.extract(extract) : s;
  };

  const variants = [];
  // Encoder qualities: per-site compression presets from the PHP side, with
  // the historical defaults when a job carries none.
  const quality = {
    avif: Number.isFinite(job.quality?.avif) ? job.quality.avif : 50,
    webp: Number.isFinite(job.quality?.webp) ? job.quality.webp : 78,
    jpeg: Number.isFinite(job.quality?.jpeg) ? job.quality.jpeg : 82,
  };
  // The largest asset we ship: the source width, but capped at the site's
  // configured maxWidth so a 4000px upload doesn't ship a 4000px file.
  const cap = Number.isFinite(job.maxWidth) && job.maxWidth > 0 ? job.maxWidth : effWidth;
  const effectiveSrc = Math.min(effWidth, cap);
  // Widths we actually generate: requested widths under the effective source
  // width, plus the effective source width itself (always a 1x asset).
  const widths = [...new Set(job.widths.filter((w) => w < effectiveSrc).concat(effectiveSrc))].sort(
    (a, b) => a - b,
  );

  for (const width of widths) {
    const height = Math.round((effHeight / effWidth) * width);
    // Re-encode strips EXIF/embedded payloads (rotate() bakes orientation).
    const base = source().resize({ width, withoutEnlargement: true });

    for (const format of RESPONSIVE_FORMATS) {
      const path = join(job.outDir, `${job.slug}-${width}.${format}`);
      await base.clone().toFormat(format, { quality: format === 'avif' ? quality.avif : quality.webp }).toFile(path);
      variants.push({ purpose: 'responsive', format, width, height, path, bytes: statSync(path).size });
    }

    // Fallback at the largest generated width only. PNG is kept ONLY when the
    // image actually uses transparency — a photo uploaded as PNG re-encoded to
    // full-width PNG is easily >1 MB, and that fallback is exactly what naive
    // audit tools fetch from <img src>. Everything opaque falls back to jpg.
    if (width === widths[widths.length - 1]) {
      const fallbackFormat = meta.format === 'png' && meta.hasAlpha ? 'png' : 'jpg';
      const path = join(job.outDir, `${job.slug}-${width}.${fallbackFormat}`);
      const encoder = fallbackFormat === 'png'
        ? base.clone().png({ compressionLevel: 9 })
        : base.clone().flatten({ background: '#ffffff' }).jpeg({ quality: quality.jpeg, mozjpeg: true });
      await encoder.toFile(path);
      variants.push({ purpose: 'responsive', format: fallbackFormat, width, height, path, bytes: statSync(path).size });
    }
  }

  // Open-graph card: 1200x630 cover crop, jpg.
  if (job.og) {
    const path = join(job.outDir, `${job.slug}-og.jpg`);
    await source().resize({ width: 1200, height: 630, fit: 'cover' })
      .jpeg({ quality: 82, mozjpeg: true }).toFile(path);
    variants.push({ purpose: 'og', format: 'jpg', width: 1200, height: 630, path, bytes: statSync(path).size });
  }

  return { ok: true, width: effWidth, height: effHeight, variants };
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
