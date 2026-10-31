<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tracing;

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Symfony\Component\Console\Input\InputInterface;
use TheWonderJoy\Gaze\Client;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\Support\AppOrigin;
use TheWonderJoy\Gaze\Laravel\Support\Values;
use TheWonderJoy\Gaze\Laravel\Watchers\JobWatcher;
use TheWonderJoy\Gaze\Laravel\Watchers\QueryWatcher;
use TheWonderJoy\Gaze\Support\Uuid;

/**
 * Units of work: one trace per HTTP request, queued job, artisan command and test.
 *
 * - The active trace is the PHP client's trace context, so every entry (gaze() calls and
 *   watcher entries alike) carries it; nested units (a sync job inside a request, a request
 *   inside a test, Artisan::call() inside a command) get the outer trace as parent.
 * - The trace id rides into queued jobs as hidden Context ("gaze.trace"), so a worker's job
 *   links to the request, command or job that dispatched it. It is read back from the job
 *   payload with a regular expression: nothing is unserialized.
 * - Each unit is closed with one summary row (kind request, job, command or test) whose trace
 *   has ended = true: status, duration, peak memory, query statistics and counters.
 */
final class TraceManager
{
    public const CONTEXT_KEY = 'gaze.trace';

    /** @var list<Trace> */
    private array $stack = [];

    private Trace $root;

    /** @var \WeakMap<Job, Trace> */
    private \WeakMap $jobs;

    /** @var list<Trace|null> command traces, innermost last (null: ignored command) */
    private array $commands = [];

    private bool $listening = false;

    private bool $draining = false;

    public function __construct(private readonly GazeManager $gaze)
    {
        $this->root = Trace::root();
        $this->jobs = new \WeakMap();
    }

    // ------------------------------------------------------------------ stack

    /** The active trace, or the root pseudo trace outside any unit of work. */
    public function current(): Trace
    {
        return $this->stack === [] ? $this->root : $this->stack[array_key_last($this->stack)];
    }

    public function active(): ?Trace
    {
        return $this->stack === [] ? null : $this->stack[array_key_last($this->stack)];
    }

    /** @return list<Trace> */
    public function stack(): array
    {
        return $this->stack;
    }

    public function start(string $kind, string $name, ?string $parent = null, ?string $id = null, ?float $startedAt = null): Trace
    {
        $parent ??= $this->active()?->id;
        if ($id === null || !preg_match('/^[0-9a-f]{32}$/', $id)) {
            $id = Uuid::traceId();
        }
        $trace = new Trace($id, $kind, $name, $parent, $startedAt);
        $this->stack[] = $trace;
        $this->sync();

        return $trace;
    }

    /**
     * Sends the summary row (trace.ended = true) and, with $pop, closes the trace. A trace that
     * stays open after its summary (HTTP: until the kernel terminates) keeps tagging late entries.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $modifiers
     */
    public function finish(Trace $trace, string $kind, array $body, array $modifiers = [], bool $pop = true): void
    {
        if (!$trace->ended) {
            $trace->ended = true;
            if ($this->gaze->listening(QueryWatcher::class)) {
                $this->gaze->watcher(QueryWatcher::class)->flushGroups($trace);
            }
            $body += ['duration_ms' => $trace->elapsedMs(), 'memory_peak' => memory_get_peak_usage(true)] + $trace->stats();
            $this->gaze->send($kind, $body, $modifiers + ['trace' => $trace->context(true)]);
        }
        if ($pop) {
            $this->pop($trace);
        }
    }

    public function pop(Trace $trace): void
    {
        $i = array_search($trace, $this->stack, true);
        if ($i !== false) {
            array_splice($this->stack, (int) $i);
            $this->sync();
        }
    }

    public function reset(): void
    {
        $this->stack = [];
        $this->commands = [];
        $this->root = Trace::root();
        $this->jobs = new \WeakMap();
        $this->sync();
    }

    private function sync(): void
    {
        $active = $this->active();
        Gaze::setTraceContext($active?->context());
        try {
            $context = $this->gaze->app->make(\Illuminate\Log\Context\Repository::class);
            if ($active !== null) {
                $context->addHidden(self::CONTEXT_KEY, $active->id);
            } else {
                $context->forgetHidden(self::CONTEXT_KEY);
            }
        } catch (\Throwable) {
        }
    }

    // ------------------------------------------------------------------ listeners

