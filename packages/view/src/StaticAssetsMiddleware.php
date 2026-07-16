<?php

declare(strict_types=1);

namespace LombokClarion\View;

use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;

/**
 * Serves /assets/* with `Cache-Control: public, max-age=31536000, immutable`
 * (§8) when the app runs under PHP's built-in server or any setup where
 * static files fall through to the framework. In real deployments the web
 * server/CDN serves these directly with the same header and this
 * middleware simply never matches.
 *
 * Path traversal is blocked by realpath-prefix check, not string
 * sanitising — the resolved path must live inside the assets directory.
 */
final class StaticAssetsMiddleware implements Middleware
{
    private const CONTENT_TYPES = [
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'ico' => 'image/x-icon',
    ];

    public function __construct(
        private readonly string $assetsDir,
        private readonly string $urlPrefix = '/assets/',
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($request->method !== 'GET' || !str_starts_with($request->path, $this->urlPrefix)) {
            return $next($request);
        }

        $relative = substr($request->path, strlen($this->urlPrefix));
        $assetsRoot = realpath($this->assetsDir);
        $resolved = realpath($this->assetsDir . '/' . $relative);

        if (
            $assetsRoot === false
            || $resolved === false
            || !str_starts_with($resolved, $assetsRoot . DIRECTORY_SEPARATOR)
            || !is_file($resolved)
        ) {
            return Response::text('Not Found', 404);
        }

        $ext = strtolower(pathinfo($resolved, PATHINFO_EXTENSION));
        $contentType = self::CONTENT_TYPES[$ext] ?? 'application/octet-stream';

        return new Response(200, file_get_contents($resolved), [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
