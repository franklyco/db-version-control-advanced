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

if (! function_exists('dbvc_get_selected_taxonomies')) {
  require_once dirname(__DIR__) . '/includes/functions.php';
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

  $custom_path            = get_option('dbvc_sync_path', '');
  $selected_post_types    = get_option('dbvc_post_types', []);
  $selected_taxonomies    = dbvc_get_selected_taxonomies();
  $current_import_mode    = dbvc_get_import_filename_format();
  $taxonomy_include_meta  = get_option('dbvc_tax_export_meta', '1');
  $taxonomy_include_parent = get_option('dbvc_tax_export_parent_slugs', '1');
  $taxonomy_filename_mode   = dbvc_get_taxonomy_filename_format();

  $allowed_export_mask_modes = ['none', 'remove_defaults', 'remove_customize', 'redact_custom'];
  $current_export_mask_mode  = get_option('dbvc_export_last_mask_mode', 'none');
  if (! in_array($current_export_mask_mode, $allowed_export_mask_modes, true)) {
    $current_export_mask_mode = 'none';
  }

  $allowed_auto_mask_modes         = ['none', 'remove_defaults', 'redact_defaults'];
  $auto_export_mask_mode           = get_option('dbvc_auto_export_mask_mode', 'none');
  if (! in_array($auto_export_mask_mode, $allowed_auto_mask_modes, true)) {
    $auto_export_mask_mode = 'none';
  }
  $auto_export_mask_placeholder    = (string) get_option(
    'dbvc_auto_export_mask_placeholder',
    get_option('dbvc_mask_placeholder', '***')
  );

  $config_feedback = [
    'post_types' => ['success' => [], 'error' => []],
    'taxonomies' => ['success' => [], 'error' => []],
    'masking'    => ['success' => [], 'error' => []],
    'import'     => ['success' => [], 'error' => []],
  ];
  $config_section_mapping = [
    'post_types' => 'dbvc-config-post-types',
    'taxonomies' => 'dbvc-config-taxonomies',
    'masking'    => 'dbvc-config-masking',
    'import'     => 'dbvc-config-import',
  ];
  $config_sections_submitted = [];
  $config_form_was_submitted = false;

  $active_main_tab      = 'tab-import';
  $active_import_subtab = 'dbvc-import-content';
  $active_export_subtab = 'dbvc-export-full';
  $active_config_subtab = 'dbvc-config-post-types';

  // Unified Configure (Tab 3) save handler.
  if (isset($_POST['dbvc_config_save']) && isset($_POST['dbvc_config_nonce']) && wp_verify_nonce($_POST['dbvc_config_nonce'], 'dbvc_config_save_action')) {
    if (! current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
    }

    $config_form_was_submitted = true;
    $active_main_tab           = 'tab-config';
    $config_sections_submitted = array_keys((array) $_POST['dbvc_config_save']);
    $primary_section           = $config_sections_submitted ? $config_sections_submitted[0] : 'post_types';
    if (isset($config_section_mapping[$primary_section])) {
      $active_config_subtab = $config_section_mapping[$primary_section];
    }

    // --- Post Types ---
    $new_post_types = [];
    if (isset($_POST['dbvc_post_types']) && is_array($_POST['dbvc_post_types'])) {
      $requested_post_types = array_map('sanitize_text_field', wp_unslash($_POST['dbvc_post_types']));
      $valid_post_types     = array_keys(dbvc_get_available_post_types());
      $new_post_types       = array_values(array_intersect($requested_post_types, $valid_post_types));
    }
    update_option('dbvc_post_types', $new_post_types);
    $selected_post_types = $new_post_types;
    if (in_array('post_types', $config_sections_submitted, true)) {
      $config_feedback['post_types']['success'][] = esc_html__('Post type selections saved.', 'dbvc');
    }

    // --- Taxonomies ---
    $new_taxonomies = [];
    if (isset($_POST['dbvc_taxonomies']) && is_array($_POST['dbvc_taxonomies'])) {
      $requested_taxes  = array_map('sanitize_key', wp_unslash($_POST['dbvc_taxonomies']));
      $valid_taxonomies = array_keys(dbvc_get_available_taxonomies());
      $new_taxonomies   = array_values(array_intersect($requested_taxes, $valid_taxonomies));
    }
    update_option('dbvc_taxonomies', $new_taxonomies);
    $selected_taxonomies = $new_taxonomies;

    $taxonomy_include_meta   = ! empty($_POST['dbvc_tax_export_meta']) ? '1' : '0';
    $taxonomy_include_parent = ! empty($_POST['dbvc_tax_export_parent_slugs']) ? '1' : '0';
    $taxonomy_filename_mode  = isset($_POST['dbvc_taxonomy_filename_format']) ? sanitize_key($_POST['dbvc_taxonomy_filename_format']) : dbvc_get_taxonomy_filename_format();
    $allowed_tax_modes       = (array) apply_filters('dbvc_allowed_taxonomy_filename_formats', ['id', 'slug', 'slug_id']);
    if (! in_array($taxonomy_filename_mode, $allowed_tax_modes, true)) {
      $taxonomy_filename_mode = 'slug';
    }
    update_option('dbvc_tax_export_meta', $taxonomy_include_meta);
    update_option('dbvc_tax_export_parent_slugs', $taxonomy_include_parent);
    update_option('dbvc_taxonomy_filename_format', $taxonomy_filename_mode);
    if (in_array('taxonomies', $config_sections_submitted, true)) {
      $config_feedback['taxonomies']['success'][] = esc_html__('Taxonomy settings saved.', 'dbvc');
    }

    // --- Masking Defaults & Auto Export ---
    $meta_defaults = isset($_POST['dbvc_mask_defaults_meta_keys']) ? (string) wp_unslash($_POST['dbvc_mask_defaults_meta_keys']) : '';
    $sub_defaults  = isset($_POST['dbvc_mask_defaults_subkeys']) ? (string) wp_unslash($_POST['dbvc_mask_defaults_subkeys']) : '';
    update_option('dbvc_mask_defaults_meta_keys', $meta_defaults);
    update_option('dbvc_mask_defaults_subkeys',  $sub_defaults);
    $existing_defaults_meta = $meta_defaults;
    $existing_defaults_sub  = $sub_defaults;

    $auto_mode_input   = isset($_POST['dbvc_auto_export_mask_mode']) ? sanitize_key($_POST['dbvc_auto_export_mask_mode']) : 'none';
    $allowed_auto_modes = (array) apply_filters('dbvc_auto_export_mask_modes', ['none', 'remove_defaults', 'redact_defaults']);
    if (! in_array($auto_mode_input, $allowed_auto_modes, true)) {
      $auto_mode_input = 'none';
    }

    $auto_placeholder = isset($_POST['dbvc_auto_export_mask_placeholder']) ? sanitize_text_field(wp_unslash($_POST['dbvc_auto_export_mask_placeholder'])) : '';
    if ($auto_placeholder === '') {
      $auto_placeholder = '***';
    }

    update_option('dbvc_auto_export_mask_mode', $auto_mode_input);
    update_option('dbvc_auto_export_mask_placeholder', $auto_placeholder);
    $auto_export_mask_mode        = $auto_mode_input;
    $auto_export_mask_placeholder = $auto_placeholder;

    if (in_array('masking', $config_sections_submitted, true)) {
      $config_feedback['masking']['success'][] = esc_html__('Masking defaults updated.', 'dbvc');
    }

    // --- Sync Path ---
    $sync_path_raw = isset($_POST['dbvc_sync_path']) ? sanitize_text_field(wp_unslash($_POST['dbvc_sync_path'])) : '';
    $validated_path = dbvc_validate_sync_path($sync_path_raw);
    if (false === $validated_path) {
      $config_feedback['import']['error'][] = esc_html__('Invalid sync path provided. Path cannot contain ../ or other unsafe characters.', 'dbvc');
    } else {
      update_option('dbvc_sync_path', $validated_path);
      $custom_path   = $validated_path;
      $resolved_path = dbvc_get_sync_path();
      if (! wp_mkdir_p($resolved_path)) {
        $config_feedback['import']['error'][] = sprintf(esc_html__('Sync folder setting saved, but the directory could not be created at: %s. Please check permissions.', 'dbvc'), esc_html($resolved_path));
      } else {
        $config_feedback['import']['success'][] = sprintf(esc_html__('Sync folder ensured at: %s', 'dbvc'), esc_html($resolved_path));
      }
    }

    // --- Import Defaults ---
    $allow_new_posts = ! empty($_POST['dbvc_allow_new_posts']) ? '1' : '0';
    update_option('dbvc_allow_new_posts', $allow_new_posts);

    $new_post_status = isset($_POST['dbvc_new_post_status']) ? sanitize_text_field(wp_unslash($_POST['dbvc_new_post_status'])) : 'draft';
    if (! in_array($new_post_status, ['draft', 'publish', 'pending'], true)) {
      $new_post_status = 'draft';
    }
    update_option('dbvc_new_post_status', $new_post_status);

    $whitelist = [];
    if (isset($_POST['dbvc_new_post_types_whitelist']) && is_array($_POST['dbvc_new_post_types_whitelist'])) {
      $requested_whitelist = array_map('sanitize_text_field', wp_unslash($_POST['dbvc_new_post_types_whitelist']));
      $valid_post_types    = array_keys(dbvc_get_available_post_types());
      $whitelist           = array_values(array_intersect($requested_whitelist, $valid_post_types));
    }
    update_option('dbvc_new_post_types_whitelist', $whitelist);

    $mirror_domain = isset($_POST['dbvc_mirror_domain']) ? sanitize_text_field(wp_unslash($_POST['dbvc_mirror_domain'])) : '';
    update_option('dbvc_mirror_domain', $mirror_domain);

    $export_use_mirror = ! empty($_POST['dbvc_export_use_mirror_domain']) ? '1' : '0';
    update_option('dbvc_export_use_mirror_domain', $export_use_mirror);

    if (in_array('import', $config_sections_submitted, true)) {
      $config_feedback['import']['success'][] = esc_html__('Import defaults saved.', 'dbvc');
    }

    // Ensure we always have at least one success notice for the triggering section
    if ($config_sections_submitted) {
      $primary = $config_sections_submitted[0];
      if (isset($config_feedback[$primary]) && empty($config_feedback[$primary]['success']) && empty($config_feedback[$primary]['error'])) {
        $config_feedback[$primary]['success'][] = esc_html__('Configuration settings saved.', 'dbvc');
      }
    }

    if (! empty($config_feedback['import']['error'])) {
      $active_config_subtab = 'dbvc-config-import';
    }
  }

  // Handle export form (with Masking Modes)
  if (isset($_POST['dbvc_export_nonce']) && wp_verify_nonce($_POST['dbvc_export_nonce'], 'dbvc_export_action')) {
    $active_main_tab      = 'tab-export';
    $active_export_subtab = 'dbvc-export-full';
    // Capability check
    if (! current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
    }

    // --- Gather POSTed basic settings ---
    $filename_mode  = isset($_POST['dbvc_export_filename_format'])
      ? sanitize_key($_POST['dbvc_export_filename_format'])
      : dbvc_get_export_filename_format();
    $allowed_filename_modes = ['id', 'slug', 'slug_id'];
    if (! in_array($filename_mode, $allowed_filename_modes, true)) {
      $filename_mode = 'id';
    }
    $strip_checked  = ! empty($_POST['dbvc_strip_domain_urls']);
    $mirror_checked = ! empty($_POST['dbvc_export_use_mirror_domain']);

    // Persist filename format & mirror checkbox (mirror domain itself is saved in Tab 3)
    update_option('dbvc_export_filename_format', $filename_mode);
    update_option('dbvc_use_slug_in_filenames', $filename_mode === 'id' ? '0' : '1'); // legacy flag
    update_option('dbvc_export_use_mirror_domain', $mirror_checked ? '1' : '0');

    // --- New: Determine masking mode (from Export tab radio) ---
    $mask_mode = isset($_POST['dbvc_export_mask_mode'])
      ? sanitize_key($_POST['dbvc_export_mask_mode'])
      : 'none'; // none | remove_defaults | remove_customize | redact_custom
    if (! in_array($mask_mode, $allowed_export_mask_modes, true)) {
      $mask_mode = 'none';
    }
    update_option('dbvc_export_last_mask_mode', $mask_mode);
    $current_export_mask_mode = $mask_mode;

    // Snapshot existing mask options so this run is temporary
    $prev_mask = [
      'action'      => get_option('dbvc_mask_action', 'remove'),
      'meta_keys'   => get_option('dbvc_mask_meta_keys', ''),
      'subkeys'     => get_option('dbvc_mask_subkeys', ''),
      'placeholder' => get_option('dbvc_mask_placeholder', '***'),
    ];

    // Load defaults (raw strings) saved in Tab #3
    $defaults_meta_raw = (string) get_option('dbvc_mask_defaults_meta_keys', '');
    $defaults_sub_raw  = (string) get_option('dbvc_mask_defaults_subkeys',  '');

    // Build effective masking for this run
    $effective_action      = 'remove';
    $effective_meta_keys   = '';
    $effective_subkeys     = '';
    $effective_placeholder = $prev_mask['placeholder']; // carry over unless user supplies

    if ($mask_mode === 'none') {
      // No masking at all
      $effective_action    = 'remove';
      $effective_meta_keys = '';
      $effective_subkeys   = '';
    } elseif ($mask_mode === 'remove_defaults') {
      // Remove using saved defaults only
      $effective_action    = 'remove';
      $effective_meta_keys = $defaults_meta_raw;
      $effective_subkeys   = $defaults_sub_raw;
    } elseif ($mask_mode === 'remove_customize') {
      // Merge defaults + user additions (pre-filled UI)
      $effective_action = 'remove';
      $custom_meta_raw  = isset($_POST['dbvc_custom_remove_meta_keys']) ? (string) wp_unslash($_POST['dbvc_custom_remove_meta_keys']) : '';
      $custom_sub_raw   = isset($_POST['dbvc_custom_remove_subkeys'])   ? (string) wp_unslash($_POST['dbvc_custom_remove_subkeys'])   : '';

      $merged_meta = array_unique(array_merge(
        dbvc_normalize_list_field($defaults_meta_raw),
        dbvc_normalize_list_field($custom_meta_raw)
      ));
      $merged_sub  = array_unique(array_merge(
        dbvc_normalize_list_field($defaults_sub_raw),
        dbvc_normalize_list_field($custom_sub_raw)
      ));

      $effective_meta_keys = implode("\n", $merged_meta);
      $effective_subkeys   = implode("\n", $merged_sub);
    } elseif ($mask_mode === 'redact_custom') {
      // Redact with user-provided keys + placeholder
      $effective_action      = 'redact';
      $effective_meta_keys   = isset($_POST['dbvc_redact_meta_keys']) ? (string) wp_unslash($_POST['dbvc_redact_meta_keys']) : '';
      $effective_subkeys     = isset($_POST['dbvc_redact_subkeys'])   ? (string) wp_unslash($_POST['dbvc_redact_subkeys'])   : '';
      $effective_placeholder = isset($_POST['dbvc_redact_placeholder'])
        ? (string) wp_unslash($_POST['dbvc_redact_placeholder'])
        : $effective_placeholder;
    }

    // Apply effective masking temporarily (keep exporter unchanged)
    update_option('dbvc_mask_action',      $effective_action);
    update_option('dbvc_mask_meta_keys',   $effective_meta_keys);
    update_option('dbvc_mask_subkeys',     $effective_subkeys);
    update_option('dbvc_mask_placeholder', $effective_placeholder);

    // --- Mirror vs Strip coordination for THIS run (unchanged) ---
    $mirror_raw  = trim((string) get_option('dbvc_mirror_domain', ''));
    $site_home   = untrailingslashit(home_url());
    $mirror_home = untrailingslashit($mirror_raw);

    $mirror_cb         = null;   // to remove later
    $restore_strip_opt = null;   // to restore user's strip choice after run
    $mirror_valid      = $mirror_checked && $mirror_home && filter_var($mirror_home, FILTER_VALIDATE_URL);

    if ($mirror_valid) {
      if ($strip_checked) {
        $restore_strip_opt = '1';                 // remember to restore ON
        update_option('dbvc_strip_domain_urls', '0'); // force OFF for this run
      } else {
        update_option('dbvc_strip_domain_urls', '0');
      }

      $mirror_cb = function (array $data, $post_id, $post) use ($site_home, $mirror_home) {
        if (isset($data['post_content']) && is_string($data['post_content'])) {
          $data['post_content'] = str_replace($site_home, $mirror_home, $data['post_content']);
        }
        if (isset($data['post_excerpt']) && is_string($data['post_excerpt'])) {
          $data['post_excerpt'] = str_replace($site_home, $mirror_home, $data['post_excerpt']);
        }
        if (isset($data['meta'])) {
          $data['meta'] = dbvc_recursive_str_replace($site_home, $mirror_home, $data['meta']);
        }
        return $data;
      };
      add_filter('dbvc_export_post_data', $mirror_cb, 9, 3);
    } else {
      update_option('dbvc_strip_domain_urls', $strip_checked ? '1' : '0');
    }

    // --- Make statuses + caps predictable during this run (with cleanup handles) ---
    $status_cb = function ($statuses, $post) {
      if ($post && $post->post_type === 'bricks_template') {
        return ['publish', 'private', 'draft', 'pending', 'future', 'inherit', 'auto-draft'];
      }
      return $statuses;
    };
    add_filter('dbvc_allowed_statuses_for_export', $status_cb, 10, 2);
    add_filter('dbvc_skip_read_cap_check', '__return_true', 10, 3);

    try {
      // --- Exports ---
      DBVC_Sync_Posts::export_options_to_json();
      DBVC_Sync_Posts::export_menus_to_json();
      if (class_exists('DBVC_Sync_Taxonomies')) {
        DBVC_Sync_Taxonomies::export_selected_taxonomies();
      }

      $selected = (array) get_option('dbvc_post_types', []);
      if (empty($selected)) {
        $selected = method_exists('DBVC_Sync_Posts', 'get_supported_post_types')
          ? DBVC_Sync_Posts::get_supported_post_types()
          : array_keys(dbvc_get_available_post_types());
      }
      if (! in_array('bricks_template', $selected, true)) {
        $selected[] = 'bricks_template';
      }

      $statuses = apply_filters('dbvc_export_all_post_statuses', [
        'publish',
        'private',
        'draft',
        'pending',
        'future',
        'inherit',
        'auto-draft'
      ]);

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
                DBVC_Sync_Posts::export_post_to_json($post_id, $post, $filename_mode);
              }
            }
          }
          $paged++;
        } while (! empty($q->posts));
      }

      if (method_exists('DBVC_Sync_Posts', 'dbvc_create_backup_folder_and_copy_exports')) {
        DBVC_Sync_Posts::dbvc_create_backup_folder_and_copy_exports();
      } else {
        error_log('[DBVC] Static method dbvc_create_backup_folder_and_copy_exports not found in DBVC_Sync_Posts.');
      }

      echo '<div class="notice notice-success"><p>' . esc_html__('Full export completed!', 'dbvc') . '</p></div>';
    } finally {
      // --- Cleanup (mirror filter + strip restoration; temp filters; restore masking options) ---
      if ($mirror_cb) {
        remove_filter('dbvc_export_post_data', $mirror_cb, 9);
      }
      if ($restore_strip_opt !== null) {
        update_option('dbvc_strip_domain_urls', $restore_strip_opt);
      }

      remove_filter('dbvc_allowed_statuses_for_export', $status_cb, 10);
      remove_filter('dbvc_skip_read_cap_check', '__return_true', 10);

      // Restore previous masking options so nothing sticks
      update_option('dbvc_mask_action',      $prev_mask['action']);
      update_option('dbvc_mask_meta_keys',   $prev_mask['meta_keys']);
      update_option('dbvc_mask_subkeys',     $prev_mask['subkeys']);
      update_option('dbvc_mask_placeholder', $prev_mask['placeholder']);
    }
  }

  if (isset($_POST['dbvc_import_button']) && wp_verify_nonce($_POST['dbvc_import_nonce'], 'dbvc_import_action')) {
    $active_main_tab     = 'tab-import';
    $active_import_subtab = 'dbvc-import-content';
    if (current_user_can('manage_options')) {
      $smart_import  = ! empty($_POST['dbvc_smart_import']);
      $import_menus  = ! empty($_POST['dbvc_import_menus']);
      $import_mode   = isset($_POST['dbvc_import_filename_format'])
        ? sanitize_key($_POST['dbvc_import_filename_format'])
        : $current_import_mode;
      $allowed_filename_modes = ['id', 'slug', 'slug_id'];
      if (! in_array($import_mode, $allowed_filename_modes, true)) {
        $import_mode = $current_import_mode;
      }

      update_option('dbvc_import_filename_format', $import_mode);

      if (class_exists('DBVC_Sync_Taxonomies')) {
        DBVC_Sync_Taxonomies::import_taxonomies();
      }

      DBVC_Sync_Posts::import_all(0, $smart_import, $import_mode);

      if ($import_menus) {
        DBVC_Sync_Posts::import_menus_from_json();
      }

      echo '<div class="notice notice-success"><p>' . esc_html__('Import completed.', 'dbvc') . '</p></div>';
    } else {
      wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
    }
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

  // === Helper: normalize list textarea (comma/newline -> array of unique trimmed strings)
  if (!function_exists('dbvc_normalize_list_field')) {
    function dbvc_normalize_list_field($raw)
    {
      $raw = (string) $raw;
      if ($raw === '') return [];
      $parts = preg_split('/[\r\n,]+/', $raw);
      $parts = array_filter(array_map('trim', $parts), fn($s) => $s !== '');
      return array_values(array_unique($parts));
    }
  }

  // Handle purge/delete controls (Tab 3).
  if (isset($_POST['dbvc_purge_sync_submit']) && wp_verify_nonce($_POST['dbvc_purge_sync_nonce'], 'dbvc_purge_sync_action')) {
    $active_main_tab      = 'tab-config';
    $active_config_subtab = 'dbvc-config-tools';
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

  if (
    isset($_POST['dbvc_bricks_export_test_button'])
    && wp_verify_nonce($_POST['dbvc_bricks_export_test_nonce'], 'dbvc_bricks_export_test_action')
  ) {
    $active_main_tab      = 'tab-config';
    $active_config_subtab = 'dbvc-config-tools';

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
      add_filter('dbvc_allowed_statuses_for_export', function ($statuses, $post) {
        if ($post && $post->post_type === 'bricks_template') {
          return ['publish', 'private', 'draft', 'pending', 'future', 'inherit', 'auto-draft'];
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
      if (! $p) {
        continue;
      }

      // Compute the exact file path the exporter will use (matches your exporter)
      $path = function_exists('dbvc_get_sync_path') ? dbvc_get_sync_path($p->post_type) : WP_CONTENT_DIR . '/dbvc-sync/' . $p->post_type . '/';
      $components = DBVC_Sync_Posts::resolve_filename_components($p->ID, $p, null, false);
      $file_path = trailingslashit($path) . $components['filename'];
      $file_path = apply_filters('dbvc_export_post_file_path', $file_path, $p->ID, $p);

      $pre_exists = file_exists($file_path);

      // Run the actual exporter once for each
      DBVC_Sync_Posts::export_post_to_json($p->ID, $p, $components['mode']);

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
    $headers = ['ID', 'Title', 'Status', 'Path', 'Dir Exists?', 'Writable?', 'Pre-Exists?', 'Post-Exists?', 'Bytes'];
    foreach ($headers as $h) echo '<th>' . esc_html($h) . '</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) {
      echo '<tr>';
      foreach ($headers as $h) {
        $v = isset($r[$h]) ? $r[$h] : '';
        echo '<td style="vertical-align:top;">' . (in_array($h, ['ID', 'Path']) ? '<code>' . esc_html($v) . '</code>' : esc_html($v)) . '</td>';
      }
      echo '</tr>';
    }
    echo '</tbody></table></div>';
  }



  // Get the current resolved path for display.
  $current_filename_mode = dbvc_get_export_filename_format();
  $resolved_path = dbvc_get_sync_path();

  // Get all public post types.
  $all_post_types = dbvc_get_available_post_types();

  $main_tabs = [
    'tab-import' => esc_html__('Import/Upload', 'dbvc'),
    'tab-export' => esc_html__('Export/Download', 'dbvc'),
    'tab-config' => esc_html__('Configure', 'dbvc'),
  ];
  $import_subtabs = [
    'dbvc-import-content' => esc_html__('Content Import', 'dbvc'),
    'dbvc-import-upload'  => esc_html__('Upload', 'dbvc'),
  ];
  $export_subtabs = [
    'dbvc-export-full'     => esc_html__('Full Export', 'dbvc'),
    'dbvc-export-download' => esc_html__('Download', 'dbvc'),
  ];
  $config_subtabs = [
    'dbvc-config-post-types' => esc_html__('Post Types', 'dbvc'),
    'dbvc-config-taxonomies' => esc_html__('Taxonomies', 'dbvc'),
    'dbvc-config-masking'    => esc_html__('Masking & Auto-Exports', 'dbvc'),
    'dbvc-config-import'     => esc_html__('Import Defaults', 'dbvc'),
    'dbvc-config-tools'      => esc_html__('Maintenance & Tools', 'dbvc'),
  ];
  $render_config_feedback = function (array $bucket) {
    $errors  = array_unique(array_filter($bucket['error'] ?? []));
    $success = array_unique(array_filter($bucket['success'] ?? []));
    if (empty($errors) && empty($success)) {
      return;
    }
    echo '<div class="dbvc-config-feedback">';
    foreach ($errors as $message) {
      echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }
    foreach ($success as $message) {
      echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
    }
    echo '</div>';
  };

?>
  <div class="wrap">
    <h1><?php esc_html_e('DB Version Control', 'dbvc'); ?></h1>

    <div class="dbvc-admin-layout" data-dbvc-tabs>
      <nav class="dbvc-main-nav" role="tablist" aria-label="<?php esc_attr_e('DBVC Sections', 'dbvc'); ?>">
<?php foreach ($main_tabs as $tab_id => $label) :
  $button_id = 'dbvc-nav-' . $tab_id;
  $is_active = ($active_main_tab === $tab_id);
?>
        <button type="button"
          id="<?php echo esc_attr($button_id); ?>"
          class="dbvc-main-nav__item<?php echo $is_active ? ' is-active' : ''; ?>"
          data-dbvc-tab="<?php echo esc_attr($tab_id); ?>"
          role="tab"
          aria-controls="<?php echo esc_attr($tab_id); ?>"
          aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
          <?php echo esc_html($label); ?>
        </button>
<?php endforeach; ?>
      </nav>

      <div class="dbvc-main-panels">
        <section id="tab-import" class="dbvc-tab-panel<?php echo $active_main_tab === 'tab-import' ? ' is-active' : ''; ?>" data-dbvc-panel="tab-import" role="tabpanel" aria-labelledby="dbvc-nav-tab-import" <?php echo $active_main_tab === 'tab-import' ? '' : 'hidden'; ?>>
        <div class="dbvc-subtabs" data-dbvc-subtabs>
          <nav class="dbvc-subtabs-nav" role="tablist" aria-label="<?php esc_attr_e('Import subsections', 'dbvc'); ?>">
<?php foreach ($import_subtabs as $panel_id => $label) :
  $button_id = 'dbvc-nav-' . $panel_id;
  $is_active = ($active_import_subtab === $panel_id);
?>
            <button type="button"
              id="<?php echo esc_attr($button_id); ?>"
              class="dbvc-subtabs-nav__item<?php echo $is_active ? ' is-active' : ''; ?>"
              data-dbvc-subtab="<?php echo esc_attr($panel_id); ?>"
              role="tab"
              aria-controls="<?php echo esc_attr($panel_id); ?>"
              aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
              <?php echo esc_html($label); ?>
            </button>
<?php endforeach; ?>
          </nav>

          <div class="dbvc-subtabs-panels">
            <section id="dbvc-import-content" class="dbvc-subtab-panel<?php echo $active_import_subtab === 'dbvc-import-content' ? ' is-active' : ''; ?>" data-dbvc-subpanel="dbvc-import-content" role="tabpanel" aria-labelledby="dbvc-nav-dbvc-import-content" <?php echo $active_import_subtab === 'dbvc-import-content' ? '' : 'hidden'; ?>>
              <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('dbvc_import_action', 'dbvc_import_nonce'); ?>
                <h2><?php esc_html_e('Import from JSON', 'dbvc'); ?></h2>
                <p><?php esc_html_e('Import posts & CPTs from the sync folder. Optionally only new/changed content.', 'dbvc'); ?></p>

                <fieldset style="margin:0 0 1rem 0;">
                  <legend><strong><?php esc_html_e('Import Filename Filter', 'dbvc'); ?></strong></legend>
                  <label style="display:block;margin:.25rem 0;">
                    <input type="radio" name="dbvc_import_filename_format" value="id" <?php checked($current_import_mode, 'id'); ?> />
                    <?php esc_html_e('Only import files named with post ID (e.g., cpt-123.json)', 'dbvc'); ?>
                  </label>
                  <label style="display:block;margin:.25rem 0;">
                    <input type="radio" name="dbvc_import_filename_format" value="slug" <?php checked($current_import_mode, 'slug'); ?> />
                    <?php esc_html_e('Only import files named with the post slug (e.g., cpt-sample-page.json)', 'dbvc'); ?>
                  </label>
                  <label style="display:block;margin:.25rem 0;">
                    <input type="radio" name="dbvc_import_filename_format" value="slug_id" <?php checked($current_import_mode, 'slug_id'); ?> />
                    <?php esc_html_e('Only import files named with slug and ID (e.g., cpt-sample-page-123.json)', 'dbvc'); ?>
                  </label>
                  <p class="description"><?php esc_html_e('Select the filename style to process. Choose the format that matches your current exports to avoid legacy duplicates.', 'dbvc'); ?></p>
                </fieldset>

                <label><input type="checkbox" name="dbvc_smart_import" value="1" /> <?php esc_html_e('Only import new or modified posts', 'dbvc'); ?></label><br>
                <label><input type="checkbox" name="dbvc_import_menus" value="1" /> <?php esc_html_e('Also import menus', 'dbvc'); ?></label><br><br>
                <?php submit_button(esc_html__('Run Import', 'dbvc'), 'primary', 'dbvc_import_button'); ?>
              </form>
            </section>

            <section id="dbvc-import-upload" class="dbvc-subtab-panel<?php echo $active_import_subtab === 'dbvc-import-upload' ? ' is-active' : ''; ?>" data-dbvc-subpanel="dbvc-import-upload" role="tabpanel" aria-labelledby="dbvc-nav-dbvc-import-upload" <?php echo $active_import_subtab === 'dbvc-import-upload' ? '' : 'hidden'; ?>>
              <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
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
                <div class="notice <?php echo esc_attr($class); ?>" style="margin-top:1em;">
                  <p><?php echo esc_html($msg); ?></p>
                </div>
              <?php endif; ?>
            </section>
          </div>
        </div>
        </section>

      <!-- Tab 2: Export / Download -->
      <section id="tab-export" class="dbvc-tab-panel<?php echo $active_main_tab === 'tab-export' ? ' is-active' : ''; ?>" data-dbvc-panel="tab-export" role="tabpanel" aria-labelledby="dbvc-nav-tab-export" <?php echo $active_main_tab === 'tab-export' ? '' : 'hidden'; ?>>
        <div class="dbvc-subtabs" data-dbvc-subtabs>
          <nav class="dbvc-subtabs-nav" role="tablist" aria-label="<?php esc_attr_e('Export subsections', 'dbvc'); ?>">
<?php foreach ($export_subtabs as $panel_id => $label) :
  $button_id = 'dbvc-nav-' . $panel_id;
  $is_active = ($active_export_subtab === $panel_id);
?>
            <button type="button"
              id="<?php echo esc_attr($button_id); ?>"
              class="dbvc-subtabs-nav__item<?php echo $is_active ? ' is-active' : ''; ?>"
              data-dbvc-subtab="<?php echo esc_attr($panel_id); ?>"
              role="tab"
              aria-controls="<?php echo esc_attr($panel_id); ?>"
              aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
              <?php echo esc_html($label); ?>
            </button>
<?php endforeach; ?>
          </nav>

          <div class="dbvc-subtabs-panels">
            <section id="dbvc-export-full" class="dbvc-subtab-panel<?php echo $active_export_subtab === 'dbvc-export-full' ? ' is-active' : ''; ?>" data-dbvc-subpanel="dbvc-export-full" role="tabpanel" aria-labelledby="dbvc-nav-dbvc-export-full" <?php echo $active_export_subtab === 'dbvc-export-full' ? '' : 'hidden'; ?>>
            <form method="post">
              <?php wp_nonce_field('dbvc_export_action', 'dbvc_export_nonce'); ?>
              <h2><?php esc_html_e('Full Export', 'dbvc'); ?></h2>
              <p><?php esc_html_e('Export all posts, options, and menus to JSON files.', 'dbvc'); ?></p>

              <fieldset style="margin:0 0 1rem 0;">
                <legend><strong><?php esc_html_e('Export Filename Format', 'dbvc'); ?></strong></legend>
                <label style="display:block;margin:.25rem 0;">
                  <input type="radio" name="dbvc_export_filename_format" value="id" <?php checked($current_filename_mode, 'id'); ?> />
                  <?php esc_html_e('Use post ID (e.g., cpt-123.json)', 'dbvc'); ?>
                </label>
                <label style="display:block;margin:.25rem 0;">
                  <input type="radio" name="dbvc_export_filename_format" value="slug" <?php checked($current_filename_mode, 'slug'); ?> />
                  <?php esc_html_e('Use post slug when available (e.g., cpt-sample-page.json)', 'dbvc'); ?>
                </label>
                <label style="display:block;margin:.25rem 0;">
                  <input type="radio" name="dbvc_export_filename_format" value="slug_id" <?php checked($current_filename_mode, 'slug_id'); ?> />
                  <?php esc_html_e('Use slug and ID (e.g., cpt-sample-page-123.json)', 'dbvc'); ?>
                </label>
                <p class="description"><?php esc_html_e('Slug-based formats fall back to the post ID if the slug is empty or numeric.', 'dbvc'); ?></p>
              </fieldset>

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

              <!-- Export Masking Mode -->
              <h3 style="margin-top:0;"><?php esc_html_e('Export Masking (Post Meta)', 'dbvc'); ?></h3>

              <?php
              // Load defaults once for the UI (saved in Tab #3).
              $mask_defaults_meta = (string) get_option('dbvc_mask_defaults_meta_keys', '');
              $mask_defaults_sub  = (string) get_option('dbvc_mask_defaults_subkeys', '');
              $mask_placeholder_d = (string) get_option('dbvc_mask_placeholder', '***'); // re-use existing option as a default value
              ?>

              <fieldset id="dbvc-mask-mode-wrap" style="margin-bottom:1rem;">
                <legend><strong><?php esc_html_e('Choose how to handle post meta during export', 'dbvc'); ?></strong></legend>
                <label style="display:block;margin:.25rem 0;">
                  <input type="radio" name="dbvc_export_mask_mode" value="none" <?php checked($current_export_mask_mode, 'none'); ?> />
                  <?php esc_html_e('1) Standard Export (No Masking)', 'dbvc'); ?>
                </label>

                <label style="display:block;margin:.25rem 0;">
                  <input type="radio" name="dbvc_export_mask_mode" value="remove_defaults" <?php checked($current_export_mask_mode, 'remove_defaults'); ?> />
                  <?php esc_html_e('2) Remove matched Masking Defaults from exports', 'dbvc'); ?>
                  <br><small>
                    <?php esc_html_e('Uses the default keys saved under Configure → Export Masking Defaults.', 'dbvc'); ?>
                  </small>
                </label>

                <label style="display:block;margin:.25rem 0;">
                  <input type="radio" name="dbvc_export_mask_mode" value="remove_customize" <?php checked($current_export_mask_mode, 'remove_customize'); ?> />
                  <?php esc_html_e('3) Remove & Customize matched Masking Defaults', 'dbvc'); ?>
                  <br><small>
                    <?php esc_html_e('Pre-fills the inputs below with your saved defaults; you can add extra keys before running export.', 'dbvc'); ?>
                  </small>
                </label>

                <label style="display:block;margin:.25rem 0;">
                  <input type="radio" name="dbvc_export_mask_mode" value="redact_custom" <?php checked($current_export_mask_mode, 'redact_custom'); ?> />
                  <?php esc_html_e('4) Redact matched items with a placeholder', 'dbvc'); ?>
                  <br><small>
                    <?php esc_html_e('Same behavior as before: provide keys to redact and a placeholder token.', 'dbvc'); ?>
                  </small>
                </label>
              </fieldset>
              <p class="description">
                <?php esc_html_e('Defaults are managed in Configure → Export Masking Defaults (Tab 3).', 'dbvc'); ?>
                <a href="#tab-config"><?php esc_html_e('Open Tab 3', 'dbvc'); ?></a>
              </p>


              <!-- Mode 3 inputs (pre-filled with defaults; user can add lines) -->
              <div id="dbvc-mask-mode-3" style="display:none;margin-left:1rem;">
                <p>
                  <label for="dbvc_custom_remove_meta_keys"><strong><?php esc_html_e('Exclude whole postmeta keys', 'dbvc'); ?></strong></label><br>
                  <textarea name="dbvc_custom_remove_meta_keys" id="dbvc_custom_remove_meta_keys" rows="3" style="width:100%;"
                    placeholder="<?php echo esc_attr('_wp_old_date, _dbvc_import_hash, dbvc_post_history, _some_private_* , /_secret_.+/'); ?>"><?php
                                                                                                                                                    echo esc_textarea($mask_defaults_meta);
                                                                                                                                                    ?></textarea><br>
                  <small><?php esc_html_e('Comma or newline separated. Supports wildcards (*, ?) and regex wrapped with /.../.', 'dbvc'); ?></small>
                </p>

                <p>
                  <label for="dbvc_custom_remove_subkeys"><strong><?php esc_html_e('Mask/remove nested sub-keys (by dot-path or leaf key)', 'dbvc'); ?></strong></label><br>
                  <textarea name="dbvc_custom_remove_subkeys" id="dbvc_custom_remove_subkeys" rows="4" style="width:100%;"
                    placeholder="<?php echo esc_attr(implode('\n', [
                                    '_bricks_page_content_2.*.settings.signature',
                                    '_bricks_page_content_2.*.settings.time',
                                    'signature',
                                    '/^.*\\.extrasCustomQueryCode$/'
                                  ])); ?>"><?php
                                            echo esc_textarea($mask_defaults_sub);
                                            ?></textarea><br>
                  <small><?php esc_html_e('One per line or comma separated. Match full dot-path (e.g. metaKey.*.path.leaf) or just a leaf key name. Wildcards and /regex/ supported.', 'dbvc'); ?></small>
                </p>
              </div>

              <!-- Mode 4 inputs (classic redact mode) -->
              <div id="dbvc-mask-mode-4" style="display:none;margin-left:1rem;">
                <p>
                  <label for="dbvc_redact_meta_keys"><strong><?php esc_html_e('Redact these whole postmeta keys', 'dbvc'); ?></strong></label><br>
                  <textarea name="dbvc_redact_meta_keys" id="dbvc_redact_meta_keys" rows="3" style="width:100%;"
                    placeholder="<?php echo esc_attr('_wp_old_date, _dbvc_import_hash, dbvc_post_history, _some_private_* , /_secret_.+/'); ?>"></textarea><br>
                  <small><?php esc_html_e('Comma or newline separated. Supports wildcards (*, ?) and regex wrapped with /.../.', 'dbvc'); ?></small>
                </p>

                <p>
                  <label for="dbvc_redact_subkeys"><strong><?php esc_html_e('Redact nested sub-keys (by dot-path or leaf key)', 'dbvc'); ?></strong></label><br>
                  <textarea name="dbvc_redact_subkeys" id="dbvc_redact_subkeys" rows="4" style="width:100%;"
                    placeholder="<?php echo esc_attr(implode('\n', [
                                    '_bricks_page_content_2.*.settings.signature',
                                    '_bricks_page_content_2.*.settings.time',
                                    'signature',
                                    '/^.*\\.extrasCustomQueryCode$/'
                                  ])); ?>"></textarea><br>
                  <small><?php esc_html_e('One per line or comma separated. Match full dot-path (e.g. metaKey.*.path.leaf) or just a leaf key name. Wildcards and /regex/ supported.', 'dbvc'); ?></small>
                </p>

                <p id="dbvc-mask-placeholder-wrap-4" style="margin-left:.25rem;">
                  <label for="dbvc_redact_placeholder"><?php esc_html_e('Redaction placeholder', 'dbvc'); ?></label><br>
                  <input type="text" name="dbvc_redact_placeholder" id="dbvc_redact_placeholder"
                    value="<?php echo esc_attr($mask_placeholder_d); ?>" style="width:240px;" />
                </p>
              </div>

              <script>
                (function() {
                  const radios = document.querySelectorAll('#dbvc-mask-mode-wrap input[name="dbvc_export_mask_mode"]');
                  const mode3 = document.getElementById('dbvc-mask-mode-3');
                  const mode4 = document.getElementById('dbvc-mask-mode-4');

                  function sync() {
                    const val = (document.querySelector('#dbvc-mask-mode-wrap input[name="dbvc_export_mask_mode"]:checked') || {}).value;
                    if (!val) return;
                    mode3.style.display = (val === 'remove_customize') ? '' : 'none';
                    mode4.style.display = (val === 'redact_custom') ? '' : 'none';
                  }
                  radios.forEach(r => r.addEventListener('change', sync));
                  sync();
                })();
              </script>

              <br>

              <?php submit_button(esc_html__('Run Full Export', 'dbvc')); ?>
            </form>
            </section>

            <section id="dbvc-export-download" class="dbvc-subtab-panel<?php echo $active_export_subtab === 'dbvc-export-download' ? ' is-active' : ''; ?>" data-dbvc-subpanel="dbvc-export-download" role="tabpanel" aria-labelledby="dbvc-nav-dbvc-export-download" <?php echo $active_export_subtab === 'dbvc-export-download' ? '' : 'hidden'; ?>>
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
            </section>
        </div>
      </section>


