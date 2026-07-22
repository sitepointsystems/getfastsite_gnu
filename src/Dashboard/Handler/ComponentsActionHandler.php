<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Component\Service\ComponentService;
use Fastsite\Dashboard\Dash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /sites/{site}/components/action — create/update (op=save) or remove
 * (op=delete) a component. React sources compile here; a compile or lint
 * error is surfaced as a flash, never a 500.
 */
final class ComponentsActionHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly ComponentService $components,
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

        if ($op === 'delete') {
            if ($handle === '') {
                return $this->dash->redirect($base, '', 'No component specified.');
            }
            $this->components->delete($site, $handle);

            return $this->dash->redirect($base, 'Deleted component "' . $handle . '".');
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
            ]);
        } catch (\InvalidArgumentException $e) {
            return $this->dash->redirect($base, '', $e->getMessage());
        }

        $note = 'Saved component "' . $component->handle . '" (' . $component->status . ').';
        if ($component->warnings !== []) {
            $note .= ' ' . \count($component->warnings) . ' warning(s).';
        }

        return $this->dash->redirect($base, $note);
    }
}
