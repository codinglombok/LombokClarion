# LombokClarion — Audit Trail (Bug Found → Fixed → Verified)

Each entry records: **how it was discovered** (test/smoke/inspection), **root cause**,
**fix**, **verification evidence**. Working principle: no bug is merely logged —
every one was fixed and re-verified within the same session.
Final status: **124/124 tests PASS, 0 FAIL**; `audit:sql` clean; `audit:security`
clean; domain boundary OK; HTTP/HTML/compiled-boot smoke tests all green.

## Stage 1 — Core framework

1. **`Binding::$concrete` typed `string|callable`** → PHP fatal: `callable` is illegal
   as a property type. *Found:* first ContainerTest run. *Fix:* `mixed` type +
   `class-string|callable` docblock. *Verified:* 12/12 ContainerTest PASS.

2. **ContainerCompiler cannot see inside factory closures** — dependencies pulled via
   `$c->get()` inside a factory body never enter the compilation graph. *Found:*
   compiled-container test failed to resolve `Test_Logger`. *Design fix (not a
   workaround):* factory dependencies must be explicitly bound / listed in
   `extraRootIds`; the limitation is documented in the compiler docblock — consistent
   with "explicit over magic". *Verified:* test passes with the explicit binding.

3. **Compiler rejects raw `Closure`s** — they cannot be serialized into a static file.
   *Fix:* array-callable contract `[Factory::class,'method']`, a clear compile-time
   error, + a test proving raw Closures are indeed rejected.

4. **`use PDO;`** (non-compound) emitted warnings. *Found:* running `migrate`.
   *Fix:* removed the lines; clean rerun.

5. **`ViewCompiler` failed to recognize `@if (expr)`** (space before the paren).
   *Found:* ViewTest — the `@if` branch never compiled. *Fix:* directive lookup via
   `@name\s*\(` regex + a balanced-paren walker (pure regex cannot handle nested
   parens such as `@if (count($x) > 0)`). *Verified:* 5/5 ViewTest PASS.

6. **Syntax error in the compiled layout**: `'@endsection'` was replaced with a string
   containing `\$this` inside single quotes → a literal backslash leaked into the
   compiled PHP. *Found:* the `@extends/@section/@yield` test failed with a parse
   error in the cache file. *Fix:* removed the escape; rerun PASS.

7. **`Test_InMemoryWidgetRepository::find()` signature clash** with the parent's
   protected `find(): ?object`. *Found:* fatal while running TestingPackageTest.
   *Fix:* interface method renamed `findName()`.

8. **`FakeCommandBus`/`FakeEventBus` could not extend `final` classes.**
   *Found:* the same fatal run. *Fix:* removed `final` from CommandBus/EventBus
   (required by the §9 Testing contract). *Verified:* 5/5 TestingPackageTest PASS.

9. **`optimize` initially compiled the console-augmented container** (raw closure
   bindings) → compilation failure. *Fix:* OptimizeCommand rebuilds a pure container
   from `services.php`. *Verified:* `optimize` writes both files.

10. **A PDO singleton cannot be frozen into a static file** (runtime connection).
    *Fix:* `$externallyProvided` compiler parameter — those ids are skipped; the
    adapter creates a fresh PDO per request then calls
    `CompiledContainer::instance(PDO::class, $pdo)` (also satisfying §5: no pooled-
    connection assumption). *Verified:* compiled-boot smoke returns 200 OK.

11. **Controllers/handlers/middleware referenced only from the route table or inside
    factory `register()` calls were never compiled** → NotFound at compiled boot.
    *Found:* the compiled-boot smoke failed repeatedly (WidgetController, then
    CreateWidgetHandler/ListWidgetsHandler, then SecurityHeaders/ValidateCsrf).
    *Fix:* all listed in `extraRootIds` in the optimize wiring. *Verified:* compiled
    boot serves `GET /api/widgets` 200 with real data.

12. **`bin/check-domain-boundary.php` false positives** on COMMENTS mentioning
    `LombokClarion\...`. *Found:* the first checker run flagged the
    CreateWidgetHandler docblock. *Fix:* strip comments via `token_get_all` before
    scanning. *Double verification:* (a) passes on clean code; (b) **proven to catch**
    a deliberately planted `use LombokClarion\Http\Request;` violation (exit 1),
    file then restored.

## Stage 2 — LombokCSS starter kit

13. **The real library**: LombokCSS's actual vocabulary
    is `.btn/.card/...` + `data-style`/`data-theme` — **not** `lc-*`/`data-variant`/
    `data-elevation`; the `quiet-editorial` theme does not exist upstream.
    *Found:* inspecting the downloaded `dist/lombok.css`. *Decision:* follow the REAL
    vocabulary (the true intent of the §8 rule); author `quiet-editorial` as an
    extension following the official token-remap pattern of `src/themes.css`.
    *Verified:* all 4 themes render end-to-end (correct `data-style` in the HTML).