<!-- Tab 3: Configure -->
<section id="tab-config" class="dbvc-tab-panel<?php echo $active_main_tab === 'tab-config' ? ' is-active' : ''; ?>" data-dbvc-panel="tab-config" role="tabpanel" aria-labelledby="dbvc-nav-tab-config" <?php echo $active_main_tab === 'tab-config' ? '' : 'hidden'; ?>>
  <form method="post" id="dbvc-config-form">
    <?php wp_nonce_field('dbvc_config_save_action', 'dbvc_config_nonce'); ?>
    <div class="dbvc-subtabs" data-dbvc-subtabs>
      <nav class="dbvc-subtabs-nav" role="tablist" aria-label="<?php esc_attr_e('Configure subsections', 'dbvc'); ?>">
<?php foreach ($config_subtabs as $panel_id => $label) :
  $button_id = 'dbvc-nav-' . $panel_id;
  $is_active = ($active_config_subtab === $panel_id);
?>
        <button type="button"
          id="<?php echo esc_attr($button_id); ?>"
          class="dbvc-subtabs-nav__item<?php echo $is_active ? ' is-active' : ''; ?>"
          data-dbvc-subtab="<?php echo esc_attr($panel_id); ?>"
          role="tab"
          aria-controls="<?php echo esc_attr($panel_id); ?>"
          aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
          <?php echo esc_html($label); ?>
        </button>
