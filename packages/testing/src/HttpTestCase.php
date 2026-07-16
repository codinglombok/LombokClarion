<?php

declare(strict_types=1);

namespace LombokClarion\Testing;

use LombokClarion\Container\CompiledContainer;
use LombokClarion\Container\Container;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Routing\Kernel;
use LombokClarion\Routing\Router;

/**
 * Boots the REAL container built from the app's actual bootstrap/services.php
 * and routes.php — never an implicit "testing env" config switch that
 * quietly swaps in different wiring. Anything that needs faking is swapped
 * explicitly via override().
 */
final class HttpTestCase
{
    private readonly Kernel $kernel;

    /**
     * @param list<class-string|\LombokClarion\Http\Middleware> $globalMiddleware
     */
    public function __construct(
        private readonly Container|CompiledContainer $container,
        private readonly Router $router,
        private readonly array $globalMiddleware = [],
    ) {
        $this->kernel = new Kernel($this->container, $this->router, $this->globalMiddleware);
    }

    /**
     * Explicitly swap a binding for this test (e.g. a fake repository or
     * FakeCommandBus) — real dependency injection, not a magic testing mode.
     */
    public function override(string $id, object $fake): void
    {
        $this->container->instance($id, $fake);
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->kernel->handle(new Request('GET', $path, query: $query));
    }

    public function post(string $path, array $body = []): Response
    {
        return $this->kernel->handle(new Request('POST', $path, body: $body));
    }

    public function put(string $path, array $body = []): Response
    {
        return $this->kernel->handle(new Request('PUT', $path, body: $body));
    }

    public function delete(string $path): Response
    {
        return $this->kernel->handle(new Request('DELETE', $path));
    }
}
