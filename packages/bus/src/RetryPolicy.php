<?php

declare(strict_types=1);

namespace LombokClarion\Bus;

/**
 * Queued commands get exactly one attempt unless they explicitly opt into
 * retries by implementing RetriesQueuedCommand and returning a RetryPolicy
 * from retryPolicy(). There is no implicit/global retry-and-backoff
 * behaviour anywhere in the queue worker (master prompt §10).
 */
final class RetryPolicy
{
    public function __construct(
        public readonly int $maxAttempts,
        public readonly int $backoffSeconds = 0,
    ) {
    }

    public static function none(): self
    {
        return new self(maxAttempts: 1, backoffSeconds: 0);
    }
}
