<?php

declare(strict_types=1);

use LombokClarion\Bus\CommandBus;
use LombokClarion\Bus\CommandHandler;
use LombokClarion\Bus\Queue\DatabaseQueueStore;
use LombokClarion\Bus\Queue\InMemoryQueueStore;
use LombokClarion\Bus\Queue\QueuedCommandBus;
use LombokClarion\Bus\Queue\QueueWorker;
use LombokClarion\Bus\Queue\ShouldQueue;
use LombokClarion\Bus\RetriesQueuedCommand;
use LombokClarion\Bus\RetryPolicy;
use LombokClarion\Container\Container;

final class Test_SendEmail implements ShouldQueue
{
    public function __construct(public readonly string $to)
    {
    }
}

final class Test_SendEmailHandler implements CommandHandler
{
    public static array $sent = [];

    public function handle(object $command): mixed
    {
        self::$sent[] = $command->to;
        return null;
    }
}

final class Test_InlineCommand
{
    public function __construct(public readonly string $data)
    {
    }
}

final class Test_InlineHandler implements CommandHandler
{
    public static ?string $last = null;

    public function handle(object $command): mixed
    {
        self::$last = $command->data;
        return 'inline-result';
    }
}

final class Test_FailingCommand implements ShouldQueue, RetriesQueuedCommand
{
    public static int $attempts = 0;

    public function retryPolicy(): RetryPolicy
    {
        return new RetryPolicy(maxAttempts: 3, backoffSeconds: 0);
    }
}

final class Test_FailingHandler implements CommandHandler
{
    public function handle(object $command): mixed
    {
        Test_FailingCommand::$attempts++;
        throw new RuntimeException('boom');
    }
}

function test_make_bus(): CommandBus
{
    $container = new Container();
    $bus = new CommandBus($container);
    $bus->register(Test_SendEmail::class, Test_SendEmailHandler::class);
    $bus->register(Test_InlineCommand::class, Test_InlineHandler::class);
    $bus->register(Test_FailingCommand::class, Test_FailingHandler::class);
    return $bus;
}

test('ShouldQueue commands are pushed to the store, not handled inline', function () {
    Test_SendEmailHandler::$sent = [];
    $store = new InMemoryQueueStore();
    $queued = new QueuedCommandBus(test_make_bus(), $store);

    $result = $queued->dispatch(new Test_SendEmail('a@b.com'));
    assertSame(null, $result); // no immediate result
    assertSame([], Test_SendEmailHandler::$sent); // not handled yet
    assertSame(1, $store->size());
});

test('non-ShouldQueue commands dispatch inline and return immediately', function () {
    Test_InlineHandler::$last = null;
    $store = new InMemoryQueueStore();
    $queued = new QueuedCommandBus(test_make_bus(), $store);

    $result = $queued->dispatch(new Test_InlineCommand('hello'));
    assertSame('inline-result', $result);
    assertSame('hello', Test_InlineHandler::$last);
    assertSame(0, $store->size()); // nothing enqueued
});

test('QueueWorker processes a queued command through the real CommandBus (parity)', function () {
    Test_SendEmailHandler::$sent = [];
    $store = new InMemoryQueueStore();
    $bus = test_make_bus();
    $queued = new QueuedCommandBus($bus, $store);

    $queued->dispatch(new Test_SendEmail('x@y.com'));
    assertSame([], Test_SendEmailHandler::$sent);

    $worker = new QueueWorker($bus, $store);
    $worker->processNext();

    assertSame(['x@y.com'], Test_SendEmailHandler::$sent);
    assertSame(0, $store->size());
});

test('failed job retries up to maxAttempts then goes to failed store', function () {
    Test_FailingCommand::$attempts = 0;
    $store = new InMemoryQueueStore();
    $bus = test_make_bus();
    $queued = new QueuedCommandBus($bus, $store);

    $queued->dispatch(new Test_FailingCommand());

    $worker = new QueueWorker($bus, $store);
    // Each processNext should pop, fail, and re-enqueue until exhausted.
    $worker->drain();

    assertSame(3, Test_FailingCommand::$attempts);
    assertSame(0, $store->size()); // no more pending
    assertSame(1, count($store->failedJobs()));
    assertSame('boom', $store->failedJobs()[0]['error']);
});

test('default retry policy is single-attempt (no retry)', function () {
    $policy = RetryPolicy::none();
    assertSame(1, $policy->maxAttempts);
    assertSame(0, $policy->backoffSeconds);
});

test('drain returns count of processed jobs', function () {
    Test_SendEmailHandler::$sent = [];
    $store = new InMemoryQueueStore();
    $bus = test_make_bus();
    $queued = new QueuedCommandBus($bus, $store);

    $queued->dispatch(new Test_SendEmail('a@b.com'));
    $queued->dispatch(new Test_SendEmail('c@d.com'));

    $worker = new QueueWorker($bus, $store);
    $count = $worker->drain();

    assertSame(2, $count);
    assertSame(['a@b.com', 'c@d.com'], Test_SendEmailHandler::$sent);
});

test('DatabaseQueueStore push + pop round-trips correctly', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store = new DatabaseQueueStore($pdo);

    $store->push(new \LombokClarion\Bus\Queue\QueuedJob(
        'job-1', 'default', Test_SendEmail::class,
        serialize(new Test_SendEmail('db@test.com')),
        0, 1, 0, null
    ));

    assertSame(1, $store->size());

    $job = $store->pop();
    assertSame('job-1', $job->id);
    assertSame(Test_SendEmail::class, $job->commandClass);
    assertSame(0, $store->size());
});

test('DatabaseQueueStore::fail records the job in failed_jobs', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $store = new DatabaseQueueStore($pdo);

    $job = new \LombokClarion\Bus\Queue\QueuedJob(
        'job-fail', 'default', 'SomeCommand', '{}', 1, 1, 0, null
    );
    $store->fail($job, 'kaboom');

    $failed = $pdo->query('SELECT * FROM failed_jobs')->fetchAll(PDO::FETCH_ASSOC);
    assertSame(1, count($failed));
    assertSame('kaboom', $failed[0]['error']);
});
