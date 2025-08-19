<?php

/**
 * Plugin Name: DB Version Control
 * Description: Sync WordPress to version-controlled JSON files for easy Git workflows. A fork of DB Version Control Main
 * Version:     1.1.1
 * Author:      Frankly / Robert DeVore
 * Author URI:  https://frankly.design
 * Text Domain: dbvc
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Update URI:  https://github.com/franklyco/db-version-control-advanced/
 */

// If this file is called directly, abort.
if (! defined('ABSPATH')) {
	die;
}

// =================================================================================
// 1. GITHUB PLUGIN UPDATER
// =================================================================================
// This section enables the plugin to receive updates from your private GitHub repository.
// ---------------------------------------------------------------------------------
require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$dbvcUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/franklyco/db-version-control-advanced/', // Your GitHub repository URL.
	__FILE__, // The full path to this main plugin file.
	'db-version-control' // The plugin's slug.
);

// (Optional) Set the branch that contains the stable release.
$dbvcUpdateChecker->setBranch('main');

// (Optional) If your repository is private, uncomment the following line and add your Personal Access Token.
// $dbvcUpdateChecker->setAuthentication('YOUR_GITHUB_PERSONAL_ACCESS_TOKEN');


// =================================================================================
// 2. COMPOSER AUTOLOADER & WP.COM COMPATIBILITY CHECK
// =================================================================================
// This section is inherited from the original plugin to handle Composer dependencies
// and check for compatibility with WordPress.com hosting environments.
// ---------------------------------------------------------------------------------

// @TODO FOR FRANKLY: The URL below points to the original author's website.
// You may want to update this to your own URL or remove this check entirely
// if WordPress.com compatibility is not a concern for your clients.
if (! class_exists('RobertDevore\WPComCheck\WPComPluginHandler')) {
	require_once __DIR__ . '/vendor/autoload.php';
}

use RobertDevore\WPComCheck\WPComPluginHandler;

new WPComPluginHandler(plugin_basename(__FILE__), 'https://robertdevore.com/why-this-plugin-doesnt-support-wordpress-com-hosting/');


// =================================================================================
// 3. PLUGIN CONSTANTS
// =================================================================================
// Defines constants used throughout the plugin for paths and versioning.
// ---------------------------------------------------------------------------------
define('DBVC_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('DBVC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DBVC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('DBVC_PLUGIN_VERSION', '1.1.0');


// =================================================================================
// 4. INCLUDE PLUGIN FILES
// =================================================================================
// Loads all necessary PHP files for the plugin to function.
// ---------------------------------------------------------------------------------
require_once DBVC_PLUGIN_PATH . 'includes/functions.php';
require_once DBVC_PLUGIN_PATH . 'includes/class-sync-posts.php';
require_once DBVC_PLUGIN_PATH . 'includes/hooks.php';
require_once DBVC_PLUGIN_PATH . 'commands/class-wp-cli-commands.php';

if (is_admin()) {
	require_once DBVC_PLUGIN_PATH . 'admin/admin-menu.php';
	require_once DBVC_PLUGIN_PATH . 'admin/admin-page.php';
}


// =================================================================================
// 5. WORDPRESS HOOKS & FILTERS
// =================================================================================
// Initializes the plugin's features by attaching functions to WordPress actions
// and filters.
// ---------------------------------------------------------------------------------

/**
 * Hooks into post save to trigger the JSON export.
 */
add_action('save_post', ['DBVC_Sync_Posts', 'export_post_to_json'], 10, 2);

/**
 * Loads the plugin text domain for internationalization.
 */
function dbvc_load_textdomain()
{
	load_plugin_textdomain('dbvc', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('plugins_loaded', 'dbvc_load_textdomain');

/**
 * Adds a "Settings" link to the plugin's action links on the Plugins page.
 *
 * @param array $links Existing plugin action links.
 * @return array Modified links array.
 */
function dbvc_add_settings_link($links)
{
	$settings_link = '<a href="' . admin_url('admin.php?page=dbvc-export') . '">' . esc_html__('Settings', 'dbvc') . '</a>';
	array_unshift($links, $settings_link);
	return $links;
}
add_filter('plugin_action_links_' . DBVC_PLUGIN_BASENAME, 'dbvc_add_settings_link');

/**
 * Enqueues all necessary CSS and JavaScript for the admin settings page.
 * This single function handles jQuery UI Tabs and Select2.
 *
 * @param string $hook_suffix The hook suffix for the current admin page.
 */
function dbvc_enqueue_admin_scripts($hook_suffix)
{
	// Only load these assets on the plugin's settings page.
	if ('toplevel_page_dbvc-export' !== $hook_suffix) {
		return;
	}

	// Enqueue jQuery UI Tabs script.
	wp_enqueue_script('jquery-ui-tabs');

	// Enqueue a base theme for jQuery UI from a CDN.
	wp_enqueue_style(
		'dbvc-jquery-ui-theme',
		'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css',
		[],
		'1.13.2'
	);

	// Enqueue Select2 styles from a CDN.
	wp_enqueue_style(
		'select2-css',
		'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
		[],
		'4.1.0-rc.0'
	);

	// Enqueue Select2 script from a CDN, with jQuery as a dependency.
	wp_enqueue_script(
		'select2-js',
		'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
		['jquery'],
		'4.1.0-rc.0',
		true // Load in the footer.
	);
}
add_action('admin_enqueue_scripts', 'dbvc_enqueue_admin_scripts');
