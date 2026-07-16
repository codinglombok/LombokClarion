<?php
// Queue a command, then process it with the worker. Run: php examples/04-queue-worker.php
declare(strict_types=1);
require __DIR__ . '/../autoload.php';

use LombokClarion\Bus\{CommandBus};
use LombokClarion\Bus\Queue\{InMemoryQueueStore, QueuedCommandBus, QueueWorker, ShouldQueue};
use LombokClarion\Container\Container;

final class SendEmail implements ShouldQueue { public function __construct(public readonly string $to) {} }
final class SendEmailHandler { public function handle(object $c): void { echo "sent to {$c->to}\n"; } }

$container = new Container();
$bus = new CommandBus($container);
$bus->register(SendEmail::class, SendEmailHandler::class);
$store = new InMemoryQueueStore();

(new QueuedCommandBus($bus, $store))->dispatch(new SendEmail('x@y.com')); // queued, not sent
echo "pending: {$store->size()}\n";
(new QueueWorker($bus, $store))->drain();                                  // sent to x@y.com
