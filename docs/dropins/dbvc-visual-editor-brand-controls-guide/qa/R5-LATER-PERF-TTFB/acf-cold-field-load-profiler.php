<?php
/**
 * R5.later-perf TTFB probe (E-159) — cold ACF field materialisation cost and its filters.
 * Usage: wp eval '1;' --require=<this file>
 * Runs at init:PHP_INT_MIN (before Bricks registers dynamic-data tags, so ACF's stores are cold),
 * wraps every acf/load_field* / acf/validate_field* / acf/prepare_field* listener, loads every
 * field of every field group once, and prints the inclusive cost per listener.
 */
$GLOBALS['__prof'] = []; $GLOBALS['__calls'] = [];
function __cb_label($cb) { try { if (is_string($cb) && str_contains($cb, '::')) $r = new ReflectionMethod($cb); elseif (is_string($cb)) $r = new ReflectionFunction($cb); elseif (is_array($cb)) $r = new ReflectionMethod(is_object($cb[0]) ? get_class($cb[0]) : $cb[0], $cb[1]); elseif ($cb instanceof Closure) $r = new ReflectionFunction($cb); else return 'unknown'; $file = str_replace(ABSPATH, '', (string) $r->getFileName()); $name = $r instanceof ReflectionMethod ? ($r->getDeclaringClass()->getName() . '::' . $r->getName()) : $r->getName(); return $name . ' @ ' . $file . ':' . $r->getStartLine(); } catch (Throwable $e) { return 'unreflectable'; } }
function __wrap_hook($hook) { global $wp_filter; if (empty($wp_filter[$hook])) return; foreach ($wp_filter[$hook]->callbacks as $prio => &$cbs) { foreach ($cbs as $id => &$entry) { $orig = $entry['function']; $label = __cb_label($orig); $entry['function'] = function (...$args) use ($orig, $label, $hook) { $t = microtime(true); $r = $orig(...$args); $GLOBALS['__prof'][$hook][$label] = ($GLOBALS['__prof'][$hook][$label] ?? 0) + (microtime(true) - $t); $GLOBALS['__calls'][$hook][$label] = ($GLOBALS['__calls'][$hook][$label] ?? 0) + 1; return $r; }; } } }
WP_CLI::add_wp_hook('init', function () {
    global $wp_filter;
    $hooks = array_filter(array_keys($wp_filter), fn($h) => str_starts_with($h, 'acf/load_field') || str_starts_with($h, 'acf/prepare_field') || $h === 'acf/get_fields' || str_starts_with($h, 'acf/validate_field') || str_starts_with($h, 'acf/get_valid_field'));
    foreach ($hooks as $h) __wrap_hook($h);
    $t = microtime(true); foreach (acf_get_field_groups() as $g) acf_get_fields($g); $total = (microtime(true) - $t) * 1000;
    fprintf(STDERR, "acf_get_fields cold: %.0f ms; wrapped hooks: %d\n", $total, count($hooks));
    $rows = []; foreach ($GLOBALS['__prof'] as $hook => $l) foreach ($l as $label => $sec) $rows["$hook :: $label"] = [$sec * 1000, $GLOBALS['__calls'][$hook][$label]];
    uasort($rows, fn($a, $b) => $b[0] <=> $a[0]);
    foreach (array_slice($rows, 0, 10, true) as $k => [$ms, $n]) fprintf(STDERR, "  %6.0f ms  %6d calls  %s\n", $ms, $n, $k);
}, PHP_INT_MIN);
