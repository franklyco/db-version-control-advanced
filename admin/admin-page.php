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
    // Capability check
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
    }

    // --- Gather POSTed settings first (so this run uses the user's current choices) ---
    $use_slug       = ! empty($_POST['dbvc_use_slug_in_filenames']);
    $strip_checked  = ! empty($_POST['dbvc_strip_domain_urls']);          // user's intent for strip
    $mirror_checked = ! empty($_POST['dbvc_export_use_mirror_domain']);   // user's intent for mirror

    // Persist slug & mirror checkbox (mirror domain string is saved on Tab 3's save handler)
    update_option('dbvc_use_slug_in_filenames',    $use_slug ? '1' : '0');
    update_option('dbvc_export_use_mirror_domain', $mirror_checked ? '1' : '0');

    // Persist masking settings edited in Tab #2
    update_option('dbvc_mask_meta_keys',   sanitize_textarea_field($_POST['dbvc_mask_meta_keys']   ?? ''));
    update_option('dbvc_mask_subkeys',     sanitize_textarea_field($_POST['dbvc_mask_subkeys']     ?? ''));
    $action = in_array(($_POST['dbvc_mask_action'] ?? 'remove'), ['remove','redact'], true) ? $_POST['dbvc_mask_action'] : 'remove';
    update_option('dbvc_mask_action',      $action);
    update_option('dbvc_mask_placeholder', sanitize_text_field($_POST['dbvc_mask_placeholder'] ?? '***'));

    // --- Mirror vs Strip coordination for THIS run ---
    $mirror_raw  = trim((string) get_option('dbvc_mirror_domain', ''));
    $site_home   = untrailingslashit(home_url());
    $mirror_home = untrailingslashit($mirror_raw);

    $mirror_cb         = null;   // to remove later
    $restore_strip_opt = null;   // to restore user's strip choice after run
    $mirror_valid      = $mirror_checked && $mirror_home && filter_var($mirror_home, FILTER_VALIDATE_URL);

    if ($mirror_valid) {
        // If *both* mirror & strip are checked, force strip OFF during this run (so replacements can occur),
        // then restore the user's "strip on" choice afterward.
        if ($strip_checked) {
            $restore_strip_opt = '1';                 // remember to restore ON
            update_option('dbvc_strip_domain_urls', '0'); // OFF for this run
        } else {
            // user left strip unchecked: leave it OFF
            update_option('dbvc_strip_domain_urls', '0');
        }

        // Attach temporary mirror replacement filter (priority 9: before default 10s)
        $mirror_cb = function(array $data, $post_id, $post) use ($site_home, $mirror_home) {
            if (isset($data['post_content']) && is_string($data['post_content'])) {
                $data['post_content'] = str_replace($site_home, $mirror_home, $data['post_content']);
            }
            if (isset($data['post_excerpt']) && is_string($data['post_excerpt'])) {
                $data['post_excerpt'] = str_replace($site_home, $mirror_home, $data['post_excerpt']);
            }
            if (isset($data['meta'])) {
                // Requires dbvc_recursive_str_replace() to be defined in your plugin.
                $data['meta'] = dbvc_recursive_str_replace($site_home, $mirror_home, $data['meta']);
            }
            return $data;
        };
        add_filter('dbvc_export_post_data', $mirror_cb, 9, 3);

    } else {
        // No mirror: just persist user's strip choice now.
        update_option('dbvc_strip_domain_urls', $strip_checked ? '1' : '0');
    }

    // --- Make statuses + caps predictable during this run (with cleanup handles) ---
    $status_cb = function ($statuses, $post) {
        if ($post && $post->post_type === 'bricks_template') {
            return ['publish','private','draft','pending','future','inherit','auto-draft'];
        }
        return $statuses;
    };
    add_filter('dbvc_allowed_statuses_for_export', $status_cb, 10, 2);

    // Optionally bypass read-cap checks during admin-triggered full export.
    add_filter('dbvc_skip_read_cap_check', '__return_true', 10, 3);

    // --- Exports ---
    DBVC_Sync_Posts::export_options_to_json();
    DBVC_Sync_Posts::export_menus_to_json();

    // Use saved selection or fall back
    $selected = (array) get_option('dbvc_post_types', []);
    if (empty($selected)) {
        $selected = method_exists('DBVC_Sync_Posts', 'get_supported_post_types')
            ? DBVC_Sync_Posts::get_supported_post_types()
            : array_keys(dbvc_get_available_post_types());
    }
    // Ensure bricks_template included if supported
    if (! in_array('bricks_template', $selected, true)) {
        $selected[] = 'bricks_template';
    }

    // Broad status list; filterable
    $statuses = apply_filters('dbvc_export_all_post_statuses', [
        'publish','private','draft','pending','future','inherit','auto-draft'
    ]);

    // Batch export by type
    foreach ($selected as $pt) {
        $paged = 1;
        do {
            $q = new WP_Query([
                'post_type'        => $pt,
                'post_status'      => $statuses,
                'posts_per_page'   => 500,
                'paged'            => $paged,
                'fields'           => 'ids',
                'orderby'          => 'ID',
                'order'            => 'ASC',
                'suppress_filters' => false,
                'no_found_rows'    => true,
            ]);

            if (! empty($q->posts)) {
                foreach ($q->posts as $post_id) {
                    $post = get_post($post_id);
                    if ($post) {
                        DBVC_Sync_Posts::export_post_to_json($post_id, $post, $use_slug);
                    }
                }
            }
            $paged++;
        } while (! empty($q->posts));
    }

    // Backup export .json files (if available)
    if (method_exists('DBVC_Sync_Posts', 'dbvc_create_backup_folder_and_copy_exports')) {
        DBVC_Sync_Posts::dbvc_create_backup_folder_and_copy_exports();
    } else {
        error_log('[DBVC] Static method dbvc_create_backup_folder_and_copy_exports not found in DBVC_Sync_Posts.');
    }

    echo '<div class="notice notice-success"><p>' . esc_html__('Full export completed!', 'dbvc') . '</p></div>';

    // --- Cleanup (mirror filter + strip restoration; temp filters) ---
    if ($mirror_cb) {
        remove_filter('dbvc_export_post_data', $mirror_cb, 9);
    }
    if ($restore_strip_opt !== null) {
        update_option('dbvc_strip_domain_urls', $restore_strip_opt);
    }

    remove_filter('dbvc_allowed_statuses_for_export', $status_cb, 10);
    remove_filter('dbvc_skip_read_cap_check', '__return_true', 10);
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

    // Save mirror domain
    $mirror = sanitize_text_field( wp_unslash($_POST['dbvc_mirror_domain'] ?? '') );
    update_option('dbvc_mirror_domain', $mirror);

    // ✅ Save the new checkbox here (NOT in the form markup)
    update_option('dbvc_export_use_mirror_domain', ! empty($_POST['dbvc_export_use_mirror_domain']) ? '1' : '0');

    echo '<div class="notice notice-success"><p>' . esc_html__('Import settings updated!', 'dbvc') . '</p></div>';
}	

	// --- Notices ---
	if (isset($_GET['dbvc_notice'])) {
		$code = sanitize_key($_GET['dbvc_notice']);

		if ($code === 'purge_ok') {
			$deleted = isset($_GET['deleted']) ? max(0, intval($_GET['deleted'])) : 0;
			$failed  = isset($_GET['failed'])  ? max(0, intval($_GET['failed']))  : 0;
			$root    = ! empty($_GET['deleted_root']);

			// Optional suffix if the root sync folder itself was removed
			$suffix = $root ? ' ' . esc_html__('(Sync folder removed)', 'dbvc') : '';

			echo '<div class="notice notice-success"><p>' .
				sprintf(
					/* translators: 1: deleted count, 2: failed count, 3: optional suffix e.g. "(Sync folder removed)" */
					esc_html__('Purge completed. Deleted: %1$d, Failed: %2$d.%3$s', 'dbvc'),
					$deleted,
					$failed,
					$suffix
				) .
				'</p></div>';
		} elseif ($code === 'confirm_fail') {
			echo '<div class="notice notice-error"><p>' .
				esc_html__('Type DELETE exactly to confirm.', 'dbvc') .
				'</p></div>';
		} elseif ($code === 'no_action') {
			echo '<div class="notice notice-error"><p>' .
				esc_html__('Select an action: Delete or Download then Delete.', 'dbvc') .
				'</p></div>';
		}
	}

    // Handle Tab #2 "Save Masking Settings" (no export)
