<?php

declare(strict_types=1);

namespace LombokClarion\Http\Errors;

use LombokClarion\Http\Request;
use Throwable;

/**
 * Somewhere to send an exception besides the log: Sentry, Rollbar, a pager.
 *
 * An interface and nothing else — no vendor SDK is a dependency of this
 * framework, and no reporter is wired by default. An application that wants
 * Sentry writes a class implementing this and binds it, which is the same
 * amount of typing as configuring a vendor package and leaves the dependency
 * where it belongs: in the application's composer.json, not in core's.
 *
 * IMPLEMENTATIONS MUST NOT THROW. {@see ErrorHandler} calls this while already
 * handling a failure; an exception from the reporter would replace the
 * original error with a less useful one. ErrorHandler guards against it
 * anyway, but a reporter that relies on that guard is a reporter that will
 * lose reports silently.
 */
interface ExceptionReporter
{
    /**
     * @param array<string, mixed> $context request/user/environment fields,
     *        already redacted by the time this is called
     */
    public function report(Throwable $exception, ?Request $request, array $context = []): void;
}
