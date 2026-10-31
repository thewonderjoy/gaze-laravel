<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Support;

use TheWonderJoy\Gaze\Config;
use TheWonderJoy\Gaze\Support\Origin;

/**
 * Laravel-aware origin: the first application frame of the current stack.
 *
 * Frames are skipped when their file is a Gaze internal (gaze-php, this package and every path
 * given to Origin::addInternalPath(), Illuminate included) or, with origin.skip_vendor, under a
 * vendor directory. Compiled Blade views are mapped back to their .blade.php source.
 * Unlike laravel-ray there are no per-watcher frame offsets: a query, a mail, a dump or a cache
 * hit all resolve to the app code that caused it.
 *
 * @internal
 */
final class AppOrigin
{
    public static bool $skipVendor = true;

    public static ?string $compiledPath = null;

    /** @var array<string, string|null> compiled view file => .blade.php path */
    private static array $blade = [];

    /** @return array<string, mixed>|null */
    public static function resolve(int $extraFrames = -1): ?array
    {
        if ($extraFrames < 0) {
            $extraFrames = (int) (Config::get('origin_frames') ?? 3);
        }
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 120);
        $n = count($trace);
        for ($i = 1; $i < $n; ++$i) {
            $file = $trace[$i]['file'] ?? null;
            if ($file === null || self::skip($file)) {
                continue;
            }
            $origin = self::point($trace[$i], $trace[$i + 1] ?? null);
            if ($extraFrames > 0) {
                $frames = [];
                for ($j = $i + 1; $j < $n && count($frames) < $extraFrames; ++$j) {
                    $f = $trace[$j]['file'] ?? null;
                    if ($f === null || Origin::isInternal($f) || Origin::isVendor($f)) {
                        continue;
                    }
                    $frame = Origin::frame($trace[$j], $trace[$j + 1] ?? null);
                    $mapped = self::blade($frame['file']);
                    if ($mapped !== null) {
                        $frame['file'] = $mapped;
                    }
                    $frames[] = $frame;
                }
                if ($frames) {
                    $origin['frames'] = $frames;
                }
            }

            return $origin;
        }

        return null;
    }

    /**
     * Origin of a callable or class method without a stack walk: a controller action, a job's
     * handle(), a command, a test method.
     *
     * @return array<string, mixed>|null
     */
    public static function of(mixed $target, ?string $method = null): ?array
    {
        try {
            if ($target instanceof \Closure) {
                $r = new \ReflectionFunction($target);
                $class = $r->getClosureScopeClass()?->getName();
            } elseif (is_string($target) && str_contains($target, '@')) {
                [$cls, $m] = explode('@', $target, 2);
                $r = new \ReflectionMethod($cls, $m);
                $class = $cls;
            } elseif ((is_object($target) || (is_string($target) && class_exists($target))) && $method !== null && method_exists($target, $method)) {
                $r = new \ReflectionMethod($target, $method);
                $class = is_object($target) ? $target::class : $target;
            } elseif (is_object($target) || (is_string($target) && class_exists($target))) {
                $r = new \ReflectionClass($target);
                $file = $r->getFileName();

                return $file === false ? null : ['file' => $file, 'line' => max(1, (int) $r->getStartLine()), 'class' => $r->getName()];
            } else {
                return null;
            }
            $file = $r->getFileName();
            if ($file === false) {
                return null;
            }
            $origin = ['file' => $file, 'line' => max(1, (int) $r->getStartLine())];
            if (!$r instanceof \ReflectionFunction || !$r->isClosure()) {
                $origin['function'] = $r->getName();
            } else {
                $origin['function'] = '{closure}';
            }
            if (isset($class) && $class !== null) {
                $origin['class'] = $class;
            }

            return $origin;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function skip(string $file): bool
    {
        if (Origin::isInternal($file)) {
            return true;
        }

        return self::$skipVendor && Origin::isVendor($file) && self::blade($file) === null; // compiled views are app code
    }

    /**
     * @param array<string, mixed> $callSite
     * @param array<string, mixed>|null $outer
     * @return array<string, mixed>
     */
    private static function point(array $callSite, ?array $outer): array
    {
        $file = (string) $callSite['file'];
        $origin = ['file' => $file];
        $line = (int) ($callSite['line'] ?? 0);
        $mapped = self::blade($file);
        if ($mapped !== null) {
            $origin['file'] = $mapped; // compiled view lines do not match the template: keep the file only
        } elseif ($line > 0) {
            $origin['line'] = $line;
        }
        if ($outer !== null) {
            if (isset($outer['function'])) {
                $origin['function'] = $outer['function'];
            }
            if (isset($outer['class'])) {
                $origin['class'] = $outer['class'];
            }
        }

        return $origin;
    }

    /** The .blade.php file a compiled view was made from (null when $file is not a compiled view). */
    public static function blade(string $file): ?string
    {
        $compiled = self::$compiledPath;
        if ($compiled === null || $compiled === '' || !str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', rtrim($compiled, '/\\')))) {
            return null;
        }
        if (array_key_exists($file, self::$blade)) {
            return self::$blade[$file];
        }
        $source = null;
        $size = @filesize($file);
        if ($size !== false && ($fh = @fopen($file, 'rb')) !== false) {
            @fseek($fh, max(0, $size - 2048));
            $tail = (string) @fread($fh, 2048);
            fclose($fh);
            if (preg_match('~/\*\*PATH (.+?) ENDPATH\*\*/~', $tail, $m)) {
                $source = $m[1];
            }
        }
        if (count(self::$blade) > 500) {
            self::$blade = [];
        }

        return self::$blade[$file] = $source;
    }
}
