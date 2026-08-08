<?php

declare(strict_types=1);

use LombokClarion\Log\ChannelLogger;
use LombokClarion\Log\Formatters\JsonFormatter;
use LombokClarion\Log\Formatters\LineFormatter;
use LombokClarion\Log\Handlers\InMemoryHandler;
use LombokClarion\Log\Handlers\StreamHandler;
use LombokClarion\Log\LogHandler;
use LombokClarion\Log\LogLevel;
use LombokClarion\Log\LogRecord;
use LombokClarion\Log\NullLogger;
use LombokClarion\Log\Redactor;

/**
 * Stage 17 slice A — the logging package: levels, redaction (non-optional),
 * handler isolation, formatters, and the stream handler's rotation.
 */

/** A handler that always throws — for the isolation guarantee. */
final class Test_ExplodingHandler implements LogHandler
{
    public int $calls = 0;

    public function handle(LogRecord $record): void
    {
        $this->calls++;
        throw new RuntimeException('handler is broken');
    }
}

function logging_tmpdir(): string
{
    $dir = sys_get_temp_dir() . '/lc-log-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o777, true);

    return $dir;
}

// ------------------------------------------------------------ levels

test('LogLevel severities follow RFC 5424 — emergency most severe', function (): void {
    assertSame(0, LogLevel::Emergency->severity());
    assertSame(7, LogLevel::Debug->severity());
    assertTrue(LogLevel::Error->severity() < LogLevel::Warning->severity());
});

test('isAtLeast() answers the question a handler actually asks', function (): void {
    assertTrue(LogLevel::Critical->isAtLeast(LogLevel::Warning), 'critical should pass a warning threshold');
    assertTrue(LogLevel::Warning->isAtLeast(LogLevel::Warning), 'the threshold itself should pass');
    assertTrue(!LogLevel::Info->isAtLeast(LogLevel::Warning), 'info should not pass a warning threshold');
});

test('a mistyped level fails at the call site instead of vanishing', function (): void {
    assertThrows(ValueError::class, fn () => LogLevel::from('warn'));
});

// ------------------------------------------------------------ redaction

test('redaction masks secrets by key name, case-insensitively', function (): void {
    $out = (new Redactor())->redact([
        'user_id' => 42,
        'password' => 'hunter2',
        'Authorization' => 'Bearer abc',
        'API_KEY' => 'sk-1',
    ]);

    assertSame(42, $out['user_id'], 'a harmless field was masked');
    assertSame(Redactor::MASK, $out['password']);
    assertSame(Redactor::MASK, $out['Authorization']);
    assertSame(Redactor::MASK, $out['API_KEY']);
});

test('redaction walks nested arrays — the leak case IS the nested case', function (): void {
    $out = (new Redactor())->redact([
        'request' => ['form' => ['password' => 'hunter2', 'email' => 'a@example.invalid']],
    ]);

    assertSame(Redactor::MASK, $out['request']['form']['password']);
    assertSame('a@example.invalid', $out['request']['form']['email'], 'a harmless nested field was masked');
});

test('additional sensitive keys extend the defaults rather than replacing them', function (): void {
    $out = (new Redactor(['nik']))->redact(['nik' => '3201...', 'password' => 'x', 'name' => 'Ana']);

    assertSame(Redactor::MASK, $out['nik'], 'the app-specific key was not redacted');
    assertSame(Redactor::MASK, $out['password'], 'a default key was dropped by passing extras');
    assertSame('Ana', $out['name']);
});

test('deeply nested context is truncated instead of exhausting the stack', function (): void {
    $deep = 'leaf';

    for ($i = 0; $i < 40; $i++) {
        $deep = ['down' => $deep];
    }

    $out = (new Redactor())->redact(['top' => $deep]);

    // The assertion that matters is that this returned at all rather than
    // dying: a logger that crashes the process while reporting an error is
    // worse than the error it was reporting.
    assertTrue(is_array($out), 'redaction did not survive deep nesting');
});

test('redaction is applied by the logger itself — there is no way to opt out', function (): void {
    $handler = new InMemoryHandler();
    $logger = new ChannelLogger([$handler]);

    $logger->info('login failed', ['password' => 'hunter2']);

    assertSame(Redactor::MASK, $handler->records()[0]->context['password']);
});

