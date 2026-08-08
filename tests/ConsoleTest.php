<?php

declare(strict_types=1);

use LombokClarion\Console\BuiltIn\AuditSecurityCommand;
use LombokClarion\Console\BuiltIn\AuditSqlCommand;
use LombokClarion\Console\BuiltIn\MigrateCommand;
use LombokClarion\Console\ConsoleKernel;
use LombokClarion\Container\Container;
use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Persistence\ConnectionConfig;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Persistence\Migration;
use LombokClarion\Persistence\SchemaBuilder;
use LombokClarion\Routing\Router;

final class Test_ConsoleCsrf implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request);
    }
}

final class Test_ConsoleSecurityHeaders implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request);
    }
}

final class Test_ConsoleAuth implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request);
    }
}

/**
 * A CSRF middleware the audit is told about by class-string, but which a route
 * supplies as an instance — the shape RateLimit and Authorize take. audit:security
 * must recognize it via instanceof, not just by class name (AUDIT-TRAIL #36).
 */
class Test_ConsoleCsrfByInstance implements Middleware
{
    public function handle(Request $request, callable $next): Response
    {
        return $next($request);
    }
}

final class Test_NoopMigration implements Migration
{
    public function up(SchemaBuilder $schema): void
    {
    }

    public function down(SchemaBuilder $schema): void
    {
    }

    public function runsInTransaction(SchemaBuilder $schema): bool
    {
        return false;
    }
}

/** @return array{0: ConsoleKernel, 1: ConnectionManager} */
function test_migrateConsole(): array
{
    $manager = new ConnectionManager([
        'default' => ConnectionConfig::provided('sqlite', new PDO('sqlite::memory:')),
        'reporting' => ConnectionConfig::provided('sqlite', new PDO('sqlite::memory:')),
    ], default: 'default');

    $container = new Container();
    $container->singleton(
        MigrateCommand::class,
        fn () => new MigrateCommand($manager, [Test_NoopMigration::class])
    );

    $console = new ConsoleKernel($container);
    $console->register(MigrateCommand::class);

    return [$console, $manager];
}

test('console kernel dispatches to a registered command by signature', function () {
    [$console] = test_migrateConsole();

    ob_start();
    $exit = $console->handle(['migrate']);
    $output = ob_get_clean();

    assertSame(0, $exit);
    assertTrue(str_contains($output, 'Migrated: ' . Test_NoopMigration::class));
});

test('migrate --connection= targets the named connection, not the default', function () {
    [$console, $manager] = test_migrateConsole();

    ob_start();
    $exit = $console->handle(['migrate', '--connection=reporting']);
    $output = ob_get_clean();

    assertSame(0, $exit);
    assertTrue(str_contains($output, 'on "reporting"'), $output);

    // The proof is not the message: the reporting DB has a migrations row and
    // the default DB has no migrations table at all, because nothing ever
    // touched it. Two isolated SQLite connections, one flag.
    $reporting = $manager->write('reporting')
        ->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    assertSame([Test_NoopMigration::class], $reporting);

    $defaultTables = $manager->write('default')
        ->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    assertSame([], $defaultTables);
});

test('migrate rejects an unknown connection instead of falling back to default', function () {
    [$console, $manager] = test_migrateConsole();

    ob_start();
    $exit = $console->handle(['migrate', '--connection=nope']);
    ob_get_clean();

    assertSame(1, $exit);

    // A rejected flag must not have migrated anything, least of all the default.
    $defaultTables = $manager->write('default')
        ->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
    assertSame([], $defaultTables);
});

test('migrate rejects an unknown argument (a typo must not silently hit default)', function () {
    [$console] = test_migrateConsole();

    ob_start();
    $exit = $console->handle(['migrate', '--connnection=reporting']);
    ob_get_clean();

    assertSame(1, $exit);
});

test('console kernel returns 1 for an unknown command', function () {
    $console = new ConsoleKernel(new Container());
    ob_start();
    $exit = $console->handle(['nope']);
    ob_get_clean();
    assertSame(1, $exit);
});

test('audit:security flags a POST route missing CSRF middleware', function () {
    $router = new Router();
    $router->post('/widgets', ['SomeController', 'store']);

    $command = new AuditSecurityCommand(
        $router,
        globalMiddleware: [],
        csrfMiddlewareClass: Test_ConsoleCsrf::class,
        securityHeadersMiddlewareClass: Test_ConsoleSecurityHeaders::class,
    );

    ob_start();
    $exit = $command->run([]);
    $output = ob_get_clean();

    assertSame(1, $exit);
    assertTrue(str_contains($output, 'missing ' . Test_ConsoleCsrf::class));
});

