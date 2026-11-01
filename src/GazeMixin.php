<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Mailable;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\Support\Values;
use TheWonderJoy\Gaze\Laravel\Support\WatcherModifiers;
use TheWonderJoy\Gaze\Laravel\Tracing\TraceManager;
use TheWonderJoy\Gaze\Laravel\Watchers\MailWatcher;
use TheWonderJoy\Gaze\Laravel\Watchers\QueryWatcher;
use TheWonderJoy\Gaze\Laravel\Watchers\ViewWatcher;

/**
 * The Laravel methods of gaze(), registered as gaze-php macros (Gaze::mixin()), with
 * laravel-ray's names: model/models, mailable, context/hiddenContext, env, view, countQueries,
 * testResponse and the watcher switches showX()/X()/stopShowingX(). Every method is inert when
 * Gaze is disabled (callbacks given to showX()/countQueries() still run and return their result).
 *
 * @mixin Gaze
 */
final class GazeMixin
{
    /** Method => watcher switch, for showX() / X() / stopShowingX(). */
    public const SWITCHES = [
        'Queries' => 'queries',
        'DuplicateQueries' => 'duplicate_queries',
        'SlowQueries' => 'slow_queries',
        'InsertQueries' => 'insert_queries',
        'SelectQueries' => 'select_queries',
        'UpdateQueries' => 'update_queries',
        'DeleteQueries' => 'delete_queries',
        'Events' => 'events',
        'Exceptions' => 'exceptions',
        'Jobs' => 'jobs',
        'Cache' => 'cache',
        'Views' => 'views',
        'Requests' => 'requests',
        'HttpClientRequests' => 'http_client',
        'Mails' => 'mails',
        'Dumps' => 'dumps',
        'Logs' => 'logs',
        'Deprecations' => 'deprecations',
    ];

    /** Short aliases laravel-ray has: queries(), events(), jobs(), views(), requests(), httpClientRequests(), slowQueries(). */
    public const ALIASES = ['queries', 'events', 'jobs', 'views', 'requests', 'httpClientRequests', 'slowQueries'];

    /** @return array<string, \Closure> every macro of this mixin, the generated switches included */
    public static function macros(): array
    {
        $mixin = new self();
        $macros = [];
        foreach ((new \ReflectionClass($mixin))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (!$method->isStatic() && $method->getNumberOfParameters() === 0 && $method->getReturnType()?->__toString() === 'Closure') {
                $macros[$method->name] = $method->invoke($mixin);
            }
        }
        foreach (self::SWITCHES as $suffix => $switch) {
            if ($suffix === 'SlowQueries') {
                continue; // takes a threshold, defined below
            }
            $macros['show' . $suffix] = function (?callable $callback = null) use ($switch) {
                /** @var Gaze $this */
                return GazeMixin::toggle($this, $switch, $callback);
            };
            $macros['stopShowing' . $suffix] = function () use ($switch) {
                /** @var Gaze $this */
                if ($this->enabled() && app()->bound(GazeManager::class)) {
                    app(GazeManager::class)->disable($switch);
                }

                return $this;
            };
        }
        foreach (self::ALIASES as $alias) {
            $target = 'show' . ucfirst($alias);
            if (isset($macros[$target])) {
                $macros[$alias] = $macros[$target];
            }
        }

        return $macros;
    }

    /** @internal showX($callback): switch on, run, restore; without a callback return the modifier proxy. */
    public static function toggle(Gaze $gaze, string $switch, ?callable $callback): mixed
    {
        if (!$gaze->enabled() || !app()->bound(GazeManager::class)) {
            return $callback !== null ? $callback() : $gaze;
        }
        $manager = app(GazeManager::class);
        $before = $manager->override($switch);
        $manager->enable($switch);
        if ($callback !== null) {
            try {
                return $callback(); // the result is returned whether or not the closure declares a return type
            } finally {
                $manager->restore($switch, $before);
            }
        }
        $class = GazeManager::watcherClassFor($switch);

        return new WatcherModifiers($class !== null ? $manager->watcher($class) : null);
    }

    public function showSlowQueries(): \Closure
    {
        return function (int|float|null $milliseconds = null, ?callable $callback = null) {
            /** @var Gaze $this */
            if (!$this->enabled() || !app()->bound(GazeManager::class) || $milliseconds === null) {
                return GazeMixin::toggle($this, 'slow_queries', $callback);
            }
            $watcher = app(GazeManager::class)->watcher(QueryWatcher::class);
            $before = $watcher->slowThresholdMs;
            $watcher->slowThresholdMs = (float) $milliseconds;
            if ($callback === null) {
                return GazeMixin::toggle($this, 'slow_queries', null);
            }
            try {
                return GazeMixin::toggle($this, 'slow_queries', $callback);
            } finally {
                $watcher->slowThresholdMs = $before; // like the switch, the threshold lasts for the callback only
            }
        };
    }

