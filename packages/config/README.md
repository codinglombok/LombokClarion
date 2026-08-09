# lombokclarion/config

**Schema-driven config: env resolved once at compile time, typed readonly classes at runtime.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/config
```

## Namespace

```php
LombokClarion\Config
```

## What's Inside

| Class | Role |
|-------|------|
| `ConfigCompiler` | Reads a schema definition + `.env` → generates a readonly PHP class file |
| `ConfigException` | Thrown on missing required env vars or schema violations |

## Usage

Define a schema in `config/config.schema.php`:

```php
return [
    'app' => [
        'name' => ['type' => 'string', 'env' => 'APP_NAME', 'default' => 'LombokClarion'],
        'debug' => ['type' => 'bool', 'env' => 'APP_DEBUG', 'default' => false],
        'key' => ['type' => 'string', 'env' => 'APP_KEY', 'required' => true],
    ],
    'database' => [
        'driver' => ['type' => 'string', 'env' => 'DB_DRIVER', 'default' => 'sqlite'],
    ],
];
```

Compile:

```php
use LombokClarion\Config\ConfigCompiler;

$compiler = new ConfigCompiler();
$compiler->compile(
    schema: require 'config/config.schema.php',
    outputPath: 'storage/config.compiled.php',
);

// At runtime — typed access, no env lookup:
$config = require 'storage/config.compiled.php';
$config->app->name;   // string
$config->app->debug;  // bool
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
