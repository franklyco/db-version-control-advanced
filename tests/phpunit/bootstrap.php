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

/**
 * Locate WS Form so the live WS Form tests can run instead of skipping.
 *
 * WP_PLUGIN_DIR is no help here: under PHPUnit it points at the throwaway test
 * WordPress, not at the site where WS Form is installed. Walk up from this
 * plugin's directory instead, looking for a directory that contains WS Form.
 * The walk matters because a git worktree sits several levels below the plugin
 * root, so WS Form is not a direct sibling there.
 *
 * Opt-in: set DBVC_TESTS_WS_FORM=1 to load it. It is off by default because
 * WS Form registers the `wsf-block/form-add` block type, and having it present
 * makes WP's block registry emit an "already registered" incorrect-usage notice
 * that WP_UnitTestCase escalates into a failure in BricksAddonPhase1Test. Off by
 * default keeps the suite's behaviour unchanged; the live WS Form tests skip.
 *
 * Set WS_FORM_PLUGIN_FILE to point at a specific entry file.
 *
 * @return string Absolute path to the WS Form entry file, or '' if not found.
 */
function _dbvc_tests_find_ws_form()
{
    if (getenv('DBVC_TESTS_WS_FORM') !== '1') {
        return '';
    }

    $override = getenv('WS_FORM_PLUGIN_FILE');
    if (is_string($override) && $override !== '') {
        return file_exists($override) ? $override : '';
    }

    $dir = dirname(__DIR__, 2);

    for ($depth = 0; $depth < 6; $depth++) {
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }

        foreach (['ws-form-pro', 'ws-form'] as $slug) {
            $candidate = $parent . '/' . $slug . '/ws-form.php';
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        $dir = $parent;
    }

    return '';
}

/**
 * @return void
 */
function _dbvc_tests_load_ws_form()
{
    $ws_form_file = _dbvc_tests_find_ws_form();
    if ($ws_form_file === '') {
        return;
    }

    require_once $ws_form_file;
}
// plugins_loaded, not muplugins_loaded: wp-settings.php defines the auth cookie
// constants between the two, and WS Form's admin bootstrap calls
// is_user_logged_in() while loading. At muplugins_loaded that fatals with
// "Undefined constant AUTH_COOKIE" in any harness where is_admin() is true.
tests_add_filter('plugins_loaded', '_dbvc_tests_load_ws_form');

/**
 * Create the WS Form tables in the test database on first use.
 *
 * Probes for the table first so repeat runs stay cheap.
 *
 * @return void
 */
function _dbvc_tests_activate_ws_form()
{
    if (! defined('WS_FORM_PLUGIN_DIR_PATH') || ! class_exists('WS_Form_Common')) {
        return;
    }

    global $wpdb;

    $prefix = defined('WS_FORM_DB_TABLE_PREFIX') ? WS_FORM_DB_TABLE_PREFIX : 'wsf_';
    $table = $wpdb->prefix . $prefix . 'form';

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
        return;
    }

    if (! class_exists('WS_Form_Activator')) {
        require_once WS_FORM_PLUGIN_DIR_PATH . 'includes/class-ws-form-activator.php';
    }

    WS_Form_Activator::activate();
}
tests_add_filter('init', '_dbvc_tests_activate_ws_form', 1);

/**
 * Force the connector and hub to use the `savepoint` transaction strategy for the
 * whole suite.
 *
 * The WordPress PHPUnit harness runs each test inside one open transaction and
 * rolls it back on teardown. Both add-ons guard their multi-row writes with a
 * transaction; under the DEFAULT `transaction` strategy that issues
 * `START TRANSACTION`, which IMPLICITLY COMMITS the harness's wrapping transaction
 * — leaking rows (enrolled-environment users, option and `dbvc_ac_*` rows) that
 * then persist across tests and accumulate until `_delete_all_data()` deletes a
 * large set at class teardown and intermittently deadlocks (`DELETE FROM
 * …users …` → "Deadlock found"). Nesting every write in a SAVEPOINT keeps it
 * inside the harness transaction — the correct test behaviour — so nothing leaks.
 *
 * Previously each connected test class opted in individually, so the ~30 that did
 * not drove leaking commits. Installing it suite-wide here is the single source of
 * truth; production is unaffected (it never loads this bootstrap).
 *
 * @return string
 */
function _dbvc_tests_savepoint_strategy()
{
    return 'savepoint';
}
tests_add_filter('dbvc_connected_transaction_strategy', '_dbvc_tests_savepoint_strategy');
tests_add_filter('dbvc_agency_control_transaction_strategy', '_dbvc_tests_savepoint_strategy');

// Serialize test runs that share a test database. The WordPress PHPUnit harness
// drops and recreates the whole schema on every run, so two phpunit processes
// pointed at the same DB + table prefix (every DBVC worktree + the main checkout
// default to DB `local` / prefix `wptests_` on the one LocalWP MySQL) corrupt each
// other mid-run — surfacing as intermittent InnoDB deadlocks in teardown
// (`[Deadlock found] … DELETE FROM …users`) and spurious failures. An exclusive
// advisory lock, keyed by DB name + prefix and held for the process lifetime,
// makes concurrent runs queue and run one at a time (the only safe option on a
// shared schema); a crashed holder releases the lock automatically. Runs against
// a different DB/prefix take a different lock and never block each other.
$GLOBALS['_dbvc_tests_run_lock'] = _dbvc_tests_acquire_run_lock($_dbvc_tests_config);

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
 * Extract a `define('NAME', 'value')` string constant from config file contents.
 *
 * @param string $contents
 * @param string $name
 * @return string
 */
function _dbvc_tests_extract_define($contents, $name)
{
    $pattern = '/define\(\s*[\'"]' . preg_quote($name, '/') . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/';

    return preg_match($pattern, (string) $contents, $matches) ? trim((string) $matches[1]) : '';
}

/**
 * Acquire a machine-wide exclusive advisory lock for this test DB + table prefix,
 * so concurrent phpunit runs against the same schema serialize instead of
 * corrupting each other's drop/recreate. Best-effort: if the lock file cannot be
 * opened the run simply proceeds unserialized.
 *
 * @param string $config_path
 * @return resource|null Open lock handle — keep referenced for the process lifetime; released on exit.
 */
function _dbvc_tests_acquire_run_lock($config_path)
{
    $contents = @file_get_contents($config_path);
    if (! is_string($contents) || $contents === '') {
        return null;
    }
    $db_name = _dbvc_tests_extract_define($contents, 'DB_NAME');
    $prefix = _dbvc_tests_extract_config_value($contents, '$table_prefix');
    $key = sha1($db_name . '|' . $prefix);
    $lock_path = sys_get_temp_dir() . '/dbvc-phpunit-' . $key . '.lock';
    $handle = @fopen($lock_path, 'c');
    if ($handle === false) {
        return null;
    }
    // Block until exclusive. The OS releases the lock if the holder crashes, so a
    // dead process can never wedge this permanently.
    flock($handle, LOCK_EX);

    return $handle;
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
