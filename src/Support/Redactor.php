<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Support;

/**
 * Masks secrets before anything leaves the process: request/response headers, session keys,
 * .env keys, request inputs (and SQL bindings for matching columns), plus extra key patterns the
 * app sends in GET /v1/hello "redact". Values passed as #[\SensitiveParameter] arguments
 * (\SensitiveParameterValue) are always masked.
 *
 * Patterns are case-insensitive; "*" matches any run of characters.
 */
final class Redactor
{
    public const CATEGORIES = ['headers', 'session', 'env', 'inputs'];

    /** @var array<string, string> category => compiled regex (null-safe: never matches) */
    private array $regex = [];

    /** @var list<string> literal parts of the input patterns, for the cheap SQL pre-check */
    private array $inputNeedles = [];

    /**
     * @param array<string, mixed> $config gaze.redact
     * @param list<string> $extra patterns from hello.redact (apply to every category)
     */
    public function __construct(private array $config = [], private array $extra = [])
    {
        $this->compile();
    }

    /** @param list<string> $extra */
    public function withExtra(array $extra): void
    {
        $this->extra = array_values(array_filter($extra, 'is_string'));
        $this->compile();
    }

    public function mask(): string
    {
        $mask = $this->config['mask'] ?? '********';

        return is_string($mask) ? $mask : '********';
    }

    public function matches(string $category, string|int $key): bool
    {
        $re = $this->regex[$category] ?? null;

        return $re !== null && preg_match($re, (string) $key) === 1;
    }

    /**
     * Headers as name => value (first value; several values joined with ", "), masked.
     *
     * @param array<string, mixed> $headers
     * @return array<string, string>
     */
    public function headers(array $headers): array
    {
        $out = [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            if (is_array($value)) {
                $value = implode(', ', array_map(static fn ($v) => is_scalar($v) ? (string) $v : '', $value));
            }
            $out[$name] = $this->matches('headers', $name) ? $this->mask() : (is_scalar($value) ? (string) $value : '');
        }

        return $out;
    }

    /**
     * Recursively masks keys of $category (inputs, session); \SensitiveParameterValue anywhere is masked.
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public function keys(string $category, array $data, int $depth = 0): array
    {
        foreach ($data as $key => $value) {
            if ($this->matches($category, $key)) {
                $data[$key] = $this->mask();
            } elseif ($value instanceof \SensitiveParameterValue) {
                $data[$key] = $this->mask();
            } elseif (is_array($value) && $depth < 16) {
                $data[$key] = $this->keys($category, $value, $depth + 1);
            }
        }

        return $data;
    }

    /** Masks \SensitiveParameterValue (#[\SensitiveParameter] arguments) anywhere in an array. */
    public function sensitive(mixed $value, int $depth = 0): mixed
    {
        if ($value instanceof \SensitiveParameterValue) {
            return $this->mask();
        }
        if (is_array($value) && $depth < 16) {
            foreach ($value as $k => $v) {
                if ($v instanceof \SensitiveParameterValue || is_array($v)) {
                    $value[$k] = $this->sensitive($v, $depth + 1);
                }
            }
        }

        return $value;
    }

    /** Masks the value of a query-string parameter or an .env-style secret inside a URL query. */
    public function url(string $url): string
    {
        $q = strpos($url, '?');
        if ($q === false) {
            return $url;
        }
        $base = substr($url, 0, $q);
        $parts = explode('&', substr($url, $q + 1));
        foreach ($parts as $i => $part) {
            $eq = strpos($part, '=');
            $name = urldecode($eq === false ? $part : substr($part, 0, $eq));
            $name = preg_replace('/\[.*$/', '', $name) ?? $name;
            if ($eq !== false && $this->matches('inputs', $name)) {
                $parts[$i] = substr($part, 0, $eq + 1) . rawurlencode($this->mask());
            }
        }

        return $base . '?' . implode('&', $parts);
    }

    /** True when some input pattern could name a column in $sql (cheap pre-check before parsing). */
    public function sqlMayHaveSecrets(string $sql): bool
    {
        foreach ($this->inputNeedles as $needle) {
            if ($needle !== '' && stripos($sql, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function compile(): void
    {
        $this->regex = [];
        $this->inputNeedles = [];
        foreach (self::CATEGORIES as $category) {
            $patterns = $this->config[$category] ?? [];
            $patterns = array_merge(is_array($patterns) ? $patterns : [], $this->extra);
            $parts = [];
            foreach ($patterns as $pattern) {
                if (!is_string($pattern) || $pattern === '') {
                    continue;
                }
                $parts[] = str_replace('\*', '.*', preg_quote($pattern, '/'));
                if ($category === 'inputs') {
                    foreach (explode('*', $pattern) as $literal) {
                        if (strlen($literal) >= 3) {
                            $this->inputNeedles[] = $literal;
                        }
                    }
                }
            }
            if ($parts) {
                $this->regex[$category] = '/^(?:' . implode('|', $parts) . ')$/i';
            }
        }
    }
}
