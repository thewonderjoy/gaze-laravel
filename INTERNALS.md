# gaze-laravel internals

How the package works inside. For using it, see [README.md](README.md).

## Traces

Every unit of work is one trace, and every entry carries it (batch-level `trace {id, kind, name, parent, started_at}` on the wire):

| Unit | Opens on | Closes with a summary row (`trace.ended = true`) |
|---|---|---|
| HTTP request (`http`) | a global middleware, prepended to the stack; an incoming W3C `traceparent` becomes its parent (the caller's span id), never its id | `RequestHandled`: kind `request` with method, url, uri, status, route, action, middleware, `duration_ms`, `memory_peak`, queries, query_ms, duplicates, slow, n_plus_one, logs, mails, jobs, exceptions |
| Queued job (`job`) | `JobProcessing` | `JobProcessed` / `JobFailed` / `JobReleasedAfterException`: kind `job` with event, status, queue, attempts, exception |
| Artisan command (`command`) | `CommandStarting` | `CommandFinished`: kind `command` with exit code (arguments redacted) |
| Test (`test`) | the `TracesTests` trait | tearDown: kind `test` with the result |

- A job links to the request, command or job that dispatched it: the trace id rides in the job payload as hidden Context (`gaze.trace`) and becomes the job trace's `parent`. It is read back with a regular expression; nothing is unserialized. A sync job is a nested trace inside the request.
- Long-running commands (`queue:work`, `horizon`, `schedule:work`, `octane:*`, `serve`, …) get no trace (`traces.ignore_commands`); the jobs they run do.
- Paths in `traces.ignore_paths` (`telescope*`, `horizon*`, `pulse*`, `_debugbar*`, …) and the relay route get no trace, and the watchers send nothing for them, terminating callbacks included (`GazeManager::inIgnoredRequest()`, decided once per request). `gaze()` calls there still arrive.
- Per-trace state (duplicate and N+1 detection, counters) is dropped with the trace, so nothing grows across requests in Octane or across jobs in a worker.
- In unit tests Laravel does not fire `CommandStarting`/`CommandFinished` for `$this->artisan()` (it reroutes Symfony's console events only outside tests), so commands run from tests are not traced unless you call `$kernel->rerouteSymfonyCommandEvents()`.

## Watchers in detail

| Switch (`config/gaze.php` `watchers`, env) | Default | Entry | Runtime |
|---|---|---|---|
| `exceptions` (`GAZE_EXCEPTIONS`) | on | `exception`: frames with snippets, request context, the trace's last queries, the SQL of a `QueryException`. Any reported `Throwable` (laravel-ray took only `\Exception`) | `showExceptions()` |
| `logs` (`GAZE_LOGS`) | on | `app_log`: level, message, redacted context; red for error and above, orange for warning | `showLogs()` |
| `mails` (`GAZE_MAILS`) | on | `mail`: html, text, subject, from, to, cc, bcc, reply_to, attachments, mailable/notification class (from `MessageSending`; laravel-ray lost the envelope on Laravel 11+) | `showMails()` |
| `dumps` (`GAZE_DUMPS`) | on | `dump()` / `dd()` as `log` entries; the original output still reaches the browser or terminal | `showDumps()` |
| `queries` (`GAZE_QUERIES`) | on | `query` (below) | `showQueries()` / `queries()` |
| `duplicate_queries`, `slow_queries`, `insert_queries`, `select_queries`, `update_queries`, `delete_queries` | off | only the matching queries | `showDuplicateQueries()`, `showSlowQueries($ms)`, `showInsertQueries()`, … |
| conditional | | queries matching a closure | `showConditionalQueries(fn (QueryExecuted $q) => ..., $callback, $name)` |
| `jobs` (`GAZE_JOBS`) | off | `job`: JobQueued (dispatching trace), JobProcessing (job trace). Described from the payload: the command is read **without `unserialize()`** (models stay `ModelIdentifier`s, nothing is re-fetched, encrypted jobs show `(encrypted)`) | `showJobs()` / `jobs()` |
| `events` (`GAZE_EVENTS`) | off | `event`: name, event object or payload | `showEvents()` / `events()` |
| `views` (`GAZE_VIEWS`) | off | `view`: name, path, relativePath, data | `showViews()` / `views()` |
| `cache` (`GAZE_CACHE`) | off | `cache`: hit / missed / written / forgotten, key, store, value (falsy values included), ttl, tags | `showCache()` |
| `requests` (`GAZE_REQUESTS`) | off | adds IP, headers, payload, session and a response preview to the request summary (all redacted) | `showRequests()` / `requests()` |
| `http_client` (`GAZE_HTTP_CLIENT`) | off | `http`: one entry per call, added when sent and updated with status, response and `duration_ms`, or with the error of a `ConnectionFailed` | `showHttpClientRequests()` |
| `deprecations` (`GAZE_DEPRECATIONS`) | off | PHP deprecations as gray `app_log` entries; when off they are filtered (recognised by the deprecation handler on the stack, not by the word "deprecated") | `showDeprecations()` |

- A listener is registered only when its watcher is on. With Gaze disabled nothing is registered at all: no listener, no middleware, no route, no dump handler. The provider switches gaze-php off without loading the manager, so a request loads the provider, gaze-php's `Config` and `Noop`, and no other class of either package (`DisabledTest` checks this in a process of its own). `gaze()` returns the Noop; the `->gaze()` macros and Blade directives stay, and do nothing. `GazeServiceProvider::RUNS` tells the Noop which macros run a callback (`showQueries($fn)`...); a test keeps it in step with `GazeMixin`.
- `showX($callback)` switches the watcher on for the callback only and returns its result. `showSlowQueries($ms, $callback)` puts the previous threshold back afterwards too; without a callback the threshold stays. `showX()` returns a proxy whose modifiers (`->green()->label('SQL')->large()`) apply to every entry of that watcher. `stopShowingX()` switches it off.
- The app can switch watchers for every process. `GET /v1/hello` may carry `"watchers": {"queries": false, "events": true}`. It is read at boot (shared by all processes for 60 s through the client's state file) and re-read by queue workers between jobs and by Octane between requests. Precedence: config < the app < `showX()` in this process. `remote_watchers => false` ignores the app.

## Queries

```json
{"sql": "select * from \"users\" where \"id\" = 5 limit 1", "template": "select * from \"users\" where \"id\" = ? limit 1",
 "bindings": [{"k": "int", "v": 5}], "connection": "mysql", "driver": "mysql", "time_ms": 0.41,
 "fingerprint": "q:7c1e9a0d3b5f2e41", "flags": []}
```

- `sql` has the bindings inlined for display, `template` keeps the placeholders, `fingerprint` hashes the normalised SQL shape (literals, `IN (...)` lists and multi-row `VALUES` collapsed, plus the connection).
- Per trace: `dup` (same SQL and bindings ran before), `slow` (`time_ms >= queries.slow_threshold_ms`, `GAZE_SLOW_QUERY_MS`, falls back to `RAY_SLOW_QUERY_THRESHOLD_IN_MS`), `n+1` (the same shape ran more than `queries.n_plus_one_threshold` times, default 3). Flags are in `body.flags` and in `tags`. The app shows the N+1 badge from the flag; no label is added.
- **N+1 grouping** (`queries.group_n_plus_one`, default on): once the threshold is crossed, the first entry is updated in place (`op: update`) with `count`, `total_ms`, `n_plus_one {count, threshold, relation}` and `executions: [{bindings, time_ms, offset_ms}]` (up to `queries.max_executions`), and the separate rows already sent are removed. Updates go out at powers of two and when the trace ends, so traffic stays linear. A lazy-loaded relation behind it is named (`App\Models\Post::author`).
- Laravel's query log is never turned on. Origins point at the app code that ran the query, not at the framework. When no app code ran it (the session middleware, the auth guard loading the user and its `$with` relations), the only app frame left is `public/index.php` or `artisan`; the origin is then the Laravel or package code that asked for it (`DatabaseSessionHandler::read`), past the database, events, pipeline and other machinery, with the entry script as the next frame.
- Queries with a class from `queries.ignore_namespaces` on the call stack are left out (default `Laravel\Telescope\`, `Laravel\Pulse\`: both write what they recorded when a request ends). The stack is read only for a query that would be sent. A Pulse dashboard polls through Livewire (`/livewire/update`), so that request still gets a trace with the app's own middleware queries; Pulse's queries in it are left out.

## Redaction

On by default, before anything leaves the process (`redact` in the config; the app can add patterns via hello `redact`):

| What | Masked by default |
|---|---|
| Headers | `authorization`, `proxy-authorization`, `cookie`, `set-cookie`, `x-xsrf-token`, `x-csrf-token`, `php-auth-pw` |
| Session | `_token`, `*password*` |
| `.env` (`gaze()->env()`) | `APP_KEY`, `*_KEY`, `*_SECRET`, `*_PASSWORD`, `*_TOKEN` |
| Inputs, query strings, JSON bodies, log context, job properties, SQL bindings of matching columns | `*password*`, `_token` |
| Anything | `#[\SensitiveParameter]` values (`\SensitiveParameterValue`) |

## Other config keys

| Key | Env | Default |
|---|---|---|
| `token` | `GAZE_TOKEN` | sent to non-loopback hosts only |
| `relay.enabled`, `relay.path`, `relay.max_bytes` | `GAZE_RELAY` | on, `_gaze`, 1 MiB |
| `relay.hosts` | `GAZE_RELAY_HOSTS` | none (comma-separated extra hosts the relay answers) |
| `origin.skip_vendor` | | `true`: origins skip vendor frames |
| `client` | | passed to `Gaze::configure()` (limits, timeouts, `linger_ms`, `state_dir`, `logger`, …) |

A `ray.php` file is never read. `define_ray_helper` defines `ray()`/`rd()` in `boot()` only if no `ray()` exists, so it never shadows spatie/ray.

## Browser relay

`POST /_gaze` forwards a protocol v1 batch from a page that cannot reach the app itself (another device, a non-allowlisted origin) to the app's `POST /v1/batch`. It exists only while Gaze is enabled **and** `APP_ENV=local`, checked again on each request because `route:cache` keeps the route after `.env` changes. It accepts `application/json` or `text/plain` (sendBeacon) up to `relay.max_bytes`. The app trusts what the relay sends like a local process, so other sites are kept out: an `Origin` whose host is not the request's (including `null` from a sandboxed frame) gets 403, and so does a `Host` other than `localhost`, `*.localhost`, `*.test`, an IP address, `APP_URL`'s host or `relay.hosts` (`GAZE_RELAY_HOSTS`), which stops DNS rebinding. Anyone who can reach the port can still post, so a dev server listening on the LAN (`--host=0.0.0.0`, Sail) takes batches from the LAN. It never forwards incoming headers (cookies, authorization): the upstream request carries only `Content-Type`, `Accept`, `User-Agent` and, for a non-loopback app, the configured `GAZE_TOKEN`. It has no CSRF middleware and no trace.

## Octane and queue workers

The client's queue is drained after every job (`JobProcessed`/`JobFailed`) and every Octane request (`RequestTerminated`). Per-request state (traces, pending HTTP client calls, counters) is reset on `RequestReceived`/`RequestTerminated`. Workers re-read the app's watcher switches between units of work, at most every `hello_ttl` seconds.

## Differences from spatie/laravel-ray

`showX()` returns a chainable proxy. `showX($callback)` always returns the callback's result. `countQueries()` returns the callback's result. `Collection::gaze($description)` uses the description as the label. The `@ray`/`@xray`/`@measure` directives are `@gaze`/`@gazeAll`/`@gazeMeasure`. `queries` is on by default (laravel-ray had it off).

## Tests

- `vendor/bin/phpunit` starts a fake app on `127.0.0.1:23640` (`php -S`).
- `tests/EndToEnd` starts the real core (`target/debug/gaze-serve --port 23641 --no-probe --json` from the monorepo root; built with `cargo build -p gaze-core` when missing) and checks that a request with queries, a log, a mail and an exception arrives as one trace. It is skipped outside the monorepo.
- Batches are validated against `tests/schema/batch.schema.json`, a copy of the monorepo's `protocol/schema/batch.schema.json`; a test fails when the copy is stale.
- `composer.json` requires `thewonderjoy/gaze-php: ^1.0`. Inside the monorepo, the path repository `../php` provides it as version `1.0.x-dev` (`options.versions`), and `minimum-stability: dev` lets the package's own tests accept that. Both settings only apply when this package is the root project, so they do not affect apps that install it.
