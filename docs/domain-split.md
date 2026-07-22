# Domain split: engine on `app.getfastsite.com`, public site on `getfastsite.com`

The fastsite **engine** (dashboard, REST API, MCP, OAuth) and the **public
marketing site** are two different things:

- The engine is the PHP app in this repo. It runs in one container behind
  Coolify/Traefik and answers on **any** Host (`nginx server_name _;`).
- The marketing site (`getfastsite.com`) is a **normal fastsite site** (handle
  `getfastsite`, seeded by `MarketingSeeder` / `site:seed-getfastsite`). Like
  every site it is built to static HTML and published to a git repo that
  **Cloudflare Pages** serves — the engine does not serve it at request time.

So "make `getfastsite.com` the public site and move the app to
`app.getfastsite.com`" is a routing/DNS cutover, not an app change. The engine
already supports it: `APP_URL` drives the OAuth issuer / MCP discovery
(`PublicUrl`), and the session cookie is host-only (`__Host-fsid`, no `Domain`),
so the app and the apex are cleanly isolated.

## Cutover steps

1. **Publish the marketing site to the apex.**
   - On the `getfastsite` site set `github_repo` + a GitHub credential (Site
     Settings), then publish it (`php bin/fastsite publish getfastsite`, or the
     dashboard **Publish** button). Confirm the dist repo fills.
   - Create/point the **Cloudflare Pages** project at that repo and attach the
     apex domain `getfastsite.com` (+ a `www → apex` redirect rule; fastsite
     canonicalises to the apex). Verify the static site serves.

2. **Move the engine to `app.getfastsite.com`.**
   - In Coolify/Traefik, route the host `app.getfastsite.com` to the engine
     container (TLS via the proxy, as today).
   - Set `APP_URL=https://app.getfastsite.com`. No code or nginx change — the
     app answers on any host and `PublicUrl`/`MetadataHandler`/`McpAuthMiddleware`
     pick up the new origin. (Even if left unset it self-heals from the
     forwarded host, but set it for a stable OAuth issuer.)

3. **Consequences to expect.**
   - The **OAuth issuer** becomes `https://app.getfastsite.com`, so existing MCP
     clients must **re-register / re-consent** against the new origin.
   - The dashboard **session** is scoped to `app.getfastsite.com` (by design;
     `__Host-` cookies can't span subdomains). Users log in at the app subdomain.
   - Point any docs / links that referenced the dashboard at
     `app.getfastsite.com`.

Nothing else in the engine needs to change — the split is DNS + the `APP_URL`
env var.
