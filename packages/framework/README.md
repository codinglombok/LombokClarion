# lombokclarion/framework

**Metapackage — one `composer require` installs the full LombokClarion runtime stack.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/framework
```

This pulls all 16 runtime packages:

| Package | Description |
|---------|-------------|
| `container` | Explicit DI + AOT compilation |
| `config` | Schema-driven typed config |
| `http` | Request/Response/Middleware |
| `routing` | Router + 3 runtime adapters |
| `bus` | Command/Query/Event + Queue |
| `persistence` | QueryBuilder, Schema, Migrations, Seeding |
| `validation` | Rules + 24-language messages |
| `security` | Hashing, CSRF, encryption, rate-limit |
| `auth` | HMAC tokens, RBAC, Gate/Policy |
| `view` | Blade-like templates, themes, assets |
| `console` | CLI kernel + 12 commands |
| `log` | Structured logging, channels, redaction |
| `i18n` | Translation + locale detection |
| `storage` | Local filesystem storage |
| `active-record` | Opt-in ActiveRecord pattern |
| `facades` | Opt-in static access pattern |
| `laravel-flavor` | Laravel-familiar helpers |

Dev-only packages (add to `require-dev`):

```bash
composer require --dev lombokclarion/testing lombokclarion/phpstan-rules
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
