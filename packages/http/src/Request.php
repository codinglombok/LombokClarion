<?php

declare(strict_types=1);

namespace LombokClarion\Http;

/**
 * Immutable request value object. Never a superglobal wrapper accessed via
 * static methods — it is always constructed explicitly by a RuntimeAdapter
 * and passed down through the Kernel/Router/Controller call chain.
 */
final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, mixed> $body     parsed body (form or JSON)
     * @param array<string, string> $headers header name (lowercased) => value
     * @param array<string, string> $cookies
     * @param array<string, mixed> $attributes route params / middleware-set data
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly array $headers = [],
        public readonly array $cookies = [],
        private readonly array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        $rawBody = file_get_contents('php://input') ?: '';
        $body = $_POST;
        if (($headers['content-type'] ?? '') && str_contains($headers['content-type'], 'application/json') && $rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new self(
            method: $method,
            path: $path === '' ? '/' : $path,
            query: $_GET,
            body: $body,
            headers: $headers,
            cookies: $_COOKIE,
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * Returns a new Request with additional attributes merged in (route
     * params, middleware-derived data). Requests are immutable — nothing
     * mutates $this.
     */
    public function withAttributes(array $attributes): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->query,
            $this->body,
            $this->headers,
            $this->cookies,
            [...$this->attributes, ...$attributes],
        );
    }
}
