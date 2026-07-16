<?php

declare(strict_types=1);

namespace LombokClarion\Http;

/**
 * Middleware are declared explicitly per-route/group in bootstrap/routes.php
 * (or a route group) — never applied globally "by convention". See master
 * prompt §6 (CSRF/rate-limit/security-headers must all be explicit).
 */
interface Middleware
{
    /**
     * @param callable(Request): Response $next
     */
    public function handle(Request $request, callable $next): Response;
}
