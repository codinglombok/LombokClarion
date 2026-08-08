<?php

declare(strict_types=1);

use LombokClarion\Container\Container;
use LombokClarion\Http\Errors\ErrorHandler;
use LombokClarion\Http\Errors\ErrorPageRenderer;
use LombokClarion\Http\Errors\ExceptionReporter;
use LombokClarion\Http\Errors\PlainErrorPageRenderer;
use LombokClarion\Http\RendersResponse;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use LombokClarion\Log\ChannelLogger;
use LombokClarion\Log\Handlers\InMemoryHandler;
use LombokClarion\Log\LogLevel;
use LombokClarion\Routing\Kernel;
use LombokClarion\Routing\Router;
use LombokClarion\View\ViewEngine;
use LombokClarion\View\ViewErrorPageRenderer;

/**
 * Stage 17 slice A — error pages and the crash path: production must not leak,
 * the fallback ladder must not depend on the view layer being healthy, and the
 * Kernel's pre-Stage-17 contract must be unchanged when no handler is wired.
 */

final class Test_RecordingReporter implements ExceptionReporter
{
    /** @var list<string> */
    public array $reported = [];

    public function report(Throwable $exception, ?Request $request, array $context = []): void
    {
        $this->reported[] = $exception::class;
    }
}

final class Test_BrokenReporter implements ExceptionReporter
{
    public function report(Throwable $exception, ?Request $request, array $context = []): void
    {
        throw new RuntimeException('the reporter itself is broken');
    }
}

final class Test_BrokenRenderer implements ErrorPageRenderer
{
    public function render(int $status, Request $request, array $details = []): Response
    {
        throw new RuntimeException('the error template is broken');
    }
}

/** An exception that IS an http outcome — the RendersResponse contract. */
final class Test_TeapotException extends RuntimeException implements RendersResponse
{
    public function toResponse(): Response
    {
        return Response::text('I am a teapot', 418);
    }
}

final class Test_CrashingController
{
    public function boom(Request $request): Response
    {
        throw new LogicException('DSN=mysql://root:hunter2@db/app');
    }

    public function teapot(Request $request): Response
    {
        throw new Test_TeapotException('short and stout');
    }
}

function errors_request(string $path = '/x', array $headers = []): Request
{
    return new Request('GET', $path, [], [], $headers);
}

function errors_views(): ViewEngine
{
    return new ViewEngine(
        dirname(__DIR__) . '/resources/views',
        sys_get_temp_dir() . '/lc-errview-' . bin2hex(random_bytes(6))
    );
}

// ------------------------------------------------------------ production leak

test('production never hands the exception message to the renderer', function (): void {
    $handler = new ErrorHandler(new PlainErrorPageRenderer(), debug: false);

    $response = $handler->handle(new RuntimeException('DSN=mysql://root:hunter2@db/app'), errors_request());

    assertSame(500, $response->status);
    assertTrue(!str_contains($response->body, 'hunter2'), 'the exception message leaked into the response');
    assertTrue(!str_contains($response->body, 'mysql://'), 'a connection string leaked into the response');
});

test('production leaks nothing through the JSON path either', function (): void {
    $handler = new ErrorHandler(new PlainErrorPageRenderer(), debug: false);

    $response = $handler->handle(
        new RuntimeException('secret-value-9000'),
        errors_request('/x', ['accept' => 'application/json'])
    );

    assertTrue(!str_contains($response->body, 'secret-value-9000'), 'the message leaked into the JSON body');

    $decoded = json_decode($response->body, true);
    assertSame(500, $decoded['status']);
    assertTrue(!isset($decoded['debug']), 'a debug key was present in production output');
});

test('debug mode shows the details — and escapes them', function (): void {
    $handler = new ErrorHandler(new PlainErrorPageRenderer(), debug: true);

    $response = $handler->handle(new RuntimeException('boom <script>alert(1)</script>'), errors_request());

    assertTrue(str_contains($response->body, 'boom'), 'debug mode did not show the message');
    assertTrue(!str_contains($response->body, '<script>'), 'debug output was not escaped — that is still XSS');
});

// ------------------------------------------------------------ logging + reporting

test('an uncaught exception is logged at critical, distinct from a handled error', function (): void {
    $sink = new InMemoryHandler();
    $handler = new ErrorHandler(new PlainErrorPageRenderer(), false, new ChannelLogger([$sink]));

    $handler->handle(new RuntimeException('kaboom'), errors_request('/orders'));

    $record = $sink->recordsAt(LogLevel::Critical)[0];
    assertSame(RuntimeException::class, $record->context['exception']);
    assertSame('/orders', $record->context['path']);
    assertTrue(isset($record->context['trace']), 'the trace was not captured');
});

test('the query string is never captured into log context', function (): void {
    $sink = new InMemoryHandler();
    $handler = new ErrorHandler(new PlainErrorPageRenderer(), false, new ChannelLogger([$sink]));

    $request = new Request('GET', '/reset', ['token' => 'secret-reset-token']);
    $handler->handle(new RuntimeException('x'), $request);

    $encoded = json_encode($sink->records()[0]->context);
    assertTrue(!str_contains((string) $encoded, 'secret-reset-token'), 'a query-string value reached the log');
});

