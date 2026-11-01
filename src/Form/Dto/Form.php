<?php

declare(strict_types=1);

namespace Fastsite\Form\Dto;

/**
 * A form DEFINITION, owned by the site. Content embeds it by handle with
 * `<div data-fs-form="contact"></div>` and the build expands that placeholder
 * into real markup.
 *
 * `recipients` lives here and is NEVER read from the submitted request. A form
 * that lets the poster name the destination is an open relay, and the account
 * it would relay through belongs to the customer — their provider would
 * terminate it, not ours.
 */
final readonly class Form
{
    /**
     * @param list<string> $recipients
     * @param list<FormField> $fields
     */
    public function __construct(
        public int $id,
        public string $handle,
        public string $name,
        public array $recipients,
        public string $subject,
        public array $fields,
        public string $submitLabel,
        public string $successPath,
        public string $successMessage,
        public string $consentText,
        public bool $autoresponderEnabled,
        public string $autoresponderSubject,
        public string $autoresponderBody,
        public bool $storeSubmissions,
        public bool $enabled,
        public string $createdAt,
        public string $updatedAt,
        /**
         * Per-form overrides for the engine's own reply messages, merged over
         * the built-in locale pack. Empty = the pack as shipped.
         *
         * @var array<string, string>
         */
        public array $messages = [],
        /**
         * Present a Cloudflare Turnstile challenge on this form.
         *
         * Per form, not per site, and off by default: the widget costs the
         * page third-party JavaScript and flips its CSP to `full`, so it is
         * the escalation for a form actually being hit — not something a site
         * switches on everywhere and pays for on pages nobody is attacking.
         * It also only protects the markup the ENGINE generates; the content
         * scorer is what covers the no-JS path and bespoke forms that post
         * straight to the endpoint.
         */
        public bool $turnstile = false,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        /** @var list<array<string, mixed>> $rawFields */
        $rawFields = (array) (json_decode((string) ($row['fields'] ?? '[]'), true) ?: []);
        $fields = [];
        foreach ($rawFields as $raw) {
            if (\is_array($raw)) {
                $fields[] = FormField::fromArray($raw);
            }
        }

        return new self(
            id: (int) $row['id'],
            handle: (string) $row['handle'],
            name: (string) ($row['name'] ?? ''),
            recipients: self::splitAddresses((string) ($row['recipients'] ?? '')),
            subject: (string) ($row['subject'] ?? ''),
            fields: $fields,
            submitLabel: (string) ($row['submit_label'] ?? ''),
            successPath: (string) ($row['success_path'] ?? ''),
            successMessage: (string) ($row['success_message'] ?? ''),
            consentText: (string) ($row['consent_text'] ?? ''),
            autoresponderEnabled: (bool) ($row['autoresponder_enabled'] ?? false),
            autoresponderSubject: (string) ($row['autoresponder_subject'] ?? ''),
            autoresponderBody: (string) ($row['autoresponder_body'] ?? ''),
            storeSubmissions: (bool) ($row['store_submissions'] ?? true),
            enabled: (bool) ($row['enabled'] ?? true),
            createdAt: (string) ($row['created_at'] ?? ''),
            updatedAt: (string) ($row['updated_at'] ?? ''),
            messages: \Fastsite\Form\FormMessages::sanitizeOverrides($row['messages'] ?? '{}'),
            turnstile: (bool) ($row['turnstile'] ?? false),
        );
    }

    /**
     * Comma / semicolon / newline separated, because people paste address
     * lists in every one of those shapes.
     *
     * @return list<string>
     */
    public static function splitAddresses(string $value): array
    {
        $parts = preg_split('/[,;\r\n]+/', $value) ?: [];
        $out = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '' && !\in_array($part, $out, true)) {
                $out[] = $part;
            }
        }

        return $out;
    }

    public function fieldByName(string $name): ?FormField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The field whose value should become Reply-To: the first email field.
     * This is the whole reason a notification is useful — hitting reply in the
     * mail client has to reach the person who filled the form in.
     */
    public function replyToField(): ?FormField
    {
        foreach ($this->fields as $field) {
            if ($field->type === 'email') {
                return $field;
            }
        }

        return null;
    }

    /** A best-effort display name for the sender, for the Reply-To mailbox. */
    public function nameField(): ?FormField
    {
        foreach ($this->fields as $field) {
            if ($field->type === 'text' && preg_match('/name|navn|nom/i', $field->name . ' ' . $field->label) === 1) {
                return $field;
            }
        }

        return null;
    }

    public function label(): string
    {
        return $this->name !== '' ? $this->name : $this->handle;
    }
}
