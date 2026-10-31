<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Console;

use Illuminate\Console\Command;
use TheWonderJoy\Gaze\Gaze;
use TheWonderJoy\Gaze\Laravel\GazeManager;
use TheWonderJoy\Gaze\Transport\HostResolver;

/**
 * php artisan gaze:doctor
 *
 * Where does this PHP process run (Windows, WSL2, Docker, Sail), is Gaze enabled and why, which
 * hosts could reach the app (each one probed with GET /v1/hello), and the exact .env lines to add.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'gaze:doctor {--port= : Probe this port instead of the configured one} {--timeout=600 : Connect timeout per candidate, in milliseconds}';

    protected $description = 'Check that this app can reach the Gaze app, and print the .env lines that make it work';

    public function handle(GazeManager $gaze): int
    {
        $port = (int) ($this->option('port') ?: (Gaze::config()['port'] ?? 23517));
        $configuredHost = $gaze->config('host');
        $configuredHost = is_string($configuredHost) && $configuredHost !== '' ? $configuredHost : null;
        $env = self::environment();

        $this->line('<options=bold>Gaze for Laravel</> ' . GazeManager::packageVersion() . ' (gaze-php ' . Gaze::VERSION . '), Laravel ' . $this->laravel->version() . ', PHP ' . PHP_VERSION);
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Environment</>');
        $this->components->twoColumnDetail('Runs on', $env['label']);
        $this->components->twoColumnDetail('APP_ENV', (string) $this->laravel->environment());
        $enabled = $gaze->enabled();
        $raw = $gaze->config('enabled');
        $why = $raw === null || $raw === '' ? 'default: on everywhere except production' : 'GAZE_ENABLED / gaze.enabled = ' . var_export($raw, true);
        $this->components->twoColumnDetail('Enabled', ($enabled ? '<fg=green>yes</>' : '<fg=yellow>no</>') . " <fg=gray>({$why})</>");
        $cached = method_exists($this->laravel, 'configurationIsCached') && $this->laravel->configurationIsCached();
        $this->components->twoColumnDetail('Config cached', $cached ? '<fg=yellow>yes</> <fg=gray>(.env changes need php artisan config:cache)</>' : 'no');
        $this->components->twoColumnDetail('Configured host', $configuredHost ?? '<fg=gray>none (discovery)</>');
        $this->components->twoColumnDetail('Port', (string) $port);
        $this->newLine();

        $candidates = [];
        if ($configuredHost !== null) {
            $candidates[$configuredHost] = 'configured (GAZE_HOST / RAY_HOST)';
        }
        $candidates += ['127.0.0.1' => 'this machine (loopback)'];
        $candidates += ['host.docker.internal' => 'Docker Desktop / Sail host'];
        if ($env['gateway'] !== null) {
            $candidates += [$env['gateway'] => 'default gateway (WSL2 NAT / container host)'];
        }

        $this->components->twoColumnDetail('<fg=gray>Candidates</>', '<fg=gray>GET /v1/hello</>');
        $results = self::probe(array_keys($candidates), $port, max(50, (int) $this->option('timeout')));
        $working = null;
        foreach ($candidates as $host => $why) {
            $r = $results[$host];
            if ($r['ok'] && $working === null) {
                $working = $host;
            }
            $state = $r['ok'] ? '<fg=green>' . $r['detail'] . '</>' : '<fg=red>' . $r['detail'] . '</>';
            $this->components->twoColumnDetail("{$host} <fg=gray>{$why}</>", $state . sprintf(' <fg=gray>%.1f ms</>', $r['ms']));
        }
        $this->newLine();

        if ($working !== null && ($working === $configuredHost || ($configuredHost === null && $working === HostResolver::DEFAULT_HOST))) {
            $this->components->info('Gaze can reach the app. Nothing to change.');
        } elseif ($working !== null && $configuredHost === null) {
            $this->components->info("Discovery finds the app at {$working}. Pin it in .env to skip the probe:");
            $this->printEnv($working, $port);
        } elseif ($working !== null) {
            $this->components->warn("The app answers at {$working}. Add to .env:");
            $this->printEnv($working, $port);
        } else {
            $this->components->error('No candidate reached the Gaze app.');
            foreach (self::advice($env, $port) as $line) {
                $this->line('  ' . $line);
            }
        }
        if ($cached && $working !== null && $working !== $configuredHost) {
            $this->line('  Then run <options=bold>php artisan config:cache</> (the config is cached).');
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Watchers</>', '<fg=gray>on / off (app overrides marked *)</>');
        $remote = $gaze->remoteSwitches();
        foreach ($gaze->switches() as $name => $on) {
            $this->components->twoColumnDetail($name, ($on ? '<fg=green>on</>' : '<fg=gray>off</>') . (isset($remote[$name]) ? ' *' : ''));
        }

        return $working !== null ? self::SUCCESS : self::FAILURE;
    }

    private function printEnv(string $host, int $port): void
    {
        $this->line("  <options=bold>GAZE_HOST={$host}</>");
        if ($port !== 23517) {
            $this->line("  <options=bold>GAZE_PORT={$port}</>");
        }
    }

    /** @return array{label: string, windows: bool, wsl: bool, docker: bool, sail: bool, gateway: string|null} */
    public static function environment(): array
    {
        $windows = DIRECTORY_SEPARATOR === '\\';
        $version = $windows ? '' : (string) @file_get_contents('/proc/version');
        $wsl = !$windows && (stripos($version, 'microsoft') !== false || getenv('WSL_DISTRO_NAME') !== false || getenv('WSL_INTEROP') !== false);
        $docker = !$windows && (@file_exists('/.dockerenv') || @file_exists('/run/.containerenv'));
        $sail = getenv('LARAVEL_SAIL') !== false && getenv('LARAVEL_SAIL') !== '';
        $gateway = null;
        if (!$windows && @is_readable('/proc/net/route')) {
            $gateway = HostResolver::gateway((string) @file_get_contents('/proc/net/route'));
        }
        $label = match (true) {
            $windows => 'Windows (native PHP)',
            $sail => 'Laravel Sail (Docker)',
            $docker && $wsl => 'Docker container on WSL2',
            $docker => 'Docker container',
            $wsl => 'WSL2 (' . (getenv('WSL_DISTRO_NAME') ?: 'Linux') . ')',
            default => PHP_OS_FAMILY,
        };

        return ['label' => $label, 'windows' => $windows, 'wsl' => $wsl, 'docker' => $docker, 'sail' => $sail, 'gateway' => $gateway];
    }

    /**
     * Probes every host in parallel.
     *
     * @param list<string> $hosts
     * @return array<string, array{ok: bool, detail: string, ms: float}>
     */
    public static function probe(array $hosts, int $port, int $connectTimeoutMs): array
    {
        $multi = curl_multi_init();
        $handles = [];
        foreach ($hosts as $host) {
            $h = curl_init(HostResolver::baseUrl($host, $port) . '/v1/hello');
            curl_setopt_array($h, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
                CURLOPT_TIMEOUT_MS => $connectTimeoutMs + 1000,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_PROXY => '',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            curl_multi_add_handle($multi, $h);
            $handles[$host] = $h;
        }
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 0.05);
            }
        } while ($running > 0 && $status === CURLM_OK);
        $errnos = [];
        while (($msg = curl_multi_info_read($multi)) !== false) {
            $key = array_search($msg['handle'], $handles, true);
            if ($key !== false) {
                $errnos[$key] = (int) $msg['result'];
            }
        }
        $out = [];
        foreach ($handles as $host => $h) {
            $errno = $errnos[$host] ?? 0;
            $code = (int) curl_getinfo($h, CURLINFO_RESPONSE_CODE);
            $ms = (float) curl_getinfo($h, CURLINFO_TOTAL_TIME) * 1000;
            $body = json_decode((string) curl_multi_getcontent($h), true);
            if ($errno === 0 && $code === 200 && is_array($body) && ($body['v'] ?? null) === 1) {
                $server = $body['server'] ?? [];
                $out[$host] = ['ok' => true, 'detail' => 'Gaze ' . ($server['name'] ?? '') . ' ' . ($server['version'] ?? ''), 'ms' => $ms];
            } elseif ($errno === 0 && $code > 0) {
                $out[$host] = ['ok' => false, 'detail' => "HTTP {$code}: something else listens here" . ($code === 404 ? ' (the Ray app?)' : ''), 'ms' => $ms];
            } else {
                $detail = match ($errno) {
                    CURLE_COULDNT_RESOLVE_HOST => 'does not resolve',
                    CURLE_COULDNT_CONNECT => 'refused',
                    CURLE_OPERATION_TIMEDOUT => 'timed out (nothing listens, or a firewall drops it)',
                    default => 'curl error ' . $errno,
                };
                $out[$host] = ['ok' => false, 'detail' => $detail, 'ms' => $ms];
            }
            curl_multi_remove_handle($multi, $h);
        }

        return $out;
    }

    /**
     * @param array{label: string, windows: bool, wsl: bool, docker: bool, sail: bool, gateway: string|null} $env
     * @return list<string>
     */
    public static function advice(array $env, int $port): array
    {
        if ($env['sail'] || ($env['docker'] && !$env['wsl'])) {
            return [
                'Start the Gaze app on the host. Docker Desktop and Sail reach it through host.docker.internal:',
                '<options=bold>GAZE_HOST=host.docker.internal</>',
            ];
        }
        if ($env['wsl']) {
            $gateway = $env['gateway'] ?? '<gateway IP>';

            return [
                'PHP runs inside WSL2 (NAT), which cannot reach Windows loopback.',
                'In the Gaze app turn on "Allow WSL" (it listens on the WSL adapter), then add:',
                "<options=bold>GAZE_HOST={$gateway}</>",
                'With WSL mirrored networking use GAZE_HOST=127.0.0.1 instead.',
            ];
        }

        return [
            "Start the Gaze app (it listens on 127.0.0.1:{$port}).",
            'If it uses another port: <options=bold>GAZE_PORT=<port></>',
        ];
    }
}