test('reporters are called, and a broken one cannot replace the original error', function (): void {
    $sink = new InMemoryHandler();
    $good = new Test_RecordingReporter();

    $handler = new ErrorHandler(
        new PlainErrorPageRenderer(),
        false,
        new ChannelLogger([$sink]),
        [new Test_BrokenReporter(), $good]
    );

    $response = $handler->handle(new LogicException('original problem'), errors_request());

    assertSame(500, $response->status, 'a broken reporter took out the response');
    assertSame([LogicException::class], $good->reported, 'a later reporter was skipped after an earlier one threw');
    assertTrue($sink->hasRecordMatching('Exception reporter failed'), 'the reporter failure was silently swallowed');
});

test('renderStatus does not log a crash or fire reporters — a 404 is not a bug', function (): void {
    $sink = new InMemoryHandler();
    $reporter = new Test_RecordingReporter();
    $handler = new ErrorHandler(new PlainErrorPageRenderer(), false, new ChannelLogger([$sink]), [$reporter]);

    $response = $handler->renderStatus(404, errors_request());

    assertSame(404, $response->status);
    assertSame([], $sink->recordsAt(LogLevel::Critical), 'a 404 was logged as a crash');
    assertSame([], $reporter->reported, 'a 404 fired an exception reporter');
});

test('a renderer that throws falls back to plain text instead of blanking', function (): void {
    $sink = new InMemoryHandler();
    $handler = new ErrorHandler(new Test_BrokenRenderer(), false, new ChannelLogger([$sink]));

    $response = $handler->renderStatus(404, errors_request());

    assertSame(404, $response->status, 'the status was lost when the renderer failed');
    assertSame('Not Found', $response->body);
    assertTrue($sink->hasRecordMatching('Error page renderer failed'), 'the renderer failure was not logged');
});

// ------------------------------------------------------------ content negotiation

test('an API client asking for JSON gets JSON even when the server is what failed', function (): void {
    $renderer = new PlainErrorPageRenderer();

    $json = $renderer->render(500, errors_request('/x', ['accept' => 'application/json']));
    $ajax = $renderer->render(500, errors_request('/x', ['x-requested-with' => 'XMLHttpRequest']));

    assertSame('application/json', $json->headers['Content-Type'] ?? '');
    assertSame('application/json', $ajax->headers['Content-Type'] ?? '');
});

test('*/* is not a request for JSON — curl and browsers both send it', function (): void {
    $renderer = new PlainErrorPageRenderer();

    $response = $renderer->render(404, errors_request('/x', ['accept' => '*/*']));

    assertTrue(str_contains($response->headers['Content-Type'] ?? '', 'text/html'));
});

// ------------------------------------------------------------ view renderer ladder

test('the view renderer picks the status-specific template when one exists', function (): void {
    $renderer = new ViewErrorPageRenderer(errors_views());

    $notFound = $renderer->render(404, errors_request('/x', ['accept' => 'text/html']));
    $forbidden = $renderer->render(403, errors_request('/x', ['accept' => 'text/html']));

    assertSame(404, $notFound->status);
    assertTrue(str_contains($notFound->body, 'Page not found'), 'errors/404 was not used');
    assertTrue(str_contains($forbidden->body, 'Not allowed'), 'errors/403 was not used');
});

test('a status with no template of its own falls to errors/generic', function (): void {
    $renderer = new ViewErrorPageRenderer(errors_views());

    $response = $renderer->render(429, errors_request('/x', ['accept' => 'text/html']));

    assertSame(429, $response->status);
    assertTrue(str_contains($response->body, 'Something went wrong'), 'errors/generic was not used');
    assertTrue(str_contains($response->body, '429'), 'the status was not interpolated');
});

test('templates compile fully — no directive or placeholder leaks into output', function (): void {
    $renderer = new ViewErrorPageRenderer(errors_views());

    foreach ([404, 403, 500, 429] as $status) {
        $body = $renderer->render($status, errors_request('/x', ['accept' => 'text/html']))->body;

        assertTrue(!str_contains($body, '@extends'), "an uncompiled directive leaked at $status");
        assertTrue(!str_contains($body, '@section'), "an uncompiled directive leaked at $status");
        assertTrue(!str_contains($body, '{{'), "an uncompiled placeholder leaked at $status");
    }
});

test('when the view layer itself is broken, the last rung still answers', function (): void {
    $broken = new ViewEngine('/nonexistent/templates', sys_get_temp_dir() . '/lc-none');
    $renderer = new ViewErrorPageRenderer($broken);

    $response = $renderer->render(500, errors_request('/x', ['accept' => 'text/html']));

    assertSame(500, $response->status);
    assertTrue(str_contains($response->body, 'Internal Server Error'), 'the plain fallback did not run');
});

