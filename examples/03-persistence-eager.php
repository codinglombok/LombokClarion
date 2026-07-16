<?php
// Bound-params QueryBuilder + N+1-safe eager loading. Run: php examples/03-persistence-eager.php
declare(strict_types=1);
require __DIR__ . '/../autoload.php';

use LombokClarion\Persistence\{EagerLoader, QueryBuilder, Relation};

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
$pdo->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INT, body TEXT)');
(new QueryBuilder($pdo, 'posts'))->insert(['title' => 'Hello']);
(new QueryBuilder($pdo, 'comments'))->insert(['post_id' => 1, 'body' => 'Nice!']);

$posts = (new QueryBuilder($pdo, 'posts'))->get();
$posts = (new EagerLoader($pdo))->load($posts, ['comments'],
    ['comments' => Relation::hasMany('comments', 'post_id')]);

echo $posts[0]['title'] . ' has ' . count($posts[0]['comments']) . " comment(s)\n";
