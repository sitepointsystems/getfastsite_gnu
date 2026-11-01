<?php

declare(strict_types=1);

namespace Fastsite\Media\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Database\SiteDatabaseManager;

/**
 * Mints and redeems upload tickets — a short-lived, single-use URL that takes
 * raw bytes for one site's media library.
 *
 * The ticket exists because over MCP the bytes would otherwise travel as an
 * argument of the tool call, written out by the model at ~4/3 the size of the
 * file, which caps a transfer at roughly 100 KB and fails by truncating. A
 * ticket moves the bytes onto an ordinary HTTP PUT and leaves the ingest
 * pipeline untouched.
 *
 * The security model is deliberately NOT a new one. A ticket is minted through
 * the same authz as every other site-scoped call (an OAuth token or API key
 * carrying `media:write` plus access to that site), and it records the site and
 * the actor at mint time. The URL holder cannot choose the site, cannot widen
 * the scope and cannot use it twice. It is a capability, not a credential:
 * nothing is stored anywhere that would let someone upload again tomorrow.
 */
final class UploadTicketService
{
    /** 30 minutes: long enough to hand the URL to a sandbox, a script or a person. */
    public const TTL = 1800;

    private const TOKEN_PREFIX = 'fsut_';

    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly Clock $clock,
        private readonly string $appUrl,
    ) {
    }

    /**
     * Mints a ticket and returns the plaintext token ONCE — only its sha256 is
     * stored, so a caller that loses the URL mints another.
     *
     * @return array{token: string, upload_url: string, expires_at: string, max_bytes: int}
     */
    public function create(
        string $siteHandle,
        string $actorType,
        ?int $actorId,
        string $filename = '',
        string $alt = '',
        bool $decorative = false,
        ?int $maxBytes = null,
    ): array {
        // The ceiling only exists to bound how much we are willing to buffer
        // before ingest() applies the REAL per-type limits (15 MB image,
        // 100 MB file, 500 MB video). A caller may lower it, never raise it.
        $cap = MediaService::maxUploadBytes();
        if ($maxBytes !== null && $maxBytes > 0) {
            $cap = min($maxBytes, $cap);
        }

        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $now = $this->clock->iso();
        $expires = $this->clock->now()
            ->add(new \DateInterval('PT' . self::TTL . 'S'))
            ->format('Y-m-d\TH:i:s\Z');

        $db = $this->databases->engine();
        // Opportunistic prune: one indexed DELETE on the way in keeps the table
        // from growing forever without inventing a scheduled job for it.
        $db->executeStatement('DELETE FROM upload_tickets WHERE expires_at < ?', [$now]);

        $db->insert('upload_tickets', [
            'token_hash' => hash('sha256', $token),
            'site_handle' => $siteHandle,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'filename' => $filename,
            'alt' => $alt,
            'decorative' => $decorative ? 1 : 0,
            'max_bytes' => $cap,
            'expires_at' => $expires,
            'created_at' => $now,
        ]);

        return [
            'token' => $token,
            'upload_url' => $this->appUrl . '/u/' . $token,
            'expires_at' => $expires,
            'max_bytes' => $cap,
        ];
    }

    /**
     * Claims a ticket for use, atomically. The guarded UPDATE is what makes the
     * ticket single-use under concurrency: two simultaneous PUTs both match the
     * hash, only one sets `claimed_at`, and the loser is told it is spent.
     *
     * A claim is spent even if the upload that follows is then REJECTED (wrong
     * content type, over the per-type limit). That is the conservative reading
     * and it is deliberate: releasing the claim on failure would turn a ticket
     * into a retry oracle that lives for its full TTL, and minting another is
     * one cheap call. Since mismatched extensions are now reconciled rather
     * than refused, a rejection here means the bytes really were wrong.
     *
     * @return array{id: int, site_handle: string, filename: string, alt: string, decorative: bool, max_bytes: int}
     */
    public function claim(string $token): array
    {
        $db = $this->databases->engine();
        $hash = hash('sha256', $token);

        $row = $db->fetchAssociative(
            'SELECT id, site_handle, filename, alt, decorative, max_bytes, expires_at, claimed_at
             FROM upload_tickets WHERE token_hash = ?',
            [$hash],
        );
        if ($row === false) {
            throw new \InvalidArgumentException('Unknown or already-cleaned-up upload ticket.');
        }
        if ($row['claimed_at'] !== null) {
            throw new \InvalidArgumentException('This upload ticket has already been used. Mint another.');
        }
        if ((string) $row['expires_at'] < $this->clock->iso()) {
            throw new \InvalidArgumentException('This upload ticket has expired. Mint another.');
        }

        $claimed = $db->executeStatement(
            'UPDATE upload_tickets SET claimed_at = ? WHERE id = ? AND claimed_at IS NULL',
            [$this->clock->iso(), (int) $row['id']],
        );
        if ($claimed !== 1) {
            throw new \InvalidArgumentException('This upload ticket has already been used. Mint another.');
        }

        return [
            'id' => (int) $row['id'],
            'site_handle' => (string) $row['site_handle'],
            'filename' => (string) $row['filename'],
            'alt' => (string) $row['alt'],
            'decorative' => (bool) $row['decorative'],
            'max_bytes' => (int) $row['max_bytes'],
        ];
    }

    /** Records what a claimed ticket produced, for audit. */
    public function complete(int $ticketId, int $mediaId): void
    {
        $this->databases->engine()->executeStatement(
            'UPDATE upload_tickets SET media_id = ? WHERE id = ?',
            [$mediaId, $ticketId],
        );
    }
}
