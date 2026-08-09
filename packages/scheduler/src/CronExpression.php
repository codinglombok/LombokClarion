<?php

declare(strict_types=1);

namespace LombokClarion\Scheduler;

/**
 * Parses and matches standard five-field cron expressions:
 *   minute hour day-of-month month day-of-week
 *
 * Supports: *, ranges (1-5), lists (1,3,5), steps (*/5, 1-10/2).
 * No named days/months — use numbers.
 */
final class CronExpression
{
    /**
     * Check whether a cron expression matches the given datetime.
     */
    public static function matches(string $expression, \DateTimeInterface $now): bool
    {
        $parts = preg_split('/\s+/', trim($expression));

        if (count($parts) !== 5) {
            throw new Exceptions\SchedulerException("Invalid cron expression: {$expression}");
        }

        [$minuteExpr, $hourExpr, $domExpr, $monthExpr, $dowExpr] = $parts;

        $minute = (int) $now->format('i');
        $hour = (int) $now->format('G');
        $dom = (int) $now->format('j');
        $month = (int) $now->format('n');
        $dow = (int) $now->format('w'); // 0=Sun

        return self::fieldMatches($minuteExpr, $minute, 0, 59)
            && self::fieldMatches($hourExpr, $hour, 0, 23)
            && self::fieldMatches($domExpr, $dom, 1, 31)
            && self::fieldMatches($monthExpr, $month, 1, 12)
            && self::fieldMatches($dowExpr, $dow, 0, 7); // 7=Sun alias
    }

    private static function fieldMatches(string $expr, int $value, int $min, int $max): bool
    {
        if ($expr === '*') {
            return true;
        }

        // List: 1,3,5
        foreach (explode(',', $expr) as $part) {
            $part = trim($part);

            // Step: */5 or 1-10/2
            if (str_contains($part, '/')) {
                [$range, $stepStr] = explode('/', $part, 2);
                $step = (int) $stepStr;

                if ($range === '*') {
                    $rangeStart = $min;
                    $rangeEnd = $max;
                } elseif (str_contains($range, '-')) {
                    [$rangeStart, $rangeEnd] = array_map('intval', explode('-', $range, 2));
                } else {
                    $rangeStart = (int) $range;
                    $rangeEnd = $max;
                }

                if ($value >= $rangeStart && $value <= $rangeEnd && ($value - $rangeStart) % $step === 0) {
                    return true;
                }
                continue;
            }

            // Range: 1-5
            if (str_contains($part, '-')) {
                [$start, $end] = array_map('intval', explode('-', $part, 2));
                if ($value >= $start && $value <= $end) {
                    return true;
                }
                continue;
            }

            // Exact value
            $partVal = (int) $part;
            // Day of week: 7 is alias for 0 (Sunday)
            if ($max === 7 && $partVal === 7) {
                $partVal = 0;
            }
            if ($value === $partVal) {
                return true;
            }
        }

        return false;
    }
}
