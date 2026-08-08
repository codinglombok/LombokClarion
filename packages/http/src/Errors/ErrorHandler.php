<?php

declare(strict_types=1);

namespace LombokClarion\Http\Errors;

use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Log\Logger;
use LombokClarion\Log\LogLevel;
use LombokClarion\Log\NullLogger;
use Throwable;

/**
 * Turns a crash into a response: logs it, reports it, renders a page.
 *
 * WHAT THIS DOES NOT DO — and the reason it is safe to have at all. It does
 * not decide status codes from exception types. Anything reaching handle() is
 * a 500, full stop. Exceptions that ARE an HTTP outcome implement
 * {@see \LombokClarion\Http\RendersResponse} and never get here; the Kernel
 * renders those directly. So there is still no class-to-status registry
 * anywhere in this framework, which is the property RendersResponse's docblock
 * was written to protect. What this class adds is what happens AFTER we have
 * already decided it is a 500.
 *
 * DEBUG IS THE ONLY SWITCH, AND IT GATES THE DATA, NOT THE TEMPLATE. When
 * $debug is false, the exception's message, class, file, line and trace are
 * never handed to the renderer at all — they are not passed and then hidden by
 * a template that might be overridden, they simply do not leave this class.
 * A leak then requires changing this file rather than misconfiguring a view,
 * which is the difference between a mistake someone can make in five minutes
 * and one that has to be written on purpose.
 *
 * That matters because the leak is not hypothetical: an exception message
 * routinely contains a DSN, a file path revealing the deploy layout, or the
 * value that failed validation.
 */
final class ErrorHandler
{
    private readonly Logger $logger;

    /** @var list<ExceptionReporter> */
    private readonly array $reporters;

    /**
     * @param bool $debug true in local development ONLY. Wire this from
     *        config('app.debug'); it must be false in production.
     * @param list<ExceptionReporter> $reporters
     */
    public function __construct(
        private readonly ErrorPageRenderer $renderer,
        private readonly bool $debug = false,
        ?Logger $logger = null,
        array $reporters = [],
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->reporters = array_values($reporters);
    }

    /**
     * The full path for an uncaught exception: log, report, render a 500.
     */
    public function handle(Throwable $exception, Request $request): Response
    {
        $context = $this->contextFor($exception, $request);

        // Logged at Critical, not Error: this is an unhandled crash reaching
        // the top of the stack, distinct from an application deliberately
        // logging that something failed. Keeping them at different levels is
        // what makes "alert me on crashes" expressible without alerting on
        // every handled error.
        $this->logger->log(LogLevel::Critical, 'Uncaught exception: ' . $exception->getMessage(), $context);

        foreach ($this->reporters as $reporter) {
            try {
                $reporter->report($exception, $request, $context);
            } catch (Throwable $reporterFailure) {
                // A reporter that breaks must not replace the original error.
                // Logged rather than swallowed so a permanently broken
                // reporter is discoverable instead of just silent.
                $this->logger->log(LogLevel::Error, 'Exception reporter failed: ' . $reporterFailure->getMessage(), [
                    'reporter' => $reporter::class,
                ]);
            }
        }

        return $this->renderStatus(500, $request, $this->debug ? $this->debugDetails($exception) : []);
    }

    /**
     * For statuses that are an outcome rather than a crash — the router's 404
     * and 405, an authorization failure that decided to be a 403. Nothing is
     * logged as critical and no reporter fires: these are not bugs.
     *
     * @param array<string, mixed> $details
     */
    public function renderStatus(int $status, Request $request, array $details = []): Response
    {
        try {
            return $this->renderer->render($status, $request, $details);
        } catch (Throwable $renderFailure) {
            // The renderer itself failed — a missing error template, a broken
            // view compiler. Falling through to a plain-text response is the
            // whole point: the alternative is an exception thrown from inside
            // error handling, which loses the original problem and hands the
            // user a blank page.
            $this->logger->log(LogLevel::Error, 'Error page renderer failed: ' . $renderFailure->getMessage(), [
                'status' => $status,
            ]);

            return Response::text($this->reasonPhrase($status), $status);
        }
    }

    /**
     * Request/environment fields worth having on every crash. Values that
     * commonly carry secrets — headers, body — are NOT collected: the logger
     * redacts by key name, but the safest handling for a bearer token is not
     * to put it in the context in the first place.
     *
     * @return array<string, mixed>
     */
    private function contextFor(Throwable $exception, ?Request $request): array
    {
        $context = [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];

        if ($request !== null) {
            $context['method'] = $request->method;
            // Path only — never the query string, which is where an id, a
            // token, or a reset link ends up.
            $context['path'] = $request->path;
        }

        if ($exception->getPrevious() !== null) {
            $context['previous'] = $exception->getPrevious()::class;
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private function debugDetails(Throwable $exception): array
    {
        return [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ];
    }

    private function reasonPhrase(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            419 => 'Page Expired',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            503 => 'Service Unavailable',
            default => 'Internal Server Error',
        };
    }
}
