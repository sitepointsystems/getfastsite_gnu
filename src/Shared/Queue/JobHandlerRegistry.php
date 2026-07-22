<?php

declare(strict_types=1);

namespace Fastsite\Shared\Queue;

final class JobHandlerRegistry
{
    /** @var array<string, JobHandler> */
    private array $handlers = [];

    /**
     * @param iterable<JobHandler> $handlers
     */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $this->handlers[$handler::type()] = $handler;
        }
    }

    public function for(string $type): JobHandler
    {
        return $this->handlers[$type]
            ?? throw new \RuntimeException('No handler registered for job type: ' . $type);
    }
}
