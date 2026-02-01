<?php
/**
 * PHPUnit bootstrap for DBVC plugin.
 */

$_tests_dir = getenv('WP_TESTS_DIR');
if (! $_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

if (! file_exists($_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "Could not find the WordPress tests library in {$_tests_dir}.\n");
    fwrite(STDERR, "Run bin/install-wp-tests.sh <db> <user> <pass> to install it.\n");
    exit(1);
}

require $_tests_dir . '/includes/functions.php';

function _dbvc_tests_load_plugin()
{
    require dirname(dirname(__DIR__)) . '/db-version-control.php';
}
tests_add_filter('muplugins_loaded', '_dbvc_tests_load_plugin');

require $_tests_dir . '/includes/bootstrap.php';