test('base context is redacted too, not just per-call context', function (): void {
    $handler = new InMemoryHandler();
    $logger = new ChannelLogger([$handler], 'app', null, ['api_token' => 'sk-live-1']);

    $logger->info('hello');

    assertSame(Redactor::MASK, $handler->records()[0]->context['api_token']);
});

// ------------------------------------------------------------ logger

test('the eight shorthands map to the eight levels', function (): void {
    $handler = new InMemoryHandler();
    $logger = new ChannelLogger([$handler]);

    $logger->emergency('a');
    $logger->alert('b');
    $logger->critical('c');
    $logger->error('d');
    $logger->warning('e');
    $logger->notice('f');
    $logger->info('g');
    $logger->debug('h');

    $levels = array_map(static fn (LogRecord $r): string => $r->level->value, $handler->records());

    assertSame(
        ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'],
        $levels
    );
});

test('a broken handler does not stop the working ones', function (): void {
    $broken = new Test_ExplodingHandler();
    $working = new InMemoryHandler();
    $logger = new ChannelLogger([$broken, $working]);

    $logger->critical('database is down');

    assertSame(1, $broken->calls, 'the broken handler was never called');
    assertSame(1, count($working->records()), 'a broken handler swallowed the record');
});

test('one timestamp per record, so two handlers cannot disagree about when', function (): void {
    $a = new InMemoryHandler();
    $b = new InMemoryHandler();
    $logger = new ChannelLogger([$a, $b]);

    $logger->info('once');

    assertSame(
        $a->records()[0]->time()->format('Y-m-d\TH:i:s.u'),
        $b->records()[0]->time()->format('Y-m-d\TH:i:s.u')
    );
});

test('withChannel and withContext derive a logger without mutating the original', function (): void {
    $handler = new InMemoryHandler();
    $logger = new ChannelLogger([$handler], 'app');
    $queue = $logger->withChannel('queue')->withContext(['job_id' => 7]);

    $logger->info('from app');
    $queue->info('from queue');

    assertSame('app', $handler->records()[0]->channel);
    assertSame([], $handler->records()[0]->context, 'derived context leaked back into the original');
    assertSame('queue', $handler->records()[1]->channel);
    assertSame(7, $handler->records()[1]->context['job_id']);
});

test('NullLogger accepts every level and records nothing', function (): void {
    $logger = new NullLogger();
    $logger->emergency('x');
    $logger->debug('y', ['k' => 'v']);

    // Reaching here without a fatal is the whole assertion: the point of
    // NullLogger is that no call site needs a null check.
    assertTrue(true);
});

// ------------------------------------------------------------ formatters

test('JsonFormatter emits one parseable line per record', function (): void {
    $record = new LogRecord(
        LogLevel::Error,
        "multi\nline message",
        ['k' => 'v'],
        new DateTimeImmutable('2026-08-07T10:15:30+00:00')
    );

    $line = (new JsonFormatter())->format($record);

    assertTrue(!str_contains($line, "\n"), 'a newline in the message broke the one-line invariant');

    $decoded = json_decode($line, true);
    assertTrue(is_array($decoded), 'output was not valid JSON');
    assertSame('error', $decoded['level']);
    assertSame("multi\nline message", $decoded['message'], 'the message did not survive the round trip');
    assertSame('v', $decoded['context']['k']);
});

test('JsonFormatter survives context that cannot be cleanly encoded', function (): void {
    $record = new LogRecord(LogLevel::Info, 'binary', ['blob' => "\xB1\x31"]);

    $line = (new JsonFormatter())->format($record);

    assertTrue(!str_contains($line, "\n"), 'the fallback broke the one-line invariant');
    assertTrue(is_array(json_decode($line, true)), 'the fallback was not valid JSON');
});

