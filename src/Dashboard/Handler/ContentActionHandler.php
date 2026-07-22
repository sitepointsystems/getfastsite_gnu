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
                        'status' => (string) ($body['status'] ?? 'draft'),
                        'scheduled_at' => (string) ($body['scheduled_at'] ?? ''),
                        'author_name' => (string) ($body['author_name'] ?? ''),
                        'seo_title' => (string) ($body['seo_title'] ?? ''),
                        'seo_description' => (string) ($body['seo_description'] ?? ''),
                    ];
                    $id = (int) ($body['id'] ?? 0);
                    $saved = $id > 0
                        ? $this->content->update($site, $id, $fields, $actor)
                        : $this->content->create($site, $fields, $actor);

                    $note = 'Saved "' . $saved->title . '" (' . $saved->status . ').';
                    if ($saved->warnings !== []) {
                        $note .= ' Warnings: ' . implode(' ', $saved->warnings);
                    }

                    return $this->dash->redirect($base . '?type=' . $saved->type, $note);

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

                    return $this->dash->redirect($base, 'Build & publish queued.');

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
