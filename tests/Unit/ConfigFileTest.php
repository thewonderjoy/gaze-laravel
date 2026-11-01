<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Laravel\Tests\SetsEnv;

/** config/gaze.php read with different env vars: names, defaults, fallbacks, and the README tables. */
final class ConfigFileTest extends TestCase
{
    use SetsEnv;

    /** Watcher switch => its env var, as config/gaze.php names them. */
    private const WATCHER_ENV = [
        'exceptions' => 'GAZE_EXCEPTIONS',
        'logs' => 'GAZE_LOGS',
        'mails' => 'GAZE_MAILS',
        'dumps' => 'GAZE_DUMPS',
        'queries' => 'GAZE_QUERIES',
        'duplicate_queries' => 'GAZE_DUPLICATE_QUERIES',
        'slow_queries' => 'GAZE_SLOW_QUERIES',
        'insert_queries' => 'GAZE_INSERT_QUERIES',
        'select_queries' => 'GAZE_SELECT_QUERIES',
        'update_queries' => 'GAZE_UPDATE_QUERIES',
        'delete_queries' => 'GAZE_DELETE_QUERIES',
        'jobs' => 'GAZE_JOBS',
        'events' => 'GAZE_EVENTS',
        'views' => 'GAZE_VIEWS',
        'cache' => 'GAZE_CACHE',
        'requests' => 'GAZE_REQUESTS',
        'http_client' => 'GAZE_HTTP_CLIENT',
        'deprecations' => 'GAZE_DEPRECATIONS',
    ];

    protected function tearDown(): void
    {
        $this->restoreEnv();
        parent::tearDown();
    }

    /**
     * config/gaze.php with exactly these env vars: every other one it reads is unset.
     *
     * @param array<string, string> $env
     * @return array<string, mixed>
     */
    private function config(array $env = []): array
    {
        $this->setEnv(array_fill_keys(self::configEnvNames(), null));
        $this->setEnv($env);

        return require dirname(__DIR__, 2) . '/config/gaze.php';
    }

    public function testEveryWatcherSwitchHasItsEnvVar(): void
    {
        $this->assertEqualsCanonicalizing(GazeManager::WATCHERS, array_keys($this->config()['watchers']));
        $this->assertEqualsCanonicalizing(GazeManager::WATCHERS, array_keys(self::WATCHER_ENV));
        foreach (self::WATCHER_ENV as $env) {
            $this->assertContains($env, self::configEnvNames());
        }
    }

    public function testDefaults(): void
    {
        $c = $this->config();
        $this->assertNull($c['enabled']);
        $this->assertNull($c['host']);
        $this->assertEquals(23517, $c['port']);
        $this->assertNull($c['token']);
        $this->assertNull($c['project']);
        $this->assertTrue($c['remote_watchers']);
        $this->assertEquals(500, $c['queries']['slow_threshold_ms']);
        $this->assertEquals(3, $c['queries']['n_plus_one_threshold']);
        $this->assertTrue($c['queries']['group_n_plus_one']);
        $this->assertTrue($c['traces']['enabled']);
        $this->assertFalse($c['define_ray_helper']);
        $this->assertTrue($c['relay']['enabled']);
        $this->assertSame('', $c['relay']['hosts']);
    }

