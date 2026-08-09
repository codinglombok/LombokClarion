<?php

declare(strict_types=1);

namespace LombokClarion\Cache;

use LombokClarion\Cache\Exceptions\CacheException;

/**
 * Named-store manager, same pattern as ConnectionManager.
 *
 * Every store is explicit configuration — no discovery, no "just use Redis
 * because it's installed". A store that is not declared here does not exist.
 */
final class CacheManager
{
    /** @var array<string, Cache> resolved instances */
    private array $stores = [];

    /**
     * @param array<string, callable(): Cache> $factories name → factory
     * @param string $default the store returned by store() with no argument
     */
    public function __construct(
        private readonly array $factories,
        private readonly string $default = 'file',
    ) {
        if (!isset($this->factories[$this->default])) {
            throw new CacheException(
                "Default cache store '{$this->default}' is not declared. "
                . 'Declared stores: ' . implode(', ', array_keys($this->factories))
            );
        }
    }

    /**
     * Return a named store. Resolved once and reused for the lifetime of
     * this manager (same request under FPM; same worker under Swoole).
     */
    public function store(?string $name = null): Cache
    {
        $name ??= $this->default;

        if (isset($this->stores[$name])) {
            return $this->stores[$name];
        }

        if (!isset($this->factories[$name])) {
            throw new CacheException(
                "Cache store '{$name}' is not declared. "
                . 'Declared stores: ' . implode(', ', array_keys($this->factories))
            );
        }

        $this->stores[$name] = ($this->factories[$name])();

        return $this->stores[$name];
    }

    /**
     * @return list<string>
     */
    public function availableStores(): array
    {
        return array_keys($this->factories);
    }
}
