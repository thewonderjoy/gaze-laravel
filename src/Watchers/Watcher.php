<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use TheWonderJoy\Gaze\Laravel\GazeManager;

/**
 * A watcher turns Laravel events into entries. Its listeners are registered only once one of its
 * switches is on (config, the app's hello.watchers, or showX() at runtime); a switch turned off
 * later makes the listener return at once.
 */
abstract class Watcher
{
    /** @var array<string, mixed> modifiers for every entry of this watcher (showX()->green()->label()) */
    public array $modifiers = [];

    private bool $listening = false;

    public function __construct(protected readonly GazeManager $gaze)
    {
    }

    final public function listen(): void
    {
        if ($this->listening) {
            return;
        }
        $this->listening = true;
        $this->register($this->gaze->events());
    }

    final public function listening(): bool
    {
        return $this->listening;
    }

    /** Forgets per-request state (Octane, tests). */
    public function reset(): void
    {
    }

    abstract protected function register(Dispatcher $events): void;

    protected function on(string $switch): bool
    {
        return $this->gaze->isOn($switch);
    }

    /**
     * @param array<string, mixed>|\stdClass $body
     * @param array<string, mixed> $modifiers
     */
    protected function send(string $kind, array|\stdClass $body, array $modifiers = []): ?string
    {
        return $this->gaze->send($kind, $body, $this->modifiers + $modifiers);
    }
}
