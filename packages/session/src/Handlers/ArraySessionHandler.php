<?php

declare(strict_types=1);

namespace LombokClarion\Session\Handlers;

use LombokClarion\Session\SessionHandler;

/**
 * In-memory session handler for testing. No persistence.
 */
final class ArraySessionHandler implements SessionHandler
{
    /** @var array<string, array{data: string, expiry: int}> */
    private array $store = [];

    public function read(string $id): ?string
    {
        if (!isset($this->store[$id])) {
            return null;
        }
        if ($this->store[$id]['expiry'] < time()) {
            unset($this->store[$id]);
            return null;
        }
        return $this->store[$id]['data'];
    }

    public function write(string $id, string $data, int $lifetime): void
    {
        $this->store[$id] = [
            'data' => $data,
            'expiry' => time() + $lifetime,
        ];
    }

    public function destroy(string $id): void
    {
        unset($this->store[$id]);
    }

    public function gc(int $maxLifetime): int
    {
        $now = time();
        $count = 0;
        foreach ($this->store as $id => $entry) {
            if ($entry['expiry'] < $now) {
                unset($this->store[$id]);
                $count++;
            }
        }
        return $count;
    }
}
