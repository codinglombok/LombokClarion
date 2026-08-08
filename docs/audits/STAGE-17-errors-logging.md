# Audit findings — Stage 17 (error pages + logging framework)

One file per stage under `docs/audits/`. Same fixed format as STAGE-14/15/16.

- **State at audit:** 20 packages (`lombokclarion/log` is new), **377 tests**
  (333 baseline + 24 `LoggingTest` + 20 `ErrorPageTest`),
  `audit:sql`/`audit:security`/`check-domain-boundary` green — all three re-run
  after the work, with no new `audit:security` warnings (the same 4 intentional
  public routes). PHPStan **not run this session** — carried as F-16-04.
- **Scope this stage:** slice A of Stage 17 — the logging package, the crash
  path, error pages per status code, and application wiring. Exception
  frequency tracking and alert escalation (plan items under "Exception
  reporting") are **not** in this slice; only the `ExceptionReporter` seam is.
- **Finding id:** `F-17-NN`. **Severity:** Critical / High / Medium / Low / Info.
  **Status:** Fixed / Accepted (won't change, with reason) / Deferred.

---

## A. Undeclared dependencies (found by auditing, not by reading)

An exhaustive check — every `use LombokClarion\X` under each package's `src/`
against that package's `composer.json` — after F-16-11 showed the class of bug
existed. These are all **pre-existing**, not introduced by Stage 17.

| ID | Sev | Area | Finding | Resolution | Status |
|------|--------|------|---------|------------|--------|
| F-17-01 | Medium | packages/view | `StaticAssetsMiddleware` imports `LombokClarion\Http\{Middleware,Request,Response}`, but `packages/view/composer.json` declared only `php`. A consumer installing `lombokclarion/view` alone gets a package that fatals the moment the middleware is touched | `lombokclarion/http` added to view's `require` | Fixed |
| F-17-02 | Medium | packages/console, packages/facades | Same shape: `console` uses `LombokClarion\Bus\Queue\QueueWorker` (WorkCommand) without requiring `lombokclarion/bus`; `facades` uses `Bus\CommandBus`, `Bus\EventBus` and `Security\PasswordHasher` without requiring either package. Both are hard class imports in `src/`, not optional integrations | Requires added to both | Fixed |
| F-16-11 | Medium | auth, bus, console, laravel-flavor | Carried from Stage 16: four packages use `PDO` directly without declaring `ext-pdo` | `ext-pdo` added to all four. Re-confirmed by grep before applying rather than trusting the earlier note | Fixed |

**Why this keeps recurring.** Nothing enforces it. `audit:sql` and
`check-domain-boundary` are custom gates because someone decided those
invariants mattered enough to automate; declared-dependency correctness has no
such gate, so it is re-discovered by hand each stage. A `audit:deps` command
would end the category — recorded as F-17-11 rather than built here, because
adding a gate is its own slice.

---

## B. The logging package

| ID | Sev | Area | Finding | Resolution | Status |
|------|--------|------|---------|------------|--------|
| F-17-03 | High | packages/log | There was no logging at all. Every consumer application would have hand-rolled its own, and the framework had nowhere to report a crash to — which is why Stage 17's error handling could not exist without this first | New `lombokclarion/log`: `LogLevel` (RFC 5424 enum), `Logger` + `LogsConveniently`, `LogRecord`, `ChannelLogger`, `LogHandler`/`LogFormatter` seams, Stream/InMemory handlers, Json/Line formatters, `NullLogger` | Fixed |
| F-17-04 | High | packages/log | **The central safety decision.** Secrets leak into logs without anyone doing anything wrong: nobody writes `log('password: hunter2')`, they write `log('login failed', $request->all())` and the password rides inside an array they never inspected. By the time it is on disk it is also in the shipper, the aggregator, and the backup | `Redactor` runs inside `ChannelLogger` before any handler sees the record, and there is **no constructor flag to disable it**. Redaction that must be remembered will be forgotten exactly once, which is all it takes. Matching is on key name, case-insensitive, recursive — over-redaction is the correct direction to err (a masked timestamp is an inconvenience; a logged credential is an incident). Base context is redacted too, pinned by a test | Accepted (deliberate) |
| F-17-05 | Medium | packages/log | A handler that throws — disk full, syslog socket gone — could take out the other handlers, losing the record precisely when something was already wrong | `ChannelLogger` isolates handlers from each other; a throwing handler is swallowed and the rest still receive the record. Pinned by a test asserting the working handler got it | Fixed |
| F-17-06 | Medium | packages/log | Size-based rotation has to rename files that other processes hold open — under FPM that is several processes, and the classic result is lines written into a file already renamed away | Rotation is by **date in the filename**: every process independently computes the same name for the same day and nothing is ever renamed. Retention deletes by the date parsed out of the name, not mtime (a backup tool touching a file would otherwise keep it young forever), and prunes once per process rather than per write | Accepted (deliberate) |
| F-17-07 | Low | packages/log | A multi-line message or a trace in context would break one-record-per-line, making the log unparseable exactly when something is going wrong | `JsonFormatter` relies on `json_encode` escaping; `LineFormatter` escapes newlines explicitly. Both pinned by tests. `JSON_PARTIAL_OUTPUT_ON_ERROR` plus an encodable fallback means a bad byte in context degrades the line instead of dropping the entry | Fixed |

---

## C. Error handling and error pages

| ID | Sev | Area | Finding | Resolution | Status |
|------|--------|------|---------|------------|--------|
| F-17-08 | High | http/Errors | **The philosophy risk in this stage.** Adding error pages is the usual excuse to introduce an exception-class-to-status registry — precisely what `RendersResponse`'s docblock exists to forbid | `ErrorPageRenderer::render()` takes a **status code, not a Throwable**. Anything reaching `ErrorHandler::handle()` is a 500, unchanged. Statuses arrive from exactly three explicit places: the router (404/405), a `RendersResponse` exception rendering itself, or "something crashed". Pinned by a test asserting a `RendersResponse` exception still returns its own 418 and is **not** logged as a crash even with a handler wired | Accepted (deliberate) |
| F-17-09 | High | http/Errors | Exception messages routinely contain a DSN, a deploy path, or the value that failed validation. A production error page that renders them is a disclosure bug | `$debug` gates the **data, not the template**: when false, message/file/line/trace are never handed to the renderer at all. A leak requires editing `ErrorHandler`, not misconfiguring a view. Pinned both through HTML and JSON paths. Debug output is HTML-escaped — a debug-only XSS is still an XSS | Fixed |
| F-17-10 | Medium | app wiring | Forgetting to unset a debug flag before deploying is the most common way traces reach production | `ServiceFactories::debugEnabled()` is **asymmetric**: `APP_DEBUG` can turn debug OFF locally but cannot turn it ON outside a local environment, and an unset `APP_ENV` reads as production — the same fail-safe direction `appKey()` already used. Pinned by a four-case test | Fixed |
| F-17-11 | Low | http/Errors, view | The most likely reason an error page fails to render is that the view layer is what broke — a template compile error, an unwritable cache directory. A renderer that only works when templates are healthy goes blank exactly when it is needed | Three-rung ladder: `errors/{status}` → `errors/generic` → `PlainErrorPageRenderer`, which has no view dependency and no template files. Pinned by a test pointing the engine at a nonexistent directory. Error templates deliberately do **not** extend the app layout (it needs `$theme` and `$assets`) and inline their CSS | Fixed |
| F-17-12 | Low | http/Errors | Returning an HTML error page to a `fetch()` call produces a client-side parse error that hides the real status | Content negotiation on `Accept` and `X-Requested-With`. `*/*` deliberately does **not** count as asking for JSON — curl and every browser send it. Pinned by a test | Fixed |
| F-17-13 | Low | routing/Kernel | Wiring error handling into the Kernel risked changing behaviour for every existing caller and test | `?ErrorHandler` defaults to null, and null is byte-for-byte the pre-Stage-17 contract: plain-text 404/405, crashes bubble to the SAPI untouched. Pinned by a test asserting both | Fixed |
| F-17-14 | Low | http/Errors | A broken `ExceptionReporter` (Sentry down) could replace the original error with a less useful one | Reporters are individually guarded; a failure is logged at Error and the remaining reporters still fire. Pinned. No vendor SDK is a dependency — `ExceptionReporter` is an interface and nothing is wired by default | Fixed |

---

## D. Verification performed

Round-trip smoke against the real application before any test was written:
redaction including nested keys, level filtering, both formatters, production
vs debug output, content negotiation across four client shapes, the template
ladder for 404/403/500/429, and the ladder's last rung with the view engine
pointed at a missing directory.

End-to-end through `php -S` with `APP_ENV=production`: `/` 200, `/widgets` 200,
`/nowhere` 404 rendering the authored page, JSON clients receiving JSON.

**An unplanned demonstration.** The first end-to-end run returned 500 on the
homepage. That was not a regression: it was the pre-existing `APP_KEY` fail-safe
correctly refusing to boot without a key under `APP_ENV=production`. Stage 17
caught it — logged at critical to `storage/logs/` with file and line, rendered
as a 500, and the `APP_KEY` message did **not** reach the client. Before this
stage the same condition would have been a raw fatal.

---

## E. Open / deferred (carried forward)

| ID | Sev | Finding | Owner / next step |
|------|--------|---------|-------------------|
| F-14-15 | Medium | npm ↔ framework license mismatch | Re-publish npm `lombokclarion` as Apache-2.0, or annotate as asset-tooling-only |
| F-14-16 | High | GitHub `main` far behind local | Phase 1 (force-push + retag) |
| F-16-01 | High | `.github/` absent from the delivery artifact | Restore from canonical repo before claiming CI state |
| F-16-02 | High | Reconciliation decision 5 references a missing file | Re-frame the decision before answering it |
| F-16-04 | Info | PHPStan gate unverified in this environment | Run locally / in CI where Packagist is reachable |
| F-17-15 | Medium | No `audit:deps` gate — declared-dependency correctness is re-discovered by hand every stage (F-16-11, F-17-01, F-17-02 are all the same bug) | Build the gate: parse `use LombokClarion\X` per package `src/`, compare against that package's `composer.json` require. Would have caught all three automatically |
| F-17-16 | Low | Stage 17 slice B not started: exception frequency tracking, alert escalation, stack-trace grouping, and syslog/database handlers | Next Stage 17 session |

**Closed this session:** F-16-11 (group A), plus F-17-01 through F-17-14.
