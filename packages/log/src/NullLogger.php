<?php

declare(strict_types=1);

namespace LombokClarion\Log;

/**
 * Logs nothing. For the constructor argument that is genuinely optional and
 * for tests that do not care.
 *
 * Exists so that nothing downstream ever has to write `if ($this->logger !==
 * null)`. A nullable logger means every call site carries a branch that is
 * never the interesting part of the code, and the one place someone forgets
 * the branch is a fatal in the error path.
 */
final class NullLogger implements Logger
{
    use LogsConveniently;

    /** @param array<string, mixed> $context */
    public function log(LogLevel $level, string $message, array $context = []): void
    {
    }
}
