<?php

declare(strict_types=1);

namespace LombokClarion\LaravelFlavor;

use LombokClarion\ActiveRecord\Model;
use LombokClarion\Container\ContainerInterface;
use LombokClarion\Facades\Facade;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Security\SecurityHeaders;

/**
 * OPTIONAL PACKAGE — `lombokclarion/laravel-flavor`.
 *
 * The entire "magic" surface of the framework, enabled by ONE call:
 *
 *   // bootstrap/services.php — the single greppable line:
 *   $globalMiddleware = LaravelFlavor::enable($container);
 *
 * After it: facades resolve (Bus/Hash/Event from lombokclarion/facades,
 * plus Auth and DB from this package), ActiveRecord models have their
 * connection, and the return value is the recommended global middleware
 * list to hand to the Kernel.
 *
 * What this deliberately is NOT:
 *
 *  - Not auto-detected. Nothing scans for this class or calls it for
 *    you. An app that never writes the line gets none of the magic, and
 *    `grep -rn "LaravelFlavor::enable"` answers "is the magic on?" with
 *    one line — which is the entire point of funnelling every implicit
 *    surface through a single explicit call.
 *  - Not a service provider system. There is no register()/boot()
 *    lifecycle, no deferred providers, no package discovery. One static
 *    method, ordinary code, runs once at bootstrap.
 *  - Not exempt from the boundary. This package carries the same
 *    `forbidden-layers: ["app/Domain"]` metadata as facades and
 *    active-record, and check-domain-boundary fails the build if any
 *    domain class imports LombokClarion\LaravelFlavor\*. The magic is
 *    for controllers and infrastructure; the domain stays injected.
 *
 * The middleware list is RETURNED, not installed: the Kernel takes its
 * global middleware at construction, and this method refuses to reach
 * into a Kernel behind your back. The wiring stays visible at the one
 * place the Kernel is built:
 *
 *   $kernel = new Kernel($container, $router, LaravelFlavor::enable($container));
 */
final class LaravelFlavor
{
    /**
     * @param 'default'|string $connection which named connection ActiveRecord
     *        models write through (the manager's write side — AR does not
     *        read/write split; a model save must never hit a replica)
     * @return list<class-string> recommended global middleware for the Kernel
     */
    public static function enable(ContainerInterface $container, string $connection = 'default'): array
    {
        // 1. Facades: one container, set once. Covers Bus/Hash/Event AND the
        //    Auth/DB facades in this package — Facade::$container is shared by
        //    design, so this is a single switch, not one per facade.
        Facade::setContainer($container);

        // 2. ActiveRecord: models talk to the named connection's write side.
        //    Resolved through the Stage 10 manager so AR inherits its caching
        //    and error mode instead of holding a second hand-opened PDO.
        Model::setConnection($container->get(ConnectionManager::class)->write($connection));

        // 3. The "sensible defaults" are just security headers. CSRF, rate
        //    limiting and auth are PER-ROUTE decisions in this framework —
        //    globalizing them is how APIs break mysteriously (CSRF on a
        //    Bearer-token route) and how login endpoints end up unthrottled
        //    because "the global limiter has it". SecurityHeaders is the one
        //    middleware that is correct on literally every response,
        //    including 404s — which is exactly what global middleware wraps.
        return [SecurityHeaders::class];
    }

    /**
     * Undo enable() — for tests and warm workers that recycle a process
     * across bootstraps. Facade state is process-global static; anything
     * that sets it should be able to unset it.
     */
    public static function disable(): void
    {
        Facade::clearContainer();
        Model::clearConnection();
    }
}
