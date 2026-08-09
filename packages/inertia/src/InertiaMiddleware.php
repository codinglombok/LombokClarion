<?php

declare(strict_types=1);

namespace LombokClarion\Inertia;

use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;

/**
 * Inertia middleware: handles asset version checking.
 *
 * When the client-side asset version differs from the server-side version,
 * Inertia requires a full page reload. This middleware detects the mismatch
 * and returns a 409 Conflict with X-Inertia-Location header.
 */
final class InertiaMiddleware implements Middleware
{
    public function __construct(
        private readonly string $version = '',
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        // Only check version on Inertia GET requests
        if ($request->method() === 'GET'
            && $request->header('X-Inertia') === 'true'
            && $request->header('X-Inertia-Version') !== ''
            && $request->header('X-Inertia-Version') !== $this->version
        ) {
            $url = $request->path();
            $qs = $request->queryString();
            if ($qs !== '') {
                $url .= '?' . $qs;
            }

            return (new Response(409, '', []))
                ->withHeader('X-Inertia-Location', $url);
        }

        /** @var Response $response */
        $response = $next($request);

        // Ensure POST/PUT/PATCH/DELETE redirects use 303 for Inertia
        if ($request->header('X-Inertia') === 'true'
            && in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            && $response->status === 302
        ) {
            return $response->withStatus(303);
        }

        return $response;
    }
}
