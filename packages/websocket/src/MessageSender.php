<?php

declare(strict_types=1);

namespace LombokClarion\WebSocket;

/**
 * Abstracts the underlying WebSocket runtime's send capability.
 * Swoole, ReactPHP, and Workerman each implement this differently.
 */
interface MessageSender
{
    public function sendTo(string $connectionId, string $message): void;

    public function close(string $connectionId, int $code = 1000, string $reason = ''): void;
}
