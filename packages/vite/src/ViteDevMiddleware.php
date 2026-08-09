<?php

declare(strict_types=1);

namespace LombokClarion\Vite;

use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;

/**
 * In development mode, injects the Vite client script tag into HTML
 * responses automatically. No-op in production.
 */
final class ViteDevMiddleware implements Middleware
{
    public function __construct(
        private readonly Vite $vite,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (!$this->vite->isDev()) {
            return $response;
        }

        $contentType = $response->headers['Content-Type'] ?? '';
        if (!str_contains($contentType, 'text/html')) {
            return $response;
        }

        // Inject Vite client before </head>
        $body = $response->body;
        if (str_contains($body, '</head>')) {
            $tag = '<script type="module" src="http://localhost:5173/@vite/client"></script>';
            $body = str_replace('</head>', $tag . "\n</head>", $body);

            return new Response($response->status, $body, $response->headers);
        }

        return $response;
    }
}