if (isset($_POST['dbvc_mask_settings_save']) && wp_verify_nonce($_POST['dbvc_mask_settings_nonce'], 'dbvc_mask_settings_action')) {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
    }

    // Persist only the masking options (avoid touching unrelated settings)
    update_option('dbvc_mask_meta_keys',      sanitize_textarea_field($_POST['dbvc_mask_meta_keys'] ?? ''));
    update_option('dbvc_mask_subkeys',        sanitize_textarea_field($_POST['dbvc_mask_subkeys'] ?? ''));
    $action = in_array(($_POST['dbvc_mask_action'] ?? 'remove'), ['remove','redact'], true) ? $_POST['dbvc_mask_action'] : 'remove';
    update_option('dbvc_mask_action',         $action);
    update_option('dbvc_mask_placeholder',    sanitize_text_field($_POST['dbvc_mask_placeholder'] ?? '***'));

    echo '<div class="notice notice-success"><p>' . esc_html__('Masking settings saved.', 'dbvc') . '</p></div>';
}

	// Handle purge/delete controls (Tab 3).
	if (isset($_POST['dbvc_purge_sync_submit']) && wp_verify_nonce($_POST['dbvc_purge_sync_nonce'], 'dbvc_purge_sync_action')) {
		if (! current_user_can('manage_options')) {
			wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
		}

		$confirmation = isset($_POST['dbvc_purge_confirm']) ? trim(wp_unslash($_POST['dbvc_purge_confirm'])) : '';
		$do_delete    = ! empty($_POST['dbvc_purge_delete']);
		$do_dl_delete = ! empty($_POST['dbvc_purge_download_then_delete']);

		if ($confirmation !== 'DELETE') {
			echo '<div class="notice notice-error"><p>' . esc_html__('Confirmation failed. Type DELETE exactly to proceed.', 'dbvc') . '</p></div>';
		} elseif (! $do_delete && ! $do_dl_delete) {
			echo '<div class="notice notice-error"><p>' . esc_html__('Please select an action: Delete all files or Download then delete.', 'dbvc') . '</p></div>';
		} else {
			$sync_dir = dbvc_get_sync_path();

			if ($do_dl_delete) {
				// Redirect to combined download+delete handler so the download happens first, then deletion.
				$dl_url = wp_nonce_url(
					add_query_arg('action', 'dbvc_download_then_delete', admin_url('admin-post.php')),
					'dbvc_purge_sync_action',
					'dbvc_purge_sync_nonce'
				);
				wp_safe_redirect($dl_url);
				exit;
			}

			// Plain delete (no download)
			list($deleted, $failed) = dbvc_delete_sync_contents($sync_dir);
			$msg = sprintf(
				/* translators: 1: deleted count, 2: failed count */
				esc_html__('Purge completed. Deleted: %1$d, Failed: %2$d.', 'dbvc'),
				(int) $deleted,
				(int) $failed
			);
			echo '<div class="notice notice-success"><p>' . $msg . '</p></div>';
		}
	}

	if (isset($_POST['dbvc_bricks_export_test_button'])
    && wp_verify_nonce($_POST['dbvc_bricks_export_test_nonce'], 'dbvc_bricks_export_test_action')) {

    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
    }

    // Scope temporary filters just for this run.
    $remove_filters = [];

    if (!empty($_POST['dbvc_force_caps'])) {
        add_filter('dbvc_skip_read_cap_check', '__return_true', 10, 3);
        $remove_filters[] = ['dbvc_skip_read_cap_check', '__return_true'];
    }

    if (!empty($_POST['dbvc_broaden_status'])) {
        add_filter('dbvc_allowed_statuses_for_export', function($statuses, $post){
            if ($post && $post->post_type === 'bricks_template') {
                return ['publish','private','draft','pending','future','inherit','auto-draft'];
            }
            return $statuses;
        }, 10, 2);
        $remove_filters[] = ['dbvc_allowed_statuses_for_export'];
    }

    $ids = get_posts([
        'post_type'        => 'bricks_template',
        'post_status'      => 'any',
        'fields'           => 'ids',
        'nopaging'         => true,
        'suppress_filters' => false,
    ]);

    $rows = [];
    foreach ($ids as $id) {
        $p = get_post($id);
        if (! $p) { continue; }

        // Compute the exact file path the exporter will use (matches your exporter)
        $path = function_exists('dbvc_get_sync_path') ? dbvc_get_sync_path($p->post_type) : WP_CONTENT_DIR . '/dbvc-sync/' . $p->post_type . '/';
        $use_slug = (get_option('dbvc_use_slug_in_filenames') === '1');
        $slug = sanitize_title($p->post_name);
        $filename_part = ($use_slug && !empty($slug) && !is_numeric($slug)) ? $slug : $p->ID;
        $file_path = trailingslashit($path) . sanitize_file_name($p->post_type . '-' . $filename_part . '.json');
        $file_path = apply_filters('dbvc_export_post_file_path', $file_path, $p->ID, $p);

        $pre_exists = file_exists($file_path);

        // Run the actual exporter once for each
        DBVC_Sync_Posts::export_post_to_json($p->ID, $p, $use_slug);

        $result = [
            'ID'            => $p->ID,
            'Title'         => get_the_title($p->ID),
            'Status'        => $p->post_status,
            'Path'          => $file_path,
            'Dir Exists?'   => is_dir(dirname($file_path)) ? 'yes' : 'no',
            'Writable?'     => is_dir(dirname($file_path)) && is_writable(dirname($file_path)) ? 'yes' : 'no',
            'Pre-Exists?'   => $pre_exists ? 'yes' : 'no',
            'Post-Exists?'  => file_exists($file_path) ? 'yes' : 'no',
            'Bytes'         => file_exists($file_path) ? filesize($file_path) : 0,
        ];

        $rows[] = $result;
        error_log('[DBVC DryRun] ' . wp_json_encode($result));
    }

    // Remove temp filters
    foreach ($remove_filters as $f) {
        remove_filter($f[0], $f[1]);
    }

    // Output a simple table
    echo '<div class="notice notice-info" style="padding:12px 12px 0;"><p><strong>Bricks Export Dry-Run Results</strong></p>';
    echo '<table class="widefat striped"><thead><tr>';
    $headers = ['ID','Title','Status','Path','Dir Exists?','Writable?','Pre-Exists?','Post-Exists?','Bytes'];
    foreach ($headers as $h) echo '<th>' . esc_html($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($headers as $h) {
            $v = isset($r[$h]) ? $r[$h] : '';
            echo '<td style="vertical-align:top;">' . (in_array($h,['ID','Path']) ? '<code>' . esc_html($v) . '</code>' : esc_html($v)) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
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

  <label>
    <input type="checkbox" name="dbvc_strip_domain_urls" value="1" <?php checked(get_option('dbvc_strip_domain_urls'), '1'); ?> />
    <?php esc_html_e('Strip domain from URLs during export', 'dbvc'); ?></label><br>

  <!-- ✅ New duplicate control -->
  <label>
    <input type="checkbox" name="dbvc_export_use_mirror_domain" value="1"
      <?php checked(get_option('dbvc_export_use_mirror_domain'), '1'); ?> />
    <?php esc_html_e('Replace current site domain with the Mirror Domain during export', 'dbvc'); ?>
  </label>
  <?php
  $mirror = trim((string) get_option('dbvc_mirror_domain', ''));
  if ($mirror) {
    echo '<br><small>' . sprintf(
      esc_html__('Current mirror domain: %s', 'dbvc'),
      '<code>' . esc_html($mirror) . '</code>'
    ) . '</small>';
  }
  ?>
<br>
<br>

<!-- Masking UI (duplicate of Tab #3) -->
<h3 style="margin-top:0;"><?php esc_html_e('Export Masking (Post Meta)', 'dbvc'); ?></h3>

<p>
  <label for="dbvc_mask_meta_keys"><strong><?php esc_html_e('Exclude whole postmeta keys', 'dbvc'); ?></strong></label><br>
  <textarea name="dbvc_mask_meta_keys" id="dbvc_mask_meta_keys" rows="3" style="width:100%;"
    placeholder="<?php echo esc_attr('_wp_old_date, _dbvc_import_hash, dbvc_post_history, _some_private_* , /_secret_.+/'); ?>"><?php
      echo esc_textarea(get_option('dbvc_mask_meta_keys', ''));
  ?></textarea><br>
  <small><?php esc_html_e('Comma or newline separated. Supports wildcards (*, ?) and regex wrapped with /.../.', 'dbvc'); ?></small>
</p>

<p>
  <label for="dbvc_mask_subkeys"><strong><?php esc_html_e('Mask/remove nested sub-keys (by dot-path or leaf key)', 'dbvc'); ?></strong></label><br>
  <textarea name="dbvc_mask_subkeys" id="dbvc_mask_subkeys" rows="4" style="width:100%;"
    placeholder="<?php echo esc_attr(implode('\n', [
      '_bricks_page_content_2.*.settings.signature',
      '_bricks_page_content_2.*.settings.time',
      'signature',
      '/^.*\\.extrasCustomQueryCode$/'
    ])); ?>"><?php
      echo esc_textarea(get_option('dbvc_mask_subkeys', ''));
  ?></textarea><br>
  <small><?php esc_html_e('One per line or comma separated. Match full dot-path (e.g. metaKey.*.path.leaf) or just a leaf key name. Wildcards and /regex/ supported.', 'dbvc'); ?></small>
