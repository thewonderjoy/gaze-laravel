<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\Support\SerializedValue;
use TheWonderJoy\Gaze\Laravel\Support\Values;
use TheWonderJoy\Gaze\Laravel\Tracing\TraceManager;

/**
 * Queue job events (kind job): JobQueued in the dispatching process, JobProcessing in the worker.
 * JobProcessed / JobFailed / JobReleasedAfterException are the summary rows of the job's trace;
 * this watcher sends them itself only when job traces are off.
 *
 * The job is described from its payload (displayName, queue, attempts, tries, timeout, ...) and
 * the serialized command is read without unserialize(): models stay ModelIdentifiers, nothing is
 * re-fetched, encrypted jobs show as "(encrypted)".
 */
final class JobWatcher extends Watcher
{
    protected function register(Dispatcher $events): void
    {
        $events->listen(JobQueued::class, $this->queued(...));
        $events->listen(JobProcessing::class, fn (JobProcessing $e) => $this->event($e->job, 'JobProcessing'));
        $events->listen(JobProcessed::class, fn (JobProcessed $e) => $this->terminal($e->job, 'JobProcessed', null));
        $events->listen(JobFailed::class, fn (JobFailed $e) => $this->terminal($e->job, 'JobFailed', $e->exception));
        $events->listen(JobReleasedAfterException::class, fn (JobReleasedAfterException $e) => $this->terminal($e->job, 'JobReleasedAfterException', null));
    }

    public function queued(JobQueued $event): void
    {
        if (!$this->on('jobs') || GazeManager::sending()) {
            return;
        }
        $body = GazeManager::quietly(function () use ($event): array {
            $payload = [];
            if (is_string($event->payload ?? null)) {
                $decoded = json_decode($event->payload, true);
                $payload = is_array($decoded) ? $decoded : [];
            }
            $name = (string) ($payload['displayName'] ?? (is_object($event->job) ? $event->job::class : (is_string($event->job) ? $event->job : 'Closure')));
            $props = [
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'uuid' => $payload['uuid'] ?? null,
                'id' => is_scalar($event->id) ? $event->id : null,
                'tries' => $payload['maxTries'] ?? null,
                'timeout' => $payload['timeout'] ?? null,
                'delay' => is_scalar($event->delay) ? $event->delay : (is_object($event->delay) ? get_debug_type($event->delay) : null),
                'data' => $payload ? self::data($this->gaze, $payload) : null,
            ];

            return ['event' => 'JobQueued', 'name' => $name, 'job' => Values::obj($name, $props), 'exception' => null];
        });
        $this->send('job', $body, ['color' => 'gray']);
    }

    private function event(Job $job, string $event): void
    {
        if (!$this->on('jobs') || GazeManager::sending()) {
            return;
        }
        $this->send('job', GazeManager::quietly(fn () => self::body($this->gaze, $job, $event, null)), ['color' => 'blue']);
    }

    private function terminal(Job $job, string $event, ?\Throwable $exception): void
    {
        if (GazeManager::bool($this->gaze->config('traces.enabled', true)) && GazeManager::bool($this->gaze->config('traces.jobs', true))) {
            return; // the job trace's summary row carries this
        }
        if (!$this->on('jobs') || GazeManager::sending()) {
            return;
        }
        $body = GazeManager::quietly(fn () => self::body($this->gaze, $job, $event, $exception));
        $this->send('job', $body, ['color' => $event === 'JobFailed' ? 'red' : ($event === 'JobProcessed' ? 'green' : 'orange')]);
    }

    /** @return array<string, mixed> IPC contract "job" body: event, job (obj Value), exception, plus name and queue facts */
    public static function body(GazeManager $gaze, Job $job, string $event, ?\Throwable $exception): array
    {
        $payload = TraceManager::payload($job);
        $name = (string) ($payload['displayName'] ?? $job->resolveName());
        $props = [];
        foreach ([
            'connection' => static fn () => $job->getConnectionName(),
            'queue' => static fn () => $job->getQueue(),
            'uuid' => static fn () => $job->uuid(),
            'id' => static fn () => $job->getJobId(),
            'attempts' => static fn () => $job->attempts(),
            'tries' => static fn () => $job->maxTries(),
            'max_exceptions' => static fn () => $job->maxExceptions(),
            'timeout' => static fn () => $job->timeout(),
        ] as $key => $read) {
            try {
                $value = $read();
                $props[$key] = is_scalar($value) ? $value : null;
            } catch (\Throwable) {
            }
        }
        if (isset($payload['backoff'])) {
            $props['backoff'] = $payload['backoff'];
        }
        if (!empty($payload['tags'])) {
            $props['tags'] = $payload['tags'];
        }
        if (isset($payload['pushedAt'])) {
            $props['pushed_at'] = $payload['pushedAt'];
        }
        $props['data'] = $payload ? self::data($gaze, $payload) : null;

        return [
            'event' => $event,
            'name' => $name,
            'job' => Values::obj($name, $props),
            'exception' => $exception === null ? null : Values::error($exception),
            'queue' => $props['queue'] ?? null,
            'connection' => $props['connection'] ?? null,
            'attempts' => $props['attempts'] ?? null,
        ];
    }

    /**
     * The payload's data with the serialized command read safely (or marked encrypted).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> Value node
     */
    public static function data(GazeManager $gaze, array $payload): array
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            return Values::from($data);
        }
        $command = $data['command'] ?? null;
        $entries = [];
        foreach ($data as $key => $value) {
            if ($key === 'command' && is_string($command)) {
                if (!preg_match('/^[OCa]:\d+:/', $command)) {
                    $entries[] = ['command', ['k' => 'const', 'v' => '(encrypted)']];
                    continue;
                }
                $redactor = $gaze->redactor();
                $parsed = SerializedValue::parse($command, static fn (string $name): bool => $redactor->matches('inputs', $name));
                $entries[] = ['command', $parsed ?? Values::from(Values::cut($command, 2000)[0])];
                continue;
            }
            $entries[] = [(string) $key, Values::from($value)];
        }

        return ['k' => 'map', 'count' => count($entries), 'entries' => $entries];
    }
}
