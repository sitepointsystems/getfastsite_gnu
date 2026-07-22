<?php

declare(strict_types=1);

namespace Fastsite\Mcp;

use Fastsite\Access\AccessContext;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * Streamable-HTTP MCP endpoint (JSON-RPC 2.0 over POST). Implements the
 * core methods an MCP client needs: initialize, tools/list, tools/call,
 * ping. Notifications (no id) get a 202 with no body.
 */
final class McpHandler implements RequestHandlerInterface
{
    private const PROTOCOL_VERSION = '2025-06-18';
    private const MAX_BATCH = 20;

    public function __construct(
        private readonly McpToolRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var AccessContext $access */
        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);

        $payload = json_decode((string) $request->getBody(), true);
        if (!\is_array($payload)) {
            return $this->rpcError(null, -32700, 'Parse error');
        }

        // Batch requests: an array of message objects. Bounded so one
        // request can't drive an unbounded number of tool executions.
        if (array_is_list($payload)) {
            if (\count($payload) > self::MAX_BATCH) {
                return $this->rpcError(null, -32600, 'Batch too large (max ' . self::MAX_BATCH . ').');
            }
            $responses = [];
            foreach ($payload as $message) {
                $r = \is_array($message) ? $this->dispatch($message, $access) : $this->errorObject(null, -32600, 'Invalid request');
                if ($r !== null) {
                    $responses[] = $r;
                }
            }

            return $responses === [] ? new Response(202) : $this->json($responses);
        }

        $response = $this->dispatch($payload, $access);

        return $response === null ? new Response(202) : $this->json($response);
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null null for a notification (no reply)
     */
    private function dispatch(array $message, AccessContext $access): ?array
    {
        $id = $message['id'] ?? null;
        $method = (string) ($message['method'] ?? '');
        $params = \is_array($message['params'] ?? null) ? $message['params'] : [];

        // Notifications have no id → no response.
        if ($id === null && str_starts_with($method, 'notifications/')) {
            return null;
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'fastsite', 'version' => '1.0'],
                'instructions' => 'fastsite turns content into fully-static, 100/100/100/100-PageSpeed sites. '
                    . 'BEFORE designing a shell or creating any content, call the `get_authoring_guide` tool '
                    . '(pass a `site` for its locales + embed syntax) and follow it — it covers the body-only '
                    . 'content model, required image dimensions and recommended sizes per role, zero-JS/SEO/a11y '
                    . 'rules, and the `<img data-media-id>` / `<div data-fs-component>` embed syntax. Reference '
                    . 'images by media id from `upload_media_from_url` (never external URLs); alt text is required. '
                    . 'On multilingual shells the language switcher is NEVER inserted automatically — call '
                    . '`get_language_switcher` for the placement markers (`<!--fs:languages-->` / `<li data-fs-languages></li>`), '
                    . 'the rendered dropdown HTML, and a customizable Twig variant, then place it where the design wants it.',
            ]),
            'ping' => $this->result($id, new \stdClass()),
            'tools/list' => $this->result($id, ['tools' => $this->registry->list()]),
            'tools/call' => $this->callTool($id, $params, $access),
            default => $id === null ? null : $this->errorObject($id, -32601, 'Method not found: ' . $method),
        };
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, array $params, AccessContext $access): array
    {
        $name = (string) ($params['name'] ?? '');
        $args = \is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $result = $this->registry->call($name, $args, $access);
            $text = \is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => (string) $text]],
                'isError' => false,
            ]);
        } catch (McpToolException $e) {
            // Tool errors are results with isError:true, NOT protocol errors.
            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                'isError' => true,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('MCP tool crashed', ['tool' => $name, 'error' => $e->getMessage()]);

            return $this->result($id, [
                'content' => [['type' => 'text', 'text' => 'Internal error running tool.']],
                'isError' => true,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorObject(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function rpcError(mixed $id, int $code, string $message): ResponseInterface
    {
        return $this->json($this->errorObject($id, $code, $message));
    }

    /**
     * @param array<mixed> $data
     */
    private function json(array $data): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