</p>

<p>
  <label><strong><?php esc_html_e('Mask action', 'dbvc'); ?></strong></label><br>
  <?php $mask_action = get_option('dbvc_mask_action', 'remove'); ?>
  <label><input type="radio" name="dbvc_mask_action" value="remove" <?php checked($mask_action, 'remove'); ?> />
    <?php esc_html_e('Remove matched items from export', 'dbvc'); ?></label><br>
  <label><input type="radio" name="dbvc_mask_action" value="redact" <?php checked($mask_action, 'redact'); ?> />
    <?php esc_html_e('Redact matched items with a placeholder', 'dbvc'); ?></label>
</p>

<p id="dbvc-mask-placeholder-wrap-2" style="margin-left:1.5em;">
  <label for="dbvc_mask_placeholder"><?php esc_html_e('Redaction placeholder', 'dbvc'); ?></label><br>
  <input type="text" name="dbvc_mask_placeholder" id="dbvc_mask_placeholder"
    value="<?php echo esc_attr(get_option('dbvc_mask_placeholder', '***')); ?>" style="width:240px;" />
</p>

<script>
  (function(){
    const remove = document.querySelector('#tab-export input[name="dbvc_mask_action"][value="remove"]');
    const redact = document.querySelector('#tab-export input[name="dbvc_mask_action"][value="redact"]');
    const wrap   = document.getElementById('dbvc-mask-placeholder-wrap-2');
    function sync(){ if (redact && redact.checked) wrap.style.display = ''; else wrap.style.display = 'none'; }
    if (remove && redact && wrap) {
      remove.addEventListener('change', sync);
      redact.addEventListener('change', sync);
      sync();
    }
  })();