    public function stopShowingSlowQueries(): \Closure
    {
        return function () {
            /** @var Gaze $this */
            if ($this->enabled() && app()->bound(GazeManager::class)) {
                $manager = app(GazeManager::class);
                $manager->disable('slow_queries');
                $manager->watcher(QueryWatcher::class)->slowThresholdMs = null; // back to queries.slow_threshold_ms
            }

            return $this;
        };
    }

    /** showConditionalQueries(fn (QueryExecuted $q) => ..., ?callable $callback, string $name = 'default') */
    public function showConditionalQueries(): \Closure
    {
        return function (\Closure $condition, ?callable $callback = null, string $name = 'default') {
            /** @var Gaze $this */
            if (!$this->enabled() || !app()->bound(GazeManager::class)) {
                return $callback !== null ? $callback() : $this;
            }
            $watcher = app(GazeManager::class)->watcher(QueryWatcher::class);
            $watcher->listen();
            $previous = $watcher->conditions[$name] ?? null;
            $watcher->conditions[$name] = $condition; // the same name replaces, never leaks a listener
            if ($callback === null) {
                return new WatcherModifiers($watcher);
            }
            try {
                return $callback();
            } finally {
                if ($previous === null) {
                    unset($watcher->conditions[$name]);
                } else {
                    $watcher->conditions[$name] = $previous;
                }
            }
        };
    }

    public function stopShowingConditionalQueries(): \Closure
    {
        return function (string $name = 'default') {
            /** @var Gaze $this */
            if (app()->bound(GazeManager::class)) {
                unset(app(GazeManager::class)->watcher(QueryWatcher::class)->conditions[$name]);
            }

            return $this;
        };
    }

    /** countQueries(fn () => ...): runs the callback, sends a "Queries" table (count, total time), returns the result. */
    public function countQueries(): \Closure
    {
        return function (callable $callback) {
            /** @var Gaze $this */
            if (!$this->enabled() || !app()->bound(GazeManager::class)) {
                return $callback();
            }
            [$result, $count, $ms] = app(GazeManager::class)->watcher(QueryWatcher::class)->count($callback);
            $this->table(['Count' => $count, 'Total time' => $ms . ' ms'], 'Queries');

            return $result;
        };
    }

    /** model($user, $posts, ...): one "model" entry per model (iterables are flattened). */
    public function model(): \Closure
    {
        return function (mixed ...$models) {
            /** @var Gaze $this */
            if (!$this->enabled()) {
                return $this;
            }
            $flat = [];
            $walk = static function (mixed $item) use (&$walk, &$flat): void {
                if ($item instanceof Model) {
                    $flat[] = $item;
                } elseif (is_iterable($item)) {
                    foreach ($item as $inner) {
                        $walk($inner);
                    }
                }
            };
            foreach ($models as $model) {
                $walk($model);
            }
            foreach ($flat as $i => $model) {
                $body = GazeManager::quietly(static fn () => GazeMixin::modelBody($model));
                ($i === 0 ? $this : Gaze::make())->custom('model', $body);
            }

            return $this;
        };
    }

    public function models(): \Closure
    {
        return function (iterable $models) {
            /** @var Gaze $this */
            return $this->model($models);
        };
    }

    /** @return array<string, mixed> IPC "model" body: class, attributes, relations */
    public static function modelBody(Model $model): array
    {
        $redactor = app()->bound(GazeManager::class) ? app(GazeManager::class)->redactor() : null;
        $attributes = $model->attributesToArray(); // honours $hidden, casts and appends
        if ($redactor !== null) {
            $attributes = $redactor->keys('inputs', $attributes);
        }
        $relations = [];
        foreach ($model->getRelations() as $name => $related) {
            $relations[$name] = $related instanceof Model ? $related->attributesToArray()
                : (is_iterable($related) ? array_map(static fn ($m) => $m instanceof Model ? $m->attributesToArray() : $m, is_array($related) ? $related : iterator_to_array($related)) : $related);
        }

        return [
            'class' => $model::class,
            'key' => $model->getKey(),
            'exists' => $model->exists,
            'attributes' => Values::from($attributes),
            'relations' => $relations === [] ? null : Values::from($relations),
        ];
    }

