# Examples — runnable single-file demos

Each file runs standalone: `php examples/01-hello-http.php`

| File | Demonstrates | Expected output |
|---|---|---|
| 01-hello-http.php | Router + Kernel + controller, no bootstrap needed | `200 {"hello":"lombok"}` |
| 02-command-bus.php | Explicit CommandBus + handler, zero HTTP/DB | `user-1` |
| 03-persistence-eager.php | Bound-params QueryBuilder + N+1-safe EagerLoader | `Hello has 1 comment(s)` |
| 04-queue-worker.php | ShouldQueue → QueuedCommandBus → QueueWorker parity | `pending: 1` then `sent to x@y.com` |

For the full application example (HTML pages, dashboard charts, CSRF, tenancy-ready
wiring) see `app/` + `bootstrap/` — run it with:
`php bin/lombokclarion migrate && php -S localhost:8080 -t public`
