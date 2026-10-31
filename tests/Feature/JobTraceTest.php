<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\FailingJob;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\RecordJob;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\Schema;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\User;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;
use TheWonderJoy\Gaze\Laravel\Tracing\TraceManager;

final class JobTraceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create();
        Schema::seed(1, 0);
        $this->resetCapture();
    }

    private function useDatabaseQueue(): void
    {
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('queue.connections.database', [
            'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90,
        ]);
    }

    /** @return array<string, list<array<string, mixed>>> entries grouped by trace kind */
    private function byTraceKind(): array
    {
        $out = [];
        foreach ($this->entries() as $e) {
            $out[$e['trace']['kind'] ?? 'none'][] = $e;
        }

        return $out;
    }

    public function testAQueuedJobLinksToTheRequestThatDispatchedItThroughHiddenContext(): void
    {
        $this->useDatabaseQueue();
        Route::post('/records', function () {
            RecordJob::dispatch(User::first(), 'hello');

            return response('queued', 202);
        });

        $this->post('/records')->assertStatus(202);
        $request = $this->added('request')[0];
        $requestTraceId = $request['trace']['id'];
        $this->assertSame(1, $request['body']['jobs'], 'the summary counts dispatched jobs');

        // the payload carries the trace id as hidden Context (a serialized string, never unserialized by Gaze)
        $payload = json_decode((string) DB::table('jobs')->value('payload'), true);
        $this->assertSame(serialize($requestTraceId), $payload['illuminate:log:context']['hidden'][TraceManager::CONTEXT_KEY]);
        $this->assertSame($requestTraceId, TraceManager::parentFromPayload($payload));

        // a worker (queue:work is not traced itself) picks it up later: the job opens its own trace
        $this->resetCapture();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);
        $this->assertSame(0, DB::table('jobs')->count());

        $groups = $this->byTraceKind();
        $this->assertArrayNotHasKey('command', $groups);
        $job = $groups['job'];
        $traceIds = array_unique(array_map(static fn ($e) => $e['trace']['id'], $job));
        $this->assertCount(1, $traceIds);
        foreach ($job as $e) {
            $this->assertSame($requestTraceId, $e['trace']['parent'], 'job trace parent = dispatching request');
            $this->assertSame(RecordJob::class, $e['trace']['name']);
        }
        $summary = array_values(array_filter($job, static fn ($e) => ($e['trace']['ended'] ?? false) === true))[0];
        $this->assertSame('job', $summary['kind']);
        $this->assertSame('JobProcessed', $summary['body']['event']);
        $this->assertSame(0, $summary['body']['status']);
        $this->assertSame('database', $summary['body']['connection']);
        $this->assertSame('green', $summary['color']);
        $this->assertSame(RecordJob::class, $summary['body']['job']['cls']);
        $this->assertStringEndsWith('RecordJob.php', $summary['origin']['file']);

        // the serialized command is read without unserialize(): the model stays a ModelIdentifier, secrets are masked
        $props = [];
        foreach ($summary['body']['job']['props'] as $p) {
            $props[$p['n']] = $p['val'];
        }
        $command = null;
        foreach ($props['data']['entries'] as [$key, $value]) {
            if ($key === 'command') {
                $command = $value;
            }
        }
        $this->assertSame('obj', $command['k']);
        $this->assertSame(RecordJob::class, $command['cls']);
        $commandProps = array_column($command['props'], 'val', 'n');
        $this->assertSame('Illuminate\\Contracts\\Database\\ModelIdentifier', $commandProps['user']['cls']);
        $this->assertSame(['k' => 'str', 'v' => '********'], $commandProps['password']);
        $this->assertStringNotContainsString('hunter2', json_encode($job));

        // the job's own work is inside its trace; Gaze re-fetched no model (one users select: the framework's restore)
        $kinds = array_column($job, 'kind');
        $this->assertContains('log', $kinds);
        $userSelects = array_filter($job, static fn ($e) => ($e['kind'] ?? '') === 'query' && str_contains($e['body']['template'], 'from "users"'));
        $this->assertCount(1, $userSelects);
        $this->assertBatchesValid();
    }

    public function testASyncJobIsANestedTraceAndTheRequestTraceResumesAfterIt(): void
    {
        Route::get('/sync', function () {
            RecordJob::dispatch(User::first(), 'now');
            gaze('after the job');

            return 'ok';
        });
        $this->get('/sync')->assertOk();

        $entries = $this->entries();
        $request = $this->added('request')[0];
        $job = array_values(array_filter($entries, static fn ($e) => ($e['trace']['kind'] ?? '') === 'job'));
        $this->assertNotEmpty($job);
        $this->assertSame($request['trace']['id'], $job[0]['trace']['parent']);
        $after = array_values(array_filter($entries, static fn ($e) => ($e['kind'] ?? '') === 'log' && ($e['body']['values'][0]['v'] ?? '') === 'after the job'))[0];
        $this->assertSame($request['trace']['id'], $after['trace']['id']);
    }

    public function testAFailedJobClosesItsTraceWithTheException(): void
    {
        $this->useDatabaseQueue();
        FailingJob::dispatch();
        $this->resetCapture();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

        $summary = array_values(array_filter($this->entries(), static fn ($e) => ($e['trace']['ended'] ?? false) && $e['kind'] === 'job'))[0];
        $this->assertSame('JobFailed', $summary['body']['event']);
        $this->assertSame(1, $summary['body']['status']);
        $this->assertSame('red', $summary['color']);
        $this->assertSame('err', $summary['body']['exception']['k']);
        $this->assertSame('job exploded', $summary['body']['exception']['message']);
        $this->assertBatchesValid();
    }

    public function testTheJobsWatcherSendsQueuedAndProcessingEvents(): void
    {
        $this->useDatabaseQueue();
        gaze()->showJobs();
        Route::post('/records', fn () => RecordJob::dispatch(User::first(), 'watch') ? 'ok' : 'ok');
        $this->post('/records')->assertOk();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

        $jobs = $this->added('job');
        $events = array_map(static fn ($e) => $e['body']['event'], $jobs);
        $this->assertSame(['JobQueued', 'JobProcessing', 'JobProcessed'], $events);
        $this->assertSame('http', $jobs[0]['trace']['kind'], 'JobQueued belongs to the dispatching request');
        $this->assertSame('job', $jobs[1]['trace']['kind'], 'JobProcessing belongs to the job trace');
        $this->assertSame(RecordJob::class, $jobs[0]['body']['job']['cls']);
        $this->assertBatchesValid();
    }
}
