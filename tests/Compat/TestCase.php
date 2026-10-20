<?php

declare(strict_types=1);

/**
 * Minimal PHPUnit-compatible TestCase shim.
 *
 * This repo deliberately ships with zero external dependencies (see
 * autoload.php): tests must run inside sandboxes without network access
 * to Packagist. When real PHPUnit IS available (installed via composer),
 * it takes precedence and this shim is never loaded — tests stay
 * 100% compatible with both runners.
 *
 * Supported API surface (the subset every test in this repo uses):
 *
 *   setUp()/tearDown() hooks, expectException[Message[Matches]/Code],
 *   fail(), markTestSkipped(), and the common assertion family:
 *   assertSame/NotSame, assertEquals/NotEquals, True/False/Null/NotNull,
 *   Empty/NotEmpty, Contains/NotContains, Count, StringContainsString,
 *   StringStartsWith/EndsWith, MatchesRegularExpression, InstanceOf,
 *   ArrayHasKey/NotArrayHasKey, GreaterThan[OrEqual], LessThan[OrEqual],
 *   Is{Array,String,Int,Float,Bool,Object,List}.
 */

namespace PHPUnit\Framework;

use Throwable;

class AssertionFailedError extends \RuntimeException
{
}

class SkippedTestError extends \RuntimeException
{
}

/**
 * Internal control-flow signal: the expected exception was thrown and
 * matched, so the test PASSED even though a Throwable escaped the method.
 */
class ExpectedExceptionSatisfied extends \LogicException
{
}

abstract class TestCase
{
    private ?string $expectedException = null;
    private ?string $expectedMessage = null;
    private ?string $expectedMessageRegex = null;
    private ?int $expectedCode = null;
    private static int $assertionCount = 0;

    protected function setUp(): void
    {
    }

    protected function tearDown(): void
    {
    }

    /**
     * @internal Used by tests/run-all.php around each test method.
     */
    final public function runBare(callable $testMethod): void
    {
        $this->expectedException = null;
        $this->expectedMessage = null;
        $this->expectedMessageRegex = null;
        $this->expectedCode = null;

        $this->setUp();

        try {
            $testMethod();
        } catch (SkippedTestError) {
            $this->tearDown();
            throw new SkippedTestError('skipped');
        } catch (\Throwable $thrown) {
            $this->tearDown();
            $this->verifyExpectations($thrown);
            return; // expectation matched → the test passed
        }

        $this->tearDown();

        if ($this->expectedException !== null) {
            self::fail(sprintf(
                'Expected exception %s was never thrown.',
                $this->expectedException
            ));
        }
    }

    /**
     * When a test threw and expectations were declared, verify them. On a
     * match this returns normally (test passed); otherwise it rethrows the
     * original Throwable (unexpected error) or fails with a mismatch report.
     */
    private function verifyExpectations(Throwable $thrown): void
    {
        if ($this->expectedException === null) {
            throw $thrown; // unexpected throw — runner reports it as an error
        }

        if (!($thrown instanceof $this->expectedException)) {
            self::fail(sprintf(
                'Expected exception %s, got %s: %s',
                $this->expectedException,
                $thrown::class,
                $thrown->getMessage()
            ));
        }

        if ($this->expectedMessage !== null && !str_contains($thrown->getMessage(), $this->expectedMessage)) {
            self::fail(sprintf(
                'Exception message "%s" does not contain "%s".',
                $thrown->getMessage(),
                $this->expectedMessage
            ));
        }

        if ($this->expectedMessageRegex !== null && preg_match($this->expectedMessageRegex, $thrown->getMessage()) !== 1) {
            self::fail(sprintf(
                'Exception message "%s" does not match regex %s.',
                $thrown->getMessage(),
                $this->expectedMessageRegex
            ));
        }

        if ($this->expectedCode !== null && $thrown->getCode() !== $this->expectedCode) {
            self::fail(sprintf('Expected exception code %d, got %d.', $this->expectedCode, $thrown->getCode()));
        }
    }

    final public function assertionCount(): int
    {
        return $this->assertionCount;
    }

    // ── Expectations ------------------------------------------------------

    protected function expectException(string $exception): void
    {
        $this->expectedException = $exception;
    }

    protected function expectExceptionMessage(string $message): void
    {
        $this->expectedMessage = $message;
    }

    protected function expectExceptionMessageMatches(string $regularExpression): void
    {
        $this->expectedMessageRegex = $regularExpression;
    }

    protected function expectExceptionCode(int|string $code): void
    {
        $this->expectedCode = (int) $code;
    }

    // ── Flow control ------------------------------------------------------

    public static function fail(string $message = ''): never
    {
        throw new AssertionFailedError($message);
    }

