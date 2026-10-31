<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel;

use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\Support\AppOrigin;

/**
 * What gaze() returns in a Laravel app while Gaze is enabled: the PHP client's fluent API plus
 * Laravel-aware origins (first app frame, compiled Blade views mapped to their template).
 * The Laravel methods (model(), mailable(), showQueries(), ...) are macros (GazeMixin), so they
 * also exist on a plain Gaze instance.
 */
class LaravelGaze extends Gaze
{
    protected function emit(string $kind, array|\stdClass $body, array $modifiers = []): static
    {
        if (!isset($modifiers['origin'])) {
            $origin = AppOrigin::resolve();
            if ($origin !== null) {
                $modifiers['origin'] = $origin;
            }
        }

        return parent::emit($kind, $body, $modifiers);
    }

    /** A QueryException also shows its SQL, bindings and connection in the context. */
    public function exception(\Throwable $exception, array $meta = []): static
    {
        if ($exception instanceof \Illuminate\Database\QueryException && !isset($meta['query'])) {
            $meta['query'] = ['sql' => $exception->getSql(), 'bindings' => $exception->getBindings(), 'connection' => $exception->connectionName];
        }

        return parent::exception($exception, $meta);
    }

    /**
     * RFC-0001 exception body (class, message, code, frames with snippets, previous).
     *
     * @internal
     * @return array<string, mixed>
     */
    public static function exceptionPayload(\Throwable $e): array
    {
        return self::exceptionBody($e, 0);
    }
}
