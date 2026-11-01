<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Access\Service\ApiKeyService;
use Fastsite\Dashboard\Dash;
use Fastsite\Dashboard\Middleware\SessionAuthMiddleware;
use Fastsite\Identity\Service\RememberTokenService;
use Fastsite\Identity\Service\SessionService;
use Fastsite\Identity\Service\TotpService;
use Fastsite\Identity\Service\UserService;
use Fastsite\Publish\Service\GithubCredentialService;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Predis\Client;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /account/action — password change, TOTP setup/enable/disable, API
 * key create/rotate/revoke, connected-app revoke, session revoke. One-time
 * secrets (fresh key token, TOTP seed) are rendered straight from this
 * response, NEVER passed through a redirect URL.
 */
final class AccountActionHandler implements RequestHandlerInterface
{
    private const PENDING_SEED_TTL = 900;

    public function __construct(
        private readonly Dash $dash,
        private readonly AccountScreenHandler $screen,
        private readonly UserService $users,
        private readonly TotpService $totp,
        private readonly ApiKeyService $keys,
        private readonly SessionService $sessions,
        private readonly RememberTokenService $remember,
        private readonly SiteDatabaseManager $databases,
        private readonly Client $redis,
        private readonly GithubCredentialService $githubCreds,
        private readonly \Fastsite\Ai\AiCredentialService $aiCreds,
        private readonly \Fastsite\Identity\Service\AvatarService $avatars,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $user = $this->dash->user($request);
        $sessionId = (string) $request->getAttribute(SessionAuthMiddleware::SESSION_ATTRIBUTE);

        try {
            switch ((string) ($body['action'] ?? '')) {
                case 'password':
                    if (!$this->users->changePassword($user['id'], (string) ($body['current'] ?? ''), (string) ($body['new'] ?? ''))) {
                        return $this->dash->redirect('/account', '', 'Current password is wrong.');
                    }
                    // New password invalidates every OTHER session + all
                    // remember-me tokens (stolen-credential hygiene).
                    $this->sessions->logoutAllForUser($user['id'], $sessionId);
                    $this->remember->revokeAll($user['id']);

                    return $this->dash->redirect('/account', 'Password changed. Other sessions signed out.');

                case 'avatar-upload':
                    $upload = $request->getUploadedFiles()['avatar'] ?? null;
                    if (!$upload instanceof \Psr\Http\Message\UploadedFileInterface || $upload->getError() !== UPLOAD_ERR_OK) {
                        return $this->dash->redirect('/account', '', 'Pick an image file first.');
                    }
                    $this->avatars->save($user['id'], (string) $upload->getStream());

                    return $this->dash->redirect('/account', 'Profile image updated.');

                case 'avatar-remove':
                    $this->avatars->remove($user['id']);

                    return $this->dash->redirect('/account', 'Profile image removed.');

                case 'totp-setup':
                    $seed = $this->totp->generateSeed();
                    $this->redis->setex('fastsite:totp-setup:' . $sessionId, self::PENDING_SEED_TTL, $seed);

                    return $this->dash->render($request, 'account.html.twig', $this->screen->context($request, [
                        'totp_setup' => [
                            'seed' => $seed,
                            'uri' => $this->totp->provisioningUri($user['email'], $seed),
                        ],
                    ]));

                case 'totp-enable':
                    $seed = $this->redis->get('fastsite:totp-setup:' . $sessionId);
                    if (!\is_string($seed) || $seed === '') {
                        return $this->dash->redirect('/account', '', 'Setup expired — start again.');
                    }
                    if (!$this->totp->enable($user['id'], $seed, (string) ($body['code'] ?? ''))) {
                        return $this->dash->render($request, 'account.html.twig', $this->screen->context($request, [
                            'totp_setup' => [
                                'seed' => $seed,
                                'uri' => $this->totp->provisioningUri($user['email'], $seed),
                                'error' => 'Code did not match — try again.',
                            ],
                        ]));
                    }
                    $this->redis->del(['fastsite:totp-setup:' . $sessionId]);

                    return $this->dash->redirect('/account', 'Two-factor authentication is ON.');

                case 'totp-disable':
                    if (!$this->totp->disable($user['id'], (string) ($body['code'] ?? ''))) {
                        return $this->dash->redirect('/account', '', 'Wrong code — TOTP stays on.');
                    }

                    return $this->dash->redirect('/account', 'Two-factor authentication is OFF.');

                case 'key-create':
                    // Scopes now arrive as a checkbox array (scopes[]); still
                    // accept a legacy comma string for the API/older forms.
                    $scopes = self::submittedScopes($body['scopes'] ?? '');
                    $sites = array_values(array_filter(array_map('trim', explode(',', (string) ($body['sites'] ?? '*')))));
                    // A dashboard user can never mint a key more powerful than
                    // themselves: non-admins may not grant `admin`, may not use
                    // the `*` wildcard, and are clamped to sites they actually
                    // belong to. Enforced server-side — the POST body is
                    // attacker-controlled, the form's <select> is not a gate.
                    [$scopes, $sites] = $this->boundKeyRequest($request, $user, $scopes, $sites);
                    $key = $this->keys->create($user['id'], trim((string) ($body['name'] ?? 'dashboard')), $scopes, $sites);

                    return $this->dash->render($request, 'account.html.twig', $this->screen->context($request, [
                        'new_key' => $key['token'],
                    ]));

                case 'key-scopes':
                    // Edit in place: the token is untouched, so nothing holding
                    // this key has to be reconfigured. Same grant gate as
                    // key-create — otherwise editing would be the way around
                    // the rule creation enforces.
                    $keyId = (int) ($body['key_id'] ?? 0);
                    $this->assertOwnKey($user['id'], $keyId);
                    $scopes = self::submittedScopes($body['scopes'] ?? '');
                    $this->assertMayGrant($user, $scopes);
                    $this->keys->updateScopes($keyId, $scopes);

                    return $this->dash->redirect('/account', 'Scopes updated, and live immediately. The key itself is unchanged.');

                case 'key-revoke':
                    $keyId = (int) ($body['key_id'] ?? 0);
                    $this->assertOwnKey($user['id'], $keyId);
                    $this->keys->revoke($keyId);

                    return $this->dash->redirect('/account', 'Key revoked.');

                case 'key-delete':
                    $keyId = (int) ($body['key_id'] ?? 0);
                    $this->assertOwnKey($user['id'], $keyId);
                    $this->keys->delete($keyId);

                    return $this->dash->redirect('/account', 'Key deleted.');

                case 'github-add':
                    $this->githubCreds->add($user['id'], (string) ($body['label'] ?? ''), (string) ($body['token'] ?? ''));

                    return $this->dash->redirect('/account', 'GitHub credential saved. Select it on a site\'s settings to publish with it.');

                case 'github-delete':
                    $this->githubCreds->delete((int) ($body['cred_id'] ?? 0), $user['id'], ($user['role'] ?? '') === 'admin');

                    return $this->dash->redirect('/account', 'GitHub credential removed.');

                case 'ai-add':
                    $this->aiCreds->add(
                        $user['id'],
                        (string) ($body['label'] ?? ''),
                        (string) ($body['key'] ?? ''),
                        (string) ($body['base_url'] ?? ''),
                        (string) ($body['model'] ?? ''),
                        (string) ($body['factcheck_model'] ?? ''),
                        (string) ($body['research_model'] ?? ''),
                        (string) ($body['translate_model'] ?? ''),
                    );

                    return $this->dash->redirect('/account', 'AI key saved. Select it on a site\'s settings to power translation + fact-checking + research.');

                case 'ai-edit':
                    $this->aiCreds->update(
                        (int) ($body['cred_id'] ?? 0),
                        $user['id'],
                        (string) ($body['label'] ?? ''),
                        (string) ($body['key'] ?? ''),
                        (string) ($body['model'] ?? ''),
                        (string) ($body['factcheck_model'] ?? ''),
                        (string) ($body['research_model'] ?? ''),
                        (string) ($body['translate_model'] ?? ''),
                    );

                    return $this->dash->redirect('/account', 'AI key updated.');

                case 'ai-delete':
                    $this->aiCreds->delete((int) ($body['cred_id'] ?? 0), $user['id'], ($user['role'] ?? '') === 'admin');

                    return $this->dash->redirect('/account', 'AI key removed.');

                case 'app-revoke':
                    $clientId = (string) ($body['client_id'] ?? '');
                    $this->databases->engine()->executeStatement(
                        'UPDATE oauth_tokens SET revoked_at = ? WHERE user_id = ? AND client_id = ? AND revoked_at IS NULL',
                        [gmdate('Y-m-d\TH:i:s\Z'), $user['id'], $clientId],
                    );

                    return $this->dash->redirect('/account', 'App disconnected — its tokens are revoked.');

                case 'session-revoke':
                    $target = (string) ($body['session_id'] ?? '');
                    $owned = $this->databases->engine()->fetchOne(
                        'SELECT 1 FROM user_sessions WHERE id = ? AND user_id = ?',
                        [$target, $user['id']],
                    );
                    if ($owned !== false && !hash_equals($sessionId, $target)) {
                        $this->sessions->logout($target);
                    }

                    return $this->dash->redirect('/account', 'Session revoked.');

                default:
                    return $this->dash->redirect('/account', '', 'Unknown action.');
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->dash->redirect('/account', '', $e->getMessage());
        }
    }

    /**
     * Clamps a self-service key request to the caller's own authority.
     * Admins keep full freedom; everyone else gets a non-escalating ceiling.
     *
     * @param array{id: int, role: string} $user
     * @param list<string> $scopes
     * @param list<string> $sites
     * @return array{0: list<string>, 1: list<string>}
     */
    private function boundKeyRequest(ServerRequestInterface $request, array $user, array $scopes, array $sites): array
    {
        $this->assertMayGrant($user, $scopes);

        if (($user['role'] ?? '') === 'admin') {
            return [$scopes, $sites === [] ? ['*'] : $sites];
        }

        $allowed = array_map(static fn ($s): string => $s->handle, $this->dash->sitesFor($user));
        // Reject the wildcard and pin to the intersection of requested and
        // permitted handles. No sites named = all of the user's own sites.
        $requested = array_values(array_filter($sites, static fn (string $s): bool => $s !== '*'));
        if (\in_array('*', $sites, true) || $requested === []) {
            $clamped = $allowed;
        } else {
            $clamped = array_values(array_intersect($requested, $allowed));
        }
        if ($clamped === []) {
            throw new \RuntimeException('You can only create keys for sites you have access to.');
        }

        return [$scopes, $clamped];
    }

    /**
     * THE one answer to "may this user put these scopes on a key". Creation and
     * the scope editor both come through here; a second copy of this rule is
     * how editing quietly becomes a privilege escalation.
     *
     * @param array<string, mixed> $user
     * @param list<string> $scopes
     */
    private function assertMayGrant(array $user, array $scopes): void
    {
        if (($user['role'] ?? '') !== 'admin' && \in_array('admin', $scopes, true)) {
            throw new \RuntimeException('Only administrators can grant the admin scope.');
        }
    }

    /**
     * The checkbox array (scopes[]) the forms post; a comma string is still
     * accepted for older callers.
     *
     * @return list<string>
     */
    private static function submittedScopes(mixed $raw): array
    {
        if (\is_array($raw)) {
            return array_values(array_filter(array_map(static fn ($s): string => trim((string) $s), $raw)));
        }

        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    }

    private function assertOwnKey(int $userId, int $keyId): void
    {
        $owned = $this->databases->engine()->fetchOne(
            'SELECT 1 FROM api_keys WHERE id = ? AND user_id = ?',
            [$keyId, $userId],
        );
        if ($owned === false) {
            throw new \RuntimeException('That key is not yours.');
        }
    }
}
