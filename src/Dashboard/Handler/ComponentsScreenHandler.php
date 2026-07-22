<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Component\Service\ComponentService;
use Fastsite\Dashboard\Dash;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /sites/{site}/components — author reusable components (html {{var}}
 * fragments or react → static SSR). Embedded in content with
 * <div data-fs-component="handle" data-values='{…}'></div>.
 */
final class ComponentsScreenHandler implements RequestHandlerInterface
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

        return $this->dash->render($request, 'components.html.twig', [
            'title' => $site->name . ' components — fastsite',
            'site' => $site,
            'components' => $this->components->list($site),
        ]);
    }
}
