<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

final class RequestTraceTest extends TestCase
{
    public function testARequestProducesOneTraceWithItsQueriesLogsAndSummary(): void
    {
        Route::get('/orders/{id}', function (string $id) {
            DB::select('select ? as id', [(int) $id]);
            DB::select('select 1 as one');
            Log::info('looking at order', ['id' => $id]);
            gaze('inside the controller');

            return response()->json(['id' => (int) $id]);
        })->name('orders.show');

        $this->get('/orders/5')->assertOk();

        $entries = $this->entries();
        $kinds = array_column($entries, 'kind');
        $this->assertContains('query', $kinds);
        $this->assertContains('app_log', $kinds);
        $this->assertContains('log', $kinds);
        $this->assertContains('request', $kinds);

        $traceIds = array_unique(array_map(static fn ($e) => $e['trace']['id'] ?? null, $entries));
        $this->assertCount(1, $traceIds, 'all entries belong to one trace: ' . json_encode($traceIds));
        $traceId = $traceIds[0];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $traceId);

        $summary = $this->added('request')[0];
        $this->assertTrue($summary['trace']['ended']);
        $this->assertSame('http', $summary['trace']['kind']);
        $this->assertSame('GET /orders/5', $summary['trace']['name']);
        $this->assertSame('GET', $summary['body']['method']);
        $this->assertSame(200, $summary['body']['status']);
        $this->assertSame('orders.show', $summary['body']['route']);
        $this->assertStringEndsWith('/orders/5', $summary['body']['url']);
        $this->assertIsFloat($summary['body']['duration_ms'] + 0.0);
        $this->assertGreaterThan(0, $summary['body']['memory_peak']);
        $this->assertSame(2, $summary['body']['queries']);
        $this->assertSame(1, $summary['body']['logs']);

        $queries = $this->added('query');
        $this->assertCount(2, $queries);
        $this->assertSame('select 5 as id', $queries[0]['body']['sql']);
        $this->assertSame('select ? as id', $queries[0]['body']['template']);
        $this->assertSame([['k' => 'int', 'v' => 5]], $queries[0]['body']['bindings']);
        $this->assertSame('testing', $queries[0]['body']['connection']);
        $this->assertSame('sqlite', $queries[0]['body']['driver']);
        $this->assertMatchesRegularExpression('/^q:[0-9a-f]{16}$/', $queries[0]['body']['fingerprint']);
        $this->assertSame(__FILE__, $queries[0]['origin']['file'], 'query origin is the app frame');

        $batch = $this->batches()[0];
        $this->assertSame('thewonderjoy/gaze-laravel', $batch['client']['name']);
        $this->assertSame('laravel', $batch['client']['framework']);
        $this->assertSame('GazeTests', $batch['client']['project']['name']);
        $this->assertBatchesValid();
    }
}
