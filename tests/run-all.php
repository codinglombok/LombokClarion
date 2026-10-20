<?php

declare(strict_types=1);

/**
 * LombokClarion — zero-dependency test runner.
 *
 * Discovers and runs every *Test.php under:
 *
 *   tests/**                         (monorepo test suites)
 *   packages/table-sheet/tests/      (vendored package tests)
 *
 * PHPUnit-compatible: every test class extends PHPUnit\Framework\TestCase.
 * If real PHPUnit is installed (composer require --dev phpunit/phpunit),
 * its TestCase takes precedence; otherwise the bundled shim in
 * tests/Compat/TestCase.php is loaded, keeping the suite runnable in
 * sandboxes without network access. Exit code 0 = all green, 1 = failures.
 *
 * Pest-style files (test('...', function () { ... })) are supported via
 * tests/Compat/Pest.php: global `test()` registrations run right after
 * their declaring file is loaded, and global assert* helpers delegate to
 * the same TestCase API. This ALSO covers the flat harness-style suites
 * (tests/*Test.php written against tests/harness.php) — they register
 * through the same test() shim, so one runner executes every suite and
 * a file that fails to load is reported as an error for that file while
 * the remaining suites still run.
 *
 * CI usage:  php tests/run-all.php
 * Filter:    php tests/run-all.php --filter=SchedulerTest
 */

// The suite IS a local environment and says so explicitly. The APP_KEY
// production guard treats an UNSET APP_ENV as production — fail safe,
// not open — so the harness must declare itself rather than rely on the
// default that guard exists to distrust. (Same contract as tests/harness.php.)
putenv('APP_ENV=testing');

require __DIR__ . '/../autoload.php';

if (!class_exists('PHPUnit\\Framework\\TestCase')) {
    require __DIR__ . '/Compat/TestCase.php';
}

require_once __DIR__ . '/Compat/Pest.php';

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedTestError;

// ── Discovery -------------------------------------------------------------

$root = dirname(__DIR__);

$testFiles = [];

$append = static function (string $dir) use (&$testFiles): void {
    if (!is_dir($dir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo
            && str_ends_with($file->getFilename(), 'Test.php')
            && !str_contains($file->getPathname(), '/Compat/')) {
            $testFiles[] = $file->getPathname();
        }
    }
};

$append($root . '/tests');
$append($root . '/packages/table-sheet/tests');

$testFiles = array_values(array_unique($testFiles));
sort($testFiles);

if ($testFiles === []) {
    fwrite(STDERR, "No test files found.\n");
    exit(1);
}

// ── Filter ----------------------------------------------------------------

$filter = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--filter=')) {
        $filter = substr($arg, strlen('--filter='));
    }
}

// ── Run -------------------------------------------------------------------

$passes = 0;
$failures = [];
$errors = [];
$skipped = 0;
$assertions = 0;
$startedAt = microtime(true);

foreach ($testFiles as $file) {
    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
    $before = [
        'classes' => get_declared_classes(),
    ];

    // A file that fatals at load time (undefined helper, syntax error,
    // missing symbol) is reported as an error for that file — the rest
    // of the suite still runs, so CI output stays actionable.
    try {
        require_once $file;
    } catch (Throwable $e) {
        $assertions += \PHPUnit\Framework\TestCase::takeAssertionCount();
        $errors[] = [$relative, $e];
        echo 'E';
        continue;
    }

    $newClasses = array_diff(get_declared_classes(), $before['classes']);

    // A test file may declare several TestCase subclasses (or none if the
    // file only pulls in helpers) — run every class ending in "Test".
    $testClasses = array_values(array_filter($newClasses, static fn (string $c): bool => str_ends_with($c, 'Test')));

    foreach ($testClasses as $class) {
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract() || !$reflection->isInstantiable()) {
            continue;
        }

        if (!$reflection->isSubclassOf('PHPUnit\\Framework\\TestCase')) {
            continue;
        }

        $methods = array_filter(
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $m): bool => str_starts_with($m->getName(), 'test')
                && $m->getDeclaringClass()->getName() === $class
        );

        if ($methods === []) {
            continue;
        }

        // Fresh instance per method, PHPUnit-style isolation.
        foreach ($methods as $method) {
            $testId = $class . '::' . $method->getName();

            if ($filter !== null && !str_contains($testId, $filter)) {
                continue;
            }

            $instance = new $class();

            try {
                $instance->runBare(fn () => $method->invoke($instance));
                $passes++;
                $assertions += \PHPUnit\Framework\TestCase::takeAssertionCount();
                echo '.';
            } catch (SkippedTestError) {
                $skipped++;
                \PHPUnit\Framework\TestCase::takeAssertionCount();
                echo 'S';
            } catch (AssertionFailedError $e) {
                $assertions += \PHPUnit\Framework\TestCase::takeAssertionCount();
                $failures[] = [$testId, $e];
                echo 'F';
            } catch (Throwable $e) {
                $assertions += \PHPUnit\Framework\TestCase::takeAssertionCount();
                $errors[] = [$testId, $e];
                echo 'E';
            }
        }
    }

    // ── Pest-style tests registered by this file --------------------------

    $pestTests = $GLOBALS['__CLARION_PEST_TESTS'] ?? [];
    $GLOBALS['__CLARION_PEST_TESTS'] = [];

    foreach ($pestTests as [$description, $closure]) {
        $pestId = $relative . ' :: ' . $description;

        if ($filter !== null && !str_contains($pestId, $filter)) {
            continue;
        }

        $case = new __ClarionPestCase();
        $bound = Closure::bind($closure, $case, $case::class) ?? $closure;

        try {
            $case->runBare($bound);
            $passes++;
            $assertions += \PHPUnit\Framework\TestCase::takeAssertionCount();
            echo '.';
        } catch (SkippedTestError) {
            $skipped++;
            \PHPUnit\Framework\TestCase::takeAssertionCount();
            echo 'S';
        } catch (AssertionFailedError $e) {
            $assertions += \PHPUnit\Framework\TestCase::takeAssertionCount();
            $failures[] = [$pestId, $e];
            echo 'F';
        } catch (Throwable $e) {
            $assertions += \PHPUnit\Framework\TestCase::takeAssertionCount();
            $errors[] = [$pestId, $e];
            echo 'E';
        }
    }
}

$duration = microtime(true) - $startedAt;

echo PHP_EOL . PHP_EOL;
printf(
    "Tests: %d, Assertions: %d, Failures: %d, Errors: %d, Skipped: %d (%.2fs, PHP %s)%s",
    $passes + count($failures) + count($errors),
    $assertions,
    count($failures),
    count($errors),
    $skipped,
    $duration,
    PHP_VERSION,
    PHP_EOL
);

$renderGroup = static function (string $title, array $items): void {
    if ($items === []) {
        return;
    }

    echo PHP_EOL . $title . PHP_EOL;

    foreach ($items as $i => [$testId, $e]) {
        printf("%d) %s%s   %s%s%s", $i + 1, $testId, PHP_EOL, $e->getMessage(), PHP_EOL, PHP_EOL);
    }
};

$renderGroup('FAILURES', $failures);
$renderGroup('ERRORS', $errors);

exit(($failures === [] && $errors === []) ? 0 : 1);
