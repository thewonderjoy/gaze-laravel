<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\Support\AppOrigin;
use TheWonderJoy\Gaze\Laravel\Support\Sql;
use TheWonderJoy\Gaze\Laravel\Support\Values;
use TheWonderJoy\Gaze\Laravel\Tracing\Trace;

/**
 * One QueryExecuted listener for every query switch (laravel-ray registered seven):
 * queries (all), duplicate_queries, slow_queries, insert/select/update/delete_queries and named
 * conditional watchers. Never turns on Laravel's query log.
 *
 * Body (kind query): sql (bindings inlined for display), template (with placeholders), bindings
 * (Value[]), connection, driver, time_ms, fingerprint (hash of the normalised SQL shape),
 * flags. Per trace (state resets with the trace):
 *  - "dup": the same SQL with the same bindings ran before in this trace;
 *  - "slow": time_ms >= queries.slow_threshold_ms;
 *  - "n+1": the same shape ran more than queries.n_plus_one_threshold times. With
 *    group_n_plus_one the runs fold into the first entry: op update with executions[]
 *    ({bindings, time_ms, offset_ms}) and the separate rows already sent are removed. A
 *    lazy-loaded relation behind it (Model::getRelationshipFromMethod on the stack) is named
 *    in n_plus_one.relation.
 */
final class QueryWatcher extends Watcher
{
    public const SWITCHES = ['queries', 'duplicate_queries', 'slow_queries', 'insert_queries', 'select_queries', 'update_queries', 'delete_queries'];

    /** @var array<string, \Closure(QueryExecuted): bool> named conditional watchers */
    public array $conditions = [];

    /** @var list<array{count: int, time_ms: float}> open countQueries() scopes */
    private array $counters = [];

    private int $quiet = 0;

    public ?float $slowThresholdMs = null;

    protected function register(Dispatcher $events): void
    {
        $events->listen(QueryExecuted::class, $this->handle(...));
    }

    public function reset(): void
    {
        $this->counters = [];
        $this->quiet = 0;
    }

    public function slowThreshold(): float
    {
        return $this->slowThresholdMs ?? (float) $this->gaze->config('queries.slow_threshold_ms', 500);
    }

    /**
     * Runs $callback while counting its queries; individual queries are not sent unless the
     * queries switch is on. Returns [result, count, total ms].
     *
     * @return array{0: mixed, 1: int, 2: float}
     */
    public function count(callable $callback): array
    {
        $this->listen();
        $this->counters[] = ['count' => 0, 'time_ms' => 0.0];
        ++$this->quiet;
        try {
            $result = $callback();
        } finally {
            --$this->quiet;
            $counter = array_pop($this->counters) ?? ['count' => 0, 'time_ms' => 0.0];
        }

        return [$result, $counter['count'], round($counter['time_ms'], 3)];
    }

