<?php
/**
 * Get the sync path for exports
 * 
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com>
 * @since     1.0.0
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Register the admin menu for DB Version Control
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_register_admin_menu() {
	add_menu_page(
		esc_html__( 'DB Version Control', 'dbvc' ),
		esc_html__( 'DBVC Export', 'dbvc' ),
		'manage_options',
		'dbvc-export',
		'dbvc_render_export_page',
		'dashicons-download',
		80
	);
}
add_action( 'admin_menu', 'dbvc_register_admin_menu' );
