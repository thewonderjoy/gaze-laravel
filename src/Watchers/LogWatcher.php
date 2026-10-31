<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\Events\MessageLogged;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\Support\Values;

/**
 * Application log calls (kind app_log: level, message, redacted context), red for error and
 * above, orange for warnings.
 *
 * Deprecations (switch "deprecations"): Laravel routes PHP deprecations to the "deprecations"
 * channel, which still fires MessageLogged. They are recognised by the call stack
 * (HandleExceptions::handleDeprecationError) or by an ErrorException with E_(USER_)DEPRECATED
 * severity, never by a substring of the message (laravel-ray dropped any line containing
 * "deprecated"). Off: filtered out; on: sent as gray app_log entries labelled "Deprecation".
 */
final class LogWatcher extends Watcher
{
    protected function register(Dispatcher $events): void
    {
        $events->listen(MessageLogged::class, $this->handle(...));
    }

    public function handle(MessageLogged $event): void
    {
        if (GazeManager::sending()) {
            return;
        }
        $exception = $event->context['exception'] ?? null;
        $deprecation = ($exception instanceof \Throwable && self::isDeprecationException($exception))
            || (strtolower((string) $event->level) === 'warning' && self::fromDeprecationHandler());
        if ($deprecation) {
            if ($this->on('deprecations')) {
                $this->sendLog($event, ['label' => 'Deprecation', 'color' => 'gray'], 'deprecation');
            }

            return;
        }
        if (!$this->on('logs')) {
            return;
        }
        if ($exception instanceof \Throwable && $this->on('exceptions') && $this->gaze->listening(ExceptionWatcher::class)) {
            return; // the exception watcher shows it with frames
        }
        $message = is_string($event->message) ? $event->message : '';
        if ($this->on('mails') && $this->gaze->listening(MailWatcher::class) && str_contains($message, 'Message-ID:') && str_contains($message, 'To:')) {
            return; // the "log" mail driver writing a mail the mail watcher already sent
        }
        $level = strtolower((string) $event->level);
        $mods = match ($level) {
            'emergency', 'alert', 'critical', 'error' => ['color' => 'red'],
            'warning' => ['color' => 'orange'],
            default => [],
        };
        $this->sendLog($event, $mods, $level);
    }

    /** @param array<string, mixed> $mods */
    private function sendLog(MessageLogged $event, array $mods, string $level): void
    {
        $this->gaze->currentTrace()->count('logs');
        $body = GazeManager::quietly(function () use ($event, $level): array {
            $message = $event->message;
            if (!is_string($message)) {
                $message = is_scalar($message) || $message instanceof \Stringable ? (string) $message : (json_encode($message, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: get_debug_type($message));
            }
            [$message] = Values::cut($message, 65536);
            $context = $event->context;
            if (($context['exception'] ?? null) instanceof \Throwable) {
                $e = $context['exception'];
                $context['exception'] = ['class' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
            }
            $redactor = $this->gaze->redactor();
            $context = $context === [] ? null : Values::from($redactor->keys('inputs', $redactor->sensitive($context)));

            return ['level' => $level, 'message' => $message, 'context' => $context];
        });
        $this->send('app_log', $body, $mods);
    }

    public static function isDeprecationException(\Throwable $e): bool
    {
        return $e instanceof \ErrorException && in_array($e->getSeverity(), [E_DEPRECATED, E_USER_DEPRECATED], true);
    }

    private static function fromDeprecationHandler(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) {
            if (($frame['function'] ?? '') === 'handleDeprecationError'
                && is_a((string) ($frame['class'] ?? ''), \Illuminate\Foundation\Bootstrap\HandleExceptions::class, true)) {
                return true;
            }
        }

        return false;
    }
}
