<?php

declare(strict_types=1);

/**
 * Environment-derived application config. Read once at boot; everything else
 * receives values through DI — no service touches $_ENV directly.
 */

$root = \dirname(__DIR__);

$dataPath = $_ENV['DATA_PATH'] ?? '';
if ($dataPath === '') {
    $dataPath = $root . '/var/data';
}

return [
    'app.env' => $_ENV['APP_ENV'] ?? 'production',
    'app.url' => rtrim($_ENV['APP_URL'] ?? 'http://localhost:8080', '/'),
    'app.secret' => $_ENV['APP_SECRET'] ?? '',
    'app.root' => $root,
    'app.data_path' => rtrim(str_replace('\\', '/', $dataPath), '/'),
    'app.log_path' => $root . '/var/log',
    'redis.url' => $_ENV['REDIS_URL'] ?? 'tcp://127.0.0.1:6379',
    'git.author_name' => $_ENV['GIT_AUTHOR_NAME'] ?? 'fastsite',
    'git.author_email' => $_ENV['GIT_AUTHOR_EMAIL'] ?? 'engine@fastsite.local',
    // GitHub token for HTTPS push auth (fine-grained PAT with Contents: RW on
    // the target repo). Used as an Authorization header for github.com only —
    // never written to .git/config. Leave unset to use SSH deploy keys instead.
    'git.token' => $_ENV['GITHUB_TOKEN'] ?? '',
    // Owner-paid translation gateway (private build). Translation uses this
    // when configured; otherwise it falls back to a site's own OpenRouter
    // credential (see below). This is the operator's service, so it stays here.
    'endpointr.url' => rtrim(trim((string) ($_ENV['ENDPOINTR_URL'] ?? '')), '/'),
    'endpointr.key' => trim((string) ($_ENV['ENDPOINTR_KEY'] ?? '')),
    // The OpenRouter key is USER-paid (translation fallback + fact-checking),
    // so it is NOT read from .env — each account stores its own key in the AI
    // credential vault (ai_credentials), and a site references one. Only the
    // non-secret client shape (default endpoint + model ids) lives here so the
    // gateway URL/model have sensible defaults when a credential omits them.
    'ai.default_base_url' => rtrim(trim((string) ($_ENV['AI_BASE_URL'] ?? 'https://openrouter.ai/api')), '/') ?: 'https://openrouter.ai/api',
    'ai.default_model' => trim((string) ($_ENV['AI_MODEL'] ?? '')) ?: '~anthropic/claude-sonnet-latest',
    // Translation gets its OWN default, deliberately cheap and fast: it is the
    // highest-volume call the engine makes (a 82-page × 9-locale site is over
    // a thousand) and the least in need of a frontier model. It is also what
    // burns a per-account rate limit and parks the whole run.
    'ai.translate_model' => trim((string) ($_ENV['TRANSLATE_MODEL'] ?? '')) ?: 'google/gemini-2.5-flash-lite',
    'ai.factcheck_model' => trim((string) ($_ENV['FACTCHECK_MODEL'] ?? '')) ?: 'perplexity/sonar',
    'ai.research_model' => trim((string) ($_ENV['RESEARCH_MODEL'] ?? '')) ?: 'perplexity/sonar',
    // Per-call translation timeout (seconds). A large page's body can take
    // minutes. MUST stay below JobRunner::STALE_AFTER_SECONDS (600) — with the
    // per-field heartbeat the largest un-heartbeated gap is one call, so this
    // is the ceiling. Clamped to [30, 590].
    'ai.translate_timeout' => max(30, min(590, (int) ($_ENV['AI_TRANSLATE_TIMEOUT'] ?? 420))),
    // Cloudflare R2 (S3-compatible) backup target. When unset, backups fall
    // back to a local directory under the data volume.
    'r2.endpoint' => rtrim($_ENV['R2_ENDPOINT'] ?? '', '/'),
    'r2.bucket' => $_ENV['R2_BUCKET'] ?? '',
    'r2.access_key' => $_ENV['R2_ACCESS_KEY'] ?? '',
    'r2.secret_key' => $_ENV['R2_SECRET_KEY'] ?? '',
    // ShortPixel image optimization (runs on landing, before sharp). Unset =
    // disabled (sharp still optimizes). lossy: 1 lossy, 2 glossy, 0 lossless.
    'shortpixel.key' => $_ENV['SHORTPIXEL_API_KEY'] ?? '',
    'shortpixel.lossy' => (int) ($_ENV['SHORTPIXEL_LOSSY'] ?? 1),
];
