<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\Schema;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;
use TheWonderJoy\Gaze\Laravel\Watchers\QueryWatcher;

/**
 * The query switches set in config/gaze.php add up: a query is sent when any of them matches it, so
 * with `queries` on the others change nothing (README, "Watchers"). Queries are dispatched as
 * QueryExecuted events with a set time, so "slow" does not depend on the machine.
 */
final class QuerySwitchesTest extends TestCase
{
    private const SUB_SWITCHES = ['duplicate_queries', 'slow_queries', 'insert_queries', 'select_queries', 'update_queries', 'delete_queries'];

    protected function setUp(): void
    {
        $watchers = $this->providedData()[0] ?? [];
        $this->gazeConfig = ['queries.slow_threshold_ms' => 500];
        foreach ($watchers as $switch => $on) {
            $this->gazeConfig["watchers.$switch"] = $on;
        }
        parent::setUp();
        Schema::create();
        $this->resetCapture();
    }

    private static function ran(string $sql, array $bindings = [], float $ms = 1.0): void
    {
        event(new QueryExecuted($sql, $bindings, $ms, DB::connection()));
    }

    /** A select, the same select again, an insert, an update, a delete and a slow select. */
    private function runStatements(): void
    {
        self::ran('select * from users where id = ?', [1]);
        self::ran('select * from users where id = ?', [1]);
        self::ran('insert into users (name, email) values (?, ?)', ['a', 'a@example.com']);
        self::ran('update users set name = ? where id = ?', ['b', 1]);
        self::ran('delete from users where id = ?', [9]);
        self::ran('select * from posts', [], 900.0);
    }

    /** @return list<string> what was sent, as short labels */
    private function sent(): array
    {
        $names = [
            'select * from users where id = ?' => 'select',
            'insert into users (name, email) values (?, ?)' => 'insert',
            'update users set name = ? where id = ?' => 'update',
            'delete from users where id = ?' => 'delete',
            'select * from posts' => 'slow select',
        ];

        return array_map(static function (array $q) use ($names): string {
            $label = $names[$q['body']['template']] ?? $q['body']['template'];

            return in_array('dup', $q['body']['flags'], true) ? "$label (dup)" : $label;
        }, $this->added('query'));
    }

    /** @return iterable<string, array{array<string, bool>, list<string>}> */
    public static function matrix(): iterable
    {
        $all = ['select', 'select (dup)', 'insert', 'update', 'delete', 'slow select'];
        $off = array_fill_keys(self::SUB_SWITCHES, false);
        yield 'defaults' => [[], $all];
        yield 'queries on, insert_queries=false' => [['insert_queries' => false], $all];
        yield 'queries on, every other switch false' => [['queries' => true] + $off, $all];
        yield 'queries on, every other switch true' => [['queries' => true] + array_fill_keys(self::SUB_SWITCHES, true), $all];
        yield 'queries off' => [['queries' => false], []];
        yield 'select_queries' => [['queries' => false, 'select_queries' => true], ['select', 'select (dup)', 'slow select']];
        yield 'insert_queries' => [['queries' => false, 'insert_queries' => true], ['insert']];
        yield 'update_queries' => [['queries' => false, 'update_queries' => true], ['update']];
        yield 'delete_queries' => [['queries' => false, 'delete_queries' => true], ['delete']];
        yield 'insert and update' => [['queries' => false, 'insert_queries' => true, 'update_queries' => true], ['insert', 'update']];
        yield 'duplicate_queries' => [['queries' => false, 'duplicate_queries' => true], ['select (dup)']];
        yield 'slow_queries' => [['queries' => false, 'slow_queries' => true], ['slow select']];
        yield 'duplicates and slow' => [['queries' => false, 'duplicate_queries' => true, 'slow_queries' => true], ['select (dup)', 'slow select']];
        yield 'select and slow: no query twice' => [['queries' => false, 'select_queries' => true, 'slow_queries' => true], ['select', 'select (dup)', 'slow select']];
    }

    /**
     * @param array<string, bool> $watchers
     * @param list<string> $expected
     */
    #[DataProvider('matrix')]
    public function testTheSwitchesAddUp(array $watchers, array $expected): void
    {
        $this->runStatements();
        $this->assertSame($expected, $this->sent());
        $this->assertSame($expected !== [], $this->manager()->listening(QueryWatcher::class), 'the watcher listens only when a query switch is on');
    }

    /** @return iterable<string, array{array<string, bool>, int, int, bool}> */
    public static function sideEffects(): iterable
    {
        yield 'every query' => [[], 6, 1, true];
        yield 'only slow queries' => [['queries' => false, 'slow_queries' => true], 6, 0, true];
        yield 'every query switch off' => [['queries' => false], 0, 0, false];
    }

    /**
     * README: summaries count every query while any query switch is on, N+1 detection only sees the
     * queries that are sent, and with every query switch off summaries have no query count and
     * exceptions don't list the last queries.
     *
     * @param array<string, bool> $watchers
     */
    #[DataProvider('sideEffects')]
    public function testWhatTheSummaryAndExceptionsSeeOfTheQueries(array $watchers, int $queries, int $nPlusOne, bool $recent): void
    {
        Route::get('/orders', function () {
            for ($id = 1; $id <= 5; $id++) {
                self::ran('select * from customers where id = ?', [$id]); // an N+1 of fast queries
            }
            self::ran('select * from posts', [], 900.0);
            throw new \RuntimeException('after the queries');
        });

        $this->get('/orders')->assertStatus(500);

        $summary = $this->added('request')[0]['body'];
        $this->assertSame($queries, $summary['queries'] ?? 0, 'queries on the summary');
        $this->assertSame($queries > 0 ? 1 : 0, $summary['slow'] ?? 0, 'slow on the summary');
        $this->assertSame($nPlusOne, $summary['n_plus_one'] ?? 0, 'N+1 on the summary');
        $exception = $this->added('exception')[0];
        $this->assertSame($recent, str_contains((string) json_encode($exception['body']), 'recent_queries'), 'the exception lists the last queries');
    }
}