<?php endforeach; ?>
      </nav>

      <div class="dbvc-subtabs-panels">
        <section id="dbvc-config-post-types" class="dbvc-subtab-panel<?php echo $active_config_subtab === 'dbvc-config-post-types' ? ' is-active' : ''; ?>" data-dbvc-subpanel="dbvc-config-post-types" role="tabpanel" aria-labelledby="dbvc-nav-dbvc-config-post-types" <?php echo $active_config_subtab === 'dbvc-config-post-types' ? '' : 'hidden'; ?>>
          <?php $render_config_feedback($config_feedback['post_types']); ?>
          <h2><?php esc_html_e('Post Types to Export/Import', 'dbvc'); ?></h2>
          <p><?php esc_html_e('Select which post types should be included.', 'dbvc'); ?></p>

          <select name="dbvc_post_types[]" multiple style="width:100%; height: 200px;" id="dbvc-post-types-select">
            <?php foreach ($all_post_types as $pt => $obj) : ?>
              <option value="<?php echo esc_attr($pt); ?>" <?php selected(in_array($pt, $selected_post_types, true)); ?>>
                <?php echo esc_html($obj->label); ?> (<?php echo esc_html($pt); ?>)
              </option>
            <?php endforeach; ?>
          </select>

          <p style="text-align:left;">
            <a href="#" id="dbvc-select-all"><?php esc_html_e('Select All', 'dbvc'); ?></a> |
            <a href="#" id="dbvc-deselect-all"><?php esc_html_e('Deselect All', 'dbvc'); ?></a>
          </p>

          <?php submit_button(esc_html__('Save Post Types', 'dbvc'), 'secondary', 'dbvc_config_save[post_types]', false); ?>
        </section>

      <section id="dbvc-config-taxonomies" class="dbvc-subtab-panel<?php echo $active_config_subtab === 'dbvc-config-taxonomies' ? ' is-active' : ''; ?>" data-dbvc-subpanel="dbvc-config-taxonomies" role="tabpanel" aria-labelledby="dbvc-nav-dbvc-config-taxonomies" <?php echo $active_config_subtab === 'dbvc-config-taxonomies' ? '' : 'hidden'; ?>>
        <?php $render_config_feedback($config_feedback['taxonomies']); ?>
        <h2><?php esc_html_e('Taxonomies to Export/Import', 'dbvc'); ?></h2>
        <p><?php esc_html_e('Select which taxonomies should be synchronized alongside posts.', 'dbvc'); ?></p>

        <select name="dbvc_taxonomies[]" multiple style="width:100%; height: 200px;" id="dbvc-taxonomies-select">
          <?php foreach (dbvc_get_available_taxonomies() as $tax => $obj) : ?>
            <option value="<?php echo esc_attr($tax); ?>" <?php selected(in_array($tax, $selected_taxonomies, true)); ?>>
              <?php echo esc_html($obj->labels->name); ?> (<?php echo esc_html($tax); ?>)
            </option>
          <?php endforeach; ?>
        </select>

        <p style="text-align:left;">
          <a href="#" id="dbvc-tax-select-all"><?php esc_html_e('Select All', 'dbvc'); ?></a> |
          <a href="#" id="dbvc-tax-deselect-all"><?php esc_html_e('Deselect All', 'dbvc'); ?></a>
        </p>

        <p>
          <label>
            <input type="checkbox" name="dbvc_tax_export_meta" value="1" <?php checked($taxonomy_include_meta, '1'); ?> />
            <?php esc_html_e('Include term meta during export/import', 'dbvc'); ?>
          </label>
        </p>

        <p>
          <label>
            <input type="checkbox" name="dbvc_tax_export_parent_slugs" value="1" <?php checked($taxonomy_include_parent, '1'); ?> />
            <?php esc_html_e('Store parent relationships using term slugs (fallback to IDs if needed)', 'dbvc'); ?>
          </label>
        </p>

        <fieldset style="margin:1rem 0;">
          <legend><strong><?php esc_html_e('Taxonomy Filename Format', 'dbvc'); ?></strong></legend>
          <p class="description"><?php esc_html_e('Controls how term JSON files are named for diffing across environments.', 'dbvc'); ?></p>
          <label style="display:block;margin:.25rem 0;">
            <input type="radio" name="dbvc_taxonomy_filename_format" value="id" <?php checked($taxonomy_filename_mode, 'id'); ?> />
            <?php esc_html_e('Use term ID (e.g., taxonomy-123.json)', 'dbvc'); ?>
          </label>
          <label style="display:block;margin:.25rem 0;">
            <input type="radio" name="dbvc_taxonomy_filename_format" value="slug" <?php checked($taxonomy_filename_mode, 'slug'); ?> />
            <?php esc_html_e('Use term slug when available (e.g., taxonomy-summer-sale.json)', 'dbvc'); ?>
          </label>
          <label style="display:block;margin:.25rem 0;">
            <input type="radio" name="dbvc_taxonomy_filename_format" value="slug_id" <?php checked($taxonomy_filename_mode, 'slug_id'); ?> />
            <?php esc_html_e('Use slug and ID (e.g., taxonomy-summer-sale-123.json)', 'dbvc'); ?>
          </label>
        </fieldset>

        <?php submit_button(esc_html__('Save Taxonomy Settings', 'dbvc'), 'secondary', 'dbvc_config_save[taxonomies]', false); ?>
      </section>

      <section id="dbvc-config-masking" class="dbvc-subtab-panel<?php echo $active_config_subtab === 'dbvc-config-masking' ? ' is-active' : ''; ?>" data-dbvc-subpanel="dbvc-config-masking" role="tabpanel" aria-labelledby="dbvc-nav-dbvc-config-masking" <?php echo $active_config_subtab === 'dbvc-config-masking' ? '' : 'hidden'; ?>>
        <?php $render_config_feedback($config_feedback['masking']); ?>
        <h2><?php esc_html_e('Export Masking Defaults', 'dbvc'); ?></h2>
        <p><?php esc_html_e('Set the default lists used when exporting with Masking Defaults (Options 2 & 3 on the Export tab).', 'dbvc'); ?></p>

        <p>
          <label for="dbvc_mask_defaults_meta_keys"><strong><?php esc_html_e('Default postmeta keys to remove/redact', 'dbvc'); ?></strong></label><br>
          <textarea name="dbvc_mask_defaults_meta_keys" id="dbvc_mask_defaults_meta_keys" rows="3" style="width:100%;"
            placeholder="<?php echo esc_attr('_wp_old_date, _dbvc_import_hash, dbvc_post_history, _some_private_* , /_secret_.+/'); ?>"><?php
                                                                                                                                    echo esc_textarea($existing_defaults_meta);
                                                                                                                                    ?></textarea><br>
          <small><?php esc_html_e('Comma or newline separated. Supports wildcards (*, ?) and regex wrapped with /.../.', 'dbvc'); ?></small>
        </p>

        <p>
          <label for="dbvc_mask_defaults_subkeys"><strong><?php esc_html_e('Default nested sub-keys (by dot-path or leaf key)', 'dbvc'); ?></strong></label><br>
          <textarea name="dbvc_mask_defaults_subkeys" id="dbvc_mask_defaults_subkeys" rows="4" style="width:100%;"
            placeholder="<?php echo esc_attr(implode('\n', [
                            '_bricks_page_content_2.*.settings.signature',
                            '_bricks_page_content_2.*.settings.time',
                            'signature',
                            '/^.*\.extrasCustomQueryCode$/'
                          ])); ?>"><?php
                                echo esc_textarea($existing_defaults_sub);
                                ?></textarea><br>
          <small><?php esc_html_e('One per line or comma separated. Match full dot-path (e.g. metaKey.*.path.leaf) or just a leaf key name. Wildcards and /regex/ supported.', 'dbvc'); ?></small>
        </p>

        <fieldset id="dbvc-auto-mask-mode-wrap" style="margin-bottom:1rem;">
          <legend><strong><?php esc_html_e('Automatic Export Masking', 'dbvc'); ?></strong></legend>
          <p class="description" style="margin-top:0;"><?php esc_html_e('Controls how DBVC handles background exports triggered by post saves or meta updates.', 'dbvc'); ?></p>
          <label style="display:block;margin:.25rem 0;">
            <input type="radio" name="dbvc_auto_export_mask_mode" value="none" <?php checked($auto_export_mask_mode, 'none'); ?> />
            <?php esc_html_e('Do not mask automatic exports', 'dbvc'); ?>
          </label>
          <label style="display:block;margin:.25rem 0;">
            <input type="radio" name="dbvc_auto_export_mask_mode" value="remove_defaults" <?php checked($auto_export_mask_mode, 'remove_defaults'); ?> />
            <?php esc_html_e('Remove Masking Defaults during automatic exports', 'dbvc'); ?>
          </label>
          <label style="display:block;margin:.25rem 0;">
            <input type="radio" name="dbvc_auto_export_mask_mode" value="redact_defaults" <?php checked($auto_export_mask_mode, 'redact_defaults'); ?> />
            <?php esc_html_e('Redact Masking Defaults during automatic exports', 'dbvc'); ?>
          </label>
          <p>
            <label for="dbvc_auto_export_mask_placeholder"><strong><?php esc_html_e('Redaction placeholder', 'dbvc'); ?></strong></label><br>
            <input type="text" name="dbvc_auto_export_mask_placeholder" id="dbvc_auto_export_mask_placeholder" class="regular-text"
              value="<?php echo esc_attr($auto_export_mask_placeholder); ?>" />
            <br><small><?php esc_html_e('Only used when automatic exports are set to redact defaults.', 'dbvc'); ?></small>
          </p>
        </fieldset>

        <?php submit_button(__('Save Masking Defaults', 'dbvc'), 'secondary', 'dbvc_config_save[masking]', false); ?>
      </section>

      <section id="dbvc-config-import" class="dbvc-subtab-panel<?php echo $active_config_subtab === 'dbvc-config-import' ? ' is-active' : ''; ?>" data-dbvc-subpanel="dbvc-config-import" role="tabpanel" aria-labelledby="dbvc-nav-dbvc-config-import" <?php echo $active_config_subtab === 'dbvc-config-import' ? '' : 'hidden'; ?>>
        <?php $render_config_feedback($config_feedback['import']); ?>
        <h2><?php esc_html_e('Custom Sync Folder Path', 'dbvc'); ?></h2>
        <p><?php esc_html_e('Enter the path where JSON files should be saved.', 'dbvc'); ?></p>
        <input type="text" name="dbvc_sync_path" value="<?php echo esc_attr($custom_path); ?>" style="width:100%;"
          placeholder="<?php esc_attr_e('e.g., wp-content/plugins/db-version-control/sync/', 'dbvc'); ?>" /><br><br>
        <strong><?php esc_html_e('Resolved path:', 'dbvc'); ?></strong> <code><?php echo esc_html($resolved_path); ?></code><br><br>

        <hr />

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
          <input type="text" name="dbvc_mirror_domain" id="dbvc_mirror_domain"
            value="<?php echo esc_attr(get_option('dbvc_mirror_domain', '')); ?>"
            style="width:100%;" placeholder="e.g., https://staging.example.com" />
          <small><?php esc_html_e('Any URLs containing this domain will be replaced with the current site domain during import.', 'dbvc'); ?></small>
        </p>

        <p style="margin-top:.5rem;">
          <label>
            <input type="checkbox" name="dbvc_export_use_mirror_domain" value="1" <?php checked(get_option('dbvc_export_use_mirror_domain'), '1'); ?> />
            <?php esc_html_e('Replace current site domain with the Mirror Domain during export', 'dbvc'); ?>
          </label>
          <br><small><?php esc_html_e('When enabled, URLs that start with the current site domain will be rewritten to the Mirror Domain in exported content and meta.', 'dbvc'); ?></small>
        </p>

        <?php submit_button(__('Save Import Settings', 'dbvc'), 'secondary', 'dbvc_config_save[import]', false); ?>
      </section>
      </form>

      <section id="dbvc-config-tools" class="dbvc-subtab-panel<?php echo $active_config_subtab === 'dbvc-config-tools' ? ' is-active' : ''; ?>" data-dbvc-subpanel="dbvc-config-tools" role="tabpanel" aria-labelledby="dbvc-nav-dbvc-config-tools" <?php echo $active_config_subtab === 'dbvc-config-tools' ? '' : 'hidden'; ?>>
        <div class="dbvc-tools-panel">
    <form method="post">
      <?php wp_nonce_field('dbvc_bricks_check_action', 'dbvc_bricks_check_nonce'); ?>
      <h2><?php esc_html_e('Temp Bricks Check', 'dbvc'); ?></h2>
      <p><?php esc_html_e('Diagnose which Bricks Templates will export and why (status, caps, filters, path).', 'dbvc'); ?></p>
      <?php submit_button(__('Run Bricks Check', 'dbvc'), 'secondary', 'dbvc_bricks_check_button'); ?>
    </form>

    <?php
    if (isset($_POST['dbvc_bricks_check_button']) && wp_verify_nonce($_POST['dbvc_bricks_check_nonce'], 'dbvc_bricks_check_action')) {
      $active_main_tab      = 'tab-config';
      $active_config_subtab = 'dbvc-config-tools';
      if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
      }

      $ids = get_posts([
        'post_type'        => 'bricks_template',
        'post_status'      => 'any',
        'fields'           => 'ids',
        'nopaging'         => true,
        'suppress_filters' => false,
      ]);

      $compute_file_path = function ($post) {
        $path = function_exists('dbvc_get_sync_path') ? dbvc_get_sync_path($post->post_type) : WP_CONTENT_DIR . '/dbvc-sync/' . $post->post_type . '/';
        $components = DBVC_Sync_Posts::resolve_filename_components($post->ID, $post, null, false);
        $file_path = trailingslashit($path) . $components['filename'];
        $file_path = apply_filters('dbvc_export_post_file_path', $file_path, $post->ID, $post);
        return $file_path;
      };

      $supported = method_exists('DBVC_Sync_Posts', 'get_supported_post_types')
        ? DBVC_Sync_Posts::get_supported_post_types()
        : array_keys(get_post_types([], 'names'));

      $rows = [];
      foreach ($ids as $id) {
        $p = get_post($id);
        if (! $p) {
          continue;
        }

        $reason   = 'pass';
        $details  = [];
        $allowed  = apply_filters('dbvc_allowed_statuses_for_export', ['publish'], $p);
        $is_supported = in_array($p->post_type, $supported, true);
        $details[] = 'allowed_statuses=' . implode(',', (array) $allowed);
        $details[] = 'supported_types?=' . ($is_supported ? 'yes' : 'no');

        if (! $is_supported) {
          $reason = 'unsupported_type';
        }

        if ($reason === 'pass' && ! in_array($p->post_status, $allowed, true)) {
          $reason = 'disallowed_status_' . $p->post_status;
        }

        if ($reason === 'pass') {
          $pto = get_post_type_object($p->post_type);
          $skip_caps = apply_filters('dbvc_skip_read_cap_check', false, $id, $p);
          if ($pto && (! defined('WP_CLI') || ! WP_CLI) && ! $skip_caps) {
            if (! current_user_can($pto->cap->read_post, $id)) {
              $reason = 'read_cap_fail';
            }
          }
        }

        if ($reason === 'pass') {
          $should_export = apply_filters('dbvc_should_export_post', true, $id, $p);
          if (! $should_export) {
            $reason = 'filtered_out_by_dbvc_should_export_post';
          }
        }

        $file_path = $compute_file_path($p);
        $path_ok = function_exists('dbvc_is_safe_file_path') ? dbvc_is_safe_file_path($file_path) : true;
        $dir = trailingslashit(dirname($file_path));
        $dir_exists = is_dir($dir);
        $dir_writable = $dir_exists ? is_writable($dir) : false;
        $exists = file_exists($file_path);

        if ($reason === 'pass' && ! $path_ok) {
          $reason = 'unsafe_file_path';
        } elseif ($reason === 'pass' && $dir_exists && ! $dir_writable) {
          $reason = 'dir_not_writable';
        }

        $rows[] = [
          'ID'             => $id,
          'Title'          => get_the_title($id),
          'Status'         => $p->post_status,
          'Modified (GMT)' => $p->post_modified_gmt,
          'Supported?'     => $is_supported ? 'yes' : 'no',
          'Reason'         => $reason,
          'File Path'      => $file_path,
          'Dir Exists?'    => $dir_exists ? 'yes' : 'no',
          'Dir Writable?'  => $dir_writable ? 'yes' : 'no',
          'File Exists?'   => $exists ? 'yes' : 'no',
          'Notes'          => implode(' | ', $details),
        ];
      }

      usort($rows, function ($a, $b) {
        if ($a['Reason'] === $b['Reason']) {
          return 0;
        }
        if ($a['Reason'] === 'pass') {
          return 1;
        }
        if ($b['Reason'] === 'pass') {
          return -1;
        }
        return strcmp($a['Reason'], $b['Reason']);
      });

      echo '<div class="notice notice-info" style="padding:12px 12px 0;"><p><strong>Bricks Template Export Check</strong></p>';
      echo '<table class="widefat striped"><thead><tr>';
      $headers = ['ID', 'Title', 'Status', 'Modified (GMT)', 'Supported?', 'Reason', 'File Path', 'Dir Exists?', 'Dir Writable?', 'File Exists?', 'Notes'];
      foreach ($headers as $h) {
        echo '<th>' . esc_html($h) . '</th>';
      }
      echo '</tr></thead><tbody>';
      foreach ($rows as $r) {
        echo '<tr>';
        foreach ($headers as $h) {
          $v = isset($r[$h]) ? $r[$h] : '';
          echo '<td style="vertical-align:top;">' . (in_array($h, ['ID', 'File Path'], true) ? '<code>' . esc_html($v) . '</code>' : esc_html($v)) . '</td>';
        }
        echo '</tr>';
      }
      echo '</tbody></table></div>';

      $skipped = array_filter($rows, fn($r) => $r['Reason'] !== 'pass');
      if ($skipped) {
        error_log('[DBVC] bricks_template skipped: ' . print_r(array_map(fn($r) => [$r['ID'] => $r['Reason']], $skipped), true));
      }
    }
    ?>

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
          <?php esc_html_e('Delete all JSON files inside the sync folder', 'dbvc'); ?>
        </label>
      </p>
      <p style="margin-bottom:0.5em;">
        <label>
          <input type="checkbox" name="dbvc_purge_download_then_delete" value="1" />
          <?php esc_html_e('Download zip then delete all JSON files', 'dbvc'); ?>
        </label>
      </p>
      <p style="margin-bottom:0.5em;">
        <label>
          <input type="checkbox" name="dbvc_purge_delete_root" value="1" />
          <?php esc_html_e('Also delete the sync folder itself', 'dbvc'); ?>
        </label>
      </p>
      <p style="margin-bottom:0.5em;">
        <label>
          <?php esc_html_e('Type DELETE to confirm:', 'dbvc'); ?><br>
          <input type="text" name="dbvc_purge_confirm" value="" style="width:160px;" />
        </label>
      </p>
      <?php submit_button(__('Execute', 'dbvc'), 'delete', 'dbvc_purge_sync_submit'); ?>
    </form>
        </div>
      </section>
    </div>
  </div>
