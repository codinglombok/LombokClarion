<?php

declare(strict_types=1);

namespace Tests\Scheduler;

use LombokClarion\Console\ConsoleKernel;
use LombokClarion\Container\Container;
use LombokClarion\Scheduler\CronExpression;
use LombokClarion\Scheduler\ScheduledTask;
use LombokClarion\Scheduler\Scheduler;
use PHPUnit\Framework\TestCase;

final class SchedulerTest extends TestCase
{
    private function at(string $dateTime): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dateTime);
    }

    public function testWildcardMatchesEverything(): void
    {
        $this->assertTrue(CronExpression::matches('* * * * *', $this->at('2025-08-28 07:23:00')));
    }

    public function testExactMinuteMatches(): void
    {
        $this->assertTrue(CronExpression::matches('23 * * * *', $this->at('2025-08-28 07:23:00')));
        $this->assertFalse(CronExpression::matches('24 * * * *', $this->at('2025-08-28 07:23:00')));
    }

    public function testRangeAndListSemantics(): void
    {
        $now = $this->at('2025-08-28 07:05:00');

        $this->assertTrue(CronExpression::matches('1-10 * * * *', $now));
        $this->assertTrue(CronExpression::matches('1,3,5 * * * *', $now));
        $this->assertFalse(CronExpression::matches('6-10 * * * *', $now));
        $this->assertFalse(CronExpression::matches('2,4,6 * * * *', $now));
    }

    public function testStepSemantics(): void
    {
        $at705 = $this->at('2025-08-28 07:05:00');
        $at707 = $this->at('2025-08-28 07:07:00');

        $this->assertTrue(CronExpression::matches('*/5 * * * *', $at705));
        $this->assertFalse(CronExpression::matches('*/5 * * * *', $at707));
        $this->assertTrue(CronExpression::matches('1-10/2 * * * *', $at705));
    }

    public function testDayOfWeekSevenIsSundayAlias(): void
    {
        $sunday = $this->at('2025-08-31 12:00:00'); // a Sunday

        $this->assertTrue(CronExpression::matches('0 12 * * 0', $sunday));
        $this->assertTrue(CronExpression::matches('0 12 * * 7', $sunday));
        $this->assertFalse(CronExpression::matches('0 12 * * 1', $sunday));
    }

    public function testInvalidExpressionThrows(): void
    {
        $this->expectException(\LombokClarion\Scheduler\Exceptions\SchedulerException::class);
        CronExpression::matches('99 99 * *', $this->at('2025-08-28 07:23:00'));
    }

    public function testFluentFrequencyHelpersProduceExpectedExpressions(): void
    {
        $task = ScheduledTask::command('prune')->everyFiveMinutes();
        $this->assertSame('*/5 * * * *', $task->expression());

        $daily = ScheduledTask::command('backup')->daily()->at('03:30');
        $this->assertSame('30 3 * * *', $daily->expression());
    }

    public function testCallbackFactoryAndAccessorAreDistinctMembers(): void
    {
        // Regression: PHP forbids two methods with the same name — the static
        // factory `callback()` and the instance accessor once collided. The
        // accessor is now callbackClosure(); both must work.
        $called = false;
        $task = ScheduledTask::callback(static function () use (&$called): void {
            $called = true;
        })->everyMinute();

        $this->assertInstanceOf(\Closure::class, $task->callbackClosure());
        $this->assertFalse($task->isCommand());
        $this->assertTrue($task->isDueAt($this->at('2025-08-28 07:00:00')));

        $run = new Scheduler(new ConsoleKernel(new Container()));
        $run->add($task);
        $results = $run->run($this->at('2025-08-28 07:00:00'));

        $this->assertCount(1, $results);
        $this->assertSame('ok', $results[0]['status']);
        $this->assertTrue($called);
    }

    public function testCommandTaskDescriptionDefaultsToCommandName(): void
    {
        $task = ScheduledTask::command('prune', '--force')->hourly();

        $this->assertSame('prune', $task->description());
        $this->assertTrue($task->isCommand());
        $this->assertSame(['--force'], $task->commandArgs());
    }

    public function testDescribeOverridesDescription(): void
    {
        $task = ScheduledTask::command('prune')->daily()->describe('Nightly prune');

        $this->assertSame('Nightly prune', $task->description());
    }

    public function testRunSkipsNotDueTasks(): void
    {
        $called = false;
        $scheduler = new Scheduler(new ConsoleKernel(new Container()));
        $scheduler->add(ScheduledTask::callback(static function () use (&$called): void {
            $called = true;
        })->cron('0 3 * * *'));

        $results = $scheduler->run($this->at('2025-08-28 07:00:00'));

        $this->assertSame([], $results);
        $this->assertFalse($called);
    }

    public function testRunCapturesCallbackErrorsWithoutThrowing(): void
    {
        $scheduler = new Scheduler(new ConsoleKernel(new Container()));
        $scheduler->add(ScheduledTask::callback(static function (): void {
            throw new \RuntimeException('boom');
        })->everyMinute()->describe('exploding task'));

        $results = $scheduler->run($this->at('2025-08-28 07:00:00'));

        $this->assertCount(1, $results);
        $this->assertSame('error', $results[0]['status']);
        $this->assertSame('boom', $results[0]['error']);
    }

    public function testWithoutOverlappingReleasesLockAfterRun(): void
    {
        // Overlap locks are per-process and released in `finally`; a plain
        // sequential run therefore runs twice — assert that behavior so a
        // future regression to "skip everything" is caught.
        $runs = 0;

        $plain = new Scheduler(new ConsoleKernel(new Container()));
        $plain->add(ScheduledTask::callback(static function () use (&$runs): void {
            $runs++;
        })->everyMinute()->withoutOverlapping());

        $plain->run($this->at('2025-08-28 07:00:00'));
        $plain->run($this->at('2025-08-28 07:00:00'));

        $this->assertSame(2, $runs);
    }

    public function testListDescribesAllTasks(): void
    {
        $scheduler = new Scheduler(new ConsoleKernel(new Container()));
        $scheduler->add(ScheduledTask::command('prune')->daily()->describe('Prune'));
        $scheduler->add(ScheduledTask::callback(static fn (): bool => true)->hourly());

        $list = $scheduler->list();

        $this->assertCount(2, $list);
        $this->assertSame('Prune', $list[0]['description']);
        $this->assertSame('0 0 * * *', $list[0]['expression']);
        $this->assertArrayHasKey('expression', $list[1]);
    }
}
