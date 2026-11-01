<?php

declare(strict_types=1);

namespace Fastsite\Access\OAuth\Handler;

use Fastsite\Http\EngineView;
use Fastsite\Identity\Service\LoginFlow;
use Fastsite\Identity\Service\SessionService;
use Fastsite\Shared\Http\ApiResponse;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * POST /oauth/login — authenticates (credentials, then TOTP when the user
 * has it on) and returns to the authorize URL. The `return` field is
 * validated to be a local /oauth/authorize path so this can't be used as
 * an open redirect.
 */
final class LoginHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly LoginFlow $flow,
        private readonly EngineView $view,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!\is_array($body)) {
            $body = [];
        }
        $returnTo = (string) ($body['return'] ?? '');
        if (!str_starts_with($returnTo, '/oauth/authorize?')) {
            return ApiResponse::error('Invalid return target.', 400);
        }

        $params = $request->getServerParams();
        $ip = (string) ($params['REMOTE_ADDR'] ?? '');
        $ua = $request->getHeaderLine('User-Agent');

        // Step 2: TOTP code for a pending challenge.
        if (isset($body['challenge'])) {
            $sessionId = $this->flow->completeTotp((string) $body['challenge'], (string) ($body['code'] ?? ''), $ip, $ua);
            if ($sessionId === null) {
                return ApiResponse::html($this->view->render('oauth-login.html.twig', [
                    'title' => 'Sign in — fastsite',
                    'client_name' => 'the application',
                    'return_to' => $returnTo,
                    'challenge' => (string) $body['challenge'],
                    'error' => 'Wrong or expired code. Try again.',
                ]), 401);
            }

            return $this->redirect($returnTo, $sessionId, $request);
        }

        $result = $this->flow->begin((string) ($body['email'] ?? ''), (string) ($body['password'] ?? ''), $ip, $ua);

        return match ($result['status']) {
            'bad' => ApiResponse::html($this->view->render('oauth-login.html.twig', [
                'title' => 'Sign in — fastsite',
                'client_name' => 'the application',
                'return_to' => $returnTo,
                'challenge' => '',
                'error' => 'Invalid credentials, or too many attempts. Try again.',
            ]), 401),
            'totp' => ApiResponse::html($this->view->render('oauth-login.html.twig', [
                'title' => 'Two-factor code — fastsite',
                'client_name' => 'the application',
                'return_to' => $returnTo,
                'challenge' => $result['challenge'],
                'error' => '',
            ])),
            'ok' => $this->redirect($returnTo, $result['session'], $request),
        };
    }

    private function redirect(string $returnTo, string $sessionId, ServerRequestInterface $request): ResponseInterface
    {
        return (new Response(302, ['Location' => $returnTo]))
            ->withAddedHeader('Set-Cookie', SessionService::setCookie($sessionId, SessionService::isHttps($request)));
    }
}
