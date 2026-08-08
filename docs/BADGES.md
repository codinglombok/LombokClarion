# Badges

Ready-to-paste badge markdown for the three projects, grouped by category, with
an accuracy note on each group. All use [shields.io](https://shields.io) so they
auto-update. Verified against the live registries on 2026-07-19:

- npm `lombokclarion` = 1.0.0 (asset tooling), `lombokcss` = 0.1.0, `lombok-charts` = 0.1.1 — **all published, badges resolve now.**
- Packagist `lombokclarion/*` = **not published yet** → Packagist badges 404 until the split mirrors are registered (see RELEASE-PLAYBOOK.md §3).
- GitHub repo confirmed: `codinglombok/LombokClarion`, `codinglombok/LombokCSS`. LombokCharts repo name assumed `codinglombok/LombokCharts` — **confirm before using its GitHub badges.**

A note on the design: keep a README to ~5–8 badges. More than that reads as
noise. Each project below has a **Recommended set** (use these) followed by the
full menu to pick from.

---

## LombokClarion  (PHP framework · `codinglombok/LombokClarion`)

### Recommended set (all accurate today)

```markdown
[![CI](https://img.shields.io/github/actions/workflow/status/codinglombok/LombokClarion/ci.yml?branch=main&label=CI&logo=github)](https://github.com/codinglombok/LombokClarion/actions/workflows/ci.yml)
[![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-2a6f97)](https://phpstan.org/)
[![Tests](https://img.shields.io/badge/tests-282%20passing-brightgreen)](https://github.com/codinglombok/LombokClarion/actions)
[![License](https://img.shields.io/github/license/codinglombok/LombokClarion?color=blue)](LICENSE)
[![Release](https://img.shields.io/github/v/release/codinglombok/LombokClarion?sort=semver)](https://github.com/codinglombok/LombokClarion/releases)
```

### Build & quality

```markdown
[![CI](https://img.shields.io/github/actions/workflow/status/codinglombok/LombokClarion/ci.yml?branch=main&label=CI&logo=github)](https://github.com/codinglombok/LombokClarion/actions/workflows/ci.yml)
[![Deploy docs](https://img.shields.io/github/actions/workflow/status/codinglombok/LombokClarion/pages.yml?branch=main&label=docs)](https://codinglombok.github.io/LombokClarion/)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-2a6f97)](https://phpstan.org/)
[![Tests](https://img.shields.io/badge/tests-282%20passing-brightgreen)](#)
[![Code size](https://img.shields.io/github/languages/code-size/codinglombok/LombokClarion)](#)
```

### Release & version

```markdown
[![Release](https://img.shields.io/github/v/release/codinglombok/LombokClarion?sort=semver)](https://github.com/codinglombok/LombokClarion/releases)
[![Tag](https://img.shields.io/github/v/tag/codinglombok/LombokClarion?sort=semver)](https://github.com/codinglombok/LombokClarion/tags)
[![Last commit](https://img.shields.io/github/last-commit/codinglombok/LombokClarion)](https://github.com/codinglombok/LombokClarion/commits)
```

### Packagist  — **pending publish; will 404 until the mirrors are registered**

Packagist badges are per-package. Pick a representative package (e.g. `http`)
or add one per package. Replace `http` as needed:

```markdown
[![Packagist](https://img.shields.io/packagist/v/lombokclarion/http)](https://packagist.org/packages/lombokclarion/http)
[![Downloads](https://img.shields.io/packagist/dt/lombokclarion/http)](https://packagist.org/packages/lombokclarion/http)
[![PHP from Packagist](https://img.shields.io/packagist/php-v/lombokclarion/http)](https://packagist.org/packages/lombokclarion/http)
[![Packagist license](https://img.shields.io/packagist/l/lombokclarion/http)](https://packagist.org/packages/lombokclarion/http)
```

### Identity / informational (static — always accurate, edit by hand)

```markdown
[![License](https://img.shields.io/badge/license-Apache--2.0-blue)](LICENSE)
[![Style](https://img.shields.io/badge/style-explicit%20over%20magic-0a9396)](#)
[![Design](https://img.shields.io/badge/design-edge%20%2F%20serverless--first-005f73)](#)
[![No magic](https://img.shields.io/badge/facades-none%20by%20default-9b2226)](#)
[![Packages](https://img.shields.io/badge/monorepo-18%20packages-6a4c93)](#)
[![Cold start](https://img.shields.io/badge/cold%20start-~3.4ms-brightgreen)](#)
```

### Repo social

```markdown
[![Stars](https://img.shields.io/github/stars/codinglombok/LombokClarion?style=social)](https://github.com/codinglombok/LombokClarion/stargazers)
[![Forks](https://img.shields.io/github/forks/codinglombok/LombokClarion?style=social)](https://github.com/codinglombok/LombokClarion/network/members)
[![Issues](https://img.shields.io/github/issues/codinglombok/LombokClarion)](https://github.com/codinglombok/LombokClarion/issues)
```

> Note: the `github/license` badge reads the LICENSE file and now shows
> **Apache-2.0**. If you keep the static identity badge too, make sure it also
> says Apache-2.0 (not MIT) so the two don't disagree.

---

## LombokCSS  (CSS framework · `codinglombok/LombokCSS` · npm `lombokcss`)

### Recommended set

```markdown
[![npm](https://img.shields.io/npm/v/lombokcss?logo=npm)](https://www.npmjs.com/package/lombokcss)
[![minzip](https://img.shields.io/bundlephobia/minzip/lombokcss)](https://bundlephobia.com/package/lombokcss)
[![CI](https://img.shields.io/github/actions/workflow/status/codinglombok/LombokCSS/ci.yml?branch=main&label=CI&logo=github)](https://github.com/codinglombok/LombokCSS/actions)
[![License](https://img.shields.io/npm/l/lombokcss?color=blue)](https://github.com/codinglombok/LombokCSS/blob/main/LICENSE)
[![Release](https://img.shields.io/github/v/release/codinglombok/LombokCSS?sort=semver)](https://github.com/codinglombok/LombokCSS/releases)
```

### Registry (npm — published, v0.1.0)

```markdown
[![npm version](https://img.shields.io/npm/v/lombokcss?logo=npm)](https://www.npmjs.com/package/lombokcss)
[![npm downloads](https://img.shields.io/npm/dm/lombokcss)](https://www.npmjs.com/package/lombokcss)
[![min size](https://img.shields.io/bundlephobia/min/lombokcss)](https://bundlephobia.com/package/lombokcss)
[![minzip size](https://img.shields.io/bundlephobia/minzip/lombokcss)](https://bundlephobia.com/package/lombokcss)
[![jsDelivr](https://img.shields.io/jsdelivr/npm/hm/lombokcss?label=jsDelivr%20hits)](https://www.jsdelivr.com/package/npm/lombokcss)
```

### Informational (static)

```markdown
[![CSS only](https://img.shields.io/badge/dependencies-zero-brightgreen)](#)
[![Themes](https://img.shields.io/badge/design%20styles-5-6a4c93)](#)
[![Tokens](https://img.shields.io/badge/tokens-%2D%2Dlc--*-005f73)](#)
[![License](https://img.shields.io/badge/license-MIT-blue)](#)
```

---

## LombokCharts  (JS charts · npm `lombok-charts` · repo name to confirm)

### Recommended set

```markdown
[![npm](https://img.shields.io/npm/v/lombok-charts?logo=npm)](https://www.npmjs.com/package/lombok-charts)
[![minzip](https://img.shields.io/bundlephobia/minzip/lombok-charts)](https://bundlephobia.com/package/lombok-charts)
[![tree-shakeable](https://img.shields.io/badge/tree--shakeable-yes-brightgreen)](#)
[![deps](https://img.shields.io/badge/dependencies-zero-brightgreen)](#)
[![License](https://img.shields.io/npm/l/lombok-charts?color=blue)](#)
```

### Registry (npm — published, v0.1.1, Apache-2.0)

```markdown
[![npm version](https://img.shields.io/npm/v/lombok-charts?logo=npm)](https://www.npmjs.com/package/lombok-charts)
[![npm downloads](https://img.shields.io/npm/dm/lombok-charts)](https://www.npmjs.com/package/lombok-charts)
[![min size](https://img.shields.io/bundlephobia/min/lombok-charts)](https://bundlephobia.com/package/lombok-charts)
[![minzip size](https://img.shields.io/bundlephobia/minzip/lombok-charts)](https://bundlephobia.com/package/lombok-charts)
[![types](https://img.shields.io/npm/types/lombok-charts)](https://www.npmjs.com/package/lombok-charts)
[![jsDelivr](https://img.shields.io/jsdelivr/npm/hm/lombok-charts?label=jsDelivr)](https://www.jsdelivr.com/package/npm/lombok-charts)
```

### Informational (static)

```markdown
[![Zero deps](https://img.shields.io/badge/dependencies-zero-brightgreen)](#)
[![Grammar of graphics](https://img.shields.io/badge/api-grammar%20of%20graphics-6a4c93)](#)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue)](#)
```

> GitHub-based badges (CI / release / stars) for LombokCharts need the exact repo
> path. If the repo is `codinglombok/LombokCharts`, reuse the LombokClarion
> GitHub-badge patterns with that path substituted.

---

## Style variants

Append a query to any shields URL to restyle a whole row consistently:

- `?style=flat` (default), `?style=flat-square`, `?style=for-the-badge`, `?style=plastic`
- `?logo=php|npm|github|packagist&logoColor=white`
- `?color=blue|brightgreen|555|<hex>` and `?labelColor=<hex>`
- `?cacheSeconds=3600` to reduce shields refresh load

Example, one consistent `for-the-badge` row:

```markdown
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=for-the-badge&logo=php&logoColor=white)
![License](https://img.shields.io/github/license/codinglombok/LombokClarion?style=for-the-badge&color=blue)
![Release](https://img.shields.io/github/v/release/codinglombok/LombokClarion?style=for-the-badge&sort=semver)
```

## One accuracy caveat to resolve

The npm `lombokclarion` package is published as **MIT** (its `package.json` still
says MIT), but the framework is now **Apache-2.0**. Pick one and make them agree:
re-publish the npm package with `"license": "Apache-2.0"` (this checkpoint already
flipped the local `package.json`), or leave the npm asset-tooling package MIT and
be explicit that only the *asset tooling* is MIT while the framework is Apache-2.0.
Either is fine — just don't let the badge and the LICENSE disagree.
