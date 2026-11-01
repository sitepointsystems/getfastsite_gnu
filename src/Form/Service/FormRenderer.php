<?php

declare(strict_types=1);

namespace Fastsite\Form\Service;

use Fastsite\Form\Dto\Form;
use Fastsite\Form\Dto\FormField;
use Fastsite\Site\Dto\Site;

/**
 * Expands `<div data-fs-form="contact"></div>` into a real, working form at
 * build time.
 *
 * WHY A PLACEHOLDER AND NOT AUTHORED MARKUP: ContentSanitizer strips `<form>`,
 * `<input>` and `<button>` from body HTML on every ingest path, so a form typed
 * into a page silently disappears with a WARN nobody reads. An inert div
 * carrying data-* attributes survives sanitising untouched (all data-* are
 * preserved on every element by contract), and the real markup is generated
 * here, after sanitising, from a definition the site owner controls.
 *
 * ZERO JAVASCRIPT, DELIBERATELY. The form is a native POST to the engine, and
 * every constraint the visitor sees enforced — required, email, url, number,
 * one-of-these-options — is a native HTML attribute the browser checks before
 * anything is sent. The engine re-checks all of it (FormService::validate),
 * because browser validation is a convenience, never a control. This is what
 * keeps a page with a contact form scoring the same as one without.
 *
 * NO CSP CHANGE IS NEEDED, and that is not luck: the build's Content-Security-
 * Policy sets only script-src and frame-ancestors, and `form-action` has no
 * default-src fallback in CSP3 — an absent directive means forms are
 * unrestricted. Adding a form-action directive later would break every site
 * with a form, since the action points at the engine's origin, not the site's.
 *
 * STYLING CONTRACT, copied from LangSwitcher because it earned it: every
 * visual default is wrapped in `:where()`, which has ZERO specificity, so any
 * real selector in the shell's CSS overrides it with no !important. Only
 * layout mechanics keep normal specificity. The --fs-form-* custom properties
 * theme it without writing selectors at all.
 */
final class FormRenderer
{
    /**
     * The honeypot. A real input, positioned off-screen, tabindex="-1",
     * aria-hidden, autocomplete off — no human sees or focuses it, and generic
     * spam bots fill every input they find. The `_fs_` prefix is reserved
     * (FormField::slug rejects it), so this can never collide with a site's
     * own field, which is exactly why it is not named something "plausible".
     *
     * Note what is NOT here: a submitted-too-fast timestamp check. The page is
     * static and cached, so any timestamp baked into it is the BUILD time, not
     * the page-load time, and the elapsed figure would be meaningless. It is
     * the obvious next idea and it does not work on this architecture.
     */
    public const HONEYPOT = '_fs_hp';

    public const CONSENT = '_fs_consent';
    public const PAGE = '_fs_page';
    public const LOCALE = '_fs_locale';
    public const RETURN_TO = '_fs_return';

    public function __construct(
        private readonly FormService $forms,
        private readonly string $engineBaseUrl,
    ) {
    }

    /**
     * Replaces every form placeholder in a fragment.
     *
     * @return array{html: string, css: string, warnings: list<string>, handles: list<string>}
     */
    public function expand(Site $site, string $html, string $locale, string $pagePath = ''): array
    {
        if (!str_contains($html, 'data-fs-form')) {
            return ['html' => $html, 'css' => '', 'warnings' => [], 'handles' => []];
        }

        $warnings = [];
        $used = [];

        // APP_URL is baked into every rendered form's action, and the build has
        // no request to derive a public origin from the way PublicUrl does for
        // OAuth discovery. Left at its default, every form on the live site
        // would post to localhost and do nothing at all, with no error anyone
        // could see. Say so loudly at build time; doctor reports it too.
        if (self::looksLocal($this->engineBaseUrl)) {
            $warnings[] = 'APP_URL is ' . $this->engineBaseUrl . ', so this form posts to an address no visitor can reach. '
                . 'Set APP_URL to the engine\'s real public URL and republish.';
        }

        $out = preg_replace_callback(
            '#<div\b[^>]*\bdata-fs-form=(["\'])(.*?)\1[^>]*>\s*</div>#is',
            function (array $m) use ($site, $locale, $pagePath, &$warnings, &$used): string {
                $handle = trim($m[2]);
                $form = $this->forms->find($site->handle, $handle);
                if ($form === null) {
                    $warnings[] = 'No form named "' . $handle . '" — placeholder left empty.';

                    return '';
                }
                if (!$form->enabled) {
                    $warnings[] = 'Form "' . $handle . '" is disabled — placeholder left empty.';

                    return '';
                }
                $used[$handle] = true;

                return $this->renderForm($site, $form, $locale, $pagePath);
            },
            $html,
        );

        $html = $out ?? $html;
        $handles = array_keys($used);

        return [
            'html' => $html,
            'css' => $handles === [] ? '' : self::css(),
            'warnings' => $warnings,
            'handles' => $handles,
        ];
    }

