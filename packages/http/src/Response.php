<?php

declare(strict_types=1);

namespace LombokClarion\Http;

/**
 * Immutable response value object returned by controllers/middleware.
 * RuntimeAdapters are responsible for actually emitting it (headers +
 * body) in whatever way their environment requires (FPM: header()/echo;
 * Swoole: $swooleResponse; Function: return value serialised by the
 * platform).
 */
final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $status = 200,
        public readonly string $body = '',
        public readonly array $headers = [],
    ) {
    }

    public static function text(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, $body, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /**
     * @param array<mixed>|object $data
     */
    public static function json(array|object $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new self($status, $body, ['Content-Type' => 'application/json']);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self($status, '', ['Location' => $to]);
    }

    public static function noContent(): self
    {
        return new self(204, '', []);
    }

    public function withHeader(string $name, string $value): self
    {
        return new self($this->status, $this->body, [...$this->headers, $name => $value]);
    }

    public function withStatus(int $status): self
    {
        return new self($status, $this->body, $this->headers);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header("$name: $value");
            }
        }

        echo $this->body;
    }
}
