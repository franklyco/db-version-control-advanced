<?php
/**
 * R5.later-perf TTFB probe (E-159) — per-listener cost on the boot hooks.
 * Usage: wp eval '1;' --require=<this file>
 * Wraps every callback registered on plugins_loaded / after_setup_theme / init / wp_loaded
 * (at PHP_INT_MIN, i.e. before they run) and prints the top costs per hook attributed to
 * the callback's declaring class/function and file. Timings are inclusive.
 */
$GLOBALS['__prof'] = [];
function __cb_label($cb) {
    try {
        if (is_string($cb) && str_contains($cb, '::')) { $r = new ReflectionMethod($cb); }
        elseif (is_string($cb)) { $r = new ReflectionFunction($cb); }
        elseif (is_array($cb)) { $r = new ReflectionMethod(is_object($cb[0]) ? get_class($cb[0]) : $cb[0], $cb[1]); }
        elseif ($cb instanceof Closure) { $r = new ReflectionFunction($cb); }
        else { return 'unknown'; }
        $file = str_replace(ABSPATH, '', (string) $r->getFileName());
        $name = $r instanceof ReflectionMethod ? ($r->getDeclaringClass()->getName() . '::' . $r->getName()) : ($r->getName() === '{closure}' ? 'closure' : $r->getName());
        return $name . ' @ ' . $file . ':' . $r->getStartLine();
    } catch (Throwable $e) { return 'unreflectable'; }
}
function __wrap_hook($hook) {
    global $wp_filter;
    if (empty($wp_filter[$hook])) return;
    foreach ($wp_filter[$hook]->callbacks as $prio => &$cbs) {
        foreach ($cbs as $id => &$entry) {
            $orig = $entry['function']; $label = __cb_label($orig);
            $entry['function'] = function (...$args) use ($orig, $label, $hook) {
                $t = microtime(true); $r = $orig(...$args);
                $GLOBALS['__prof'][$hook][$label] = ($GLOBALS['__prof'][$hook][$label] ?? 0) + (microtime(true) - $t);
                return $r;
            };
        }
    }
}
foreach (['plugins_loaded', 'after_setup_theme', 'init', 'wp_loaded'] as $h) {
    WP_CLI::add_wp_hook($h, function () use ($h) { __wrap_hook($h); }, PHP_INT_MIN);
}
WP_CLI::add_wp_hook('wp_loaded', function () {
    foreach ($GLOBALS['__prof'] as $hook => $rows) {
        arsort($rows); $total = array_sum($rows);
        fprintf(STDERR, "\n== %s  (listeners total %.0f ms)\n", $hook, $total * 1000);
        foreach (array_slice($rows, 0, 8, true) as $label => $sec) { if ($sec * 1000 < 20) break; fprintf(STDERR, "  %6.0f ms  %s\n", $sec * 1000, $label); }
    }
}, PHP_INT_MAX);
