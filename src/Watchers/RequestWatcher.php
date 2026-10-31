<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Http\Events\RequestHandled;
use TheWonderJoy\Gaze\Laravel\GazeManager;

/**
 * Request details: IP, headers, payload, session, response preview (all redacted).
 *
 * With HTTP traces on (the default) they ride on the request's summary row; this watcher then
 * registers nothing. With traces off it sends its own "request" entry on RequestHandled.
 */
final class RequestWatcher extends Watcher
{
    protected function register(Dispatcher $events): void
    {
        if (GazeManager::bool($this->gaze->config('traces.enabled', true)) && GazeManager::bool($this->gaze->config('traces.http', true))) {
            return;
        }
        $events->listen(RequestHandled::class, $this->handle(...));
    }

    public function handle(RequestHandled $event): void
    {
        if (!$this->on('requests') || GazeManager::sending()) {
            return;
        }
        $body = $this->gaze->requestSummary($event->request, $event->response);
        $status = (int) ($body['status'] ?? 0);
        $this->send('request', $body, $status >= 500 ? ['color' => 'red'] : ($status >= 400 ? ['color' => 'orange'] : []));
    }
}
