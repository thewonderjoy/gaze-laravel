<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Support;

/**
 * Reads PHP serialize() output into a Value tree WITHOUT unserialize(): no class is loaded or
 * instantiated, no __wakeup/__unserialize runs, models in a job (ModelIdentifier) are shown as
 * class + id instead of being re-fetched from the database (laravel-ray's JobEventPayload did
 * unserialize, which re-queried every model and broke on encrypted jobs).
 *
 * @internal
 */
final class SerializedValue
{
    private int $pos = 0;

    private int $nodes = 0;

    private function __construct(
        private readonly string $s,
        private readonly int $maxDepth,
        private readonly int $maxItems,
        private readonly int $maxString,
        private readonly ?\Closure $mask,
    ) {
    }

    /**
     * @param \Closure(string): bool|null $mask property/key names whose values are masked
     * @return array<string, mixed>|null null when $serialized is not valid serialize() output
     */
    public static function parse(string $serialized, ?\Closure $mask = null, int $maxDepth = 6, int $maxItems = 100, int $maxString = 2000): ?array
    {
        $parser = new self($serialized, $maxDepth, $maxItems, $maxString, $mask);
        try {
            $value = $parser->value(1);

            return $parser->pos === strlen($serialized) ? $value : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function value(int $depth): array
    {
        ++$this->nodes;
        $t = $this->s[$this->pos] ?? '';
        switch ($t) {
            case 'N':
                $this->expect('N;');

                return ['k' => 'null'];
            case 'b':
                $v = $this->scalar('b');

                return ['k' => 'bool', 'v' => $v === '1'];
            case 'i':
                $v = $this->scalar('i');

                return \TheWonderJoy\Gaze\Value\ValueFactory::int((int) $v);
            case 'd':
                $v = $this->scalar('d');

                return ['k' => 'float', 'v' => match (strtoupper($v)) {
                    'NAN' => 'NaN', 'INF' => 'INF', '-INF' => '-INF', default => (float) $v,
                }];
            case 's':
                return $this->str($this->string('s'));
            case 'S':
                throw new \UnexpectedValueException('escaped strings are not supported');
            case 'E':
                $name = $this->string('E');

                return ['k' => 'const', 'v' => $name];
            case 'r':
            case 'R':
                $n = (int) $this->scalar($t);

                return ['k' => 'ref', 'to' => $n, 'cls' => null];
            case 'a':
                return $this->array($depth);
            case 'O':
                return $this->object($depth);
            case 'C':
                $cls = $this->string('C', false);
                $this->expect(':');
                $len = $this->int();
                $this->expect(':{');
                $data = substr($this->s, $this->pos, $len);
                $this->pos += $len;
                $this->expect('}');

                return ['k' => 'obj', 'cls' => $cls, 'props' => [['n' => 'serialized', 'vis' => 'meta', 'val' => $this->str($data)]]];
            default:
                throw new \UnexpectedValueException('unexpected ' . $t);
        }
    }

    /** @return array<string, mixed> */
    private function array(int $depth): array
    {
        $this->expect('a:');
        $count = $this->int();
        $this->expect(':{');
        $entries = [];
        $list = true;
        for ($i = 0; $i < $count; ++$i) {
            $key = $this->key();
            if ($key !== $i) {
                $list = false;
            }
            $value = $this->child($depth, is_string($key) ? $key : null);
            if ($i < $this->maxItems) {
                $entries[] = [$key, $value];
            }
        }
        $this->expect('}');
        $node = $list ? ['k' => 'list', 'count' => $count, 'items' => array_map(static fn ($e) => $e[1], $entries)]
            : ['k' => 'map', 'count' => $count, 'entries' => $entries];
        if ($count > $this->maxItems) {
            $node['cut'] = $count - $this->maxItems;
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function object(int $depth): array
    {
        $cls = $this->string('O', false);
        $this->expect(':');
        $count = $this->int();
        $this->expect(':{');
        $props = [];
        for ($i = 0; $i < $count; ++$i) {
            $raw = (string) $this->key();
            $vis = 'pub';
            $decl = null;
            $name = $raw;
            if (str_starts_with($raw, "\0*\0")) {
                $vis = 'prot';
                $name = substr($raw, 3);
            } elseif (str_starts_with($raw, "\0")) {
                $end = strpos($raw, "\0", 1);
                $vis = 'priv';
                $decl = $end === false ? null : substr($raw, 1, $end - 1);
                $name = $end === false ? $raw : substr($raw, $end + 1);
            }
            $value = $this->child($depth, $name);
            if ($i < $this->maxItems) {
                $prop = ['n' => $name, 'vis' => $vis, 'val' => $value];
                if ($decl !== null && $decl !== '') {
                    $prop['decl'] = $decl;
                }
                $props[] = $prop;
            }
        }
        $this->expect('}');
        $node = ['k' => 'obj', 'cls' => $cls, 'props' => $props];
        if ($count > $this->maxItems) {
            $node['cut'] = $count - $this->maxItems;
        }

        return $node;
    }

    /** @return array<string, mixed> */
    private function child(int $depth, ?string $name): array
    {
        if ($depth >= $this->maxDepth || $this->nodes > 5000) {
            $this->value($depth + 1); // still parse to stay in sync, then drop it

            return ['k' => 'const', 'v' => '…'];
        }
        $value = $this->value($depth + 1);
        if ($name !== null && $this->mask !== null && ($this->mask)($name)) {
            return ['k' => 'str', 'v' => '********'];
        }

        return $value;
    }

    private function key(): int|string
    {
        $t = $this->s[$this->pos] ?? '';
        if ($t === 'i') {
            return (int) $this->scalar('i');
        }
        if ($t === 's') {
            return $this->string('s');
        }
        throw new \UnexpectedValueException('bad key');
    }

    /** @return array<string, mixed> */
    private function str(string $v): array
    {
        $len = strlen($v);
        if (!preg_match('//u', $v)) {
            $node = ['k' => 'str', 'v' => base64_encode(substr($v, 0, $this->maxString)), 'enc' => 'b64'];
        } else {
            $node = ['k' => 'str', 'v' => $len > $this->maxString ? mb_strcut($v, 0, $this->maxString, 'UTF-8') : $v];
        }
        if ($len > $this->maxString) {
            $node['cut'] = $len - $this->maxString;
            $node['len'] = $len;
        }

        return $node;
    }

    private function scalar(string $type): string
    {
        $this->expect($type . ':');
        $end = strpos($this->s, ';', $this->pos);
        if ($end === false) {
            throw new \UnexpectedValueException('unterminated');
        }
        $v = substr($this->s, $this->pos, $end - $this->pos);
        $this->pos = $end + 1;

        return $v;
    }

    private function string(string $type, bool $terminated = true): string
    {
        $this->expect($type . ':');
        $len = $this->int();
        $this->expect(':"');
        $v = substr($this->s, $this->pos, $len);
        if (strlen($v) !== $len) {
            throw new \UnexpectedValueException('short string');
        }
        $this->pos += $len;
        $this->expect($terminated ? '";' : '"');

        return $v;
    }

    private function int(): int
    {
        if (!preg_match('/\G-?\d+/', $this->s, $m, 0, $this->pos)) {
            throw new \UnexpectedValueException('number expected');
        }
        $this->pos += strlen($m[0]);

        return (int) $m[0];
    }

    private function expect(string $text): void
    {
        if (substr_compare($this->s, $text, $this->pos, strlen($text)) !== 0) {
            throw new \UnexpectedValueException('expected ' . $text);
        }
        $this->pos += strlen($text);
    }
}
