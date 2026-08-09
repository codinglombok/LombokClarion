# lombokclarion/log

**Structured logging: channels, handlers, formatters, context redaction.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/log
```

## Namespace

```php
LombokClarion\Log
```

## What's Inside

| Class | Role |
|-------|------|
| `Logger` | Interface: `log(LogLevel, string, array)` |
| `ChannelLogger` | Multi-handler logger with channel name |
| `NullLogger` | No-op logger for testing |
| `LogLevel` | Enum: Debug, Info, Notice, Warning, Error, Critical, Alert, Emergency |
| `LogRecord` | Structured record VO (level, message, context, channel, timestamp) |
| `LogHandler` | Handler interface (decides whether to write) |
| `LogFormatter` | Formatter interface (record → string) |
| `LogsConveniently` | Trait: `debug()`, `info()`, `warning()`, `error()` sugar |
| `Redactor` | Redacts sensitive fields (password, token, secret, etc.) |
| `StreamHandler` | File handler with daily rotation + retention |
| `InMemoryHandler` | Testing handler (stores records in array) |
| `LineFormatter` | Human-readable single-line format |
| `JsonFormatter` | JSON structured format |

## Usage

```php
use LombokClarion\Log\ChannelLogger;
use LombokClarion\Log\Handlers\StreamHandler;
use LombokClarion\Log\LogLevel;

$logger = new ChannelLogger([
    new StreamHandler(
        path: 'storage/logs/app.log',
        minimumLevel: LogLevel::Info,
        daily: true,
        retentionDays: 14,
    ),
], channel: 'app');

$logger->log(LogLevel::Info, 'User logged in', ['user_id' => 42]);

// Convenience methods (via LogsConveniently trait on ChannelLogger):
$logger->info('Order placed', ['order_id' => 99]);
$logger->error('Payment failed', ['reason' => 'timeout']);
```

### Redaction

Sensitive fields (`password`, `token`, `secret`, `authorization`, `cookie`) are automatically redacted from log context.

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
