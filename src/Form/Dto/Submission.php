<?php

declare(strict_types=1);

namespace Fastsite\Form\Dto;

/**
 * One stored submission. It is written BEFORE any send is attempted, and that
 * ordering is the whole reliability story: SMTP is configured per site and can
 * be wrong, expired, or blocked by a provider that turned off basic auth. With
 * the row on disk first, a broken mail config is a delayed notification. With
 * the send first, it is a lead that never existed and nobody ever knew about.
 */
final readonly class Submission
{
    /**
     * @param array<string, string> $payload
     */
    public function __construct(
        public int $id,
        public int $formId,
        public string $locale,
        public string $pagePath,
        public array $payload,
        public string $replyTo,
        public string $ipHash,
        public string $userAgent,
        public string $status,
        public int $attempts,
        public string $error,
        public string $trace,
        public string $createdAt,
        public ?string $sentAt,
        /**
         * What the content scorer made of this submission.
         *
         * Kept on EVERY row, not just flagged ones, because the scores of the
         * submissions that PASSED are the only way to tell a threshold that is
         * working from one that is one point away from eating real leads.
         *
         * @var int
         */
        public int $spamScore = 0,
        /** @var list<string> */
        public array $spamReasons = [],
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        /** @var array<string, string> $payload */
        $payload = [];
        $decoded = json_decode((string) ($row['payload'] ?? '{}'), true);
        if (\is_array($decoded)) {
            foreach ($decoded as $key => $value) {
                $payload[(string) $key] = \is_scalar($value) ? (string) $value : '';
            }
        }

        return new self(
            id: (int) $row['id'],
            formId: (int) $row['form_id'],
            locale: (string) ($row['locale'] ?? ''),
            pagePath: (string) ($row['page_path'] ?? ''),
            payload: $payload,
            replyTo: (string) ($row['reply_to'] ?? ''),
            ipHash: (string) ($row['ip_hash'] ?? ''),
            userAgent: (string) ($row['user_agent'] ?? ''),
            status: (string) ($row['status'] ?? 'pending'),
            attempts: (int) ($row['attempts'] ?? 0),
            error: (string) ($row['error'] ?? ''),
            trace: (string) ($row['trace'] ?? ''),
            createdAt: (string) ($row['created_at'] ?? ''),
            sentAt: ($row['sent_at'] ?? null) !== null ? (string) $row['sent_at'] : null,
            spamScore: (int) ($row['spam_score'] ?? 0),
            spamReasons: self::decodeReasons($row['spam_reasons'] ?? '[]'),
        );
    }

    /** @return list<string> */
    private static function decodeReasons(mixed $raw): array
    {
        $decoded = json_decode(\is_string($raw) ? $raw : '[]', true);
        if (!\is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn ($r): string => \is_scalar($r) ? (string) $r : '', $decoded),
            static fn (string $r): bool => $r !== '',
        ));
    }
}
