<?php

declare(strict_types=1);

namespace Fastsite\Form\Dto;

/**
 * One input on a form. Deliberately a small, closed set of types: every type
 * here maps to a native HTML input that validates in the browser with no
 * JavaScript, which is what lets a static page reject a malformed email
 * before it ever reaches us.
 *
 * `hidden` carries a value the PAGE supplies rather than the visitor — a
 * computed total from a bespoke widget, a campaign tag, which variant of a
 * landing page this was. It was left out at first on the grounds that a hidden
 * value is indistinguishable from one a bot supplied; that reasoning was
 * wrong. The only thing worth protecting is the recipient, and that comes from
 * the stored definition and can never be read from the request at all — so a
 * hidden field is no more trusted, and no less, than a text field anyone can
 * edit with devtools. Every submitted value is untrusted either way.
 */
final readonly class FormField
{
    public const TYPES = ['text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'checkbox', 'hidden'];

    /**
     * @param list<string> $options select choices, ignored for other types
     */
    public function __construct(
        public string $name,
        public string $type,
        public string $label,
        public bool $required = false,
        public string $placeholder = '',
        public array $options = [],
        public bool $wide = true,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromArray(array $row): self
    {
        $type = (string) ($row['type'] ?? 'text');
        if (!\in_array($type, self::TYPES, true)) {
            $type = 'text';
        }

        /** @var list<string> $options */
        $options = array_values(array_filter(
            array_map(
                static fn (mixed $o): string => trim((string) $o),
                (array) ($row['options'] ?? []),
            ),
            static fn (string $o): bool => $o !== '',
        ));

        $name = self::slug((string) ($row['name'] ?? ''));
        $label = trim((string) ($row['label'] ?? ''));

        return new self(
            name: $name,
            type: $type,
            label: $label !== '' ? $label : ucfirst(str_replace('_', ' ', $name)),
            required: (bool) ($row['required'] ?? false),
            placeholder: mb_substr(trim((string) ($row['placeholder'] ?? '')), 0, 200),
            options: $options,
            wide: (bool) ($row['wide'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'label' => $this->label,
            'required' => $this->required,
            'placeholder' => $this->placeholder,
            'options' => $this->options,
            'wide' => $this->wide,
        ];
    }

    /**
     * A field name becomes an HTML `name` attribute and a key in the stored
     * payload, so it is normalised to a conservative slug rather than trusted.
     * The `_fs_` prefix is reserved for the engine's own control fields, and a
     * field that claimed it could shadow them.
     */
    public static function slug(string $value): string
    {
        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        if (str_starts_with($slug, '_fs_') || $slug === '') {
            $slug = 'field_' . substr(md5($value), 0, 6);
        }

        return mb_substr($slug, 0, 40);
    }
}
