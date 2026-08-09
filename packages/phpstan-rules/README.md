# lombokclarion/phpstan-rules

**PHPStan extension: SQL injection detection + domain boundary enforcement.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require --dev lombokclarion/phpstan-rules
```

Include in `phpstan.neon`:

```neon
includes:
    - vendor/lombokclarion/phpstan-rules/extension.neon
```

## Namespace

```php
LombokClarion\PHPStanRules
```

## What's Inside

| Class | Rule |
|-------|------|
| `NoRawSqlValuesRule` | Flags string concatenation and variable interpolation inside SQL query methods |
| `DomainBoundaryRule` | Flags `LombokClarion\ActiveRecord` and `LombokClarion\Facades` imports in `app/Domain/` |

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
