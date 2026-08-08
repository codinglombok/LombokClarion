# LombokClarion — Audit Trail (Bug Found → Fixed → Verified)

Each entry records: **how it was discovered** (test/smoke/inspection), **root cause**,
**fix**, **verification evidence**. Working principle: no bug is merely logged —
every one was fixed and re-verified within the same session.
Final status: **265/265 tests PASS, 0 FAIL**; `audit:sql` clean; `audit:security`
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

13. **Master-prompt assumptions vs. the real library**: LombokCSS's actual vocabulary
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

## Stage 8b — i18n

27. **Internationalization package**: Translator + DetectLocale + en/id catalogs.
    Design choice consistent with the constitution: missing translations are never
    silent — recorded and assertable in CI (`assertNoMissingKeys`), and a catalog
    parity test guards key drift between locales. *Verified:* 7 new tests, suite
    124 → **131/131 PASS**, all audits clean.

## Stage 8c — running the gates that had never been run

Everything below was found by *executing* two gates that had only ever been
lint-checked or assumed. None of it was visible from reading the code; all of it
had been sitting under a green build.

28. **Both SQL audits had a one-line bypass** (severity: high — a false negative in
    a security audit is a security bug by this project's own definition).
    *Found:* running `phpstan.phar` against the rules for the first time, then
    testing the equivalent shapes against `audit:sql`.
    *Cause:* both engines only inspected the **call site**. Assigning the SQL to a
    variable first hid it completely:
    `$sql = "... $id"; $pdo->query($sql);` — reported by neither engine, while the
    identical inline form was reported by both.
    *Fix:* `NoRawSqlValuesRule` now asks PHPStan for the argument's inferred type
    instead of matching its syntactic shape — anything that does not fold to a
    constant string, and is not built from validated identifiers, is a finding.
    `TokenScanner` has no type inference, so it gained a small intra-file taint
    pass instead (a variable assigned SQL built with a variable is tainted).
    *Verified:* all three previously-invisible shapes now reported by both engines;
    7 regression tests pin each shape (`TokenScannerTest`).

29. **`audit:sql` never scanned the framework itself.** *Cause:* it defaults to
    `app/`, and CI passed only `app`. Every claim of "audit:sql clean" therefore
    said nothing about `packages/` — where all the framework's SQL lives.
    *Fix:* CI now scans `app packages`; added `--exclude=PATH` for the one file
    that legitimately cannot be proven safe by either engine.

30. **Precision, in the other direction.** The rule flagged 8 sites in `packages/`,
    all false positives: literal-only concatenation (`'INSERT … ' . 'VALUES (?, ?)'`)
    and `Identifier::quote()` results. Left as-is, the first `vendor/bin/phpstan
    analyse` a user ran would have been red — and a gate that is always red gets
    switched off. Constant-string folding plus a sanitizer allow-list cleared all 8.
    *Related:* `AuditSqlCommand` was itself interpolating a table name with manual
    quoting — the auditor violating the rule it enforces. Now routed through
    `Identifier::quote()`, called inline at each site so **both** engines accept it;
    a site only one gate accepts is precisely the disagreement this command exists
    to prevent.

31. **The skeleton was not installable.** *Found:* by finally running
    `composer install` — which turned out never to have needed Packagist at all,
    since every dependency is a `path` repository. The earlier "no network" caveat
    was wrong, and it had been inherited unexamined across sessions.
    *Cause:* `composer.json` listed 10 packages; the sandbox `autoload.php` shim
    mapped 13. `active-record`, `facades` and `i18n` existed only in the shim, so a
    real deployment would have fatalled on `Model`, `Facade` or `Translator`.
    *Fix:* the three packages added to `require-dev` (the app itself does not use
    them — they are opt-in by design — but the tests do); `phpstan-rules` wired via
    `autoload-dev` so the analysis step does not drag Packagist into an otherwise
    fully-local graph. *Verified:* clean `composer install`, then the full suite run
    against `vendor/autoload.php` with the shim moved aside — 144/144. CI now runs
    the suite both ways, so a package missing from `composer.json` can never hide
    behind the shim again.

32. **`new static()` rested on an unwritten contract.** PHPStan's own level-0 check
    flagged `Model::create()`/hydration: any subclass adding a required constructor
    argument would fatal at runtime. *Fix:* `@phpstan-consistent-constructor` on
    `Model` — turning an assumption into a checked contract rather than silencing
    the warning.

33. **`TrustedDdl`, and why it has teeth.** DDL cannot bind identifiers, so schema
    statements must be string-built; they needed an escape hatch to pass the now-
    stricter rule. `TrustedDdl::mark()` mirrors `View\Safe::mark()`, with one forced
    difference: it returns a bare `string`, because `PDO::exec()` rejects a
    `Stringable` under `strict_types=1`. That alone would have made it a universal
    "silence the audit" bypass, so it is narrowed at runtime — anything that is not
    DDL is rejected outright. *Verified:* 6 tests, including proof that a
    value-carrying `SELECT` and all three DML verbs cannot be laundered through it.

## Stage 9 — Auth + RBAC (`lombokclarion/auth`)

Built on the existing PasswordHasher and RequestContext, as specified — no
static `Auth::user()`, no attribute scanning. Wiring it into a running request
is what exposed #34-37; each was invisible until something actually used the
seams these packages had always exposed.

34. **Header case mismatch, caught by a test.** The first `Authenticate` test
    supplied an `Authorization` header and got 401. *Cause:* the framework stores
    header keys lowercased (`Request::fromGlobals` normalizes; `header()`
    lowercases the lookup), so a hand-built Request with a capitalized key silently
    misses. Low severity — the fix was the test — but recorded because it is a trap
    anyone constructing a Request by hand will hit, and the note now sits in the
    test.

35. **RequestContext was resolved fresh on every `get()`** (severity: high — it
    silently breaks auth). *Cause:* nothing bound a per-request instance;
    `RequestContext`'s own docblock claimed `Kernel::handle()` did, but Kernel
    never mentioned it. So `Authenticate` bound the user into one instance and the
    controller read an empty different one. The claim had gone unnoticed because
    nothing before Stage 9 both wrote and read the context across a request.
    *Fix:* `Kernel::handle()` now binds one `RequestContext` per request before any
    middleware runs (and, under a warm worker, this is also what stops one
    request's user leaking into the next). *Verified:* the end-to-end smoke test
    (`/me` returns the bound user's id) and the tenancy suite, which exercises the
    same mechanism.

36. **`audit:security` only recognized class-string middleware.** Routes carry
    middleware as class-strings OR instances (`RateLimit::perMinute()`,
    `Authorize::role()`, `new Authenticate(...)`). The CSRF check compared
    class-strings only, so a route protected by an instance was reported as
    unprotected — a false positive in a security gate, which is how gates get
    muted. *Fix:* a shared `hasMiddleware()` that matches by `instanceof` too; the
    new auth-warning check uses it from the start.

37. **Two compile-time guards fired on new bindings — both correctly.**
    `ContainerInterface` was narrower than its implementations (both already had
    `instance()`); widening it to expose `instance()`/`hasInstance()` is what let
    Kernel bind the context through the interface it actually holds. Separately,
    registering `WidgetPolicy` with a raw closure made `optimize` throw: the
    container compiler only accepts `[Factory::class, 'method']` array callables,
    because a Closure cannot be written into `services.compiled.php`. Both are the
    guardrails working as designed; the fix in each case was to use the seam
    correctly (array-callable factory, interface method), not to loosen the guard.

## Stage 9 — new surface, and why each choice
- `TokenIssuer`: HMAC-signed `base64url(userId).expiry.signature`. The id is
  base64url-encoded because an id may legally contain `.` (an email), which would
  otherwise make the token ambiguous to parse. `verify()` returns null rather than
  throwing — an invalid token is ordinary on a public endpoint — and checks the
  signature with `hash_equals` before anything else. Tested: tamper, wrong secret,
  expiry (including the exact-boundary second), dotted ids, malformed shapes.
- `AuthManager.attempt()` verifies against a dummy hash on the user-not-found path,
  so response time does not reveal whether an account exists. Paired with, not a
  substitute for, RateLimit on the login route.
- Stateless by default, revocable on demand: with no `TokenStore`, `logout()`
  clears the request context and returns **false** — a signed token cannot be
  un-signed, and the framework reports that rather than implying a server-side
  revoke it did not do. Supply a `TokenStore` to buy revocation at the cost of a
  per-request lookup; the store holds `sha256(token)`, never the token.
- `Gate` throws on an undefined ability instead of returning false: a typo must not
  read as "denied" and pass the tests. Abilities are registered by hand and are
  greppable (`$gate->abilities()`), never discovered.
- `Authorize` returns 401 (not 403) when nobody is authenticated — missing identity
  is a different answer from insufficient rights — and its 403 body names no
  permission, so it cannot be used to map the authorization model.
- `SchemaBuilder::createTable()` gained a `$constraints` parameter for table-level
  clauses (composite primary keys on the join tables); column names are still
  validated. `DatabaseRoleRepository` binds every value and needs no audit
  exemption.

## Stage 10 — Multi-database ConnectionManager

The manager, read/write split, grammar seam and `--connection=` per the staged
spec. Both findings this stage are the same species as #34-37: invisible until
the new seam was actually used, and both were caught by gates the repo already
had.

38. **`optimize` rejected the new `TenantAwareConnection` binding — correctly.**
    The first wiring in services.php used a raw closure, which the container
    compiler refuses because a Closure cannot be written into
    `services.compiled.php`. Identical to the WidgetPolicy half of #37 —
    apparently once per stage someone (this time: the Stage 10 build itself)
    re-learns that bindings must be `[Factory::class, 'method']` array
    callables. *Fix:* `ServiceFactories::tenantAwareConnection()`. *Verified:*
    `optimize` completes, and a CompiledContainer::fromFile smoke resolves
    TenantAwareConnection with an externally-provided ConnectionManager and
    opens a real tenant database through it.

39. **EagerLoader would have reverted eager-loaded relations to ANSI quoting.**
    It constructed its child QueryBuilders as `new QueryBuilder($pdo, $table)`
    — correct for four stages, silently wrong the moment a grammar other than
    the default exists: a parent query on a mysql connection would emit
    backticks while its relations emitted double quotes. Found by grepping
    every `new QueryBuilder` site after wiring the seam, which is the review
    the phpstan.neon exemption note prescribes for QueryBuilder changes.
    *Fix:* EagerLoader takes an optional Grammar (same default, same rationale)
    and threads it into both relation queries. *Verified:* an end-to-end test
    runs a MySqlGrammar parent + eager load against SQLite (which accepts
    backticks), plus the existing eager tests confirm the default is unchanged.

## Stage 10 — new surface, and why each choice

- `ConnectionManager` holds **typed `ConnectionConfig` objects**, not arrays —
  the shape is checked once at construction. Three named constructors (`dsn`,
  `provided`, `template`) because those are the three ways a connection exists,
  with genuinely different rules: `provided` is the `$externallyProvided`
  compiled-boot case; `template` carries `{database}` and is unusable by name
  on purpose.
- **Lazy by construction**: declaring a connection opens nothing (tested with a
  deliberately unopenable DSN that only throws on first use). Resolved PDOs are
  cached per name — and per database for templates, so one tenant shares one
  handle while two tenants never do.
- **Read/write split routes, and only routes**: with no split configured,
  `read()` returns the *identical* PDO instance `write()` returns — identity,
  not equality, so an unconfigured split costs nothing. There is deliberately
  no sticky-connection heuristic that redirects reads after a write; that magic
  hides replication lag in tests and produces unreproducible staleness in
  production. Read-your-own-write asks for `write()` explicitly.
- **Grammar is the smallest possible seam** (`quoteIdentifier()` + `name()`),
  not a per-clause dialect abstraction. Both grammars still route through
  `Identifier::validate()` — quoting is presentation, validation is the
  security property. `GrammarFactory` throws on unknown drivers rather than
  falling back to ANSI, so a wrong guess fails here, named, not at the server.
- **mysql SQL generation is proven without a mysql server**: `toSql()` exposes
  the built SELECT for pure string assertions, and SQLite's backtick tolerance
  lets the mysql grammar run end-to-end (insert/select/update with a live
  injection payload staying a bound value).
- **`TenantAwareConnection` is now a bridge, not a factory**: it delegates to
  `ConnectionManager::forDatabase()` and gains the manager's caching,
  error-mode and validation for free. The Stage 9 static `forTenant()`
  signature is kept (it builds a throwaway manager) so nothing downstream
  breaks; the instance form is what services.php wires. `lombokclarion/http`
  now declares `lombokclarion/persistence` — the dependency was already latent
  (`ext-pdo` was used undeclared too).
- **`forDatabase()` validates the substituted name** (rejects `;`, `=`, `..`,
  and validates the basename as an identifier): a tenant record is data, and a
  hostile `databaseName` must not be able to rewrite the DSN or escape the
  tenant directory. Covered by tests including a `pgsql:...;host=attacker`
  shape.
- **`migrate --connection=`**: the command now holds the manager, not a
  pre-built runner — a runner is bound to one PDO at construction, so a command
  holding a runner *cannot* honour the flag. Unknown connections and unknown
  arguments are hard errors (a typo'd `--connnection=` must not silently
  migrate the default). Each connection tracks its own `migrations` table,
  because each connection is its own database. Migrations always use the write
  side, never a replica.

## Stage 11 — `laravel-flavor` preset + compiled routes

No new numbered findings this stage — the first since Stage 8. Not because
nothing could go wrong, but because the two structural hazards were visible in
the design phase and designed out rather than discovered by a gate:

- **A stale compiled route index** would dispatch requests to the wrong route —
  silent and wrong in the worst way. Designed out: the index carries an
  order-sensitive fingerprint of the route table, and CompiledRouteMatcher
  refuses a mismatch at construction with the fix in the message. Tested with
  both an added route and a REORDERED route set (same routes, different
  precedence — the subtle case).
- **Instance middleware cannot honestly be serialized** (`RateLimit::perMinute(5)`
  is an object; var_export would need __set_state and a lie about closures).
  Designed out by scoping: the compiled artifact is a MATCH INDEX ONLY —
  regexes, param names/types, and an O(1) exact-path map — pointing into the
  live route table by index. Handlers and middleware stay in routes.php, which
  is cheap to load now that Route compiles its regex lazily.

One guardrail-worked moment worth recording: the mass-assignment guard from
Stage 4 rejected the flavor test's model for an undeclared `$fillable` — the
structural block firing on the framework's own test code, exactly as designed.

## Stage 11 — new surface, and why each choice

- **Typed route params do two jobs inseparably**: `{id:int}` narrows the MATCH
  (`/widgets/abc` is a 404, never a controller holding "abc") and CASTS the
  captured value (the attribute is a PHP int before the controller runs).
  There is no "matched but invalid" state to handle. The type vocabulary is
  `int` and `string` only — uuid/slug/date validation is input validation and
  belongs in a FormRequest, not the route table. An unknown type throws at
  compile with the known list, never silently matches everything.
- **Route regex compilation went lazy** (first `match()`, not the
  constructor) so that when the compiled index is in use, loading routes.php
  costs object construction only — the per-request preg_replace_callback work
  the compiled table exists to remove is actually removed, not moved.
- **`optimize` emits `storage/routes.compiled.php`**; the front controller
  loads it when present, dev stays live. Benchmark (as a test, worst case:
  the LAST dynamic route of a 500-route table, mean over 200 iterations):
  ~0.036ms against the 0.5ms budget; static paths ~0.0001ms via hash lookup.
- **`paginate(page, perPage)`** runs a COUNT and the page SELECT off the same
  wheres/joins so they cannot disagree. Asymmetric input handling on purpose:
  `page` is clamped (its source is a `?page=` URL param; `?page=0` means
  "first page", not 500) while `per_page <= 0` throws (its source is code; a
  bug to surface, not input to tolerate).
- **`LaravelFlavor::enable($container)` is the entire magic surface in one
  greppable line**: Facade::setContainer (covers Bus/Hash/Event plus the new
  Auth and DB facades), Model::setConnection via the Stage 10 manager's write
  side (AR must never write to a replica), and RETURNS the recommended global
  middleware — returned, not installed, because the Kernel takes middleware at
  construction and nothing should reach into it behind your back. The default
  list is SecurityHeaders alone: CSRF/rate-limit/auth are per-route decisions,
  and globalizing them is how APIs break mysteriously.
- **Auth and DB facades live in laravel-flavor, not lombokclarion/facades**:
  the base facades package depends only on the container, and an Auth facade
  there would drag lombokclarion/auth into every facades consumer. The flavor
  is the aggregation point that already depends on both.
- **`disable()` exists because `enable()` does**: process-global static state
  that can be set must be unsettable (warm workers, tests). Model gained
  `clearConnection()` for the same reason.
- **The domain boundary needed no new rule**: check-domain-boundary bans the
  `LombokClarion\` prefix wholesale, which covers the new package with no
  special case — and the suite proves the teeth by planting a Domain-layer
  `use LombokClarion\LaravelFlavor\DB` and asserting the real checker fails.

## Consolidation pass — the benchmark that turned into four findings

The cold-boot benchmark (benchmarks/) existed to pay off an unmeasured claim.
Its first result — "optimized" no faster than dev — pulled a thread that ended
in four numbered findings. All four share one root: **nothing ever booted the
app the way production would**. 240 tests exercised every component and both
compiled artifacts in isolation; no test loaded the artifacts through the real
bootstrap files with a hand-built request. tests/CompiledBootParityTest.php now
does exactly that, and CI builds the artifacts BEFORE the suite so the parity
test always has them.

40. **The compiled container and config were produced, verified, and consumed
    by nothing.** `optimize` wrote services.compiled.php and config.compiled.php;
    tests proved CompiledContainer::fromFile works; DEPLOYMENT.md told operators
    to generate them — and public/index.php unconditionally built the live
    container. Write-only artifacts. *Found by:* the benchmark's optimized
    variant showing no improvement. *Fix:* bootstrap/externals.php holds the
    live handles (PDO, ConnectionManager) as the ONE source both boot paths
    bind; public/index.php loads the compiled container when present.

41. **Three wiring holes were hiding behind #40**, each surfacing the moment
    the compiled path actually ran: (a) RequestContext had no explicit binding
    — dev resolved it through the live container's reflection-autowire
    fallback, invisible to the compiler, so the compiled boot died with
    NotFound; (b) routes.php constructed `new Authenticate($container->get(
    AuthManager::class))` EAGERLY at boot, capturing a RequestContext no
    request ever reads — in dev this made /me return 401 with a VALID token
    (worse than the compiled path's crash: silently wrong beats loudly broken);
    (c) AuthController was never added to extraRootIds when Stage 9 created it.
    *Fixes:* explicit RequestContext singleton binding; Authenticate as a
    CLASS-STRING route middleware (the Kernel resolves those per request,
    after the per-request context is bound — eager construction is for
    middleware carrying per-route VALUES like RateLimit::perMinute, not
    per-request STATE); AuthController in extraRootIds, plus a structural test
    that resolves every route's middleware and controller from the compiled
    graph so the next forgotten root fails by name in CI.

42. **The app had no way to create a user, and SQLite accepted the resulting
    NULL primary key.** users.id is an app-supplied VARCHAR(32) by design — but
    no register route, no seeder, no command supplied it. The natural hand-
    written INSERT omits id; SQLite's legacy quirk admits NULL into a non-
    INTEGER PRIMARY KEY; login then succeeds and mints a token with an EMPTY
    user id, making every authenticated request an unexplainable 401. The
    DB-backed auth path had never been walked end-to-end: AuthTest covers
    AuthManager exhaustively against ArrayUserProvider, in memory. *Fixes:*
    `NOT NULL` added explicitly to all VARCHAR primary keys in CreateAuthTables
    (the quirk is now a constraint violation at INSERT, where the bug is), and
    `user:create` — id minted as 16 random bytes hex (exactly the column
    width), password via LOMBOKCLARION_PASSWORD env, never argv (argv is
    visible in `ps` and shell history).

43. **A trap documented in #34 was still armed.** Request::header() lowercased
    the LOOKUP while the constructor stored keys verbatim, so a hand-built
    Request with 'Authorization' silently lost the header — a silent 401 in
    auth middleware. #34 recorded the trap; the tests dodged it by passing
    lowercase; nobody disarmed it. *Fix:* the constructor normalizes header
    keys (RFC 9110 §5.1 — names are case-insensitive), so the documented
    precondition became an enforced invariant. Documenting a footgun is not
    fixing it.

Also from this pass: the benchmark harness itself (benchmarks/, methodology in
its README — fresh process per sample, opcache off both sides, medians over 50
runs), the measured LombokClarion half (~3.2 ms dev / ~3.4 ms optimized at this
app's size, with the honest note that compiled artifacts are a SCALING tool
whose fixed costs exceed their savings on a 20-binding graph), and the
same-methodology Laravel counterpart script for a machine where Packagist is
reachable. The comparative claim in the design spec (internal doc, not in repo) is annotated as
qualified until the same-machine table exists.

## Stage 12 — validation

Two guardrails-worked moments, no new numbered findings: the Stage 8b i18n
parity gate refused the suite until all 24 catalogs carried the 11 new
validation.* keys (exactly the CI-assertable completeness it was built for),
and PHPStan caught a PHP-semantics slip in the bool canonicalization map
(true/1/'1' collide as array keys — rewritten as an explicit match). Design
notes live in the design spec Stage 12 section (internal doc); the
RendersResponse seam added to http/Kernel is deliberately interface-narrow —
the Kernel renders only exceptions that ARE HTTP outcomes and lets bugs stay
loud.

## Deploy checklist — run before first real deployment

Executed as a fresh-unzip walk of the shipped artifact (identity, secrets scan,
migrations forward+idempotent, all gates, live serve smoke — all green) and it
still produced two findings, both in the gap between "works when exercised by
tests" and "survives an operator's ordinary mistakes":

44. **Forgetting APP_KEY silently shipped a publicly-known HMAC secret.** Every
    factory fell back to the hardcoded 'dev-secret-change-me' — a string in
    this public repo — as the secret signing BOTH auth tokens and CSRF. Anyone
    who read the source could forge a login on any deployment that missed one
    env var. *Fix:* ServiceFactories::appKey() — the single read point — throws
    at boot in non-local APP_ENV when the key is missing, the repo default, or
    under 32 chars, with the fix in the message; an UNSET APP_ENV counts as
    production (fail safe, not open — which is also why tests/harness.php now
    declares APP_ENV=testing explicitly). "The login page is down" is a better
    morning than "everyone could sign their own tokens for a month".

45. **Every migration authored a down() and nothing could run one.** No
    rollback method on MigrationRunner, no command — the runbook's implied
    rollback story was dead code, which is worse than none because dead code
    reads as capability. *Fix:* MigrationRunner::rollback($manifest, $steps)
    (newest first, same transactional discipline as migrate(), hard error on a
    recorded class missing from the manifest — guessing is not a rollback) +
    `migrate:rollback [--steps=N] [--connection=]` with migrate's argument
    discipline, + a written rollback runbook in DEPLOYMENT.md whose first line
    is backup-before-migrate, because down() undoes schema, not data.

Minor closures from the same walk: DEPLOYMENT.md gained the first-admin
user:create step (the only way to mint a user, previously undocumented in the
deploy path) and .env.example documents LOMBOKCLARION_PASSWORD.

## First consumer app — SIGAP-MP Tahap 1

The framework's first use as an external dependency (separate repo, path
requires, own bootstrap). One integration lesson and one numbered finding in
the first hour of consumption — the "seams reveal holes when actually used"
pattern, now from outside:

- Lesson (not a gap): SchemaBuilder::createTable's third `$constraints`
  parameter already covers composite primary keys; the consumer initially
  smuggled the constraint through a column key and Identifier::validate
  rejected it correctly.
- Packaging gap, since numbered and closed as #47 below: the validation
  package's message catalogs lived in the framework repo's app-level
  resources/lang/, so a consumer had to copy the 11 validation.* keys by hand.

46. **QueryBuilder could not say IS NULL.** Soft-delete filtering
    (`WHERE deleted_at IS NULL`) is a daily query, and no bound parameter can
    express it — `= NULL` is always false in SQL. *Fix:*
    whereNull()/whereNotNull() as dedicated methods rather than an 'is'
    operator in where(), because where() promises "the value is a bound
    parameter" and an operator whose only value is the non-value NULL would be
    the one row of that table where the promise silently means nothing. 'is'
    in where() stays rejected; the method is the API.

47. **validation.* catalogs shipped with the app, not the package.** The i18n
    messages that make ValidationException render human text lived in the
    framework repo's app-level resources/lang/ — every consumer app had to
    copy 11 keys × 24 locales by hand or silently render raw keys. *Fix:* the
    catalogs moved into packages/validation/resources/lang/ behind
    `Lang::catalog($locale)` — an explicit loader (no directory scanning),
    hardcoded `Lang::LOCALES` constant, RuntimeException on unknown locale.
    App catalogs keep only app keys and may still override package keys by
    addCatalog() order. Two new gates: package-catalog parity vs en +
    LOCALES↔disk sync in both directions, and a sweep asserting no app catalog
    smuggles validation.* back in.

## Stage 13 — storage/upload

No new numbered findings: the hazards this stage exists for were designed out
up front rather than surfaced afterwards, and each has a test pinning it:

- **Path traversal** — every public LocalStorage method funnels through
  `assertSafePath()` (same boundary discipline as
  ConnectionManager::databaseKey()): absolute paths, backslashes, NUL, `.`,
  `..`, and empty segments are hard errors, never normalized. A caller who
  built `../` did not mean something guessable.
- **Executable-upload smuggling** — three independent lines: (1) the stored
  NAME is always generated (16 random bytes, hex), the client filename touches
  nothing; (2) the extension is the CALLER's explicit argument, validated
  `/^[a-z0-9]{1,10}$/` — `clientExtension()` passed through unexamined hits a
  wall, not a filesystem; (3) `Rule::file()` sniffs the mime from the BYTES
  via finfo — a PHP payload named `kitten.png` with an `image/png` client
  header is rejected on content. The pattern deliberately does NOT blocklist
  `php`: which extensions are storable is the caller's whitelist decision
  after Rule::file() proved the type, and a test pins that division of labor
  so tightening it later is a choice, not drift.
- **Provenance confusion** — `UploadedFile::moveTo()` owns the
  move_uploaded_file-vs-rename decision because `$sapi` is its private
  knowledge; exposing the flag would turn a checked invariant back into a
  convention. Single-shot: a second move is a hard error.
- **The `$_FILES` split** — FormRequest rejoins `$request->files` into the
  validation source (undoing PHP's split, not inventing a merge policy), and
  `UPLOAD_ERR_NO_FILE` is dropped as ABSENT so `->nullable()` sees absence
  rather than a broken upload.

Division of labor stays as decided: mime/size are validation's job
(Rule::file), storage is pure persistence; no cloud drivers in core (S3/GCS
would be separate packages against the Storage interface); multi-file
(`photos[]`) is out of Rule::file v1's vocabulary — a list fails
validation.file.type until a dedicated rule earns its place.

## PHPStan gate raised to level 5, and publishing wired

Two follow-ups that had been explicitly parked as "separate jobs" were done.

**PHPStan level 0 → 5.** The gate had run at level 0 because raising it surfaced a
backlog. That backlog was worked off rather than deferred:

- Genuine defects fixed: `ConfigCompiler::cast()` gained a `default => throw` arm
  (an unknown cast type was silently unhandled); `ViewCompiler`'s docblock listed
  `@extends` as a template directive, which PHPStan parsed as the generics tag and
  choked on — reworded so no directive name starts with `@`; `QueryBuilder::with()`
  carried `@param list<string>` on a variadic (element type is `string`, not the
  collected list); a redundant `&& $depth > 0` in `TokenScanner` (the `break` on
  `$depth <= 0` already guards the loop); `Model` called `static::filterFillable()`
  on a *private* method (`self::` is the identical, safe resolution) and held a
  write-only `$exists` property (three writes, zero reads — removed).
- `treatPhpDocTypesAsCertain: false` was set, and it is a stance, not a mute:
  PHPDoc types are a promise a caller can break, and this framework validates
  untrusted input at its boundaries precisely because they do. Its defensive
  runtime checks (`is_string`, `instanceof`, a `class-string` that might not
  autoload) are intentional; treating PHPDoc as certain would flag every one as
  dead. This alone dropped the level-5 finding count from 23 to 10.
- Three residual findings are PHPStan stub/inference limitations, not code
  defects, and each carries a **sited** `@phpstan-ignore` with its reason rather
  than a blanket suppression: `Model::find()`'s `static` covariance is lost
  through the `self`-returning fluent chain (asserted with an inline `@var`);
  `WorkCommand`'s worker daemon is an intentional `while (true)` that exits on a
  signal, not the condition; `Container`'s `catch` around `new ReflectionClass`
  guards a runtime autoload failure that PHPStan's stub proves away. The
  `QueryBuilder` `rawSqlValue` exemption is unchanged and still tests-backed.
- CI needed no edit: it runs `phpstan analyse -c phpstan.neon` with no `--level`,
  so it inherits level 5 from the config. Level 6 was deliberately not taken — it
  is a large iterable-annotation project, not a correctness backlog.

**Publishing decided and wired.** The parked monorepo-vs-split question is
resolved in `docs/PUBLISHING.md`: the monorepo at `codinglombok/LombokClarion`
stays canonical, and each package is mirrored read-only by `git subtree split` to
`codinglombok/<pkg>` for Packagist to watch, published under the `lombokclarion/*`
vendor. `bin/split-package.sh` (pure git-subtree, no third-party tooling — kept
inspectable like the rest of the repo, and verified against a local bare mirror:
the split root is the package's own `composer.json` + `src/`, no `packages/`
leak) and `.github/workflows/split.yml` (explicit 18-package matrix, gated on a
green CI run, tag-aware) implement it. All cross-package constraints are `*`, so
the split needs no constraint rewrite. `.gitignore` now excludes the generated
assets (`public/assets/*` hashed files, `storage/assets.manifest.php`) and upload
target (`storage/app/*`) alongside the already-excluded compiled artifacts.

## Stage 15 — upstream consumer fixes (InakSasambo)

The first real consumer application — InakSasambo (sistem informasi pengelolaan
Ditresnarkoba Polda NTB), running LombokClarion on live institutional work —
exercised the framework end-to-end and reported nine targeted hazards and gaps.
All nine are now closed:

1. **Middleware docblocks lied** (U-S1-1): said `list<class-string>`, but the
   middleware system accepted `Middleware` instances too. Consumer's PHPStan-clean
   code was impossible. Fixed: docblocks widened to `list<class-string|Middleware>`
   throughout (Router, Kernel, all route verbs, group()). Bonus: `public/index.php`
   was outside PHPStan's paths, so the framework never analyzed its own entrypoint —
   fixed by adding `public/` to `phpstan.neon` paths. (Consequence: PHPStan's level 5
   @docblock stricter; F-15-11 caught `: callable` vs native `Closure` in Authorize,
   tightened to resolve.)

2. **Lookup indexes missing** (U-S1-4): consumer's schema audit needed the ability
   to add indexes and make the schema reversible. `SchemaBuilder` gains
   `createIndex($table, $columns, $unique=false)` and `dropIndex()`; index names
   are **derived** (`<table>_<cols>_idx`), not caller-supplied (smaller validation
   surface, `dropIndex()` reversible without magic names), validated via
   `Identifier::validate()`, and driver-aware (MySQL/SQLite specific DROP).

3. **GROUP BY counting missing** (U-S1-5): scalar `count()` existed; `countBy()`
   didn't. Added `QueryBuilder::countBy(string $col): array<string,int>` with the
   column qualified through `qualifyColumn()`, so it audits as a valid reference.

4. **Auth boot-context trap still live** (U-S2-1, re-visiting #41): the eager
   `role()` / `permission()` factories re-armed the trap for routes evaluated at
   boot. In a long-running process (queue workers, HTTP server), a boot-time
   captured `AuthManager` would never see the per-request context — 401 with a
   valid token, always. Added `roleLazy()` / `permissionLazy()` (U-S2-1): instance
   carries only the required string + a resolver-callable, resolved per-request
   inside `handle()`, **no memoization by design** (caching defeats the purpose).
   Resolver is `\Closure` (packages/auth gains no container dependency). Class
   docblock now documents the trap; eager factories warn.

5. **TokenIssuer was deterministic per second** (U-S2-3): two logins in one second
   generated byte-identical tokens → identical tokenIds → durable TokenStore UNIQUE
   violation or token resurrection. Fixed: **8-byte hex nonce** (8 random bytes,
   hex-encoded, signed) into the payload. Token format now **4-field**:
   `id.expiry.nonce.sig`. Verification still accepts legacy **3-field** format
   (`id.expiry.sig`, pre-nonce) until those tokens expire — payload-version
   compatibility so live sessions survive the deploy. (Consequence: +4 new tests.)

6. **TokenStore contract unwritten** (U-S2-3): `remember()` had no spec for
   re-remembered tokenIds. Documented: `remember()` **overwrites and un-revokes**.
   Reference: UPSERT with `revoked_at = NULL`. Pinned with a triple-stage test
   (remember → revoke → remember).

7. **REMOTE_ADDR never captured** (U-S2-2): `Request` never checked
   `$_SERVER['REMOTE_ADDR']`, so `RateLimit` keyed on the spoofable
   `X-Forwarded-For` header first. Fixed: `Request` ctor gains trailing
   `?string $remoteAddr = null` (non-breaking); `fromGlobals()` captures
   `REMOTE_ADDR`; new `ip(): ?string` returns it (XFF deliberately **not**
   consulted — docblock is clear). RateLimit identity now `ip() ?? xff ?? 'unknown'`
   (XFF kept only as fallback for proxied setups).

8. **phpstan-rules dragged Packagist** (U-S1-2): the package lived in `require`,
   so installing it pulled in `phpstan/phpstan` from Packagist, breaking the CI
   PHAR workflow (installed PhpStan stays separate). Moved to `suggest` with PHAR
   flow documented.

9. **Unversioned dev-main** (U-S1-3): no `branch-alias` for `dev-main` branch,
   so consumers tracking `main` got unversioned installs. Added
   `extra.branch-alias {dev-main: 2.2.x-dev}` to all 19 packages. (Last history
   tag: v2.1.0.)

Bonus: **local-dev runbook** (U-S1-6) — `docs/DEPLOYMENT.md` now covers
first-run setup without the #44 APP_KEY fail-safe leaving devs confused.

**Result: 286 tests** (282 baseline + 4 new tests for nonce/revoke/legacy/contract;
5th case — non-hex nonce — folded into the existing malformed-tokens test).
All four gates green, both autoload paths verified.


## "No bug" evidence (operational definition)

"No bug" here means: **no known failures** across the entire tested surface — not a
claim of absolute correctness (no software can guarantee that). Verified surface:

- **286 automated unit/integration tests** (282 baseline + 4 new Stage 15 auth tests):
  0 failures (run `php tests/run-all.php`), passing both under the sandbox
  `autoload.php` shim and under Composer's real autoloader with the shim moved aside.
- End-to-end smokes recorded during the build: JSON API (200/419/201/422),
  HTML flow (CSRF cookie → form → 303 → escaped output; a `<script>` payload proven
  to become `&lt;script&gt;`), compiled-container boot serving requests, 4 themes
  rendering, immutable assets + traversal rejected, `work` running.
- Quality gates: `audit:sql app packages --explain` clean (scope corrected — see #29);
  `audit:security` clean; `check-domain-boundary` OK (and proven to have teeth, see #12);
  `phpstan analyse` → `[OK] No errors` (executed for the first time in Stage 8c, see #28).
- SQL injection tested directly with a `Robert'); DROP TABLE...--` payload → table intact.
  Note the scope of that claim: it exercises the QueryBuilder path, which was always
  parameterized. It says nothing about the bypass in #28 — that shape needed its own
  tests, and now has them.
- Auth exercised end-to-end through the real Kernel (see Stage 9): login success/failure,
  cookie and Bearer transport, tampered/expired token rejection, stateless logout
  reporting no server-side revoke, and CSRF-less login → 419. RBAC grant/deny, policy
  dispatch, and a bound injection payload against DatabaseRoleRepository are unit-tested.
- All generated artifacts (`storage/*.compiled.php`, sqlite, assets) are excluded
  from the release package; regenerate via `migrate` + `optimize`.
