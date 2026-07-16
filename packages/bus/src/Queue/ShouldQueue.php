<?php

declare(strict_types=1);

namespace LombokClarion\Bus\Queue;

/**
 * A command that implements ShouldQueue is serialized and pushed to the
 * queue store by QueuedCommandBus instead of being handled inline.
 * The worker then deserializes it and hands it to the real CommandBus.
 *
 * Default is single-attempt, no retry (§10). To opt into retries,
 * ALSO implement RetriesQueuedCommand and return a RetryPolicy.
 */
interface ShouldQueue
{
}
