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

// --- Variable-indirection regression (the one-line bypass) ------------------
// These guard the blind spot found when the PHPStan sibling rule was first
// executed: both engines only inspected the call site, so assigning the SQL to
// a variable first hid it completely. A false negative in an audit is a
// security bug (SECURITY.md), so each shape gets a permanent test.

test('TokenScanner flags SQL concatenated into a variable before query()', function () {
    $findings = test_scan_source(
        '<?php $sql = "SELECT * FROM t WHERE id = " . $id; $pdo->query($sql);'
    );
    assertSame(1, count($findings));
    assertTrue(str_contains($findings[0], '$sql'));
});

test('TokenScanner flags SQL interpolated into a variable before exec()', function () {
    $findings = test_scan_source(
        '<?php $sql = "DELETE FROM t WHERE id = $id"; $pdo->exec($sql);'
    );
    assertSame(1, count($findings));
    assertTrue(str_contains($findings[0], '$sql'));
});

test('TokenScanner reports the nearest preceding tainting assignment', function () {
    $code = "<?php\n"
        . "\$sql = 'SELECT * FROM a WHERE id = ' . \$id;\n"
        . "\$pdo->query(\$sql);\n"
        . "\$sql = 'SELECT * FROM b WHERE id = ' . \$id;\n"
        . "\$pdo->query(\$sql);\n";
    $findings = test_scan_source($code);
    assertSame(2, count($findings));
    assertTrue(str_contains($findings[0], 'line 2'));
    assertTrue(str_contains($findings[1], 'line 4'));
});

test('literal-only .= chains never taint (bound-parameter builders stay clean)', function () {
    $code = "<?php\n"
        . "\$sql = 'SELECT * FROM jobs WHERE ready = ?';\n"
        . "if (\$queue !== null) { \$sql .= ' AND queue = ?'; }\n"
        . "\$sql .= ' LIMIT 1';\n"
        . "\$stmt = \$pdo->prepare(\$sql);\n";
    assertSame([], test_scan_source($code));
});

// --- Escape hatches, shared by both engines --------------------------------

test('Identifier::quote() into a variable is not treated as tainted', function () {
    $code = "<?php\n"
        . "\$quoted = Identifier::quote(\$table);\n"
        . "\$pdo->query('SELECT COUNT(*) FROM ' . \$quoted);\n";
    assertSame([], test_scan_source($code));
});

test('TrustedDdl::mark() marks a reviewed DDL site as clean', function () {
    $code = "<?php\n"
        . "\$sql = \"CREATE TABLE t (id \$autoIncrement)\";\n"
        . "\$pdo->exec(TrustedDdl::mark(\$sql));\n";
    assertSame([], test_scan_source($code));
});

test('an unmarked interpolated DDL site is still reported', function () {
    $code = "<?php\n"
        . "\$sql = \"CREATE TABLE t (id \$autoIncrement)\";\n"
        . "\$pdo->exec(\$sql);\n";
    assertSame(1, count(test_scan_source($code)));
});
