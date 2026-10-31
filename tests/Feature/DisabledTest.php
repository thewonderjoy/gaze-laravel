<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\VarDumper\VarDumper;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\LaravelGaze;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;
use TheWonderJoy\Gaze\Laravel\Tracing\TraceRequests;
use TheWonderJoy\Gaze\Laravel\Watchers\DumpHandler;

final class DisabledTest extends TestCase
{
    protected array $gazeConfig = ['enabled' => false, 'watchers.events' => true, 'watchers.views' => true, 'watchers.cache' => true, 'watchers.http_client' => true];

    protected string $appEnv = 'local';

    public function testNothingIsRegisteredAndTheHelperIsInert(): void
    {
        $this->assertFalse($this->manager()->enabled());
        $this->assertSame([], $this->packageListeners(), 'no listener at all');
        $this->assertFalse($this->app->make(Kernel::class)->hasMiddleware(TraceRequests::class));
        $this->assertNull(Route::getRoutes()->getByName('gaze.relay'));
        $handler = VarDumper::setHandler(null);
        VarDumper::setHandler($handler);
        $this->assertNotInstanceOf(DumpHandler::class, $handler);

        $a = gaze();
        $b = gaze('x', ['y' => 1]);
        $this->assertSame($a, $b, 'one shared inert instance');
        $this->assertNotInstanceOf(LaravelGaze::class, $a);
        $this->assertTrue($a->disabled());
        $this->assertSame(7, gaze()->showQueries(fn () => 7), 'callbacks still run');
        $this->assertSame('r', gaze()->countQueries(fn () => 'r'));
        gaze()->model()->env()->context();
        collect([1, 2])->gaze();

        Route::get('/quiet', function () {
            DB::select('select 1');
            Log::error('not sent');

            return 'ok';
        });
        $this->get('/quiet')->assertOk();
        $this->assertSame([], $this->requests(), 'not even a handshake');
    }

    public function testProductionIsOffByDefault(): void
    {
        $this->app['config']->set('gaze.enabled', null);
        $this->app['env'] = 'production';
        $this->assertFalse($this->manager()->enabled());
        $this->app['env'] = 'local';
        $this->assertTrue($this->manager()->enabled());
        $this->app['config']->set('gaze.enabled', 'false');
        $this->assertFalse($this->manager()->enabled());
        $this->app['config']->set('gaze.enabled', true);
        $this->app['env'] = 'production';
        $this->assertTrue($this->manager()->enabled(), 'GAZE_ENABLED=true wins');
    }

    public function testTheRayShimIsOffByDefault(): void
    {
        $this->assertFalse(function_exists('ray'), 'ray() is only defined with define_ray_helper');
        $this->assertFalse(function_exists('rd'));
        $this->assertFalse(Gaze::hasMacro('ray'));
    }
}
