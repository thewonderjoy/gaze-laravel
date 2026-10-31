<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

/**
 * @internal The VarDumper handler installed by DumpWatcher.
 */
final class DumpHandler
{
    /** @var (callable(mixed, ?string): mixed)|null */
    public $previous;

    public function __construct(?callable $previous, private readonly DumpWatcher $watcher)
    {
        $this->previous = $previous;
    }

    public function __invoke(mixed $value, ?string $label = null): mixed
    {
        $result = null;
        if ($this->previous !== null) {
            $result = ($this->previous)($value, $label);
        }
        $this->watcher->capture($value, $label);

        return $result;
    }
}
