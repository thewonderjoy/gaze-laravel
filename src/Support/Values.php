<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Support;

use TheWonderJoy\Gaze\Value\ValueFactory;

/**
 * Builders for RFC-0001 Value nodes used in Laravel entry bodies.
 *
 * @internal
 */
final class Values
{
    public static function from(mixed $value): array
    {
        if ($value instanceof \SensitiveParameterValue) {
            return ['k' => 'str', 'v' => '********'];
        }

        return ValueFactory::from($value);
    }

    /**
     * A synthetic object node: {k: obj, cls, props: [{n, vis: pub, val}]}; null values are skipped.
     *
     * @param array<string, mixed> $props raw PHP values (converted) or Value nodes (['k' => ...] arrays, kept)
     * @return array<string, mixed>
     */
    public static function obj(string $class, array $props): array
    {
        $out = [];
        foreach ($props as $name => $value) {
            if ($value === null) {
                continue;
            }
            $out[] = ['n' => (string) $name, 'vis' => 'pub', 'val' => self::isNode($value) ? $value : self::from($value)];
        }

        return ['k' => 'obj', 'cls' => $class === '' ? 'object' : $class, 'props' => $out];
    }

    /** @return array<string, mixed> {k: err, name, message, stack} */
    public static function error(\Throwable $e, int $stackChars = 4000): array
    {
        $stack = $e->getFile() . ':' . $e->getLine() . "\n" . $e->getTraceAsString();
        if (strlen($stack) > $stackChars) {
            $stack = substr($stack, 0, $stackChars) . "\n…";
        }
        $node = ['k' => 'err', 'name' => $e::class, 'message' => $e->getMessage(), 'stack' => $stack];
        if ($e->getPrevious() !== null) {
            $node['cause'] = self::error($e->getPrevious(), (int) ($stackChars / 2));
        }

        return $node;
    }

    /**
     * Symfony Address list to [{email, name}] (IPC contract Addr).
     *
     * @param iterable<mixed> $addresses
     * @return list<array{email: string, name: string|null}>
     */
    public static function addresses(iterable $addresses): array
    {
        $out = [];
        foreach ($addresses as $address) {
            if (is_object($address) && method_exists($address, 'getAddress')) {
                $name = method_exists($address, 'getName') ? (string) $address->getName() : '';
                $out[] = ['email' => (string) $address->getAddress(), 'name' => $name === '' ? null : $name];
            } elseif (is_array($address) && isset($address['address'])) {
                $name = (string) ($address['name'] ?? '');
                $out[] = ['email' => (string) $address['address'], 'name' => $name === '' ? null : $name];
            } elseif (is_string($address)) {
                $out[] = ['email' => $address, 'name' => null];
            }
        }

        return $out;
    }

    /** @return array{0: string, 1: int} text cut to $max bytes (on a UTF-8 boundary) and the bytes cut */
    public static function cut(string $text, int $max): array
    {
        $len = strlen($text);
        if ($len <= $max) {
            return [$text, 0];
        }
        $cut = mb_strcut($text, 0, $max, 'UTF-8');

        return [$cut, $len - strlen($cut)];
    }

    public static function isNode(mixed $value): bool
    {
        return is_array($value) && isset($value['k']) && is_string($value['k']) && count($value) <= 8
            && in_array($value['k'], ['null', 'undef', 'bool', 'int', 'float', 'str', 'const', 'list', 'map', 'obj', 'ref', 'res', 'fn', 'date', 'err', 'dom'], true);
    }

    public static function nowMs(): float
    {
        return round(microtime(true) * 1000, 3);
    }
}