test('audit:security passes when CSRF and security headers middleware are present', function () {
    $router = new Router();
    $router->post('/widgets', ['SomeController', 'store'], [Test_ConsoleCsrf::class]);

    $command = new AuditSecurityCommand(
        $router,
        globalMiddleware: [Test_ConsoleSecurityHeaders::class],
        csrfMiddlewareClass: Test_ConsoleCsrf::class,
        securityHeadersMiddlewareClass: Test_ConsoleSecurityHeaders::class,
    );

    ob_start();
    $exit = $command->run([]);
    ob_get_clean();

    assertSame(0, $exit);
});

test('audit:security recognizes CSRF supplied as a middleware instance', function () {
    $router = new Router();
    // Protected by an instance, not a class-string. The old check only matched
    // class-strings and would have wrongly reported this route as unprotected.
    $router->post('/widgets', ['SomeController', 'store'], [new Test_ConsoleCsrfByInstance()]);

    $command = new AuditSecurityCommand(
        $router,
        globalMiddleware: [Test_ConsoleSecurityHeaders::class],
        csrfMiddlewareClass: Test_ConsoleCsrfByInstance::class,
        securityHeadersMiddlewareClass: Test_ConsoleSecurityHeaders::class,
    );

    ob_start();
    $exit = $command->run([]);
    ob_get_clean();

    assertSame(0, $exit);
});

test('audit:security warns about a mutating route with no auth, but does not fail', function () {
    $router = new Router();
    $router->post('/widgets', ['SomeController', 'store'], [Test_ConsoleCsrf::class]);

    $command = new AuditSecurityCommand(
        $router,
        globalMiddleware: [Test_ConsoleSecurityHeaders::class],
        csrfMiddlewareClass: Test_ConsoleCsrf::class,
        securityHeadersMiddlewareClass: Test_ConsoleSecurityHeaders::class,
        authMiddlewareClass: Test_ConsoleAuth::class,
    );

    ob_start();
    $exit = $command->run([]);
    $output = ob_get_clean();

    // Warning, not finding: exit stays 0 so a legitimately-public route does not
    // break the build.
    assertSame(0, $exit);
    assertTrue(str_contains($output, 'warning'));
    assertTrue(str_contains($output, 'POST /widgets'));
});

test('audit:security --public silences an intentional public route', function () {
    $router = new Router();
    $router->post('/login', ['AuthController', 'login'], [Test_ConsoleCsrf::class]);

    $command = new AuditSecurityCommand(
        $router,
        globalMiddleware: [Test_ConsoleSecurityHeaders::class],
        csrfMiddlewareClass: Test_ConsoleCsrf::class,
        securityHeadersMiddlewareClass: Test_ConsoleSecurityHeaders::class,
        authMiddlewareClass: Test_ConsoleAuth::class,
    );

    ob_start();
    $exit = $command->run(['--public=POST:/login']);
    $output = ob_get_clean();

    assertSame(0, $exit);
    assertTrue(!str_contains($output, 'warning'));
});

test('audit:security --strict turns an auth warning into a build failure', function () {
    $router = new Router();
    $router->post('/widgets', ['SomeController', 'store'], [Test_ConsoleCsrf::class]);

    $command = new AuditSecurityCommand(
        $router,
        globalMiddleware: [Test_ConsoleSecurityHeaders::class],
        csrfMiddlewareClass: Test_ConsoleCsrf::class,
        securityHeadersMiddlewareClass: Test_ConsoleSecurityHeaders::class,
        authMiddlewareClass: Test_ConsoleAuth::class,
    );

    ob_start();
    $exit = $command->run(['--strict']);
    ob_get_clean();

    assertSame(1, $exit);
});

test('audit:security leaves the auth check off entirely when no auth class is configured', function () {
    $router = new Router();
    $router->post('/widgets', ['SomeController', 'store'], [Test_ConsoleCsrf::class]);

    // An app that does not use lombokclarion/auth passes null and is never nagged.
    $command = new AuditSecurityCommand(
        $router,
        globalMiddleware: [Test_ConsoleSecurityHeaders::class],
        csrfMiddlewareClass: Test_ConsoleCsrf::class,
        securityHeadersMiddlewareClass: Test_ConsoleSecurityHeaders::class,
    );

    ob_start();
    $exit = $command->run(['--strict']);
    $output = ob_get_clean();

    assertSame(0, $exit);
    assertTrue(!str_contains($output, 'warning'));
});

