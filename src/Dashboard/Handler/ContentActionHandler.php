<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Content\Service\ContentService;
use Fastsite\Content\Service\DuplicateContentException;
use Fastsite\Dashboard\Dash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/{site}/content/action — save (create/update) or publish-site.
 * Thin dispatch onto ContentService / PublishTrigger.
 */
final class ContentActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly ContentService $content,
        private readonly PublishTrigger $publish,
        private readonly \Fastsite\FactCheck\FactCheckService $factCheck,
        private readonly \Fastsite\Translation\Service\TranslationService $translations,
        private readonly \Fastsite\Shared\Queue\JobQueue $queue,
        private readonly \Fastsite\Category\Service\CategoryService $categories,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $user = $this->dash->user($request);
        $actor = 'user:' . $user['id'];
        $base = '/sites/' . $site->handle . '/content';
        $action = (string) ($body['action'] ?? '');

        try {
            switch ($action) {
                case 'save':
                    $fields = [
                        'type' => ($body['type'] ?? 'post') === 'page' ? 'page' : 'post',
                        'title' => (string) ($body['title'] ?? ''),
                        'slug' => (string) ($body['slug'] ?? ''),
                        'excerpt' => (string) ($body['excerpt'] ?? ''),
                        'body_html' => (string) ($body['body_html'] ?? ''),
                        'intro' => (string) ($body['intro'] ?? ''),
                        'tldr' => (string) ($body['tldr'] ?? ''),
                        'conclusion' => (string) ($body['conclusion'] ?? ''),
                        'status' => (string) ($body['status'] ?? 'draft'),
                        'scheduled_at' => (string) ($body['scheduled_at'] ?? ''),
                        'author_name' => (string) ($body['author_name'] ?? ''),
                        'seo_title' => (string) ($body['seo_title'] ?? ''),
                        'seo_description' => (string) ($body['seo_description'] ?? ''),
                        // Checkboxes: absent when unchecked, so always send both.
                        'noindex' => ($body['noindex'] ?? '') !== '',
                        'nofollow' => ($body['nofollow'] ?? '') !== '',
                        // Page-specific schema.org nodes, merged into the page
                        // graph the build already emits. Blank clears.
                        'json_ld' => trim((string) ($body['json_ld'] ?? '')),
                    ];
                    // Posts only: the select is not rendered for pages, and a
                    // page sent a category would be refused.
                    if ($fields['type'] === 'post') {
                        $fields['category'] = (string) ($body['category'] ?? '');
                    }
                    $id = (int) ($body['id'] ?? 0);
                    $saved = $id > 0
                        ? $this->content->update($site, $id, $fields, $actor)
                        : $this->content->create($site, $fields, $actor);

                    $note = 'Saved "' . $saved->title . '" (' . $saved->status . ').';
                    if ($saved->warnings !== []) {
                        $note .= ' Warnings: ' . implode(' ', $saved->warnings);
                    }

                    return $this->dash->redirect($base . '?type=' . $saved->type, $note);

                case 'category-save':
                    // original_slug empty = a new category; otherwise an edit,
                    // where a changed slug is a rename (301s recorded).
                    $original = trim((string) ($body['original_slug'] ?? ''));
                    $slug = trim((string) ($body['slug'] ?? ''));
                    $name = (string) ($body['name'] ?? '');
                    $input = [
                        'name' => $name,
                        'description_html' => (string) ($body['description_html'] ?? ''),
                        'seo_title' => (string) ($body['seo_title'] ?? ''),
                        'seo_description' => (string) ($body['seo_description'] ?? ''),
                    ];
                    if ($original !== '' && $slug !== '' && $slug !== $original) {
                        $input['slug'] = $slug;
                    }
                    $key = $original !== '' ? $original : ($slug !== '' ? $slug : \Fastsite\Content\Service\Slugger::slugify($name));
                    $result = $this->categories->upsert($site, $key, $input);
                    $note = ($result['created'] ? 'Added' : 'Saved') . ' category "' . $result['category']->name . '" — the site republishes.';
                    if ($result['redirects'] > 0) {
                        $note .= ' ' . $result['redirects'] . ' old URL' . ($result['redirects'] === 1 ? '' : 's') . ' now 301 to the new ones.';
                    }

                    return $this->dash->redirect($base . '?type=post', $note);

                case 'category-delete':
                    $this->categories->delete($site, (string) ($body['slug'] ?? ''));

                    return $this->dash->redirect($base . '?type=post', 'Category deleted — the site republishes.');

                case 'delete':
                    $deleted = $this->content->softDelete($site, (int) ($body['id'] ?? 0), $actor);

                    return $this->dash->redirect($base . '?type=' . $deleted->type, 'Moved "' . $deleted->title . '" to trash — Build & publish to update the live site.');

                case 'restore':
                    $restored = $this->content->restore($site, (int) ($body['id'] ?? 0));

                    return $this->dash->redirect($base . '?type=' . $restored->type, 'Restored "' . $restored->title . '".');

                case 'purge':
                    $this->content->purge($site, (int) ($body['id'] ?? 0));

                    return $this->dash->redirect($base . '?type=' . (($body['type'] ?? 'post') === 'page' ? 'page' : 'post') . '&trash=1', 'Permanently deleted.');

                case 'publish-site':
                    $this->publish->trigger($site, 'publish: dashboard request', 'dashboard');

                    // The button is in the site nav now, so it fires from any
                    // screen — returning to Content from Media or Settings
                    // would throw away where you were. Constrained to this
                    // site's own screens: `back` arrives in a request, and a
                    // redirect target read from one is a gadget unless it is
                    // pinned to somewhere it could already reach.
                    $back = (string) ($body['back'] ?? '');
                    $prefix = '/sites/' . $site->handle . '/';
                    $to = ($back === $prefix || str_starts_with($back, $prefix)) && !str_contains($back, '//')
                        ? $back
                        : $base;

                    return $this->dash->redirect($to, 'Build & publish queued.');

                case 'retranslate':
                    $id = (int) ($body['id'] ?? 0);
                    $type = ($body['type'] ?? 'post') === 'page' ? 'page' : 'post';
                    if ($site->locales === []) {
                        return $this->dash->redirect($base . '?type=' . $type, '', 'This site has no other languages.');
                    }
                    $reset = $this->translations->resetContent($site, $id);
                    // Straight to the translate lane: the rows are stale
                    // NOW, so PublishTrigger's pending-work probe would only
                    // re-derive what we already know. promoteNow because a
                    // dedupe-absorbed enqueue hands back a possibly
                    // backoff-parked twin, and the click must not wait it out.
                    $uuid = $this->queue->enqueue('translate_content', $site->handle, ['trigger' => 'dashboard-retranslate'], 'low', 'translate:' . $site->handle);
                    $this->queue->promoteNow($uuid);

                    return $this->dash->redirect(
                        $base . '?type=' . $type,
                        $reset > 0
                            ? 'Queued a full re-translation of this page — ' . $reset . ' language version' . ($reset > 1 ? 's' : '') . ' will be regenerated from the current source, then the site republishes.'
                            : 'Queued translation for this page (no existing language versions to reset).',
                    );

                case 'fact-check':
                    $id = (int) ($body['id'] ?? 0);
                    $type = ($body['type'] ?? 'post') === 'page' ? 'page' : 'post';
                    try {
                        $report = $this->factCheck->checkContent($site, $id);
                    } catch (\Fastsite\FactCheck\FactCheckException $e) {
                        return $this->dash->redirect($base . '?type=' . $type . '&edit=' . $id, '', $e->getMessage());
                    }
                    $n = \count($report['claims'] ?? []);

                    return $this->dash->redirect($base . '?type=' . $type . '&edit=' . $id . '&fc=report', 'Fact-check complete — ' . $n . ' claim(s) reviewed.');

                case 'fact-check-correct':
                    $id = (int) ($body['id'] ?? 0);
                    $type = ($body['type'] ?? 'post') === 'page' ? 'page' : 'post';
                    try {
                        $result = $this->factCheck->autoCorrectContent($site, $id, $actor);
                    } catch (\Fastsite\FactCheck\FactCheckException $e) {
                        return $this->dash->redirect($base . '?type=' . $type . '&edit=' . $id, '', $e->getMessage());
                    }
                    $note = $result['changed']
                        ? 'Auto-corrected ' . $result['corrected'] . ' claim(s) — review and Build & publish to update the live site.'
                        : 'Fact-check complete — nothing needed correcting.';

                    return $this->dash->redirect($base . '?type=' . $type . '&edit=' . $id . '&fc=report', $note);

                default:
                    return $this->dash->redirect($base, '', 'Unknown action.');
            }
        } catch (DuplicateContentException | \InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect($base, '', $e->getMessage());
        }
    }
}
