<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\FailingJob;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\Schema;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;
use TheWonderJoy\Gaze\Laravel\Watchers\QueryWatcher;

/**
 * config/gaze.php: "long-running workers re-read it between jobs". That should hold with traces off
 * too (GAZE_TRACES=false, or traces.jobs=false), not only when jobs get a trace.
 */
final class WorkerRemoteSwitchWithoutTracesTest extends TestCase
{
    protected ?array $control = ['watchers' => ['queries' => false]];

    protected function setUp(): void
    {
        $this->gazeConfig = ['client.hello_ttl' => 1] + ($this->providedData()[0] ?? []);
        parent::setUp();
    }

    /** @return iterable<string, array{array<string, bool>}> */
    public static function tracesOff(): iterable
    {
        yield 'GAZE_TRACES=false' => [['traces.enabled' => false]];
        yield 'traces.jobs=false' => [['traces.jobs' => false]];
    }

    /** @param array<string, bool> $config */
    #[DataProvider('tracesOff')]
    public function testAQueueWorkerPicksUpNewSwitchesBetweenJobs(array $config): void
    {
        Schema::create();
        $this->app['config']->set('queue.default', 'database');
        $this->app['config']->set('queue.connections.database', ['driver' => 'database', 'connection' => 'testing', 'table' => 'jobs', 'queue' => 'default', 'retry_after' => 90]);
        $this->assertFalse($this->manager()->isOn('queries'));

        $this->control(['watchers' => ['queries' => true]]);
        sleep(2); // past hello_ttl
        FailingJob::dispatch();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--stop-when-empty' => true]);

        $this->assertTrue($this->manager()->isOn('queries'), 'the worker re-read the app\'s switches');
        $this->assertTrue($this->manager()->listening(QueryWatcher::class));
    }
}
