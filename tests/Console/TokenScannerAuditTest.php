<?php

declare(strict_types=1);

namespace Tests\Console;

use LombokClarion\Console\BuiltIn\TokenScanner;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the audit:sql TokenScanner.
 *
 * The scanner is the gate that keeps the audit command's exit code honest:
 * it must catch every unsafe shape, and it must accept exactly the two
 * sanctioned escape hatches (Identifier::validate()/quote() assigned
 * variables, TrustedDdl::mark() wrapped exec calls).
 */
final class TokenScannerAuditTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lombokclarion-scanner-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*.php') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function scan(string $code): array
    {
        $file = $this->dir . '/Subject.php';
        file_put_contents($file, $code);

        return (new TokenScanner())->scan($file);
    }

    public function testCatchesVariableInterpolation(): void
    {
        $findings = $this->scan('<?php
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = $id");
        ');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('variable interpolation', $findings[0]);
    }

    public function testCatchesStringConcatenation(): void
    {
        $findings = $this->scan("<?php
            \$stmt = \$pdo->query('SELECT * FROM users WHERE id = ' . \$id);
        ");

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('string concatenation', $findings[0]);
    }

    public function testCatchesSprintfFeedingQuery(): void
    {
        $findings = $this->scan("<?php
            \$stmt = \$pdo->exec(sprintf('DROP TABLE %s', \$table));
        ");

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('sprintf', $findings[0]);
    }

    public function testCatchesTaintRoutedThroughLocalVariable(): void
    {
        $findings = $this->scan("<?php
            \$sql = \"DELETE FROM users WHERE id = \$id\";
            \$pdo->prepare(\$sql);
        ");

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('assigned unsafe SQL at line', $findings[0]);
    }

    public function testAcceptsSanitizedIdentifierVariable(): void
    {
        $findings = $this->scan("<?php
            \$table = Identifier::validate(\$this->table);
            \$stmt = \$pdo->prepare('SELECT value, expiry FROM ' . \$table . ' WHERE key = ?');
            \$stmt->execute([\$key]);
        ");

        $this->assertSame([], $findings);
    }

    public function testAcceptsSanitizedVariableRoutedThroughSqlVariable(): void
    {
        $findings = $this->scan("<?php
            \$table = Identifier::validate(\$this->table);
            \$sql = 'INSERT OR REPLACE INTO ' . \$table . ' (id, payload) VALUES (?, ?)';
            \$stmt = \$pdo->prepare(\$sql);
        ");

        $this->assertSame([], $findings);
    }

    public function testAcceptsTrustedDdlMarkedExec(): void
    {
        $findings = $this->scan("<?php
            \$table = Identifier::validate(\$this->table);
            \$this->pdo->exec(TrustedDdl::mark('CREATE TABLE IF NOT EXISTS ' . \$table . ' (id TEXT)'));
        ");

        $this->assertSame([], $findings);
    }

    public function testTrustedDdlDoesNotLaunderValueStatements(): void
    {
        // The scanner accepts the SHAPE, but TrustedDdl::mark() must throw at
        // runtime for anything that is not DDL — that runtime narrowing is
        // what makes the hatch safe. Guard the narrowing itself.
        $this->expectException(\LombokClarion\Persistence\Exceptions\QueryException::class);
        \LombokClarion\Persistence\TrustedDdl::mark('DELETE FROM t WHERE id = 1');
    }

    public function testBoundParametersAreNeverFlagged(): void
    {
        $findings = $this->scan("<?php
            \$stmt = \$pdo->prepare('SELECT * FROM users WHERE id = ? AND email = :email');
            \$stmt->execute([\$id, \$email]);
        ");

        $this->assertSame([], $findings);
    }

    public function testCommentsAndStringLiteralsAreNotFalsePositives(): void
    {
        $findings = $this->scan("<?php
            // ->prepare(\"SELECT \$id\") inside a comment must not trip it.
            \$text = 'the word prepare( in a string is not a call';
            \$stmt = \$pdo->prepare('SELECT * FROM users WHERE id = ?');
        ");

        $this->assertSame([], $findings);
    }
}
