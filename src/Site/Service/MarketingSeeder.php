<?php

declare(strict_types=1);

namespace Fastsite\Site\Service;

use Fastsite\Build\Service\BuildService;
use Fastsite\Content\Repository\ContentRepository;
use Fastsite\Content\Service\ContentService;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;
use Fastsite\Site\Repository\SiteRepository;

/**
 * Stands up getfastsite.com — the marketing site for fastsite — inside the
 * engine itself (dogfood). Creates the site, installs the `shell-marketing`
 * theme into its shell/, authors the marketing pages + a launch post through
 * the real ContentService (so they are sanitized and audited like any other
 * content), and builds. Idempotent: re-running reinstalls the theme and adds
 * only the content whose slug is missing.
 *
 * Publishing (git push to the site's repo) stays operator-driven — set the
 * repo + a GitHub credential on the site, then `publish getfastsite`.
 */
final class MarketingSeeder
{
    public const HANDLE = 'getfastsite';

    public function __construct(
        private readonly SiteService $sites,
        private readonly SiteRepository $siteRepo,
        private readonly ContentService $content,
        private readonly ContentRepository $contentRepo,
        private readonly BuildService $build,
        private readonly SiteDatabaseManager $databases,
        private readonly string $appRoot,
    ) {
    }

    /**
     * @return list<string> human-readable log lines
     */
    public function seed(bool $build = true): array
    {
        $log = [];

        $site = $this->siteRepo->findByHandle(self::HANDLE);
        if ($site === null) {
            $site = $this->sites->create(
                self::HANDLE,
                'fastsite',
                'https://getfastsite.com',
            );
            $log[] = sprintf('Created site "%s" (id %d).', $site->handle, $site->id);
        } else {
            $log[] = sprintf('Site "%s" already exists — reusing.', $site->handle);
        }

        $installed = $this->installShell($site->handle);
        $log[] = sprintf('Installed the shell-marketing theme (%d files).', $installed);

        foreach ($this->pages() as $page) {
            $log[] = $this->authorOnce($site, 'page', $page);
        }
        foreach ($this->posts() as $post) {
            $log[] = $this->authorOnce($site, 'post', $post);
        }

        if ($build) {
            $stats = $this->build->build($site);
            $log[] = sprintf(
                'Built %d page(s): %d written, %d unchanged → %s',
                $stats->pages,
                $stats->written,
                $stats->skipped,
                $stats->outputDir,
            );
        }

        $log[] = 'Next: set the site\'s github_repo + a GitHub credential, then `publish ' . self::HANDLE . '`.';

        return $log;
    }

    /**
     * @param array{title:string, slug:string, excerpt?:string, body_html:string, seo_title?:string, seo_description?:string, author_name?:string} $item
     */
    private function authorOnce(Site $site, string $type, array $item): string
    {
        $existing = $this->contentRepo->findBySlug($site->handle, $type, $item['slug']);
        if ($existing !== null) {
            return sprintf('· %s "%s" exists (id %d) — left as-is.', $type, $item['slug'], $existing->id);
        }

        $created = $this->content->create($site, [
            'type' => $type,
            'title' => $item['title'],
            'slug' => $item['slug'],
            'excerpt' => $item['excerpt'] ?? '',
            'body_html' => $item['body_html'],
            'status' => 'published',
            'author_name' => $item['author_name'] ?? '',
            'seo_title' => $item['seo_title'] ?? '',
            'seo_description' => $item['seo_description'] ?? '',
        ], 'seeder');

        $warn = $created->warnings !== [] ? ' [' . implode('; ', $created->warnings) . ']' : '';

        return sprintf('+ %s "%s" published (id %d).%s', $type, $created->slug, $created->id, $warn);
    }

