<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Symfony\Component\VarDumper\VarDumper;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\GazeMixin;
use TheWonderJoy\Gaze\Laravel\GazeServiceProvider;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;
use TheWonderJoy\Gaze\Laravel\Tracing\TraceRequests;
use TheWonderJoy\Gaze\Laravel\Watchers\DumpHandler;
use TheWonderJoy\Gaze\Noop;

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
        $this->assertInstanceOf(Noop::class, $a);
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

    public function testARequestLoadsOnlyTheProviderConfigAndNoopAndNeverExits(): void
    {
        $base = getenv();
        foreach (['GAZE_ENABLED', 'GAZE_HOST', 'GAZE_PORT', 'RAY_HOST', 'RAY_PORT', 'APP_ENV'] as $k) {
            unset($base[$k]);
        }
        $base = ['GAZE_HOST' => '127.0.0.1', 'GAZE_PORT' => (string) self::PORT, 'GAZE_DEFINE_RAY_HELPER' => 'true'] + $base;
        foreach ([['APP_ENV' => 'production'], ['APP_ENV' => 'local', 'GAZE_ENABLED' => 'false']] as $env) {
            // output to files, not pipes (a Windows pipe holds about 4 KB)
            $out = self::root() . DIRECTORY_SEPARATOR . 'off-' . bin2hex(random_bytes(4));
            $proc = proc_open([PHP_BINARY, dirname(__DIR__) . '/Fixtures/off-app.php'], [1 => ['file', $out . '.out', 'w'], 2 => ['file', $out . '.err', 'w']], $pipes, null, $env + $base);
            $exit = proc_close($proc);
            $r = json_decode((string) file_get_contents($out . '.out'), true);
            $err = (string) file_get_contents($out . '.err');
            @unlink($out . '.out');
            @unlink($out . '.err');

            $this->assertSame(0, $exit, $err);
            $this->assertSame(200, $r['status'] ?? null, 'die(), gaze_dd() and rd() returned. ' . $err);
            $this->assertSame(['showQueries' => 'q', 'queries' => 'q2', 'countQueries' => 'c', 'showSlowQueries' => 's', 'showConditionalQueries' => 'cq', 'group' => 'g', 'pass' => 'p', 'blade' => 'ok'], $r['ran']);
            $this->assertSame([GazeServiceProvider::class, \TheWonderJoy\Gaze\Config::class, Noop::class], $r['classes']);
        }
        $this->assertSame([], $this->requests(), 'not even a handshake');
    }

    public function testTheNoopRunsTheCallbackOfEveryMacroThatTakesOne(): void
    {
        $runs = [];
        foreach (GazeMixin::macros() as $name => $macro) {
            foreach ((new \ReflectionFunction($macro))->getParameters() as $i => $param) {
                if ($param->getName() === 'callback') {
                    $runs[$name] = $i;
                }
            }
        }
        $declared = GazeServiceProvider::RUNS;
        ksort($runs);
        ksort($declared);
        $this->assertSame($runs, $declared, 'GazeServiceProvider::RUNS lists every macro that takes a $callback');
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