</script>

  <br>

  <?php submit_button(esc_html__('Run Full Export', 'dbvc')); ?>
</form>

<!-- Save-only form for masking settings (does not trigger export) -->
<form method="post" style="margin-top:1.5rem;">
  <?php wp_nonce_field('dbvc_mask_settings_action', 'dbvc_mask_settings_nonce'); ?>
  <h3 style="margin-top:0;"><?php esc_html_e('Export Masking (Post Meta)', 'dbvc'); ?></h3>

  <p>
    <label for="dbvc_mask_meta_keys"><strong><?php esc_html_e('Exclude whole postmeta keys', 'dbvc'); ?></strong></label><br>
    <textarea name="dbvc_mask_meta_keys" id="dbvc_mask_meta_keys" rows="3" style="width:100%;"
      placeholder="<?php echo esc_attr('_wp_old_date, _dbvc_import_hash, dbvc_post_history, _some_private_* , /_secret_.+/'); ?>"><?php
        echo esc_textarea(get_option('dbvc_mask_meta_keys', ''));
    ?></textarea><br>
    <small><?php esc_html_e('Comma or newline separated. Supports wildcards (*, ?) and regex wrapped with /.../.', 'dbvc'); ?></small>
  </p>

  <p>
    <label for="dbvc_mask_subkeys"><strong><?php esc_html_e('Mask/remove nested sub-keys (by dot-path or leaf key)', 'dbvc'); ?></strong></label><br>
    <textarea name="dbvc_mask_subkeys" id="dbvc_mask_subkeys" rows="4" style="width:100%;"
      placeholder="<?php echo esc_attr(implode('\n', [
        '_bricks_page_content_2.*.settings.signature',
        '_bricks_page_content_2.*.settings.time',
        'signature',
        '/^.*\\.extrasCustomQueryCode$/'
      ])); ?>"><?php
        echo esc_textarea(get_option('dbvc_mask_subkeys', ''));
    ?></textarea><br>
    <small><?php esc_html_e('One per line or comma separated. Match full dot-path (e.g. metaKey.*.path.leaf) or just a leaf key name. Wildcards and /regex/ supported.', 'dbvc'); ?></small>
  </p>

  <p>
    <label><strong><?php esc_html_e('Mask action', 'dbvc'); ?></strong></label><br>
    <?php $mask_action = get_option('dbvc_mask_action', 'remove'); ?>
    <label><input type="radio" name="dbvc_mask_action" value="remove" <?php checked($mask_action, 'remove'); ?> />
      <?php esc_html_e('Remove matched items from export', 'dbvc'); ?></label><br>
    <label><input type="radio" name="dbvc_mask_action" value="redact" <?php checked($mask_action, 'redact'); ?> />
      <?php esc_html_e('Redact matched items with a placeholder', 'dbvc'); ?></label>
  </p>

  <p id="dbvc-mask-placeholder-wrap-2b" style="margin-left:1.5em;">
    <label for="dbvc_mask_placeholder_2"><?php esc_html_e('Redaction placeholder', 'dbvc'); ?></label><br>
    <input type="text" name="dbvc_mask_placeholder" id="dbvc_mask_placeholder_2"
      value="<?php echo esc_attr(get_option('dbvc_mask_placeholder', '***')); ?>" style="width:240px;" />
  </p>

  <?php submit_button(__('Save Masking Settings', 'dbvc'), 'secondary', 'dbvc_mask_settings_save'); ?>
