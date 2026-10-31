<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\Post;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\Schema;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\User;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;
use TheWonderJoy\Gaze\Laravel\Watchers\QueryWatcher;

final class QueryWatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create();
        $this->resetCapture();
    }

    /** @return list<array<string, mixed>> app state entries of kind query in the trace of the HTTP request */
    private function requestQueries(): array
    {
        $out = [];
        foreach ($this->state() as $e) {
            if (($e['kind'] ?? null) === 'query' && ($e['trace']['kind'] ?? null) === 'http') {
                $out[] = $e;
            }
        }

        return $out;
    }

    public function testNPlusOneRunsAreGroupedIntoTheFirstEntryWithExecutions(): void
    {
        Schema::seed(5, 1);
        Route::get('/posts', function () {
            return Post::all()->map(fn (Post $p) => $p->author->name)->all(); // lazy loads: N+1
        });
        $this->resetCapture();

        $this->get('/posts')->assertOk();

        $queries = $this->requestQueries();
        $this->assertCount(2, $queries, 'select posts + ONE grouped entry for the 5 author lookups');
        $group = array_values(array_filter($queries, static fn ($q) => str_contains($q['body']['template'], '"users"')))[0];
        $this->assertSame('n+1', $group['body']['flags'][0]);
        $this->assertTrue($group['tags']['n+1']);
        $this->assertSame(5, $group['body']['count']);
        $this->assertCount(5, $group['body']['executions']);
        $this->assertSame(['bindings', 'time_ms', 'offset_ms'], array_keys($group['body']['executions'][0]));
        $ids = array_map(static fn ($x) => $x['bindings'][0]['v'], $group['body']['executions']);
        $this->assertSame([1, 2, 3, 4, 5], $ids);
        $this->assertSame(Post::class . '::author', $group['body']['n_plus_one']['relation']);
        $this->assertArrayNotHasKey('label', $group, 'the app derives the N+1 badge from the flag and executions');
        $this->assertSame(__FILE__, $group['origin']['file']);

        // the first entry was updated in place; rows 2 and 3 (sent before the threshold) were removed
        $ops = array_count_values(array_map(static fn ($e) => $e['op'], array_filter($this->entries(), static fn ($e) => ($e['kind'] ?? 'query') === 'query' || $e['op'] === 'remove')));
        $this->assertSame(2, $ops['remove'] ?? 0);
        $this->assertGreaterThanOrEqual(1, $ops['update'] ?? 0);

        $summary = $this->added('request')[0];
        $this->assertSame(6, $summary['body']['queries']);
        $this->assertSame(1, $summary['body']['n_plus_one']);
        $this->assertBatchesValid();
    }

    public function testWithoutGroupingEveryRunIsARowAndRunsPastTheThresholdAreFlagged(): void
    {
        $this->app['config']->set('gaze.queries.group_n_plus_one', false);
        Schema::seed(5, 1);
        Route::get('/posts', fn () => Post::all()->map(fn (Post $p) => $p->author->name)->all());
        $this->get('/posts')->assertOk();

        $authors = array_values(array_filter($this->requestQueries(), static fn ($q) => str_contains($q['body']['template'], '"users"')));
        $this->assertCount(5, $authors);
        $this->assertNotContains('n+1', $authors[2]['body']['flags']);
        $this->assertContains('n+1', $authors[3]['body']['flags']);
        $this->assertContains('n+1', $authors[4]['body']['flags']);
    }

    public function testAnIdenticalQueryIsFlaggedAsDuplicateAndStatsResetPerTrace(): void
    {
        Route::get('/dup', function () {
            DB::select('select * from users where id = ?', [1]);
            DB::select('select * from users where id = ?', [1]);
            DB::select('select * from users where id = ?', [2]);

            return 'ok';
        });
        $this->get('/dup')->assertOk();
        $this->get('/dup')->assertOk();

        $queries = $this->added('query');
        $this->assertCount(6, $queries);
        $this->assertSame([[], ['dup'], [], [], ['dup'], []], array_map(static fn ($q) => $q['body']['flags'], $queries), 'the second request starts clean');
        $this->assertTrue($queries[1]['tags']['dup']);
        $this->assertSame(1, $this->added('request')[0]['body']['duplicates']);
    }

    public function testSlowQueriesWatcherSendsOnlySlowOnesWithTheFlag(): void
    {
        $this->manager()->disable('queries');
        gaze()->showSlowQueries(0);
        DB::select('select 1');
        $q = $this->added('query');
        $this->assertCount(1, $q);
        $this->assertContains('slow', $q[0]['body']['flags']);
        $this->assertSame('red', $q[0]['color']);

        gaze()->stopShowingSlowQueries();
        DB::select('select 2');
        $this->assertCount(1, $this->added('query'));
    }

    public function testTypeWatchersSelectByStatementIncludingCtesAndComments(): void
    {
        $this->manager()->disable('queries');
        gaze()->showInsertQueries();
        DB::insert('insert into users (name, email) values (?, ?)', ['a', 'a@example.com']);
        DB::select('select * from users');
        DB::select('/* report */ with x as (select 1 as n) select * from x');
        gaze()->stopShowingInsertQueries();
        gaze()->showSelectQueries();
        DB::select('/* report */ with x as (select 1 as n) select * from x');
        DB::update('update users set name = ?', ['b']);

        $sql = array_map(static fn ($q) => $q['body']['template'], $this->added('query'));
        $this->assertSame(['insert into users (name, email) values (?, ?)', '/* report */ with x as (select 1 as n) select * from x'], $sql);
    }

    public function testConditionalQueriesAndShowQueriesWithACallback(): void
    {
        $this->manager()->disable('queries');
        gaze()->showConditionalQueries(fn ($query) => str_contains($query->sql, 'posts'));
        DB::select('select * from users');
        DB::select('select * from posts');
        gaze()->showConditionalQueries(fn ($query) => str_contains($query->sql, 'nothing')); // same name: replaces
        DB::select('select * from posts where id = 1');
        gaze()->stopShowingConditionalQueries();

        $result = gaze()->showQueries(function () {
            return DB::select('select 42 as answer');
        });
        DB::select('select 43');

        $this->assertSame(42, $result[0]->answer, 'the callback result is returned even without a return type');
        $sql = array_map(static fn ($q) => $q['body']['template'], $this->added('query'));
        $this->assertSame(['select * from posts', 'select 42 as answer'], $sql);
    }

    public function testShowQueriesModifiersApplyToEveryQueryAndChain(): void
    {
        $this->manager()->disable('queries');
        gaze()->showQueries()->green()->label('SQL')->large();
        DB::select('select 1');
        $q = $this->added('query')[0];
        $this->assertSame(['green', 'SQL', 'lg'], [$q['color'], $q['label'], $q['size']]);
    }

    public function testCountQueriesReturnsTheResultAndSendsATable(): void
    {
        $this->manager()->disable('queries');
        $result = gaze()->countQueries(function () {
            DB::select('select 1');
            DB::select('select 2');

            return 'done';
        });
        $this->assertSame('done', $result);
        $this->assertSame([], $this->added('query'), 'individual queries are not sent while counting');
        $table = $this->added('table')[0];
        $this->assertSame('Queries', $table['label']);
        $this->assertSame(['Count', ['k' => 'int', 'v' => 2]], $table['body']['entries'][0]);
    }

    public function testBindingsOfSecretColumnsAreMasked(): void
    {
        DB::insert('insert into users (name, email, password) values (?, ?, ?)', ['ada', 'ada@example.com', 'hunter2']);
        DB::update('update "users" set "password" = ? where "id" = ?', ['s3cret', 1]);
        $queries = $this->added('query');
        $json = json_encode($queries);
        $this->assertStringNotContainsString('hunter2', $json);
        $this->assertStringNotContainsString('s3cret', $json);
        $this->assertSame(['k' => 'str', 'v' => 'ada'], $queries[0]['body']['bindings'][0]);
        $this->assertSame(['k' => 'str', 'v' => '********'], $queries[0]['body']['bindings'][2]);
        $this->assertStringContainsString("'********'", $queries[1]['body']['sql']);
        $this->assertSame(['k' => 'int', 'v' => 1], $queries[1]['body']['bindings'][1]);
    }

    public function testQueryBuilderMacroAndNoQueryLog(): void
    {
        $this->manager()->disable('queries');
        $builder = User::query()->where('email', 'ada@example.com')->gaze('lookup');
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $builder);
        $q = $this->added('query')[0];
        $this->assertSame('lookup', $q['label']);
        $this->assertSame('select * from "users" where "email" = \'ada@example.com\'', $q['body']['sql']);
        $this->assertSame([['k' => 'str', 'v' => 'ada@example.com']], $q['body']['bindings']);
        $this->assertFalse(DB::connection()->logging(), 'the query log stays off');
        $this->assertTrue($this->manager()->listening(QueryWatcher::class));
    }
}
