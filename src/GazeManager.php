<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel;

use Composer\InstalledVersions;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use TheWonderJoy\Gaze\Client;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\Support\AppOrigin;
use TheWonderJoy\Gaze\Laravel\Support\Redactor;
use TheWonderJoy\Gaze\Laravel\Support\Values;
use TheWonderJoy\Gaze\Laravel\Tracing\Trace;
use TheWonderJoy\Gaze\Laravel\Tracing\TraceManager;
use TheWonderJoy\Gaze\Laravel\Watchers;
use TheWonderJoy\Gaze\Support\Origin;

/**
 * Per-application state of Gaze for Laravel: settings, watcher switches (config < app via
 * hello.watchers < this process via showX()), the watchers, traces and redaction.
 */
final class GazeManager
{
    public const VERSION = '1.0.3';

    public const CLIENT_NAME = 'thewonderjoy/gaze-laravel';

    /** Watcher switch names (config keys, hello.watchers keys). */
    public const WATCHERS = [
        'exceptions', 'logs', 'mails', 'jobs', 'events', 'dumps',
        'queries', 'duplicate_queries', 'slow_queries', 'insert_queries', 'select_queries', 'update_queries', 'delete_queries',
        'views', 'cache', 'requests', 'http_client', 'deprecations',
    ];

    /** Watcher class => the switches that need its listeners. */
    private const CLASSES = [
        Watchers\ExceptionWatcher::class => ['exceptions'],
        Watchers\LogWatcher::class => ['logs', 'deprecations'],
        Watchers\MailWatcher::class => ['mails'],
        Watchers\JobWatcher::class => ['jobs'],
        Watchers\EventWatcher::class => ['events'],
        Watchers\DumpWatcher::class => ['dumps'],
        Watchers\QueryWatcher::class => ['queries', 'duplicate_queries', 'slow_queries', 'insert_queries', 'select_queries', 'update_queries', 'delete_queries'],
        Watchers\ViewWatcher::class => ['views'],
        Watchers\CacheWatcher::class => ['cache'],
        Watchers\RequestWatcher::class => ['requests'],
        Watchers\HttpClientWatcher::class => ['http_client'],
    ];

    private static bool $sending = false;

    private bool $booted = false;

    /** @var array<string, bool> */
    private array $configured = [];

    /** @var array<string, bool> from the app (hello.watchers) */
    private array $remote = [];

    /** @var array<string, bool> showX()/stopShowingX() in this process */
    private array $overrides = [];

    /** @var array<class-string, Watchers\Watcher> */
    private array $watchers = [];

    private ?TraceManager $traces = null;

    private Redactor $redactor;

    private float $remoteCheckedAt = 0.0;

    public function __construct(public readonly Application $app)
    {
        $this->redactor = new Redactor((array) $this->config('redact', []));
    }

    // ------------------------------------------------------------------ settings

    public function config(string $key, mixed $default = null): mixed
    {
        return $this->app['config']->get('gaze.' . $key, $default);
    }

    /** gaze.enabled, or (null) everywhere but production. */
    public function enabled(): bool
    {
        return GazeServiceProvider::enabled($this->app);
    }

    public static function bool(mixed $value): bool
    {
        return GazeServiceProvider::bool($value);
    }

    /**
     * Configures the PHP client while Gaze is on (off, the provider switches gaze-php off without it).
     * Called in register() and again in boot(), in case configuration changed in between.
     */
    public function configureClient(): void
    {
        $enabled = $this->enabled();
        $options = (array) $this->config('client', []);
        $options['enabled'] = $enabled;
        $host = $this->config('host');
        if (is_string($host) && $host !== '') {
            $options['host'] = $host;
        }
        $port = $this->config('port');
        if (is_numeric($port) && (int) $port > 0) {
            $options['port'] = (int) $port;
        }
        $token = $this->config('token');
        if (is_string($token) && $token !== '') {
            $options['token'] = $token;
        }
        $name = $this->config('project');
        $name = is_string($name) && $name !== '' ? $name : (string) $this->app['config']->get('app.name', 'Laravel');
        $options['project'] = ['name' => $name, 'root' => $this->app->basePath()];
        $options['client_name'] = self::CLIENT_NAME;
        $options['client_version'] = self::packageVersion();
        $options['framework'] = 'laravel';
        $options['framework_version'] = $this->app->version();
        if ($options === $this->applied) {
            return;
        }
        $this->applied = $options;
        Gaze::configure($options);
    }