    public function handle(QueryExecuted $event): void
    {
        if (GazeManager::sending()) {
            return;
        }
        $sql = (string) $event->sql;
        if (preg_match('/^\s*explain\b/i', $sql)) {
            return;
        }
        $time = (float) $event->time;
        foreach ($this->counters as $i => $_) {
            ++$this->counters[$i]['count'];
            $this->counters[$i]['time_ms'] += $time;
        }

        $trace = $this->gaze->currentTrace();
        $connection = (string) $event->connectionName;
        $fingerprint = Sql::fingerprint($sql, $connection);
        $prepared = $this->prepared($event);
        $exactKey = hash('xxh3', $connection . "\0" . $sql . "\0" . json_encode($prepared, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
        $dup = isset($trace->exact[$exactKey]);
        $trace->exact[$exactKey] = ($trace->exact[$exactKey] ?? 0) + 1;
        $slow = $time >= $this->slowThreshold();
        $type = Sql::type($sql);

        ++$trace->queries;
        $trace->queryMs += $time;
        if ($dup) {
            ++$trace->duplicates;
        }
        if ($slow) {
            ++$trace->slow;
        }
        $trace->recent[] = ['sql' => $sql, 'time_ms' => round($time, 3)];
        if (count($trace->recent) > 5) {
            array_shift($trace->recent);
        }
        if ($trace->isRoot()) {
            $trace->trim();
        }

        $send = $this->on('queries')
            || ($dup && $this->on('duplicate_queries'))
            || ($slow && $this->on('slow_queries'))
            || ($type !== 'other' && $this->on($type . '_queries'));
        if ($this->quiet > 0 && !$this->on('queries')) {
            $send = $send && (($dup && $this->on('duplicate_queries')) || ($slow && $this->on('slow_queries')));
        }
        if (!$send) {
            foreach ($this->conditions as $condition) {
                try {
                    if ($condition($event)) {
                        $send = true;
                        break;
                    }
                } catch (\Throwable) {
                }
            }
        }
        if (!$send) {
            return;
        }
        try {
            $this->emit($event, $trace, $fingerprint, $prepared, $time, $dup, $slow);
        } catch (\Throwable) {
        }
    }

    /** @return list<mixed> */
    private function prepared(QueryExecuted $event): array
    {
        try {
            return array_values($event->connection->prepareBindings((array) $event->bindings));
        } catch (\Throwable) {
            return array_values((array) $event->bindings);
        }
    }

    /** @param list<mixed> $prepared */
    private function emit(QueryExecuted $event, Trace $trace, string $fingerprint, array $prepared, float $time, bool $dup, bool $slow): void
    {
        $sql = (string) $event->sql;
        $raw = array_values((array) $event->bindings);
        $redactor = $this->gaze->redactor();
        $masked = [];
        if ($raw && $redactor->sqlMayHaveSecrets($sql)) {
            foreach (Sql::placeholderColumns($sql, count($raw)) as $i => $column) {
                if ($column !== null && $redactor->matches('inputs', $column)) {
                    $masked[$i] = true;
                }
            }
        }
        $bindings = [];
        $display = $prepared;
        foreach ($raw as $i => $binding) {
            if (isset($masked[$i]) || $binding instanceof \SensitiveParameterValue) {
                $bindings[] = ['k' => 'str', 'v' => $redactor->mask()];
                $display[$i] = $redactor->mask();
            } else {
                $bindings[] = Values::from($binding);
            }
        }
        try {
            $inlined = $event->connection->getQueryGrammar()->substituteBindingsIntoRawSql($sql, $display);
        } catch (\Throwable) {
            $inlined = $sql;
        }
        $driver = null;
        try {
            $driver = $event->connection->getDriverName();
        } catch (\Throwable) {
        }
        $flags = [];
        if ($dup) {
            $flags[] = 'dup';
        }
        if ($slow) {
            $flags[] = 'slow';
        }
        $body = [
            'sql' => $inlined,
            'template' => $sql,
            'bindings' => $bindings,
            'connection' => (string) $event->connectionName,
            'driver' => $driver,
            'time_ms' => round($time, 3),
            'fingerprint' => $fingerprint,
        ];
        if ($event->readWriteType !== null) {
            $body['read_write'] = (string) $event->readWriteType;
        }
        if ($driver === null) {
            unset($body['driver']);
        }
        $execution = ['bindings' => $bindings, 'time_ms' => round($time, 3), 'offset_ms' => $trace->isRoot() ? 0.0 : $trace->offsetMs()];

        $group = $trace->groups[$fingerprint] ?? null;
        if ($group === null) {
            $origin = AppOrigin::resolve();
            $mods = $this->mods($flags, $origin);
            $body['flags'] = $flags;
            $id = $this->gaze->send('query', $body, $this->modifiers + $mods);
            $trace->groups[$fingerprint] = [
                'id' => $id, 'body' => $body, 'origin' => $origin, 'count' => 1, 'rows' => [],
                'executions' => [$execution], 'dup' => $dup, 'slow' => $slow, 'grouped' => false, 'dirty' => false,
                'relation' => null, 'total_ms' => $time,
            ];

            return;
        }

        $group = &$trace->groups[$fingerprint];
        ++$group['count'];
        $group['total_ms'] += $time;
        $group['dup'] = $group['dup'] || $dup;
        $group['slow'] = $group['slow'] || $slow;
        if (count($group['executions']) < (int) $this->gaze->config('queries.max_executions', 200)) {
            $group['executions'][] = $execution;
        }
        if ($group['count'] === 2) {
            $group['relation'] = self::lazyRelation();
        }
        $threshold = max(1, (int) $this->gaze->config('queries.n_plus_one_threshold', 3));
        $isNPlusOne = $group['count'] > $threshold;
        if ($isNPlusOne && $group['count'] === $threshold + 1) {
            ++$trace->nPlusOne;
        }
        $grouping = GazeManager::bool($this->gaze->config('queries.group_n_plus_one', true));

        if (!$grouping || !$isNPlusOne) {
            if ($isNPlusOne) {
                $flags[] = 'n+1';
            }
            $body['flags'] = $flags;
            $id = $this->gaze->send('query', $body, $this->modifiers + $this->mods($flags, AppOrigin::resolve()));
            if ($id !== null && !$group['grouped']) {
                $group['rows'][] = $id;
            }

            return;
        }

        if (!$group['grouped']) {
            // threshold crossed: the first entry becomes the group, the separate rows go away
            $group['grouped'] = true;
            foreach ($group['rows'] as $rowId) {
                $this->gaze->remove($rowId);
            }
            $group['rows'] = [];
            $this->sendGroup($group);

            return;
        }
        // later runs: update at powers of two (bounded traffic), the rest at trace end
        $group['dirty'] = true;
        if (($group['count'] & ($group['count'] - 1)) === 0) {
            $this->sendGroup($group);
        }
    }

    /** Sends the pending updates of grouped N+1 entries (called when a trace ends). */
    public function flushGroups(Trace $trace): void
    {
        foreach ($trace->groups as $fingerprint => $group) {
            if ($group['grouped'] && $group['dirty']) {
                $this->sendGroup($trace->groups[$fingerprint]);
            }
        }
    }

    /** @param array<string, mixed> $group */
    private function sendGroup(array &$group): void
    {
        $group['dirty'] = false;
        if ($group['id'] === null) {
            return;
        }
        $flags = ['n+1'];
        if ($group['dup']) {
            $flags[] = 'dup';
        }
        if ($group['slow']) {
            $flags[] = 'slow';
        }
        $body = $group['body'];
        $body['flags'] = $flags;
        $body['count'] = $group['count'];
        $body['total_ms'] = round($group['total_ms'], 3);
        $body['executions'] = $group['executions'];
        if ($group['count'] > count($group['executions'])) {
            $body['executions_cut'] = $group['count'] - count($group['executions']);
        }
        $body['n_plus_one'] = array_filter([
            'count' => $group['count'],
            'threshold' => max(1, (int) $this->gaze->config('queries.n_plus_one_threshold', 3)),
            'relation' => $group['relation'],
        ], static fn ($v) => $v !== null);
        // No label: the app derives the "N+1 ×count" badge from the n+1 flag and the executions.
        $mods = $this->mods($flags, $group['origin']);
        $mods['color'] ??= 'orange';
        $this->gaze->send('query', $body, $this->modifiers + $mods, $group['id']);
    }

    /**
     * @param list<string> $flags
     * @param array<string, mixed>|null $origin
     * @return array<string, mixed>
     */
    private function mods(array $flags, ?array $origin): array
    {
        $mods = [];
        if ($origin !== null) {
            $mods['origin'] = $origin;
        }
        if ($flags) {
            $tags = [];
            foreach ($flags as $flag) {
                $tags[$flag] = true;
            }
            $mods['tags'] = $tags;
        }
        if (in_array('slow', $flags, true)) {
            $mods['color'] = 'red';
        }

        return $mods;
    }

    /** "App\Models\Post::author" when the query runs for a lazy-loaded relation. */
    public static function lazyRelation(): ?string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 80) as $frame) {
            if (($frame['function'] ?? '') === 'getRelationshipFromMethod' && isset($frame['object']) && is_object($frame['object'])) {
                $method = $frame['args'][0] ?? null;

                return $frame['object']::class . (is_string($method) ? '::' . $method : '');
            }
        }

        return null;
    }
}
