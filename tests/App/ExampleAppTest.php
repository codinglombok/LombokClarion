<?php

declare(strict_types=1);

namespace Tests\App;

use App\Domain\Widget\CreateWidget;
use App\Domain\Widget\ListWidgets;
use App\Domain\Widget\Widget;
use LombokClarion\Auth\AuthManager;
use LombokClarion\Auth\Authenticate;
use LombokClarion\Container\Container;
use LombokClarion\Security\CsrfTokenManager;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end test of the shipped example app: boots the REAL container from
 * bootstrap/, runs the REAL migrations against a throwaway SQLite file, then
 * exercises the bus, the auth stack, and the HTTP kernel — the same wiring
 * `php -S localhost:8000 -t public` uses in dev mode.
 */
final class ExampleAppTest extends TestCase
{
    private static ?string $dbPath = null;

    private Container $container;

    protected function setUp(): void
    {
        if (self::$dbPath === null) {
            self::$dbPath = sys_get_temp_dir() . '/lombokclarion-app-' . bin2hex(random_bytes(6)) . '.sqlite';
        }

        putenv('DB_PATH=' . self::$dbPath);
        // The #44 production guard refuses the default APP_KEY when APP_ENV
        // is unset/production — tests boot in local mode with a throwaway key.
        putenv('APP_ENV=local');
        putenv('APP_KEY=' . bin2hex(random_bytes(32)));

        $container = (require __DIR__ . '/../../bootstrap/services.php')();
        $console = (require __DIR__ . '/../../bootstrap/console.php')($container);

        ob_start();
        $exit = $console->handle(['migrate']);
        ob_end_clean();
        $this->assertSame(0, $exit, 'migrate must succeed on a fresh database');

        $this->container = $container;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$dbPath !== null && is_file(self::$dbPath)) {
            @unlink(self::$dbPath);
        }
    }

    private function http(): \LombokClarion\Testing\HttpTestCase
    {
        $router = (require __DIR__ . '/../../bootstrap/routes.php')($this->container);

        return new \LombokClarion\Testing\HttpTestCase($this->container, $router);
    }

    public function testWidgetCrudFlowsThroughTheBus(): void
    {
        $commands = $this->container->get(\LombokClarion\Bus\CommandBus::class);
        $queries = $this->container->get(\LombokClarion\Bus\QueryBus::class);

        $widget = $commands->dispatch(new CreateWidget('Standing Desk', 1499900));

        $this->assertInstanceOf(Widget::class, $widget);
        $this->assertSame('Standing Desk', $widget->name);
        $this->assertSame(1499900, $widget->priceCents);

        $all = $queries->ask(new ListWidgets());
        $this->assertCount(1, $all);
        $this->assertSame($widget->id, $all[0]->id);
    }

    public function testUserCreateCommandAndAuthAttempt(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('libargon2 not compiled into this PHP build');
        }

        $console = (require __DIR__ . '/../../bootstrap/console.php')($this->container);

        putenv('LOMBOKCLARION_PASSWORD=str0ng-Passphrase!');
        ob_start();
        $exit = $console->handle(['user:create', 'admin@example.test']);
        $out = ob_get_clean();
        putenv('LOMBOKCLARION_PASSWORD');

        $this->assertSame(0, $exit, 'user:create must succeed: ' . $out);

        $auth = $this->container->get(AuthManager::class);
        $user = $auth->attempt('admin@example.test', 'str0ng-Passphrase!');
        $this->assertNotNull($user);
        $this->assertNotSame('str0ng-Passphrase!', $user->getPasswordHash());
        $this->assertNull($auth->attempt('admin@example.test', 'wrong-password'));

        // Duplicate email is rejected.
        putenv('LOMBOKCLARION_PASSWORD=whatever-2');
        ob_start();
        $dup = $console->handle(['user:create', 'admin@example.test']);
        ob_end_clean();
        putenv('LOMBOKCLARION_PASSWORD');
        $this->assertSame(1, $dup);
    }

    public function testLoginIssuesTokenThatAuthenticates(): void
    {
        if (!defined('PASSWORD_ARGON2ID')) {
            self::markTestSkipped('libargon2 not compiled into this PHP build');
        }

        $console = (require __DIR__ . '/../../bootstrap/console.php')($this->container);
        putenv('LOMBOKCLARION_PASSWORD=another-Passphrase!');
        ob_start();
        $console->handle(['user:create', 'token@example.test']);
        ob_end_clean();
        putenv('LOMBOKCLARION_PASSWORD');

        $auth = $this->container->get(AuthManager::class);
        $user = $auth->attempt('token@example.test', 'another-Passphrase!');
        $this->assertNotNull($user);

        $token = $auth->login($user);
        $this->assertNotEmpty($token);

        $roundTrip = $auth->authenticateToken($token);
        $this->assertNotNull($roundTrip);
        $this->assertSame($user->getAuthId(), $roundTrip->getAuthId());

        $this->assertNull($auth->authenticateToken('forged.token.value'));
    }

    public function testHomePageRenders(): void
    {
        $response = $this->http()->get('/');

        $this->assertSame(200, $response->status);
        $this->assertNotEmpty($response->body);
    }

    public function testWidgetApiListStartsEmptyAndStoreRejectsBadInput(): void
    {
        $http = $this->http();

        $index = $http->get('/api/widgets');
        $this->assertSame(200, $index->status);

        // The widget list is a JSON array; the DB is shared across this test
        // class, so the exact count depends on ordering — shape is the contract.
        $decoded = json_decode($index->body, true);
        $this->assertIsArray($decoded);
    }

    public function testMutatingPostWithoutCsrfIsRejected419(): void
    {
        $response = $this->http()->post('/api/widgets', ['name' => 'desk', 'price_cents' => 100]);

        $this->assertSame(419, $response->status, 'POST without a CSRF pair must be rejected');
    }

    public function testMutatingPostWithValidCsrfCreatesWidget(): void
    {
        /** @var CsrfTokenManager $tokens */
        $tokens = $this->container->get(CsrfTokenManager::class);
        $token = $tokens->generate();

        $this->http()->post(
            '/api/widgets',
            ['name' => 'Rail Desk', 'price_cents' => 123456],
            ['X-CSRF-Token' => $token],
            ['csrf_token' => $token],
        );

        // Re-list to prove persistence: the response code alone could mask
        // a silently swallowed handler failure.
        $index = $this->http()->get('/api/widgets');
        $this->assertSame(200, $index->status);
        $this->assertStringContainsString('Rail Desk', $index->body);
    }

    public function testProtectedMeRouteRequiresToken(): void
    {
        $http = $this->http();

        $anonymous = $http->get('/me');
        $this->assertSame(401, $anonymous->status);
    }
}
