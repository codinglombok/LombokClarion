<?php

declare(strict_types=1);

namespace LombokClarion\Session;

/**
 * Server-side session. Holds key-value data, flash data (available for one
 * request), and a CSRF token. The session is loaded from the handler at
 * start() and persisted at save().
 *
 * The session is NOT globally accessible — it is bound into RequestContext
 * by the StartSession middleware, and injected into controllers/services
 * that need it.
 */
final class Session
{
    private string $id;

    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, mixed> */
    private array $flash = [];

    /** @var array<string, mixed> flash from previous request, being read now */
    private array $previousFlash = [];

    private bool $started = false;
    private bool $regenerated = false;

    public function __construct(
        private readonly SessionHandler $handler,
        private readonly int $lifetime = 7200, // 2 hours
    ) {
        $this->id = $this->generateId();
    }

    public function start(?string $id = null): void
    {
        if ($this->started) {
            return;
        }

        if ($id !== null && $this->isValidId($id)) {
            $this->id = $id;
        }

        $raw = $this->handler->read($this->id);

        if ($raw !== null) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $this->data = $decoded['data'] ?? [];
                $this->previousFlash = $decoded['flash'] ?? [];
            }
        }

        $this->started = true;
    }

    public function save(): void
    {
        if (!$this->started) {
            return;
        }

        $payload = json_encode([
            'data' => $this->data,
            'flash' => $this->flash,
        ], JSON_THROW_ON_ERROR);

        $this->handler->write($this->id, $payload, $this->lifetime);
        $this->flash = [];
    }

    public function id(): string
    {
        return $this->id;
    }

    public function regenerate(): void
    {
        $oldId = $this->id;
        $this->id = $this->generateId();
        $this->handler->destroy($oldId);
        $this->regenerated = true;
    }

    public function wasRegenerated(): bool
    {
        return $this->regenerated;
    }

    public function invalidate(): void
    {
        $this->handler->destroy($this->id);
        $this->data = [];
        $this->flash = [];
        $this->previousFlash = [];
        $this->id = $this->generateId();
        $this->regenerated = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $this->previousFlash[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) || array_key_exists($key, $this->previousFlash);
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->previousFlash, $this->data);
    }

    public function flush(): void
    {
        $this->data = [];
        $this->flash = [];
    }

    /**
     * Flash data: available only during the NEXT request, then auto-removed.
     */
    public function flash(string $key, mixed $value): void
    {
        $this->flash[$key] = $value;
    }

    /**
     * Get a previously flashed value (from the previous request).
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->previousFlash[$key] ?? $default;
    }

    public function token(): string
    {
        if (!isset($this->data['_token'])) {
            $this->data['_token'] = bin2hex(random_bytes(32));
        }

        return $this->data['_token'];
    }

    private function generateId(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function isValidId(string $id): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $id) === 1;
    }
}
