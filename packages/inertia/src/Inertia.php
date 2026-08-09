<?php

declare(strict_types=1);

namespace LombokClarion\Inertia;

use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\View\ViewEngine;

/**
 * Server-side adapter for the Inertia.js protocol.
 *
 * Controllers call $inertia->render('PageComponent', $props) instead of
 * returning a view. On the first (full-page) request, Inertia renders a
 * root template containing a JSON-encoded page object. On subsequent
 * XHR requests (X-Inertia header), it returns the page object as JSON.
 *
 * See https://inertiajs.com/the-protocol
 */
final class Inertia
{
    /** @var array<string, mixed> data shared with every page */
    private array $sharedProps = [];

    public function __construct(
        private readonly ViewEngine $viewEngine,
        private readonly string $rootView = 'app',
        private readonly string $version = '',
    ) {
    }

    /**
     * Share props with every Inertia response (e.g. auth user, flash).
     *
     * @param array<string, mixed> $props
     */
    public function share(array $props): void
    {
        $this->sharedProps = array_merge($this->sharedProps, $props);
    }

    /**
     * Render an Inertia page response.
     *
     * @param string $component  the frontend component name (e.g. 'Users/Index')
     * @param array<string, mixed> $props  page-specific props
     */
    public function render(Request $request, string $component, array $props = []): Response
    {
        $page = [
            'component' => $component,
            'props' => array_merge($this->sharedProps, $props),
            'url' => $request->path() . ($request->queryString() !== '' ? '?' . $request->queryString() : ''),
            'version' => $this->version,
        ];

        // Inertia XHR request — return JSON
        if ($this->isInertiaRequest($request)) {
            return Response::json($page)
                ->withHeader('X-Inertia', 'true')
                ->withHeader('Vary', 'X-Inertia');
        }

        // Full page — render root view with the page object embedded
        $html = $this->viewEngine->render($this->rootView, [
            'page' => $page,
            'pageJson' => json_encode($page, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP),
        ]);

        return Response::html($html);
    }

    /**
     * Redirect back with the Inertia protocol.
     */
    public function location(string $url): Response
    {
        return (new Response(409, '', []))
            ->withHeader('X-Inertia-Location', $url);
    }

    private function isInertiaRequest(Request $request): bool
    {
        return $request->header('X-Inertia') === 'true';
    }
}
