<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Tests;

/** Sets env vars the way a .env file or the shell does ($_SERVER, $_ENV and putenv), and puts them back. */
trait SetsEnv
{
    /** @var array<string, array{0: mixed, 1: mixed, 2: string|false}> */
    private array $savedEnv = [];

    /** @param array<string, string|null> $vars null unsets the variable */
    protected function setEnv(array $vars): void
    {
        foreach ($vars as $name => $value) {
            if (!array_key_exists($name, $this->savedEnv)) {
                $this->savedEnv[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null, getenv($name)];
            }
            if ($value === null) {
                unset($_SERVER[$name], $_ENV[$name]);
                putenv($name);
            } else {
                $_SERVER[$name] = $_ENV[$name] = $value;
                putenv($name . '=' . $value);
            }
        }
    }

    protected function restoreEnv(): void
    {
        foreach ($this->savedEnv as $name => [$server, $env, $put]) {
            if ($server === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $server;
            }
            if ($env === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $env;
            }
            putenv($put === false ? $name : $name . '=' . $put);
        }
        $this->savedEnv = [];
    }

    /** @return list<string> every env var config/gaze.php reads */
    protected static function configEnvNames(): array
    {
        preg_match_all("/env\\('([A-Z0-9_]+)'/", (string) file_get_contents(dirname(__DIR__) . '/config/gaze.php'), $m);

        return array_values(array_unique($m[1]));
    }
}
