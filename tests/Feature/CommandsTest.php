<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use TheWonderJoy\Gaze\Laravel\Console\DoctorCommand;
use TheWonderJoy\Gaze\Laravel\Tests\TestCase;

final class CommandsTest extends TestCase
{
    public function testAnArtisanCommandIsOneTraceClosedWithItsExitCode(): void
    {
        Artisan::command('report:build {--password=}', function () {
            DB::select('select 1');
            gaze('building');

            return 3;
        });
        // Laravel fires CommandStarting/CommandFinished for `php artisan`, but not while running unit
        // tests unless the Symfony console events are rerouted (done here as a real artisan run would).
        $kernel = $this->app->make(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
        $this->artisan('report:build', ['--password' => 'cli-secret'])->assertExitCode(3);

        $entries = $this->entries();
        $traced = array_values(array_filter($entries, static fn ($e) => ($e['trace']['kind'] ?? null) === 'command'));
        $this->assertCount(3, $traced, 'query, log, summary');
        $this->assertCount(1, array_unique(array_map(static fn ($e) => $e['trace']['id'], $traced)));
        $summary = $traced[2];
        $this->assertSame('command', $summary['kind']);
        $this->assertTrue($summary['trace']['ended']);
        $this->assertSame('report:build', $summary['trace']['name']);
        $this->assertSame(3, $summary['body']['exit_code']);
        $this->assertSame(3, $summary['body']['status']);
        $this->assertSame('red', $summary['color']);
        $this->assertStringNotContainsString('cli-secret', json_encode($entries));
        $this->assertBatchesValid();
    }

    public function testDoctorProbesTheCandidatesAndReportsTheWorkingHost(): void
    {
        $this->artisan('gaze:doctor', ['--timeout' => 300])
            ->expectsOutputToContain('Gaze for Laravel')
            ->expectsOutputToContain('Gaze can reach the app. Nothing to change.')
            ->assertExitCode(0);

        $results = DoctorCommand::probe(['127.0.0.1', 'gaze-does-not-exist.invalid'], self::PORT, 300);
        $this->assertTrue($results['127.0.0.1']['ok']);
        $this->assertStringContainsString('Gaze fake-app', $results['127.0.0.1']['detail']);
        $this->assertFalse($results['gaze-does-not-exist.invalid']['ok']);

        $env = DoctorCommand::environment();
        $this->assertSame(DIRECTORY_SEPARATOR === '\\', $env['windows']);
        $wsl = DoctorCommand::advice(['label' => 'WSL2', 'windows' => false, 'wsl' => true, 'docker' => false, 'sail' => false, 'gateway' => '172.30.240.1'], 23517);
        $this->assertStringContainsString('GAZE_HOST=172.30.240.1', implode("\n", $wsl));
        $sail = DoctorCommand::advice(['label' => 'Sail', 'windows' => false, 'wsl' => false, 'docker' => true, 'sail' => true, 'gateway' => null], 23517);
        $this->assertStringContainsString('GAZE_HOST=host.docker.internal', implode("\n", $sail));
    }

    public function testDoctorPrintsTheEnvLinesWhenTheConfiguredHostIsWrong(): void
    {
        $this->app['config']->set('gaze.host', 'gaze-does-not-exist.invalid');
        $this->artisan('gaze:doctor', ['--timeout' => 300])
            ->expectsOutputToContain('The app answers at 127.0.0.1')
            ->expectsOutputToContain('GAZE_HOST=127.0.0.1')
            ->expectsOutputToContain('GAZE_PORT=' . self::PORT)
            ->assertExitCode(0);
    }

    public function testCleanRemovesGazeCallsOrOnlyListsThemWithDryRun(): void
    {
        $dir = self::root() . DIRECTORY_SEPARATOR . 'clean-' . bin2hex(random_bytes(3));
        @mkdir($dir, 0777, true);
        $file = $dir . DIRECTORY_SEPARATOR . 'Service.php';
        $code = "<?php\n\nfunction run(\$x) {\n    gaze(\$x)->red();\n    \$y = gaze()->pass(\$x);\n    return \$y;\n}\n";
        file_put_contents($file, $code);

        $this->artisan('gaze:clean', ['paths' => [$dir], '--dry-run' => true])
            ->expectsOutputToContain('would remove')
            ->assertExitCode(0);
        $this->assertSame($code, file_get_contents($file));

        $this->artisan('gaze:clean', ['paths' => [$dir]])->expectsOutputToContain('Removed 1 call')->assertExitCode(0);
        $cleaned = (string) file_get_contents($file);
        $this->assertStringNotContainsString('gaze($x)->red()', $cleaned);
        $this->assertStringContainsString('gaze()->pass($x)', $cleaned, 'a used result is left alone');
    }
}