</form>

<script>
  // Show/hide placeholder based on selected action (Tab #2 variant)
  (function(){
    const root   = document.getElementById('tab-export');
    if (!root) return;
    const remove = root.querySelector('input[name="dbvc_mask_action"][value="remove"]');
    const redact = root.querySelector('input[name="dbvc_mask_action"][value="redact"]');
    const wrap   = document.getElementById('dbvc-mask-placeholder-wrap-2b');
    function sync(){ if (redact && redact.checked) wrap.style.display = ''; else wrap.style.display = 'none'; }
    if (remove && redact && wrap) { remove.addEventListener('change', sync); redact.addEventListener('change', sync); sync(); }
  })();
</script>

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

<hr />
<?php
// 1) Add a "Temp Bricks Check" form in the #tab-config section if you haven't already:
// (Place this where your other config forms live)
?>

<form method="post">
  <?php wp_nonce_field('dbvc_bricks_check_action', 'dbvc_bricks_check_nonce'); ?>
  <h2><?php esc_html_e('Temp Bricks Check', 'dbvc'); ?></h2>
  <p><?php esc_html_e('Diagnose which Bricks Templates will export and why (status, caps, filters, path).', 'dbvc'); ?></p>
  <?php submit_button(__('Run Bricks Check', 'dbvc'), 'secondary', 'dbvc_bricks_check_button'); ?>
