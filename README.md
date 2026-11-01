# fastsite

Ultra-lean static publishing engine. Content flows in dynamically — REST API, signed webhooks, MCP (AI agents are first-class publishers) — and fully static HTML flows out, deployed to a CDN through a git repo per site or served directly by the engine.

Built for **100/100/100/100 Google PageSpeed** on every published page: inlined minified CSS, zero shipped JavaScript, complete SEO head, responsive AVIF/WebP images with dimensions, auto-generated sitemaps, robots.txt and llms.txt.

Not a CMS — an engine.

```
 AI agent / autoseo / any system
        │  (REST · webhook · MCP)
        ▼
 ┌─────────────────────────────┐
 │ fastsite engine (PHP 8.3)   │
 │  engine.db + site.db each   │
 │  sanitize → render → audit  │
 └────────────┬────────────────┘
              ▼
   sites/{handle}/build/   (static HTML, inlined CSS, zero JS)
              ▼
   git push → Cloudflare Pages / Netlify   — or —   served directly
```

## Why

Publishing pipelines rot from the inside: a plugin ships a render-blocking script, a theme update adds 400 KB of CSS, and the Lighthouse score quietly dies. fastsite inverts the model — content is **body-only HTML**, everything else (the "shell": Twig templates, design tokens, fonts) is managed separately, and every build runs through an audit that fails the pipeline before a slow page can ship.

- **Static output is the product.** No PHP, no database and no JavaScript on the published site. Nothing to hack, nothing to slow down.
- **Agents are first-class authors.** A built-in MCP server with OAuth 2.1 consent lets AI clients create posts, upload media, edit the shell and publish — under per-scope control.
- **Every ingest path is sanitized.** Allowlist-based HTML sanitizer on REST, webhooks and MCP alike; content problems are normalized and logged, never build failures.
- **The build defends the score.** Per-build PageAudit: missing image dimensions or alt text, external resources, shipped scripts or oversized CSS block the build; content smells only warn.

## Features

- **REST API v1** — scoped API keys (argon2id-hashed secrets), rate limiting, idempotency keys, one response envelope.
- **Signed webhooks** — HMAC-SHA256 with timestamp window and replay guard; mappers turn any payload into content. Failed image adoptions never fail an article.
- **MCP server + OAuth 2.1** — dynamic client registration, PKCE, refresh-token rotation with family revocation, consent screen; tools cover content, media, shell files, components, translation, backups and publishing.
- **Media pipeline** — AVIF + WebP at five responsive widths plus an original-format fallback and a 1200×630 OG crop; sha256 dedupe; EXIF stripped; multipart, base64 and SSRF-guarded URL intake; optional ShortPixel pre-optimization.
- **i18n + auto-translation** — per-locale page trees with hreflang clusters and per-locale sitemaps; row-level staleness tracking; translation runs on the operator's endpointr gateway when configured, else each site's own user-paid OpenRouter credential.
- **Components** — HTML fragments with shortcode variables, or React components compiled and server-side-rendered **to static markup** at build time. Zero JS ships; renders are cached per (values, locale).
- **Files library** — attach any file type; HTML embeds inline verbatim (widgets may ship their own scripts inside an audited-exempt region), JSX/TSX compiles to a browser bundle, everything else becomes a crawlable download link.
- **Shell management** — the site's whole look lives in an on-disk tree (Twig, CSS tokens, fonts) editable over REST/MCP/dashboard, with zip snapshots and one-click restore. A burst of edits coalesces into a single rebuild.
- **Queue + self-healing worker** — jobs recorded in SQLite (Redis carries only ids, a flush loses nothing), heartbeats, exponential-backoff retries, dead-lettering, per-site locks.
- **Backups** — engine and per-site snapshots (SQLite `VACUUM INTO`, content-addressed media blobs, shell zips) to Cloudflare R2 or local storage; integrity-checked staged restores; nightly schedule with catch-up.
- **Dashboards** — lean server-rendered screens (sites, content, media, files, components, settings, users, ops, access) with sessions, TOTP 2FA, remember-me rotation and CSRF protection throughout.
- **Serving modes** — `git` (push the build to a repo a CDN watches; one-click Cloudflare Pages setup with a scoped API token) or `direct` (the engine serves the built tree itself: Host-matched, `_headers` honored, www→apex 301, 404 tracking).
- **SEO autopilot** — one canonical host per site (apex by default, or www — the other form 301s to it), per-locale sitemaps + index, robots.txt, llms.txt, JSON-LD, OpenGraph/Twitter cards with absolute share images, auto `_redirects` on slug changes, and 410 Gone rules for URLs you retire for good.
- **Authoring guide API** — a machine-readable contract (`GET /api/v1/authoring-guide`, also an MCP tool) that tells any agent exactly how to write content and design shells before it starts.

