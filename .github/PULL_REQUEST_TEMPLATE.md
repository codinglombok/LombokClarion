## What & why

## Checklist
- [ ] `php tests/run-all.php` green (new code has tests; bug fixes have regression tests)
- [ ] `php bin/check-domain-boundary.php` OK
- [ ] `php bin/lombokclarion audit:sql app --explain` & `audit:security` clean
- [ ] No new runtime deps; strict_types in new files; explicit registration only
