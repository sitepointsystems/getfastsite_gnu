<?php

declare(strict_types=1);

namespace Fastsite\Access\Middleware;

use Fastsite\Access\AccessContext;
use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Shared\Http\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Honors the Idempotency-Key header on POST. The key is RESERVED (row
 * inserted with a pending sentinel) BEFORE the handler runs, so two
 * concurrent identical POSTs cannot both execute the side effect — the
 * loser sees the reservation. An exact replay returns the stored response;
 * the same key with a different body is a 422; an in-flight duplicate is a
 * 409. Keys are scoped per API key and swept after 24h.
 */
final class IdempotencyMiddleware implements MiddlewareInterface
{
    private const PENDING = 0;

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $idempotencyKey = $request->getHeaderLine('Idempotency-Key');
        if ($request->getMethod() !== 'POST' || $idempotencyKey === '') {
            return $handler->handle($request);
        }

        $access = $request->getAttribute(AccessContext::REQUEST_ATTRIBUTE);
        $actor = $access instanceof AccessContext ? (string) $access->apiKeyId : 'anon';

        $keyHash = hash('sha256', $actor . ':' . $idempotencyKey);
        $endpoint = $request->getMethod() . ' ' . $request->getUri()->getPath();
        $requestHash = hash('sha256', $endpoint . "\x1f" . (string) $request->getBody());

        $db = $this->databases->engine();

        // Try to CLAIM the key atomically before doing any work.
        $claimed = $db->executeStatement(
            'INSERT INTO idempotency_keys (key_hash, endpoint, request_hash, response_status, response_body, created_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (key_hash) DO NOTHING',
            [$keyHash, $endpoint, $requestHash, self::PENDING, '', $this->clock->iso()],
        );

        if ($claimed !== 1) {
            return $this->replayOrConflict($db, $keyHash, $requestHash);
        }

        // We own the key — run the handler, then finalize or release.
        try {
            $response = $handler->handle($request);
        } catch (\Throwable $e) {
            $db->executeStatement('DELETE FROM idempotency_keys WHERE key_hash = ?', [$keyHash]);
            throw $e;
        }

        $status = $response->getStatusCode();
        if ($status >= 500 || $status === 429) {
            // Not conclusive — release so a genuine retry is not blocked.
            $db->executeStatement('DELETE FROM idempotency_keys WHERE key_hash = ?', [$keyHash]);

            return $response;
        }

        $db->executeStatement(
            'UPDATE idempotency_keys SET response_status = ?, response_body = ? WHERE key_hash = ?',
            [$status, (string) $response->getBody(), $keyHash],
        );

        return $response;
    }

    private function replayOrConflict(\Doctrine\DBAL\Connection $db, string $keyHash, string $requestHash): ResponseInterface
    {
        $row = $db->fetchAssociative('SELECT * FROM idempotency_keys WHERE key_hash = ?', [$keyHash]);
        if ($row === false) {
            // Reservation vanished (released between our conflict and read) —
            // ask the client to retry rather than double-executing.
            return ApiResponse::error('Idempotency-Key is being processed — retry shortly', 409);
        }

        if ((string) $row['request_hash'] !== $requestHash) {
            return ApiResponse::error('Idempotency-Key was already used with a different request', 422);
        }

        if ((int) $row['response_status'] === self::PENDING) {
            return ApiResponse::error('A request with this Idempotency-Key is already being processed', 409)
                ->withHeader('Retry-After', '2');
        }

        return ApiResponse::json(
            (array) json_decode((string) $row['response_body'], true),
            (int) $row['response_status'],
        )->withHeader('Idempotency-Replayed', 'true');
    }
}
