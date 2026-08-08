<?php

declare(strict_types=1);

namespace LombokClarion\Log;

use Throwable;

/**
 * The Logger applications get: redacts, stamps the time once, and fans the
 * record out to every handler.
 *
 * HANDLERS ARE ISOLATED FROM EACH OTHER. A handler that throws does not stop
 * the others from receiving the record. The reason is the shape of the
 * failure: the moment a handler is most likely to break — disk full, syslog
 * socket gone, database down — is the moment something is already wrong and
 * the log line is the only evidence of it. Letting the broken destination take
 * out the working ones means losing the record precisely when it mattered.
 *
 * REDACTION IS NOT OPTIONAL. It happens here, before any handler sees the
 * record, and there is no constructor flag to disable it. See
 * {@see Redactor} for why an opt-in version is the same as no version.
 *
 * The timestamp is stamped once here rather than per handler, so two
 * destinations cannot disagree about when the event happened.
 */
final class ChannelLogger implements Logger
{
    use LogsConveniently;

    /** @var list<LogHandler> */
    private readonly array $handlers;

    private readonly Redactor $redactor;

    /**
     * @param list<LogHandler> $handlers
     * @param array<string, mixed> $baseContext fields merged into every record
     *        from this logger — request id, deployment, whatever this process
     *        knows and every line should carry
     */
    public function __construct(
        array $handlers,
        private readonly string $channel = 'app',
        ?Redactor $redactor = null,
        private readonly array $baseContext = [],
    ) {
        $this->handlers = array_values($handlers);
        $this->redactor = $redactor ?? new Redactor();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(LogLevel $level, string $message, array $context = []): void
    {
        $record = new LogRecord(
            $level,
            $message,
            $this->redactor->redact([...$this->baseContext, ...$context]),
            new \DateTimeImmutable(),
            $this->channel,
        );

        foreach ($this->handlers as $handler) {
            try {
                $handler->handle($record);
            } catch (Throwable) {
                // Deliberately swallowed — see the class docblock. There is
                // nowhere to report this that is not itself a log handler.
            }
        }
    }

    /**
     * A logger writing to the same handlers under a different channel name.
     * Channels are for telling apart where a line came from (http, queue,
     * migrations) without standing up a separate logger and its handlers.
     */
    public function withChannel(string $channel): self
    {
        return new self($this->handlers, $channel, $this->redactor, $this->baseContext);
    }

    /**
     * @param array<string, mixed> $context merged over the existing base
     */
    public function withContext(array $context): self
    {
        return new self(
            $this->handlers,
            $this->channel,
            $this->redactor,
            [...$this->baseContext, ...$context],
        );
    }
}