test('LineFormatter keeps a record on one line and appends context as JSON', function (): void {
    $record = new LogRecord(
        LogLevel::Warning,
        "two\nlines",
        ['k' => 'v'],
        new DateTimeImmutable('2026-08-07T10:15:30+00:00')
    );

    $line = (new LineFormatter())->format($record);

    assertTrue(!str_contains($line, "\n"), 'the message newline was not escaped');
    assertTrue(str_contains($line, 'WARNING'), 'the level is missing');
    assertTrue(str_contains($line, '{"k":"v"}'), 'context was dropped');
});

test('formatters do not terminate their own lines — that belongs to the handler', function (): void {
    $record = new LogRecord(LogLevel::Info, 'x');

    assertTrue(!str_ends_with((new JsonFormatter())->format($record), "\n"));
    assertTrue(!str_ends_with((new LineFormatter())->format($record), "\n"));
});

// ------------------------------------------------------------ stream handler

test('StreamHandler writes one line per record, above its threshold only', function (): void {
    $dir = logging_tmpdir();
    $path = $dir . '/app.log';
    $logger = new ChannelLogger([new StreamHandler($path, LogLevel::Warning)]);

    $logger->debug('ignored');
    $logger->info('also ignored');
    $logger->warning('kept');
    $logger->critical('kept too');

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents($path))));

    assertSame(2, count($lines), 'threshold filtering let the wrong number of records through');
    assertTrue(str_contains($lines[0], 'kept'));
});

test('StreamHandler creates its directory at construction, and says so if it cannot', function (): void {
    $dir = logging_tmpdir() . '/nested/deeper';
    new StreamHandler($dir . '/app.log');

    assertTrue(is_dir($dir), 'the log directory was not created');

    // A misconfiguration should surface at boot, not at 3am on the first
    // error — this is the one failure the handler is loud about.
    assertThrows(RuntimeException::class, fn () => new StreamHandler('/proc/nope/app.log'));
});

test('a write failure does not throw — a full disk must not kill the request', function (): void {
    $dir = logging_tmpdir();
    $path = $dir . '/app.log';
    $handler = new StreamHandler($path);

    // Make the file unwritable, then log through it.
    file_put_contents($path, '');
    chmod($path, 0o400);

    $logger = new ChannelLogger([$handler]);
    $logger->error('cannot be written');

    // Surviving the call is the assertion.
    assertTrue(true);

    chmod($path, 0o600);
});

test('daily rotation puts the date in the filename instead of renaming files', function (): void {
    $dir = logging_tmpdir();
    $handler = new StreamHandler($dir . '/app.log', LogLevel::Debug, null, daily: true);

    $handler->handle(new LogRecord(LogLevel::Info, 'today'));

    $expected = $dir . '/app-' . (new DateTimeImmutable())->format('Y-m-d') . '.log';

    assertTrue(is_file($expected), 'the dated file was not created');
    assertTrue(!is_file($dir . '/app.log'), 'the undated path was written to as well');
});

test('retention deletes rotated files older than the window, keeping recent ones', function (): void {
    $dir = logging_tmpdir();

    $old = $dir . '/app-' . (new DateTimeImmutable('-30 days'))->format('Y-m-d') . '.log';
    $recent = $dir . '/app-' . (new DateTimeImmutable('-1 day'))->format('Y-m-d') . '.log';
    file_put_contents($old, "old\n");
    file_put_contents($recent, "recent\n");

    $handler = new StreamHandler($dir . '/app.log', LogLevel::Debug, null, daily: true, retentionDays: 7);
    $handler->handle(new LogRecord(LogLevel::Info, 'now'));

    assertTrue(!is_file($old), 'a file past the retention window survived');
    assertTrue(is_file($recent), 'a file inside the retention window was deleted');
});

// ------------------------------------------------------------ test handler

test('InMemoryHandler supports the assertions a test actually wants to make', function (): void {
    $handler = new InMemoryHandler();
    $logger = new ChannelLogger([$handler]);

    $logger->warning('cache miss for key foo');
    $logger->error('database timeout');

    assertTrue($handler->hasRecordMatching('cache miss'));
    assertSame(1, count($handler->recordsAt(LogLevel::Error)));
    assertSame('database timeout', $handler->recordsAt(LogLevel::Error)[0]->message);

    $handler->clear();
    assertSame([], $handler->records());
});
