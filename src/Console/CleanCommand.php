<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Console;

use Illuminate\Console\Command;
use TheWonderJoy\Gaze\Console\CleanCommand as PhpCleanCommand;

/**
 * php artisan gaze:clean [--dry-run] [path ...]
 *
 * Removes gaze(...) and gaze_dd(...) statements with gaze-php's tokenizer-based cleaner (pure
 * PHP, no bash, no Rector). Default paths: app, routes, config, resources, tests, database.
 * Calls whose result is used are reported and left alone.
 */
final class CleanCommand extends Command
{
    public const PATHS = ['app', 'routes', 'config', 'resources', 'tests', 'database'];

    protected $signature = 'gaze:clean {paths?* : Files or directories (default: app, routes, config, resources, tests, database)} {--dry-run : Only list what would be removed}';

    protected $description = 'Remove gaze() and gaze_dd() calls from the application';

    public function handle(): int
    {
        $paths = (array) $this->argument('paths');
        if ($paths === []) {
            foreach (self::PATHS as $dir) {
                $path = $this->laravel->basePath($dir);
                if (is_dir($path)) {
                    $paths[] = $path;
                }
            }
        }
        if ($paths === []) {
            $this->components->warn('Nothing to clean: none of ' . implode(', ', self::PATHS) . ' exists.');

            return self::SUCCESS;
        }
        $argv = ['gaze', 'clean'];
        if ($this->option('dry-run')) {
            $argv[] = '--dry-run';
        }
        $out = fopen('php://temp', 'w+b');
        $code = PhpCleanCommand::main(array_merge($argv, array_values($paths)), $out);
        rewind($out);
        $this->output->write((string) stream_get_contents($out));
        fclose($out);

        return $code;
    }
}
