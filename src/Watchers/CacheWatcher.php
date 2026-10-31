<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

use Illuminate\Cache\Events\CacheEvent;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Contracts\Events\Dispatcher;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\Support\Values;

/**
 * Cache hits, misses, writes and forgets (kind cache: event, hit, key, store, value, ttl, tags).
 * Falsy values (0, "0", "", false) are shown (laravel-ray dropped them) and modifiers from
 * showCache()->... apply to every event, not only hits.
 */
final class CacheWatcher extends Watcher
{
    protected function register(Dispatcher $events): void
    {
        $events->listen([CacheHit::class, CacheMissed::class, KeyWritten::class, KeyForgotten::class], $this->handle(...));
    }

    public function handle(CacheEvent $event): void
    {
        if (!$this->on('cache') || GazeManager::sending()) {
            return;
        }
        $this->gaze->currentTrace()->count('cache');
        $body = GazeManager::quietly(function () use ($event): array {
            $body = [
                'event' => match (true) {
                    $event instanceof CacheHit => 'hit',
                    $event instanceof CacheMissed => 'missed',
                    $event instanceof KeyWritten => 'written',
                    $event instanceof KeyForgotten => 'forgotten',
                    default => strtolower(class_basename($event)),
                },
                'hit' => $event instanceof CacheHit,
                'key' => (string) $event->key,
                'store' => $event->storeName ?? null,
            ];
            if ($event instanceof CacheHit || $event instanceof KeyWritten) {
                $body['value'] = Values::from($this->gaze->redactor()->matches('inputs', (string) $event->key) ? $this->gaze->redactor()->mask() : $event->value);
            }
            if ($event instanceof KeyWritten) {
                $body['ttl'] = $event->seconds;
            }
            if ($event->tags) {
                $body['tags'] = array_values($event->tags);
            }

            return $body;
        });
        $this->send('cache', $body, $event instanceof CacheMissed ? ['color' => 'orange'] : []);
    }
}
