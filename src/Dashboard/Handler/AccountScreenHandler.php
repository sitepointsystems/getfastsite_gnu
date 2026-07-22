<?php

declare(strict_types=1);

namespace Fastsite\Dashboard\Handler;

use Fastsite\Access\OAuth\ScopeCatalog;
use Fastsite\Dashboard\Dash;
use Fastsite\Identity\Service\TotpService;
use Fastsite\Publish\Service\GithubCredentialService;
use Fastsite\Shared\Database\SiteDatabaseManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * GET /account — screen 5: password, TOTP, API keys, connected MCP apps,
 * active sessions.
 */
final class AccountScreenHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly Dash $dash,
        private readonly TotpService $totp,
        private readonly SiteDatabaseManager $databases,
        private readonly GithubCredentialService $githubCreds,
        private readonly \Fastsite\Ai\AiCredentialService $aiCreds,
        private readonly \Fastsite\Ai\OpenRouterModels $orModels,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->dash->render($request, 'account.html.twig', $this->context($request));
    }

    /**
     * Shared with AccountActionHandler (which re-renders with one-time
     * secrets in context instead of redirecting them through a URL).
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function context(ServerRequestInterface $request, array $extra = []): array
    {
        $user = $this->dash->user($request);
        $engine = $this->databases->engine();

        return $extra + [
            'title' => 'Account — fastsite',
            'scope_catalog' => ScopeCatalog::all(),
            'github_creds' => $this->githubCreds->listForUser($user['id']),
            'ai_creds' => $this->aiCreds->listForUser($user['id']),
            'or_models' => $this->orModels->list(),
            'ai_model_default' => 'anthropic/claude-sonnet-latest',
            'ai_factcheck_default' => 'perplexity/sonar',
            'ai_research_default' => 'perplexity/sonar',
            'totp_enabled' => $this->totp->isEnabled($user['id']),
            'keys' => $engine->fetchAllAssociative(
                'SELECT id, name, key_id, scopes, sites, last_used_at, revoked_at, created_at
                 FROM api_keys WHERE user_id = ? ORDER BY id DESC LIMIT 50',
                [$user['id']],
            ),
            'apps' => $engine->fetchAllAssociative(
                "SELECT t.client_id, c.client_name, MAX(t.created_at) AS connected_at, COUNT(*) AS tokens
                 FROM oauth_tokens t JOIN oauth_clients c ON c.client_id = t.client_id
                 WHERE t.user_id = ? AND t.revoked_at IS NULL AND (t.refresh_expires_at IS NULL OR t.refresh_expires_at > ?)
                 GROUP BY t.client_id, c.client_name ORDER BY connected_at DESC",
                [$user['id'], gmdate('Y-m-d\TH:i:s\Z')],
            ),
            'sessions' => $engine->fetchAllAssociative(
                'SELECT id, ip, user_agent, created_at, last_seen_at FROM user_sessions
                 WHERE user_id = ? ORDER BY last_seen_at DESC LIMIT 20',
                [$user['id']],
            ),
            'current_session' => (string) $request->getAttribute(\Fastsite\Dashboard\Middleware\SessionAuthMiddleware::SESSION_ATTRIBUTE),
            'new_key' => null,
            'totp_setup' => null,
        ];
    }
}
