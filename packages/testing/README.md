# lombokclarion/testing

**Test doubles, HTTP/Console test cases, cold-start budget enforcement.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require --dev lombokclarion/testing
```

## Namespace

```php
LombokClarion\Testing
```

## What's Inside

| Class | Role |
|-------|------|
| `HttpTestCase` | Boots real container + explicit `override()` for integration tests |
| `ConsoleTestCase` | Runs CLI commands and asserts output/exit code |
| `BenchmarkTestCase` | Measures execution time against a budget |
| `ColdStartTest` | Fails when cold-start boot exceeds budget (~5ms) |
| `FakeCommandBus` | Records dispatched commands without handling them |
| `FakeEventBus` | Records dispatched events without notifying listeners |
| `InMemoryRepository` | Generic in-memory repository for domain tests |

## Usage

```php
use LombokClarion\Testing\HttpTestCase;

class WidgetTest extends HttpTestCase {
    public function testCreateWidget(): void {
        $this->override(WidgetRepository::class, new InMemoryRepository());
        $response = $this->post('/api/widgets', ['name' => 'Test']);
        $this->assertStatus($response, 201);
    }
}

// Fake bus (unit test)
$bus = new FakeCommandBus();
$bus->dispatch(new CreateWidget('Gadget'));
$bus->assertDispatched(CreateWidget::class);

// Cold-start test (in CI)
$test = new ColdStartTest(budgetMs: 5.0);
$test->run(); // fails if boot exceeds 5ms
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