</form>

<?php
// 2) Handler: paste this near your other POST handlers inside dbvc_render_export_page()
if (isset($_POST['dbvc_bricks_check_button']) && wp_verify_nonce($_POST['dbvc_bricks_check_nonce'], 'dbvc_bricks_check_action')) {
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
    }

    // Collect all bricks_template posts
    $ids = get_posts([
        'post_type'        => 'bricks_template',
        'post_status'      => 'any',
        'fields'           => 'ids',
        'nopaging'         => true,
        'suppress_filters' => false,
    ]);

    // Helper to compute export path & filename exactly like your exporter
    $compute_file_path = function($post) {
        $path = function_exists('dbvc_get_sync_path') ? dbvc_get_sync_path($post->post_type) : WP_CONTENT_DIR . '/dbvc-sync/' . $post->post_type . '/';
        $use_slug_option = (get_option('dbvc_use_slug_in_filenames') === '1');
        $slug = sanitize_title($post->post_name);
        $filename_part = ($use_slug_option && !empty($slug) && !is_numeric($slug)) ? $slug : $post->ID;
        $file_path = trailingslashit($path) . sanitize_file_name($post->post_type . '-' . $filename_part . '.json');
        $file_path = apply_filters('dbvc_export_post_file_path', $file_path, $post->ID, $post);
        return $file_path;
    };

    $supported = method_exists('DBVC_Sync_Posts', 'get_supported_post_types')
        ? DBVC_Sync_Posts::get_supported_post_types()
        : array_keys( get_post_types([], 'names') );

    $rows = [];
    foreach ($ids as $id) {
        $p = get_post($id);
        if (! $p) { continue; }

        $reason   = 'pass';
        $details  = [];
        $allowed  = apply_filters('dbvc_allowed_statuses_for_export', ['publish'], $p);
        $is_supported = in_array($p->post_type, $supported, true);
        $details[] = 'allowed_statuses=' . implode(',', (array)$allowed);
        $details[] = 'supported_types?=' . ($is_supported ? 'yes' : 'no');

        // Type check
        if (! $is_supported) {
            $reason = 'unsupported_type';
        }

        // Status check
        if ($reason === 'pass' && ! in_array($p->post_status, $allowed, true)) {
            $reason = 'disallowed_status_' . $p->post_status;
        }

        // Capability check (mirrors exporter)
        if ($reason === 'pass') {
            $pto = get_post_type_object($p->post_type);
            $skip_caps = apply_filters('dbvc_skip_read_cap_check', false, $id, $p);
            if ($pto && (! defined('WP_CLI') || ! WP_CLI) && ! $skip_caps) {
                if (! current_user_can($pto->cap->read_post, $id)) {
                    $reason = 'read_cap_fail';
                }
            }
        }

        // Incremental guard via filter (if present)
        // If any code hooks this and returns false, we surface it here.
        if ($reason === 'pass') {
            $should_export = apply_filters('dbvc_should_export_post', true, $id, $p);
            if (! $should_export) {
                $reason = 'filtered_out_by_dbvc_should_export_post';
            }
        }

        // Path & filename checks (safe path, writability, collision)
        $file_path = $compute_file_path($p);
        $path_ok = function_exists('dbvc_is_safe_file_path') ? dbvc_is_safe_file_path($file_path) : true;
        $dir = trailingslashit( dirname($file_path) );
        $dir_exists = is_dir($dir);
        $dir_writable = $dir_exists ? is_writable($dir) : false;
        $exists = file_exists($file_path);

        if ($reason === 'pass' && ! $path_ok) {
            $reason = 'unsafe_file_path';
        } elseif ($reason === 'pass' && $dir_exists && ! $dir_writable) {
            $reason = 'dir_not_writable';
        }

        $rows[] = [
            'ID'               => $id,
            'Title'            => get_the_title($id),
            'Status'           => $p->post_status,
            'Modified (GMT)'   => $p->post_modified_gmt,
            'Supported?'       => $is_supported ? 'yes' : 'no',
            'Reason'           => $reason,
            'File Path'        => $file_path,
            'Dir Exists?'      => $dir_exists ? 'yes' : 'no',
            'Dir Writable?'    => $dir_writable ? 'yes' : 'no',
            'File Exists?'     => $exists ? 'yes' : 'no',
            'Notes'            => implode(' | ', $details),
        ];
    }

    // Sort: skipped first, then pass
    usort($rows, function($a, $b){
        if ($a['Reason'] === $b['Reason']) return 0;
        if ($a['Reason'] === 'pass') return 1;
        if ($b['Reason'] === 'pass') return -1;
        return strcmp($a['Reason'], $b['Reason']);
    });

    // Output
    echo '<div class="notice notice-info" style="padding:12px 12px 0;"><p><strong>Bricks Template Export Check</strong></p>';
    echo '<table class="widefat striped"><thead><tr>';
    $headers = ['ID','Title','Status','Modified (GMT)','Supported?','Reason','File Path','Dir Exists?','Dir Writable?','File Exists?','Notes'];
    foreach ($headers as $h) echo '<th>' . esc_html($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
        echo '<tr>';
        foreach ($headers as $h) {
            $v = isset($r[$h]) ? $r[$h] : '';
            echo '<td style="vertical-align:top;">' . (in_array($h, ['ID','File Path']) ? '<code>' . esc_html($v) . '</code>' : esc_html($v)) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';

    // Also log a compact list of skipped IDs + reasons
    $skipped = array_filter($rows, fn($r) => $r['Reason'] !== 'pass');
    if ($skipped) {
        error_log('[DBVC] bricks_template skipped: ' . print_r(array_map(fn($r)=>[$r['ID']=>$r['Reason']], $skipped), true));
    }
}

?>

<hr />

<form method="post">
  <?php wp_nonce_field('dbvc_bricks_export_test_action', 'dbvc_bricks_export_test_nonce'); ?>
  <h2><?php esc_html_e('Bricks Export Dry-Run (templates only)', 'dbvc'); ?></h2>
  <p><?php esc_html_e('Runs export_post_to_json for all bricks_template posts and reports file write results.', 'dbvc'); ?></p>
  <label>
    <input type="checkbox" name="dbvc_force_caps" value="1" />
    <?php esc_html_e('Bypass read capability check for this run', 'dbvc'); ?>
  </label><br>
  <label>
    <input type="checkbox" name="dbvc_broaden_status" value="1" />
    <?php esc_html_e('Broaden allowed statuses for bricks_template during this run', 'dbvc'); ?>
  </label><br><br>
  <?php submit_button(__('Run Bricks Export Dry-Run', 'dbvc'), 'secondary', 'dbvc_bricks_export_test_button'); ?>
</form>


				<hr />

				<iframe name="dbvc_dl_iframe" style="display:none;width:0;height:0;border:0;" title="DBVC download"></iframe>

				<form id="dbvc-purge-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="dbvc_purge_sync" />
					<?php wp_nonce_field('dbvc_purge_sync_action', 'dbvc_purge_sync_nonce'); ?>
					<input type="hidden" name="dbvc_return" value="<?php echo esc_url(menu_page_url('db-version-control', false)); ?>" />

					<h2><?php esc_html_e('Purge Sync Folder', 'dbvc'); ?></h2>
					<p><?php esc_html_e('These actions affect the current sync folder path shown above. Use with caution.', 'dbvc'); ?></p>

					<p style="margin-bottom:0.5em;">
						<label>
							<input type="checkbox" name="dbvc_purge_delete" value="1" />
							<strong><?php esc_html_e('Delete all files (and folders) in the sync path', 'dbvc'); ?></strong>
						</label>
					</p>
					<p style="margin:0 0 1em;">
						<label>
							<input type="checkbox" name="dbvc_purge_download_then_delete" value="1" />
							<strong><?php esc_html_e('Download then delete all files', 'dbvc'); ?></strong>
							<br /><small><?php esc_html_e('Creates a ZIP of the sync folder, starts the download, then deletes the folder contents.', 'dbvc'); ?></small>
						</label>
					</p>
					<p style="margin:0 0 1em;">
						<label>
							<input type="checkbox" name="dbvc_purge_delete_root" value="1" />
							<strong>
								<?php
								$root_name = basename(untrailingslashit(dbvc_get_sync_path()));
								printf(esc_html__('Also delete the sync folder itself (%s)', 'dbvc'), esc_html($root_name));
								?>
							</strong>
							<br /><small><?php esc_html_e('Removes the folder after its contents are purged. Use with caution.', 'dbvc'); ?></small>
						</label>
					</p>
					<p>
						<label for="dbvc_purge_confirm">
							<?php esc_html_e('Type DELETE to confirm:', 'dbvc'); ?>
						</label><br />
						<input type="text" id="dbvc_purge_confirm" name="dbvc_purge_confirm" placeholder="DELETE" style="width:280px;" required />
					</p>

					<?php submit_button(esc_html__('Process', 'dbvc'), 'delete', 'dbvc_purge_sync_submit'); ?>
				</form>

				<script>
					jQuery(function($) {
						var pollTimer = null;

						function startPolling() {
							if (pollTimer) return;

							if (!$('#dbvc-inline-info').length) {
								$('<div id="dbvc-inline-info" class="notice notice-info"><p><?php echo esc_js(__('Your download has started. The sync folder will be purged after the download completes…', 'dbvc')); ?></p></div>').insertAfter('.wrap h1:first');
							}

							pollTimer = setInterval(function() {
								$.post(ajaxurl, {
									action: 'dbvc_purge_status'
								}).done(function(resp) {
									if (resp && resp.success && resp.data) {
										clearInterval(pollTimer);
										pollTimer = null;

										var data = resp.data || {};
										var suffix = data.deleted_root ? ' <?php echo esc_js(__('(Sync folder removed)', 'dbvc')); ?>' : '';
										var html = '<div class="notice notice-success"><p>' +
											'<?php echo esc_js(__('Purge completed. Deleted: %1$d, Failed: %2$d.%3$s', 'dbvc')); ?>'
											.replace('%1$d', parseInt(data.deleted || 0, 10))
											.replace('%2$d', parseInt(data.failed || 0, 10))
											.replace('%3$s', suffix) +
											'</p></div>';

										$('#dbvc-inline-info').remove();
										$(html).insertAfter('.wrap h1:first');
									}
								});
							}, 2000);
						}

						function isDownloadThenDeleteChecked() {
							return $('#dbvc-purge-form input[name="dbvc_purge_download_then_delete"]').is(':checked');
						}

						// Toggle target based on checkbox state
						$('#dbvc-purge-form input[name="dbvc_purge_download_then_delete"]').on('change', function() {
							if (this.checked) {
								$('#dbvc-purge-form').attr('target', 'dbvc_dl_iframe');
							} else {
								$('#dbvc-purge-form').removeAttr('target');
							}
						}).trigger('change'); // set initial state on load

						// On submit: decide path
						$('#dbvc-purge-form').on('submit', function() {
							if (isDownloadThenDeleteChecked()) {
								// Keep submission in iframe and poll for transient
								$(this).attr('target', 'dbvc_dl_iframe');
								startPolling();
							} else {
								// Delete-only: submit in main window so PHP redirect shows notice
								$(this).removeAttr('target');
							}
						});
					});
				</script>


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
      foreach (['draft','publish','pending'] as $s) {
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
    <input type="text" name="dbvc_mirror_domain" id="dbvc_mirror_domain"
           value="<?php echo esc_attr(get_option('dbvc_mirror_domain', '')); ?>"
           style="width:100%;" placeholder="e.g., https://staging.example.com" />
    <small><?php esc_html_e('Any URLs containing this domain will be replaced with the current site domain during import.', 'dbvc'); ?></small>
  </p>

  <p style="margin-top:.5rem;">
    <label>
      <input type="checkbox" name="dbvc_export_use_mirror_domain" value="1"
        <?php checked(get_option('dbvc_export_use_mirror_domain'), '1'); ?> />
      <?php esc_html_e('Replace current site domain with the Mirror Domain during export', 'dbvc'); ?>
    </label>
    <br><small><?php esc_html_e('When enabled, URLs that start with the current site domain will be rewritten to the Mirror Domain in exported content and meta.', 'dbvc'); ?></small>
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
				allowClear: true
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
