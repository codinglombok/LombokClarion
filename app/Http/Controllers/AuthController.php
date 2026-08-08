<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use LombokClarion\Auth\Authenticate;
use LombokClarion\Auth\AuthManager;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;

final class AuthController
{
    public function __construct(private readonly AuthManager $auth)
    {
    }

    public function login(Request $request): Response
    {
        $user = $this->auth->attempt(
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
        );

        if ($user === null) {
            // One message for both "no such account" and "wrong password". The
            // pair of failures must be indistinguishable in body, status, and —
            // thanks to AuthManager's dummy-hash path — in timing.
            return Response::json(['error' => 'Invalid credentials'], 401);
        }

        $token = $this->auth->login($user);

        return Response::json(['ok' => true])
            ->withHeader('Set-Cookie', $this->cookie($token));
    }

    public function logout(Request $request): Response
    {
        $token = $request->cookies[Authenticate::COOKIE] ?? null;
        $revoked = $this->auth->logout(is_string($token) ? $token : null);

        return Response::json(['ok' => true, 'revoked_server_side' => $revoked])
            ->withHeader('Set-Cookie', Authenticate::COOKIE . '=; Path=/; Max-Age=0; HttpOnly; SameSite=Lax');
    }

    public function me(Request $request): Response
    {
        $user = $this->auth->user();

        return Response::json(['id' => $user?->getAuthId()]);
    }

    /**
     * HttpOnly so a successful XSS cannot read the session token; SameSite=Lax
     * so it is not attached to cross-site POSTs (defence in depth — CSRF is
     * still validated on its own middleware, not delegated to the cookie).
     */
    private function cookie(string $token): string
    {
        return Authenticate::COOKIE . '=' . $token . '; Path=/; HttpOnly; SameSite=Lax';
    }
}
