<?php

declare(strict_types=1);

namespace LombokClarion\Log;

/**
 * What application code depends on. One method: everything else is sugar
 * layered on top by {@see LogsConveniently}, not extra contract for an
 * implementer to get wrong.
 *
 * NOT a facade and never resolved statically — a Logger is constructor-
 * injected like every other service in this framework. A logger reached
 * through a global is a dependency that does not appear in any signature, and
 * the first thing it costs you is the ability to assert in a test what your
 * code logged.
 */
interface Logger
{
    /**
     * @param array<string, mixed> $context structured fields, not sprintf args
     */
    public function log(LogLevel $level, string $message, array $context = []): void;
}
