<?php

/**
 * Plugin Name: DB Version Control Advanced
 * Description: Sync WordPress to version-controlled JSON files for easy Git workflows. A fork of DB Version Control Main
 * Version:     1.3.1
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
if (! defined('DBVC_NEW_ENTITY_DECISION_KEY')) {
	define('DBVC_NEW_ENTITY_DECISION_KEY', '__dbvc_new_entity__');
}

require_once DBVC_PLUGIN_PATH . 'includes/class-database.php';
require_once DBVC_PLUGIN_PATH . 'includes/functions.php';
require_once DBVC_PLUGIN_PATH . 'includes/class-sync-logger.php';
require_once DBVC_PLUGIN_PATH . 'includes/class-backup-manager.php';
require_once DBVC_PLUGIN_PATH . 'includes/class-snapshot-manager.php';
require_once DBVC_PLUGIN_PATH . 'includes/class-media-sync.php';
require_once DBVC_PLUGIN_PATH . 'includes/Dbvc/Media/Logger.php';
require_once DBVC_PLUGIN_PATH . 'includes/Dbvc/Media/BundleManager.php';
require_once DBVC_PLUGIN_PATH . 'includes/Dbvc/Media/Resolver.php';
require_once DBVC_PLUGIN_PATH . 'includes/Dbvc/Media/Reconciler.php';
require_once DBVC_PLUGIN_PATH . 'includes/class-export-manager.php';
// require_once DBVC_PLUGIN_PATH . 'includes/class-menu-importer.php'; // Added new menu importer/exporter class - removed later to avoid over-complicating the class-sync-posts.php
require_once DBVC_PLUGIN_PATH . 'includes/class-sync-posts.php';
require_once DBVC_PLUGIN_PATH . 'includes/class-sync-taxonomies.php';
require_once DBVC_PLUGIN_PATH . 'includes/hooks.php';
require_once DBVC_PLUGIN_PATH . 'commands/class-wp-cli-commands.php';
require_once DBVC_PLUGIN_PATH . 'admin/class-admin-app.php';
DBVC_Admin_App::init();

if (is_admin()) {
	require_once DBVC_PLUGIN_PATH . 'admin/admin-menu.php';
	require_once DBVC_PLUGIN_PATH . 'admin/admin-page.php';
}

DBVC_Database::init();
register_activation_hook(__FILE__, ['DBVC_Database', 'activate']);

// Hook into post save.
add_action('save_post', ['DBVC_Sync_Posts', 'ensure_post_uid_on_save'], 1, 2);
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

function dbvc_enqueue_admin_assets($hook)
{
	if ($hook !== 'toplevel_page_dbvc-export') {
		return;
	}

	wp_enqueue_style(
		'dbvc-select2',
		'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
		[],
		'4.1.0-rc.0'
	);
	wp_enqueue_script(
		'dbvc-select2',
		'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
		['jquery'],
		'4.1.0-rc.0',
		true
	);
}
add_action('admin_enqueue_scripts', 'dbvc_enqueue_admin_assets');
