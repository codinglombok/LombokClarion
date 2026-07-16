<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Widget\CreateWidget;
use App\Domain\Widget\ListWidgets;
use App\Domain\Widget\Widget;
use App\Http\Requests\CreateWidgetRequest;
use LombokClarion\Bus\CommandBus;
use LombokClarion\Bus\QueryBus;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Security\Exceptions\ValidationException;

final class WidgetController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {
    }

    public function index(Request $request): Response
    {
        $widgets = $this->queries->ask(new ListWidgets());

        return Response::json(array_map(
            fn (Widget $w) => ['id' => $w->id, 'name' => $w->name, 'price_cents' => $w->priceCents],
            $widgets
        ));
    }

    public function store(Request $request): Response
    {
        try {
            $data = (new CreateWidgetRequest())->validated($request);
        } catch (ValidationException $e) {
            return Response::json(['errors' => $e->errors], 422);
        }

        $widget = $this->commands->dispatch(new CreateWidget($data['name'], (int) $data['price_cents']));

        return Response::json(
            ['id' => $widget->id, 'name' => $widget->name, 'price_cents' => $widget->priceCents],
            201
        );
    }
}