    /** @return iterable<string, array{array<string, string>, string, mixed}> */
    public static function envCases(): iterable
    {
        yield 'GAZE_ENABLED=false' => [['GAZE_ENABLED' => 'false'], 'enabled', false];
        yield 'GAZE_ENABLED=true' => [['GAZE_ENABLED' => 'true'], 'enabled', true];
        yield 'GAZE_HOST' => [['GAZE_HOST' => 'gaze.test'], 'host', 'gaze.test'];
        yield 'RAY_HOST alone' => [['RAY_HOST' => 'ray.test'], 'host', 'ray.test'];
        yield 'GAZE_HOST wins over RAY_HOST' => [['GAZE_HOST' => 'gaze.test', 'RAY_HOST' => 'ray.test'], 'host', 'gaze.test'];
        yield 'GAZE_PORT' => [['GAZE_PORT' => '23700'], 'port', 23700];
        yield 'RAY_PORT alone' => [['RAY_PORT' => '23701'], 'port', 23701];
        yield 'GAZE_PORT wins over RAY_PORT' => [['GAZE_PORT' => '23700', 'RAY_PORT' => '23701'], 'port', 23700];
        yield 'GAZE_TOKEN' => [['GAZE_TOKEN' => 's3cret'], 'token', 's3cret'];
        yield 'GAZE_PROJECT' => [['GAZE_PROJECT' => 'shop'], 'project', 'shop'];
        foreach (self::WATCHER_ENV as $switch => $env) {
            yield "$env=true" => [[$env => 'true'], "watchers.$switch", true];
            yield "$env=false" => [[$env => 'false'], "watchers.$switch", false];
        }
        yield 'GAZE_REMOTE_WATCHERS=false' => [['GAZE_REMOTE_WATCHERS' => 'false'], 'remote_watchers', false];
        yield 'GAZE_SLOW_QUERY_MS' => [['GAZE_SLOW_QUERY_MS' => '200'], 'queries.slow_threshold_ms', 200];
        yield 'RAY_SLOW_QUERY_THRESHOLD_IN_MS alone' => [['RAY_SLOW_QUERY_THRESHOLD_IN_MS' => '300'], 'queries.slow_threshold_ms', 300];
        yield 'GAZE_SLOW_QUERY_MS wins over the Ray one' => [['GAZE_SLOW_QUERY_MS' => '200', 'RAY_SLOW_QUERY_THRESHOLD_IN_MS' => '300'], 'queries.slow_threshold_ms', 200];
        yield 'GAZE_N_PLUS_ONE_THRESHOLD' => [['GAZE_N_PLUS_ONE_THRESHOLD' => '5'], 'queries.n_plus_one_threshold', 5];
        yield 'GAZE_GROUP_N_PLUS_ONE=false' => [['GAZE_GROUP_N_PLUS_ONE' => 'false'], 'queries.group_n_plus_one', false];
        yield 'GAZE_TRACES=false' => [['GAZE_TRACES' => 'false'], 'traces.enabled', false];
        yield 'GAZE_DEFINE_RAY_HELPER=true' => [['GAZE_DEFINE_RAY_HELPER' => 'true'], 'define_ray_helper', true];
        yield 'GAZE_RELAY=false' => [['GAZE_RELAY' => 'false'], 'relay.enabled', false];
        yield 'GAZE_RELAY_HOSTS' => [['GAZE_RELAY_HOSTS' => 'shop.lan,10.0.0.5'], 'relay.hosts', 'shop.lan,10.0.0.5'];
    }

    /** @param array<string, string> $env */
    #[DataProvider('envCases')]
    public function testEveryEnvVarReachesItsSetting(array $env, string $path, mixed $expected): void
    {
        $this->assertEquals($expected, data_get($this->config($env), $path));
    }

    /**
     * A blank line in .env (`GAZE_SLOW_QUERY_MS=`) should behave like an unset one: the Ray fallback
     * or the default, not 0 (which flags every query slow) or 1 (which calls every repeat an N+1).
     *
     * @return iterable<string, array{array<string, string>, string, int}>
     */
    public static function blankCases(): iterable
    {
        yield 'GAZE_SLOW_QUERY_MS=' => [['GAZE_SLOW_QUERY_MS' => ''], 'queries.slow_threshold_ms', 500];
        yield 'GAZE_SLOW_QUERY_MS= with the Ray one set' => [['GAZE_SLOW_QUERY_MS' => '', 'RAY_SLOW_QUERY_THRESHOLD_IN_MS' => '300'], 'queries.slow_threshold_ms', 300];
        yield 'GAZE_N_PLUS_ONE_THRESHOLD=' => [['GAZE_N_PLUS_ONE_THRESHOLD' => ''], 'queries.n_plus_one_threshold', 3];
    }

    /** @param array<string, string> $env */
    #[DataProvider('blankCases')]
    public function testABlankNumberFallsBackLikeAnUnsetOne(array $env, string $path, int $expected): void
    {
        $this->assertEquals($expected, data_get($this->config($env), $path), 'a blank value is read as ' . var_export(data_get($this->config($env), $path), true));
    }

    public function testTheReadmeWatcherTableMatchesTheConfig(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');
        preg_match_all('/^\| (\w+) \| `(GAZE_\w+)` \| (on|off) \|/m', $readme, $rows, PREG_SET_ORDER);
        $this->assertNotEmpty($rows, 'the README watcher table');
        $defaults = $this->config()['watchers'];
        foreach ($rows as [, $switch, $env, $state]) {
            $this->assertSame(self::WATCHER_ENV[$switch] ?? null, $env, "README env name of $switch");
            $this->assertSame($state === 'on', $defaults[$switch], "README default of $switch");
        }
        $listed = array_column($rows, 1);
        $missing = array_diff(array_keys(self::WATCHER_ENV), $listed);
        $this->assertSame(
            ['duplicate_queries', 'slow_queries', 'insert_queries', 'select_queries', 'update_queries', 'delete_queries'],
            array_values($missing),
            'every watcher outside the query sub-switches is in the table',
        );
        foreach ($missing as $switch) {
            $this->assertStringContainsString('`' . self::WATCHER_ENV[$switch] . '`', $readme, "README names $switch's env var");
        }
    }
}
