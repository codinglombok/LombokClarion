<?php

declare(strict_types=1);

namespace LombokClarion\Log;

/**
 * The eight RFC 5424 severities, in the order everyone already knows them from
 * syslog and PSR-3.
 *
 * An enum rather than string constants because a level is a closed set and a
 * typo'd string level is the kind of bug that only shows up when the log you
 * needed turns out to be missing. `LogLevel::from('warn')` fails loudly at the
 * call site instead.
 *
 * severity() exists so handlers can filter with a comparison. Lower number =
 * more severe, matching syslog: EMERGENCY is 0, DEBUG is 7. That means
 * "at least this severe" is `<=`, which reads backwards the first time and
 * correctly every time after — the alternative is inventing our own numbering
 * that disagrees with every other tool on the box.
 */
enum LogLevel: string
{
    case Emergency = 'emergency';
    case Alert = 'alert';
    case Critical = 'critical';
    case Error = 'error';
    case Warning = 'warning';
    case Notice = 'notice';
    case Info = 'info';
    case Debug = 'debug';

    public function severity(): int
    {
        return match ($this) {
            self::Emergency => 0,
            self::Alert => 1,
            self::Critical => 2,
            self::Error => 3,
            self::Warning => 4,
            self::Notice => 5,
            self::Info => 6,
            self::Debug => 7,
        };
    }

    /**
     * True when this level is at least as severe as $minimum — the test a
     * handler applies to decide whether to write.
     */
    public function isAtLeast(self $minimum): bool
    {
        return $this->severity() <= $minimum->severity();
    }
}