    /**
     * Reinstalls the marketing theme into the site's shell/, replacing the
     * default scaffold. The manifest self-heals on the next shell read.
     */
    private function installShell(string $handle): int
    {
        $source = $this->appRoot . '/templates/shell-marketing';
        $target = $this->databases->sitePath($handle) . '/shell';

        if (!is_dir($source)) {
            throw new \RuntimeException('Marketing theme missing at ' . $source);
        }

        if (is_dir($target)) {
            $this->clearDir($target);
        } else {
            mkdir($target, 0770, true);
        }

        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);
            $relative = str_replace('\\', '/', substr($item->getPathname(), \strlen($source) + 1));
            $dest = $target . '/' . $relative;
            if ($item->isDir()) {
                if (!is_dir($dest)) {
                    mkdir($dest, 0770, true);
                }
                continue;
            }
            $dir = \dirname($dest);
            if (!is_dir($dir)) {
                mkdir($dir, 0770, true);
            }
            copy($item->getPathname(), $dest);
            ++$count;
        }

        return $count;
    }

    private function clearDir(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            \assert($item instanceof \SplFileInfo);
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
    }

    /**
     * @return list<array{title:string, slug:string, excerpt:string, body_html:string, seo_title:string, seo_description:string}>
     */
    private function pages(): array
    {
        $howItWorks = <<<'HTML'
<p class="lead">fastsite is a publishing <em>engine</em>, not a CMS. You send content; it produces fully static HTML, commits it per site, and lets your CDN serve it. Here is the whole model.</p>

<h2>1. You send bodies, not pages</h2>
<p>Content is body-only HTML. You never author a full page — the <strong>shell</strong> owns the chrome (head, header, footer, design tokens) and the engine wraps every body in it at build time. Send content three ways:</p>
<ul>
  <li><strong>REST</strong> — <code>POST /api/v1/sites/{site}/content</code> with an API key.</li>
  <li><strong>MCP</strong> — the <code>create_post</code> / <code>create_page</code> tools, with OAuth consent.</li>
  <li><strong>Webhooks</strong> — an HMAC-signed payload mapped to a post, page, media import, or component.</li>
</ul>
<p>Every ingest path runs the same sanitizer: a fixed tag allowlist, inline styles stripped, scripts and iframes removed with a warning. Content problems become warnings — they never wedge a build.</p>

<h2>2. Agents design the shell</h2>
<p>The shell is a small tree of Twig templates, a <code>tokens.css</code> design system, and per-locale UI strings. It is what an AI agent designs and iterates on over the API or MCP. Change a token, and the whole site re-themes on the next build.</p>

<h2>3. The engine builds static HTML</h2>
<p>On every publish the engine:</p>
<ul>
  <li>Expands <code>&lt;img data-media-id&gt;</code> into responsive <code>&lt;picture&gt;</code> with AVIF + WebP, dimensions, and lazy-loading.</li>
  <li>Inlines minified CSS (tokens first), renders zero JavaScript, and minifies the HTML.</li>
  <li>Writes a full SEO head: title, description, canonical, Open Graph, and JSON-LD.</li>
  <li>Generates <code>sitemap.xml</code>, <code>robots.txt</code>, and <code>llms.txt</code> automatically.</li>
  <li>Runs a per-build audit. A missing image dimension, an external resource, or a shipped <code>&lt;script&gt;</code> blocks the build before it can cost you a point.</li>
</ul>

<h2>4. It commits and deploys</h2>
<p>Only changed files are written — the build is incremental. The output is committed to your git repository, one commit per publish, and Cloudflare Pages serves it. Your database never touches the edge.</p>

<blockquote>The hard goal: 100/100/100/100 on Google PageSpeed for every published page. The audit is what keeps that promise honest.</blockquote>

<h2>Quickstart</h2>
<ol>
  <li>Create a site and design its shell (or start from the default).</li>
  <li>Create an API key or connect over MCP.</li>
  <li>Send a post. Watch a static page appear, committed and live.</li>
  <li>Add a locale — the engine translates and emits a per-language tree with correct hreflang.</li>
</ol>
<p>Ready for specifics? Read the <a href="/api/">API &amp; MCP reference</a> or the <a href="/webhooks/">webhooks walkthrough</a>.</p>
HTML;

        $api = <<<'HTML'
<p class="lead">Everything the dashboard does is available on the REST API and over MCP — the same authorization model, the same scopes, the same results.</p>

<h2>Authentication</h2>
<p>REST uses an API key header; MCP uses OAuth 2.1 with PKCE. Both resolve to one <code>AccessContext</code> with a shared scope vocabulary, so a tool and an endpoint enforce access identically.</p>
<pre><code>X-API-Key: fs_&lt;key_id&gt;_&lt;secret&gt;</code></pre>

<h2>Create a post over REST</h2>
<pre><code>curl -X POST https://your-host/api/v1/sites/blog/content \
  -H "X-API-Key: $FASTSITE_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: 2f1c…" \
  -d '{
    "type": "post",
    "title": "Hello, world",
    "body_html": "&lt;p&gt;Shipped in a single call.&lt;/p&gt;",
    "status": "published"
  }'</code></pre>
<p>The response is the created row. Writing content does not publish it — call <code>POST /api/v1/sites/blog/publish</code> when you are ready, and a static <code>/blog/hello-world/</code> is built and committed. Re-sending the same <code>Idempotency-Key</code> replays the stored response instead of creating a duplicate.</p>

