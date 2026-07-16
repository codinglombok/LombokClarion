<?php

declare(strict_types=1);

namespace LombokClarion\Bus;

interface RetriesQueuedCommand
{
    public function retryPolicy(): RetryPolicy;
}
