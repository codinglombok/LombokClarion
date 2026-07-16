<?php

declare(strict_types=1);

namespace LombokClarion\Security;

use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;

final class SecurityHeaders implements Middleware
{
    /**
     * @param array<string, string> $overrides merge/override the defaults below
     */
    public function __construct(private readonly array $overrides = [])
    {
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $defaults = [
            'Content-Security-Policy' => "default-src 'self'",
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Strict-Transport-Security' => 'max-age=63072000; includeSubDomains',
        ];

        foreach ([...$defaults, ...$this->overrides] as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}