<h2>The same thing over MCP</h2>
<p>Point an MCP client (like Claude) at <code>/mcp</code>. After the OAuth consent screen, the model can call tools directly:</p>
<pre><code>{
  "method": "tools/call",
  "params": {
    "name": "create_post",
    "arguments": {
      "site": "blog",
      "title": "Hello, world",
      "body_html": "&lt;p&gt;Shipped by an agent.&lt;/p&gt;",
      "status": "published"
    }
  }
}</code></pre>

<h2>A tour of the surface</h2>
<table>
  <thead><tr><th>Area</th><th>REST</th><th>MCP tools</th></tr></thead>
  <tbody>
    <tr><td>Content</td><td><code>/content</code>, <code>/content/{id}</code></td><td><code>create_post</code>, <code>create_page</code>, <code>update_post</code>, <code>delete_content</code></td></tr>
    <tr><td>Media</td><td><code>/media</code></td><td><code>upload_media_from_url</code>, <code>list_media</code></td></tr>
    <tr><td>Shell</td><td><code>/shell/files</code>, <code>/shell/file/{path}</code></td><td><code>shell_list_files</code>, <code>shell_put_file</code></td></tr>
    <tr><td>Components</td><td><code>/components/{handle}</code></td><td><code>upsert_component</code>, <code>list_components</code></td></tr>
    <tr><td>i18n</td><td><code>/languages</code></td><td><code>add_language</code>, <code>translate_site</code></td></tr>
    <tr><td>Publish</td><td><code>/publish</code></td><td><code>publish_site</code>, <code>preview_site</code>, <code>get_build_status</code></td></tr>
    <tr><td>Backup</td><td><code>/backups</code></td><td><code>backup_site</code>, <code>list_backups</code>, <code>restore_backup</code></td></tr>
  </tbody>
</table>

<h2>Scopes</h2>
<p>Every key and every grant carries a fixed set of scopes — <code>content:write</code>, <code>media:write</code>, <code>shell:write</code>, <code>sites:read</code>, and so on. <code>admin</code> implies all. A key is also pinned to specific sites; a request outside that set is rejected before it reaches a handler.</p>

<h2>Read the authoring guide first</h2>
<p>The engine serves a machine-readable authoring guide at <code>GET /api/v1/authoring-guide</code> and the MCP tool <code>get_authoring_guide</code>. It is the contract every agent should fetch before designing a shell or sending content — the rules that keep a page at a perfect score.</p>
HTML;

        $webhooks = <<<'HTML'
<p class="lead">Webhooks turn any upstream system into a publisher. Sign a payload, POST it, and the engine maps it to content and rebuilds — no key exchange in the request path, the signature is the auth.</p>

<h2>The endpoint</h2>
<pre><code>POST /webhooks/{integration}</code></pre>
<p>The raw body is read verbatim and verified before anything else runs. Two headers are required:</p>
<ul>
  <li><code>X-Fastsite-Signature: t=&lt;unix&gt;,v1=&lt;hmac_sha256("&lt;t&gt;.&lt;body&gt;", secret)&gt;</code> — a ±300s window, compared with a constant-time check.</li>
  <li><code>X-Delivery-Id</code> — unique per integration, so a replayed delivery is rejected.</li>
</ul>
<p>A valid delivery is recorded and enqueued, and the endpoint returns <code>202 Accepted</code> immediately. The mapping and build happen on the queue with automatic retries and backoff.</p>

<h2>Built-in mappers</h2>
<p>Mappers are code, not configuration strings. Each one takes a generic payload and turns it into an engine action. The engine ships a family of them:</p>
<table>
  <thead><tr><th>Mapper</th><th>Does</th></tr></thead>
  <tbody>
    <tr><td><code>create_post</code></td><td>Maps a payload to a published post.</td></tr>
    <tr><td><code>create_page</code></td><td>Maps a payload to a page.</td></tr>
    <tr><td><code>import_media</code></td><td>Pulls an image by URL (SSRF-guarded) into the media library.</td></tr>
    <tr><td><code>upsert_component</code></td><td>Creates or updates a component.</td></tr>
    <tr><td><code>autoseo</code></td><td>Idempotent article ingest: adopts external images, derives alt text, never fails on a bad image.</td></tr>
  </tbody>
</table>

<h2>A worked example</h2>
<pre><code># body.json is the exact bytes you sign and send
SECRET="whsec_…"
BODY=$(cat body.json)
TS=$(date +%s)
SIG=$(printf '%s.%s' "$TS" "$BODY" | \
  openssl dgst -sha256 -hmac "$SECRET" | awk '{print $2}')

