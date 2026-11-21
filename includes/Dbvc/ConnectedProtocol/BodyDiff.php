<?php

namespace Dbvc\ConnectedProtocol;

/**
 * Deterministic nested diff of two canonical bodies (CR-1).
 *
 * Walks both decoded bodies together and reports every leaf that differs as
 * one row: a dot-path (list indexes are plain integers: `content.3.settings.text`),
 * the operation (`add` / `remove` / `replace`) and the canonical hash of each
 * side's subtree. Maps recurse by key union; lists recurse by index (ordering
 * inside a list is meaningful, as the canonicalizer contract says); a node
 * whose shape differs between the sides (list vs map, scalar vs array) is one
 * `replace` leaf. Equal subtrees are skipped early.
 *
 * Bounds keep a receipt predictable: at most `max_paths` rows (further rows are
 * dropped and `truncated` is set) and at most `max_depth` levels (a differing
 * subtree at the limit is reported as one `replace` row with `collapsed=true`
 * instead of being expanded). Rows are sorted by path (byte order), the same
 * ordering the top-level patch used before CR-1, so output is stable across
 * PHP versions and array insertion orders.
 *
 * Pure: no WordPress, no I/O. Shared by the connector (which emits the patch)
 * and the hub (which validates and renders it).
 */
final class BodyDiff
{
    public const DEFAULT_MAX_PATHS = 1000;
    public const DEFAULT_MAX_DEPTH = 32;

    public const OP_ADD = 'add';
    public const OP_REMOVE = 'remove';
    public const OP_REPLACE = 'replace';

    /**
     * @param array<string, mixed>                 $before
     * @param array<string, mixed>                 $after
     * @param array{max_paths?: int, max_depth?: int} $opts
     * @return array{changed_paths: array<int, array<string, mixed>>, truncated: bool}
     */
    public static function paths(array $before, array $after, array $opts = [])
    {
        $max_paths = max(1, (int) ($opts['max_paths'] ?? self::DEFAULT_MAX_PATHS));
        $max_depth = max(1, (int) ($opts['max_depth'] ?? self::DEFAULT_MAX_DEPTH));
        $rows = [];
        $truncated = false;
        self::walk($before, $after, '', 0, $max_depth, $rows);
        usort($rows, static function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });
        if (count($rows) > $max_paths) {
            $rows = array_slice($rows, 0, $max_paths);
            $truncated = true;
        }

        return ['changed_paths' => $rows, 'truncated' => $truncated];
    }

    /**
     * Read a dot-path out of a decoded body; numeric segments index lists.
     * Returns `[found, value]` so an absent key is distinguishable from null.
     *
     * @param mixed  $body
     * @param string $path
     * @return array{0: bool, 1: mixed}
     */
    public static function value_at($body, $path)
    {
        if ($path === '') {
            return [true, $body];
        }
        $node = $body;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($node)) {
                return [false, null];
            }
            if (array_key_exists($segment, $node)) {
                $node = $node[$segment];
                continue;
            }
            if (ctype_digit($segment) && array_key_exists((int) $segment, $node)) {
                $node = $node[(int) $segment];
                continue;
            }
            return [false, null];
        }

        return [true, $node];
    }

    /**
     * @param mixed                            $before
     * @param mixed                            $after
     * @param string                           $prefix
     * @param int                              $depth
     * @param int                              $max_depth
     * @param array<int, array<string, mixed>> $rows
     * @return void
     */
    private static function walk($before, $after, $prefix, $depth, $max_depth, array &$rows)
    {
        if ($before === $after) {
            return;
        }
        $both_arrays = is_array($before) && is_array($after);
        if (! $both_arrays) {
            // A leaf, or a scalar-vs-array shape change: one row.
            $rows[] = self::row($prefix, $before, $after, false);
            return;
        }
        $before_list = Canonicalizer::isList($before);
        $after_list = Canonicalizer::isList($after);
        // An empty array is both a list and a map; let the non-empty side decide the shape.
        if ($before === []) {
            $before_list = $after_list;
        } elseif ($after === []) {
            $after_list = $before_list;
        }
        if ($before_list !== $after_list && $prefix !== '') {
            $rows[] = self::row($prefix, $before, $after, false);
            return;
        }
        if ($depth >= $max_depth && $prefix !== '') {
            $rows[] = self::row($prefix, $before, $after, true);
            return;
        }
        $keys = [];
        foreach (array_keys($before) as $key) {
            $keys[(string) $key] = true;
        }
        foreach (array_keys($after) as $key) {
            $keys[(string) $key] = true;
        }
        foreach (array_keys($keys) as $key) {
            $path = $prefix === '' ? $key : $prefix . '.' . $key;
            $in_before = array_key_exists($key, $before);
            $in_after = array_key_exists($key, $after);
            if ($in_before && $in_after) {
                self::walk($before[$key], $after[$key], $path, $depth + 1, $max_depth, $rows);
            } elseif ($in_before) {
                $rows[] = ['path' => $path, 'operation' => self::OP_REMOVE, 'before_hash' => self::hash($before[$key]), 'after_hash' => null];
            } else {
                $rows[] = ['path' => $path, 'operation' => self::OP_ADD, 'before_hash' => null, 'after_hash' => self::hash($after[$key])];
            }
        }
    }

    /**
     * @param string $path
     * @param mixed  $before
     * @param mixed  $after
     * @param bool   $collapsed
     * @return array<string, mixed>
     */
    private static function row($path, $before, $after, $collapsed)
    {
        $row = ['path' => $path, 'operation' => self::OP_REPLACE, 'before_hash' => self::hash($before), 'after_hash' => self::hash($after)];
        if ($collapsed) {
            $row['collapsed'] = true;
        }

        return $row;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function hash($value)
    {
        return Canonicalizer::hash(Canonicalizer::encode($value));
    }
}
