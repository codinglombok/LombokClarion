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
     * @param array<string, string> $headers header name => value (any casing;
     *        normalized to lowercase at construction)
     * @param array<string, string> $cookies
     * @param array<string, mixed> $attributes route params / middleware-set data
     * @param array<string, UploadedFile|list<UploadedFile>> $files uploaded
     *        files by field name — normalized from $_FILES' transposed shape
     *        by UploadedFile::fromFilesArray(), or built by hand in tests
     * @param string|null $remoteAddr the peer address the runtime saw
     *        (REMOTE_ADDR under a SAPI). Explicit constructor input so a
     *        hand-built test Request can carry one — dispatch parity with
     *        fromGlobals() is preserved, nothing peeks at superglobals later.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $body = [],
        array $headers = [],
        public readonly array $cookies = [],
        private readonly array $attributes = [],
        public readonly array $files = [],
        public readonly ?string $remoteAddr = null,
    ) {
        // HTTP header names are case-insensitive (RFC 9110 §5.1), so the
        // constructor normalizes rather than documenting a precondition and
        // hoping. Before this, a hand-built Request with 'Authorization'
        // passed header() lookups NULL — a silent 401 in auth middleware.
        // AUDIT-TRAIL #34 documented the trap; the tests dodged it with
        // lowercase keys; finding #43 is the trap finally being disarmed
        // instead of signposted.
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = $value;
        }
        $this->headers = $normalized;
    }

    /** @var array<string, string> header name (lowercased) => value */
    public readonly array $headers;

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
            files: UploadedFile::fromFilesArray($_FILES, sapi: true),
            remoteAddr: isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null,
        );
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * Client address as the runtime reported it (REMOTE_ADDR), or null for a
     * hand-built Request that did not set one.
     *
     * Deliberately NOT proxy-aware: `X-Forwarded-For` / `Forwarded` are
     * client-supplied headers, and trusting them by default would let anyone
     * forge the very audit column this method exists to fill — worse than
     * null. A trusted-proxy layer, if ever added, must be explicit opt-in
     * configuration (a proxy allow-list), never automatic detection.
     */
    public function ip(): ?string
    {
        return $this->remoteAddr;
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
            $this->files,
            $this->remoteAddr,
        );
    }
}
