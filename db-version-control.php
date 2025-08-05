<?php
/**
 * Plugin Name: DB Version Control
 * Description: Sync WordPress to version-controlled JSON files for easy Git workflows.
 * Version:     1.1.0
 * Author:      Robert DeVore
 * Author URI:  https://robertdevore.com
 * Text Domain: dbvc
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.txt
 * Update URI:  https://github.com/robertdevore/db-version-control/
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

require 'vendor/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/robertdevore/db-version-control/',
	__FILE__,
	'db-version-control'
);

// Set the branch that contains the stable release.
$myUpdateChecker->setBranch( 'main' );

// Check if Composer's autoloader is already registered globally.
if ( ! class_exists( 'RobertDevore\WPComCheck\WPComPluginHandler' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use RobertDevore\WPComCheck\WPComPluginHandler;

new WPComPluginHandler( plugin_basename( __FILE__ ), 'https://robertdevore.com/why-this-plugin-doesnt-support-wordpress-com-hosting/' );

// Define constants for the plugin.
define( 'DBVC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'DBVC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DBVC_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'DBVC_PLUGIN_VERSION', '1.1.0' );

require_once DBVC_PLUGIN_PATH . 'includes/functions.php';
// require_once DBVC_PLUGIN_PATH . 'includes/class-menu-importer.php'; // Added new menu importer/exporter class - removed later to avoid over-complicating the class-sync-posts.php
require_once DBVC_PLUGIN_PATH . 'includes/class-sync-posts.php';
require_once DBVC_PLUGIN_PATH . 'includes/hooks.php';
require_once DBVC_PLUGIN_PATH . 'commands/class-wp-cli-commands.php';
if ( is_admin() ) {
	require_once DBVC_PLUGIN_PATH . 'admin/admin-menu.php';
    require_once DBVC_PLUGIN_PATH . 'admin/admin-page.php';
}

// Hook into post save.
add_action( 'save_post', [ 'DBVC_Sync_Posts', 'export_post_to_json' ], 10, 2 );

/**
 * Load plugin text domain for translations.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_load_textdomain() {
	load_plugin_textdomain( 'dbvc', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
}
add_action( 'plugins_loaded', 'dbvc_load_textdomain' );

/**
 * Add settings link to plugin action links.
 * 
 * @param mixed $links
 * 
 * @since  1.0.0
 * @return array
 */
function dbvc_add_settings_link( $links ) {
	$settings_link = '<a href="' . admin_url( 'admin.php?page=dbvc-export' ) . '">' . esc_html__( 'Settings', 'dbvc' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'dbvc_add_settings_link' );
