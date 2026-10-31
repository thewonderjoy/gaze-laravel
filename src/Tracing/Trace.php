<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tracing;

use TheWonderJoy\Gaze\Laravel\Support\Values;

/**
 * One unit of work (HTTP request, queued job, artisan command, test) and the per-trace state the
 * watchers keep: query statistics, duplicate and N+1 detection, counters for the summary row.
 * All of it is dropped with the trace, so nothing grows across requests in Octane or across jobs
 * in a worker (laravel-ray's duplicate list never resets).
 */
final class Trace
{
    public readonly float $startedAt;

    public readonly int $startedHr;

    public bool $ended = false;

    /** @var array<string, mixed> extra trace fields (summary data collected along the way) */
    public array $meta = [];

    public int $queries = 0;

    public float $queryMs = 0.0;

    public int $duplicates = 0;

    public int $slow = 0;

    public int $nPlusOne = 0;

    /** @var array<string, int> exact query (sql + bindings) => runs */
    public array $exact = [];

    /** @var array<string, array<string, mixed>> fingerprint => group state (QueryWatcher) */
    public array $groups = [];

    /** @var list<array{sql: string, time_ms: float}> last queries, attached to exceptions */
    public array $recent = [];

    /** @var array<string, int> */
    public array $counts = ['exceptions' => 0, 'logs' => 0, 'mails' => 0, 'jobs' => 0, 'http' => 0, 'events' => 0, 'views' => 0, 'cache' => 0];

    public function __construct(
        public readonly ?string $id,
        public readonly string $kind,
        public string $name,
        public readonly ?string $parent = null,
        ?float $startedAt = null,
    ) {
        $this->startedAt = $startedAt ?? Values::nowMs();
        $this->startedHr = hrtime(true);
    }

    /** Pseudo trace for work outside any unit (bootstrap, a long-running process between units). */
    public static function root(): self
    {
        return new self(null, 'other', '');
    }

    public function isRoot(): bool
    {
        return $this->id === null;
    }

    /** The trace object sent on the wire (RFC-0001 Trace). @return array<string, mixed> */
    public function context(bool $ended = false): array
    {
        $trace = ['id' => (string) $this->id, 'kind' => $this->kind, 'name' => $this->name, 'started_at' => $this->startedAt];
        if ($this->parent !== null) {
            $trace['parent'] = $this->parent;
        }
        if ($ended) {
            $trace['ended'] = true;
        }

        return $trace;
    }

    public function elapsedMs(): float
    {
        return round((hrtime(true) - $this->startedHr) / 1e6, 3);
    }

    public function offsetMs(): float
    {
        return round(Values::nowMs() - $this->startedAt, 3);
    }

    public function count(string $what, int $by = 1): void
    {
        $this->counts[$what] = ($this->counts[$what] ?? 0) + $by;
    }

    /** Bounded memory for the root pseudo trace (it lives as long as the process). */
    public function trim(): void
    {
        if (count($this->exact) > 5000) {
            $this->exact = [];
        }
        if (count($this->groups) > 1000) {
            $this->groups = [];
        }
    }

    /** Query statistics for summary rows. @return array<string, int|float> */
    public function stats(): array
    {
        return array_filter([
            'queries' => $this->queries,
            'query_ms' => round($this->queryMs, 3),
            'duplicates' => $this->duplicates,
            'slow' => $this->slow,
            'n_plus_one' => $this->nPlusOne,
        ] + $this->counts, static fn ($v) => $v !== 0 && $v !== 0.0);
    }
}