curl -X POST https://your-host/webhooks/newsroom \
  -H "X-Fastsite-Signature: t=$TS,v1=$SIG" \
  -H "X-Delivery-Id: $(uuidgen)" \
  -H "Content-Type: application/json" \
  --data-binary @body.json</code></pre>

<h2>Yours to manage</h2>
<p>Webhooks are site-scoped, not admin-only: any member of a site can create integrations, rotate secrets, inspect deliveries, and replay a failed one — from the dashboard, the REST API, or MCP. Secrets are stored encrypted and can be revealed to configure the sender.</p>
HTML;

        return [
            [
                'title' => 'How it works',
                'slug' => 'how-it-works',
                'excerpt' => 'The whole model: send bodies, design a shell, and the engine builds static HTML, commits per site, and deploys to your CDN.',
                'body_html' => $howItWorks,
                'seo_title' => 'How fastsite works — content in, static HTML out',
                'seo_description' => 'fastsite is a static publishing engine, not a CMS. Send content over REST, MCP, or webhooks; get 100/100/100/100 static pages committed per site.',
            ],
            [
                'title' => 'API & MCP',
                'slug' => 'api',
                'excerpt' => 'One authorization model across REST and MCP. Create content, upload media, manage the shell, translate, and publish — from code or from an agent.',
                'body_html' => $api,
                'seo_title' => 'fastsite API & MCP reference',
                'seo_description' => 'The fastsite REST API and MCP server: create posts and pages, upload media, manage the shell and components, translate, and publish — with shared scopes.',
            ],
            [
                'title' => 'Webhooks',
                'slug' => 'webhooks',
                'excerpt' => 'HMAC-signed inbound webhooks map any payload to a post, page, media import, or component — the signature is the auth, and the build runs on the queue.',
                'body_html' => $webhooks,
                'seo_title' => 'fastsite webhooks — signed, mapped, published',
                'seo_description' => 'Turn any upstream system into a publisher. Sign a payload, POST it to fastsite, and a built-in mapper turns it into content and rebuilds your static site.',
            ],
        ];
    }

    /**
     * @return list<array{title:string, slug:string, excerpt:string, body_html:string, author_name:string, seo_title:string, seo_description:string}>
     */
    private function posts(): array
    {
        $launch = <<<'HTML'
<p class="lead">This very page proves the point. The site you are reading was authored, built, and published by the fastsite engine — the same engine you can point your own content at.</p>

<h2>What fastsite is</h2>
<p>An ultra-lean static publishing engine. Content comes in over REST, a webhook, or MCP; it lands in per-site SQLite; the engine renders fully static HTML, commits it to git one commit per publish, and Cloudflare Pages serves it. Not a CMS — there is no runtime, no client-side framework, and nothing to hydrate.</p>

<h2>Why zero JavaScript</h2>
<p>The fastest script is the one you never ship. Every page here is inlined, minified CSS and pre-rendered HTML. A build-time audit refuses to publish a page that ships a <code>&lt;script&gt;</code>, loads an external resource, or drops an image dimension — so a perfect PageSpeed score is a property of the pipeline, not a thing you chase.</p>

<h2>Built for agents, too</h2>
<p>fastsite treats an AI agent as a first-class author. The MCP server exposes the whole engine as tools; an agent designs the shell, writes content, imports images, translates into new languages, and publishes — all under OAuth consent and per-scope control. The authoring guide is served in a machine-readable form so the agent knows the rules before it starts.</p>

<h2>What is new</h2>
<ul>
  <li><strong>Teams</strong> — share a site with your collaborators by email invite.</li>
  <li><strong>Per-user GitHub credentials</strong> — each publisher uses their own token; nothing shared, nothing in a config file.</li>
  <li><strong>A WYSIWYG editor</strong> — write bodies visually, drop in components, or flip to raw HTML.</li>
  <li><strong>Redirects &amp; trash</strong> — canonical-host control, manual redirects, and soft-delete you can undo.</li>
</ul>

<p>Design a shell once, and let humans and agents fill it forever. Start with the <a href="/how-it-works/">guide</a>.</p>
HTML;

        return [
            [
                'title' => 'Introducing fastsite',
                'slug' => 'introducing-fastsite',
                'excerpt' => 'A static publishing engine that keeps every page at a perfect score — and treats AI agents as first-class authors. This site is its own proof.',
                'body_html' => $launch,
                'author_name' => 'The fastsite team',
                'seo_title' => 'Introducing fastsite — 100/100/100/100 publishing for humans and agents',
                'seo_description' => 'fastsite is an ultra-lean static publishing engine: content in over REST, MCP, or webhooks; zero-JS static HTML out, committed per site and served from the edge.',
            ],
        ];
    }
}
