<?php

declare(strict_types=1);

namespace LombokClarion\Http;

/**
 * Request-scoped data (authenticated user, tenant, correlation id, ...)
 * flows through this explicit, injectable object — never through statics.
 * A new RequestContext is created per request and bound into the container
 * as an instance for that request's lifetime (see Kernel::handle()).
 */
final class RequestContext
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }
}
