<?php

declare(strict_types=1);

namespace Fastsite\Webhook\Mapper;

final class WebhookMapperRegistry
{
    /** @var array<string, WebhookMapper> */
    private array $mappers = [];

    /**
     * @param iterable<WebhookMapper> $mappers
     */
    public function __construct(iterable $mappers)
    {
        foreach ($mappers as $mapper) {
            $this->mappers[$mapper::key()] = $mapper;
        }
    }

    public function get(string $key): WebhookMapper
    {
        return $this->mappers[$key]
            ?? throw new \RuntimeException('No webhook mapper registered for: ' . $key);
    }

    public function has(string $key): bool
    {
        return isset($this->mappers[$key]);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->mappers);
    }
}
