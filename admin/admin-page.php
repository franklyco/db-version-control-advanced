<?php

/**
 * Get the sync path for exports
 * 
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com>
 * @since     1.0.0
 * @return string
 */

// If this file is called directly, abort.
if (! defined('WPINC')) {
	die;
}

/**
 * Render the export settings page
 * 
 * @since  1.0.0
 * @return void
 */
function dbvc_render_export_page()
{
	// Check user capabilities
	if (! current_user_can('manage_options')) {
		wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'dbvc'));
	}

	$custom_path         = get_option('dbvc_sync_path', '');
	$selected_post_types = get_option('dbvc_post_types', []);

	// Handle custom sync path form.
	if (isset($_POST['dbvc_sync_path_save']) && wp_verify_nonce($_POST['dbvc_sync_path_nonce'], 'dbvc_sync_path_action')) {
		// Additional capability check
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
		}

		$new_path = sanitize_text_field(wp_unslash($_POST['dbvc_sync_path']));

		// Validate path to prevent directory traversal
		$new_path = dbvc_validate_sync_path($new_path);
		if (false === $new_path) {
			echo '<div class="notice notice-error"><p>' . esc_html__('Invalid sync path provided. Path cannot contain ../ or other unsafe characters.', 'dbvc') . '</p></div>';
		} else {
			update_option('dbvc_sync_path', $new_path);
			$custom_path = $new_path;

			// Create the directory immediately to test the path.
			$resolved_path = dbvc_get_sync_path();
			if (wp_mkdir_p($resolved_path)) {
				echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Sync folder updated and created at: %s', 'dbvc'), '<code>' . esc_html($resolved_path) . '</code>') . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . sprintf(esc_html__('Sync folder setting saved, but could not create directory at: %s. Please check permissions.', 'dbvc'), '<code>' . esc_html($resolved_path) . '</code>') . '</p></div>';
			}
		}
	}

	// Handle post types selection form.
	if (isset($_POST['dbvc_post_types_save']) && wp_verify_nonce($_POST['dbvc_post_types_nonce'], 'dbvc_post_types_action')) {
		// Additional capability check
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
		}

		$new_post_types = [];
		if (isset($_POST['dbvc_post_types']) && is_array($_POST['dbvc_post_types'])) {
			$new_post_types = array_map('sanitize_text_field', wp_unslash($_POST['dbvc_post_types']));

			// Get all valid post types (public + FSE types)
			$valid_post_types = get_post_types(['public' => true]);

			// Add FSE post types to valid list if block theme is active
			if (wp_is_block_theme()) {
				$fse_types = ['wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation'];
				$valid_post_types = array_merge($valid_post_types, array_combine($fse_types, $fse_types));
			}

			// Filter to only include valid post types
			$new_post_types = array_intersect($new_post_types, array_keys($valid_post_types));
		}

		update_option('dbvc_post_types', $new_post_types);
		$selected_post_types = $new_post_types;
		echo '<div class="notice notice-success"><p>' . esc_html__('Post types selection updated!', 'dbvc') . '</p></div>';
	}

	// Handle export form.
	// Handle export form.
	if (isset($_POST['dbvc_export_nonce']) && wp_verify_nonce($_POST['dbvc_export_nonce'], 'dbvc_export_action')) {
		// Additional capability check
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
		}

		// Save export options
		$use_slug      = ! empty($_POST['dbvc_use_slug_in_filenames']);
		$strip_domain  = ! empty($_POST['dbvc_strip_domain_urls']);

		update_option('dbvc_use_slug_in_filenames', $use_slug ? '1' : '0');
		update_option('dbvc_strip_domain_urls', $strip_domain ? '1' : '0');

		// Run full export
		DBVC_Sync_Posts::export_options_to_json();
		DBVC_Sync_Posts::export_menus_to_json();

		$posts = get_posts([
			'post_type'      => 'any',
			'posts_per_page' => -1,
			'post_status'    => 'any',
		]);

		foreach ($posts as $post) {
			DBVC_Sync_Posts::export_post_to_json($post->ID, $post, $use_slug);
		}

		// Create dated backup of export .json files
		if (method_exists('DBVC_Sync_Posts', 'dbvc_create_backup_folder_and_copy_exports')) {
			DBVC_Sync_Posts::dbvc_create_backup_folder_and_copy_exports();
		} else {
			error_log('[DBVC] Static method dbvc_create_backup_folder_and_copy_exports not found in DBVC_Sync_Posts.');
		}

		echo '<div class="notice notice-success"><p>' . esc_html__('Full export completed!', 'dbvc') . '</p></div>';
	}


	if (isset($_POST['dbvc_import_button']) && wp_verify_nonce($_POST['dbvc_import_nonce'], 'dbvc_import_action')) {
		if (current_user_can('manage_options')) {
			$smart_import  = ! empty($_POST['dbvc_smart_import']);
			$import_menus  = ! empty($_POST['dbvc_import_menus']);

			DBVC_Sync_Posts::import_all(0, $smart_import);

			if ($import_menus) {
				DBVC_Sync_Posts::import_menus_from_json();
			}

			echo '<div class="notice notice-success"><p>' . esc_html__('Import completed.', 'dbvc') . '</p></div>';
		} else {
			wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
		}
	}

	// Handle new post creation + mirror domain settings
	if (isset($_POST['dbvc_create_settings_save']) && wp_verify_nonce($_POST['dbvc_create_settings_nonce'], 'dbvc_create_settings_action')) {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
		}

		update_option('dbvc_allow_new_posts', ! empty($_POST['dbvc_allow_new_posts']) ? '1' : '0');
		update_option('dbvc_new_post_status', sanitize_text_field($_POST['dbvc_new_post_status'] ?? 'draft'));

		$whitelist = isset($_POST['dbvc_new_post_types_whitelist']) && is_array($_POST['dbvc_new_post_types_whitelist'])
			? array_map('sanitize_text_field', wp_unslash($_POST['dbvc_new_post_types_whitelist']))
			: [];
		update_option('dbvc_new_post_types_whitelist', $whitelist);

		update_option('dbvc_mirror_domain', sanitize_text_field($_POST['dbvc_mirror_domain'] ?? ''));

		echo '<div class="notice notice-success"><p>' . esc_html__('Import settings updated!', 'dbvc') . '</p></div>';
	}


	// Get the current resolved path for display.
	$resolved_path = dbvc_get_sync_path();

	// Get all public post types.
	$all_post_types = dbvc_get_available_post_types();

