<?php

declare(strict_types=1);

namespace Fastsite\Ai;

use Fastsite\Shared\Clock\Clock;
use Fastsite\Shared\Crypto\TokenCipher;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Fastsite\Site\Dto\Site;

/**
 * Per-user AI (OpenRouter) key vault. Mirrors {@see GithubCredentialService}:
 * keys are stored TokenCipher-encrypted and never re-displayed; a site
 * references one by id (sites.ai_credential_id) and a translate/fact-check job
 * decrypts it at run time. Nothing lives in .env — the key spends the USER's
 * own AI credits, not the platform operator's.
 */
final class AiCredentialService
{
    public function __construct(
        private readonly SiteDatabaseManager $databases,
        private readonly TokenCipher $cipher,
        private readonly Clock $clock,
    ) {
    }

    /**
     * A user's credentials WITHOUT the key (label + metadata only).
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        return $this->databases->engine()->fetchAllAssociative(
            'SELECT id, label, key_hint, base_url, model, translate_model, factcheck_model, research_model, created_at, last_used_at
             FROM ai_credentials WHERE user_id = ? ORDER BY id DESC',
            [$userId],
        );
    }

    /**
     * All credentials (id + owner + label), for the site-settings picker.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->databases->engine()->fetchAllAssociative(
            'SELECT c.id, c.label, u.email FROM ai_credentials c JOIN users u ON u.id = c.user_id ORDER BY c.id DESC',
        );
    }

    public function add(int $userId, string $label, string $key, string $baseUrl = '', string $model = '', string $factcheckModel = '', string $researchModel = '', string $translateModel = ''): int
    {
        $label = trim($label);
        $key = trim($key);
        if ($label === '') {
            throw new \InvalidArgumentException('A label is required.');
        }
        if ($key === '') {
            throw new \InvalidArgumentException('An API key is required.');
        }
        $enc = $this->cipher->encrypt($key);
        if ($enc === null) {
            throw new \RuntimeException('Cannot encrypt the key — APP_SECRET is not configured.');
        }

        $db = $this->databases->engine();
        $db->insert('ai_credentials', [
            'user_id' => $userId,
            'label' => mb_substr($label, 0, 200),
            'key_enc' => $enc,
            'key_hint' => self::mask($key),
            'base_url' => rtrim(trim($baseUrl), '/'),
            'model' => mb_substr(trim($model), 0, 120),
            'translate_model' => mb_substr(trim($translateModel), 0, 120),
            'factcheck_model' => mb_substr(trim($factcheckModel), 0, 120),
            'research_model' => mb_substr(trim($researchModel), 0, 120),
            'created_at' => $this->clock->iso(),
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Edit a credential the user owns: label + models always; the key only when
     * a new one is supplied (blank leaves the saved key unchanged).
     */
    public function update(int $id, int $userId, string $label, string $key, string $model, string $factcheckModel, string $researchModel = '', string $translateModel = ''): void
    {
        $label = trim($label);
        if ($label === '') {
            throw new \InvalidArgumentException('A label is required.');
        }
        $owned = $this->databases->engine()->fetchOne(
            'SELECT 1 FROM ai_credentials WHERE id = ? AND user_id = ?',
            [$id, $userId],
        );
        if ($owned === false) {
            throw new \RuntimeException('That AI key is not yours.');
        }

        $fields = [
            'label' => mb_substr($label, 0, 200),
            'model' => mb_substr(trim($model), 0, 120),
            'translate_model' => mb_substr(trim($translateModel), 0, 120),
            'factcheck_model' => mb_substr(trim($factcheckModel), 0, 120),
            'research_model' => mb_substr(trim($researchModel), 0, 120),
        ];
        $key = trim($key);
        if ($key !== '') {
            $enc = $this->cipher->encrypt($key);
            if ($enc === null) {
                throw new \RuntimeException('Cannot encrypt the key — APP_SECRET is not configured.');
            }
            $fields['key_enc'] = $enc;
            $fields['key_hint'] = self::mask($key);
        }

        $this->databases->engine()->update('ai_credentials', $fields, ['id' => $id]);
    }

    /** Delete a credential the user owns (admins may delete any). */
    public function delete(int $id, int $userId, bool $isAdmin = false): void
    {
        $sql = 'DELETE FROM ai_credentials WHERE id = ?' . ($isAdmin ? '' : ' AND user_id = ?');
        $params = $isAdmin ? [$id] : [$id, $userId];
        $this->databases->engine()->executeStatement($sql, $params);
    }

    /** Non-secret masked hint: first 6 + last 4 (e.g. "sk-or-…1a2b"). */
    private static function mask(string $key): string
    {
        $key = trim($key);
        $len = mb_strlen($key);
        if ($len <= 10) {
            return str_repeat('•', max(0, $len));
        }

        return mb_substr($key, 0, 6) . '…' . mb_substr($key, -4);
    }

    /**
     * The decrypted credential a site references, or null when the site has
     * none selected (or the row/key is gone). Bumps last_used_at.
     */
    public function resolveForSite(Site $site): ?AiCredential
    {
        if ($site->aiCredentialId === null) {
            return null;
        }
        $row = $this->databases->engine()->fetchAssociative(
            'SELECT id, key_enc, base_url, model, translate_model, factcheck_model, research_model FROM ai_credentials WHERE id = ?',
            [$site->aiCredentialId],
        );
        if ($row === false) {
            return null;
        }
        $key = $this->cipher->decrypt((string) $row['key_enc']);
        if ($key === null || $key === '') {
            return null;
        }
        $this->databases->engine()->executeStatement(
            'UPDATE ai_credentials SET last_used_at = ? WHERE id = ?',
            [$this->clock->iso(), (int) $row['id']],
        );

        return new AiCredential(
            apiKey: $key,
            baseUrl: (string) ($row['base_url'] ?? ''),
            model: (string) ($row['model'] ?? ''),
            factcheckModel: (string) ($row['factcheck_model'] ?? ''),
            translateModel: (string) ($row['translate_model'] ?? ''),
            researchModel: (string) ($row['research_model'] ?? ''),
        );
    }
}
