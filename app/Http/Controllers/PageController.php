<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Widget\CreateWidget;
use App\Domain\Widget\ListWidgets;
use App\Http\Requests\CreateWidgetRequest;
use LombokClarion\Bus\CommandBus;
use LombokClarion\Bus\QueryBus;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Security\CsrfTokenManager;
use LombokClarion\Security\Exceptions\ValidationException;
use LombokClarion\View\AssetManifest;
use LombokClarion\View\Theme;
use LombokClarion\View\ViewEngine;

final class PageController
{
    public function __construct(
        private readonly ViewEngine $views,
        private readonly Theme $theme,
        private readonly AssetManifest $assets,
        private readonly QueryBus $queries,
        private readonly CommandBus $commands,
        private readonly CsrfTokenManager $csrf,
    ) {
    }

    public function home(Request $request): Response
    {
        return Response::html($this->views->render('welcome', [
            'title' => 'Welcome',
            'theme' => $this->theme,
            'assets' => $this->assets,
        ]));
    }

    public function dashboard(Request $request): Response
    {
        /** @var list<\App\Domain\Widget\Widget> $widgets */
        $widgets = $this->queries->ask(new ListWidgets());

        $chartData = array_map(
            fn ($w) => ['label' => $w->name, 'value' => $w->priceCents],
            $widgets
        );

        // JSON_HEX_* flags make the payload safe to embed inside a <script>
        // block (no way to break out with </script> or quotes), which is why
        // the view may mark it Safe for raw output.
        $chartJson = json_encode(
            $chartData,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return Response::html($this->views->render('dashboard', [
            'title' => 'Dashboard',
            'theme' => $this->theme,
            'assets' => $this->assets,
            'chartData' => $chartData,
            'chartJson' => $chartJson,
        ]));
    }

    public function widgets(Request $request): Response
    {
        // Double-submit cookie: reuse the visitor's existing valid token so
        // multiple open tabs don't invalidate each other; issue a fresh one
        // otherwise.
        $token = $request->cookies['csrf_token'] ?? null;
        if ($token === null || !$this->csrf->isValid($token)) {
            $token = $this->csrf->generate();
        }

        $html = $this->views->render('widgets', [
            'title' => 'Widgets',
            'theme' => $this->theme,
            'assets' => $this->assets,
            'widgets' => $this->queries->ask(new ListWidgets()),
            'csrfToken' => $token,
        ]);

        return Response::html($html)->withHeader(
            'Set-Cookie',
            "csrf_token=$token; Path=/; HttpOnly; SameSite=Lax"
        );
    }

    public function storeWidget(Request $request): Response
    {
        try {
            $data = (new CreateWidgetRequest())->validated($request);
        } catch (ValidationException $e) {
            return Response::html($this->views->render('widgets', [
                'title' => 'Widgets',
                'theme' => $this->theme,
                'assets' => $this->assets,
                'widgets' => $this->queries->ask(new ListWidgets()),
                'csrfToken' => (string) ($request->cookies['csrf_token'] ?? ''),
                'errors' => $e->errors,
            ]), 422);
        }

        $this->commands->dispatch(new CreateWidget($data['name'], (int) $data['price_cents']));

        return Response::redirect('/widgets', 303);
    }
}
