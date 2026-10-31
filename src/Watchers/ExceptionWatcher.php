<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\LaravelGaze;
use TheWonderJoy\Gaze\Laravel\Support\Values;

/**
 * Reported exceptions (everything the handler logs with context['exception']): any Throwable,
 * PHP Errors included (laravel-ray only took \Exception). The entry carries frames with code
 * snippets and a context with the request, the route, the trace's last queries and, for a
 * QueryException, the failing SQL. Nothing is computed unless the watcher is on.
 */
final class ExceptionWatcher extends Watcher
{
    protected function register(Dispatcher $events): void
    {
        $events->listen(MessageLogged::class, $this->handle(...));
    }

    public function handle(MessageLogged $event): void
    {
        $e = $event->context['exception'] ?? null;
        if (!$e instanceof \Throwable || !$this->on('exceptions') || GazeManager::sending()) {
            return;
        }
        if (LogWatcher::isDeprecationException($e)) {
            return; // handled (or filtered) by the deprecations switch of the log watcher
        }
        $trace = $this->gaze->currentTrace();
        $trace->count('exceptions');
        $body = GazeManager::quietly(function () use ($e, $event, $trace): array {
            $body = LaravelGaze::exceptionPayload($e);
            $context = ['level' => (string) $event->level];
            if ((string) $event->message !== '' && (string) $event->message !== $e->getMessage()) {
                $context['message'] = (string) $event->message;
            }
            if ($e instanceof QueryException) {
                $context['query'] = ['sql' => $e->getSql(), 'bindings' => $e->getBindings(), 'connection' => $e->connectionName ?? null];
            }
            $request = $this->gaze->requestContext();
            if ($request) {
                $context['request'] = $request;
            }
            if ($trace->recent) {
                $context['recent_queries'] = $trace->recent;
            }
            $extra = $event->context;
            unset($extra['exception']);
            if ($extra) {
                $context['log_context'] = $this->gaze->redactor()->keys('inputs', $this->gaze->redactor()->sensitive($extra));
            }
            $body['context'] = Values::from($context);

            return $body;
        });
        $this->send('exception', $body, ['color' => 'red']);
    }
}
