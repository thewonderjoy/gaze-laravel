<?php

// While Gaze is off (APP_ENV=production, or GAZE_ENABLED=false): one request through the HTTP kernel
// that calls what an app may have left behind, then prints the package classes that were loaded.
// DisabledTest runs it in a process of its own (the test case itself loads Gaze).

declare(strict_types=1);

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Orchestra\Testbench\Foundation\Application;
use TheWonderJoy\Gaze\Laravel\GazeServiceProvider;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$app = Application::create(options: ['extra' => ['providers' => [GazeServiceProvider::class]]]);
$app['router']->get('/off', static function () {
    gaze('x', ['y' => 1])->red()->label('l')->model()->env()->context()->enable()->send('still off');
    gaze()->pause();
    gaze()->die();
    gaze_dd('returns');
    ray('x')->die();
    rd('returns');
    collect([1])->gaze();
    str('a')->gaze('label');
    DB::table('users')->where('id', 1)->gaze();

    return response()->json([
        'showQueries' => gaze()->showQueries(static fn () => 'q'),
        'queries' => gaze()->queries(static fn () => 'q2'),
        'countQueries' => gaze()->countQueries(static fn () => 'c'),
        'showSlowQueries' => gaze()->showSlowQueries(100, static fn () => 's'),
        'showConditionalQueries' => gaze()->showConditionalQueries(static fn () => true, static fn () => 'cq'),
        'group' => gaze()->group('g', static fn () => 'g'),
        'pass' => gaze()->pass('p'),
        'blade' => trim(Blade::render('@gaze($x)@gazeMeasure ok', ['x' => 1])),
    ]);
});

$response = $app->make(Kernel::class)->handle(Request::create('/off'));
echo json_encode([
    'status' => $response->getStatusCode(),
    'ran' => json_decode((string) $response->getContent(), true),
    'classes' => array_values(array_filter(get_declared_classes(), static fn (string $c) => str_starts_with($c, 'TheWonderJoy\\'))),
]);
