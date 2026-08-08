<?php

declare(strict_types=1);

namespace LombokClarion\Log;

use DateTimeImmutable;

/**
 * One log entry, frozen. Handlers receive this rather than loose arguments so
 * that a formatter cannot silently disagree with a handler about what the
 * fields were.
 *
 * The timestamp is taken once, when the record is made, not when a handler
 * gets around to writing it — otherwise two handlers on the same record can
 * disagree about when the thing happened, and under a queue or a buffering
 * handler they will.
 */
final class LogRecord
{
    /**
     * @param array<string, mixed> $context structured fields; the whole point
     *        of this framework's logging being structured rather than a
     *        sprintf of everything into one string
     */
    public function __construct(
        public readonly LogLevel $level,
        public readonly string $message,
        public readonly array $context = [],
        public readonly ?DateTimeImmutable $time = null,
        public readonly string $channel = 'app',
    ) {
    }

    public function time(): DateTimeImmutable
    {
        return $this->time ?? new DateTimeImmutable();
    }

    /**
     * @param array<string, mixed> $context merged over the existing context
     */
    public function withContext(array $context): self
    {
        return new self(
            $this->level,
            $this->message,
            [...$this->context, ...$context],
            $this->time,
            $this->channel,
        );
    }

    public function withChannel(string $channel): self
    {
        return new self($this->level, $this->message, $this->context, $this->time, $channel);
    }
}
