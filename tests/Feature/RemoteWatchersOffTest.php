<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;
use TheWonderJoy\Gaze\Laravel\Watchers\EventWatcher;
use TheWonderJoy\Gaze\Laravel\Watchers\QueryWatcher;

/** remote_watchers=false (GAZE_REMOTE_WATCHERS): the app's hello "watchers" and "redact" are ignored. */
final class RemoteWatchersOffTest extends TestCase
{
    protected ?array $control = ['watchers' => ['queries' => false, 'events' => true], 'redact' => ['*secret*']];

    protected array $gazeConfig = ['remote_watchers' => false, 'watchers.requests' => true];

    public function testTheAppsSwitchesAndRedactionAreIgnored(): void
    {
        $manager = $this->manager();
        $this->assertSame([], $manager->remoteSwitches());
        $this->assertTrue($manager->isOn('queries'));
        $this->assertFalse($manager->isOn('events'));
        $this->assertTrue($manager->listening(QueryWatcher::class));
        $this->assertFalse($manager->listening(EventWatcher::class));

        Route::post('/form', fn () => 'ok');
        $this->post('/form', ['client_secret' => 'xyz'])->assertOk();
        $payload = (string) json_encode($this->added('request')[0]['body']['payload'] ?? null);
        $this->assertStringContainsString('xyz', $payload, 'the app\'s redact patterns are not applied');
    }
}
