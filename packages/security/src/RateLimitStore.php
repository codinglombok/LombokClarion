<?php

declare(strict_types=1);

namespace LombokClarion\Security;

/**
 * Per §5, no in-process cache is assumed to survive between requests in
 * serverless/edge deployments, so production should bind this to a
 * Redis-backed (or similar) implementation. InMemoryRateLimitStore is
 * provided for local dev/single-process FPM only.
 */
interface RateLimitStore
{
    /**
     * Increments the counter for $key and returns the new count within
     * the current window.
     */
    public function increment(string $key, int $windowSeconds): int;
}
