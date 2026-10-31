<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

/** Outside APP_ENV=local the relay route does not exist. */
final class RelayRouteTest extends TestCase
{
    protected string $appEnv = 'testing';

    public function testTheRelayIsOnlyRegisteredInTheLocalEnvironment(): void
    {
        $this->assertNull(Route::getRoutes()->getByName('gaze.relay'));
        $this->call('POST', '/_gaze', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertNotFound();
    }
}
