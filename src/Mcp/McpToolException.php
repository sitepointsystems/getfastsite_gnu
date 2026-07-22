<?php

declare(strict_types=1);

namespace Fastsite\Mcp;

/**
 * A tool-level error surfaced to the MCP client as an isError result
 * (not a protocol error).
 */
final class McpToolException extends \RuntimeException
{
}
