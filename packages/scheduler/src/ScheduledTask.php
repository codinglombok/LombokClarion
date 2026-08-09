<?php

declare(strict_types=1);

namespace LombokClarion\Scheduler;

/**
 * Represents a single scheduled task with a cron expression.
 *
 * Built via the fluent API:
 *   ScheduledTask::command('prune')->daily()->at('03:00')
 *   ScheduledTask::callback(fn () => ...)->everyFiveMinutes()
 */
final class ScheduledTask
{
    private string $expression = '* * * * *';
    private ?string $description = null;
    private bool $withoutOverlapping = false;

    private function __construct(
        private readonly ?string $commandName,
        private readonly ?\Closure $callback,
        /** @var list<string> */
        private readonly array $commandArgs = [],
    ) {
    }

    public static function command(string $name, string ...$args): self
    {
        return new self($name, null, $args);
    }

    public static function callback(\Closure $callback): self
    {
        return new self(null, $callback);
    }

    // --- Frequency helpers ---

    public function cron(string $expression): self
    {
        $this->expression = $expression;
        return $this;
    }

    public function everyMinute(): self
    {
        $this->expression = '* * * * *';
        return $this;
    }

    public function everyFiveMinutes(): self
    {
        $this->expression = '*/5 * * * *';
        return $this;
    }

    public function everyFifteenMinutes(): self
    {
        $this->expression = '*/15 * * * *';
        return $this;
    }

    public function everyThirtyMinutes(): self
    {
        $this->expression = '*/30 * * * *';
        return $this;
    }

    public function hourly(): self
    {
        $this->expression = '0 * * * *';
        return $this;
    }

    public function hourlyAt(int $minutes): self
    {
        $this->expression = "{$minutes} * * * *";
        return $this;
    }

    public function daily(): self
    {
        $this->expression = '0 0 * * *';
        return $this;
    }

    public function at(string $time): self
    {
        [$hour, $minute] = explode(':', $time);
        $parts = explode(' ', $this->expression);
        $parts[0] = ltrim($minute, '0') ?: '0';
        $parts[1] = ltrim($hour, '0') ?: '0';
        $this->expression = implode(' ', $parts);
        return $this;
    }

    public function weekly(): self
    {
        $this->expression = '0 0 * * 0';
        return $this;
    }

    public function weeklyOn(int $dayOfWeek, string $time = '00:00'): self
    {
        [$hour, $minute] = explode(':', $time);
        $this->expression = ltrim($minute, '0') . ' ' . ltrim($hour, '0') . " * * {$dayOfWeek}";
        return $this;
    }

    public function monthly(): self
    {
        $this->expression = '0 0 1 * *';
        return $this;
    }

    public function monthlyOn(int $dayOfMonth, string $time = '00:00'): self
    {
        [$hour, $minute] = explode(':', $time);
        $this->expression = ltrim($minute, '0') . ' ' . ltrim($hour, '0') . " {$dayOfMonth} * *";
        return $this;
    }

    public function describe(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function withoutOverlapping(): self
    {
        $this->withoutOverlapping = true;
        return $this;
    }

    // --- Accessors ---

    public function expression(): string
    {
        return $this->expression;
    }

    public function description(): string
    {
        return $this->description ?? $this->commandName ?? '(callback)';
    }

    public function isCommand(): bool
    {
        return $this->commandName !== null;
    }

    public function commandName(): ?string
    {
        return $this->commandName;
    }

    /** @return list<string> */
    public function commandArgs(): array
    {
        return $this->commandArgs;
    }

    public function callback(): ?\Closure
    {
        return $this->callback;
    }

    public function preventsOverlapping(): bool
    {
        return $this->withoutOverlapping;
    }

    /**
     * Check if this task is due at the given time.
     */
    public function isDueAt(\DateTimeInterface $now): bool
    {
        return CronExpression::matches($this->expression, $now);
    }

    /**
     * Check if this task is due right now.
     */
    public function isDue(): bool
    {
        return $this->isDueAt(new \DateTimeImmutable());
    }
}
