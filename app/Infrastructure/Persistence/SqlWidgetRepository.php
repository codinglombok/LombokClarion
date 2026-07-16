<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Widget\Widget;
use App\Domain\Widget\WidgetRepositoryInterface;
use LombokClarion\Persistence\QueryBuilder;
use PDO;

final class SqlWidgetRepository implements WidgetRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(Widget $widget): void
    {
        $existing = $this->queryBuilder()->where('id', '=', $widget->id)->first();

        if ($existing === null) {
            $this->queryBuilder()->insert([
                'id' => $widget->id,
                'name' => $widget->name,
                'price_cents' => $widget->priceCents,
            ]);
            return;
        }

        $this->queryBuilder()->where('id', '=', $widget->id)->update([
            'name' => $widget->name,
            'price_cents' => $widget->priceCents,
        ]);
    }

    public function find(string $id): ?Widget
    {
        $row = $this->queryBuilder()->where('id', '=', $id)->first();
        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        return array_map($this->hydrate(...), $this->queryBuilder()->orderBy('name')->get());
    }

    private function queryBuilder(): QueryBuilder
    {
        return new QueryBuilder($this->pdo, 'widgets');
    }

    private function hydrate(array $row): Widget
    {
        return new Widget($row['id'], $row['name'], (int) $row['price_cents']);
    }
}
