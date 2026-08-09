<?php

declare(strict_types=1);

namespace LombokClarion\WebSocket;

/**
 * Represents a single WebSocket connection. The runtime adapter wraps
 * its native connection handle into this object.
 */
final class Connection
{
    /** @var array<string, mixed> arbitrary per-connection metadata */
    private array $attributes = [];

    public function __construct(
        public readonly string $id,
        private readonly MessageSender $sender,
        public readonly ?string $userId = null,
    ) {
    }

    public function send(string $message): void
    {
        $this->sender->sendTo($this->id, $message);
    }

    public function sendJson(mixed $data): void
    {
        $this->send(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->sender->close($this->id, $code, $reason);
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function isAuthenticated(): bool
    {
        return $this->userId !== null;
    }
}
