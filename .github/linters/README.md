# Linter configs (super-linter)

super-linter reads its per-linter configs from this directory
(`LINTER_RULES_PATH: .github/linters`).

| File | Linter | Notes |
|---|---|---|
| `.yaml-lint.yml` | yamllint (VALIDATE_YAML) | `truthy.check-keys: false` keeps `on:` workflow keys valid; cosmetic style rules disabled. |
| `.markdown-lint.yml` | markdownlint (VALIDATE_MARKDOWN) | Filename MUST be `.markdown-lint.yml` — a `.yaml` extension is silently ignored by super-linter. |

JS/CSS in this repo are minified build artifacts under `public/assets/` and
`resources/`; both are excluded via `FILTER_REGEX_EXCLUDE` in
`.github/workflows/linter.yml`, so no eslint/stylelint config is needed.
