<?php

declare(strict_types=1);

namespace LombokClarion\Security;

use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;

final class ValidateCsrf implements Middleware
{
    private const MUTATING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    public function __construct(private readonly CsrfTokenManager $tokens)
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->method, self::MUTATING_METHODS, true)) {
            return $next($request);
        }

        $cookieToken = $request->cookies['csrf_token'] ?? null;
        $submitted = $request->input('_csrf') ?? $request->header('X-CSRF-Token');

        if (
            $cookieToken === null
            || $submitted === null
            || !hash_equals((string) $cookieToken, (string) $submitted)
            || !$this->tokens->isValid((string) $cookieToken)
        ) {
            return Response::text('CSRF token mismatch', 419);
        }

        return $next($request);
    }
}
