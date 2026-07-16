<?php

declare(strict_types=1);

use LombokClarion\Container\CompiledContainer;
use LombokClarion\Container\Container;
use LombokClarion\Container\ContainerCompiler;
use LombokClarion\Container\Exceptions\ContainerException;
use LombokClarion\Container\Exceptions\NotFoundException;

interface Test_GreeterInterface
{
    public function greet(): string;
}

final class Test_EnglishGreeter implements Test_GreeterInterface
{
    public function greet(): string
    {
        return 'hello';
    }
}

final class Test_Logger
{
    public array $lines = [];

    public function log(string $line): void
    {
        $this->lines[] = $line;
    }
}

final class Test_Service
{
    public function __construct(
        public readonly Test_GreeterInterface $greeter,
        public readonly Test_Logger $logger,
        public readonly string $env = 'dev',
    ) {
    }
}

final class Test_Unbindable
{
    public function __construct(public readonly string $apiKey)
    {
    }
}

final class Test_CircularA
{
    public function __construct(public Test_CircularB $b)
    {
    }
}

final class Test_CircularB
{
    public function __construct(public Test_CircularA $a)
    {
    }
}

final class Test_Factories
{
    public static function makeService(\LombokClarion\Container\ContainerInterface $c): Test_Service
    {
        return new Test_Service($c->get(Test_GreeterInterface::class), $c->get(Test_Logger::class), 'prod');
    }
}

test('resolves an unbound concrete class via autowiring', function () {
    $c = new Container();
    $logger = $c->get(Test_Logger::class);
    assertTrue($logger instanceof Test_Logger);
});

test('unbound interface throws NotFoundException', function () {
    $c = new Container();
    assertThrows(NotFoundException::class, fn () => $c->get(Test_GreeterInterface::class));
});

test('explicit interface binding resolves and autowires transitive deps', function () {
    $c = new Container();
    $c->bind(Test_GreeterInterface::class, Test_EnglishGreeter::class);
    $service = $c->get(Test_Service::class);
    assertTrue($service instanceof Test_Service);
    assertSame('hello', $service->greeter->greet());
    assertSame('dev', $service->env);
});

test('singleton returns the same instance every time', function () {
    $c = new Container();
    $c->singleton(Test_Logger::class, Test_Logger::class);
    $a = $c->get(Test_Logger::class);
    $b = $c->get(Test_Logger::class);
    assertTrue($a === $b, 'expected same instance');
});

test('non-singleton bind returns a fresh instance every time', function () {
    $c = new Container();
    $c->bind(Test_Logger::class, Test_Logger::class);
    $a = $c->get(Test_Logger::class);
    $b = $c->get(Test_Logger::class);
    assertTrue($a !== $b, 'expected distinct instances');
});

test('instance() registers a pre-built object', function () {
    $c = new Container();
    $logger = new Test_Logger();
    $c->instance(Test_Logger::class, $logger);
    assertTrue($c->get(Test_Logger::class) === $logger);
});

test('scalar constructor param with no default and no binding throws ContainerException', function () {
    $c = new Container();
    assertThrows(ContainerException::class, fn () => $c->get(Test_Unbindable::class));
});

test('closure binding can supply scalar-dependent objects', function () {
    $c = new Container();
    $c->bind(Test_Unbindable::class, fn () => new Test_Unbindable('secret-key'));
    $obj = $c->get(Test_Unbindable::class);
    assertSame('secret-key', $obj->apiKey);
});

test('circular dependency is detected', function () {
    $c = new Container();
    assertThrows(ContainerException::class, fn () => $c->get(Test_CircularA::class));
});

test('compiler produces a working compiled container with zero reflection at runtime', function () {
    $dev = new Container();
    $dev->bind(Test_GreeterInterface::class, Test_EnglishGreeter::class);
    // Anything a closure/array-callable factory needs must ALSO be bound
    // explicitly: the compiler cannot see inside a factory's body, only
    // inside constructor signatures. This is consistent with "explicit
    // over magic" (master prompt §2.5).
    $dev->bind(Test_Logger::class, Test_Logger::class);
    $dev->singleton(Test_Service::class, [Test_Factories::class, 'makeService']);

    $compiler = new ContainerCompiler();
    $source = $compiler->compile($dev, rootIds: [Test_Service::class]);

    $tmpFile = tempnam(sys_get_temp_dir(), 'compiled_') . '.php';
    file_put_contents($tmpFile, $source);

    $compiled = CompiledContainer::fromFile($tmpFile);
    $service = $compiled->get(Test_Service::class);

    assertTrue($service instanceof Test_Service);
    assertSame('hello', $service->greeter->greet());
    assertSame('prod', $service->env);

    // Singleton semantics preserved in compiled form.
    $service2 = $compiled->get(Test_Service::class);
    assertTrue($service === $service2);

    unlink($tmpFile);
});

test('compiled container throws NotFoundException for unbound interfaces, same as dev container', function () {
    $dev = new Container();
    $compiler = new ContainerCompiler();
    $source = $compiler->compile($dev);
    $tmpFile = tempnam(sys_get_temp_dir(), 'compiled_') . '.php';
    file_put_contents($tmpFile, $source);

    $compiled = CompiledContainer::fromFile($tmpFile);
    assertThrows(NotFoundException::class, fn () => $compiled->get(Test_GreeterInterface::class));

    unlink($tmpFile);
});

test('compiler rejects raw Closure bindings with a clear error', function () {
    $dev = new Container();
    $dev->bind('some.id', fn () => 'nope');
    $compiler = new ContainerCompiler();
    assertThrows(ContainerException::class, fn () => $compiler->compile($dev));
});
