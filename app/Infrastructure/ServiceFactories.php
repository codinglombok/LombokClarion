<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\Widget\CreateWidget;
use App\Domain\Widget\CreateWidgetHandler;
use App\Domain\Widget\ListWidgets;
use App\Domain\Widget\ListWidgetsHandler;
use LombokClarion\Bus\CommandBus;
use LombokClarion\Bus\EventBus;
use LombokClarion\Bus\QueryBus;
use LombokClarion\Container\ContainerInterface;
use App\Infrastructure\Auth\DatabaseUserProvider;
use App\Infrastructure\Auth\WidgetPolicy;
use LombokClarion\Auth\AuthManager;
use LombokClarion\Auth\DatabaseRoleRepository;
use LombokClarion\Auth\Gate;
use LombokClarion\Auth\Policy;
use LombokClarion\Auth\RoleRepository;
use LombokClarion\Auth\TokenIssuer;
use LombokClarion\Auth\UserProvider;
use LombokClarion\Http\Errors\ErrorHandler;
use LombokClarion\Http\Errors\ErrorPageRenderer;
use LombokClarion\Http\RequestContext;
use LombokClarion\Http\TenantAwareConnection;
use LombokClarion\Log\ChannelLogger;
use LombokClarion\Log\Handlers\StreamHandler;
use LombokClarion\Log\LogLevel;
use LombokClarion\Log\Logger;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Security\CsrfTokenManager;
use PDO;
use LombokClarion\Security\InMemoryRateLimitStore;
use LombokClarion\Security\SecurityConfig;
use LombokClarion\View\AssetManifest;
use LombokClarion\View\Theme;
use LombokClarion\View\ViewEngine;
use LombokClarion\View\ViewErrorPageRenderer;

/**
 * ContainerCompiler cannot bake a raw Closure into services.compiled.php,
 * so every closure binding in bootstrap/services.php is written as an
 * array callable pointing here — [ServiceFactories::class, 'commandBus'],
 * etc. — which the compiler CAN emit as a plain static call with zero
 * runtime reflection (see LombokClarion\Container\ContainerCompiler).
 */
final class ServiceFactories
{
    public static function commandBus(ContainerInterface $c): CommandBus
    {
        $bus = new CommandBus($c);
        $bus->register(CreateWidget::class, CreateWidgetHandler::class);
        return $bus;
    }

    public static function queryBus(ContainerInterface $c): QueryBus
    {
        $bus = new QueryBus($c);
        $bus->register(ListWidgets::class, ListWidgetsHandler::class);
        return $bus;
    }

    public static function eventBus(ContainerInterface $c): EventBus
    {
        return new EventBus($c);
    }

    public static function securityConfig(ContainerInterface $c): SecurityConfig
    {
        return new SecurityConfig();
    }

    public static function csrfTokenManager(ContainerInterface $c): CsrfTokenManager
    {
        return new CsrfTokenManager(self::appKey());
    }

    public static function tokenIssuer(ContainerInterface $c): TokenIssuer
    {
        // Same APP_KEY as CSRF, different purpose. Both are HMAC over a secret;
        // neither is interchangeable with the other because the payload shapes
        // differ, and a token from one will not verify as the other.
        return new TokenIssuer(self::appKey(), ttlSeconds: 7200);
    }

    public static function userProvider(ContainerInterface $c): UserProvider
    {
        return new DatabaseUserProvider($c->get(PDO::class));
    }

    public static function roleRepository(ContainerInterface $c): RoleRepository
    {
        return new DatabaseRoleRepository($c->get(PDO::class));
    }

    /**
     * The one place APP_KEY is read — with the production guard the deploy
     * checklist demanded (AUDIT-TRAIL #44). Before this, forgetting APP_KEY
     * meant every such deployment silently shared the hardcoded
     * 'dev-secret-change-me' — a PUBLIC string in this repo — as the HMAC
     * secret for BOTH auth tokens and CSRF: anyone who has read the source
     * can forge a login. In non-local environments that is now a boot
     * failure naming the fix, because "the login page is down" is a better
     * morning than "everyone could sign their own tokens for a month".
     */
    public static function appKey(): string
    {
        $key = getenv('APP_KEY') ?: '';
        $env = getenv('APP_ENV') ?: 'production'; // unset env means prod: fail safe, not open

        $isLocal = in_array($env, ['local', 'dev', 'development', 'testing'], true);

        if (!$isLocal && ($key === '' || $key === 'dev-secret-change-me' || str_starts_with($key, 'change-me') || strlen($key) < 32)) {
            throw new \RuntimeException(
                'APP_KEY is missing, still the repo default, or shorter than 32 chars while APP_ENV=' . $env . '. ' .
                'Generate one: `openssl rand -hex 32`, set it via the environment/secret manager, and redeploy. ' .
                'Refusing to boot with a publicly-known HMAC secret.'
            );
        }

        return $key !== '' ? $key : 'dev-secret-change-me';
    }

    public static function storage(): \LombokClarion\Storage\LocalStorage
    {
        // storage/app: sibling of storage/views and the compiled artifacts,
        // OUTSIDE public/ — nothing here is web-reachable by path. Anything
        // meant to be served goes through a controller that authorizes first;
        // "uploads are private until proven public" is the default, not the
        // exception.
        return new \LombokClarion\Storage\LocalStorage(dirname(__DIR__, 2) . '/storage/app');
    }

