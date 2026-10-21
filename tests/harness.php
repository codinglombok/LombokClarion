<?php

declare(strict_types=1);

// The suite IS a local environment and says so explicitly. The APP_KEY
// production guard (#44) treats an UNSET APP_ENV as production — fail
// safe, not open — so the harness must declare itself rather than rely
// on the default that guard exists to distrust.
putenv('APP_ENV=testing');

require __DIR__ . '/../autoload.php';

/** @var array<string, callable> $__tests */
$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][$name] = $fn;
}

function assertTrue(bool $cond, string $message = ''): void
{
    if (!$cond) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $msg = $message !== '' ? $message : sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        );
        throw new RuntimeException('Assertion failed: ' . $msg);
    }
}

function assertThrows(string $exceptionClass, callable $fn, string $message = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if ($e instanceof $exceptionClass) {
            return;
        }
        throw new RuntimeException(sprintf(
            'Expected %s, got %s: %s',
            $exceptionClass,
            get_class($e),
            $e->getMessage()
        ));
    }
    throw new RuntimeException('Assertion failed: expected ' . $exceptionClass . ' to be thrown. ' . $message);
}

function runTests(string $file): void
{
    $GLOBALS['__tests'] = [];
    require $file;

    $failures = 0;
    foreach ($GLOBALS['__tests'] as $name => $fn) {
        try {
            $fn();
            echo "  PASS  $name\n";
        } catch (Throwable $e) {
            $failures++;
            echo "  FAIL  $name\n";
            echo "        " . $e->getMessage() . "\n";
            echo "        " . $e->getFile() . ':' . $e->getLine() . "\n";
        }
    }

    if ($failures > 0) {
        echo "\n$failures failure(s) in $file\n";
        exit(1);
    }
}

// ── CLI entry ---------------------------------------------------------------
// php tests/harness.php tests/SomeTest.php   (single-suite standalone run)
//
// NOTE: CI always runs the unified runner (php tests/run-all.php), which
// loads the function_exists-guarded equivalents from tests/Compat/Pest.php
// and executes every *Test.php — including the suites registered through
// this harness. This entry point only exists for isolated debugging.
if (PHP_SAPI === 'cli' && isset($argv[1])) {
    runTests($argv[1]);
}

