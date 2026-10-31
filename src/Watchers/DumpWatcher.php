<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Symfony\Component\VarDumper\VarDumper;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Support\Origin;

/**
 * dump() and dd() also go to Gaze. The previous VarDumper handler (Laravel's CLI/HTML dumper, or
 * Symfony's default) still runs first, so output reaches the browser or terminal as before.
 * dd() works because the client drains its queue at shutdown.
 */
final class DumpWatcher extends Watcher
{
    private ?DumpHandler $handler = null;

    protected function register(Dispatcher $events): void
    {
        $previous = VarDumper::setHandler(null);
        if ($previous instanceof DumpHandler) {
            $previous = $previous->previous; // a re-booted app (tests, Octane) must not stack handlers
        }
        if ($previous === null) {
            $previous = self::defaultHandler();
        }
        $this->handler = new DumpHandler($previous, $this);
        VarDumper::setHandler($this->handler);
        Origin::addInternalPath(dirname((string) (new \ReflectionClass(VarDumper::class))->getFileName()));
    }

    /**
     * Restores the handler a DumpHandler wrapped (an app booted again in the same process with
     * Gaze or the dumps watcher off: tests, Octane).
     */
    public static function uninstall(): void
    {
        $current = VarDumper::setHandler(null);
        VarDumper::setHandler($current instanceof DumpHandler ? $current->previous : $current);
    }

    /** @internal */
    public function capture(mixed $value, ?string $label): void
    {
        if (!$this->on('dumps') || GazeManager::sending()) {
            return;
        }
        try {
            $gaze = Gaze::make();
            if (!$gaze->enabled()) {
                return;
            }
            $gaze->send($value);
            $mods = $this->modifiers;
            if ($label !== null && $label !== '') {
                $mods['label'] = $label;
            }
            foreach ($mods as $name => $arg) {
                match ($name) {
                    'label' => $gaze->label((string) $arg),
                    'color' => $gaze->color((string) $arg),
                    'size' => $gaze->size((string) $arg),
                    'hidden' => $arg ? $gaze->hide() : null,
                    default => null,
                };
            }
        } catch (\Throwable) {
        }
    }

    /** Symfony's own default handler (created by VarDumper::dump() when none is set). */
    private static function defaultHandler(): ?callable
    {
        try {
            $register = new \ReflectionMethod(VarDumper::class, 'register');
            $register->invoke(null);
            $handler = VarDumper::setHandler(null);

            return $handler;
        } catch (\Throwable) {
            return null;
        }
    }
}
