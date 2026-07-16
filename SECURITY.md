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

## Supported versions
| Version | Supported |
|---|---|
| 1.x | ✅ |
