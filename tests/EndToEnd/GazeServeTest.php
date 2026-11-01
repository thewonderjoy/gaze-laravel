<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\EndToEnd;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\Schema;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\User;
use TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\WelcomeMail;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

/**
 * Against the real Gaze core: `gaze-serve --port 23641 --no-probe --json` (app/crates/core) prints
 * every UI frame as JSON. A Testbench request with queries, a log line, a mail and an exception
 * must come out as ONE http trace holding those entries and its summary. The server is stopped
 * after the class (stdin "q", then killed if it is still running).
 *
 * The binary is target/debug/gaze-serve(.exe) at the repository root; it is built with
 * `cargo build -p gaze-core` when missing. Outside the monorepo the test is skipped.
 */
final class GazeServeTest extends TestCase
{
    public const SERVE_PORT = 23641;

    /** @var resource|null */
    private static $serve = null;

    /** @var array<int, resource> */
    private static array $pipes = [];

    private static string $stdout = '';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('gaze.port', self::SERVE_PORT);
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        $repo = dirname(__DIR__, 4);
        $exe = $repo . '/target/debug/gaze-serve' . (DIRECTORY_SEPARATOR === '\\' ? '.exe' : '');
        if (!is_file($exe) && is_file($repo . '/Cargo.toml')) {
            // Output goes to a file, not pipes. Cargo prints its progress to stderr; reading stdout to the
            // end first left cargo blocked on a full stderr pipe (about 4 KB on Windows), and CI hung.
            $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gaze-cargo-build-' . getmypid() . '.log';
            $build = proc_open(['cargo', 'build', '-p', 'gaze-core'], [1 => ['file', $log, 'w'], 2 => ['redirect', 1]], $pipes, $repo);
            if (is_resource($build)) {
                proc_close($build);
            }
            @unlink($log);
        }
        if (!is_file($exe)) {
            return; // tests are skipped in setUp()
        }
        $probe = @fsockopen('127.0.0.1', self::SERVE_PORT, $errno, $errstr, 0.2);
        if ($probe !== false) {
            fclose($probe);
            throw new \RuntimeException('Port ' . self::SERVE_PORT . ' is in use; gaze-serve needs it.');
        }
        self::$stdout = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gaze-serve-e2e-' . getmypid() . '.ndjson';
        @unlink(self::$stdout);
        self::$serve = proc_open(
            [$exe, '--port', (string) self::SERVE_PORT, '--no-probe', '--json'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$stdout, 'w'], 2 => ['file', self::$stdout . '.err', 'w']],
            self::$pipes,
            $repo,
        );
        $deadline = microtime(true) + 15;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', self::SERVE_PORT, $errno, $errstr, 0.2);
            if ($fp !== false) {
                fclose($fp);

                return;
            }
            usleep(50000);
        }
        self::stopServe();
        throw new \RuntimeException('gaze-serve did not start: ' . @file_get_contents(self::$stdout . '.err'));
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServe();
        parent::tearDownAfterClass();
    }

    private static function stopServe(): void
    {
        if (self::$serve === null) {
            return;
        }
        @fwrite(self::$pipes[0], "q\n");
        @fclose(self::$pipes[0]);
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline && (proc_get_status(self::$serve)['running'] ?? false)) {
            usleep(50000);
        }
        $status = proc_get_status(self::$serve);
        if ($status['running'] ?? false) {
            proc_terminate(self::$serve);
            if (DIRECTORY_SEPARATOR === '\\') {
                exec('taskkill /F /PID ' . (int) $status['pid'] . ' 2>NUL');
            }
        }
        proc_close(self::$serve);
        self::$serve = null;
        @unlink(self::$stdout);
        @unlink(self::$stdout . '.err');
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$serve === null) {
            $this->markTestSkipped('target/debug/gaze-serve is not available (build it with cargo build -p gaze-core).');
        }
    }

    /**
     * Frames printed by gaze-serve, folded into entries (by core id) and traces (by id).
     *
     * @return array{entries: array<string, array<string, mixed>>, traces: array<string, array<string, mixed>>}
     */
    private function serverState(): array
    {
        $entries = [];
        $traces = [];
        foreach (@file(self::$stdout, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $frame = json_decode($line, true);
            if (!is_array($frame)) {
                continue;
            }
            if (!empty($frame['cleared'])) {
                $entries = [];
                $traces = [];
            }
            foreach ($frame['add'] ?? [] as $e) {
                $entries[$e['id']] = $e;
            }
            foreach ($frame['patch'] ?? [] as $p) {
                if (isset($entries[$p['id']])) {
                    $entries[$p['id']] = array_merge($entries[$p['id']], $p);
                }
            }
            foreach ($frame['del'] ?? [] as $id) {
                unset($entries[$id]);
            }
            foreach ($frame['traces'] ?? [] as $t) {
                $traces[$t['id']] = $t;
            }
        }

        return ['entries' => $entries, 'traces' => $traces];
    }

    public function testARequestBecomesOneTraceInTheRealCore(): void
    {
        Schema::create();
        Schema::seed(2, 0);
        Route::get('/checkout', function () {
            User::query()->where('email', 'u1@example.com')->first();
            DB::select('select count(*) as n from users');
            Log::warning('stock is low', ['sku' => 'BOOK-1']);
            Mail::send(new WelcomeMail());
            throw new \RuntimeException('Payment gateway timeout');
        });

        $this->get('/checkout')->assertStatus(500);
        $this->flushGaze();

        $deadline = microtime(true) + 15;
        do {
            $state = $this->serverState();
            $http = array_values(array_filter($state['traces'], static fn ($t) => $t['kind'] === 'http' && $t['name'] === 'GET /checkout'));
            if ($http !== [] && $http[0]['endedAt'] !== null) {
                break;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        $this->assertCount(1, $http, 'exactly one http trace: ' . json_encode(array_values($state['traces'])));
        $trace = $http[0];
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $trace['id']);
        $this->assertNotNull($trace['endedAt'], 'the summary row closed the trace');
        $this->assertSame(500, $trace['status']);
        $this->assertGreaterThan(0, $trace['durationMs']);
        $this->assertSame(2, $trace['summary']['queries']);
        $this->assertSame(1, $trace['summary']['mails']);
        $this->assertSame(1, $trace['summary']['exceptions']);

        $inTrace = array_values(array_filter($state['entries'], static fn ($e) => $e['traceId'] === $trace['id']));
        $kinds = array_count_values(array_column($inTrace, 'kind'));
        $this->assertSame(2, $kinds['query'] ?? 0, json_encode($kinds));
        $this->assertSame(1, $kinds['app_log'] ?? 0);
        $this->assertSame(1, $kinds['mail'] ?? 0);
        $this->assertSame(1, $kinds['exception'] ?? 0);
        $this->assertSame(1, $kinds['request'] ?? 0);

        $byKind = [];
        foreach ($inTrace as $e) {
            $byKind[$e['kind']][] = $e;
        }
        $this->assertSame('Payment gateway timeout', $byKind['exception'][0]['body']['message']);
        $this->assertContains('error', $byKind['exception'][0]['flags']);
        $this->assertSame('Welcome aboard', $byKind['mail'][0]['body']['subject']);
        $this->assertSame('ada@example.com', $byKind['mail'][0]['body']['to'][0]['email']);
        $this->assertSame('warning', $byKind['app_log'][0]['body']['level']);
        $this->assertStringContainsString('u1@example.com', $byKind['query'][0]['title']);
        $this->assertNotNull($byKind['query'][0]['body']['fingerprint']);
        $this->assertSame(500, $byKind['request'][0]['body']['status']);
        $this->assertContains('error', $byKind['request'][0]['flags']);
        $this->assertSame(__FILE__, $byKind['query'][0]['origin']['file']);
    }

    public function testAnNPlusOneIsOneQueryEntryWithExecutionsInTheCore(): void
    {
        Schema::create();
        Schema::seed(5, 1);
        Route::get('/feed', fn () => \TheWonderJoy\Gaze\Laravel\Tests\Fixtures\App\Post::all()->map(fn ($p) => $p->author->name)->all());

        $this->get('/feed')->assertOk();
        $this->flushGaze();

        $deadline = microtime(true) + 15;
        do {
            $state = $this->serverState();
            $http = array_values(array_filter($state['traces'], static fn ($t) => $t['name'] === 'GET /feed' && $t['endedAt'] !== null));
            usleep($http === [] ? 100000 : 0);
        } while ($http === [] && microtime(true) < $deadline);

        $this->assertCount(1, $http);
        $queries = array_values(array_filter($state['entries'], static fn ($e) => $e['traceId'] === $http[0]['id'] && $e['kind'] === 'query'));
        $this->assertCount(2, $queries, 'posts + one grouped author query (the rows sent before the threshold were removed)');
        $grouped = array_values(array_filter($queries, static fn ($q) => isset($q['body']['executions'])))[0];
        $this->assertCount(5, $grouped['body']['executions']);
        $keys = array_keys($grouped['body']['executions'][0]);
        sort($keys);
        $this->assertSame(['bindings', 'offsetMs', 'timeMs'], $keys, 'the core maps time_ms/offset_ms to the UI contract');
        $this->assertIsNumeric($grouped['body']['executions'][4]['offsetMs']);
        $this->assertContains('n+1', $grouped['flags'], 'client flags are merged by the core');
        $this->assertNull($grouped['label'], 'the badge is derived by the app, not sent as a label');
    }

    /**
     * The app shows the slow badge gaze-laravel decided with GAZE_SLOW_QUERY_MS, not its own 100 ms:
     * at 500 ms a 177 ms query is not slow, a 600 ms one is.
     */
    public function testTheCoreKeepsTheSlowDecisionOfGazeLaravel(): void
    {
        $this->app['config']->set('gaze.queries.slow_threshold_ms', 500);
        Route::get('/slow-check', function () {
            foreach ([177.0, 600.0, 20.0] as $ms) {
                event(new \Illuminate\Database\Events\QueryExecuted("select $ms as ms", [], $ms, DB::connection()));
            }

            return 'ok';
        });

        $this->get('/slow-check')->assertOk();
        $this->flushGaze();

        $deadline = microtime(true) + 15;
        do {
            $state = $this->serverState();
            $http = array_values(array_filter($state['traces'], static fn ($t) => $t['name'] === 'GET /slow-check' && $t['endedAt'] !== null));
            usleep($http === [] ? 100000 : 0);
        } while ($http === [] && microtime(true) < $deadline);

        $this->assertCount(1, $http);
        $slow = [];
        foreach ($state['entries'] as $e) {
            if ($e['traceId'] === $http[0]['id'] && $e['kind'] === 'query') {
                $slow[$e['body']['sql']] = in_array('slow', $e['flags'], true);
            }
        }
        ksort($slow);
        $this->assertSame(['select 177 as ms' => false, 'select 20 as ms' => false, 'select 600 as ms' => true], $slow);
        $this->assertSame(1, $http[0]['summary']['slow'], 'the trace counts one slow query');
    }
}
