<?php

declare(strict_types=1);

namespace Chatly\Tests;

/**
 * Assertions, framework-free.
 *
 * Deliberately not PHPUnit's: the same case bodies are executed by
 * `tests/run.php` with bare PHP and by PHPUnit through
 * {@see ContractTest}, so they cannot depend on either.
 */
final class Assert
{
    public static int $count = 0;

    public static function reset(): void
    {
        self::$count = 0;
    }

    public static function true(bool $condition, string $message): void
    {
        ++self::$count;
        if (!$condition) {
            throw new AssertionFailed($message);
        }
    }

    public static function false(bool $condition, string $message): void
    {
        self::true(!$condition, $message);
    }

    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        ++self::$count;
        if ($expected !== $actual) {
            throw new AssertionFailed(sprintf(
                "%s\n  expected: %s\n  actual:   %s",
                $message,
                self::render($expected),
                self::render($actual),
            ));
        }
    }

    public static function notSame(mixed $unexpected, mixed $actual, string $message): void
    {
        ++self::$count;
        if ($unexpected === $actual) {
            throw new AssertionFailed(sprintf(
                "%s\n  both were: %s",
                $message,
                self::render($actual),
            ));
        }
    }

    public static function contains(string $needle, string $haystack, string $message): void
    {
        ++self::$count;
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(sprintf(
                "%s\n  looking for: %s\n  in:          %s",
                $message,
                $needle,
                $haystack,
            ));
        }
    }

    public static function notContains(string $needle, string $haystack, string $message): void
    {
        ++self::$count;
        if (str_contains($haystack, $needle)) {
            throw new AssertionFailed(sprintf(
                "%s\n  should not contain: %s\n  but was:            %s",
                $message,
                $needle,
                $haystack,
            ));
        }
    }

    /**
     * Assert that `$fn` throws `$class`, and hand the exception back so the
     * caller can inspect it.
     *
     * @param class-string<\Throwable> $class
     */
    public static function throws(string $class, \Closure $fn, string $message): \Throwable
    {
        ++self::$count;
        try {
            $fn();
        } catch (\Throwable $e) {
            if ($e instanceof $class) {
                return $e;
            }
            throw new AssertionFailed(sprintf(
                "%s\n  expected: %s\n  thrown:   %s: %s",
                $message,
                $class,
                $e::class,
                $e->getMessage(),
            ));
        }

        throw new AssertionFailed($message . "\n  nothing was thrown; expected " . $class);
    }

    private static function render(mixed $value): string
    {
        if (\is_string($value)) {
            return '"' . $value . '"';
        }
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        return get_debug_type($value) . ' ' . json_encode($value);
    }
}
