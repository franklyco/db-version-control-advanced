<?php
/**
 * PHPUnit bootstrap for DBVC plugin.
 */

$dbvc_plugin_dir = dirname(__DIR__, 2);
$dbvc_vendor_dir = $dbvc_plugin_dir . '/vendor';

$_tests_dir = getenv('WP_TESTS_DIR');
if (! $_tests_dir) {
    $_tests_dir = $dbvc_plugin_dir . '/tmp/wordpress-tests-lib';
}

$_dbvc_plugin_root = dirname(dirname(__DIR__));
$_dbvc_tests_config = $_tests_dir . '/wp-tests-config.php';

if (! file_exists($_tests_dir . '/includes/functions.php')) {
    fwrite(STDERR, "Could not find the WordPress tests library in {$_tests_dir}.\n");
    fwrite(STDERR, "Run bin/install-wp-tests.sh <db> <user> <pass> to install it.\n");
    exit(1);
}

if (! file_exists($_dbvc_tests_config)) {
    fwrite(STDERR, "Could not find wp-tests-config.php in {$_tests_dir}.\n");
    fwrite(STDERR, "Run bin/install-wp-tests.sh <db> <user> <pass> to generate a safe test config.\n");
    exit(1);
}

$polyfills_path = $dbvc_vendor_dir . '/yoast/phpunit-polyfills';
if (! defined('WP_TESTS_PHPUNIT_POLYFILLS_PATH') && is_dir($polyfills_path)) {
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $polyfills_path);
}

require $_tests_dir . '/includes/functions.php';

_dbvc_tests_assert_safe_config($_dbvc_tests_config, $_dbvc_plugin_root);

function _dbvc_tests_load_plugin()
{
    if (! defined('DBVC_PHPUNIT')) {
        define('DBVC_PHPUNIT', true);
    }
    require dirname(__DIR__, 2) . '/db-version-control.php';
}
tests_add_filter('muplugins_loaded', '_dbvc_tests_load_plugin');

require $_tests_dir . '/includes/bootstrap.php';

/**
 * @param string $message
 * @return void
 */
function _dbvc_tests_abort($message)
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

/**
 * @param string $config_path
 * @param string $plugin_root
 * @return void
 */
function _dbvc_tests_assert_safe_config($config_path, $plugin_root)
{
    $contents = file_get_contents($config_path);
    if (! is_string($contents) || trim($contents) === '') {
        _dbvc_tests_abort("Unsafe wp-tests-config.php: unable to read {$config_path}.");
    }

    $table_prefix = _dbvc_tests_extract_config_value($contents, '$table_prefix');
    if ($table_prefix === '') {
        _dbvc_tests_abort(
            "Unsafe wp-tests-config.php: missing \$table_prefix in {$config_path}. Re-run bin/install-wp-tests.sh so PHPUnit uses an isolated test prefix."
        );
    }

    $unsafe_prefixes = ['wp_'];
    $live_prefix = _dbvc_tests_detect_live_table_prefix($plugin_root);
    if ($live_prefix !== '' && ! in_array($live_prefix, $unsafe_prefixes, true)) {
        $unsafe_prefixes[] = $live_prefix;
    }

    if (in_array($table_prefix, $unsafe_prefixes, true)) {
        _dbvc_tests_abort(
            "Unsafe wp-tests-config.php: test table prefix '{$table_prefix}' matches the live site prefix. Use an isolated test prefix such as 'wptests_'."
        );
    }
}

/**
 * @param string $contents
 * @param string $symbol
 * @return string
 */
function _dbvc_tests_extract_config_value($contents, $symbol)
{
    $pattern = '/^\s*' . preg_quote($symbol, '/') . '\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/m';
    if (! preg_match($pattern, $contents, $matches)) {
        return '';
    }

    return isset($matches[1]) ? trim((string) $matches[1]) : '';
}

/**
 * @param string $plugin_root
 * @return string
 */
function _dbvc_tests_detect_live_table_prefix($plugin_root)
{
    $wp_config_path = dirname(dirname(dirname($plugin_root))) . '/wp-config.php';
    if (! file_exists($wp_config_path)) {
        return '';
    }

    $contents = file_get_contents($wp_config_path);
    if (! is_string($contents) || trim($contents) === '') {
        return '';
    }

    return _dbvc_tests_extract_config_value($contents, '$table_prefix');
}
