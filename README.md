# thewonderjoy/gaze-laravel

See what your Laravel app is doing in the Gaze desktop app: your own `gaze()` calls, plus queries, logs, exceptions, mails, jobs and more, grouped by the request (or job, command, test) they came from.

Gaze is a fast debug window for PHP, Laravel and JavaScript (Windows first). This package builds on [`thewonderjoy/gaze-php`](https://packagist.org/packages/thewonderjoy/gaze-php).

![The Gaze window showing one Laravel request with all its queries, and an N+1 query open in the inspector with the advice to eager-load and the list of its 12 runs](art/gaze-laravel.png)

Requires PHP 8.2+ and Laravel 12 or 13. Off in production unless you set `GAZE_ENABLED=true`.

```bash
composer require --dev thewonderjoy/gaze-laravel
php artisan gaze:doctor    # checks that this app can reach Gaze
```

The service provider is discovered automatically. To change settings, publish the config: `php artisan vendor:publish --tag=gaze-config`.

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

## Traces

Everything that happens during one request ends up together in the app, with a summary at the end (status, time, memory, query count, N+1 warnings). The same goes for queued jobs, artisan commands and tests. A job shows which request dispatched it.

To get one trace per test, add the trait to your base test case:

```php
use TheWonderJoy\Gaze\Laravel\Testing\TracesTests;

abstract class TestCase extends BaseTestCase
{
    use TracesTests;
}
```

## Watchers

Each watcher can be switched in `config/gaze.php`, with an env variable, or for part of your code with `gaze()->showX()`.

| Watcher | Env | Default | Sends |
|---|---|---|---|
| exceptions | `GAZE_EXCEPTIONS` | on | reported exceptions, with code snippets and the last queries |
| logs | `GAZE_LOGS` | on | log messages (errors in red, warnings in orange) |
| mails | `GAZE_MAILS` | on | sent mails, rendered |
| dumps | `GAZE_DUMPS` | on | `dump()` and `dd()` output |
| queries | `GAZE_QUERIES` | on | SQL with bindings and time; flags duplicates, slow queries and N+1 |
| jobs | `GAZE_JOBS` | off | queued and processed jobs |
| events | `GAZE_EVENTS` | off | dispatched events |
| views | `GAZE_VIEWS` | off | rendered views and their data |
| cache | `GAZE_CACHE` | off | hits, misses, writes, deletes |
| requests | `GAZE_REQUESTS` | off | headers, payload and session on the request summary |
| http_client | `GAZE_HTTP_CLIENT` | off | outgoing HTTP calls with status and time |
| deprecations | `GAZE_DEPRECATIONS` | off | PHP deprecation notices |

Repeated queries (N+1) are folded into one row that lists every run. A watcher that is off registers nothing, so it costs nothing.

## Secrets are masked

Before anything leaves your app, passwords, tokens, cookies, `Authorization` headers and `.env` secrets (`*_KEY`, `*_SECRET`, `*_PASSWORD`, `*_TOKEN`) are replaced with a mask. This is on by default; see `redact` in the config.

## Settings

| Key | Env | Default |
|---|---|---|
| `enabled` | `GAZE_ENABLED` | on everywhere except production |
| `host` | `GAZE_HOST`, then `RAY_HOST` | found automatically: 127.0.0.1, Docker/Sail host, WSL2 host |
| `port` | `GAZE_PORT`, then `RAY_PORT` | 23517 |
| `project` | `GAZE_PROJECT` | `APP_NAME` |
| `define_ray_helper` | `GAZE_DEFINE_RAY_HELPER` | `false`; `true` makes leftover `ray()` calls go to Gaze |

`config/gaze.php` works with `php artisan config:cache`.

## Artisan commands

- `php artisan gaze:doctor`: where PHP runs (Windows, WSL2, Docker, Sail), whether Gaze is on and why, which hosts answer, and the exact `.env` lines to add.
- `php artisan gaze:clean [--dry-run]`: removes `gaze()` and `gaze_dd()` calls from `app`, `routes`, `config`, `resources`, `tests` and `database`.

## Coming from spatie/laravel-ray

The same methods exist on `gaze()` under the same names. You can keep `spatie/laravel-ray` installed next to it; Gaze also accepts Ray's messages. The Blade directives are `@gaze`, `@gazeAll` and `@gazeMeasure`. One difference: query watching is on by default, because traces and N+1 detection need it.

## Develop

This package lives in the [thewonderjoy/gaze](https://github.com/thewonderjoy/gaze) monorepo under `packages/laravel`. The `thewonderjoy/gaze-laravel` repo is a read-only copy; send changes to the monorepo.

```bash
cd packages/laravel
composer install      # uses ../php (gaze-php) from the monorepo
vendor/bin/phpunit    # starts a fake app on 127.0.0.1:23640, plus an end-to-end suite
```

The end-to-end suite runs the real Gaze server (`cargo build -p gaze-core` if it is missing), so it needs Rust. It is skipped outside the monorepo. To test against Laravel 12: `composer require --dev "orchestra/testbench:^10.0" -W`.

For a real app to click around in, see `dev/laravel-app` in the monorepo. How the package works inside: [INTERNALS.md](INTERNALS.md).

## Release

From the monorepo, tag and push. Composer reads the version from the tag.

```bash
git tag laravel/v0.2.0
git push origin laravel/v0.2.0
```

CI copies `packages/laravel` to the `gaze-laravel` repo with tag `v0.2.0`, and Packagist picks it up. If the release needs a newer `gaze-php`, release that first and raise the `thewonderjoy/gaze-php` constraint in `composer.json`. Full steps: `RELEASING.md` in the monorepo.

## License

MIT