test('the error templates do not depend on $theme or $assets', function (): void {
    // Rendered with no data beyond what the renderer passes: if a template
    // reached for the app layout's variables this would fail.
    $renderer = new ViewErrorPageRenderer(errors_views());

    $response = $renderer->render(500, errors_request('/x', ['accept' => 'text/html']));

    assertTrue(str_contains($response->body, 'broke on our end'), 'errors/500 failed to render standalone');
});

// ------------------------------------------------------------ kernel wiring

test('a Kernel with no error handler behaves exactly as it did before Stage 17', function (): void {
    $router = new Router();
    $router->get('/boom', [Test_CrashingController::class, 'boom']);
    $kernel = new Kernel(new Container(), $router);

    $notFound = $kernel->handle(errors_request('/nowhere'));

    assertSame(404, $notFound->status);
    assertSame('Not Found', $notFound->body, 'the plain 404 body changed');

    // The crash still bubbles to the SAPI rather than being dressed up.
    assertThrows(LogicException::class, fn () => $kernel->handle(errors_request('/boom')));
});

test('a Kernel with an error handler renders the crash instead of bubbling', function (): void {
    $sink = new InMemoryHandler();
    $router = new Router();
    $router->get('/boom', [Test_CrashingController::class, 'boom']);

    $kernel = new Kernel(
        new Container(),
        $router,
        errorHandler: new ErrorHandler(new PlainErrorPageRenderer(), false, new ChannelLogger([$sink]))
    );

    $response = $kernel->handle(errors_request('/boom'));

    assertSame(500, $response->status);
    assertTrue(!str_contains($response->body, 'hunter2'), 'the controller exception leaked through the kernel');
    assertSame(1, count($sink->recordsAt(LogLevel::Critical)));
});

test('404 and 405 go through the renderer when a handler is wired', function (): void {
    $router = new Router();
    $router->get('/only-get', [Test_CrashingController::class, 'boom']);

    $kernel = new Kernel(
        new Container(),
        $router,
        errorHandler: new ErrorHandler(new PlainErrorPageRenderer(), false)
    );

    $notFound = $kernel->handle(errors_request('/nowhere'));
    $notAllowed = $kernel->handle(new Request('POST', '/only-get'));

    assertSame(404, $notFound->status);
    assertTrue(str_contains($notFound->body, '<!DOCTYPE'), 'the 404 was not rendered as a page');
    assertSame(405, $notAllowed->status);
});

test('RendersResponse still wins — Stage 17 did not create a class-to-status map', function (): void {
    $sink = new InMemoryHandler();
    $router = new Router();
    $router->get('/teapot', [Test_CrashingController::class, 'teapot']);

    $kernel = new Kernel(
        new Container(),
        $router,
        errorHandler: new ErrorHandler(new PlainErrorPageRenderer(), false, new ChannelLogger([$sink]))
    );

    $response = $kernel->handle(errors_request('/teapot'));

    // The exception rendered ITSELF. The error handler never saw it, so it
    // was not logged as a crash: an exception that is an HTTP outcome is not
    // a bug, and the presence of an error handler must not change that.
    assertSame(418, $response->status);
    assertSame('I am a teapot', $response->body);
    assertSame([], $sink->recordsAt(LogLevel::Critical), 'an HTTP-outcome exception was logged as a crash');
});

// ------------------------------------------------------------ debug gating

test('APP_DEBUG cannot turn debug ON outside a local environment', function (): void {
    $original = ['env' => getenv('APP_ENV'), 'debug' => getenv('APP_DEBUG')];

    try {
        // The failure this locks out: someone leaves APP_DEBUG=true in a
        // deploy config and stack traces start reaching the public. The flag
        // is deliberately incapable of causing it.
        putenv('APP_ENV=production');
        putenv('APP_DEBUG=true');
        assertTrue(!\App\Infrastructure\ServiceFactories::debugEnabled(), 'APP_DEBUG forced debug on in production');

        // An unset APP_ENV must read as production for the same reason
        // appKey() treats it that way — a container that lost its environment
        // must not start serving traces.
        putenv('APP_ENV=');
        putenv('APP_DEBUG=true');
        assertTrue(!\App\Infrastructure\ServiceFactories::debugEnabled(), 'an unset APP_ENV defaulted to debug');

        putenv('APP_ENV=local');
        putenv('APP_DEBUG=true');
        assertTrue(\App\Infrastructure\ServiceFactories::debugEnabled(), 'debug was unavailable locally');

        // It can still be turned OFF locally — the asymmetry only blocks
        // turning it on where it does not belong.
        putenv('APP_ENV=local');
        putenv('APP_DEBUG=false');
        assertTrue(!\App\Infrastructure\ServiceFactories::debugEnabled(), 'APP_DEBUG=false was ignored locally');
    } finally {
        putenv($original['env'] === false ? 'APP_ENV' : 'APP_ENV=' . $original['env']);
        putenv($original['debug'] === false ? 'APP_DEBUG' : 'APP_DEBUG=' . $original['debug']);
    }
});
