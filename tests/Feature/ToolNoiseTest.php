<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Pulse\Storage\GazeTestDatabaseStorage;
use Laravel\Telescope\Storage\GazeTestEntriesRepository;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

require_once dirname(__DIR__) . '/Fixtures/Tools.php';

/** Telescope, Pulse, Horizon and similar tools: their dashboards and their own storage stay out of the feed. */
final class ToolNoiseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::statement('create table telescope_entries (uuid text, type text, content text)');
        DB::statement('create table telescope_entries_tags (entry_uuid text, tag text)');
        DB::statement('create table pulse_entries (type text, key text, value integer)');
        DB::statement('create table pulse_aggregates (type text, key text, value integer)');
        $this->resetCapture();
    }

    public function testQueriesTelescopeAndPulseRunToStoreWhatTheyRecordedAreLeftOut(): void
    {
        Route::get('/orders', function () {
            DB::select('select 1 as app_query');
            // Telescope and Pulse write what they recorded once the response is sent
            app()->terminating(static function (): void {
                GazeTestEntriesRepository::store();
                GazeTestDatabaseStorage::store();
            });

            return 'ok';
        });

        $this->get('/orders')->assertOk();

        $sql = array_map(static fn ($e) => $e['body']['template'], $this->added('query'));
        $this->assertSame(['select 1 as app_query'], $sql);
    }

    public function testTheListOfIgnoredNamespacesIsASetting(): void
    {
        config(['gaze.queries.ignore_namespaces' => ['Laravel\Pulse\\']]);
        GazeTestEntriesRepository::store();
        GazeTestDatabaseStorage::store();

        $sql = array_map(static fn ($e) => $e['body']['template'], $this->added('query'));
        $this->assertCount(3, $sql, 'Telescope is shown again: ' . json_encode($sql));
        $this->assertStringContainsString('telescope_entries', $sql[0]);
    }

    public function testARequestOnAnIgnoredPathSendsNoWatcherEntries(): void
    {
        $ran = false;
        Route::post('/telescope/telescope-api/dumps', function () use (&$ran) {
            DB::select('select 1 as dashboard_poll');
            Log::info('polled');
            // the test app keeps terminating callbacks across requests: run it for this one only
            app()->terminating(static function () use (&$ran): void {
                if (!$ran) {
                    $ran = true;
                    DB::select('select 2 as after_the_poll');
                }
            });

            return ['entries' => []];
        });
        Route::get('/orders', static fn () => DB::select('select 3 as app_query') ? 'ok' : 'ok');

        $this->post('/telescope/telescope-api/dumps')->assertOk();
        $this->get('/orders')->assertOk();

        $sql = array_map(static fn ($e) => $e['body']['template'], $this->added('query'));
        $this->assertSame(['select 3 as app_query'], $sql);
        $this->assertSame([], $this->added('app_log'));
        $this->assertSame(['GET /orders'], array_map(static fn ($e) => $e['trace']['name'], $this->added('request')));
    }

    public function testGazeCallsInAnIgnoredRequestStillArrive(): void
    {
        Route::get('/horizon/api/stats', function () {
            gaze('asked for it');

            return 'ok';
        });

        $this->get('/horizon/api/stats')->assertOk();

        $this->assertSame('asked for it', $this->added('log')[0]['body']['values'][0]['v'] ?? null);
    }
}
