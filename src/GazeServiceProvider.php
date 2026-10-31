<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Stringable;
use Illuminate\View\Compilers\BladeCompiler;
use TheWonderJoy\Gaze\Config;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\Http\RelayController;
use TheWonderJoy\Gaze\Laravel\Support\Sql;
use TheWonderJoy\Gaze\Laravel\Support\Values;
use TheWonderJoy\Gaze\Noop;

/**
 * Gaze for Laravel. Auto-discovered.
 *
 * register(): merges config/gaze.php and configures the PHP client as early as possible, so a
 * gaze() call in another provider already sees the right host and the enabled switch.
 * boot(): Blade directives always, and commands in the console. While Gaze is off nothing else of
 * the package is loaded: gaze() returns gaze-php's Noop, and the ->gaze() macros on collections,
 * strings, query builders and test responses do nothing. Only while it is on: the manager, the
 * gaze() macros, and everything that observes the application (listeners, middleware, the dump
 * handler, the relay route).
 */
final class GazeServiceProvider extends ServiceProvider
{
    /**
     * GazeMixin macros that run a callback and return its result, by the callback's position: the Noop
     * keeps running them while Gaze is off.
     */
    public const RUNS = [
        'countQueries' => 0, 'showConditionalQueries' => 1, 'showSlowQueries' => 1, 'slowQueries' => 1,
        'showQueries' => 0, 'queries' => 0, 'showDuplicateQueries' => 0, 'showInsertQueries' => 0, 'showSelectQueries' => 0,
        'showUpdateQueries' => 0, 'showDeleteQueries' => 0, 'showEvents' => 0, 'events' => 0, 'showExceptions' => 0,
        'showJobs' => 0, 'jobs' => 0, 'showCache' => 0, 'showViews' => 0, 'views' => 0, 'showRequests' => 0, 'requests' => 0,
        'showHttpClientRequests' => 0, 'httpClientRequests' => 0, 'showMails' => 0, 'showDumps' => 0, 'showLogs' => 0,
        'showDeprecations' => 0,
    ];

    private bool $on = false;

    /** gaze.enabled, or (null) everywhere but production. */
    public static function enabled(Application $app): bool
    {
        $enabled = $app['config']->get('gaze.enabled');
        if ($enabled === null || $enabled === '') {
            return !$app->isProduction();
        }

        return self::bool($enabled);
    }

    public static function bool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/gaze.php', 'gaze');
        $this->configure();
    }

    public function boot(): void
    {
        $this->configure(); // again, in case the configuration changed after register()

        $this->publishes([__DIR__ . '/../config/gaze.php' => $this->app->configPath('gaze.php')], 'gaze-config');
        if ($this->app->runningInConsole()) {
            $this->commands([Console\DoctorCommand::class, Console\CleanCommand::class]);
        }
        $this->registerBladeDirectives();
        $rayHelper = self::bool($this->app['config']->get('gaze.define_ray_helper', false)) && !function_exists('ray');

        if (!$this->on) {
            $this->registerInertMacros();
            if ($rayHelper) {
                // gaze-php's ray()/rd(), without loading Gaze: off, they return the Noop
                require_once dirname((string) (new \ReflectionClass(Noop::class))->getFileName()) . '/ray-helper.php';
            }
            if (class_exists(Watchers\DumpWatcher::class, false)) {
                Watchers\DumpWatcher::uninstall(); // a handler left by an earlier app in this process
            }

            return;
        }
        $gaze = $this->app->make(GazeManager::class);
        $this->registerMacros();
        if ($rayHelper) {
            Gaze::registerRayHelper(); // after Composer "files": never shadows spatie/ray's ray()
        }
        $gaze->boot();
        if (!$gaze->listening(Watchers\DumpWatcher::class)) {
            Watchers\DumpWatcher::uninstall();
        }
        $this->registerRelayRoute($gaze);
    }

    /**
     * Off, only gaze-php's Config and Noop are loaded (gaze() returns the Noop); on, the manager
     * configures the client.
     */
    private function configure(): void
    {
        $this->on = self::enabled($this->app);
        if (!$this->on) {
            Config::set(['enabled' => false]);
            Noop::runs(self::RUNS);

            return;
        }
        if (!$this->app->bound(GazeManager::class)) {
            $this->app->singleton(GazeManager::class, static fn ($app) => new GazeManager($app));
        }
        $this->app->make(GazeManager::class)->configureClient(); // no-op unless the configuration changed
    }

    /** Off: the ->gaze() macros an app may still call return their object and do nothing else. */
    private function registerInertMacros(): void
    {
        $inert = function () {
            return $this;
        };
        Collection::macro('gaze', $inert);
        Stringable::macro('gaze', $inert);
        QueryBuilder::macro('gaze', $inert);
        if (class_exists(\Illuminate\Testing\TestResponse::class)) {
            \Illuminate\Testing\TestResponse::macro('gaze', $inert);
        }
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
        if (!self::bool($gaze->config('relay.enabled', true)) || !$this->app->isLocal()) {
            return;
        }
        if (method_exists($this->app, 'routesAreCached') && $this->app->routesAreCached()) {
            return; // route:cache already holds it (it is registered while caching)
        }
        $path = '/' . trim((string) $gaze->config('relay.path', '_gaze'), '/');
        $this->app['router']->post($path, RelayController::class)->name('gaze.relay');
    }
}
