<?php

declare(strict_types=1);

/**
 * Pest-style global helpers — compatibility layer.
 *
 * Some suites in this repository were written against Pest's global API
 * (test('description', function () { ... assertSame(...); })).
 * This shim lets those files run under tests/run-all.php without
 * installing Pest, while staying forward-compatible: every helper is
 * guarded by function_exists and delegates to PHPUnit\Framework\TestCase
 * assertions, which exist BOTH in real PHPUnit (inherited from Assert)
 * and in the bundled tests/Compat/TestCase.php shim.
 *
 * Registered closures are executed by tests/run-all.php immediately after
 * the declaring file is loaded. Inside a closure, `$this` is bound to a
 * fresh TestCase instance, so $this->expectException(...) also works.
 */

use PHPUnit\Framework\TestCase;

if (!function_exists('test')) {
    /**
     * Register a Pest-style test closure for execution by tests/run-all.php.
     */
    function test(string $description, Closure $closure): void
    {
        $GLOBALS['__CLARION_PEST_TESTS'] ??= [];
        $GLOBALS['__CLARION_PEST_TESTS'][] = [$description, $closure];
    }
}

if (!class_exists('__ClarionPestCase')) {
    /**
     * Concrete TestCase used to host globally registered test closures.
     * Gives closures a real $this (setUp/tearDown/expectations work) and
     * participates in the shared assertion counter.
     */
    final class __ClarionPestCase extends TestCase
    {
        /**
         * Run $test and require it to throw $exceptionClass (optionally
         * containing $message). Accepts both argument orders used in the
         * wild: assertThrows(ExceptionClass::class, fn () => ...) and the
         * official Pest order assertThrows(fn () => ..., ExceptionClass::class).
         */
        public static function assertThrowsClosure(
            Closure|string $test,
            Closure|string|null $exception = null,
            ?string $message = null,
        ): void {
            if ($test instanceof Closure) {
                $exceptionClass = $exception;
            } else {
                // (class, closure) order
                $exceptionClass = $test;
                $test = $exception;
            }

            if (!$test instanceof Closure || !is_string($exceptionClass) || $exceptionClass === '') {
                self::fail('assertThrows() requires an exception class name and a closure.');
            }

            if (method_exists(TestCase::class, 'recordAssertion')) {
                TestCase::recordAssertion();
            }

            try {
                $test();
            } catch (Throwable $e) {
                if (!($e instanceof $exceptionClass)) {
                    self::fail(sprintf(
                        'Expected exception %s, got %s: %s',
                        $exceptionClass,
                        $e::class,
                        $e->getMessage()
                    ));
                }

                if ($message !== null && !str_contains($e->getMessage(), $message)) {
                    self::fail(sprintf(
                        'Expected exception message to contain "%s", got "%s".',
                        $message,
                        $e->getMessage()
                    ));
                }

                return; // thrown as expected → assertion passed
            }

            self::fail(sprintf('Expected exception %s was never thrown.', $exceptionClass));
        }
    }
}

if (!function_exists('assertThrows')) {
    function assertThrows(Closure|string $test, Closure|string|null $exception = null, ?string $message = null): void
    {
        __ClarionPestCase::assertThrowsClosure($test, $exception, $message);
    }
}

if (!function_exists('assertSame')) {
    function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        TestCase::assertSame($expected, $actual, $message);
    }
}

if (!function_exists('assertNotSame')) {
    function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        TestCase::assertNotSame($expected, $actual, $message);
    }
}

if (!function_exists('assertEquals')) {
    function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        TestCase::assertEquals($expected, $actual, $message);
    }
}

if (!function_exists('assertNotEquals')) {
    function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        TestCase::assertNotEquals($expected, $actual, $message);
    }
}

if (!function_exists('assertTrue')) {
    function assertTrue(mixed $condition, string $message = ''): void
    {
        TestCase::assertTrue($condition, $message);
    }
}

if (!function_exists('assertFalse')) {
    function assertFalse(mixed $condition, string $message = ''): void
    {
        TestCase::assertFalse($condition, $message);
    }
}

if (!function_exists('assertNull')) {
    function assertNull(mixed $actual, string $message = ''): void
    {
        TestCase::assertNull($actual, $message);
    }
}

if (!function_exists('assertNotNull')) {
    function assertNotNull(mixed $actual, string $message = ''): void
    {
        TestCase::assertNotNull($actual, $message);
    }
}

if (!function_exists('assertEmpty')) {
    function assertEmpty(mixed $actual, string $message = ''): void
    {
        TestCase::assertEmpty($actual, $message);
    }
}

if (!function_exists('assertNotEmpty')) {
    function assertNotEmpty(mixed $actual, string $message = ''): void
    {
        TestCase::assertNotEmpty($actual, $message);
    }
}

