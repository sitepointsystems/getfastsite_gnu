<?php

declare(strict_types=1);

namespace Fastsite\Publish\Service;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;

/**
 * Per-user GitHub PAT vault (#1). Tokens are stored TokenCipher-encrypted and
 * never re-displayed; a site references one by id and the publisher decrypts
 * it at push time. Nothing lives in .env.
 */
final class GithubCredentialService
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly TokenCipher $cipher,
        private readonly Clock $clock,
    ) {
    }

    /**
     * A user's credentials WITHOUT the token (label + metadata only).
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        return $this->databases->engine()->fetchAllAssociative(
            'SELECT id, label, created_at, last_used_at FROM github_credentials WHERE user_id = ? ORDER BY id DESC',
            [$userId],
        );
    }

    /** All credentials (id + owner + label), for the site-settings picker. */
    public function listAll(): array
    {
        return $this->databases->engine()->fetchAllAssociative(
            'SELECT c.id, c.label, u.email FROM github_credentials c JOIN users u ON u.id = c.user_id ORDER BY c.id DESC',
        );
    }

    public function add(int $userId, string $label, string $token): int
    {
        $label = trim($label);
        $token = trim($token);
        if ($label === '') {
            throw new \InvalidArgumentException('A label is required.');
        }
        if ($token === '') {
            throw new \InvalidArgumentException('A token is required.');
        }
        $enc = $this->cipher->encrypt($token);
        if ($enc === null) {
            throw new \RuntimeException('Cannot encrypt the token — APP_SECRET is not configured.');
        }

        $db = $this->databases->engine();
        $db->insert('github_credentials', [
            'user_id' => $userId,
            'label' => mb_substr($label, 0, 200),
            'token_enc' => $enc,
            'created_at' => $this->clock->iso(),
        ]);

        return (int) $db->lastInsertId();
    }

    /** Delete a credential the user owns (admins may delete any). */
    public function delete(int $id, int $userId, bool $isAdmin = false): void
    {
        $sql = 'DELETE FROM github_credentials WHERE id = ?' . ($isAdmin ? '' : ' AND user_id = ?');
        $params = $isAdmin ? [$id] : [$id, $userId];
        $this->databases->engine()->executeStatement($sql, $params);
    }

    /**
     * The decrypted token for a site's selected credential, or null when the
     * site has none (the publisher then falls back to the global token).
     */
    public function tokenForSite(Site $site): ?string
    {
        if ($site->githubCredentialId === null) {
            return null;
        }
        $row = $this->databases->engine()->fetchAssociative(
            'SELECT id, token_enc FROM github_credentials WHERE id = ?',
            [$site->githubCredentialId],
        );
        if ($row === false) {
            return null;
        }
        $token = $this->cipher->decrypt((string) $row['token_enc']);
        if ($token === null) {
            return null;
        }
        $this->databases->engine()->executeStatement(
            'UPDATE github_credentials SET last_used_at = ? WHERE id = ?',
            [$this->clock->iso(), (int) $row['id']],
        );

        return $token;
    }
}
