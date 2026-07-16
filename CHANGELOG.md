# Changelog

## 1.0.0
Built in 7 staged passes 

- **Stage 1** — Core: Container (+AOT compiler → zero-reflection CompiledContainer),
  Http, Routing (+Fpm/Function/Swoole adapters), Bus, Config compiler, Persistence
  (bound-params-only QueryBuilder, migrations manifest), View (auto-escaping),
  Console (migrate/optimize/audits), Security (Argon2id/CSRF/RateLimit/Headers/
  AES-GCM/FormRequest), Testing (fakes, ColdStartTest), example Widget app,
  domain-boundary checker. 67 tests.
- **Stage 2** — LombokCSS starter kit: vendored upstream dist, quiet-editorial theme
  extension, Theme/AssetPublisher/AssetManifest/StaticAssetsMiddleware, HTML pages.
  Fixed real Kernel bug: global middleware now wraps routing (runs on 404). 77 tests.
- **Stage 3** — Multi-tenancy (§11), Queue/Worker (§12), QueryBuilder joins/groupBy. 97 tests.
- **Stage 4** — EagerLoader (N+1), optional packages active-record & facades
  (forbidden-layers enforced). 110 tests.
- **Stage 5** — TokenScanner (tokenizer-based audit:sql), --explain, Plugin system,
  work-command wiring fix. 122 tests.
- **Stage 6** — LombokCharts dashboard (script-breakout-safe JSON embedding). 124 tests.
- **Stage 8** — Gap closure: `lombokclarion/phpstan-rules` extension package
  (raw-SQL + domain-boundary AST rules) and `deploy/db-roles.sql` least-privilege
  Postgres template. 124 tests.
- **Stage 7** — Deployment tooling: Dockerfile (base/worker/cloudrun), compose,
  nginx, systemd, GitHub Actions CI, deployment guide for GitHub/VPS/Docker/GCP/AWS/DO.
