<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\FailingJob;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\Schema;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;
use TheWonderJoy\Gaze\Laravel\Watchers\QueryWatcher;

/** A long-running worker re-reads the app's watcher switches between jobs (at most every hello_ttl seconds). */
final class WorkerRemoteSwitchTest extends TestCase
{
    protected ?array $control = ['watchers' => ['queries' => false]];

    protected array $gazeConfig = ['client.hello_ttl' => 1];

    public function testAQueueWorkerPicksUpNewSwitchesBetweenJobs(): void
    {
        Schema::create();
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('queue.connections.database', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $this->assertFalse($this->manager()->isOn('queries'));
        $this->assertFalse($this->manager()->listening(QueryWatcher::class));

        $this->control(['watchers' => ['queries' => true]]); // the app turns queries on while the worker runs
        sleep(2);                                             // past hello_ttl
        FailingJob::dispatch();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

        $this->assertTrue($this->manager()->isOn('queries'));
        $this->assertTrue($this->manager()->listening(QueryWatcher::class), 'registered on the fly');
        $jobQueries = array_filter($this->added('query'), static fn ($q) => ($q['trace']['kind'] ?? '') === 'job');
        $this->assertNotEmpty($jobQueries, 'queries inside the next job are sent');
        $this->assertBatchesValid();
    }
}