</section>
      </div>
    </div>
  </div><!-- .wrap -->

  <style>
    .dbvc-admin-layout { display:flex; gap:2rem; align-items:flex-start; }
    .dbvc-main-nav { flex:0 0 220px; display:flex; flex-direction:column; gap:0.5rem; margin:0; padding:0; }
    .dbvc-main-nav__item { display:block; width:100%; text-align:left; border:1px solid #dcdcde; border-radius:4px; padding:0.6rem 0.8rem; background:#fff; font-weight:600; cursor:pointer; transition:box-shadow .15s ease, border-color .15s ease; }
    .dbvc-main-nav__item:hover,
    .dbvc-main-nav__item:focus { border-color:#2271b1; color:#2271b1; outline:0; box-shadow:0 0 0 1px #2271b1; }
    .dbvc-main-nav__item.is-active { background:#f0f6fc; border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; color:#1d2327; }
    .dbvc-main-panels { flex:1; min-width:0; }
    .dbvc-tab-panel[hidden] { display:none; }
    .dbvc-subtabs { display:flex; gap:1.5rem; align-items:flex-start; }
    .dbvc-subtabs-nav { flex:0 0 220px; display:flex; flex-direction:column; gap:0.5rem; margin:0; padding:0; }
    .dbvc-subtabs-nav__item { display:block; width:100%; text-align:left; border:1px solid #dcdcde; border-radius:4px; padding:0.55rem 0.75rem; background:#f6f7f7; font-weight:600; cursor:pointer; transition:box-shadow .15s ease, border-color .15s ease; }
    .dbvc-subtabs-nav__item:hover,
    .dbvc-subtabs-nav__item:focus { border-color:#2271b1; color:#2271b1; outline:0; box-shadow:0 0 0 1px #2271b1; }
    .dbvc-subtabs-nav__item.is-active { background:#fff; border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; color:#1d2327; }
    .dbvc-subtabs-panels { flex:1; min-width:0; }
    .dbvc-subtab-panel[hidden] { display:none; }
    .dbvc-config-feedback { margin:0 0 1rem; }
    .dbvc-config-feedback .notice { margin:0 0 .75rem; }
    .dbvc-tools-panel form { margin-bottom:2rem; }
    .dbvc-tools-panel table { margin-top:1rem; }
    @media (max-width:782px) {
      .dbvc-admin-layout,
      .dbvc-subtabs { flex-direction:column; }
      .dbvc-main-nav,
      .dbvc-subtabs-nav { flex:0 0 auto; flex-direction:row; flex-wrap:wrap; gap:0.5rem; }
      .dbvc-main-nav__item,
      .dbvc-subtabs-nav__item { flex:1 1 160px; }
    }
  </style>

  <script>
    (function() {
      function updateHash(id) {
        if (!id) return;
        if (window.location.hash === '#' + id) {
          return;
        }
        if (history.replaceState) {
          history.replaceState(null, '', '#' + id);
        } else {
          window.location.hash = '#' + id;
        }
      }

      function initMainTabs(container) {
        const buttons = Array.from(container.querySelectorAll('[data-dbvc-tab]'));
        const panels  = Array.from(container.querySelectorAll('[data-dbvc-panel]'));
        if (!buttons.length || !panels.length) {
          return null;
        }

        function activate(panelId, opts) {
          if (!panelId) return;
          const options = opts || {};
          let matched = false;
          buttons.forEach(function(button) {
            const isActive = button.getAttribute('data-dbvc-tab') === panelId;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            button.setAttribute('tabindex', isActive ? '0' : '-1');
            if (isActive) {
              matched = true;
            }
          });

          panels.forEach(function(panel) {
            const isActive = panel.getAttribute('data-dbvc-panel') === panelId;
            panel.classList.toggle('is-active', isActive);
            if (isActive) {
              panel.removeAttribute('hidden');
            } else {
              panel.setAttribute('hidden', 'hidden');
            }
          });

          if (matched && options.updateHash !== false) {
            updateHash(panelId);
          }
        }

        buttons.forEach(function(button) {
          button.addEventListener('click', function() {
            activate(button.getAttribute('data-dbvc-tab'));
          });
        });

        const initial = buttons.find(function(btn) {
          return btn.classList.contains('is-active');
        }) || buttons[0];
        if (initial) {
          activate(initial.getAttribute('data-dbvc-tab'), { updateHash: false });
        }

        return { activate: activate };
      }

      function initSubtabs(container) {
        const buttons = Array.from(container.querySelectorAll('[data-dbvc-subtab]'));
        const panels  = Array.from(container.querySelectorAll('[data-dbvc-subpanel]'));
        if (!buttons.length || !panels.length) {
          return null;
        }

        function activate(panelId, opts) {
          if (!panelId) return;
          const options = opts || {};
          let matched = false;
          buttons.forEach(function(button) {
            const isActive = button.getAttribute('data-dbvc-subtab') === panelId;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-selected', isActive ? 'true' : 'false');
            button.setAttribute('tabindex', isActive ? '0' : '-1');
            if (isActive) {
              matched = true;
            }
          });

          panels.forEach(function(panel) {
            const isActive = panel.getAttribute('data-dbvc-subpanel') === panelId;
            panel.classList.toggle('is-active', isActive);
            if (isActive) {
              panel.removeAttribute('hidden');
            } else {
              panel.setAttribute('hidden', 'hidden');
            }
          });

          if (matched && options.updateHash !== false) {
            updateHash(panelId);
          }
        }

        buttons.forEach(function(button) {
          button.addEventListener('click', function() {
            activate(button.getAttribute('data-dbvc-subtab'));
          });
        });

        const initial = buttons.find(function(btn) {
          return btn.classList.contains('is-active');
        }) || buttons[0];
        if (initial) {
          activate(initial.getAttribute('data-dbvc-subtab'), { updateHash: false });
        }

        return { activate: activate, panels: panels };
      }

      const mainContainer = document.querySelector('.dbvc-admin-layout[data-dbvc-tabs]');
      const mainApi = mainContainer ? initMainTabs(mainContainer) : null;

      const subtabMap = new Map();
      document.querySelectorAll('.dbvc-subtabs[data-dbvc-subtabs]').forEach(function(container) {
        const api = initSubtabs(container);
        if (!api) {
          return;
        }
        api.panels.forEach(function(panel) {
          const id = panel.getAttribute('data-dbvc-subpanel');
          if (id) {
            subtabMap.set(id, { api: api, container: container });
          }
        });
      });

      function activateFromHash(hash) {
        if (!hash) return;
        const id = hash.replace('#', '');
        if (!id) return;

        const panel = mainContainer ? mainContainer.querySelector('[data-dbvc-panel="' + id + '"]') : null;
        if (panel && mainApi) {
          mainApi.activate(id, { updateHash: false });
          return;
        }

        const sub = subtabMap.get(id);
        if (sub) {
          const parentPanel = sub.container.closest('[data-dbvc-panel]');
          if (parentPanel && mainApi) {
            mainApi.activate(parentPanel.getAttribute('data-dbvc-panel'), { updateHash: false });
          }
          sub.api.activate(id, { updateHash: false });
        }
      }

      activateFromHash(window.location.hash);
      window.addEventListener('hashchange', function() {
        activateFromHash(window.location.hash);
      });
    })();

    jQuery(function($) {
      const $selectBox = $('#dbvc-post-types-select');
      if ($selectBox.length) {
        $selectBox.select2({
          placeholder: <?php echo wp_json_encode(esc_html__('Select post types…', 'dbvc')); ?>,
          allowClear: true,
          width: '100%'
        });

        $('#dbvc-select-all').on('click', function(event) {
          event.preventDefault();
          $selectBox.find('option').prop('selected', true);
          $selectBox.trigger('change');
        });

        $('#dbvc-deselect-all').on('click', function(event) {
          event.preventDefault();
          $selectBox.find('option').prop('selected', false);
          $selectBox.trigger('change');
        });
      }

      const $taxSelect = $('#dbvc-taxonomies-select');
      if ($taxSelect.length) {
        $taxSelect.select2({
          placeholder: <?php echo wp_json_encode(esc_html__('Select taxonomies…', 'dbvc')); ?>,
          allowClear: true,
          width: '100%'
        });

        $('#dbvc-tax-select-all').on('click', function(event) {
          event.preventDefault();
          $taxSelect.find('option').prop('selected', true);
          $taxSelect.trigger('change');
        });

        $('#dbvc-tax-deselect-all').on('click', function(event) {
          event.preventDefault();
          $taxSelect.find('option').prop('selected', false);
          $taxSelect.trigger('change');
        });
      }
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
