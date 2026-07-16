<?php

declare(strict_types=1);

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
