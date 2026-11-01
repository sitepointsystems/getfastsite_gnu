<?php

declare(strict_types=1);

namespace Fastsite\Http;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Renders the engine's own UI templates (OAuth login/consent, dashboards)
 * from templates/. These are first-party, so no sandbox — unlike the
 * agent-authored site shells the build renders.
 */
final class EngineView
{
    private ?Environment $twig = null;

    public function __construct(private readonly string $appRoot)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context = []): string
    {
        return $this->twig()->render($template, $context);
    }

    private function twig(): Environment
    {
        if ($this->twig === null) {
            $this->twig = new Environment(new FilesystemLoader($this->appRoot . '/templates/engine'), [
                'cache' => false,
                'autoescape' => 'html',
                'strict_variables' => false,
            ]);
            // Brand assets are optional: drop the files into public/brand/ and
            // every engine screen (login, sidebar, favicon) picks them up. No
            // files → templates fall back to the text brand. Cache-busted by
            // mtime so a re-uploaded logo isn't stuck behind the browser cache.
            $this->twig->addGlobal('brand', $this->brandContext());

            // Locale display helpers: {{ locale_flag('da') }} → 🇩🇰,
            // {{ locale_label('da') }} → "🇩🇰 Danish". Code stays the value.
            $this->twig->addFunction(new \Twig\TwigFunction('locale_flag', [\Fastsite\Shared\Locales::class, 'flag']));
            $this->twig->addFunction(new \Twig\TwigFunction('locale_name', [\Fastsite\Shared\Locales::class, 'name']));
            $this->twig->addFunction(new \Twig\TwigFunction('locale_label', [\Fastsite\Shared\Locales::class, 'label']));
            // Real flag SVG URL (public/flags/{cc}.svg) — the icon used everywhere.
            $this->twig->addFunction(new \Twig\TwigFunction('locale_flag_url', [\Fastsite\Shared\Locales::class, 'flagUrl']));

            // api_keys.scopes and .sites are stored as a JSON array and the key
            // tables select the raw column, so printing one verbatim put an
            // unbreakable ~120-character string in a table cell — which is what
            // used to push the Access table past the page width. Decoded here
            // rather than in each screen handler so both tables render from one
            // rule; pairs with _chiplist.html.twig.
            $this->twig->addFilter(new \Twig\TwigFilter(
                'json_list',
                /** @return list<string> */
                static function (mixed $value): array {
                    if (\is_string($value)) {
                        $value = $value === '' ? [] : json_decode($value, true);
                    }
                    if (!\is_array($value)) {
                        return [];
                    }
                    $out = [];
                    foreach ($value as $item) {
                        if (\is_scalar($item)) {
                            $out[] = (string) $item;
                        }
                    }

                    return $out;
                },
            ));
        }

        return $this->twig;
    }

    /**
     * @return array{has_icon: bool, has_logo: bool, icon_url: string, logo_url: string}
     */
    private function brandContext(): array
    {
        $dir = $this->appRoot . '/public/brand';
        $icon = $this->firstExisting($dir, ['fastsite-icon.png', 'fastsite-icon.svg', 'icon.png', 'icon.svg']);
        $logo = $this->firstExisting($dir, ['fastsite-logo.png', 'fastsite-logo.svg', 'logo.png', 'logo.svg']);

        return [
            'has_icon' => $icon !== null,
            'has_logo' => $logo !== null,
            'icon_url' => $icon !== null ? '/brand/' . $icon[0] . '?v=' . $icon[1] : '',
            'logo_url' => $logo !== null ? '/brand/' . $logo[0] . '?v=' . $logo[1] : '',
        ];
    }

    /**
     * @param list<string> $candidates
     * @return array{0: string, 1: int}|null [filename, mtime]
     */
    private function firstExisting(string $dir, array $candidates): ?array
    {
        foreach ($candidates as $name) {
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                return [$name, (int) filemtime($path)];
            }
        }

        return null;
    }
}
