<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Testing;

use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\Tracing\Trace;

/**
 * One Gaze trace per test (kind test): requests, jobs and commands the test runs become child
 * traces, and the summary row says whether the test passed.
 *
 *     use TheWonderJoy\Gaze\Laravel\Testing\TracesTests;
 *
 *     abstract class TestCase extends BaseTestCase { use TracesTests; }
 *
 * Laravel's and Testbench's test cases call setUpTracesTests() / tearDownTracesTests() for
 * every trait they use.
 */
trait TracesTests
{
    protected ?Trace $gazeTestTrace = null;

    protected function setUpTracesTests(): void
    {
        $this->gazeTestTrace = null;
        if (!isset($this->app) || !$this->app->bound(GazeManager::class)) {
            return;
        }
        $gaze = $this->app->make(GazeManager::class);
        if (!$gaze->enabled() || !GazeManager::bool($gaze->config('traces.enabled', true))) {
            return;
        }
        $this->gazeTestTrace = $gaze->traces()->startTest(static::class . '::' . $this->name());
    }

    protected function tearDownTracesTests(): void
    {
        $trace = $this->gazeTestTrace;
        $this->gazeTestTrace = null;
        if ($trace === null || !isset($this->app) || !$this->app->bound(GazeManager::class)) {
            return;
        }
        $status = $this->status();
        $this->app->make(GazeManager::class)->traces()->finishTest($trace, $status->asString(), $status->isSuccess() || $status->isUnknown());
    }
}
