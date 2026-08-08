<?php

declare(strict_types=1);

use App\Domain\Widget\WidgetRepositoryInterface;
use App\Infrastructure\Persistence\SqlWidgetRepository;
use App\Infrastructure\ServiceFactories;
use LombokClarion\Bus\CommandBus;
use LombokClarion\Bus\EventBus;
use LombokClarion\Bus\QueryBus;
use LombokClarion\Container\Container;
use App\Infrastructure\Auth\WidgetPolicy;
use LombokClarion\Auth\AuthManager;
use LombokClarion\Auth\Gate;
use LombokClarion\Auth\RoleRepository;
use LombokClarion\Auth\TokenIssuer;
use LombokClarion\Auth\UserProvider;
use LombokClarion\Security\Argon2idPasswordHasher;
use LombokClarion\Security\CsrfTokenManager;
use LombokClarion\Security\InMemoryRateLimitStore;
use LombokClarion\Security\PasswordHasher;
use LombokClarion\Security\RateLimitStore;
use LombokClarion\Security\SecurityConfig;
use LombokClarion\Storage\Storage;
use LombokClarion\View\AssetManifest;
use LombokClarion\Http\TenantAwareConnection;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Http\Errors\ErrorHandler;
use LombokClarion\Http\Errors\ErrorPageRenderer;
use LombokClarion\Log\Logger;
use LombokClarion\View\Theme;
use LombokClarion\View\ViewEngine;

/**
 * Returns a fully-wired dev-mode Container. This same function is used to
 * boot the app directly (uncompiled) in local dev, AND as the source of
 * truth `lombokclarion optimize` reads from to produce
 * services.compiled.php (see bin/lombokclarion's `optimize` wiring).
 */
return function (): Container {
    $container = new Container();

    // Live handles (PDO, ConnectionManager) come from bootstrap/externals.php —
    // the same file the optimized boot path uses, so dev and compiled boots
    // bind identical objects. See finding #40 for why this is one file.
    foreach ((require __DIR__ . '/externals.php')() as $id => $instance) {
        $container->instance($id, $instance);
    }

    $container->singleton(TenantAwareConnection::class, [ServiceFactories::class, 'tenantAwareConnection']);

    // --- Domain bindings -----------------------------------------------
    $container->bind(WidgetRepositoryInterface::class, SqlWidgetRepository::class);

    // --- Bus -------------------------------------------------------------
    $container->singleton(CommandBus::class, [ServiceFactories::class, 'commandBus']);
    $container->singleton(QueryBus::class, [ServiceFactories::class, 'queryBus']);
    $container->singleton(EventBus::class, [ServiceFactories::class, 'eventBus']);

    // --- Security ---------------------------------------------------------
    $container->singleton(SecurityConfig::class, [ServiceFactories::class, 'securityConfig']);
    $container->bind(PasswordHasher::class, Argon2idPasswordHasher::class);
    $container->singleton(CsrfTokenManager::class, [ServiceFactories::class, 'csrfTokenManager']);
    $container->singleton(RateLimitStore::class, [ServiceFactories::class, 'rateLimitStore']);

    // --- auth (lombokclarion/auth) ---------------------------------------
    $container->singleton(TokenIssuer::class, [ServiceFactories::class, 'tokenIssuer']);
    $container->singleton(UserProvider::class, [ServiceFactories::class, 'userProvider']);
    $container->singleton(RoleRepository::class, [ServiceFactories::class, 'roleRepository']);
    $container->singleton(Gate::class, [ServiceFactories::class, 'gate']);
    // Array-callable, not a raw closure: OptimizeCommand compiles the container
    // into services.compiled.php, and only [Factory::class, 'method'] bindings
    // survive that (a Closure cannot be written to a PHP file). The compiler
    // rejecting a closure here is the intended guardrail, not a bug — see
    // AUDIT-TRAIL #37.
    $container->singleton(WidgetPolicy::class, [ServiceFactories::class, 'widgetPolicy']);
    // NOT a singleton: AuthManager holds the per-request RequestContext, so a
    // shared instance would leak the authenticated user between requests under
    // a persistent runtime (Swoole). Bound per resolution on purpose.
    $container->bind(AuthManager::class, [ServiceFactories::class, 'authManager']);
    // Per-resolution like AuthManager, and for the same reason: Authenticate
    // holds an AuthManager holding the CURRENT RequestContext. Resolved by the
    // Kernel per request (class-string middleware), after the per-request
    // context is bound — never eagerly at routes.php build time, where it
    // would capture a boot context no request ever sees (finding #41).
    $container->bind(\LombokClarion\Auth\Authenticate::class, [ServiceFactories::class, 'authenticate']);
    $container->singleton(\LombokClarion\Http\RequestContext::class, [ServiceFactories::class, 'requestContext']);
    $container->bind(InMemoryRateLimitStore::class, [ServiceFactories::class, 'rateLimitStore']);

    // --- Storage (lombokclarion/storage) -----------------------------------
    // Interface → LocalStorage rooted at storage/app (ServiceFactories::storage).
    // Singleton is safe: LocalStorage holds only its root string, no per-request
    // state. Array-callable so `optimize` can compile it (see note above / #37).
    $container->singleton(Storage::class, [ServiceFactories::class, 'storage']);

    // --- View / theme / assets ---------------------------------------------
    $container->singleton(ViewEngine::class, [ServiceFactories::class, 'viewEngine']);
    $container->singleton(Theme::class, [ServiceFactories::class, 'theme']);

    // Stage 17. Bound by INTERFACE (Logger, ErrorPageRenderer) so an app can
    // swap either without touching anything that depends on them — and so
    // nothing downstream is tempted to type-hint a concrete handler stack.
    $container->singleton(Logger::class, [ServiceFactories::class, 'logger']);
    $container->singleton(ErrorPageRenderer::class, [ServiceFactories::class, 'errorPageRenderer']);
    $container->singleton(ErrorHandler::class, [ServiceFactories::class, 'errorHandler']);
    $container->singleton(AssetManifest::class, [ServiceFactories::class, 'assetManifest']);

    return $container;
};
