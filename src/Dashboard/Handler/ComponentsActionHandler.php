<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Build\Service\PublishTrigger;
use Fastsite\Component\Service\ComponentHookService;
use Fastsite\Component\Service\ComponentService;
use Fastsite\Dashboard\Dash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/{site}/components/action — create/update (op=save) or remove
 * (op=delete) a component, and manage component hooks (op=hook-save /
 * op=hook-delete — build-time CTA injection rules; those republish, as does
 * editing a component an enabled hook injects). React sources compile here;
 * a compile or lint error is surfaced as a flash, never a 500.
 */
final class ComponentsActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly ComponentService $components,
        private readonly ComponentHookService $hooks,
        private readonly PublishTrigger $publish,
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
        $base = '/sites/' . $site->handle . '/components';
        $op = (string) ($body['op'] ?? 'save');
        $handle = trim((string) ($body['handle'] ?? ''));

        if ($op === 'hook-save') {
            try {
                $hook = $this->hooks->save($site->handle, [
                    'id' => (int) ($body['id'] ?? 0),
                    'label' => (string) ($body['label'] ?? ''),
                    'component_handle' => (string) ($body['component_handle'] ?? ''),
                    'values' => (string) ($body['values'] ?? ''),
                    'position' => (string) ($body['position'] ?? 'bottom'),
                    'paragraph' => (int) ($body['paragraph'] ?? 1),
                    'target' => (string) ($body['target'] ?? 'posts'),
                    'include_slugs' => (string) ($body['include_slugs'] ?? ''),
                    'exclude_slugs' => (string) ($body['exclude_slugs'] ?? ''),
                    'priority' => (int) ($body['priority'] ?? 100),
                    'enabled' => (string) ($body['enabled'] ?? '0'),
                ]);
            } catch (\InvalidArgumentException $e) {
                return $this->dash->redirect($base, '', $e->getMessage());
            }
            $this->publish->trigger($site, 'publish: component hook changed (dashboard)', 'dashboard');

            return $this->dash->redirect($base, 'Saved hook #' . $hook->id . ' (' . $hook->componentHandle . ' → ' . $hook->target . '). Republishing.');
        }

        if ($op === 'hook-delete') {
            $id = (int) ($body['id'] ?? 0);
            $this->hooks->delete($site->handle, $id);
            $this->publish->trigger($site, 'publish: component hook removed (dashboard)', 'dashboard');

            return $this->dash->redirect($base, 'Deleted hook #' . $id . '. Republishing.');
        }

        if ($op === 'delete') {
            if ($handle === '') {
                return $this->dash->redirect($base, '', 'No component specified.');
            }
            $referenced = $this->hooks->referencing($site->handle, $handle)
                || $this->components->referencedByContent($site, $handle);
            $this->components->delete($site, $handle);
            if ($referenced) {
                $this->publish->trigger($site, 'publish: referenced component removed (dashboard)', 'dashboard');
            }

            return $this->dash->redirect($base, 'Deleted component "' . $handle . '".' . ($referenced ? ' It was in use — republishing.' : ''));
        }

        // Optional variables_schema is a JSON object typed into the form.
        $schemaRaw = trim((string) ($body['variables_schema'] ?? ''));
        $schema = [];
        if ($schemaRaw !== '') {
            $decoded = json_decode($schemaRaw, true);
            if (!\is_array($decoded)) {
                return $this->dash->redirect($base, '', 'Variables schema must be valid JSON.');
            }
            $schema = $decoded;
        }

        try {
            $component = $this->components->upsert($site, $handle, [
                'type' => (string) ($body['type'] ?? 'html'),
                'source_code' => (string) ($body['source_code'] ?? ''),
                'css' => (string) ($body['css'] ?? ''),
                'variables_schema' => $schema,
                // An unchecked checkbox is ABSENT from a POST — pass the
                // explicit '0' so turning verbatim off is never made by
                // omission (the ftp_verify_cert rule).
                'verbatim' => (string) ($body['verbatim'] ?? '0'),
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->dash->redirect($base, '', $e->getMessage());
        }

        $note = 'Saved component "' . $component->handle . '" (' . $component->status . ').';
        if ($component->warnings !== []) {
            $note .= ' ' . \count($component->warnings) . ' warning(s).';
        }
        // A referenced component appears on pages whose content never changed
        // (hook-injected pages have no content row at all) — republish now so
        // the edit actually reaches the live site.
        if ($this->hooks->referencing($site->handle, $component->handle) || $this->components->referencedByContent($site, $component->handle)) {
            $this->publish->trigger($site, 'publish: referenced component changed (dashboard)', 'dashboard');
            $note .= ' In use — republishing.';
        }

        return $this->dash->redirect($base, $note);
    }
}
