# thewonderjoy/gaze-laravel

See what your Laravel app is doing in the Gaze desktop app. You get your own `gaze()` calls plus queries, logs, exceptions, mails, jobs and more, grouped by the request, job, command or test they came from.

Gaze is a desktop debug window for PHP, Laravel and JavaScript apps, on Mac, Windows and Linux. This package builds on [`thewonderjoy/gaze-php`](https://packagist.org/packages/thewonderjoy/gaze-php).

**Get the app:** download Gaze from [thewonderjoy.com/products/gaze](https://thewonderjoy.com/products/gaze).

![The Gaze window showing one Laravel request with all its queries, and an N+1 query open in the inspector with the advice to eager-load and the list of its 12 runs, shown half in the light theme and half in the dark theme](https://raw.githubusercontent.com/thewonderjoy/gaze-laravel/main/art/gaze-laravel.png)

## Install

```bash
composer require thewonderjoy/gaze-laravel
```

It needs PHP 8.2+ and Laravel 12 or 13. Laravel finds the service provider by itself. Open the [Gaze app](https://thewonderjoy.com/products/gaze), then check that your app can reach it:

```bash
php artisan gaze:doctor
```

If it can't, for example under Sail, Docker or WSL, or because Gaze runs on another port, the command prints the `.env` lines to add. To change settings, publish the config with `php artisan vendor:publish --tag=gaze-config`.

### Normal or `--dev`?

Install it as a normal dependency, as above. `--dev` keeps the package off your servers, but then a call you forgot to remove breaks there:

| | `composer require` | `composer require --dev` |
|---|---|---|
| In production | Installed but off. Only its service provider and a no-op load, and nothing is registered or sent. | Not installed (`composer install --no-dev`) |
| A `gaze()` or `gaze_dd()` you forgot | Does nothing. `gaze_dd()` doesn't exit. | Fatal error: `Call to undefined function gaze()` |
| A `->gaze()` you forgot on a collection, string or query | Does nothing | `BadMethodCallException` |
| A `@gaze` you forgot in a view | Renders nothing | Shows up as `@gaze(...)` text on the page |
| Before you deploy | Nothing to do | Run `php artisan gaze:clean` to remove `gaze()` and `gaze_dd()` calls. Remove `->gaze()` and `@gaze` by hand. |

### Environments

Gaze is off when `APP_ENV=production` and on everywhere else, staging and testing included. Set `GAZE_ENABLED=false` on servers where you don't want it. To use it in production for a while, set `GAZE_ENABLED=true`.

## Use

```php
gaze('Hello', $user);                       // everything from gaze-php works
gaze()->model($order)->green();             // an Eloquent model with its relations
gaze()->mailable(new OrderShipped($order)); // a rendered mail
gaze()->showQueries();                      // show queries from here on
$total = gaze()->countQueries(fn () => $report->build());

collect($rows)->gaze('rows')->map(...);     // on collections, strings, query builders
User::where('active', true)->gaze()->get(); // the SQL with bindings, before it runs
$this->get('/orders')->gaze()->assertOk();  // on test responses
```

```blade
@gaze($order)
@gazeAll        {{-- every variable in this view --}}
@gazeMeasure    {{-- stopwatch --}}
```

## Methods

`gaze()` has every method of [gaze-php](https://packagist.org/packages/thewonderjoy/gaze-php): values, colours, tables, `pause()`, `measure()`, `count()`, screens and the rest. This package adds the Laravel ones below.

**Laravel values**

| Method | What it shows |
|---|---|
| `model(...$models)` | Eloquent models with their attributes and loaded relations, one entry per model. Collections are flattened. |
| `models($collection)` | The same, for a collection. |
| `mailable(...$mailables)` | The rendered mail with subject, from, to, cc and bcc. |
| `view($view)` | A view's name, path and data. |
| `context(...$keys)` | Laravel Context data: all of it, or the keys you name. |
| `hiddenContext(...$keys)` | Hidden Context data. |
| `env($only = null, $file = null)` | The `.env` file as a table, with secrets masked. |
| `testResponse($response)` | A test response: status, headers, body and decoded JSON. |
| `countQueries(fn () => ...)` | Runs the closure, shows how many queries it ran and how long they took, and returns its result. |

**Turn a watcher on for part of your code**

Each watcher in the list below has three methods. With queries as the example:

| Method | What it does |
|---|---|
| `showQueries()` | Turns the watcher on from here. It returns an object you can style: `gaze()->showQueries()->red()`. |
| `showQueries(fn () => ...)` | Turns it on only while the closure runs, and returns the closure's result. |
| `stopShowingQueries()` | Turns it off. |

| Watcher | Sends |
|---|---|
| `Queries` | every query |
| `DuplicateQueries`, `SlowQueries($ms)` | only repeated queries, or only queries that take `$ms` or longer (default `GAZE_SLOW_QUERY_MS`, 500) |
| `SelectQueries`, `InsertQueries`, `UpdateQueries`, `DeleteQueries` | only that type of query |
| `Events`, `Exceptions`, `Jobs`, `Cache`, `Views`, `Mails`, `Dumps`, `Logs`, `Deprecations` | that watcher's entries |
| `Requests`, `HttpClientRequests` | incoming request details, or outgoing HTTP calls |

Short forms: `queries()`, `slowQueries()`, `events()`, `jobs()`, `views()`, `requests()`, `httpClientRequests()`.

To send only some queries, pass a condition: `showConditionalQueries(fn (QueryExecuted $query) => ..., $callback = null, $name = 'default')`. Stop it with `stopShowingConditionalQueries($name)`.

**On other objects**

| Call | Sends |
|---|---|
| `collect($rows)->gaze($label = '')` | the collection's items |
| `str($text)->gaze($label = '')` | the string |
| `User::where(...)->gaze($label = '')` | the SQL with bindings, before it runs |
| `$this->get('/')->gaze()` | the test response, as `testResponse()` does |

Each one returns the object you called it on, so you can keep chaining.

**Blade**

| Directive | Sends |
|---|---|
| `@gaze($a, $b)` | the values |
| `@gazeAll` | every variable in the view |
| `@gazeMeasure`, `@gazeMeasure('name')` | a stopwatch, like `measure()` |

## Traces

Everything that happens during one request ends up together in the app, closed by a summary with the status, time, memory, query count and any N+1 warnings. Queued jobs, artisan commands and tests work the same way, and a job shows which request dispatched it.

A request sent from the browser links back to the call that sent it, so its trace shows `from browser POST /api/cart`. Gaze reads the W3C `traceparent` header for this, which [gaze-js](https://github.com/thewonderjoy/gaze-js#link-browser-calls-to-server-requests) adds to the page's `fetch` and XHR calls once its network capture is on. There's nothing to set up on the Laravel side. To turn it off, set `traces.accept_traceparent` to `false` in `config/gaze.php`.

To get one trace per test, add the trait to your base test case:

```php
use TheWonderJoy\Gaze\Laravel\Testing\TracesTests;

abstract class TestCase extends BaseTestCase
{
    use TracesTests;
}
```

## Watchers

You can switch each watcher in `config/gaze.php`, with an env variable, or for part of your code with `gaze()->showX()`.

| Watcher | Env | Default | Sends |
|---|---|---|---|
| exceptions | `GAZE_EXCEPTIONS` | on | reported exceptions, with code snippets and the last queries |
| logs | `GAZE_LOGS` | on | log messages, errors in red and warnings in orange |
| mails | `GAZE_MAILS` | on | sent mails, rendered |
| dumps | `GAZE_DUMPS` | on | `dump()` and `dd()` output |
| queries | `GAZE_QUERIES` | on | SQL with bindings and time, flagging duplicates, slow queries and N+1 |
| jobs | `GAZE_JOBS` | off | queued and processed jobs |
| events | `GAZE_EVENTS` | off | dispatched events |
| views | `GAZE_VIEWS` | off | rendered views and their data |
| cache | `GAZE_CACHE` | off | hits, misses, writes, deletes |
| requests | `GAZE_REQUESTS` | off | headers, payload and session, on the request summary |
| http_client | `GAZE_HTTP_CLIENT` | off | outgoing HTTP calls with status and time |
| deprecations | `GAZE_DEPRECATIONS` | off | PHP deprecation notices |

When the same query runs again and again (N+1), the app folds the runs into one row that lists each of them. A watcher that is off registers nothing, so it costs nothing.

The query switches add up: a query is sent when any of them matches it. `queries` sends every query, so while it's on, the switches below change nothing. `GAZE_INSERT_QUERIES=false` doesn't hide inserts. To see only some queries, turn `queries` off and switch on the ones you want:

| Env | Sends |
|---|---|
| `GAZE_DUPLICATE_QUERIES` | a query that already ran with the same bindings in this request |
| `GAZE_SLOW_QUERIES` | queries that take `GAZE_SLOW_QUERY_MS` or longer (default 500) |
| `GAZE_SELECT_QUERIES`, `GAZE_INSERT_QUERIES`, `GAZE_UPDATE_QUERIES`, `GAZE_DELETE_QUERIES` | that kind of statement |

For example, `GAZE_QUERIES=false` with `GAZE_SLOW_QUERIES=true` sends only slow queries. Request summaries still count every query while any query switch is on, but N+1 detection only sees the queries that are sent. With every query switch off, request summaries show no query count and exceptions don't list the last queries.

## Secrets are masked

Before anything leaves your app, Gaze masks passwords, tokens, cookies, `Authorization` headers and `.env` secrets (`*_KEY`, `*_SECRET`, `*_PASSWORD`, `*_TOKEN`). This is on by default. See `redact` in the config.

## Settings

| Key | Env | Default |
|---|---|---|
| `enabled` | `GAZE_ENABLED` | on everywhere except production |
| `host` | `GAZE_HOST` | found automatically: 127.0.0.1, the Docker or Sail host, the WSL2 host |
| `port` | `GAZE_PORT` | 23517 |
| `project` | `GAZE_PROJECT` | `APP_NAME` |

`config/gaze.php` works with `php artisan config:cache`.

## Artisan commands

- `php artisan gaze:doctor` shows where PHP runs (Windows, WSL2, Docker, Sail), whether Gaze is on and why, which hosts answer, and the exact `.env` lines to add.
- `php artisan gaze:clean [--dry-run]` removes `gaze()` and `gaze_dd()` calls from `app`, `routes`, `config`, `resources`, `tests` and `database`.

## Ray compatibility

You can keep `spatie/laravel-ray` installed next to this package, because the Gaze app also accepts Ray's messages. To move over, replace `ray(` with `gaze(`. The method names are the same, apart from these:

| laravel-ray | Gaze |
|---|---|
| `collect()->ray()`, `str()->ray()`, `$query->ray()`, `$response->ray()` | `->gaze()` |
| `@ray`, `@xray`, `@measure` | `@gaze`, `@gazeAll`, `@gazeMeasure` |
| `ray($model)`, `ray($mailable)` | `gaze()->model($model)`, `gaze()->mailable($mail)`. Plain `gaze($model)` shows an object dump, not the model view. |
| `php artisan ray:publish-config` | `php artisan vendor:publish --tag=gaze-config` |
| `php artisan ray:clean` | `php artisan gaze:clean` |
| `loggedMail()` | not yet |
| query watching is off by default | on by default, because traces and N+1 detection need it |

The gaze-php README lists the Ray methods it doesn't have.

If you'd rather not rename your calls, remove laravel-ray and set `GAZE_DEFINE_RAY_HELPER=true` (`define_ray_helper` in the config). Your `ray()` calls then go to Gaze. A call to one of the missing methods throws an error.

`RAY_HOST` and `RAY_PORT` are read when `GAZE_HOST` and `GAZE_PORT` aren't set.

## License

MIT
