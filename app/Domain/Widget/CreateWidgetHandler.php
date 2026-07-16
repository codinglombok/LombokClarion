<?php

declare(strict_types=1);

namespace App\Domain\Widget;

/**
 * Deliberately does NOT `implements \LombokClarion\Bus\CommandHandler` —
 * that would be a framework import inside app/Domain/**, which the CI
 * boundary check forbids. LombokClarion\Bus\CommandBus calls handle()
 * structurally (duck-typed), so this class satisfies the contract without
 * naming it.
 */
final class CreateWidgetHandler
{
    public function __construct(private readonly WidgetRepositoryInterface $repository)
    {
    }

    public function handle(object $command): Widget
    {
        /** @var CreateWidget $command */
        $widget = new Widget(
            id: bin2hex(random_bytes(8)),
            name: $command->name,
            priceCents: $command->priceCents,
        );

        $this->repository->save($widget);

        return $widget;
    }
}
