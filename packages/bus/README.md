# lombokclarion/bus

**Command/Query/Event bus — explicit handler registration, queued dispatch, retry.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/bus
```

## Namespace

```php
LombokClarion\Bus
```

## What's Inside

| Class | Role |
|-------|------|
| `CommandBus` | Dispatches a command → exactly one handler |
| `QueryBus` | Dispatches a query → exactly one handler |
| `EventBus` | Dispatches an event → multiple listeners |
| `CommandHandler` / `QueryHandler` / `EventListener` | Handler interfaces |
| `RetryPolicy` | Max attempts + backoff configuration |
| `RetriesQueuedCommand` | Interface for commands with custom retry |
| `ShouldQueue` | Marker: dispatch this command to the queue |
| `QueuedCommandBus` | Decorator: wraps CommandBus for async dispatch |
| `QueueWorker` | Pulls jobs from store, dispatches through CommandBus |
| `QueueStore` | Backend interface (push/pop/fail/createTable) |
| `DatabaseQueueStore` | Persistent queue using `jobs`/`failed_jobs` tables |
| `InMemoryQueueStore` | In-memory queue for testing |
| `QueuedJob` | Job wrapper: payload + metadata (attempts, queue name) |

## Usage

```php
use LombokClarion\Bus\CommandBus;
use LombokClarion\Bus\EventBus;

// Command dispatch
$bus = new CommandBus();
$bus->register(CreateWidget::class, new CreateWidgetHandler($repo));
$bus->dispatch(new CreateWidget('Gadget'));

// Event dispatch (multiple listeners)
$events = new EventBus();
$events->listen(WidgetCreated::class, new SendNotification());
$events->listen(WidgetCreated::class, new UpdateCache());
$events->dispatch(new WidgetCreated($id));

// Queued dispatch
$queuedBus = new QueuedCommandBus($bus, new DatabaseQueueStore($pdo));
$queuedBus->dispatch(new SendEmail($to, $body)); // if SendEmail implements ShouldQueue

// Worker
$worker = new QueueWorker($bus, $store);
$worker->work(queue: 'default', loop: true, sleep: 3);
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
