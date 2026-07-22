<?php

declare(strict_types=1);

namespace Fastsite\Mcp;

use Fastsite\Access\AccessContext;

final class McpToolRegistry
{
    /** @var array<string, McpTool> */
    private array $tools = [];

    public function __construct(McpTools $tools)
    {
        foreach ($tools->all() as $tool) {
            $this->tools[$tool->name] = $tool;
        }
    }

    /**
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function list(): array
    {
        return array_values(array_map(
            static fn (McpTool $t): array => ['name' => $t->name, 'description' => $t->description, 'inputSchema' => $t->inputSchema],
            $this->tools,
        ));
    }

    /**
     * Runs a tool with scope enforcement.
     *
     * @param array<string, mixed> $args
     * @return string|array<string, mixed>
     */
    public function call(string $name, array $args, AccessContext $access): string|array
    {
        $tool = $this->tools[$name] ?? throw new McpToolException('Unknown tool: ' . $name);
        if ($tool->scope !== null && !$access->hasScope($tool->scope)) {
            throw new McpToolException('This token lacks the required scope: ' . $tool->scope);
        }

        return ($tool->handler)($access, $args);
    }
}
