<?php

/**
 * Plugin Name: DB Version Control Advanced
 * Description: Sync WordPress to version-controlled JSON files for easy Git workflows. A fork of DB Version Control Main
 * Version:     1.1.53
 * Author:      Frankly / Robert DeVore
 * Author URI:  https://frankly.design
 * Text Domain: dbvc
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Update URI:  https://github.com/franklyco/db-version-control-advanced/
 */

// If this file is called directly, abort.
if (! defined('WPINC')) {
	die;
}

// Current Version 1.1.4
require 'vendor/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/franklyco/db-version-control-advanced/',
	__FILE__,
	'db-version-control'
);

// Set the branch that contains the stable release.
$myUpdateChecker->setBranch('main');

// Check if Composer's autoloader is already registered globally.
if (! class_exists('RobertDevore\WPComCheck\WPComPluginHandler')) {
	require_once __DIR__ . '/vendor/autoload.php';
}

use RobertDevore\WPComCheck\WPComPluginHandler;

new WPComPluginHandler(plugin_basename(__FILE__), 'https://robertdevore.com/why-this-plugin-doesnt-support-wordpress-com-hosting/');

// Define constants for the plugin.
define('DBVC_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('DBVC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DBVC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('DBVC_PLUGIN_VERSION', '1.1.0');

require_once DBVC_PLUGIN_PATH . 'includes/functions.php';
// require_once DBVC_PLUGIN_PATH . 'includes/class-menu-importer.php'; // Added new menu importer/exporter class - removed later to avoid over-complicating the class-sync-posts.php
require_once DBVC_PLUGIN_PATH . 'includes/class-sync-posts.php';
require_once DBVC_PLUGIN_PATH . 'includes/hooks.php';
require_once DBVC_PLUGIN_PATH . 'commands/class-wp-cli-commands.php';
if (is_admin()) {
	require_once DBVC_PLUGIN_PATH . 'admin/admin-menu.php';
	require_once DBVC_PLUGIN_PATH . 'admin/admin-page.php';
}

// Hook into post save.
add_action('save_post', ['DBVC_Sync_Posts', 'export_post_to_json'], 10, 2);

/**
 * Load plugin text domain for translations.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_load_textdomain()
{
	load_plugin_textdomain('dbvc', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('plugins_loaded', 'dbvc_load_textdomain');

/**
 * Add settings link to plugin action links.
 * 
 * @param mixed $links
 * 
 * @since  1.0.0
 * @return array
 */
function dbvc_add_settings_link($links)
{
	$settings_link = '<a href="' . admin_url('admin.php?page=dbvc-export') . '">' . esc_html__('Settings', 'dbvc') . '</a>';
	array_unshift($links, $settings_link);
	return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'dbvc_add_settings_link');

// Adds Jquery UI for settings tabs
function dbvc_enqueue_tabs($hook)
{
	if ($hook !== 'toplevel_page_db-version-control') return;
	wp_enqueue_script('jquery-ui-tabs');
	wp_enqueue_style('jquery-ui-core');
	wp_enqueue_style('jquery-ui-theme');
}
add_action('admin_enqueue_scripts', 'dbvc_enqueue_tabs');

function dbvc_enqueue_admin_assets($hook_suffix)
{
	// Only load on our DBVC settings page
	// If you registered your menu with 'dbvc-export' as the slug, 
	// the hook_suffix will be 'toplevel_page_dbvc-export'
	if ('toplevel_page_dbvc-export' !== $hook_suffix) {
		return;
	}

	// Enqueue jQuery UI Tabs
	wp_enqueue_script('jquery-ui-tabs');

	// Optional: enqueue a theme for the tabs so they look nice
	wp_enqueue_style(
		'dbvc-jquery-ui-theme',
		'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css',
		[],
		'1.13.2'
	);
}
add_action('admin_enqueue_scripts', 'dbvc_enqueue_admin_assets');

function dbvc_enqueue_select2_and_tabs($hook)
{
	if ($hook !== 'toplevel_page_dbvc-export') return;

	// jQuery UI Tabs (if you use it)
	wp_enqueue_script('jquery-ui-tabs');
	wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css');

	// Select2 styles & scripts (from CDN)
	wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
	wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);
}
add_action('admin_enqueue_scripts', 'dbvc_enqueue_select2_and_tabs');
