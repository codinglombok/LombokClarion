# lombokclarion/table-sheet

> **Vendored package.** This is the PHP port of [LombokTableSheet](https://github.com/codinglombok/LombokTableSheet)
> (`ports/php`, tag `v1.0.1`), copied verbatim into the LombokClarion monorepo per project
> directive. It is the **fallback/cadangan** consumption path — the **primary** path for
> most apps is the external sibling library `lomboktablesheet` on npm
> (github.com/codinglombok/LombokTableSheet). Use this vendored package when you want
> TableSheet's data/export engine available as a native Composer dependency without a
> separate npm/JS toolchain, or when the upstream repo is unavailable.
>
> Namespace is `Lombok\TableSheet\*` (not `LombokClarion\*`) — preserved as-is from the
> upstream port rather than renamed, so code stays portable between this vendored copy
> and the standalone repo.

PHP 8.1+ port of the LombokTableSheet core. Structural translation of the TypeScript
core (see the upstream repo's ARCHITECTURE.md §2.2 for why the TS core was written to
make this mechanical), covering:

- **Core data model** — `Cell`, `Sheet`, `Workbook` (`src/Core/Model.php`)
- **Formula engine** — tokenizer → Pratt parser → AST → evaluator, no `eval()`
  (`src/Core/Formula.php`)
- **Split/merge** (`src/Core/SplitMerge.php`)
- **CSV codec** (`src/Formats/CsvCodec.php`) — uses PHP's native `fgetcsv`/`fputcsv`
  under the hood for RFC-4180 correctness
- **JSON + Markdown codecs** (`src/Formats/JsonCodec.php`)

**Not yet ported** (matches the TS roadmap stage this was ported at): the DOM/editable
Sheet adapters (PHP has no DOM to render into — a PHP consumer would use this as a
data-layer/export engine, e.g. inside a Laravel/Symfony app generating XLSX/CSV exports),
XLSX codec, i18n formatting layer, templates. These are naturally suited to a future
stage 6b as PHP-specific needs (e.g. integrating with `intl` ext for i18n) become clear.

## Install

Once published to Packagist:
```bash
composer require codinglombok/lomboktablesheet
```

## Usage

```php
use Lombok\TableSheet\Core\Workbook;
use Lombok\TableSheet\Formats\CsvCodec;
use Lombok\TableSheet\Formats\JsonCodec;
use Lombok\TableSheet\Core\SplitMerge;

$result = CsvCodec::decode("name,age\nAlice,30\nBob,25\n");
echo JsonCodec::encode($result->workbook);

[$top, $bottom] = SplitMerge::splitByRows($result->workbook, 'Sheet1', 1);
```

### Formulas

```php
use Lombok\TableSheet\Core\Sheet;
use Lombok\TableSheet\Core\FormulaEngine;

$sheet = new Sheet('S1');
$sheet->setValue(0, 0, 10);
$sheet->setValue(0, 1, 20);

$ast = FormulaEngine::parse('=SUM(A1:B1)*2');
$result = FormulaEngine::evaluate($ast, FormulaEngine::sheetResolver($sheet));
// $result === 60, or a FormulaError instance (#DIV/0!, #CIRC!, #NAME?, #VALUE!)
```

## Development

This environment doesn't have network access to Packagist, so tests run against a
small local classmap bootstrap (`autoload.php`) rather than `vendor/autoload.php`.
Once Packagist is reachable, `composer install` + `vendor/bin/phpunit` is the
normal path; the classmap autoload strategy in `composer.json` works the same way.

```bash
phpunit --testdox tests    # or: vendor/bin/phpunit, once composer install works
```

27 tests, 59 assertions, covering the same behavioral scenarios as the TypeScript
core's test suite in the upstream repo — including a direct parity check confirming
byte-identical CSV/JSON output and identical formula evaluation results between the
TS and PHP implementations for the same inputs.

## License

Apache 2.0 — see [LICENSE](LICENSE) (vendored alongside this package).
