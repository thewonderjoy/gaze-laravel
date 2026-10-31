<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Orchestra\Testbench\TestCase as Testbench;
use Symfony\Component\VarDumper\VarDumper;
use TheWonderJoy\Gaze\Client;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\GazeServiceProvider;
use TheWonderJoy\Gaze\Laravel\Watchers\DumpHandler;

/**
 * Testbench app wired to a fake Gaze app (tests/Fixtures/fake-app.php under `php -S`, one per
 * test run, killed at shutdown). Each test gets a clean request log, a clean control file and a
 * fresh client state directory, so the shared "down"/hello caches never leak between tests.
 */
abstract class TestCase extends Testbench
{
    public const PORT = 23640;

    public const SCHEMA_ID = 'https://example.invalid/protocol/v1/batch.schema.json';

    /** @var resource|null */
    private static $server = null;

    private static ?string $root = null;

    private static ?Validator $validator = null;

    protected string $stateDir;

    /** @var array<string, mixed> gaze.* overrides applied before the app boots (dot keys) */
    protected array $gazeConfig = [];

    /** @var array<string, mixed>|null control.json written before the app boots */
    protected ?array $control = null;

    protected string $appEnv = 'testing';

    public static function root(): string
    {
        if (self::$root === null) {
            // keyed by port, not pid: tests run in a separate process share the parent's fake app
            self::$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gaze-laravel-tests-' . self::PORT;
            @mkdir(self::$root . '/server', 0777, true);
            register_shutdown_function(static function (): void {
                if (self::$server !== null) { // only the process that started the fake app cleans up
                    self::stopServer();
                    self::rmrf((string) self::$root);
                }
            });
        }

        return self::$root;
    }

    public static function serverDir(): string
    {
        return self::root() . DIRECTORY_SEPARATOR . 'server';
    }

