<?php

declare(strict_types=1);

use LombokClarion\ActiveRecord\Exceptions\ActiveRecordException;
use LombokClarion\ActiveRecord\Model;
use LombokClarion\ActiveRecord\ModelQueryBuilder;
use LombokClarion\Bus\CommandBus;
use LombokClarion\Bus\CommandHandler;
use LombokClarion\Container\Container;
use LombokClarion\Facades\Bus;
use LombokClarion\Facades\Facade;
use LombokClarion\Persistence\EagerLoader;
use LombokClarion\Persistence\Exceptions\QueryException;
use LombokClarion\Persistence\QueryBuilder;
use LombokClarion\Persistence\Relation;

// --- Eager Loader tests --------------------------------------------------

function test_make_eager_db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
    $pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, body TEXT)');
    $pdo->exec('CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec('CREATE TABLE profiles (author_id INTEGER PRIMARY KEY, bio TEXT)');

    $pdo->exec("INSERT INTO authors VALUES (1, 'Alice')");
    $pdo->exec("INSERT INTO posts VALUES (1, 'Hello')");
    $pdo->exec("INSERT INTO posts VALUES (2, 'World')");
    $pdo->exec("INSERT INTO comments VALUES (1, 1, 'Nice')");
    $pdo->exec("INSERT INTO comments VALUES (2, 1, 'Great')");
    $pdo->exec("INSERT INTO comments VALUES (3, 2, 'Cool')");
    $pdo->exec("INSERT INTO profiles VALUES (1, 'Writer')");
    return $pdo;
}

test('EagerLoader hasMany loads related rows in one query and attaches them', function () {
    $pdo = test_make_eager_db();
    $loader = new EagerLoader($pdo);

    $posts = (new QueryBuilder($pdo, 'posts'))->orderBy('id')->get();
    $relations = ['comments' => Relation::hasMany('comments', 'post_id')];

    $result = $loader->load($posts, ['comments'], $relations);

    assertSame(2, count($result));
    assertSame(2, count($result[0]['comments']));
    assertSame('Nice', $result[0]['comments'][0]['body']);
    assertSame(1, count($result[1]['comments']));
});

test('EagerLoader hasOne attaches a single related row', function () {
    $pdo = test_make_eager_db();
    $loader = new EagerLoader($pdo);

    $authors = (new QueryBuilder($pdo, 'authors'))->get();
    $relations = ['profile' => Relation::hasOne('profiles', 'author_id', 'id', 'profile')];

    $result = $loader->load($authors, ['profile'], $relations);
    assertSame('Writer', $result[0]['profile']['bio']);
});

test('EagerLoader belongsTo attaches the parent row', function () {
    $pdo = test_make_eager_db();
    $loader = new EagerLoader($pdo);

    $comments = (new QueryBuilder($pdo, 'comments'))->where('id', '=', 1)->get();
    $relations = ['post' => Relation::belongsTo('posts', 'post_id', 'id', 'post')];

    $result = $loader->load($comments, ['post'], $relations);
    assertSame('Hello', $result[0]['post']['title']);
});

test('EagerLoader rejects unknown relation names', function () {
    $pdo = test_make_eager_db();
    $loader = new EagerLoader($pdo);
    $posts = (new QueryBuilder($pdo, 'posts'))->get();

    assertThrows(QueryException::class, fn () => $loader->load($posts, ['nonexistent'], []));
});

test('EagerLoader with empty parent rows returns empty (no crash, no query)', function () {
    $pdo = test_make_eager_db();
    $loader = new EagerLoader($pdo);
    $result = $loader->load([], ['comments'], ['comments' => Relation::hasMany('comments', 'post_id')]);
    assertSame([], $result);
});

// --- Active Record tests -------------------------------------------------

function test_make_ar_db(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, body TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, article_id INTEGER NOT NULL, name TEXT NOT NULL)');
    return $pdo;
}

// Define model CLASSES outside the test closures so they're available globally.
// PHP doesn't allow class declarations inside closures.

final class Test_Article extends Model
{
    protected static string $table = 'articles';
    protected static array $fillable = ['title', 'body'];

