<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Stringable;
use Illuminate\View\Compilers\BladeCompiler;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\Http\RelayController;
use TheWonderJoy\Gaze\Laravel\Support\Sql;
use TheWonderJoy\Gaze\Laravel\Support\Values;

/**
 * Gaze for Laravel. Auto-discovered.
 *
 * register(): merges config/gaze.php and configures the PHP client as early as possible, so a
 * gaze() call in another provider already sees the right host and the enabled switch.
 * boot(): macros, Blade directives and commands always (they are inert when disabled); everything
 * that observes the application (listeners, middleware, the dump handler, the relay route) only
 * while Gaze is enabled.
 */
final class GazeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/gaze.php', 'gaze');
        $this->app->singleton(GazeManager::class, static fn ($app) => new GazeManager($app));
        $this->app->make(GazeManager::class)->configureClient();
    }

    public function boot(): void
    {
        $gaze = $this->app->make(GazeManager::class);
        $gaze->configureClient(); // no-op unless the configuration changed after register()

        $this->publishes([__DIR__ . '/../config/gaze.php' => $this->app->configPath('gaze.php')], 'gaze-config');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\DoctorCommand::class, Console\CleanCommand::class]);
        }
        $this->registerMacros();
        $this->registerBladeDirectives();
        if (GazeManager::bool($gaze->config('define_ray_helper', false)) && !function_exists('ray')) {
            Gaze::registerRayHelper(); // after Composer "files": never shadows spatie/ray's ray()
        }

        if (!$gaze->enabled()) {
            Watchers\DumpWatcher::uninstall(); // a handler left by an earlier app in this process
            return;
        }
        $gaze->boot();
        if (!$gaze->listening(Watchers\DumpWatcher::class)) {
            Watchers\DumpWatcher::uninstall();
        }
        $this->registerRelayRoute($gaze);
    }

    private function registerMacros(): void
    {
        foreach (GazeMixin::macros() as $name => $macro) {
            if (!method_exists(Gaze::class, $name)) {
                Gaze::macro($name, $macro);
            }
        }

        Collection::macro('gaze', function (string $description = '') {
            /** @var Collection $this */
            $gaze = Gaze::make();
            if ($description !== '') {
                $gaze->label($description);
            }
            $gaze->send($this->all());

            return $this;
        });

        Stringable::macro('gaze', function (string $description = '') {
            /** @var Stringable $this */
            $gaze = Gaze::make();
            if ($description !== '') {
                $gaze->label($description);
            }
            $gaze->send($this->value());

            return $this;
        });

        QueryBuilder::macro('gaze', function (string $description = '') {
            /** @var QueryBuilder $this */
            $gaze = Gaze::make();
            if (!$gaze->enabled()) {
                return $this;
            }
            $template = $this->toSql();
            $connection = $this->getConnection();
            try {
                $sql = $this->toRawSql();
            } catch (\Throwable) {
                $sql = $template;
            }
            $gaze->custom('query', [
                'sql' => $sql,
                'template' => $template,
                'bindings' => array_map(Values::from(...), array_values($this->getBindings())),
                'connection' => $connection->getName(),
                'driver' => $connection->getDriverName(),
                'fingerprint' => Sql::fingerprint($template, (string) $connection->getName()),
                'built' => true,
            ], ['label' => $description !== '' ? $description : 'Query builder']);

            return $this;
        });

        if (class_exists(\Illuminate\Testing\TestResponse::class)) {
            \Illuminate\Testing\TestResponse::macro('gaze', function () {
                /** @var \Illuminate\Testing\TestResponse $this */
                Gaze::make()->testResponse($this);

                return $this;
            });
        }
    }

    private function registerBladeDirectives(): void
    {
        $this->callAfterResolving('blade.compiler', static function (BladeCompiler $blade): void {
            $blade->directive('gaze', static fn (string $expression) => $expression === ''
                ? '<?php gaze(); ?>'
                : "<?php gaze({$expression}); ?>");
            $blade->directive('gazeAll', static fn () => "<?php gaze()->label('View data')->send(\\Illuminate\\Support\\Arr::except(get_defined_vars()['__data'] ?? [], ['__env', 'app', 'errors', '__data', '__path'])); ?>");
            $blade->directive('gazeMeasure', static fn (string $expression) => $expression === ''
                ? '<?php gaze()->measure(); ?>'
                : "<?php gaze()->measure({$expression}); ?>");
        });
    }

    private function registerRelayRoute(GazeManager $gaze): void
    {
        if (!GazeManager::bool($gaze->config('relay.enabled', true)) || !$this->app->isLocal()) {
            return;
        }
        if (method_exists($this->app, 'routesAreCached') && $this->app->routesAreCached()) {
            return; // route:cache already holds it (it is registered while caching)
        }
        $path = '/' . trim((string) $gaze->config('relay.path', '_gaze'), '/');
        $this->app['router']->post($path, RelayController::class)->name('gaze.relay');
    }
}
