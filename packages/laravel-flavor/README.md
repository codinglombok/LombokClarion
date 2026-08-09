# lombokclarion/laravel-flavor

**Laravel-familiar preset: Auth/DB static access, `config()` and `env()` helpers.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/laravel-flavor
```

## Namespace

```php
LombokClarion\LaravelFlavor
```

## What's Inside

| Class | Role |
|-------|------|
| `LaravelFlavor` | Boot helper: wires facades, registers helpers, binds Auth/DB |
| `Auth` | Static auth: `Auth::user()`, `Auth::check()`, `Auth::login()` |
| `DB` | Static query: `DB::table('x')`, `DB::select()`, `DB::insert()` |

## Usage

```php
use LombokClarion\LaravelFlavor\LaravelFlavor;

// Bootstrap (once)
LaravelFlavor::boot($container);

// Then use familiar patterns:
use LombokClarion\LaravelFlavor\Auth;
use LombokClarion\LaravelFlavor\DB;

$user = Auth::user();
if (Auth::check()) { /* ... */ }

$widgets = DB::table('widgets')
    ->where('status', '=', 'active')
    ->get();
```

> **Note:** This package is for teams migrating from Laravel. It wraps the same underlying services (AuthManager, QueryBuilder) with static access. The domain layer remains framework-free.

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
