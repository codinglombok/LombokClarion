<?php

declare(strict_types=1);

use LombokClarion\Container\Container;
use LombokClarion\Container\Exceptions\ContainerException;
use LombokClarion\Container\Plugin;
use LombokClarion\Container\PluginRegistrar;

final class Test_AnalyticsService
{
    public function track(string $event): string
    {
        return "tracked:$event";
    }
}

final class Test_AnalyticsPlugin implements Plugin
{
    public function name(): string
    {
        return 'vendor/analytics';
    }

    public function capabilities(): array
    {
        return ['bindings'];
    }

    public function register(Container $container): void
    {
        $container->singleton(Test_AnalyticsService::class, Test_AnalyticsService::class);
    }
}

final class Test_RouteHungryPlugin implements Plugin
{
    public function name(): string
    {
        return 'vendor/route-hungry';
    }

    public function capabilities(): array
    {
        return ['bindings', 'routes'];
    }

    public function register(Container $container): void
    {
    }
}

test('plugin registers bindings into the container via explicit registration', function () {
    $container = new Container();
    $registrar = new PluginRegistrar($container);
    $registrar->register(new Test_AnalyticsPlugin());

    $service = $container->get(Test_AnalyticsService::class);
    assertSame('tracked:signup', $service->track('signup'));
    assertSame(['vendor/analytics'], $registrar->registeredPlugins());
});

test('duplicate plugin registration throws', function () {
    $registrar = new PluginRegistrar(new Container());
    $registrar->register(new Test_AnalyticsPlugin());
    assertThrows(ContainerException::class, fn () => $registrar->register(new Test_AnalyticsPlugin()));
});

test('capability allow-list blocks plugins declaring capabilities the app forbids', function () {
    $registrar = new PluginRegistrar(new Container(), allowedCapabilities: ['bindings']);
    // Allowed: only declares bindings.
    $registrar->register(new Test_AnalyticsPlugin());
    // Blocked: declares routes, which the app did not allow.
    assertThrows(ContainerException::class, fn () => $registrar->register(new Test_RouteHungryPlugin()));
});

test('null allow-list permits all capabilities', function () {
    $registrar = new PluginRegistrar(new Container());
    $registrar->register(new Test_RouteHungryPlugin());
    assertSame(['vendor/route-hungry'], $registrar->registeredPlugins());
});