## Requirements

- PHP 8.3 (pdo_sqlite, intl, sodium, zip)
- Composer
- Node.js 20+ with npm (sharp, esbuild, react — media + component pipelines)
- Redis (queue + cache + sessions)

## Quick start

```bash
composer install
npm install
cp .env.example .env        # set APP_SECRET: php -r "echo bin2hex(random_bytes(32));"

php bin/fastsite install --email=you@example.com --password=change-me-please
php bin/fastsite site:create demo --name="Demo Site" --url=https://demo.example.com
php bin/fastsite content:create demo --title="Hello" --body="<p>Hi.</p>" --status=published
php bin/fastsite build demo
php bin/fastsite preview demo   # self-contained tree viewable from file:// or any URL
```

Static output lands in `var/data/sites/demo/build/`. Log in to the dashboard at `http://localhost:8080` (serve `public/` with `php -S localhost:8080 -t public public/router.php`).

Run a queue worker and the scheduler for async publishing, translation and backups:

```bash
php bin/fastsite queue:work      # serves every queue — fine for dev
php bin/fastsite schedule:run    # cron: every minute
```

Production (the bundled supervisord config) runs four workers: dedicated
lanes for builds, translations and image optimization (`queue:work
--queues=build|translate|media`) plus a general worker for everything else —
so a translation backlog can never delay a publish.

## Going live

**Git → CDN (default).** Point the site at a GitHub repo (`site:create --repo=…` or Site Settings); every publish commits the build and pushes. Connect the repo to Cloudflare Pages or Netlify with *no build step* and output directory `/`. The dashboard's Go-live card can do the whole Cloudflare Pages setup — project, custom domain, DNS — from one scoped API token, and walks you through the manual path if you prefer clicking.

**Direct.** Select "Direct from this server", point the domain's DNS at the engine, and it serves the built tree itself — same headers, redirects and 404 handling the CDN would apply, plus the two a CDN cannot do: the www ↔ non-www 301 and real 410 Gone responses.

**Redirects** (Site Settings → Redirects, the REST `redirects` endpoint, or the `upsert_redirect` MCP tool) come in two shapes. The *host rule* — www or non-www — is a site setting, not a path rule, because a CDN's `_redirects` file matches paths only and can never express it; on Cloudflare it belongs in a zone Redirect Rule, and the settings card spells out the exact one. *Path rules* are 301/302/308 to another path, or **410** for a URL that is gone for good with no replacement — served for real in direct mode, and degraded to your 404 page on a CDN (`_redirects` supports no 410 line, so the rule ships as a comment instead of a broken one).

A pre-push secret scan runs before every publish: a private-key header anywhere in the dist aborts the push; suspicious patterns in engine-generated artifacts abort, in rendered content they warn.

## CLI

Everything operational goes through one entrypoint — `php bin/fastsite <command>`:

| Area | Commands |
|---|---|
| Setup | `install`, `migrate`, `doctor` |
| Sites | `site:create`, `site:list`, `site:migrate`, `site:add-language` |
| Content | `content:create`, `build`, `preview`, `publish [--sync]` |
| Queue | `queue:work`, `schedule:run`, `jobs:list`, `jobs:retry` |
| API keys | `key:create`, `key:list`, `key:rotate`, `key:revoke` |
| Webhooks | `webhook:create`, `webhook:list`, `webhook:deliveries`, `webhook:replay` |
| Shell | `shell:list`, `shell:snapshot`, `shell:restore` |
| Backups | `backup:site`, `backup:engine`, `backup:list`, `backup:prune`, `restore:site`, `restore:engine` |

