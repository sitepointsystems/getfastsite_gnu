<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

final readonly class Job
{
    /**
     * @param array<string, mixed> $payload
     * @param list<array<string, mixed>>|null $chain remaining steps after this job
     */
    public function __construct(
        public int $id,
        public string $uuid,
        public string $type,
        public string $queue,
        public ?string $siteHandle,
        public array $payload,
        public string $status,
        public int $attempts,
        public int $maxAttempts,
        public ?string $dedupeKey,
        public ?array $chain,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row['payload'], true) ?: [];
        $chain = $row['chain'] !== null ? (json_decode((string) $row['chain'], true) ?: null) : null;

        return new self(
            id: (int) $row['id'],
            uuid: (string) $row['uuid'],
            type: (string) $row['type'],
            queue: (string) $row['queue'],
            siteHandle: $row['site_handle'] !== null ? (string) $row['site_handle'] : null,
            payload: $payload,
            status: (string) $row['status'],
            attempts: (int) $row['attempts'],
            maxAttempts: (int) $row['max_attempts'],
            dedupeKey: $row['dedupe_key'] !== null ? (string) $row['dedupe_key'] : null,
            chain: \is_array($chain) ? array_values($chain) : null,
        );
    }
}
