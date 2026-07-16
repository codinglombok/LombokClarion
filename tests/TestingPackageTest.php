<?php

declare(strict_types=1);

use LombokClarion\Bus\CommandHandler;
use LombokClarion\Container\CompiledContainer;
use LombokClarion\Container\Container;
use LombokClarion\Container\ContainerCompiler;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Routing\Router;
use LombokClarion\Testing\ColdStartTest;
use LombokClarion\Testing\FakeCommandBus;
use LombokClarion\Testing\HttpTestCase;
use LombokClarion\Testing\InMemoryRepository;

interface Test_WidgetRepositoryInterface
{
    public function save(string $id, string $name): void;
    public function findName(string $id): ?string;
}

final class Test_InMemoryWidgetRepository extends InMemoryRepository implements Test_WidgetRepositoryInterface
{
    public function save(string $id, string $name): void
    {
        $this->put($id, (object) ['name' => $name]);
    }

    public function findName(string $id): ?string
    {
        $item = $this->find($id);
        return $item?->name;
    }
}

test('InMemoryRepository stores and retrieves entities without a database', function () {
    $repo = new Test_InMemoryWidgetRepository();
    $repo->save('1', 'Lamp');
    assertSame('Lamp', $repo->findName('1'));
    assertSame(null, $repo->findName('missing'));
});

final class Test_PingCommand
{
}

final class Test_WidgetController
{
    public function __construct(private readonly Test_WidgetRepositoryInterface $repo)
    {
    }

    public function show(Request $request): Response
    {
        $name = $this->repo->findName((string) $request->attribute('id'));
        return $name === null ? Response::text('not found', 404) : Response::text($name);
    }
}

test('HttpTestCase boots the real container and allows explicit override()', function () {
    $container = new Container();
    $container->bind(Test_WidgetRepositoryInterface::class, Test_InMemoryWidgetRepository::class);

    $router = new Router();
    $router->get('/widgets/{id}', [Test_WidgetController::class, 'show']);

    $http = new HttpTestCase($container, $router);

    // Override with a pre-seeded fake repository.
    $fakeRepo = new Test_InMemoryWidgetRepository();
    $fakeRepo->save('42', 'Overridden Lamp');
    $http->override(Test_WidgetRepositoryInterface::class, $fakeRepo);

    $response = $http->get('/widgets/42');
    assertSame(200, $response->status);
    assertSame('Overridden Lamp', $response->body);
});

test('FakeCommandBus records dispatched commands without running a real handler', function () {
    $bus = new FakeCommandBus();
    $bus->willReturn(Test_PingCommand::class, 'pong');

    $result = $bus->dispatch(new Test_PingCommand());
    assertSame('pong', $result);
    assertTrue($bus->wasDispatched(Test_PingCommand::class));
});

test('ColdStartTest passes for a trivial compiled container within a generous budget', function () {
    $dev = new Container();
    $dev->singleton(Test_InMemoryWidgetRepository::class, Test_InMemoryWidgetRepository::class);
    $source = (new ContainerCompiler())->compile($dev, [Test_InMemoryWidgetRepository::class]);
    $tmp = tempnam(sys_get_temp_dir(), 'coldstart_') . '.php';
    file_put_contents($tmp, $source);

    $test = new ColdStartTest();
    // Generous budget for this sandboxed, non-opcache test environment —
    // real CI enforces the ~5ms (5000µs) production budget from §5.
    $elapsed = $test->assertContainerBootUnder($tmp, 50_000.0, [Test_InMemoryWidgetRepository::class]);
    assertTrue($elapsed >= 0);

    unlink($tmp);
});

test('ColdStartTest fails loudly when the budget is exceeded', function () {
    $dev = new Container();
    $source = (new ContainerCompiler())->compile($dev);
    $tmp = tempnam(sys_get_temp_dir(), 'coldstart_') . '.php';
    file_put_contents($tmp, $source);

    $test = new ColdStartTest();
    assertThrows(RuntimeException::class, fn () => $test->assertContainerBootUnder($tmp, 0.0000001, []));

    unlink($tmp);
});