if (!function_exists('assertContains')) {
    function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        TestCase::assertContains($needle, $haystack, $message);
    }
}

if (!function_exists('assertNotContains')) {
    function assertNotContains(mixed $needle, array $haystack, string $message = ''): void
    {
        TestCase::assertNotContains($needle, $haystack, $message);
    }
}

if (!function_exists('assertCount')) {
    function assertCount(int $expectedCount, mixed $haystack, string $message = ''): void
    {
        TestCase::assertCount($expectedCount, $haystack, $message);
    }
}

if (!function_exists('assertNotCount')) {
    function assertNotCount(int $expectedCount, mixed $haystack, string $message = ''): void
    {
        TestCase::assertNotCount($expectedCount, $haystack, $message);
    }
}

if (!function_exists('assertEqualsWithDelta')) {
    function assertEqualsWithDelta(mixed $expected, mixed $actual, float $delta, string $message = ''): void
    {
        TestCase::assertEqualsWithDelta($expected, $actual, $delta, $message);
    }
}

if (!function_exists('assertStringContainsString')) {
    function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        TestCase::assertStringContainsString($needle, $haystack, $message);
    }
}

if (!function_exists('assertStringNotContainsString')) {
    function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        TestCase::assertStringNotContainsString($needle, $haystack, $message);
    }
}

if (!function_exists('assertStringStartsWith')) {
    function assertStringStartsWith(string $prefix, string $string, string $message = ''): void
    {
        TestCase::assertStringStartsWith($prefix, $string, $message);
    }
}

if (!function_exists('assertStringEndsWith')) {
    function assertStringEndsWith(string $suffix, string $string, string $message = ''): void
    {
        TestCase::assertStringEndsWith($suffix, $string, $message);
    }
}

if (!function_exists('assertMatchesRegularExpression')) {
    function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        TestCase::assertMatchesRegularExpression($pattern, $string, $message);
    }
}

if (!function_exists('assertInstanceOf')) {
    function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
    {
        TestCase::assertInstanceOf($expected, $actual, $message);
    }
}

if (!function_exists('assertArrayHasKey')) {
    function assertArrayHasKey(int|string $key, array $array, string $message = ''): void
    {
        TestCase::assertArrayHasKey($key, $array, $message);
    }
}

if (!function_exists('assertArrayNotHasKey')) {
    function assertArrayNotHasKey(int|string $key, array $array, string $message = ''): void
    {
        TestCase::assertArrayNotHasKey($key, $array, $message);
    }
}

if (!function_exists('assertGreaterThan')) {
    function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        TestCase::assertGreaterThan($expected, $actual, $message);
    }
}

if (!function_exists('assertGreaterThanOrEqual')) {
    function assertGreaterThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        TestCase::assertGreaterThanOrEqual($expected, $actual, $message);
    }
}

if (!function_exists('assertLessThan')) {
    function assertLessThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        TestCase::assertLessThan($expected, $actual, $message);
    }
}

if (!function_exists('assertLessThanOrEqual')) {
    function assertLessThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        TestCase::assertLessThanOrEqual($expected, $actual, $message);
    }
}

if (!function_exists('assertIsArray')) {
    function assertIsArray(mixed $actual, string $message = ''): void
    {
        TestCase::assertIsArray($actual, $message);
    }
}

if (!function_exists('assertIsList')) {
    function assertIsList(mixed $actual, string $message = ''): void
    {
        TestCase::assertIsList($actual, $message);
    }
}

if (!function_exists('assertIsString')) {
    function assertIsString(mixed $actual, string $message = ''): void
    {
        TestCase::assertIsString($actual, $message);
    }
}

if (!function_exists('assertIsInt')) {
    function assertIsInt(mixed $actual, string $message = ''): void
    {
        TestCase::assertIsInt($actual, $message);
    }
}

if (!function_exists('assertIsFloat')) {
    function assertIsFloat(mixed $actual, string $message = ''): void
    {
        TestCase::assertIsFloat($actual, $message);
    }
}

if (!function_exists('assertIsBool')) {
    function assertIsBool(mixed $actual, string $message = ''): void
    {
        TestCase::assertIsBool($actual, $message);
    }
}

if (!function_exists('assertIsObject')) {
    function assertIsObject(mixed $actual, string $message = ''): void
    {
        TestCase::assertIsObject($actual, $message);
    }
}

if (!function_exists('fail')) {
    function fail(string $message = ''): never
    {
        TestCase::fail($message);
    }
}

if (!function_exists('markTestSkipped')) {
    function markTestSkipped(string $message = ''): never
    {
        TestCase::markTestSkipped($message);
    }
}
