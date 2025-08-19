<?php

/**
 * Action hooks for DB Version Control 
 * 
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com>
 * @since     1.0.0
 */

// If this file is called directly, abort.
if (! defined('WPINC')) {
	die;
}

/**
 * Handle post deletion by removing corresponding JSON files.
 * 
 * @param int $post_id The post ID being deleted.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_post_deletion($post_id)
{
	$post = get_post($post_id);
	if ($post && in_array($post->post_type, DBVC_Sync_Posts::get_supported_post_types(), true)) {
		$path = dbvc_get_sync_path($post->post_type);
		$file_path = $path . $post->post_type . '-' . $post_id . '.json';
		if (file_exists($file_path)) {
			unlink($file_path);
		}
	}

	// Allow other plugins to hook into post deletion
	do_action('dbvc_after_post_deletion', $post_id, $post);
}

/**
 * Handle post status transitions by re-exporting the post.
 * 
 * @param string  $new_status New post status.
 * @param string  $old_status Old post status.
 * @param WP_Post $post       Post object.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_post_status_transition($new_status, $old_status, $post)
{
	if ($new_status !== $old_status) {
		DBVC_Sync_Posts::export_post_to_json($post->ID, $post);
	}

	// Allow other plugins to hook into status transitions
	do_action('dbvc_after_post_status_transition', $new_status, $old_status, $post);
}

/**
 * Handle post meta updates by re-exporting the post.
 * 
 * @param int    $meta_id    Meta field ID.
 * @param int    $object_id  Post ID.
 * @param string $meta_key   Meta key.
 * @param mixed  $meta_value Meta value.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_post_meta_update($meta_id, $object_id, $meta_key, $meta_value)
{
	// Allow other plugins to skip certain meta keys
	$skip_meta_keys = apply_filters('dbvc_skip_meta_keys', ['_edit_lock', '_edit_last']);
	if (in_array($meta_key, $skip_meta_keys, true)) {
		return;
	}

	$post = get_post($object_id);
	if ($post && in_array($post->post_type, DBVC_Sync_Posts::get_supported_post_types(), true)) {
		DBVC_Sync_Posts::export_post_to_json($object_id, $post);
	}

	// Allow other plugins to hook into meta updates
	do_action('dbvc_after_post_meta_update', $meta_id, $object_id, $meta_key, $meta_value);
}

/**
 * Handle plugin activation/deactivation by exporting options.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_plugin_changes()
{
	DBVC_Sync_Posts::export_options_to_json();

	// Allow other plugins to export their own data on plugin changes.
	do_action('dbvc_after_plugin_changes');
}

/**
 * Handle theme changes by exporting options and menus.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_theme_changes()
{
	DBVC_Sync_Posts::export_options_to_json();
	DBVC_Sync_Posts::export_menus_to_json();

	// Allow other plugins to export their own data on theme changes.
	do_action('dbvc_after_theme_changes');
}

/**
 * Handle customizer saves by exporting options.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_customizer_save()
{
	DBVC_Sync_Posts::export_options_to_json();

	// Allow other plugins to export their own data on customizer saves.
	do_action('dbvc_after_customizer_save');
}

/**
 * Handle widget updates by exporting options.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_widget_updates()
{
	if (isset($_POST['savewidgets'])) {
		DBVC_Sync_Posts::export_options_to_json();

		// Allow other plugins to export their own data on widget updates.
		do_action('dbvc_after_widget_updates');
	}
}

/**
 * Handle user profile updates by exporting options.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_user_updates()
{
	DBVC_Sync_Posts::export_options_to_json();

	// Allow other plugins to export their own data on user updates.
	do_action('dbvc_after_user_updates');
}

/**
 * Handle comment changes by exporting options.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_comment_changes()
{
	DBVC_Sync_Posts::export_options_to_json();

	// Allow other plugins to export their own data on comment changes.
	do_action('dbvc_after_comment_changes');
}

/**
 * Handle taxonomy term changes by exporting options.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_term_changes()
{
	DBVC_Sync_Posts::export_options_to_json();

	// Allow other plugins to export their own data on term changes.
	do_action('dbvc_after_term_changes');
}

/**
 * Handle general option updates by exporting options.
 * 
 * @param string $option    Option name.
 * @param mixed  $old_value Old option value.
 * @param mixed  $new_value New option value.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_option_updates($option, $old_value, $new_value)
{
	// Allow other plugins to add their own options to skip
	$skip_options = apply_filters('dbvc_skip_option_names', ['dbvc_']);

	foreach ($skip_options as $skip_pattern) {
		if (strpos($option, $skip_pattern) === 0) {
			return;
		}
	}

	// Allow other plugins to decide if this option should trigger an export.
	$should_export = apply_filters('dbvc_should_export_on_option_update', true, $option, $old_value, $new_value);
	if (! $should_export) {
		return;
	}

	DBVC_Sync_Posts::export_options_to_json();

	// Allow other plugins to export their own data on option updates.
	do_action('dbvc_after_option_update', $option, $old_value, $new_value);
}

/**
 * Handle navigation menu updates by exporting menus.
 * 
 * @param int   $menu_id Menu ID.
 * @param array $data    Menu data.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_menu_updates($menu_id, $data = [])
{
	DBVC_Sync_Posts::export_menus_to_json();

	// Allow other plugins to export their own data on menu updates.
	do_action('dbvc_after_menu_updates', $menu_id, $data);
}

/**
 * Handle menu item saves by exporting menus.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_menu_item_save()
{
	DBVC_Sync_Posts::export_menus_to_json();

	// Allow other plugins to export their own data on menu item saves.
	do_action('dbvc_after_menu_item_save');
}

/**
 * Handle menu item deletion by exporting menus.
 * 
 * @param int $post_id Post ID.
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_handle_menu_item_deletion($post_id)
{
	if (get_post_type($post_id) === 'nav_menu_item') {
		DBVC_Sync_Posts::export_menus_to_json();

		// Allow other plugins to export their own data on menu item deletion
		do_action('dbvc_after_menu_item_deletion', $post_id);
	}
}

/**
 * Handle FSE template and theme changes.
 * 
 * @since  1.1.0
 * @return void
 */
