<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use TheWonderJoy\Gaze\Client;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

require_once dirname(__DIR__) . '/Fixtures/Octane/events.php';

/** Octane keeps one app per worker: per-request state is dropped and the queue drained on RequestTerminated. */
final class OctaneTest extends TestCase
{
    public function testPerRequestStateIsResetAndTheQueueDrained(): void
    {
        $listeners = $this->packageListeners();
        $this->assertArrayHasKey(RequestReceived::class, $listeners);
        $this->assertArrayHasKey(RequestTerminated::class, $listeners);

        $traces = $this->manager()->traces();
        event(new RequestReceived());
        $trace = $traces->start('http', 'GET /octane');
        DB::select('select 1');
        DB::select('select 1');
        $this->assertSame(1, $trace->duplicates);
        gaze('queued in the request');
        event(new RequestTerminated());

        $this->assertNull($traces->active(), 'the trace stack is empty');
        $this->assertNull(Gaze::traceContext());
        $this->assertSame(0, Client::current()?->status()['queued'], 'drained');
        DB::select('select 1');
        $this->assertSame(0, $traces->current()->duplicates, 'duplicate detection starts over');
        $this->assertCount(1, $this->added('log'));
    }
}
