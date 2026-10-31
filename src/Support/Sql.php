<?php

declare(strict_types=1);

namespace TheWonderJoy\Gaze\Laravel\Support;

/**
 * SQL helpers for the query watcher: statement type, normalised shape and fingerprint,
 * and the column each "?" placeholder belongs to (for masking bindings).
 *
 * @internal
 */
final class Sql
{
    /** @var array<string, string> */
    private static array $fingerprints = [];

    /**
     * Normalised shape: comments removed, literals and placeholders become "?", IN (...) lists and
     * multi-row VALUES collapse, whitespace collapses, keywords lowercase. Two queries that differ
     * only in their bindings have the same shape.
     */
    public static function shape(string $sql): string
    {
        $s = preg_replace('~/\*.*?\*/|--[^\n]*|#[^\n]*~s', ' ', $sql) ?? $sql;
        $s = preg_replace("~'(?:[^'\\\\]|\\\\.|'')*'~s", '?', $s) ?? $s;         // string literals
        $s = preg_replace('~(?<![\w$])-?\d+(?:\.\d+)?(?:e[+-]?\d+)?\b~i', '?', $s) ?? $s; // numbers
        $s = preg_replace('~\$\d+|:\w+~', '?', $s) ?? $s;                        // $1 / :name placeholders
        $s = strtolower(trim(preg_replace('~\s+~', ' ', $s) ?? $s));
        $s = preg_replace('~\(\s*\?(?:\s*,\s*\?)*\s*\)~', '(?+)', $s) ?? $s;       // in (?, ?, ?) and (?, ?) rows
        $s = preg_replace('~\(\?\+\)(?:\s*,\s*\(\?\+\))+~', '(?+)', $s) ?? $s;   // values (?+), (?+)

        return $s;
    }

    /** Short stable hash of the shape (and connection): "q:" + 16 hex. */
    public static function fingerprint(string $sql, string $connection = ''): string
    {
        $key = $connection . "\0" . $sql;
        if (isset(self::$fingerprints[$key])) {
            return self::$fingerprints[$key];
        }
        if (count(self::$fingerprints) > 2000) {
            self::$fingerprints = [];
        }

        return self::$fingerprints[$key] = 'q:' . hash('xxh3', $connection . "\0" . self::shape($sql));
    }

    /** select | insert | update | delete | other (CTEs, leading comments and REPLACE handled). */
    public static function type(string $sql): string
    {
        $s = ltrim(preg_replace('~^(?:\s*(?:/\*.*?\*/|--[^\n]*\n|#[^\n]*\n))+~s', '', $sql) ?? $sql);
        $s = ltrim($s, " \t\n\r(");
        if (preg_match('~^(select|insert|update|delete|replace|with)\b~i', $s, $m)) {
            $word = strtolower($m[1]);
            if ($word === 'replace') {
                return 'insert';
            }
            if ($word !== 'with') {
                return $word;
            }
            // WITH ... AS (...) [, ... AS (...)] <statement>: the first keyword at depth 0 after the CTEs
            $depth = 0;
            $len = strlen($s);
            for ($i = 4; $i < $len; ++$i) {
                $c = $s[$i];
                if ($c === '(') {
                    ++$depth;
                } elseif ($c === ')') {
                    --$depth;
                } elseif ($depth === 0 && preg_match('~\G\s*(select|insert|update|delete)\b~iA', $s, $mm, 0, $i) && ($i === 0 || !ctype_alnum($s[$i - 1]))) {
                    return strtolower($mm[1]);
                }
            }

            return 'select';
        }

        return 'other';
    }

    /**
     * The column name for each "?" placeholder (null when unknown), in order. Understands
     * INSERT (...) VALUES (?, ?), (...) and `column` <op> ? / IN (?, ?) patterns.
     *
     * @return list<string|null>
     */
    public static function placeholderColumns(string $sql, int $count): array
    {
        $columns = array_fill(0, $count, null);
        if ($count === 0) {
            return $columns;
        }
        if (preg_match('~^\s*(?:insert|replace)\b.*?\(([^()]*)\)\s*values\s*~is', $sql, $m)) {
            $cols = array_map(static fn ($c) => trim($c, " \t\n\r`\"[]"), explode(',', $m[1]));
            $cols = array_values(array_filter($cols, static fn ($c) => $c !== ''));
            if ($cols) {
                foreach ($columns as $i => $_) {
                    $columns[$i] = self::unqualify($cols[$i % count($cols)]);
                }

                return $columns;
            }
        }
        $offset = 0;
        $index = 0;
        $len = strlen($sql);
        $quote = null;
        for ($p = 0; $p < $len && $index < $count; ++$p) {
            $c = $sql[$p];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                continue;
            }
            if ($c !== '?') {
                continue;
            }
            $before = substr($sql, max($offset, $p - 160), $p - max($offset, $p - 160));
            if (preg_match('~([\w.`"\[\]]+)\s*(?:=|!=|<>|<=|>=|<|>|\blike\b|\bnot\s+like\b|\bin\s*\((?:\s*\?\s*,)*)\s*$~i', $before, $mm)) {
                $columns[$index] = self::unqualify($mm[1]);
            }
            ++$index;
        }

        return $columns;
    }

    private static function unqualify(string $identifier): string
    {
        $identifier = str_replace(['`', '"', '[', ']'], '', $identifier);
        $dot = strrpos($identifier, '.');

        return $dot === false ? $identifier : substr($identifier, $dot + 1);
    }
}