    public static function relations(): array
    {
        return ['tags' => Relation::hasMany('tags', 'article_id')];
    }
}

final class Test_EmptyFillable extends Model
{
    protected static string $table = 'articles';
    protected static array $fillable = [];
}

test('ActiveRecord: create + find + update + delete round-trip', function () {
    $pdo = test_make_ar_db();
    Test_Article::setConnection($pdo);

    $article = Test_Article::create(['title' => 'Hello', 'body' => 'World']);
    assertTrue(isset($article->id));
    assertSame('Hello', $article->title);

    $found = Test_Article::find($article->id);
    assertSame('Hello', $found->title);

    $found->update(['title' => 'Updated']);
    assertSame('Updated', $found->title);

    $refetch = Test_Article::findOrFail($found->id);
    assertSame('Updated', $refetch->title);

    $refetch->delete();
    assertSame(null, Test_Article::find($refetch->id));
});

test('ActiveRecord: query builder with where + orderBy + limit', function () {
    $pdo = test_make_ar_db();
    Test_Article::setConnection($pdo);
    Test_Article::create(['title' => 'A', 'body' => 'a']);
    Test_Article::create(['title' => 'B', 'body' => 'b']);
    Test_Article::create(['title' => 'C', 'body' => 'c']);

    $results = Test_Article::query()->where('title', '!=', 'B')->orderBy('title', 'desc')->limit(1)->all();
    assertSame(1, count($results));
    assertSame('C', $results[0]->title);
});

test('ActiveRecord: with() eager-loads relations (N+1 safe)', function () {
    $pdo = test_make_ar_db();
    Test_Article::setConnection($pdo);
    $article = Test_Article::create(['title' => 'Post', 'body' => 'Content']);

    $pdo->exec("INSERT INTO tags (article_id, name) VALUES ({$article->id}, 'php')");
    $pdo->exec("INSERT INTO tags (article_id, name) VALUES ({$article->id}, 'framework')");

    $articles = Test_Article::query()->with('tags')->all();
    assertSame(1, count($articles));
    assertSame(2, count($articles[0]->tags));
    assertSame('php', $articles[0]->tags[0]['name']);
});

test('ActiveRecord: mass-assignment of undeclared columns is structurally blocked', function () {
    $pdo = test_make_ar_db();
    Test_Article::setConnection($pdo);

    $article = Test_Article::create([
        'title' => 'X',
        'body' => 'Y',
        'is_admin' => 1, // not in $fillable
    ]);

    assertSame(null, $article->is_admin);
});

test('ActiveRecord: empty $fillable throws', function () {
    $pdo = test_make_ar_db();
    Test_EmptyFillable::setConnection($pdo);
    assertThrows(ActiveRecordException::class, fn () => Test_EmptyFillable::create(['title' => 'X', 'body' => 'Y']));
});

test('ActiveRecord: findOrFail throws for missing ID', function () {
    $pdo = test_make_ar_db();
    Test_Article::setConnection($pdo);
    assertThrows(ActiveRecordException::class, fn () => Test_Article::findOrFail(99999));
});

// --- Facades tests -------------------------------------------------------

final class Test_FacadePing
{
    public function __construct(public readonly string $data)
    {
    }
}

final class Test_FacadePingHandler implements CommandHandler
{
    public static ?string $handled = null;

    public function handle(object $command): mixed
    {
        self::$handled = $command->data;
        return 'pong';
    }
}

test('Facade resolves from the container and delegates calls', function () {
    Test_FacadePingHandler::$handled = null;
    $container = new Container();
    $bus = new CommandBus($container);
    $bus->register(Test_FacadePing::class, Test_FacadePingHandler::class);
    $container->instance(CommandBus::class, $bus);

    Facade::setContainer($container);
    $result = Bus::dispatch(new Test_FacadePing('hello'));

    assertSame('pong', $result);
    assertSame('hello', Test_FacadePingHandler::$handled);

    Facade::clearContainer();
});

test('Facade throws when container is not set', function () {
    Facade::clearContainer();
    assertThrows(RuntimeException::class, fn () => Bus::dispatch(new Test_FacadePing('x')));
});
