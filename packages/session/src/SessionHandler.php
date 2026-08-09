<?php

declare(strict_types=1);

namespace LombokClarion\Session;

/**
 * Backend for session storage. Implementations: FileSessionHandler,
 * DatabaseSessionHandler, ArraySessionHandler (testing).
 */
interface SessionHandler
{
    public function read(string $id): ?string;

    public function write(string $id, string $data, int $lifetime): void;

    public function destroy(string $id): void;

    /**
     * Remove sessions older than $maxLifetime seconds.
     */
    public function gc(int $maxLifetime): int;
}
