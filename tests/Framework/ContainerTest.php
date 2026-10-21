<?php

declare(strict_types=1);

namespace Tests\Framework;

use LombokClarion\Container\Container;
use LombokClarion\Container\Exceptions\ContainerException;
use LombokClarion\Container\Exceptions\NotFoundException;
use PHPUnit\Framework\TestCase;

final class FixtureA
{
}

final class FixtureB
{
    public function __construct(public readonly FixtureA $a)
    {
    }
}

final class FixtureC
{
    public function __construct(public readonly string $name)
    {
    }
}

final class ContainerTest extends TestCase
{
    public function testInstanceBindingReturnsExactObject(): void
    {
        $container = new Container();
        $shared = new FixtureA();
        $container->instance(FixtureA::class, $shared);

        $this->assertSame($shared, $container->get(FixtureA::class));
    }

    public function testSingletonResolvesOnce(): void
    {
        $container = new Container();
        $container->singleton(FixtureA::class, FixtureA::class);

        $this->assertSame($container->get(FixtureA::class), $container->get(FixtureA::class));
    }

    public function testBindResolvesFreshInstances(): void
    {
        $container = new Container();
        $container->bind(FixtureA::class, FixtureA::class);

        $this->assertNotSame($container->get(FixtureA::class), $container->get(FixtureA::class));
    }

    public function testReflectionAutowiresConstructorDependencies(): void
    {
        $container = new Container();
        $container->singleton(FixtureA::class, FixtureA::class);

        $b = $container->get(FixtureB::class);

        $this->assertInstanceOf(FixtureB::class, $b);
        $this->assertSame($container->get(FixtureA::class), $b->a);
    }

    public function testScalarDependencyWithoutBindingThrows(): void
    {
        $container = new Container();

        $this->expectException(ContainerException::class);
        $container->get(FixtureC::class);
    }

    public function testClosureFactoryReceivesContainer(): void
    {
        $container = new Container();
        $container->singleton(FixtureC::class, function (Container $c) {
            return new FixtureC('made-by-factory');
        });

        $this->assertSame('made-by-factory', $container->get(FixtureC::class)->name);
    }

    public function testUnknownClassThrowsNotFoundException(): void
    {
        $container = new Container();

        $this->expectException(NotFoundException::class);
        $container->get('No\\Such\\Class');
    }

    public function testHasReportsBoundIds(): void
    {
        $container = new Container();
        $container->singleton(FixtureA::class, FixtureA::class);

        $this->assertTrue($container->has(FixtureA::class));
        $this->assertFalse($container->has('No\\Such\\Class'));
    }

    public function testInterfaceBindingIsNotAutowiredImplicitly(): void
    {
        $container = new Container();

        // Interfaces must be explicitly bound — that is the framework's
        // "explicit over magic" contract.
        $this->expectException(ContainerException::class);
        $container->get(\LombokClarion\Container\ContainerInterface::class);
    }
}
