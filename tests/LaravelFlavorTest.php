<?php

declare(strict_types=1);

use LombokClarion\ActiveRecord\Model;
use LombokClarion\Auth\AuthManager;
use LombokClarion\Bus\CommandBus;
use LombokClarion\Container\Container;
use LombokClarion\Facades\Facade;
use LombokClarion\Facades\Hash;
use LombokClarion\LaravelFlavor\Auth;
use LombokClarion\LaravelFlavor\DB;
use LombokClarion\LaravelFlavor\LaravelFlavor;
use LombokClarion\Persistence\ConnectionConfig;
use LombokClarion\Persistence\ConnectionManager;
use LombokClarion\Security\Argon2idPasswordHasher;
use LombokClarion\Security\PasswordHasher;
use LombokClarion\Security\SecurityHeaders;

/** A container with just enough bound for the flavor to switch on. */
function test_flavorContainer(): Container
{
    $container = new Container();

    $manager = new ConnectionManager([
        'default' => ConnectionConfig::provided('sqlite', new PDO('sqlite::memory:')),
        'reporting' => ConnectionConfig::provided('sqlite', new PDO('sqlite::memory:')),
    ], default: 'default');

    $container->instance(ConnectionManager::class, $manager);
    $container->singleton(PasswordHasher::class, fn () => new Argon2idPasswordHasher(new LombokClarion\Security\SecurityConfig()));

    return $container;
}

final class Test_FlavorWidget extends Model
{
    protected static string $table = 'flavor_widgets';

    /** @var list<string> */
    protected static array $fillable = ['name'];
}

test('one enable() call switches on facades, AR connection, and returns the middleware list', function () {
    $container = test_flavorContainer();

    try {
        $middleware = LaravelFlavor::enable($container);

        // The return value is the Kernel's global middleware — returned, not
        // installed behind anyone's back.
        assertSame([SecurityHeaders::class], $middleware);

        // Facades from the base package resolve...
        $hash = Hash::hash('secret');
        assertTrue(Hash::verify('secret', $hash));

        // ...and ActiveRecord has its connection: a full write/read round-trip
        // through the model, against the manager's default connection.
        Model::getConnection()->exec(
            'CREATE TABLE flavor_widgets (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)'
        );
        $widget = Test_FlavorWidget::create(['name' => 'lamp']);

        assertSame('lamp', Test_FlavorWidget::find($widget->getKey())->name);
    } finally {
        LaravelFlavor::disable();
    }
});

test('DB facade reaches the Stage 10 manager: named connections and matched grammar', function () {
    $container = test_flavorContainer();

    try {
        LaravelFlavor::enable($container);

        DB::connection()->exec('CREATE TABLE d (id INTEGER)');
        DB::connection('reporting')->exec('CREATE TABLE r (id INTEGER)');

        // Two names, two isolated databases — the facade is sugar over the
        // manager, not a second wiring path.
        $defaultTables = DB::connection()
            ->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        assertSame(['d'], $defaultTables);

        // DB::table() hands back a QueryBuilder already paired to the driver.
        DB::table('d')->insert(['id' => 1]);
        assertSame(1, count(DB::table('d')->get()));
    } finally {
        LaravelFlavor::disable();
    }
});

test('Auth facade resolves the AuthManager through the container', function () {
    $container = test_flavorContainer();

    // An AuthManager needs its collaborators; bind a minimal one.
    $container->singleton(AuthManager::class, function ($c) {
        $users = new class implements LombokClarion\Auth\UserProvider {
            public function findByIdentifier(string $identifier): ?LombokClarion\Auth\Authenticatable
            {
                return null;
            }

            public function findById(string $id): ?LombokClarion\Auth\Authenticatable
            {
                return null;
            }
        };
        return new AuthManager(
            $users,
            $c->get(PasswordHasher::class),
            new LombokClarion\Auth\TokenIssuer('test-secret-32-bytes-xxxxxxxxxxxx'),
            new LombokClarion\Http\RequestContext()
        );
    });

    try {
        LaravelFlavor::enable($container);

        // Unauthenticated container-resolved manager: check() is false and
        // attempt() against an empty provider yields null — the facade carried
        // both calls to the same instance the container holds.
        assertTrue(!Auth::check());
        assertSame(null, Auth::attempt('nobody', 'irrelevant'));
        assertTrue($container->get(AuthManager::class) instanceof AuthManager);
    } finally {
        LaravelFlavor::disable();
    }
});

test('enable(connection:) points ActiveRecord at a NAMED connection write side', function () {
    $container = test_flavorContainer();

    try {
        LaravelFlavor::enable($container, connection: 'reporting');

        $manager = $container->get(ConnectionManager::class);
        assertTrue(Model::getConnection() === $manager->write('reporting'));
        assertTrue(Model::getConnection() !== $manager->write('default'));
    } finally {
        LaravelFlavor::disable();
    }
});

test('disable() is a true undo: facades and AR are off after it', function () {
    $container = test_flavorContainer();
    LaravelFlavor::enable($container);
    LaravelFlavor::disable();

    // Facade access after disable is the same loud error as never enabling —
    // process-global static state must not linger across a bootstrap cycle.
    assertThrows(RuntimeException::class, fn () => Hash::hash('x'));
    assertThrows(
        LombokClarion\ActiveRecord\Exceptions\ActiveRecordException::class,
        fn () => Model::getConnection()
    );
});

test('nothing is on before enable() — the magic has no default-on path', function () {
    // Fresh process state guaranteed by the disable() calls above; the facade
    // and the model must both refuse, pointing at the explicit call they need.
    Facade::clearContainer();
    Model::clearConnection();

    assertThrows(RuntimeException::class, fn () => Hash::hash('x'));
    assertThrows(RuntimeException::class, fn () => DB::connection());
});

test('the boundary checker treats LaravelFlavor like any LombokClarion import: banned in Domain', function () {
    // check-domain-boundary bans ALL LombokClarion\* imports from app/Domain/**,
    // which covers this package with no special case. Prove the teeth exist
    // rather than trusting the docblock: plant a violation, run the real
    // checker, expect failure.
    $root = dirname(__DIR__);
    $violation = $root . '/app/Domain/Widget/FlavorLeak.php';

    file_put_contents($violation, "<?php\n\nnamespace App\\Domain\\Widget;\n\nuse LombokClarion\\LaravelFlavor\\DB;\n\nfinal class FlavorLeak\n{\n    public function rows(): array\n    {\n        return DB::table('widgets')->get();\n    }\n}\n");

    try {
        exec('php ' . escapeshellarg($root . '/bin/check-domain-boundary.php') . ' 2>&1', $output, $exit);
        assertTrue($exit !== 0, 'boundary checker must fail on a Domain import of LaravelFlavor');
        assertTrue(str_contains(implode("\n", $output), 'FlavorLeak'));
    } finally {
        unlink($violation);
    }
});
