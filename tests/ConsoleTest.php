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
use LombokClarion\Persistence\Migration;
use LombokClarion\Persistence\MigrationRunner;
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

test('console kernel dispatches to a registered command by signature', function () {
    $container = new Container();
    $container->singleton(MigrateCommand::class, function ($c) {
        $pdo = new PDO('sqlite::memory:');
        $schema = new SchemaBuilder($pdo, 'sqlite');
        return new MigrateCommand(new MigrationRunner($pdo, $schema, 'sqlite'), [Test_NoopMigration::class]);
    });

    $console = new ConsoleKernel($container);
    $console->register(MigrateCommand::class);

    ob_start();
    $exit = $console->handle(['migrate']);
    $output = ob_get_clean();

    assertSame(0, $exit);
    assertTrue(str_contains($output, 'Migrated: ' . Test_NoopMigration::class));
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
