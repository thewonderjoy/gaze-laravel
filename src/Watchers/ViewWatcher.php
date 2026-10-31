<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Watchers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\View\View;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\Support\Values;

/**
 * Rendered views, layouts, partials and components (kind view: name, path, relativePath, data
 * without Laravel's internals). Also used by gaze()->view($view).
 */
final class ViewWatcher extends Watcher
{
    protected function register(Dispatcher $events): void
    {
        $events->listen('composing:*', $this->handle(...));
    }

    /** @param array<int, mixed> $payload */
    public function handle(string $name, array $payload = []): void
    {
        $view = $payload[0] ?? null;
        if (!$view instanceof View || !$this->on('views') || GazeManager::sending()) {
            return;
        }
        $this->gaze->currentTrace()->count('views');
        $this->send('view', GazeManager::quietly(fn () => self::body($this->gaze, $view)));
    }

    /** @return array<string, mixed> */
    public static function body(GazeManager $gaze, View $view): array
    {
        $path = method_exists($view, 'getPath') ? (string) $view->getPath() : '';
        $base = rtrim(str_replace('\\', '/', $gaze->app->basePath()), '/') . '/';
        $normalized = str_replace('\\', '/', $path);
        $relative = str_starts_with($normalized, $base) ? substr($normalized, strlen($base)) : null;
        $data = $view->getData();
        unset($data['app'], $data['__env'], $data['obLevel'], $data['__data'], $data['__path']);
        if (isset($data['errors']) && is_object($data['errors']) && method_exists($data['errors'], 'any') && !$data['errors']->any()) {
            unset($data['errors']);
        }

        return [
            'name' => $view->name(),
            'path' => $path,
            'relativePath' => $relative,
            'data' => Values::from($gaze->redactor()->sensitive($data)),
        ];
    }
}