    /** @var array<string, mixed>|null options last given to Gaze::configure() */
    private ?array $applied = null;

    public static function packageVersion(): string
    {
        try {
            if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled(self::CLIENT_NAME)) {
                $v = InstalledVersions::getPrettyVersion(self::CLIENT_NAME);
                if (is_string($v) && $v !== '' && !str_starts_with($v, 'dev-') && !str_contains($v, 'no-version-set')) {
                    return ltrim($v, 'v');
                }
            }
        } catch (\Throwable) {
        }

        return self::VERSION;
    }

    // ------------------------------------------------------------------ boot

    /** Everything that runs only while Gaze is enabled. */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;
        Gaze::setFactory(static fn () => new LaravelGaze());
        Origin::addInternalPath(__DIR__);
        // .../src/Illuminate in laravel/framework, .../vendor/illuminate with the split packages
        Origin::addInternalPath(dirname((string) (new \ReflectionClass(\Illuminate\Support\ServiceProvider::class))->getFileName(), 2));
        AppOrigin::$skipVendor = self::bool($this->config('origin.skip_vendor', true));
        $compiled = $this->app['config']->get('view.compiled');
        AppOrigin::$compiledPath = is_string($compiled) ? $compiled : null;
        AppOrigin::$entryScripts = [$this->app->publicPath('index.php'), $this->app->basePath('artisan')];

        $this->resolveSwitches();
        // traces first: a watcher's JobProcessing entry must already belong to the job's trace
        if (self::bool($this->config('traces.enabled', true))) {
            $this->traces()->listen();
        } else {
            $this->traces()->listenForDrain();
        }
        $this->listenSwitchedOn();
    }

    /** Registers the listeners of every watcher with a switch on (once per watcher). */
    private function listenSwitchedOn(): void
    {
        foreach (self::CLASSES as $class => $switches) {
            foreach ($switches as $switch) {
                if ($this->isOn($switch)) {
                    $this->watcher($class)->listen();
                    break;
                }
            }
        }
    }

    public function events(): Dispatcher
    {
        return $this->app['events'];
    }

    public function redactor(): Redactor
    {
        return $this->redactor;
    }

    public function traces(): TraceManager
    {
        return $this->traces ??= new TraceManager($this);
    }

    /**
     * @template T of Watchers\Watcher
     * @param class-string<T> $class
     * @return T
     */
    public function watcher(string $class): Watchers\Watcher
    {
        return $this->watchers[$class] ??= new $class($this);
    }

    /** @return class-string<Watchers\Watcher>|null */
    public static function watcherClassFor(string $switch): ?string
    {
        foreach (self::CLASSES as $class => $switches) {
            if (in_array($switch, $switches, true)) {
                return $class;
            }
        }

        return null;
    }

    /** True when the listeners of watcher $class are registered. */
    public function listening(string $class): bool
    {
        return isset($this->watchers[$class]) && $this->watchers[$class]->listening();
    }

    // ------------------------------------------------------------------ switches

    public function isOn(string $switch): bool
    {
        return $this->overrides[$switch] ?? $this->remote[$switch] ?? $this->configured[$switch] ?? false;
    }

    /** @return array<string, bool> effective switches */
    public function switches(): array
    {
        $out = [];
        foreach (self::WATCHERS as $name) {
            $out[$name] = $this->isOn($name);
        }

        return $out;
    }

    /** @return array<string, bool> */
    public function remoteSwitches(): array
    {
        return $this->remote;
    }

    /** Runtime switch for this process (showX()); registers the watcher's listeners on first use. */
    public function enable(string $switch): void
    {
        $this->overrides[$switch] = true;
        if ($this->booted && ($class = self::watcherClassFor($switch)) !== null) {
            $this->watcher($class)->listen();
        }
    }

    public function disable(string $switch): void
    {
        $this->overrides[$switch] = false;
    }

    /** Restores a switch to what it was before enable()/disable() (showX($callback)). */
    public function restore(string $switch, ?bool $override): void
    {
        if ($override === null) {
            unset($this->overrides[$switch]);
        } else {
            $this->overrides[$switch] = $override;
        }
    }

    public function override(string $switch): ?bool
    {
        return $this->overrides[$switch] ?? null;
    }

    /** Config switches, then the app's hello.watchers and hello.redact. */
    public function resolveSwitches(): void
    {
        $config = (array) $this->config('watchers', []);
        foreach (self::WATCHERS as $name) {
            $this->configured[$name] = array_key_exists($name, $config) && self::bool($config[$name]);
        }
        $this->remote = [];
        $extra = [];
        if (self::bool($this->config('remote_watchers', true))) {
            $hello = Gaze::hello();
            foreach ((array) ($hello['watchers'] ?? []) as $name => $on) {
                if (in_array($name, self::WATCHERS, true) && is_bool($on)) {
                    $this->remote[$name] = $on;
                }
            }
            $extra = array_values(array_filter((array) ($hello['redact'] ?? []), 'is_string'));
        }
        $this->redactor->withExtra($extra);
        $this->remoteCheckedAt = microtime(true);
    }

    /**
     * Long-running workers (queue, Octane): re-reads the app's switches at most every hello_ttl
     * seconds, between units of work, and registers watchers the app switched on.
     */
    public function refreshRemote(): void
    {
        if (!$this->booted || !self::bool($this->config('remote_watchers', true))) {
            return;
        }
        $ttl = max(1, (int) (Gaze::config()['hello_ttl'] ?? 60));
        if (microtime(true) - $this->remoteCheckedAt < $ttl) {
            return;
        }
        $client = Client::current();
        if ($client !== null) {
            $client->drain();
            $client->reconfigure(true); // forget the in-process "up" memo: the next call re-reads the shared hello
        }
        $this->resolveSwitches();
        $this->listenSwitchedOn();
    }

    /** Octane / tests: forget per-request state (traces, pending HTTP client calls, counters). */
    public function resetRequestState(): void
    {
        $this->traces?->reset();
        foreach ($this->watchers as $watcher) {
            $watcher->reset();
        }
    }

    // ------------------------------------------------------------------ sending

    /**
     * Sends one entry; returns its id (null when nothing was sent). Never throws, never recurses
     * (a watcher triggered while an entry is being built is ignored).
     *
     * @param array<string, mixed>|\stdClass $body
     * @param array<string, mixed> $modifiers color, label, size, hidden, origin, trace, tags
     */
    public function send(string $kind, array|\stdClass $body, array $modifiers = [], ?string $updateId = null): ?string
    {
        if (self::$sending || $this->inIgnoredRequest()) {
            return null;
        }
        self::$sending = true;
        try {
            $gaze = Gaze::make();
            if (!$gaze->enabled()) {
                return null;
            }
            if ($updateId !== null) {
                $gaze->update($updateId);
            }
            $gaze->custom($kind, $body, $modifiers);

            return $gaze->id();
        } catch (\Throwable) {
            return null;
        } finally {
            self::$sending = false;
        }
    }

    public function remove(string $id): void
    {
        if (self::$sending) {
            return;
        }
        try {
            $gaze = Gaze::make();
            if ($gaze->enabled()) {
                $gaze->update($id)->remove();
            }
        } catch (\Throwable) {
        }
    }

    public static function sending(): bool
    {
        return self::$sending;
    }

    /**
     * In a request on an ignored path (traces.ignore_paths: the Telescope, Horizon and Pulse
     * dashboards, the relay): the watchers send nothing for it, its terminating callbacks
     * included. gaze() calls still arrive. Decided once per request.
     */
    public function inIgnoredRequest(): bool
    {
        $request = $this->app->bound('request') ? $this->app['request'] : null;
        if (!$request instanceof Request) {
            return false;
        }
        $ignored = $request->attributes->get('_gaze_ignored');
        if (!is_bool($ignored)) {
            $ignored = $this->traces()->ignoresRequest($request);
            $request->attributes->set('_gaze_ignored', $ignored);
        }

        return $ignored;
    }

    /** Builds a body while ignoring watcher events it triggers (rendering, casting, ...). */
    public static function quietly(\Closure $callback): mixed
    {
        if (self::$sending) {
            return $callback();
        }
        self::$sending = true;
        try {
            return $callback();
        } finally {
            self::$sending = false;
        }
    }

    // ------------------------------------------------------------------ request summaries

    /**
     * Body of the "request" row closing an HTTP trace; with the requests watcher on it also
     * carries headers, payload, session and a response preview (all redacted).
     *
     * @return array<string, mixed>
     */
    public function requestSummary(Request $request, ?SymfonyResponse $response): array
    {
        $r = $this->redactor;
        $body = [
            'method' => $request->getMethod(),
            'url' => $r->url($request->fullUrl()),
            'uri' => $r->url($request->getRequestUri()),
            'status' => $response?->getStatusCode() ?? 0,
        ];
        $route = $request->route();
        if ($route instanceof Route) {
            $body['route'] = $route->getName() ?? '/' . ltrim($route->uri(), '/');
            $body['action'] = $route->getActionName();
            try {
                $body['middleware'] = array_values(array_map(
                    static fn ($m) => is_string($m) ? $m : (is_object($m) ? $m::class : 'Closure'),
                    $route->gatherMiddleware(),
                ));
            } catch (\Throwable) {
            }
        }
        if (!$this->isOn('requests')) {
            return $body;
        }

        return self::quietly(function () use ($request, $response, $body, $r): array {
            $body['ip'] = $request->ip();
            $body['headers'] = Values::from($r->headers($request->headers->all()));
            $payload = $r->keys('inputs', $request->input());
            foreach ($request->allFiles() as $key => $file) {
                $files = is_array($file) ? $file : [$file];
                foreach ($files as $i => $f) {
                    if (is_object($f) && method_exists($f, 'getClientOriginalName')) {
                        $payload[is_array($file) ? $key . '.' . $i : $key] = ['file' => $f->getClientOriginalName(), 'size' => $f->getSize()];
                    }
                }
            }
            $body['payload'] = Values::from($payload);
            if ($request->hasSession()) {
                $body['session'] = Values::from($r->keys('session', $request->session()->all()));
            }
            if ($response !== null) {
                $body['response'] = Values::from($this->responsePreview($response));
                $body['response_headers'] = Values::from($r->headers($response->headers->all()));
            }
            if (defined('LARAVEL_START') && !isset($_SERVER['LARAVEL_OCTANE'])) {
                $body['since_start_ms'] = round((microtime(true) - (float) LARAVEL_START) * 1000, 3);
            }

            return $body;
        });
    }

    /** @return array<string, mixed> */
    public function responsePreview(SymfonyResponse $response): array
    {
        if ($response instanceof RedirectResponse) {
            return ['type' => 'redirect', 'target' => $this->redactor->url($response->getTargetUrl())];
        }
        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            return ['type' => 'json', 'json' => is_array($data) ? $this->redactor->keys('inputs', $data) : $data];
        }
        if ($response instanceof BinaryFileResponse) {
            return ['type' => 'file', 'file' => $response->getFile()->getFilename()];
        }
        if ($response instanceof StreamedResponse) {
            return ['type' => 'stream'];
        }
        $original = property_exists($response, 'original') ? $response->original : null;
        if ($original instanceof \Illuminate\Contracts\View\View) {
            return ['type' => 'view', 'view' => $original->name(), 'data' => array_keys($original->getData())];
        }
        $content = (string) $response->getContent();
        $type = (string) $response->headers->get('Content-Type', '');
        [$text, $cut] = Values::cut($content, 2000);
        $preview = ['type' => str_contains($type, 'html') ? 'html' : 'text', 'content_type' => $type, 'size' => strlen($content), 'content' => $text];
        if ($cut > 0) {
            $preview['cut'] = $cut;
        }

        return $preview;
    }

    /** Request facts attached to exception entries. @return array<string, mixed> */
    public function requestContext(): array
    {
        if ($this->app->runningInConsole() && !$this->app->runningUnitTests()) {
            return [];
        }
        try {
            $request = $this->app->bound('request') ? $this->app->make('request') : null;
        } catch (\Throwable) {
            return [];
        }
        if (!$request instanceof Request || $request->server('REQUEST_METHOD') === null && !$request->route()) {
            return [];
        }
        $out = [
            'method' => $request->getMethod(),
            'url' => $this->redactor->url($request->fullUrl()),
            'headers' => $this->redactor->headers($request->headers->all()),
        ];
        $route = $request->route();
        if ($route instanceof Route) {
            $out['route'] = $route->getName() ?? $route->uri();
            $out['action'] = $route->getActionName();
            $out['parameters'] = array_map(
                static fn ($p) => $p instanceof \Illuminate\Database\Eloquent\Model ? $p::class . '#' . $p->getKey() : (is_scalar($p) ? $p : get_debug_type($p)),
                $route->parameters(),
            );
        }

        return $out;
    }

    /** Str::is against a list of patterns. @param list<string> $patterns */
    public static function matchesAny(string $value, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && Str::is($pattern, $value)) {
                return true;
            }
        }

        return false;
    }

    public function currentTrace(): Trace
    {
        return $this->traces()->current();
    }
}
