<?php

declare(strict_types=1);

namespace LombokClarion\Auth;

use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;

/**
 * Built via static factories rather than resolved from the container, because
 * the role or permission has to be baked in per route — the same shape as
 * RateLimit::perMinute():
 *
 *   $router->post('/posts/{id}', [PostController::class, 'update'], [
 *       new Authenticate($auth),
 *       Authorize::permission('post.edit', $auth, $roles),
 *   ]);
 *
 * Order matters and is left visible: Authorize on its own returns 401, not 403,
 * when nobody is authenticated. It refuses to guess that an anonymous visitor
 * "lacks permission" — they lack identity, which is a different answer and a
 * different status code.
 *
 * BOOT-CONTEXT TRAP (U-S2-1, same bug class as AUDIT-TRAIL #41): the example
 * above is only safe when the routes file is (re)evaluated per request. If
 * your routes file runs once at BOOT — the normal case — an AuthManager
 * instance captured there holds the boot RequestContext, which Authenticate
 * (resolved per request) never fills. Result: 401 on every request despite a
 * valid token. In a routes-file-at-boot application, use the *Lazy factories
 * below, which capture only the required string and defer service resolution
 * to handle(), when the per-request context is bound:
 *
 *   $router->get('/perkara', [PerkaraController::class, 'index'], [
 *       Authenticate::class,
 *       Authorize::permissionLazy('perkara.view',
 *           fn(): array => [$c->get(AuthManager::class), $c->get(RoleRepository::class)]),
 *   ]);
 */
final class Authorize implements Middleware
{
    private const ROLE = 'role';
    private const PERMISSION = 'permission';

    /**
     * @param (\Closure(): array{AuthManager, RoleRepository})|null $resolver
     *        when set, $auth/$roles are null and the pair is resolved inside
     *        handle() on every request — never captured at construction
     */
    private function __construct(
        private readonly string $kind,
        private readonly string $required,
        private readonly ?AuthManager $auth,
        private readonly ?RoleRepository $roles,
        private readonly ?\Closure $resolver = null,
    ) {
    }

    /**
     * Eager variant: captures the given AuthManager as-is. Only correct when
     * this line runs per request (see the boot-context trap in the class
     * docblock). If your routes file is evaluated at boot, use roleLazy().
     */
    public static function role(string $role, AuthManager $auth, RoleRepository $roles): self
    {
        return new self(self::ROLE, $role, $auth, $roles);
    }

    /**
     * Eager variant: captures the given AuthManager as-is. Only correct when
     * this line runs per request (see the boot-context trap in the class
     * docblock). If your routes file is evaluated at boot, use
     * permissionLazy().
     */
    public static function permission(string $permission, AuthManager $auth, RoleRepository $roles): self
    {
        return new self(self::PERMISSION, $permission, $auth, $roles);
    }

    /**
     * Boot-safe variant (U-S2-1): the instance carries only the role string;
     * AuthManager + RoleRepository are produced by $resolver inside handle(),
     * after the Kernel has bound the per-request context. No memoization on
     * purpose — caching the pair on the instance would recreate the trap in
     * any long-running runtime.
     *
     * @param callable(): array{AuthManager, RoleRepository} $resolver
     */
    public static function roleLazy(string $role, callable $resolver): self
    {
        return new self(self::ROLE, $role, null, null, \Closure::fromCallable($resolver));
    }

    /**
     * Boot-safe variant (U-S2-1). See roleLazy().
     *
     * @param callable(): array{AuthManager, RoleRepository} $resolver
     */
    public static function permissionLazy(string $permission, callable $resolver): self
    {
        return new self(self::PERMISSION, $permission, null, null, \Closure::fromCallable($resolver));
    }

    public function handle(Request $request, callable $next): Response
    {
        if ($this->resolver !== null) {
            [$auth, $roles] = ($this->resolver)();
            if (!$auth instanceof AuthManager || !$roles instanceof RoleRepository) {
                throw new \RuntimeException(
                    'Authorize lazy resolver must return array{AuthManager, RoleRepository}'
                );
            }
        } else {
            $auth = $this->auth;
            $roles = $this->roles;
            assert($auth !== null && $roles !== null);
        }

        $user = $auth->user();

        if ($user === null) {
            return Response::text('Unauthorized', 401)
                ->withHeader('WWW-Authenticate', 'Bearer');
        }

        $granted = $this->kind === self::ROLE
            ? in_array($this->required, $roles->rolesFor($user->getAuthId()), true)
            : in_array($this->required, $roles->permissionsFor($user->getAuthId()), true);

        if (!$granted) {
            // The body says nothing about what was required. Telling a user which
            // permission they lack maps out the authorization model for anyone
            // probing it; the detail belongs in your logs, not the response.
            return Response::text('Forbidden', 403);
        }

        return $next($request);
    }
}