    public function listen(): void
    {
        if ($this->listening) {
            return;
        }
        $this->listening = true;
        $events = $this->gaze->events();
        $config = fn (string $key) => GazeManager::bool($this->gaze->config('traces.' . $key, true));

        if ($config('http')) {
            $this->registerMiddleware();
            $events->listen(RequestHandled::class, $this->onRequestHandled(...));
        }
        if ($config('jobs')) {
            $events->listen(JobQueued::class, $this->onJobQueued(...));
            $events->listen(JobProcessing::class, $this->onJobProcessing(...));
            $events->listen(JobProcessed::class, $this->onJobProcessed(...));
            $events->listen(JobFailed::class, $this->onJobFailed(...));
            $events->listen(JobReleasedAfterException::class, $this->onJobReleased(...));
        } else {
            $this->listenForDrain();
        }
        if ($config('commands')) {
            $events->listen(CommandStarting::class, $this->onCommandStarting(...));
            $events->listen(CommandFinished::class, $this->onCommandFinished(...));
        }
        $this->listenForOctane();
    }

    /** Without job traces: still drain the client after each job (queue workers never exit). */
    public function listenForDrain(): void
    {
        if ($this->draining) {
            return;
        }
        $this->draining = true;
        $events = $this->gaze->events();
        $drain = static function (): void {
            Client::current()?->drain();
        };
        $events->listen(JobProcessed::class, $drain);
        $events->listen(JobFailed::class, $drain);
        $this->listenForOctane();
    }

    private bool $octane = false;

    private function listenForOctane(): void
    {
        if ($this->octane || !class_exists(\Laravel\Octane\Events\RequestReceived::class)) {
            return;
        }
        $this->octane = true;
        $events = $this->gaze->events();
        $events->listen(\Laravel\Octane\Events\RequestReceived::class, function (): void {
            $this->gaze->resetRequestState();
            $this->gaze->refreshRemote();
        });
        $events->listen(\Laravel\Octane\Events\RequestTerminated::class, function (): void {
            Client::current()?->drain();
            $this->gaze->resetRequestState();
        });
    }

    private function registerMiddleware(): void
    {
        $add = static function ($kernel): void {
            if ($kernel instanceof \Illuminate\Foundation\Http\Kernel && !$kernel->hasMiddleware(TraceRequests::class)) {
                $kernel->prependMiddleware(TraceRequests::class);
            }
        };
        $app = $this->gaze->app;
        if ($app->resolved(HttpKernel::class)) {
            $add($app->make(HttpKernel::class));
        } else {
            $app->afterResolving(HttpKernel::class, $add);
        }
    }

    // ------------------------------------------------------------------ HTTP

    public function ignoresRequest(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        $relay = trim((string) $this->gaze->config('relay.path', '_gaze'), '/');

        return $path === $relay || GazeManager::matchesAny($path, (array) $this->gaze->config('traces.ignore_paths', []));
    }

    /** Opens the trace of an HTTP request (continuing an incoming W3C traceparent). */
    public function startRequest(Request $request): Trace
    {
        $name = $request->getMethod() . ' /' . ltrim($request->path(), '/');
        $id = null;
        $parent = null;
        $header = (string) $request->headers->get('traceparent', '');
        if ($header !== '' && GazeManager::bool($this->gaze->config('traces.accept_traceparent', true))
            && preg_match('/^[0-9a-f]{2}-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}$/', strtolower(trim($header)), $m)
            && $m[1] !== str_repeat('0', 32)) {
            $id = $m[1];
            $parent = $m[2];
        }
        $trace = $this->start('http', $name, $parent, $id);
        $request->attributes->set('_gaze_trace', $trace);

        return $trace;
    }

    public function onRequestHandled(RequestHandled $event): void
    {
        $request = $event->request;
        $trace = $request->attributes->get('_gaze_trace');
        $opened = false;
        if (!$trace instanceof Trace) {
            if ($this->ignoresRequest($request)) {
                return;
            }
            $trace = $this->startRequest($request); // the middleware did not run (custom kernel)
            $opened = true;
        }
        if ($trace->ended) {
            return;
        }
        $body = $this->gaze->requestSummary($request, $event->response);
        $status = (int) ($body['status'] ?? 0);
        $mods = [];
        if ($status >= 500) {
            $mods['color'] = 'red';
        } elseif ($status >= 400) {
            $mods['color'] = 'orange';
        }
        $route = $request->route();
        $action = $route instanceof \Illuminate\Routing\Route ? ($route->getAction('uses') ?? null) : null;
        $origin = AppOrigin::of($action);
        if ($origin !== null) {
            $mods['origin'] = $origin;
        }
        $this->finish($trace, 'request', $body, $mods, $opened);
    }