14. **REAL KERNEL BUG: global middleware never ran on 404/405** — `handle()` returned
    404 before the pipeline was built, so `StaticAssetsMiddleware` never executed
    (assets 404'd) and SecurityHeaders were missing from error responses.
    *Found:* asset smoke test `GET /assets/...` → 404. *Fix:* Kernel restructured —
    global middleware wraps the routing decision (`route()` became the pipeline core).
    *Verified:* assets 200 + `Cache-Control: immutable`; permanent regression test
    "global middleware runs even for unmatched (404) paths".

15. **Theme test failure**: the minifier strips quotes from attribute selectors
    (`data-style=resonant-stark`). *Fix:* the test matches both forms.

16. **Path traversal `/assets/../../...`** — explicitly tested; rejected 404 via a
    `realpath` prefix check (not string sanitizing).

## Stage 3 — Tenancy, Queue, Joins

17. **`leftJoin` test failure**: duplicate `name` columns across tables in SQLite
    results overwrote each other. *Fix:* assertion switched to the empty category's
    `id` (the point of the test = unmatched rows still appear). QueryBuilder has no
    column aliases yet — limitation recorded, not hidden.

18. **`where()/select()/orderBy()` lacked `table.column` support** required by joins.
    *Fix:* `qualifyColumn()` — both segments still pass `Identifier::validate()`
    (injection surface remains zero; the identifier-injection test still PASSES).

## Stage 4 — EagerLoader, ActiveRecord, Facades

19. AR mass assignment: columns outside `$fillable` are **structurally dropped**;
    empty `$fillable` → exception (impossible to "forget"). Both tested.

## Stage 5 — TokenScanner, --explain, Plugin, wiring

20. **Stale ConsoleTest assertion** after the audit message changed ("raw SQL built
    via..." → "SQL query/prepare built via..."). *Fix:* assert on the stable phrase.

21. **Wrong EXPLAIN format detection**: modern SQLite writes `SCAN big`, not
    `SCAN TABLE big`. *Found:* the --explain test failed; diagnosed by dumping the
    real plan. *Fix:* pattern covers `SCAN (TABLE )?tbl` + Postgres `Seq Scan`, and
    does NOT flag `USING INDEX`. *Verified:* test PASS.

22. **WIRING BUG: `WorkCommand` was created but never registered** in
    `bootstrap/console.php`. *Found:* auditing the command list (`work` was absent).
    *Fix:* DatabaseQueueStore+QueueWorker wiring + registration. *Verified:*
    `php bin/lombokclarion work` → "Processed 0 job(s)."

## Stage 6 — LombokCharts

23. **Charting integration**: real library vendored (UMD min, 58KB, 13 marks); the
    `/dashboard` page + route + nav + `optimize` asset wiring. Anticipated & tested
    risk: **script breakout** — chart data is embedded inside `<script>` via
    `{!! !!}`; safe because JSON_HEX_TAG/AMP/APOS/QUOT (a `</script>` label becomes
    `\u003C...`), wrapped in `Safe::mark()` so the XSS audit stays clean
    (views audit: no issues). *Verified:* smoke — dashboard 200, hashed JS served as
    `application/javascript` 58,099 bytes, a malicious label never reaches HTML raw;
    +2 permanent tests. Total 124 PASS.

## Stage 7 — Deployment tooling

24. **Compose YAML bug caught by automated validation**: the flow mapping
    `build: { context: ., target: worker }` is invalid per the YAML parser.
    *Found:* `yaml.safe_load` at the verification gate (not on a user's deploy!).
    *Fix:* block style. *Verified:* both YAML files (compose + CI workflow) parse;
    suite still 124 PASS.

25. **Workflow YAML bug caught by validation (again)**: GitHub Actions
    `${{ secrets... }}` is invalid inside a YAML flow mapping (`env: { X: ${{...}} }`).
    *Found:* the `yaml.safe_load` gate on npm-publish.yml & pages.yml before release.
    *Fix:* block style. *Verified:* all three workflows parse; suite still 124 PASS.

## Stage 8 — Gap closure

26. **PHPStan rules package + DB-roles template**: `packages/phpstan-rules`
    (NoRawSqlValuesRule, DomainBoundaryRule, extension.neon) and
    `deploy/db-roles.sql` close the last two actionable spec gaps. *Verification:*
    `php -l` on both rule classes, composer.json JSON-parsed, full suite 124 PASS,
    all audits clean. Honest limit recorded: PHPStan binary unavailable offline, so
    rule execution is deferred to an online environment; equivalent behavior is
    already test-covered via TokenScanner and the boundary checker.

## "No bug" evidence (operational definition)

"No bug" here means: **no known failures** across the entire tested surface — not a
claim of absolute correctness (no software can guarantee that). Verified surface:

- 124 automated unit/integration tests: 0 failures (run `php tests/run-all.php`).
- End-to-end smokes recorded during the build: JSON API (200/419/201/422),
  HTML flow (CSRF cookie → form → 303 → escaped output; a `<script>` payload proven
  to become `&lt;script&gt;`), compiled-container boot serving requests, 4 themes
  rendering, immutable assets + traversal rejected, `work` running.
- Quality gates: `audit:sql app --explain` clean; `audit:security` clean;
  `check-domain-boundary` OK (and proven to have teeth, see #12).
- SQL injection tested directly with a `Robert'); DROP TABLE...--` payload → table intact.
- All generated artifacts (`storage/*.compiled.php`, sqlite, assets) are excluded
  from the release package; regenerate via `migrate` + `optimize`.
