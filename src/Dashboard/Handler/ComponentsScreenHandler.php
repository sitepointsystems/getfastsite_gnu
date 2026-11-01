<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Component\Service\ComponentHookService;
use Fastsite\Component\Service\ComponentService;
use Fastsite\Dashboard\Dash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/components — author reusable components (html {{var}}
 * fragments or react → static SSR). Embedded in content with
 * <div data-fs-component="handle" data-values='{…}'></div>, or injected
 * site-wide by a component hook (a CTA after the first paragraph of every
 * post) without touching content. ?hook={id} prefills the hook form;
 * ?component={handle} prefills the component form with its stored source.
 */
final class ComponentsScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly ComponentService $components,
        private readonly ComponentHookService $hooks,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $site = $this->dash->siteFor($request, (string) $request->getAttribute('site'));
        if ($site === null) {
            return $this->dash->notFound();
        }

        $hooks = $this->hooks->list($site->handle);

        $editId = (int) (($request->getQueryParams()['hook'] ?? 0));
        $editHook = null;
        foreach ($hooks as $hook) {
            if ($hook->id === $editId) {
                $editHook = $hook;
                break;
            }
        }

        $components = $this->components->list($site);

        // ?component={handle} — prefill the form with the stored source so
        // editing means editing, not retyping the whole component.
        $editHandle = trim((string) ($request->getQueryParams()['component'] ?? ''));
        $editComponent = null;
        foreach ($components as $component) {
            if ($component->handle === $editHandle) {
                $editComponent = $component;
                break;
            }
        }

        return $this->dash->render($request, 'components.html.twig', [
            'title' => $site->name . ' components — fastsite',
            'site' => $site,
            'components' => $components,
            'hooks' => $hooks,
            'edit_hook' => $editHook,
            'edit_component' => $editComponent,
        ]);
    }
}
