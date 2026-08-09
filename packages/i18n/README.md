# lombokclarion/i18n

**Translation loader and locale-detection middleware.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/i18n
```

## Namespace

```php
LombokClarion\I18n
```

## What's Inside

| Class | Role |
|-------|------|
| `Translator` | Loads `.php` translation files, resolves `key` → localized string |
| `DetectLocale` | Middleware: reads `Accept-Language` header → sets locale in `RequestContext` |

## Usage

```php
use LombokClarion\I18n\Translator;

$translator = new Translator('resources/lang');
$translator->setLocale('id');

echo $translator->get('welcome'); // from resources/lang/id.php
echo $translator->get('greeting', ['name' => 'Budi']); // "Halo, Budi"
```

### Locale Detection Middleware

```php
$router->group('/app', [DetectLocale::class], function (Router $r) {
    // All routes here auto-detect locale from Accept-Language
});
```

**Supported locales** (24): ar, bn, de, en, es, fa, fr, hi, id, it, ja, ko, ms, nl, pl, pt, ru, sw, th, tr, uk, ur, vi, zh

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