    public static function requestContext(): RequestContext
    {
        // Explicitly bound so the COMPILED graph contains it. Before this,
        // RequestContext resolved only via the dev container's reflection
        // autowire fallback — invisible to the compiler, so the compiled boot
        // died with NotFound the moment anything asked for it (finding #41).
        // The Kernel still binds a fresh instance per request when none
        // exists; this binding is the explicit declaration + compiled-path
        // fallback, and both orders converge on one shared context.
        return new RequestContext();
    }

    public static function authenticate(ContainerInterface $c): \LombokClarion\Auth\Authenticate
    {
        return new \LombokClarion\Auth\Authenticate($c->get(AuthManager::class));
    }

    public static function authManager(ContainerInterface $c): AuthManager
    {
        // No TokenStore wired here: this example runs stateless, so logout is a
        // client-side cookie clear and says so (see AuthController::logout,
        // which reports revoked_server_side=false rather than implying more).
        // Pass an InMemoryTokenStore/DatabaseTokenStore here to buy revocation.
        return new AuthManager(
            $c->get(UserProvider::class),
            $c->get(\LombokClarion\Security\PasswordHasher::class),
            $c->get(TokenIssuer::class),
            $c->get(RequestContext::class),
        );
    }

    public static function widgetPolicy(ContainerInterface $c): WidgetPolicy
    {
        return new WidgetPolicy($c->get(RoleRepository::class));
    }

    public static function gate(ContainerInterface $c): Gate
    {
        // Policies resolve through the container, so they can have dependencies.
        $gate = new Gate(static fn (string $class): Policy => $c->get($class));

        // Every ability this application has, listed here. Not scanned, not
        // discovered — greppable, and visible in a diff when it changes (§2).
        $gate->define('widget.delete', WidgetPolicy::class);

        return $gate;
    }

    public static function tenantAwareConnection(ContainerInterface $c): TenantAwareConnection
    {
        // The manager is externallyProvided (it holds live PDO handles), but a
        // TenantAwareConnection is just wiring around it — compilable, provided
        // the binding is this array callable and not a raw closure. That raw
        // closure is exactly what `optimize` rejected; AUDIT-TRAIL #38.
        return new TenantAwareConnection($c->get(ConnectionManager::class), 'tenants');
    }

    public static function rateLimitStore(ContainerInterface $c): InMemoryRateLimitStore
    {
        return new InMemoryRateLimitStore();
    }

    public static function viewEngine(ContainerInterface $c): ViewEngine
    {
        return new ViewEngine(
            templatesPath: dirname(__DIR__, 2) . '/resources/views',
            cachePath: dirname(__DIR__, 2) . '/storage/views',
        );
    }

    public static function theme(ContainerInterface $c): Theme
    {
        return new Theme(getenv('THEME_STYLE') ?: 'resonant-stark');
    }

    public static function assetManifest(ContainerInterface $c): AssetManifest
    {
        return AssetManifest::fromFile(dirname(__DIR__, 2) . '/storage/assets.manifest.php');
    }

    /**
     * Stage 17. Debug output is gated on this, so it follows the same
     * fail-safe reading as appKey(): an UNSET APP_ENV means production.
     * Defaulting the other way would mean a container that lost its
     * environment starts serving stack traces to the public — the one
     * failure mode where "it still worked" is worse than a crash.
     *
     * APP_DEBUG can force it off within a local environment, but cannot turn
     * it ON outside one. That asymmetry is deliberate: forgetting to unset a
     * debug flag before deploying is the single most common way traces reach
     * production, and this makes the flag incapable of causing it.
     */
    public static function debugEnabled(): bool
    {
        $env = getenv('APP_ENV') ?: 'production';
        $isLocal = in_array($env, ['local', 'dev', 'development', 'testing'], true);

        if (!$isLocal) {
            return false;
        }

        $debug = strtolower((string) (getenv('APP_DEBUG') ?: 'true'));

        return !in_array($debug, ['0', 'false', 'off', 'no'], true);
    }

    public static function logger(ContainerInterface $c): Logger
    {
        $handlers = [
            new StreamHandler(
                path: dirname(__DIR__, 2) . '/storage/logs/app.log',
                minimumLevel: self::debugEnabled() ? LogLevel::Debug : LogLevel::Info,
                daily: true,
                retentionDays: 14,
            ),
        ];

        return new ChannelLogger($handlers, channel: 'app');
    }

    public static function errorPageRenderer(ContainerInterface $c): ErrorPageRenderer
    {
        // The view-backed renderer already falls back to the dependency-free
        // one when a template is missing or the view layer is broken, so
        // wiring this is not a bet on templates always working.
        return new ViewErrorPageRenderer($c->get(ViewEngine::class));
    }

    public static function errorHandler(ContainerInterface $c): ErrorHandler
    {
        return new ErrorHandler(
            renderer: $c->get(ErrorPageRenderer::class),
            debug: self::debugEnabled(),
            logger: $c->get(Logger::class),
            // No reporters by default: no vendor SDK is a dependency of this
            // framework. An app adds its own here.
            reporters: [],
        );
    }
}
