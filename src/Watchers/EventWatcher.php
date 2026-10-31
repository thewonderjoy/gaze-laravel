<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\Support\Values;

/**
 * Every dispatched event (kind event: name, the event object or the payload), framework events
 * included, as in laravel-ray. The "*" listener is registered only when the watcher is switched
 * on: a wildcard listener makes Laravel dispatch view composing/creating events for every view.
 */
final class EventWatcher extends Watcher
{
    protected function register(Dispatcher $events): void
    {
        $events->listen('*', $this->handle(...));
    }

    /** @param array<int, mixed> $payload */
    public function handle(string $name, array $payload = []): void
    {
        if (!$this->on('events') || GazeManager::sending()) {
            return;
        }
        $this->gaze->currentTrace()->count('events');
        $body = GazeManager::quietly(function () use ($name, $payload): array {
            $object = $payload[0] ?? null;
            $classBased = is_object($object) && $object::class === $name;

            return [
                'name' => $name,
                'event' => $classBased ? Values::from($object) : null,
                'payload' => $classBased || $payload === [] ? null : Values::from($this->gaze->redactor()->sensitive($payload)),
                'class_based' => $classBased,
            ];
        });
        $this->send('event', $body);
    }
}
