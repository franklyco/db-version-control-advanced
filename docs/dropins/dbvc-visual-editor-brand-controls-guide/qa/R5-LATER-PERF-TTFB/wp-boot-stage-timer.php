<?php
/**
 * R5.later-perf TTFB probe (E-159) — WordPress boot stage timer.
 * Usage: wp eval '1;' --require=<this file>   (optionally --skip-plugins=... / --skip-themes)
 * Prints the elapsed time at the first and last listener of each boot hook.
 */
$GLOBALS['__t0']   = microtime( true );
$GLOBALS['__last'] = $GLOBALS['__t0'];
function __dbvc_stage( $name ) {
	$now = microtime( true );
	fprintf( STDERR, "%-24s +%6.0f ms  (total %6.0f ms)\n", $name, ( $now - $GLOBALS['__last'] ) * 1000, ( $now - $GLOBALS['__t0'] ) * 1000 );
	$GLOBALS['__last'] = $now;
}
foreach ( [ 'muplugins_loaded', 'plugins_loaded', 'setup_theme', 'after_setup_theme', 'init', 'wp_loaded' ] as $h ) {
	WP_CLI::add_wp_hook( $h, function () use ( $h ) { __dbvc_stage( "$h:start" ); }, PHP_INT_MIN );
	WP_CLI::add_wp_hook( $h, function () use ( $h ) { __dbvc_stage( "$h:end" ); }, PHP_INT_MAX );
}