    public static function markTestSkipped(string $message = ''): never
    {
        throw new SkippedTestError($message);
    }

    protected static function recordAssertion(): void
    {
        self::$assertionCount++;
    }

    /**
     * @internal Total assertions since the last resetAssertionCount().
     */
    final public static function takeAssertionCount(): int
    {
        $n = self::$assertionCount;
        self::$assertionCount = 0;
        return $n;
    }

    // ── Assertions --------------------------------------------------------

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if ($expected !== $actual) {
            self::fail(sprintf(
                '%sFailed asserting that %s is identical to %s.',
                self::prefix($message),
                self::export($actual),
                self::export($expected)
            ));
        }
    }

    public static function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if ($expected === $actual) {
            self::fail(sprintf(
                '%sFailed asserting that %s is NOT identical to %s.',
                self::prefix($message),
                self::export($actual),
                self::export($expected)
            ));
        }
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if ($expected != $actual) {
            self::fail(sprintf(
                '%sFailed asserting that %s equals %s.',
                self::prefix($message),
                self::export($actual),
                self::export($expected)
            ));
        }
    }

    public static function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if ($expected == $actual) {
            self::fail(sprintf(
                '%sFailed asserting that %s does not equal %s.',
                self::prefix($message),
                self::export($actual),
                self::export($expected)
            ));
        }
    }

    public static function assertTrue(mixed $condition, string $message = ''): void
    {
        self::recordAssertion();
        if ($condition !== true) {
            self::fail($message !== '' ? $message : 'Failed asserting that condition is true.');
        }
    }

    public static function assertFalse(mixed $condition, string $message = ''): void
    {
        self::recordAssertion();
        if ($condition !== false) {
            self::fail($message !== '' ? $message : 'Failed asserting that condition is false.');
        }
    }

    public static function assertNull(mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if ($actual !== null) {
            self::fail(sprintf('%sFailed asserting that %s is null.', self::prefix($message), self::export($actual)));
        }
    }

    public static function assertNotNull(mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if ($actual === null) {
            self::fail($message !== '' ? $message : 'Failed asserting that value is not null.');
        }
    }

    public static function assertEmpty(mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if (!empty($actual)) {
            self::fail(sprintf('%sFailed asserting that %s is empty.', self::prefix($message), self::export($actual)));
        }
    }

    public static function assertNotEmpty(mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if (empty($actual)) {
            self::fail($message !== '' ? $message : 'Failed asserting that value is not empty.');
        }
    }

    public static function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        self::recordAssertion();
        if (!in_array($needle, $haystack, true)) {
            self::fail(sprintf(
                '%sFailed asserting that %s is contained in the array.',
                self::prefix($message),
                self::export($needle)
            ));
        }
    }

    public static function assertNotContains(mixed $needle, array $haystack, string $message = ''): void
    {
        self::recordAssertion();
        if (in_array($needle, $haystack, true)) {
            self::fail(sprintf(
                '%sFailed asserting that %s is NOT contained in the array.',
                self::prefix($message),
                self::export($needle)
            ));
        }
    }

    public static function assertCount(int $expectedCount, mixed $haystack, string $message = ''): void
    {
        self::recordAssertion();
        if (!is_countable($haystack) || count($haystack) !== $expectedCount) {
            $actual = is_countable($haystack) ? (string) count($haystack) : 'not countable';
            self::fail(sprintf('%sFailed asserting count is %s, got %s.', self::prefix($message), $expectedCount, $actual));
        }
    }

    public static function assertNotCount(int $expectedCount, mixed $haystack, string $message = ''): void
    {
        self::recordAssertion();
        if (is_countable($haystack) && count($haystack) === $expectedCount) {
            self::fail(sprintf('%sFailed asserting count is NOT %s.', self::prefix($message), $expectedCount));
        }
    }

    public static function assertEqualsWithDelta(
        mixed $expected,
        mixed $actual,
        float $delta,
        string $message = '',
    ): void {
        self::recordAssertion();
        if (!is_numeric($actual) || abs((float) $actual - (float) $expected) > $delta) {
            self::fail(sprintf(
                '%sFailed asserting that %s equals %s within delta %s.',
                self::prefix($message),
                self::export($actual),
                self::export($expected),
                $delta
            ));
        }
    }

    public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        self::recordAssertion();
        if (!str_contains($haystack, $needle)) {
            self::fail(sprintf(
                '%sFailed asserting that "%s" contains "%s".',
                self::prefix($message),
                $haystack,
                $needle
            ));
        }
    }

    public static function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        self::recordAssertion();
        if (str_contains($haystack, $needle)) {
            self::fail(sprintf(
                '%sFailed asserting that "%s" does NOT contain "%s".',
                self::prefix($message),
                $haystack,
                $needle
            ));
        }
    }

    public static function assertStringStartsWith(string $prefix, string $string, string $message = ''): void
    {
        self::recordAssertion();
        if (!str_starts_with($string, $prefix)) {
            self::fail(sprintf(
                '%sFailed asserting that "%s" starts with "%s".',
                self::prefix($message),
                $string,
                $prefix
            ));
        }
    }

    public static function assertStringEndsWith(string $suffix, string $string, string $message = ''): void
    {
        self::recordAssertion();
        if (!str_ends_with($string, $suffix)) {
            self::fail(sprintf(
                '%sFailed asserting that "%s" ends with "%s".',
                self::prefix($message),
                $string,
                $suffix
            ));
        }
    }

    public static function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        self::recordAssertion();
        if (preg_match($pattern, $string) !== 1) {
            self::fail(sprintf(
                '%sFailed asserting that "%s" matches %s.',
                self::prefix($message),
                $string,
                $pattern
            ));
        }
    }

    public static function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if (!$actual instanceof $expected) {
            $actualType = is_object($actual) ? $actual::class : gettype($actual);
            self::fail(sprintf(
                '%sFailed asserting that %s is an instance of %s.',
                self::prefix($message),
                $actualType,
                $expected
            ));
        }
    }

    public static function assertArrayHasKey(int|string $key, array $array, string $message = ''): void
    {
        self::recordAssertion();
        if (!array_key_exists($key, $array)) {
            self::fail(sprintf('%sFailed asserting that array has key "%s".', self::prefix($message), $key));
        }
    }

    public static function assertArrayNotHasKey(int|string $key, array $array, string $message = ''): void
    {
        self::recordAssertion();
        if (array_key_exists($key, $array)) {
            self::fail(sprintf('%sFailed asserting that array does NOT have key "%s".', self::prefix($message), $key));
        }
    }

    public static function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if ($actual <= $expected) {
            self::fail(sprintf(
                '%sFailed asserting that %s is greater than %s.',
                self::prefix($message),
                self::export($actual),
                self::export($expected)
            ));
        }
    }

    public static function assertGreaterThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if ($actual < $expected) {
            self::fail(sprintf(
                '%sFailed asserting that %s is greater than or equal to %s.',
                self::prefix($message),
                self::export($actual),
                self::export($expected)
            ));
        }
    }

    public static function assertLessThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if ($actual >= $expected) {
            self::fail(sprintf(
                '%sFailed asserting that %s is less than %s.',
                self::prefix($message),
                self::export($actual),
                self::export($expected)
            ));
        }
    }

    public static function assertLessThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if ($actual > $expected) {
            self::fail(sprintf(
                '%sFailed asserting that %s is less than or equal to %s.',
                self::prefix($message),
                self::export($actual),
                self::export($expected)
            ));
        }
    }

    public static function assertIsArray(mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if (!is_array($actual)) {
            self::fail($message !== '' ? $message : 'Failed asserting that value is an array.');
        }
    }

    public static function assertIsList(mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if (!is_array($actual) || array_values($actual) !== $actual) {
            self::fail($message !== '' ? $message : 'Failed asserting that value is a list.');
        }
    }

    public static function assertIsString(mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if (!is_string($actual)) {
            self::fail($message !== '' ? $message : 'Failed asserting that value is a string.');
        }
    }

    public static function assertIsInt(mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if (!is_int($actual)) {
            self::fail($message !== '' ? $message : 'Failed asserting that value is an int.');
        }
    }

    public static function assertIsFloat(mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if (!is_float($actual)) {
            self::fail($message !== '' ? $message : 'Failed asserting that value is a float.');
        }
    }

    public static function assertIsBool(mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if (!is_bool($actual)) {
            self::fail($message !== '' ? $message : 'Failed asserting that value is a bool.');
        }
    }

    public static function assertIsObject(mixed $actual, string $message = ''): void
    {
        self::recordAssertion();
        if (!is_object($actual)) {
            self::fail($message !== '' ? $message : 'Failed asserting that value is an object.');
        }
    }

    // ── Helpers -----------------------------------------------------------

    private static function prefix(string $message): string
    {
        return $message !== '' ? $message . ' ' : '';
    }

    private static function export(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_string($value)) {
            $cut = mb_strlen($value) > 60 ? mb_substr($value, 0, 57) . '...' : $value;

            return "'" . addcslashes($cut, "'\0..\37\\") . "'";
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return 'array(' . count($value) . ')';
        }

        if (is_object($value)) {
            return $value::class;
        }

        return gettype($value);
    }
}