?>
	<div class="wrap">
		<h1><?php esc_html_e('DB Version Control', 'dbvc'); ?></h1>

		<div id="dbvc-tabs">
			<ul>
				<li><a href="#tab-import"><?php esc_html_e('Import/Upload', 'dbvc'); ?></a></li>
				<li><a href="#tab-export"><?php esc_html_e('Export/Download', 'dbvc'); ?></a></li>
				<li><a href="#tab-config"><?php esc_html_e('Configure', 'dbvc'); ?></a></li>
			</ul>

			<!-- Tab 1: Import / Upload -->
			<div id="tab-import">
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field('dbvc_import_action', 'dbvc_import_nonce'); ?>
					<h2><?php esc_html_e('Import from JSON', 'dbvc'); ?></h2>
					<p><?php esc_html_e('Import posts & CPTs from the sync folder. Optionally only new/changed content.', 'dbvc'); ?></p>
					<label><input type="checkbox" name="dbvc_smart_import" value="1" /> <?php esc_html_e('Only import new or modified posts', 'dbvc'); ?></label><br>
					<label><input type="checkbox" name="dbvc_import_menus" value="1" /> <?php esc_html_e('Also import menus', 'dbvc'); ?></label><br><br>
					<?php submit_button(esc_html__('Run Import', 'dbvc'), 'primary', 'dbvc_import_button'); ?>
				</form>

				<hr />

				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1em;">
					<?php wp_nonce_field('dbvc_upload_sync', 'dbvc_upload_sync_nonce'); ?>
					<input type="hidden" name="action" value="dbvc_upload_sync" />
					<h2><?php esc_html_e('Upload to Sync Folder', 'dbvc'); ?></h2>
					<p><?php esc_html_e('Upload a .zip of JSON files (or a single .json) to overwrite your sync folder.', 'dbvc'); ?></p>
					<input type="file" name="dbvc_sync_upload" accept=".zip,.json" required />
					<?php submit_button(__('Upload', 'dbvc'), 'secondary'); ?>
				</form>


				<?php if (isset($_GET['dbvc_upload'])) :
					$state = sanitize_key($_GET['dbvc_upload']);
					$class = ('success' === $state) ? 'updated' : 'error';
					$msg   = ('success' === $state)
						? __('Upload completed!', 'dbvc')
						: __('Upload failed – check file type & permissions.', 'dbvc');
				?>
					<div class="notice <?php echo esc_attr($class); ?>">
						<p><?php echo esc_html($msg); ?></p>
					</div>
				<?php endif; ?>
			</div>

			<!-- Tab 2: Export / Download -->
			<div id="tab-export">
				<form method="post">
					<?php wp_nonce_field('dbvc_export_action', 'dbvc_export_nonce'); ?>
					<h2><?php esc_html_e('Full Export', 'dbvc'); ?></h2>
					<p><?php esc_html_e('Export all posts, options, and menus to JSON files.', 'dbvc'); ?></p>
					<label><input type="checkbox" name="dbvc_use_slug_in_filenames" value="1" <?php checked(get_option('dbvc_use_slug_in_filenames'), '1'); ?> />
						<?php esc_html_e('Use slug instead of post ID in export filenames', 'dbvc'); ?></label><br>
					<label><input type="checkbox" name="dbvc_strip_domain_urls" value="1" <?php checked(get_option('dbvc_strip_domain_urls'), '1'); ?> />
						<?php esc_html_e('Strip domain from URLs during export', 'dbvc'); ?></label><br><br>
					<?php submit_button(esc_html__('Run Full Export', 'dbvc')); ?>
				</form>

				<hr />

				<h2><?php esc_html_e('Download Current Sync Folder', 'dbvc'); ?></h2>
				<p>
					<a href="<?php echo esc_url(
									wp_nonce_url(
										add_query_arg('action', 'dbvc_download_sync', admin_url('admin-post.php')),
										'dbvc_download_sync'
									)
								); ?>" class="button button-secondary">
						<?php esc_html_e('Download as ZIP', 'dbvc'); ?>
					</a>
				</p>
			</div>

			<!-- Tab 3: Configure -->
			<div id="tab-config">
				<form method="post">
					<?php wp_nonce_field('dbvc_post_types_action', 'dbvc_post_types_nonce'); ?>
					<h2><?php esc_html_e('Post Types to Export/Import', 'dbvc'); ?></h2>
					<p><?php esc_html_e('Select which post types should be included.', 'dbvc'); ?></p>
					<select name="dbvc_post_types[]" multiple style="width:100%;" id="dbvc-post-types-select">
						<?php foreach (dbvc_get_available_post_types() as $pt => $obj) : ?>
							<option value="<?php echo esc_attr($pt); ?>" <?php selected(in_array($pt, get_option('dbvc_post_types', []), true)); ?>>
								<?php echo esc_html($obj->label); ?> (<?php echo esc_html($pt); ?>)
							</option>
						<?php endforeach; ?>
					</select><br><br>
					<?php submit_button(esc_html__('Save Post Types', 'dbvc'), 'secondary', 'dbvc_post_types_save'); ?>
				</form>

				<hr />

				<form method="post">
					<?php wp_nonce_field('dbvc_sync_path_action', 'dbvc_sync_path_nonce'); ?>
					<h2><?php esc_html_e('Custom Sync Folder Path', 'dbvc'); ?></h2>
					<p><?php esc_html_e('Enter the path where JSON files should be saved.', 'dbvc'); ?></p>
					<input type="text" name="dbvc_sync_path" value="<?php echo esc_attr(get_option('dbvc_sync_path', '')); ?>" style="width:100%;" placeholder="<?php esc_attr_e('e.g., wp-content/plugins/db-version-control/sync/', 'dbvc'); ?>" /><br><br>
					<strong><?php esc_html_e('Resolved path:', 'dbvc'); ?></strong> <code><?php echo esc_html(dbvc_get_sync_path()); ?></code><br><br>
					<?php submit_button(esc_html__('Save Folder Path', 'dbvc'), 'secondary', 'dbvc_sync_path_save'); ?>
				</form>

				<form method="post">
					<?php wp_nonce_field('dbvc_create_settings_action', 'dbvc_create_settings_nonce'); ?>
					<h2><?php esc_html_e('Post Creation & Mirror Domain Settings', 'dbvc'); ?></h2>

					<p><label>
							<input type="checkbox" name="dbvc_allow_new_posts" value="1" <?php checked(get_option('dbvc_allow_new_posts'), '1'); ?> />
							<?php esc_html_e('Allow importing new posts that do not already exist on this site', 'dbvc'); ?>
						</label></p>

					<p>
						<label for="dbvc_new_post_status"><?php esc_html_e('Default status for new posts:', 'dbvc'); ?></label><br>
						<select name="dbvc_new_post_status" id="dbvc_new_post_status">
							<?php
							$status = get_option('dbvc_new_post_status', 'draft');
							foreach (['draft', 'publish', 'pending'] as $s) {
								echo '<option value="' . esc_attr($s) . '" ' . selected($status, $s, false) . '>' . esc_html($s) . '</option>';
							}
							?>
						</select>
					</p>

					<p>
						<label for="dbvc_new_post_types_whitelist"><?php esc_html_e('Restrict creation to selected post types (optional):', 'dbvc'); ?></label><br>
						<select name="dbvc_new_post_types_whitelist[]" multiple size="5" style="width:100%;">
							<?php
							$selected_types = (array) get_option('dbvc_new_post_types_whitelist', []);
							foreach (dbvc_get_available_post_types() as $pt => $obj) {
								echo '<option value="' . esc_attr($pt) . '" ' . selected(in_array($pt, $selected_types, true), true, false) . '>' . esc_html($obj->label) . ' (' . esc_html($pt) . ')</option>';
							}
							?>
						</select>
					</p>

					<p>
						<label for="dbvc_mirror_domain"><?php esc_html_e('Mirror domain (optional):', 'dbvc'); ?></label><br>
						<input type="text" name="dbvc_mirror_domain" id="dbvc_mirror_domain" value="<?php echo esc_attr(get_option('dbvc_mirror_domain', '')); ?>" style="width:100%;" placeholder="e.g., https://staging.example.com" />
						<small><?php esc_html_e('Any URLs containing this domain will be replaced with the current site domain during import.', 'dbvc'); ?></small>
					</p>

					<?php submit_button(__('Save Import Settings', 'dbvc'), 'secondary', 'dbvc_create_settings_save'); ?>
				</form>

			</div>
		</div><!-- #dbvc-tabs -->
	</div><!-- .wrap -->

	<script>
		jQuery(function($) {
			$('#dbvc-tabs').tabs();
			$('#dbvc-post-types-select').select2({
				placeholder: <?php echo wp_json_encode(esc_html__('Select post types…', 'dbvc')); ?>,
				allowClear: false
			});
		});
	</script>
<?php
}
/**
 * Get all available post types for the settings page.
 * 
 * @since  1.1.0
 * @return array
 */
function dbvc_get_available_post_types()
{
	$post_types = get_post_types(['public' => true], 'objects');

	// Add FSE post types if block theme is active
	if (wp_is_block_theme()) {
		$fse_types = [
			'wp_template' => (object) [
				'label' => __('Templates (FSE)', 'dbvc'),
				'name' => 'wp_template'
			],
			'wp_template_part' => (object) [
				'label' => __('Template Parts (FSE)', 'dbvc'),
				'name' => 'wp_template_part'
			],
			'wp_global_styles' => (object) [
				'label' => __('Global Styles (FSE)', 'dbvc'),
				'name' => 'wp_global_styles'
			],
			'wp_navigation' => (object) [
				'label' => __('Navigation (FSE)', 'dbvc'),
				'name' => 'wp_navigation'
			],
		];

		$post_types = array_merge($post_types, $fse_types);
	}

	return $post_types;
}
