<?php

declare(strict_types=1);

namespace LombokClarion\Testing;

/**
 * @template TEntity of object
 * @template TId
 *
 * Domain-layer tests should never need HTTP bootstrapping or a real
 * database (master prompt §9). Concrete repository fakes extend this and
 * implement the domain's own RepositoryInterface, giving handlers a real
 * object to talk to without touching Persistence at all.
 */
abstract class InMemoryRepository
{
    /** @var array<int|string, object> */
    protected array $items = [];

    protected function put(int|string $id, object $entity): void
    {
        $this->items[$id] = $entity;
    }

    protected function find(int|string $id): ?object
    {
        return $this->items[$id] ?? null;
    }

    protected function remove(int|string $id): void
    {
        unset($this->items[$id]);
    }

    /** @return list<object> */
    protected function all(): array
    {
        return array_values($this->items);
    }

    protected function count(): int
    {
        return count($this->items);
    }
}
