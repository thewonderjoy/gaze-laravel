<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\OrderShipped;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;
use TheWonderJoy\Gaze\Laravel\Watchers\EventWatcher;
use TheWonderJoy\Gaze\Laravel\Watchers\LogWatcher;
use TheWonderJoy\Gaze\Laravel\Watchers\QueryWatcher;

/** The app switches watchers for every process through GET /v1/hello "watchers" and adds redaction patterns. */
final class RemoteWatchersTest extends TestCase
{
    protected ?array $control = [
        'watchers' => ['queries' => false, 'events' => true, 'logs' => false, 'requests' => true, 'not_a_watcher' => true],
        'redact' => ['*secret*'],
    ];

    public function testHelloWatchersOverrideTheConfigAtBoot(): void
    {
        $manager = $this->manager();
        $this->assertSame(['queries' => false, 'events' => true, 'logs' => false, 'requests' => true], $manager->remoteSwitches());
        $this->assertFalse($manager->isOn('queries'));
        $this->assertTrue($manager->isOn('events'));
        $this->assertFalse($manager->listening(QueryWatcher::class), 'switched off by the app: never registered');
        $this->assertTrue($manager->listening(EventWatcher::class), 'switched on by the app: registered');
        $this->assertFalse($manager->listening(LogWatcher::class));
        $listeners = $this->packageListeners();
        $this->assertArrayNotHasKey(\Illuminate\Database\Events\QueryExecuted::class, $listeners);
        $this->assertArrayHasKey('*', $listeners);

        Route::post('/form', function () {
            DB::select('select 1');
            Log::info('not sent');
            Event::dispatch(new OrderShipped(1));

            return 'ok';
        });
        $this->post('/form', ['client_secret' => 'xyz', 'name' => 'Ada'])->assertOk();

        $this->assertSame([], $this->added('query'));
        $this->assertSame([], $this->added('app_log'));
        $this->assertNotEmpty(array_filter($this->added('event'), static fn ($e) => $e['body']['name'] === OrderShipped::class));
        $summary = $this->added('request')[0];
        $payload = json_encode($summary['body']['payload']);
        $this->assertStringNotContainsString('xyz', $payload, 'hello.redact patterns apply');
        $this->assertStringContainsString('Ada', $payload);
        $this->assertBatchesValid();
    }

    public function testRuntimeShowXStillWinsInThisProcess(): void
    {
        gaze()->showQueries();
        $this->assertTrue($this->manager()->isOn('queries'));
        DB::select('select 1');
        $this->assertCount(1, $this->added('query'));
    }
}
