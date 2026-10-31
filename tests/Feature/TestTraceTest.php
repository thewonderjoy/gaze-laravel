<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Depends;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\Testing\TracesTests;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

/** The TracesTests trait opens one trace per test; requests inside it are child traces. */
final class TestTraceTest extends TestCase
{
    use TracesTests;

    /** @var array<string, mixed>|null */
    private static ?array $previous = null;

    public function testTheTestIsATraceAndItsRequestsAreChildren(): void
    {
        $trace = Gaze::traceContext();
        $this->assertSame('test', $trace['kind']);
        $this->assertSame(self::class . '::testTheTestIsATraceAndItsRequestsAreChildren', $trace['name']);

        Route::get('/child', fn () => 'ok');
        $this->get('/child')->assertOk();
        gaze('back in the test');

        $request = $this->added('request')[0];
        $this->assertSame($trace['id'], $request['trace']['parent']);
        $log = $this->added('log')[0];
        $this->assertSame($trace['id'], $log['trace']['id'], 'the test trace is active again after the request');
        self::$previous = $trace;
    }

    #[Depends('testTheTestIsATraceAndItsRequestsAreChildren')]
    public function testThePreviousTestWasClosedWithASummary(): void
    {
        // the summary of the previous test went out in its tearDown; its trace is not active anymore
        $this->assertNotSame(self::$previous['id'] ?? null, Gaze::traceContext()['id'] ?? null);
        $this->assertSame('test', Gaze::traceContext()['kind']);
    }
}