    /**
     * A base URL no outside visitor could reach. Mirrors PublicUrl::isLocalish
     * — the same question, asked at build time instead of request time.
     */
    public static function looksLocal(string $base): bool
    {
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));

        return $host === ''
            || $host === 'localhost'
            || $host === '127.0.0.1'
            || $host === '0.0.0.0'
            || $host === '::1'
            || str_ends_with($host, '.local');
    }

    /** The complete `<form>` element for one definition. */
    public function renderForm(Site $site, Form $form, string $locale, string $pagePath = ''): string
    {
        $action = rtrim($this->engineBaseUrl, '/') . '/f/'
            . rawurlencode($site->handle) . '/' . rawurlencode($form->handle);

        $idBase = 'fs-' . $form->handle;
        $body = '';
        $hiddenFields = '';

        foreach ($form->fields as $field) {
            // Hidden fields carry a page-supplied value and have no label, so
            // they go OUTSIDE the grid — inside it each would occupy a cell
            // and blow a hole in the two-column layout. The stored
            // `placeholder` is their default value; a bespoke widget's script
            // overwrites it before submit.
            if ($field->type === 'hidden') {
                $hiddenFields .= '<input type="hidden" name="' . $this->attr($field->name) . '"'
                    . ' value="' . $this->attr($field->placeholder) . '">';
                continue;
            }
            $body .= $this->renderField($form, $field, $idBase);
        }

        if ($form->consentText !== '') {
            $consentId = $idBase . '-consent';
            $body .= '<div class="fs-form-row fs-form-row--wide fs-form-consent">'
                . '<input type="checkbox" id="' . $this->attr($consentId) . '" name="' . self::CONSENT . '" value="1" required>'
                . '<label for="' . $this->attr($consentId) . '">' . $this->text($form->consentText) . '</label>'
                . '</div>';
        }

        $label = $form->submitLabel !== '' ? $form->submitLabel : 'Send';

        // The honeypot sits AFTER the real fields and before the button:
        // where a bot filling sequentially will meet it.
        $honeypot = '<div class="fs-form-hp" aria-hidden="true">'
            . '<label for="' . $this->attr($idBase . '-hp') . '">Leave this field empty</label>'
            . '<input type="text" id="' . $this->attr($idBase . '-hp') . '" name="' . self::HONEYPOT . '"'
            . ' tabindex="-1" autocomplete="off">'
            . '</div>';

        $hidden = '<input type="hidden" name="' . self::PAGE . '" value="' . $this->attr($pagePath) . '">'
            . '<input type="hidden" name="' . self::LOCALE . '" value="' . $this->attr($locale) . '">'
            . '<input type="hidden" name="' . self::RETURN_TO . '" value="' . $this->attr($this->returnUrl($site, $pagePath)) . '">'
            . $hiddenFields;

        return '<form class="fs-form" id="' . $this->attr($idBase) . '" method="post"'
            . ' action="' . $this->attr($action) . '"'
            . ' data-fs-form-name="' . $this->attr($form->handle) . '">'
            . $hidden
            . '<div class="fs-form-grid">' . $body . '</div>'
            . $honeypot
            . $this->turnstileWidget($site, $form)
            . '<div class="fs-form-actions">'
            . '<button type="submit" class="fs-form-submit">' . $this->text($label) . '</button>'
            . '</div>'
            . '</form>';
    }

    /**
     * The Turnstile widget, when this form asks for it AND the site has both
     * halves of the key pair.
     *
     * BOTH halves are required. A site key with no secret renders a widget the
     * engine can never verify — a form that looks protected, is not, and gives
     * nobody any reason to look. Rendering nothing is the honest failure, and
     * the Forms screen says so where someone will see it.
     *
     * The widget is wrapped in `<div data-fs-embed>` for the same reason every
     * other trusted script region is: PageAudit depth-strips those before its
     * zero-JS and external-resource checks, so an opted-in form does not fail
     * the build that every other page still has to pass. The CSP follows
     * automatically — BuildService::scriptDemand scans the rendered page for
     * `<script src` and flips `_headers` to `full` for that site.
     */
    private function turnstileWidget(Site $site, Form $form): string
    {
        if (!$form->turnstile || !$site->turnstileConfigured()) {
            return '';
        }

        return '<div data-fs-embed class="fs-form-challenge">'
            . '<div class="cf-turnstile" data-sitekey="' . $this->attr($site->turnstileSiteKey) . '"'
            . ' data-theme="auto" data-size="flexible"></div>'
            . '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
            . '</div>';
    }

    private function renderField(Form $form, FormField $field, string $idBase): string
    {
        $id = $idBase . '-' . $field->name;
        $name = $this->attr($field->name);
        $required = $field->required ? ' required' : '';
        $placeholder = $field->placeholder !== '' ? ' placeholder="' . $this->attr($field->placeholder) . '"' : '';
        $rowClass = 'fs-form-row' . ($field->wide ? ' fs-form-row--wide' : '');

        if ($field->type === 'checkbox') {
            return '<div class="' . $rowClass . ' fs-form-check">'
                . '<input type="checkbox" id="' . $this->attr($id) . '" name="' . $name . '" value="yes"' . $required . '>'
                . '<label for="' . $this->attr($id) . '">' . $this->text($field->label) . $this->requiredMark($field) . '</label>'
                . '</div>';
        }

        $label = '<label for="' . $this->attr($id) . '">' . $this->text($field->label) . $this->requiredMark($field) . '</label>';

        if ($field->type === 'textarea') {
            $control = '<textarea id="' . $this->attr($id) . '" name="' . $name . '" rows="6"'
                . $placeholder . $required . '></textarea>';
        } elseif ($field->type === 'select') {
            $options = '<option value="">' . $this->text($field->placeholder !== '' ? $field->placeholder : 'Choose…') . '</option>';
            foreach ($field->options as $option) {
                $options .= '<option value="' . $this->attr($option) . '">' . $this->text($option) . '</option>';
            }
            $control = '<select id="' . $this->attr($id) . '" name="' . $name . '"' . $required . '>' . $options . '</select>';
        } else {
            // autocomplete tokens are a real accessibility win and cost nothing:
            // they let a browser fill a contact form in one tap.
            $auto = match ($field->type) {
                'email' => ' autocomplete="email"',
                'tel' => ' autocomplete="tel"',
                'url' => ' autocomplete="url"',
                default => preg_match('/name|navn/i', $field->name) === 1 ? ' autocomplete="name"' : '',
            };
            $control = '<input type="' . $this->attr($field->type) . '" id="' . $this->attr($id) . '"'
                . ' name="' . $name . '"' . $placeholder . $auto . $required . '>';
        }

        return '<div class="' . $rowClass . '">' . $label . $control . '</div>';
    }

    private function requiredMark(FormField $field): string
    {
        return $field->required ? '<span class="fs-form-req" aria-hidden="true">*</span>' : '';
    }

    /**
     * Where the visitor is sent back to when something goes wrong. Absolute,
     * on the site's own canonical origin — the handler re-checks it against
     * that origin before redirecting, so a posted value can never bounce a
     * visitor off the site.
     */
    private function returnUrl(Site $site, string $pagePath): string
    {
        $base = rtrim($site->canonicalBaseUrl(), '/');
        if ($base === '') {
            return '';
        }

        return $base . '/' . ltrim($pagePath, '/');
    }

    private function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function text(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Visual defaults at ZERO specificity, so the shell's own CSS wins without
     * !important. Only the honeypot's concealment keeps real specificity — a
     * stray reset that revealed it would put a nonsense field on the page.
     */
    public static function css(): string
    {
        return <<<'CSS'
        .fs-form-hp{position:absolute!important;left:-9999px!important;width:1px!important;height:1px!important;overflow:hidden!important}
        :where(.fs-form){--fs-form-gap:1rem;--fs-form-radius:.5rem;--fs-form-border:currentColor;--fs-form-accent:currentColor;display:block;margin:1.5rem 0}
        :where(.fs-form .fs-form-grid){display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--fs-form-gap)}
        :where(.fs-form .fs-form-row){display:flex;flex-direction:column;gap:.35rem;min-width:0}
        :where(.fs-form .fs-form-row--wide){grid-column:1/-1}
        :where(.fs-form label){font-size:.9em;line-height:1.3}
        :where(.fs-form .fs-form-req){opacity:.6;margin-left:.15em}
        :where(.fs-form input,.fs-form textarea,.fs-form select){font:inherit;color:inherit;width:100%;box-sizing:border-box;padding:.6em .75em;background:var(--fs-form-bg,transparent);border:1px solid var(--fs-form-border);border-radius:var(--fs-form-radius);opacity:.95}
        :where(.fs-form textarea){resize:vertical;min-height:8em}
        :where(.fs-form input:focus-visible,.fs-form textarea:focus-visible,.fs-form select:focus-visible){outline:2px solid var(--fs-form-accent);outline-offset:1px}
        :where(.fs-form .fs-form-check,.fs-form .fs-form-consent){flex-direction:row;align-items:flex-start;gap:.5rem}
        :where(.fs-form .fs-form-check input,.fs-form .fs-form-consent input){width:auto;flex:none;margin-top:.2em}
        :where(.fs-form .fs-form-actions){margin-top:var(--fs-form-gap)}
        :where(.fs-form .fs-form-submit){font:inherit;cursor:pointer;padding:.7em 1.4em;border:1px solid var(--fs-form-border);border-radius:var(--fs-form-radius);background:var(--fs-form-accent);color:var(--fs-form-submit-fg,Canvas)}
        @media (max-width:640px){:where(.fs-form .fs-form-grid){grid-template-columns:1fr}}
        CSS;
    }
}