    /** mailable($mail, ...): renders each mailable with its envelope (from, to, cc, bcc, subject). Never swaps a Mail::fake(). */
    public function mailable(): \Closure
    {
        return function (Mailable ...$mailables) {
            /** @var Gaze $this */
            if (!$this->enabled()) {
                return $this;
            }
            foreach ($mailables as $i => $mailable) {
                $body = GazeManager::quietly(static function () use ($mailable): array {
                    $html = '';
                    try {
                        $html = (string) $mailable->render();
                    } catch (\Throwable $e) {
                        $html = '<p>Could not render: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</p>';
                    }
                    [$html] = Values::cut($html, MailWatcher::MAX_HTML);

                    return [
                        'html' => $html,
                        'subject' => $mailable->subject,
                        'from' => Values::addresses($mailable->from),
                        'to' => Values::addresses($mailable->to),
                        'cc' => Values::addresses($mailable->cc),
                        'bcc' => Values::addresses($mailable->bcc),
                        'reply_to' => Values::addresses($mailable->replyTo),
                        'mailable' => $mailable::class,
                    ];
                });
                ($i === 0 ? $this : Gaze::make())->custom('mail', $body);
            }

            return $this;
        };
    }

    /** context() / context('key', ...): Laravel Context data, labelled "Context". */
    public function context(): \Closure
    {
        return function (string ...$keys) {
            /** @var Gaze $this */
            if (!$this->enabled() || !class_exists(\Illuminate\Support\Facades\Context::class)) {
                return $this;
            }
            $data = $keys ? \Illuminate\Support\Facades\Context::only($keys) : \Illuminate\Support\Facades\Context::all();

            return $this->label('Context')->send($data);
        };
    }

    /** hiddenContext() / hiddenContext('key', ...): hidden Context data, labelled "Hidden Context" (Gaze's own key left out). */
    public function hiddenContext(): \Closure
    {
        return function (string ...$keys) {
            /** @var Gaze $this */
            if (!$this->enabled() || !class_exists(\Illuminate\Support\Facades\Context::class)) {
                return $this;
            }
            $data = $keys ? \Illuminate\Support\Facades\Context::onlyHidden($keys) : \Illuminate\Support\Facades\Context::allHidden();
            unset($data[TraceManager::CONTEXT_KEY]);

            return $this->label('Hidden Context')->send($data);
        };
    }

    /** env() / env(['APP_NAME', ...], $file): the .env file as a table; secrets (APP_KEY, *_KEY, *_SECRET, *_PASSWORD, ...) are masked. */
    public function env(): \Closure
    {
        return function (?array $only = null, ?string $filePath = null) {
            /** @var Gaze $this */
            if (!$this->enabled()) {
                return $this;
            }
            $file = $filePath ?? app()->environmentFilePath();
            $contents = @file_get_contents($file);
            if ($contents === false) {
                return $this->text("Environment file not found: {$file}")->label('.env');
            }
            try {
                $values = \Dotenv\Dotenv::parse($contents);
            } catch (\Throwable $e) {
                return $this->text('Could not parse ' . $file . ': ' . $e->getMessage())->label('.env');
            }
            if ($only !== null) {
                $values = array_intersect_key($values, array_flip($only));
            }
            $redactor = app()->bound(GazeManager::class) ? app(GazeManager::class)->redactor() : null;
            foreach ($values as $key => $value) {
                if ($redactor !== null && ($redactor->matches('env', $key) || $redactor->matches('inputs', $key)) && $value !== null && $value !== '') {
                    $values[$key] = $redactor->mask();
                }
            }

            return $this->table($values, '.env');
        };
    }

    /** view($view): the view's name, path and data. */
    public function view(): \Closure
    {
        return function (View $view) {
            /** @var Gaze $this */
            if (!$this->enabled() || !app()->bound(GazeManager::class)) {
                return $this;
            }

            return $this->custom('view', GazeManager::quietly(static fn () => ViewWatcher::body(app(GazeManager::class), $view)));
        };
    }

    /** testResponse($response): status, headers, content and decoded JSON of a TestResponse. */
    public function testResponse(): \Closure
    {
        return function (object $testResponse) {
            /** @var Gaze $this */
            if (!$this->enabled()) {
                return $this;
            }
            $response = property_exists($testResponse, 'baseResponse') ? $testResponse->baseResponse : $testResponse;
            if (!$response instanceof \Symfony\Component\HttpFoundation\Response) {
                return $this->send($testResponse);
            }
            $redactor = app()->bound(GazeManager::class) ? app(GazeManager::class)->redactor() : null;
            $content = (string) $response->getContent();
            $json = json_decode($content, true);
            [$text] = Values::cut($content, 65536);
            $headers = [];
            foreach ($response->headers->all() as $name => $values) {
                $headers[$name] = implode(', ', $values);
            }
            if ($redactor !== null) {
                $headers = $redactor->headers($headers);
            }

            return $this->custom('response', [
                'status' => $response->getStatusCode(),
                'headers' => Values::from($headers),
                'content' => $text,
                'json' => is_array($json) ? Values::from($redactor?->keys('inputs', $json) ?? $json) : null,
            ], $response->getStatusCode() >= 500 ? ['color' => 'red'] : ($response->getStatusCode() >= 400 ? ['color' => 'orange'] : []));
        };
    }
}