test('audit:sql flags string-concatenated query() calls', function () {
    $dir = sys_get_temp_dir() . '/lombokclarion_audit_' . uniqid();
    mkdir($dir);
    file_put_contents($dir . '/Bad.php', "<?php\n\$pdo->query(\"SELECT * FROM t WHERE id = \" . \$id);\n");

    $command = new AuditSqlCommand();
    ob_start();
    $exit = $command->run([$dir]);
    $output = ob_get_clean();

    assertSame(1, $exit);
    assertTrue(str_contains($output, 'built via string concatenation'));

    unlink($dir . '/Bad.php');
    rmdir($dir);
});

test('audit:sql passes clean files', function () {
    $dir = sys_get_temp_dir() . '/lombokclarion_audit_' . uniqid();
    mkdir($dir);
    file_put_contents($dir . '/Good.php', "<?php\n\$qb->where('id', '=', \$id)->get();\n");

    $command = new AuditSqlCommand();
    ob_start();
    $exit = $command->run([$dir]);
    ob_get_clean();

    assertSame(0, $exit);

    unlink($dir . '/Good.php');
    rmdir($dir);
});

// --- #42: user:create ------------------------------------------------------

test('user:create mints a 32-char id, hashes the password, and login round-trips', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE users ("id" VARCHAR(32) NOT NULL PRIMARY KEY, "email" VARCHAR(255) NOT NULL UNIQUE, "password_hash" VARCHAR(255) NOT NULL)');
    $hasher = new LombokClarion\Security\Argon2idPasswordHasher(new LombokClarion\Security\SecurityConfig());
    $cmd = new App\Console\UserCreateCommand($pdo, $hasher);

    putenv('LOMBOKCLARION_PASSWORD=secret-xyz');
    ob_start();
    $exit = $cmd->run(['x@y.test']);
    $out = ob_get_clean();
    putenv('LOMBOKCLARION_PASSWORD');

    assertSame(0, $exit);
    $row = $pdo->query('SELECT * FROM users')->fetch(PDO::FETCH_ASSOC);
    assertSame(32, strlen($row['id']));
    assertTrue($hasher->verify('secret-xyz', $row['password_hash']));
    assertTrue(str_contains($out, $row['id']));
});

test('user:create refuses: bad email, missing env password, duplicate email', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec('CREATE TABLE users ("id" VARCHAR(32) NOT NULL PRIMARY KEY, "email" VARCHAR(255) NOT NULL UNIQUE, "password_hash" VARCHAR(255) NOT NULL)');
    $hasher = new LombokClarion\Security\Argon2idPasswordHasher(new LombokClarion\Security\SecurityConfig());
    $cmd = new App\Console\UserCreateCommand($pdo, $hasher);

    putenv('LOMBOKCLARION_PASSWORD=p');
    assertSame(1, $cmd->run(['not-an-email']));
    putenv('LOMBOKCLARION_PASSWORD');
    assertSame(1, $cmd->run(['ok@y.test']));

    putenv('LOMBOKCLARION_PASSWORD=p');
    ob_start(); $cmd->run(['dup@y.test']); ob_get_clean();
    $exit = $cmd->run(['dup@y.test']);
    putenv('LOMBOKCLARION_PASSWORD');
    assertSame(1, $exit);
    assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn());
});

// --- #44: APP_KEY production guard ----------------------------------------

test('#44: production boot refuses a missing, default, or short APP_KEY', function () {
    $restore = getenv('APP_ENV');
    putenv('APP_ENV=production');

    try {
        foreach (['', 'dev-secret-change-me', 'change-me-to-a-random-32-byte-secret', 'short'] as $bad) {
            $bad === '' ? putenv('APP_KEY') : putenv("APP_KEY=$bad");
            assertThrows(RuntimeException::class, fn () => App\Infrastructure\ServiceFactories::appKey());
        }

        putenv('APP_KEY=' . str_repeat('a', 64));
        assertSame(str_repeat('a', 64), App\Infrastructure\ServiceFactories::appKey());
    } finally {
        putenv('APP_KEY');
        putenv("APP_ENV=$restore");
    }
});

test('#44: local environments keep the dev fallback so onboarding stays one command', function () {
    $restore = getenv('APP_ENV');
    putenv('APP_ENV=local');
    putenv('APP_KEY');

    try {
        assertSame('dev-secret-change-me', App\Infrastructure\ServiceFactories::appKey());
    } finally {
        putenv("APP_ENV=$restore");
    }
});
