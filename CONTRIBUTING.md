# Contributing

## Ground rules (from the design constitution)
1. **Explicit over magic** — no auto-discovery, no facades in core, no hidden globals.
   Every binding/route/command/migration must be readable in one bootstrap file.
2. **Safe by construction** — if an unsafe path exists in an API you add, that is a
   design bug, not a documentation task.
3. `app/Domain/**` may never import `LombokClarion\*` (CI-enforced).

## Workflow
```bash
php tests/run-all.php                  # must be green before and after your change
php bin/check-domain-boundary.php
php bin/lombokclarion audit:sql app --explain
php bin/lombokclarion audit:security
```
- Tests are written alongside code; a bug fix ships with a regression test in the
  same PR (see docs/AUDIT-TRAIL.md for the standard).
- PHP 8.3+, `declare(strict_types=1)` in every file; no new runtime dependencies
  without discussion (the framework is deliberately zero-dependency at request time).
- Vendored assets (resources/lombokcss, resources/lombokcharts) are refreshed via
  `npm run assets:update`, never edited by hand (except quiet-editorial.css, which
  is ours).
