<?php

// Gaze for Laravel (thewonderjoy/gaze-laravel).
//
// Everything here can be set from .env. The file lives in Laravel's config repository, so
// `php artisan config:cache` stores the resolved values. A ray.php file is never read.

return [

    // null (the default): on in every environment except production. GAZE_ENABLED overrides.
    // When off, nothing of the package runs: gaze() returns a no-op (gaze_dd() doesn't exit),
    // and no listener, middleware, route or dump handler is registered.
    'enabled' => env('GAZE_ENABLED'),

    // Where the Gaze app listens. Unset: RAY_HOST / RAY_PORT, then discovery (127.0.0.1;
    // host.docker.internal under Docker or Sail; the WSL2 gateway). `php artisan gaze:doctor`
    // probes every candidate and prints the .env lines to use.
    'host' => env('GAZE_HOST', env('RAY_HOST')),
    'port' => env('GAZE_PORT', env('RAY_PORT', 23517)),

    // Bearer token, sent to non-loopback hosts only (the app shows it when it listens beyond loopback).
    'token' => env('GAZE_TOKEN'),

    // Project name shown in the app (default: APP_NAME). The project root is base_path().
    'project' => env('GAZE_PROJECT'),

    // Watchers switched on at boot. The app can switch any of them for every process
    // (web, queue workers, scheduler, tests) through GET /v1/hello "watchers"; see remote_watchers.
    'watchers' => [
        'exceptions' => env('GAZE_EXCEPTIONS', true),
        'logs' => env('GAZE_LOGS', true),
        'mails' => env('GAZE_MAILS', true),
        'dumps' => env('GAZE_DUMPS', true),

        // The query switches add up: a query is sent when any of them matches it. `queries`
        // sends every query, so while it is on the six below change nothing (false does not hide
        // inserts). To see only some queries, turn `queries` off and switch those on.
        'queries' => env('GAZE_QUERIES', true),
        'duplicate_queries' => env('GAZE_DUPLICATE_QUERIES', false),
        'slow_queries' => env('GAZE_SLOW_QUERIES', false),
        'insert_queries' => env('GAZE_INSERT_QUERIES', false),
        'select_queries' => env('GAZE_SELECT_QUERIES', false),
        'update_queries' => env('GAZE_UPDATE_QUERIES', false),
        'delete_queries' => env('GAZE_DELETE_QUERIES', false),

        'jobs' => env('GAZE_JOBS', false),
        'events' => env('GAZE_EVENTS', false),
        'views' => env('GAZE_VIEWS', false),
        'cache' => env('GAZE_CACHE', false),
        'requests' => env('GAZE_REQUESTS', false),
        'http_client' => env('GAZE_HTTP_CLIENT', false),
        'deprecations' => env('GAZE_DEPRECATIONS', false),
    ],

    // Honour the app's watcher switches and redaction list (GET /v1/hello, cached for 60 s
    // across processes; long-running workers re-read it between jobs and Octane requests).
    'remote_watchers' => env('GAZE_REMOTE_WATCHERS', true),

    'queries' => [
        // A query at or above this many milliseconds is flagged "slow" (slow_queries watcher). A blank
        // GAZE_SLOW_QUERY_MS= counts as unset.
        'slow_threshold_ms' => env('GAZE_SLOW_QUERY_MS', '') !== '' ? env('GAZE_SLOW_QUERY_MS') : env('RAY_SLOW_QUERY_THRESHOLD_IN_MS', 500),

        // The same SQL shape (fingerprint) run more than this many times in one trace is an N+1.
        'n_plus_one_threshold' => env('GAZE_N_PLUS_ONE_THRESHOLD', '') !== '' ? env('GAZE_N_PLUS_ONE_THRESHOLD') : 3,

        // Fold an N+1 into its first entry (body.executions[]) instead of one row per run.
        'group_n_plus_one' => env('GAZE_GROUP_N_PLUS_ONE', true),

        // Runs kept per grouped entry (the count keeps going).
        'max_executions' => 200,

        // Queries run by code in these namespaces are left out: Telescope and Pulse write what
        // they recorded at the end of every request. Matched against the call stack.
        'ignore_namespaces' => ['Laravel\Telescope\\', 'Laravel\Pulse\\'],
    ],

    'traces' => [
        // One trace per HTTP request, queued job, artisan command (and test, with the TracesTests trait).
        'enabled' => env('GAZE_TRACES', true),
        'http' => true,
        'jobs' => true,
        'commands' => true,

        // Link a request to the call that sent its W3C traceparent (a gaze-js page or server).
        'accept_traceparent' => true,

        // Request paths (Str::is patterns) Gaze ignores: no trace, and the watchers send nothing
        // for them, not even from terminating callbacks (gaze() calls still arrive). The
        // Telescope, Horizon and Pulse dashboards poll these.
        'ignore_paths' => ['_gaze', 'telescope*', 'horizon*', '_debugbar*', '_ignition*', 'pulse*', 'favicon.ico'],

        // Long-running or noisy commands that get no trace (jobs they run still get their own).
        'ignore_commands' => [
            'queue:work', 'queue:listen', 'horizon', 'horizon:*', 'schedule:work', 'schedule:run',
            'octane:*', 'reverb:start', 'serve', 'pail', 'tinker', 'package:discover', 'list', 'help',
            'gaze:*', 'vendor:publish',
        ],
    ],

    // Masked before anything leaves the process (the app can add patterns via hello "redact").
    // Patterns are case-insensitive, * matches any run of characters.
    'redact' => [
        'mask' => '********',
        'headers' => ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'x-xsrf-token', 'x-csrf-token', 'php-auth-pw'],
        'session' => ['_token', '*password*'],
        'env' => ['APP_KEY', '*_KEY', '*_SECRET', '*_PASSWORD', '*_TOKEN'],
        'inputs' => ['*password*', '_token'],
    ],

    // Opt-in ray()/rd() for code written against spatie/laravel-ray. Defined in boot() only when
    // no ray() exists yet, so it never shadows spatie/ray.
    'define_ray_helper' => env('GAZE_DEFINE_RAY_HELPER', false),

    // POST /<path> forwards batches from pages that cannot reach the app directly
    // (@thewonderjoy/gaze-js). Registered only when Gaze is enabled and APP_ENV=local. It answers
    // requests for localhost, *.localhost, *.test, IP addresses, APP_URL's host and `hosts`
    // (comma-separated), so a page on another site cannot reach it through DNS rebinding.
    'relay' => [
        'enabled' => env('GAZE_RELAY', true),
        'path' => '_gaze',
        'max_bytes' => 1048576,
        'hosts' => env('GAZE_RELAY_HOSTS', ''),
    ],

    // Entry origins skip framework and vendor frames, so they point at the first app frame.
    'origin' => [
        'skip_vendor' => true,
    ],

    // Passed to the PHP client as is (Gaze::configure()): limits, connect_timeout_ms, linger_ms,
    // drain_ms, state_dir, logger, origin_frames, ...
    'client' => [],

];