function dbvc_handle_fse_changes()
{
	// Only run if WordPress is fully loaded and we're not in admin
	if (! did_action('wp_loaded') || (is_admin() && ! wp_doing_ajax())) {
		return;
	}

	DBVC_Sync_Posts::export_fse_theme_data();
	do_action('dbvc_after_fse_changes');
}

// Handles processing zip/delete all in Admin tab 3
// Purge (delete or download-then-delete)
add_action('admin_post_dbvc_purge_sync', 'dbvc_handle_purge_sync');

function dbvc_handle_purge_sync()
{
	if (! current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions.', 'dbvc'));
	check_admin_referer('dbvc_purge_sync_action', 'dbvc_purge_sync_nonce');

	$confirmation   = isset($_POST['dbvc_purge_confirm']) ? trim(wp_unslash($_POST['dbvc_purge_confirm'])) : '';
	$do_delete      = ! empty($_POST['dbvc_purge_delete']);
	$do_dl_then_del = ! empty($_POST['dbvc_purge_download_then_delete']);
	$delete_root    = ! empty($_POST['dbvc_purge_delete_root']); // <-- new

	if ($confirmation !== 'DELETE') {
		dbvc_admin_notice_redirect('confirm_fail');
	}

	if (! $do_delete && ! $do_dl_then_del) {
		dbvc_admin_notice_redirect('no_action');
	}

	$sync_dir = dbvc_get_sync_path();

	if ($do_dl_then_del) {
		dbvc_download_then_delete_stream($sync_dir, $delete_root); // <-- pass it through
	}

	// Plain delete
	list($deleted, $failed, $deleted_root) = dbvc_delete_sync_contents($sync_dir, $delete_root);
	dbvc_admin_notice_redirect('purge_ok', [
		'deleted'      => (int) $deleted,
		'failed'       => (int) $failed,
		'deleted_root' => $deleted_root ? 1 : 0,
	]);
}

/**
 * Stream a zip of the sync folder, then delete contents after the response finishes.
 */
function dbvc_download_then_delete_stream($sync_dir, $delete_root = false)
{
	if (! class_exists('ZipArchive')) wp_die(esc_html__('ZipArchive not available.', 'dbvc'));
	if (! is_dir($sync_dir)) wp_die(esc_html__('Sync directory does not exist.', 'dbvc'));

	$zip = dbvc_build_sync_zip($sync_dir);
	if (is_wp_error($zip)) wp_die(esc_html($zip->get_error_message()));

	// Ensure clean output state before headers
	nocache_headers();
	if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
	@ini_set('zlib.output_compression', 'Off');
	while (ob_get_level()) {
		@ob_end_clean();
	}

	// After the response finishes, delete + set transient exactly once
	register_shutdown_function(function () use ($sync_dir, $delete_root) {
		list($deleted, $failed, $deleted_root_flag) = dbvc_delete_sync_contents($sync_dir, $delete_root);

		set_transient(
			'dbvc_last_purge',
			[
				'deleted'      => (int) $deleted,
				'failed'       => (int) $failed,
				'deleted_root' => $deleted_root_flag ? 1 : 0,
				'ts'           => time(),
			],
			5 * MINUTE_IN_SECONDS
		);
	});

	$filename = 'dbvc-sync-' . date('Ymd-His') . '.zip';
	clearstatcache(true, $zip);
	$size = @filesize($zip);

	header('Content-Type: application/zip');
	header('Content-Disposition: attachment; filename="' . $filename . '"');
	if ($size !== false) {
		header('Content-Length: ' . $size);
	}
	header('Pragma: public');
	header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
	header('Expires: 0');

	$fp = fopen($zip, 'rb');
	fpassthru($fp);
	fclose($fp);
	@unlink($zip);
	exit;
}


/**
 * Redirect back to your settings page with a notice query string.
 */
function dbvc_admin_notice_redirect($code, array $extra = [])
{
	// 1) Prefer explicit return URL from form
	$target = isset($_POST['dbvc_return']) ? esc_url_raw($_POST['dbvc_return']) : '';

	// 2) Otherwise, use the referrer
	if (! $target) {
		$ref = wp_get_referer();
		if ($ref) {
			$target = $ref;
		}
	}

	// 3) Final fallback: your menu slug (CHANGE THIS if your slug differs)
	if (! $target) {
		$target = menu_page_url('db-version-control', false);
		if (! $target) {
			$target = admin_url('admin.php?page=db-version-control');
		}
	}

	// Clean previous notice args and add the new ones
	$remove = ['dbvc_notice', 'deleted', 'failed', 'deleted_root', '_wpnonce', 'action'];
	$target = remove_query_arg($remove, $target);
	$target = add_query_arg(array_merge(['dbvc_notice' => $code], $extra), $target);

	wp_safe_redirect($target);
	exit;
}

add_action('wp_ajax_dbvc_purge_status', function () {
	if (! current_user_can('manage_options')) {
		wp_send_json_error(['message' => 'forbidden'], 403);
	}

	$data = get_transient('dbvc_last_purge');
	if ($data) {
		// Clear it so the notice is one-shot
		delete_transient('dbvc_last_purge');
		wp_send_json_success($data);
	}

	wp_send_json_success(null); // not ready yet
});



/**
 * Handle block pattern changes.
 * 
 * @since  1.1.0
 * @return void
 */
function dbvc_handle_pattern_changes()
{
	// Patterns are typically stored in theme files, but custom ones in options
	DBVC_Sync_Posts::export_options_to_json();
	do_action('dbvc_after_pattern_changes');
}

// Register all action hooks.
add_action('before_delete_post', 'dbvc_handle_post_deletion', 10, 1);
add_action('transition_post_status', 'dbvc_handle_post_status_transition', 10, 3);
add_action('updated_post_meta', 'dbvc_handle_post_meta_update', 10, 4);
add_action('added_post_meta', 'dbvc_handle_post_meta_update', 10, 4);
add_action('deleted_post_meta', 'dbvc_handle_post_meta_update', 10, 4);
add_action('activated_plugin', 'dbvc_handle_plugin_changes', 10, 0);
add_action('deactivated_plugin', 'dbvc_handle_plugin_changes', 10, 0);
add_action('switch_theme', 'dbvc_handle_theme_changes', 10, 0);
add_action('customize_save_after', 'dbvc_handle_customizer_save', 10, 0);
add_action('sidebar_admin_page', 'dbvc_handle_widget_updates', 10, 0);
add_action('profile_update', 'dbvc_handle_user_updates', 10, 0);
add_action('comment_post', 'dbvc_handle_comment_changes', 10, 0);
add_action('edit_comment', 'dbvc_handle_comment_changes', 10, 0);
add_action('created_term', 'dbvc_handle_term_changes', 10, 0);
add_action('edited_term', 'dbvc_handle_term_changes', 10, 0);
add_action('delete_term', 'dbvc_handle_term_changes', 10, 0);
add_action('update_option_dbvc_sync_path', 'dbvc_handle_plugin_changes', 10, 0);
add_action('update_option_dbvc_post_types', 'dbvc_handle_plugin_changes', 10, 0);
add_action('updated_option', 'dbvc_handle_option_updates', 10, 3);
add_action('wp_update_nav_menu', 'dbvc_handle_menu_updates', 10, 2);
add_action('save_post_nav_menu_item', 'dbvc_handle_menu_item_save', 10, 1);
add_action('delete_post', 'dbvc_handle_menu_item_deletion', 10, 1);
// Register sync-folder download/upload
add_action('admin_post_dbvc_download_sync', ['DBVC_Sync_Posts', 'handle_download_sync']);
add_action('admin_post_dbvc_upload_sync',   ['DBVC_Sync_Posts', 'handle_upload_sync']);

// FSE hooks - use safer, later hooks that don't interfere with admin loading
add_action('wp_loaded', function () {
	// Only add FSE hooks after WordPress is fully loaded
	if (wp_is_block_theme()) {
		add_action('save_post_wp_template', 'dbvc_handle_fse_changes', 10, 0);
		add_action('save_post_wp_template_part', 'dbvc_handle_fse_changes', 10, 0);
		add_action('save_post_wp_global_styles', 'dbvc_handle_fse_changes', 10, 0);
		add_action('save_post_wp_navigation', 'dbvc_handle_fse_changes', 10, 0);

		// Hook into theme switching, but only on frontend or during WP-CLI
		if (! is_admin() || defined('WP_CLI')) {
			add_action('switch_theme', 'dbvc_handle_fse_changes', 10, 0);
		}
	}
}, 20);
