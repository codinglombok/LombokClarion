<?php

declare(strict_types=1);

namespace LombokClarion\Facades;

use LombokClarion\Container\ContainerInterface;
use RuntimeException;

/**
 * OPTIONAL PACKAGE — `lombokclarion/facades`.
 *
 * This exists ONLY for teams that genuinely prefer the static-access
 * pattern and knowingly trade explicit injection for brevity. It is
 * explicitly isolated from core and the domain layer:
 *
 *  - `composer.json` carries `forbidden-layers: ["app/Domain"]`.
 *  - The `lombokclarion optimize` step checks this metadata and fails
 *    the build if a domain class imports LombokClarion\Facades\*.
 *  - The base class requires an explicit setContainer() call in bootstrap
 *    — it never discovers or installs itself automatically (§2.1, §2.5).
 *
 * Usage:
 *
 *   // In bootstrap/services.php (opt-in, never default):
 *   \LombokClarion\Facades\Facade::setContainer($container);
 *
 *   // In a controller or infrastructure class (NEVER in app/Domain/**):
 *   use LombokClarion\Facades\Bus;
 *   Bus::dispatch(new CreateWidget('Lamp', 999));
 *
 * Under the hood this is still real dependency injection: the facade
 * resolves the backing service from the container every time, so the
 * container's binding (explicit, typed, one file) is still the source
 * of truth. The facade is syntactic sugar, not a different wiring path.
 */
abstract class Facade
{
    private static ?ContainerInterface $container = null;

    /**
     * Must be called explicitly in bootstrap/services.php if (and ONLY if)
     * the app opts into facades. There is no auto-detection.
     */
    public static function setContainer(ContainerInterface $container): void
    {
        self::$container = $container;
    }

    public static function clearContainer(): void
    {
        self::$container = null;
    }

    /**
     * @return class-string the container ID this facade resolves to
     */
    abstract protected static function accessor(): string;

    /**
     * @param list<mixed> $arguments
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        if (self::$container === null) {
            throw new RuntimeException(
                'Facade container not set. Call Facade::setContainer($container) in bootstrap/services.php ' .
                'to opt into facades. If you did not intend to use facades, inject the service directly.'
            );
        }

        $instance = self::$container->get(static::accessor());

        return $instance->{$method}(...$arguments);
    }
}