    // ------------------------------------------------------------------ jobs

    public function onJobQueued(JobQueued $event): void
    {
        $this->current()->count('jobs');
    }

    public function onJobProcessing(JobProcessing $event): void
    {
        $this->gaze->refreshRemote();
        $job = $event->job;
        $payload = self::payload($job);
        $name = (string) ($payload['displayName'] ?? $job->resolveName());
        $parent = self::parentFromPayload($payload) ?? $this->active()?->id;
        $this->jobs[$job] = $this->start('job', $name, $parent);
    }

    public function onJobProcessed(JobProcessed $event): void
    {
        $this->endJob($event->job, 'JobProcessed', null);
    }

    public function onJobFailed(JobFailed $event): void
    {
        $this->endJob($event->job, 'JobFailed', $event->exception);
    }

    public function onJobReleased(JobReleasedAfterException $event): void
    {
        $this->endJob($event->job, 'JobReleasedAfterException', null);
    }

    private function endJob(Job $job, string $event, ?\Throwable $exception): void
    {
        $trace = $this->jobs[$job] ?? null;
        if ($trace instanceof Trace) {
            unset($this->jobs[$job]);
            $body = JobWatcher::body($this->gaze, $job, $event, $exception);
            $body['status'] = $event === 'JobProcessed' ? 0 : 1;
            $mods = ['color' => match ($event) {
                'JobFailed' => 'red',
                'JobReleasedAfterException' => 'orange',
                default => 'green',
            }];
            $origin = AppOrigin::of((string) ($body['name'] ?? ''), 'handle');
            if ($origin !== null) {
                $mods['origin'] = $origin;
            }
            $this->finish($trace, 'job', $body, $mods);
        }
        Client::current()?->drain(); // workers never exit: deliver what this job produced now
    }

    /** @return array<string, mixed> */
    public static function payload(Job $job): array
    {
        try {
            $payload = $job->payload();
        } catch (\Throwable) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }

    /** The dispatching trace id from the job's hidden Context (serialized string; parsed, not unserialized). */
    public static function parentFromPayload(array $payload): ?string
    {
        $value = $payload['illuminate:log:context']['hidden'][self::CONTEXT_KEY] ?? null;
        if (is_string($value) && preg_match('/^s:32:"([0-9a-f]{32})";$/', $value, $m)) {
            return $m[1];
        }

        return null;
    }

    // ------------------------------------------------------------------ commands

    public function onCommandStarting(CommandStarting $event): void
    {
        $name = $event->command;
        if (!is_string($name) || $name === '' || GazeManager::matchesAny($name, (array) $this->gaze->config('traces.ignore_commands', []))) {
            $this->commands[] = null;

            return;
        }
        $trace = $this->start('command', $name);
        $trace->meta['input'] = $this->commandInput($event->input);
        $this->commands[] = $trace;
    }

    public function onCommandFinished(CommandFinished $event): void
    {
        if ($this->commands === []) {
            return;
        }
        $trace = array_pop($this->commands);
        if (!$trace instanceof Trace) {
            return;
        }
        $body = ['name' => (string) $event->command, 'input' => $trace->meta['input'] ?? '', 'exit_code' => $event->exitCode, 'status' => $event->exitCode];
        $this->finish($trace, 'command', $body, $event->exitCode === 0 ? [] : ['color' => 'red']);
    }

    private function commandInput(InputInterface $input): string
    {
        try {
            $text = method_exists($input, '__toString') ? (string) $input : '';
        } catch (\Throwable) {
            return '';
        }
        $redactor = $this->gaze->redactor();

        return (string) preg_replace_callback(
            '/(--([\w-]+)=)(\'(?:[^\']|\\\\\')*\'|"[^"]*"|\S+)/',
            fn ($m) => $redactor->matches('inputs', $m[2]) || $redactor->matches('env', $m[2]) ? $m[1] . $redactor->mask() : $m[0],
            $text,
        );
    }

    // ------------------------------------------------------------------ tests

    public function startTest(string $name): Trace
    {
        return $this->start('test', $name);
    }

    public function finishTest(Trace $trace, string $result, bool $success): void
    {
        $this->finish($trace, 'test', ['name' => $trace->name, 'result' => $result, 'status' => $success ? 0 : 1], $success ? ['color' => 'green'] : ['color' => 'red']);
        Client::current()?->flush();
    }

    public static function nowMs(): float
    {
        return Values::nowMs();
    }
}