    public static function startServer(): void
    {
        if (self::$server !== null) {
            return;
        }
        self::root();
        if (getenv('GAZE_TEST_CHILD') === '1' || ($_SERVER['GAZE_TEST_CHILD'] ?? null) === '1') {
            return; // a separate-process test: the parent's fake app is already listening
        }
        $probe = @stream_socket_server('tcp://127.0.0.1:' . self::PORT, $errno, $errstr);
        if ($probe === false) {
            throw new \RuntimeException('Port ' . self::PORT . ' is in use; the fake Gaze app needs it.');
        }
        fclose($probe);
        putenv('GAZE_TEST_CHILD=1'); // inherited by separate-process tests
        $env = getenv();
        $env['GAZE_FAKE_DIR'] = self::serverDir();
        $log = self::root() . DIRECTORY_SEPARATOR . 'php-s.log';
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::PORT, __DIR__ . '/Fixtures/fake-app.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
            $pipes,
            null,
            $env,
        );
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', self::PORT, $errno, $errstr, 0.2);
            if ($fp !== false) {
                fclose($fp);

                return;
            }
            usleep(20000);
        }
        self::stopServer();
        throw new \RuntimeException('The fake Gaze app did not start on port ' . self::PORT);
    }

    public static function stopServer(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    protected function setUp(): void
    {
        self::startServer();
        $this->stateDir = self::root() . DIRECTORY_SEPARATOR . 'state-' . bin2hex(random_bytes(4));
        @mkdir($this->stateDir, 0777, true);
        @unlink(self::serverDir() . '/requests.ndjson');
        @unlink(self::serverDir() . '/control.json');
        if ($this->control !== null) {
            $this->control($this->control);
        }
        self::uninstallDumpHandler();
        Gaze::reset();
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::uninstallDumpHandler();
        Gaze::reset();
    }

    /** A DumpHandler left by a previous app must not observe the next test. */
    public static function uninstallDumpHandler(): void
    {
        $current = VarDumper::setHandler(null);
        VarDumper::setHandler($current instanceof DumpHandler ? $current->previous : $current);
    }

    protected function getPackageProviders($app): array
    {
        return [GazeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['env'] = $this->appEnv;
        $config = $app['config'];
        $gaze = require dirname(__DIR__) . '/config/gaze.php';
        $gaze['enabled'] = true;
        $gaze['host'] = '127.0.0.1';
        $gaze['port'] = static::PORT;
        // generous timeouts: a cold `php -S` on Windows can take seconds to answer the first request
        $gaze['client'] = ['state_dir' => $this->stateDir, 'logger' => false, 'connect_timeout_ms' => 2000, 'timeout_ms' => 15000, 'flush_timeout_ms' => 15000];
        foreach ($this->gazeConfig as $key => $value) {
            data_set($gaze, $key, $value);
        }
        $config->set('gaze', $gaze);
        $config->set('database.default', 'testing');
        $config->set('mail.default', 'array');
        $config->set('queue.default', 'sync');
        $config->set('app.name', 'GazeTests');
        $config->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
    }

    protected function manager(): GazeManager
    {
        return $this->app->make(GazeManager::class);
    }

    /** @param array<string, mixed> $control */
    protected function control(array $control): void
    {
        file_put_contents(self::serverDir() . '/control.json', json_encode($control));
    }

    /** Sends what is queued, then forgets every request the fake app received so far. */
    protected function resetCapture(): void
    {
        $this->flushGaze();
        @unlink(self::serverDir() . '/requests.ndjson');
    }

    /** Waits until everything queued reached the fake app. */
    protected function flushGaze(): void
    {
        Client::current()?->flush(15000);
    }

    /** @return list<array<string, mixed>> every request the fake app received */
    protected function requests(): array
    {
        $out = [];
        foreach (@file(self::serverDir() . '/requests.ndjson', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $out[] = json_decode($line, true);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> decoded POST /v1/batch bodies */
    protected function batches(): array
    {
        $out = [];
        foreach ($this->requests() as $r) {
            if ($r['method'] === 'POST' && $r['path'] === '/v1/batch') {
                $out[] = json_decode($r['body'], true);
            }
        }

        return $out;
    }

    /**
     * Entries of all batches in arrival order; "trace" is the entry's effective trace (its own,
     * else the batch's).
     *
     * @return list<array<string, mixed>>
     */
    protected function entries(): array
    {
        $this->flushGaze();
        $out = [];
        foreach ($this->batches() as $b) {
            foreach ($b['entries'] as $e) {
                if (!isset($e['trace']) && isset($b['trace'])) {
                    $e['trace'] = $b['trace'];
                }
                $out[] = $e;
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> entries of $kind (op add) */
    protected function added(string $kind): array
    {
        return array_values(array_filter($this->entries(), static fn ($e) => ($e['kind'] ?? null) === $kind && $e['op'] === 'add'));
    }

    /**
     * The app's view of entries after applying ops (add/update/patch/remove), keyed by id.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function state(): array
    {
        $state = [];
        foreach ($this->entries() as $e) {
            $id = $e['id'];
            switch ($e['op']) {
                case 'add':
                case 'update':
                    $state[$id] = array_merge($state[$id] ?? [], $e);
                    break;
                case 'patch':
                    if (isset($state[$id])) {
                        $state[$id] = array_merge($state[$id], $e, ['op' => $state[$id]['op']]);
                    }
                    break;
                case 'remove':
                    unset($state[$id]);
                    break;
            }
        }

        return $state;
    }

    protected function assertBatchesValid(): void
    {
        $this->flushGaze();
        $count = 0;
        foreach ($this->requests() as $r) {
            if ($r['method'] === 'POST' && $r['path'] === '/v1/batch') {
                self::assertValidBatchJson($r['body']);
                ++$count;
            }
        }
        $this->assertGreaterThan(0, $count, 'no batch was received');
    }

    public static function assertValidBatchJson(string $json): void
    {
        $validator = self::$validator ??= self::makeValidator();
        $result = $validator->validate(json_decode($json), self::SCHEMA_ID);
        $message = '';
        if (!$result->isValid()) {
            $message = json_encode((new ErrorFormatter())->format($result->error(), true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                . "\n" . substr($json, 0, 3000);
        }
        self::assertTrue($result->isValid(), 'batch does not match batch.schema.json: ' . $message);
    }

    private static function makeValidator(): Validator
    {
        $validator = new Validator();
        $validator->setMaxErrors(5);
        $validator->resolver()->registerFile(self::SCHEMA_ID, __DIR__ . '/schema/batch.schema.json');

        return $validator;
    }

    /** Listener closures registered by this package, per event name. @return array<string, int> */
    protected function packageListeners(): array
    {
        $out = [];
        $events = $this->app['events'];
        $all = $events->getRawListeners();
        $wildcards = (new \ReflectionProperty($events, 'wildcards'))->getValue($events);
        foreach ($wildcards as $event => $listeners) {
            $all[$event] = array_merge($all[$event] ?? [], $listeners);
        }
        foreach ($all as $event => $listeners) {
            foreach ($listeners as $listener) {
                if (self::isPackageListener($listener) && !self::isTestListener($listener)) {
                    $out[$event] = ($out[$event] ?? 0) + 1;
                }
            }
        }

        return $out;
    }

    private static function isTestListener(mixed $listener): bool
    {
        if (!$listener instanceof \Closure) {
            return false;
        }
        $r = new \ReflectionFunction($listener);

        return str_contains($r->getClosureScopeClass()?->getName() ?? '', '\\Tests\\');
    }

    private static function isPackageListener(mixed $listener): bool
    {
        if ($listener instanceof \Closure) {
            $r = new \ReflectionFunction($listener);
            $class = $r->getClosureScopeClass()?->getName() ?? '';
            $this_ = $r->getClosureThis();
            if ($this_ !== null) {
                $class = $this_::class;
            }

            return str_starts_with($class, 'TheWonderJoy\\Gaze\\') && !str_contains($class, '\\Tests\\');
        }
        if (is_array($listener) && is_object($listener[0] ?? null)) {
            return str_starts_with($listener[0]::class, 'TheWonderJoy\\Gaze\\');
        }
        if (is_string($listener)) {
            return str_starts_with($listener, 'TheWonderJoy\\Gaze\\');
        }

        return false;
    }

    protected static function rmrf(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
