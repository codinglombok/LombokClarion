<?php

declare(strict_types=1);

namespace LombokClarion\Security;

use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;

/**
 * Built via a static factory rather than resolved by class-string from the
 * container, since it needs a per-route limit value baked in:
 *
 *   $router->post('/login', [AuthController::class, 'login'], [
 *       RateLimit::perMinute(5, $rateLimitStore),
 *   ]);
 *
 * Router/Kernel accept Middleware instances directly alongside
 * class-string middleware for exactly this reason.
 */
final class RateLimit implements Middleware
{
    private function __construct(
        private readonly int $max,
        private readonly int $windowSeconds,
        private readonly RateLimitStore $store,
    ) {
    }

    public static function perMinute(int $max, RateLimitStore $store): self
    {
        return new self($max, 60, $store);
    }

    public static function perHour(int $max, RateLimitStore $store): self
    {
        return new self($max, 3600, $store);
    }

    public function handle(Request $request, callable $next): Response
    {
        $identity = $request->header('x-forwarded-for') ?? 'unknown';
        $key = $identity . '|' . $request->method . '|' . $request->path;

        $count = $this->store->increment($key, $this->windowSeconds);

        if ($count > $this->max) {
            return Response::text('Too Many Requests', 429)
                ->withHeader('Retry-After', (string) $this->windowSeconds);
        }

        return $next($request);
    }
}
