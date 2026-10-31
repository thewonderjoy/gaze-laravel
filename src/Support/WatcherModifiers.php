<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Support;

use TheWonderJoy\Gaze\Laravel\Watchers\Watcher;

/**
 * Returned by gaze()->showQueries() and the other showX() methods: modifiers chained on it apply
 * to every later entry of that watcher. Every method returns $this, so any number can be
 * chained (laravel-ray's RayProxy returned null after the first).
 *
 * @method self green()
 * @method self orange()
 * @method self red()
 * @method self purple()
 * @method self blue()
 * @method self gray()
 * @method self grey()
 * @method self color(string $color)
 * @method self small()
 * @method self large()
 * @method self size(string $size)
 * @method self label(string $label)
 * @method self hide()
 */
final class WatcherModifiers
{
    public function __construct(private readonly ?Watcher $watcher)
    {
    }

    /** @param array<int, mixed> $arguments */
    public function __call(string $method, array $arguments): self
    {
        if ($this->watcher === null) {
            return $this;
        }
        $m = &$this->watcher->modifiers;
        $method = strtolower($method);
        $arg = strtolower((string) ($arguments[0] ?? ''));
        $arg = $arg === 'grey' ? 'gray' : $arg;
        match ($method) {
            'green', 'orange', 'red', 'purple', 'blue', 'gray' => $m['color'] = $method,
            'grey' => $m['color'] = 'gray',
            'color' => in_array($arg, ['green', 'orange', 'red', 'purple', 'blue', 'gray'], true) ? $m['color'] = $arg : null,
            'small' => $m['size'] = 'sm',
            'large' => $m['size'] = 'lg',
            'size' => $m['size'] = match ($arg) {
                'sm', 'small' => 'sm',
                'lg', 'large' => 'lg',
                default => 'md',
            },
            'label' => $m['label'] = mb_substr((string) ($arguments[0] ?? ''), 0, 200),
            'hide' => $m['hidden'] = true,
            default => throw new \BadMethodCallException("Method {$method} cannot be applied to watcher entries."),
        };

        return $this;
    }

    public function watcher(): ?Watcher
    {
        return $this->watcher;
    }
}
