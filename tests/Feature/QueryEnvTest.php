<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use TheWonderJoy\Gaze\Laravel\Tests\SetsEnv;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

/** The query thresholds from .env, as the app boots with them: what gets flagged slow and N+1. */
final class QueryEnvTest extends TestCase
{
    use SetsEnv;

    protected function setUp(): void
    {
        $this->setEnv(['GAZE_SLOW_QUERY_MS' => null, 'RAY_SLOW_QUERY_THRESHOLD_IN_MS' => null, 'GAZE_N_PLUS_ONE_THRESHOLD' => null]);
        $this->setEnv($this->providedData()[0] ?? []);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->restoreEnv();
    }

    /** @return array<string, list<string>> sql => flags of the queries sent */
    private function flagsOf(float ...$times): array
    {
        foreach ($times as $ms) {
            event(new QueryExecuted("select $ms", [], $ms, DB::connection()));
        }
        $out = [];
        foreach ($this->added('query') as $q) {
            $out[$q['body']['template']] = $q['body']['flags'];
        }

        return $out;
    }

    /** @return iterable<string, array{array<string, string>, float, float}> env, fastest slow time, slowest fast time */
    public static function thresholds(): iterable
    {
        yield 'unset: 500' => [[], 500.0, 499.0];
        yield 'GAZE_SLOW_QUERY_MS=50' => [['GAZE_SLOW_QUERY_MS' => '50'], 50.0, 49.9];
        yield 'RAY_SLOW_QUERY_THRESHOLD_IN_MS=80' => [['RAY_SLOW_QUERY_THRESHOLD_IN_MS' => '80'], 80.0, 79.0];
        yield 'GAZE_SLOW_QUERY_MS=0: every query' => [['GAZE_SLOW_QUERY_MS' => '0'], 0.0, -1.0];
        yield 'GAZE_SLOW_QUERY_MS= (blank): 500' => [['GAZE_SLOW_QUERY_MS' => ''], 500.0, 5.0];
    }

    /** @param array<string, string> $env */
    #[DataProvider('thresholds')]
    public function testTheSlowThresholdIsAtOrAboveTheConfiguredMilliseconds(array $env, float $slow, float $fast): void
    {
        $flags = $this->flagsOf($slow, max($fast, 0.0));
        $this->assertContains('slow', $flags["select $slow"], "$slow ms is slow");
        if ($fast >= 0) {
            $this->assertNotContains('slow', $flags["select $fast"], "$fast ms is not slow");
        }
    }

    /** @return iterable<string, array{array<string, string>, int}> env, runs that stay below the N+1 flag */
    public static function nPlusOne(): iterable
    {
        yield 'unset: 3' => [[], 3];
        yield 'GAZE_N_PLUS_ONE_THRESHOLD=5' => [['GAZE_N_PLUS_ONE_THRESHOLD' => '5'], 5];
        yield 'GAZE_N_PLUS_ONE_THRESHOLD= (blank): 3' => [['GAZE_N_PLUS_ONE_THRESHOLD' => ''], 3];
    }

    /** @param array<string, string> $env */
    #[DataProvider('nPlusOne')]
    public function testTheNPlusOneThresholdCountsRunsOfOneShape(array $env, int $allowed): void
    {
        foreach ([$allowed, $allowed + 1] as $runs) {
            Route::get("/runs/$runs", function () use ($runs) {
                for ($id = 1; $id <= $runs; $id++) {
                    event(new QueryExecuted('select * from customers where id = ?', [$id], 1.0, DB::connection()));
                }

                return 'ok';
            });
            $this->resetCapture();
            $this->get("/runs/$runs")->assertOk();
            $queries = array_filter($this->state(), static fn ($e) => ($e['kind'] ?? null) === 'query');
            $flagged = array_filter($queries, static fn ($q) => in_array('n+1', $q['body']['flags'], true));
            $this->assertSame($runs > $allowed, $flagged !== [], "$runs runs of one shape");
        }
    }
}
