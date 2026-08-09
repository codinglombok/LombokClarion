# lombokclarion/facades

**Opt-in static-access pattern: Facade base + Bus/Event/Hash. Forbidden from `app/Domain/`.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/facades
```

## Namespace

```php
LombokClarion\Facades
```

## What's Inside

| Class | Role |
|-------|------|
| `Facade` | Base class with `__callStatic`, requires explicit `setContainer()` |
| `Bus` | `Bus::dispatch($command)` → CommandBus |
| `Event` | `Event::dispatch($event)` → EventBus |
| `Hash` | `Hash::make($password)`, `Hash::check($password, $hash)` → PasswordHasher |

## Usage

```php
use LombokClarion\Facades\Facade;
use LombokClarion\Facades\Bus;
use LombokClarion\Facades\Hash;

// Explicit opt-in (in bootstrap)
Facade::setContainer($container);

// Then in app code:
Bus::dispatch(new CreateWidget('Gadget'));
$hash = Hash::make('password');
$valid = Hash::check('password', $hash);
```

> **Note:** This package carries `forbidden-layers: ["app/Domain"]`. The domain boundary checker blocks imports of `LombokClarion\Facades\*` from domain code. Facades are for infrastructure/controller code only.

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
