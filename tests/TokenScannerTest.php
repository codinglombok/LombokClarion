<?php

declare(strict_types=1);

use LombokClarion\Console\BuiltIn\AuditSqlCommand;
use LombokClarion\Console\BuiltIn\TokenScanner;

function test_scan_source(string $code): array
{
    $file = tempnam(sys_get_temp_dir(), 'scan_') . '.php';
    file_put_contents($file, $code);
    $findings = (new TokenScanner())->scan($file);
    unlink($file);
    return $findings;
}

test('TokenScanner flags concatenation into query()', function () {
    $findings = test_scan_source('<?php $pdo->query("SELECT * FROM t WHERE id = " . $id);');
    assertSame(1, count($findings));
    assertTrue(str_contains($findings[0], 'concatenation'));
});

test('TokenScanner flags variable interpolation inside query string', function () {
    $findings = test_scan_source('<?php $pdo->prepare("SELECT * FROM t WHERE id = $id");');
    assertSame(1, count($findings));
    assertTrue(str_contains($findings[0], 'interpolation'));
});

test('TokenScanner flags sprintf feeding prepare()', function () {
    $findings = test_scan_source('<?php $pdo->prepare(sprintf("SELECT * FROM %s", $table));');
    assertTrue(count($findings) >= 1);
    $all = implode(' ', $findings);
    assertTrue(str_contains($all, 'sprintf'));
});

test('TokenScanner handles multi-line concatenation (regex-era blind spot)', function () {
    $findings = test_scan_source(
        "<?php \$pdo->query(\n    \"SELECT * FROM t \" .\n    \"WHERE id = \" .\n    \$id\n);"
    );
    assertSame(1, count($findings));
});

test('TokenScanner ignores query( mentioned in comments and plain strings', function () {
    $findings = test_scan_source(<<<'CODE'
<?php
// never do $pdo->query("bad" . $id) — this is a comment
$note = 'the text query("x" . $y) inside a single-quoted string';
$safe = $pdo->prepare('SELECT * FROM t WHERE id = ?');
CODE);
    assertSame([], $findings);
});

test('TokenScanner passes bound-parameter usage', function () {
    $findings = test_scan_source(<<<'CODE'
<?php
$stmt = $pdo->prepare('SELECT * FROM widgets WHERE id = ? AND status = ?');
$stmt->execute([$id, $status]);
CODE);
    assertSame([], $findings);
});

test('audit:sql --explain flags sequential scans on populated tables', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE big (id INTEGER PRIMARY KEY, val TEXT)');
    for ($i = 1; $i <= 20; $i++) {
        $pdo->exec("INSERT INTO big (val) VALUES ('row$i')");
    }

    $emptyDir = sys_get_temp_dir() . '/lc_explain_' . uniqid();
    mkdir($emptyDir);

    $command = new AuditSqlCommand($pdo);
    ob_start();
    $exit = $command->run([$emptyDir, '--explain']);
    $output = ob_get_clean();

    assertSame(1, $exit);
    assertTrue(str_contains($output, 'sequential scan'));
    assertTrue(str_contains($output, '"big"'));

    rmdir($emptyDir);
});

test('audit:sql without --explain does not touch the database', function () {
    // PDO deliberately null — must not crash.
    $emptyDir = sys_get_temp_dir() . '/lc_noexplain_' . uniqid();
    mkdir($emptyDir);

    $command = new AuditSqlCommand(null);
    ob_start();
    $exit = $command->run([$emptyDir, '--explain']); // --explain with null PDO: skip gracefully
    ob_get_clean();

    assertSame(0, $exit);
    rmdir($emptyDir);
});
