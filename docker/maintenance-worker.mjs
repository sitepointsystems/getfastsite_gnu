// Cloudflare Worker: the screen app.getfastsite.com shows while it is down.
//
// Deployed to the getfastsite.com zone as `fastsite-maintenance`, routed at
// app.getfastsite.com/*, following the pattern already proven on another of
// our proxied hosts.
// Kept in the repo rather than only in Cloudflare so the thing visitors see
// when the engine is down is reviewable, diffable and re-deployable, instead
// of living solely in a dashboard nobody remembers editing.
//
// WHAT IT DOES NOT DO: invent an outage. It proxies every request untouched
// and only substitutes a page when the ORIGIN actually failed — a deploy, a
// container restart, an OOM. A healthy 500 from the application (a real bug)
// is passed through, because hiding it behind "back in a minute" would delay
// someone finding out.
//
// Statuses treated as "the origin is not there":
//   502 Bad Gateway, 503 Service Unavailable, 504 Gateway Timeout,
//   507 Insufficient Storage, and Cloudflare's own 520-527 origin errors.

const ORIGIN_DOWN = new Set([502, 503, 504, 507, 520, 521, 522, 523, 524, 525, 526, 527]);

/**
 * Machine surfaces never get HTML. A client that asked for JSON and receives
 * a styled apology cannot tell an outage from a malformed response, and the
 * expensive case is /f/* — the form endpoint live customer sites post to.
 * A visitor's enquiry answered with a maintenance page is a lead lost
 * silently, which is the exact failure this worker exists to prevent.
 *
 * These also have their own routes with no script attached, so they bypass
 * the worker entirely; this is the belt to that pair of braces.
 */
const MACHINE_PREFIXES = ['/api/', '/mcp', '/f/', '/webhooks/', '/oauth/', '/.well-known/'];

function wantsHtml(request) {
  const url = new URL(request.url);
  if (MACHINE_PREFIXES.some((p) => url.pathname === p.replace(/\/$/, '') || url.pathname.startsWith(p))) {
    return false;
  }
  // A browser navigation asks for text/html. XHR/fetch from the dashboard
  // asks for JSON and should see the real status, not a page.
  return (request.headers.get('accept') || '').includes('text/html');
}

export default {
  async fetch(request, env, ctx) {
    let response;
    try {
      response = await fetch(request);
    } catch (err) {
      // The origin refused the connection outright — mid-deploy, most often.
      return wantsHtml(request) ? page(503) : new Response('origin unavailable', { status: 503 });
    }

    if (!ORIGIN_DOWN.has(response.status) || !wantsHtml(request)) {
      return response;
    }

    // Keep the real status. Answering 200 would tell crawlers and uptime
    // checks that a broken site is fine, which is how an outage goes
    // unnoticed for a day.
    return page(response.status);
  },
};

function page(status) {
  return new Response(html(status), {
    status,
    headers: {
      'Content-Type': 'text/html; charset=utf-8',
      // Never let an outage page outlive the outage.
      'Cache-Control': 'no-store, must-revalidate',
      'Retry-After': '30',
      'X-Robots-Tag': 'noindex',
    },
  });
}

// Inline everything: the origin is down, so nothing it hosts can be fetched —
// a stylesheet or a logo <img> would render as a broken page about a broken
// page. Colours are the engine's own tokens (dash-layout.html.twig).
function html(status) {
  const detail = status === 507
    ? 'The server has run out of storage. This one needs a hand — it will not clear on its own.'
    : 'We are deploying an update. This usually takes less than a minute.';

  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Back shortly — fastsite</title>
<style>
  :root{ --bg:#070d14; --surface:#0d1622; --border:#1c2b3d; --text:#e8f0f8; --text-2:#8aa0b8; --accent:#2a86ff; }
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:1.5rem;
       background:radial-gradient(120% 60% at 70% 0%,rgba(42,134,255,.10),transparent 60%),var(--bg);
       color:var(--text);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;line-height:1.55}
  .card{max-width:32rem;width:100%;background:var(--surface);border:1px solid var(--border);
        border-radius:14px;padding:2rem;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.45)}
  .dot{width:.6rem;height:.6rem;border-radius:50%;background:var(--accent);display:inline-block;
       margin-right:.5rem;animation:pulse 1.4s ease-in-out infinite}
  @keyframes pulse{0%,100%{opacity:.35}50%{opacity:1}}
  @media (prefers-reduced-motion:reduce){.dot{animation:none;opacity:.8}}
  h1{margin:0 0 .6rem;font-size:1.35rem}
  p{margin:0 0 .4rem;color:var(--text-2);font-size:.95rem}
  .code{margin-top:1.4rem;font-size:.75rem;color:#5b6b7e;font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
</style>
</head>
<body>
  <div class="card">
    <h1><span class="dot"></span>Back in a moment</h1>
    <p>${detail}</p>
    <p>Published sites are unaffected — they are static and served from the CDN.</p>
    <div class="code">HTTP ${status}</div>
  </div>
  <script>setTimeout(function(){ location.reload(); }, 15000);</script>
</body>
</html>`;
}
