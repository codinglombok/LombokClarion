# Security Policy

## Reporting
Please do NOT open public issues for vulnerabilities. Use GitHub private
vulnerability reporting (Security → Report a vulnerability) on this repository.
You will receive an acknowledgement within 72 hours.

## Scope highlights
- SQL injection: QueryBuilder is bound-parameters-only by construction; any API
  that would accept interpolated values is itself a vulnerability — report it.
- XSS: `{{ }}` must always escape; any bypass outside `{!! !!}` is in scope.
- CSRF/session/token handling in lombokclarion/security.
- `audit:sql` / `audit:security` false negatives are treated as security bugs.
  This is not theoretical: until Stage 8c both SQL engines inspected only the
  call site, so routing a query through a local variable evaded them entirely
  (AUDIT-TRAIL #28). If you find a shape that reaches `PDO::query/prepare/exec`
  with an unbound value and is not reported, that is a reportable bug.

## What the SQL audits do and do not cover
Two engines enforce the same rule and are meant to agree; a finding one reports
and the other does not is a bug in one of them.

- `lombokclarion audit:sql app packages` — tokenizer-based, no dependencies,
  runs anywhere. It has no type inference, so it uses a small intra-file taint
  pass and errs toward reporting.
- `vendor/bin/phpstan analyse` (or `phpstan.phar`, see `phpstan.neon`) — uses
  PHPStan's inferred types, so it can constant-fold and follow variables across
  a function body.

Known limits, stated plainly rather than left for a reader to discover:

- **`QueryBuilder` is exempt** from the raw-SQL rule in both engines. It is the
  one component that assembles SQL by design, and its strings pass through
  `implode()`/`sprintf()` over arrays, which defeats both constant folding and
  token inspection. What stands in for the gate there is `PersistenceTest`
  (identifier rejection, bound-value round-trips, a live injection payload).
- **The taint pass is intra-file and name-based.** SQL built in one method and
  executed in another, or passed through a property, is not tracked.
- **`TrustedDdl::mark()` suppresses a finding at a call site.** It cannot be used
  to launder value-carrying SQL (it rejects anything that is not DDL at runtime),
  but it does mean the marked statement is trusted rather than proven.
- Neither engine attempts taint analysis from HTTP input to query. They enforce
  a structural invariant — values are bound — not provenance.

## Supported versions
| Version | Supported |
|---|---|
| 1.x | ✅ |
