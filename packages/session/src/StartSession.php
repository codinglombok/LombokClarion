<?php

declare(strict_types=1);

namespace LombokClarion\Session;

use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;

/**
 * Middleware: reads session ID from cookie, loads session, saves after
 * response, and sets the session cookie on the response.
 */
final class StartSession implements Middleware
{
    public function __construct(
        private readonly Session $session,
        private readonly string $cookieName = 'lc_session',
        private readonly string $cookiePath = '/',
        private readonly bool $secure = true,
        private readonly bool $httpOnly = true,
        private readonly string $sameSite = 'Lax',
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        // Read session ID from cookie
        $cookies = $request->cookies();
        $sessionId = $cookies[$this->cookieName] ?? null;

        $this->session->start($sessionId);

        /** @var Response $response */
        $response = $next($request);

        $this->session->save();

        // Set or refresh cookie
        $cookieValue = $this->session->id();
        $parts = [
            "{$this->cookieName}={$cookieValue}",
            "Path={$this->cookiePath}",
            'HttpOnly',
            "SameSite={$this->sameSite}",
        ];

        if ($this->secure) {
            $parts[] = 'Secure';
        }

        return $response->withHeader('Set-Cookie', implode('; ', $parts));
    }
}