`doctor` checks the whole stack (databases, Redis, queue, node toolchain, backup store, per-site dist health) and exits non-zero on failure — wire it into monitoring.

## REST API

Authenticate with `X-API-Key: fs_<key_id>_<secret>`. Keys carry scopes (`content:write`, `media:write`, `shell:write`, …) and an optional site allowlist. All responses share one envelope:

```json
{ "success": true, "data": { … } }
```

Highlights under `/api/v1`:

- `POST /sites/{site}/content` — create/update content (send `Idempotency-Key` to make retries safe)
- `POST /sites/{site}/media` — multipart upload, JSON `{data, filename}` base64 upload, or JSON `{url}` import; alt text is auto-generated when omitted
- `GET/PUT/DELETE /shell/file/{path}`, `POST /shell/snapshot`, `POST /shell/restore/{rev}` — raw-body shell file management
- `PUT /sites/{site}/components/{handle}` — upload an HTML or React component (React compiles at upload; Node-only imports are rejected)
- `GET /authoring-guide`, `GET /sites/{site}/authoring-guide` — the authoring contract, generic or site-specific

Webhooks: `POST /webhooks/{integration}` with `X-Fastsite-Signature: t=<unix>,v1=<hmac_sha256("<t>.<body>", secret)>` (±300 s window) and a unique `X-Delivery-Id` per delivery.

## MCP

Point an MCP client at `POST /mcp`. An unauthenticated request advertises the OAuth authorization server; the client registers dynamically, the user logs in and consents on the engine's own screens, and the client gets a scoped bearer token. From there the agent can fetch the authoring guide, create posts, upload media (from a URL or as raw base64 bytes it holds itself), edit shell files, manage translations and trigger publishes — every tool gated by scope and site membership.

## Configuration

All environment variables (see `.env.example`):

| Variable | Purpose |
|---|---|
| `APP_URL` | Public origin of the engine (dashboard, API, OAuth issuer) — set it in production |
| `APP_SECRET` | 64+ char random secret; derives the at-rest cipher key and signs sessions |
| `DATA_PATH` | Data volume root (databases, media, builds, repos); defaults to `var/data` |
| `REDIS_URL` | Redis connection, e.g. `tcp://127.0.0.1:6379` |
| `GIT_AUTHOR_NAME` / `GIT_AUTHOR_EMAIL` | Identity on publish commits |
| `ENDPOINTR_URL` / `ENDPOINTR_KEY` | Operator's endpointr AI gateway for translation (private flavor) |
| `AI_TRANSLATE_TIMEOUT` | Per-call translation timeout in seconds (default 420) |

Fact-checking (and translation fallback) is user-paid: each account adds its own OpenRouter key in the dashboard (Account → AI keys) and selects it per site. Optional per-site ShortPixel keys are configured on Site Settings the same way. Secrets are encrypted at rest (AES-256-GCM derived from `APP_SECRET`); the engine's own issued tokens are stored only as hashes.

## Deploy

A production-ready `docker-compose.yml` (app + Redis) is included — one container runs nginx + PHP-FPM, the queue worker and the scheduler under supervisord, with Node, Chromium and ffmpeg for the media/component/screenshot pipelines. Works out of the box on Coolify: set `APP_URL` and `APP_SECRET`, attach the domain, deploy. Data persists in the `fastsite-data` named volume.

Build flavor via the `FLAVOR` env (Docker build arg): `private` (default — endpointr translator, needs `ENDPOINTR_URL`/`ENDPOINTR_KEY`) or `public` (OpenRouter only, no proprietary code). The `scripts/deploy-*.sh` trimmers run inside the image build — never against a working copy.

## Testing

```bash
php vendor/phpunit/phpunit/phpunit
```

The suite (260+ tests) covers the sanitizer, build output contract, PageAudit, queue self-healing, API auth, webhook signatures, OAuth flows, i18n, components, backup/restore integrity and the dashboards. Tests that need Redis use database 15 and skip when Redis is absent.

## Open-source build

A GPL-3.0 build (OpenRouter-only AI, no proprietary gateway code) is published at [sitepointsystems/getfastsite_gnu](https://github.com/sitepointsystems/getfastsite_gnu) as a scrubbed single-commit export of this repo.
