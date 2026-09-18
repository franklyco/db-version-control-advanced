<?php
/**
 * R5.later-perf TTFB probe (E-159) — per-plugin main-file include cost.
 * Usage: wp eval '1;' --require=<this file> 2>&1 | grep ' ms ' | sort -rn
 * Note: CLI numbers include compilation; PHP-FPM with a warm opcache is far cheaper for pure includes.
 */
$GLOBALS['__pl_last'] = null;
WP_CLI::add_wp_hook( 'muplugins_loaded', function () { $GLOBALS['__pl_last'] = microtime( true ); }, PHP_INT_MAX );
WP_CLI::add_wp_hook( 'plugin_loaded', function ( $plugin ) {
	$now = microtime( true );
	if ( $GLOBALS['__pl_last'] !== null ) {
		fprintf( STDERR, "  %6.0f ms  %s\n", ( $now - $GLOBALS['__pl_last'] ) * 1000, basename( dirname( $plugin ) ) . '/' . basename( $plugin ) );
	}
	$GLOBALS['__pl_last'] = $now;
}, PHP_INT_MAX );
