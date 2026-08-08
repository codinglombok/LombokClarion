<?php

declare(strict_types=1);

namespace LombokClarion\Log;

/**
 * The eight per-level shorthands, implemented once in terms of log().
 *
 * A trait rather than eight more methods on the {@see Logger} interface: an
 * implementer should have to write exactly one method to be a valid logger.
 * Putting the shorthands in the interface would make every test double and
 * every decorator carry eight near-identical forwarding methods that can drift
 * from each other.
 *
 * @phpstan-require-implements Logger
 */
trait LogsConveniently
{
    /** @param array<string, mixed> $context */
    public function emergency(string $message, array $context = []): void
    {
        $this->log(LogLevel::Emergency, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function alert(string $message, array $context = []): void
    {
        $this->log(LogLevel::Alert, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::Critical, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::Notice, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $message, $context);
    }
}
