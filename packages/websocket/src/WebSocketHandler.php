<?php

declare(strict_types=1);

namespace LombokClarion\WebSocket;

/**
 * Application-level WebSocket event handler. Implement this to define
 * what happens when connections open, receive messages, or close.
 *
 * Register explicitly in bootstrap — no auto-discovery.
 */
interface WebSocketHandler
{
    public function onOpen(Connection $connection): void;

    public function onMessage(Connection $connection, string $message): void;

    public function onClose(Connection $connection, int $code, string $reason): void;

    public function onError(Connection $connection, \Throwable $error): void;
}
