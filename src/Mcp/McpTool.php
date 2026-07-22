<?php

declare(strict_types=1);

namespace Fastsite\Mcp;

use Fastsite\Access\AccessContext;

/**
 * One MCP tool: its advertised schema plus the scope it needs and the
 * closure that runs it. The closure returns a plain array that becomes the
 * tool result content.
 */
final readonly class McpTool
{
    /**
     * @param array<string, mixed> $inputSchema JSON Schema for arguments
     * @param \Closure(AccessContext, array<string, mixed>): (string|array<string, mixed>) $handler
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public ?string $scope,
        public \Closure $handler,
    ) {
    }
}
