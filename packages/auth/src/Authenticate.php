<?php

declare(strict_types=1);

namespace LombokClarion\Auth;

use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;

/**
 * Per-route, never global — the same rule CSRF and rate limiting follow (§6).
 * A route is authenticated because its declaration says so in bootstrap/routes.php,
 * where you can read it, not because it matched a pattern in a config file.
 *
 *   $router->get('/account', [AccountController::class, 'show'], [
 *       new Authenticate($authManager),
 *   ]);
 *
 * On success the user is bound into RequestContext for the rest of the request.
 * On failure: 401 and the handler never runs.
 */
final class Authenticate implements Middleware
{
    public const COOKIE = 'auth_token';

    public function __construct(private readonly AuthManager $auth)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return $this->unauthorized();
        }

        $user = $this->auth->authenticateToken($token);

        if ($user === null) {
            return $this->unauthorized();
        }

        // Not login(): the user is already logged in and is re-proving it on a
        // new request. Minting a fresh token here would silently extend every
        // session forever, so binding and issuing are separate verbs.
        $this->auth->bind($user);

        return $next($request);
    }

    /**
     * Cookie first, then `Authorization: Bearer` — a browser session and an API
     * client want different transports, and supporting both costs one method.
     */
    private function extractToken(Request $request): ?string
    {
        $cookie = $request->cookies[self::COOKIE] ?? null;
        if (is_string($cookie) && $cookie !== '') {
            return $cookie;
        }

        $header = $request->header('Authorization');
        if ($header !== null && stripos($header, 'bearer ') === 0) {
            $token = trim(substr($header, 7));

            return $token === '' ? null : $token;
        }

        return null;
    }

    private function unauthorized(): Response
    {
        // WWW-Authenticate is what makes a 401 a 401 rather than a 403 with the
        // wrong number on it (RFC 9110 §11.6.1).
        return Response::text('Unauthorized', 401)
            ->withHeader('WWW-Authenticate', 'Bearer');
    }
}
