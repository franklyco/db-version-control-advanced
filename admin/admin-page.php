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

  echo '<div id="dbvc-admin-app-root"></div>';

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
  $sort_export_meta = get_option('dbvc_export_sort_meta', '0') === '1';

  $allowed_auto_mask_modes         = ['none', 'remove_defaults', 'redact_defaults'];
  $auto_export_mask_mode           = get_option('dbvc_auto_export_mask_mode', 'none');
  if (! in_array($auto_export_mask_mode, $allowed_auto_mask_modes, true)) {
    $auto_export_mask_mode = 'none';
  }
  $auto_export_mask_placeholder    = (string) get_option(
    'dbvc_auto_export_mask_placeholder',
    get_option('dbvc_mask_placeholder', '***')
  );

  $existing_defaults_meta = (string) get_option('dbvc_mask_defaults_meta_keys', '');
  $existing_defaults_sub  = (string) get_option('dbvc_mask_defaults_subkeys', '');

  $config_feedback = [
    'post_types' => ['success' => [], 'error' => []],
    'taxonomies' => ['success' => [], 'error' => []],
    'masking'    => ['success' => [], 'error' => []],
    'import'     => ['success' => [], 'error' => []],
    'media'      => ['success' => [], 'error' => []],
  ];
  $config_section_mapping = [
    'post_types' => 'dbvc-config-post-types',
    'taxonomies' => 'dbvc-config-taxonomies',
    'masking'    => 'dbvc-config-masking',
    'import'     => 'dbvc-config-import',
    'media'      => 'dbvc-config-media',
  ];
  $config_sections_submitted = [];
  $config_form_was_submitted = false;

  $active_main_tab      = 'tab-import';
  $active_import_subtab = 'dbvc-import-content';
  $active_export_subtab = 'dbvc-export-full';
  $active_config_subtab = 'dbvc-config-post-types';
  $backup_feedback      = ['success' => [], 'error' => []];
  $selected_backup      = isset($_GET['dbvc_backup']) ? sanitize_text_field(wp_unslash($_GET['dbvc_backup'])) : '';
  $selected_backup_page = isset($_GET['dbvc_backup_page']) ? max(1, absint($_GET['dbvc_backup_page'])) : 1;
  $logging_enabled        = get_option(DBVC_Sync_Logger::OPTION_ENABLED, '0');
  $logging_max_size       = DBVC_Sync_Logger::get_max_size();
  $logging_max_size_kb    = max(1, (int) round($logging_max_size / 1024));
  $logging_directory_raw  = (string) get_option(DBVC_Sync_Logger::OPTION_DIRECTORY, '');
  $logging_effective_dir  = DBVC_Sync_Logger::get_log_directory();
  $logging_effective_file = $logging_effective_dir ? trailingslashit($logging_effective_dir) . DBVC_Sync_Logger::LOG_FILENAME : '';
  $logging_import_events  = get_option(DBVC_Sync_Logger::OPTION_IMPORT_EVENTS, '0');
  $logging_term_events    = get_option(DBVC_Sync_Logger::OPTION_TERM_EVENTS, '0');
  $logging_upload_events  = get_option(DBVC_Sync_Logger::OPTION_UPLOAD_EVENTS, '0');
  $logging_media_events   = get_option(DBVC_Sync_Logger::OPTION_MEDIA_EVENTS, '0');
  $media_retrieve_enabled = get_option(DBVC_Media_Sync::OPTION_ENABLED, '0');
  $media_preserve_names   = get_option(DBVC_Media_Sync::OPTION_PRESERVE_NAMES, '1');
  $media_preview_enabled  = get_option(DBVC_Media_Sync::OPTION_PREVIEW_ENABLED, '0');
  $media_allow_external   = get_option(DBVC_Media_Sync::OPTION_ALLOW_EXTERNAL, '0');
  $import_require_review  = get_option('dbvc_import_require_review', '0');
  $force_reapply_new_posts = get_option('dbvc_force_reapply_new_posts', '0');
  $prefer_entity_uids     = get_option('dbvc_prefer_entity_uids', '0');
  $diff_ignore_option     = get_option('dbvc_diff_ignore_paths', null);
  if ($diff_ignore_option === null || $diff_ignore_option === false) {
    $diff_ignore_paths = 'meta.dbvc_post_history.*';
  } else {
    $diff_ignore_paths = (string) $diff_ignore_option;
  }
  $media_clear_url        = '#';
  if (class_exists('DBVC_Media_Sync')) {
    $media_clear_url = wp_nonce_url(
      add_query_arg([
        'action'   => 'dbvc_clear_media_cache',
        'dbvc_tab' => 'tab-config',
      ], admin_url('admin-post.php')),
      'dbvc_clear_media_cache'
    );
  }

  $sync_media_preview_data  = null;
  $sync_media_preview_ready = false;
  $sync_manifest_path       = '';
  if (
    class_exists('DBVC_Media_Sync')
    && class_exists('DBVC_Backup_Manager')
    && function_exists('dbvc_get_sync_path')
  ) {
    $sync_manifest_path = trailingslashit(dbvc_get_sync_path()) . DBVC_Backup_Manager::MANIFEST_FILENAME;
    if (file_exists($sync_manifest_path) && is_readable($sync_manifest_path)) {
      $sync_media_preview_ready = true;
      if ($media_preview_enabled === '1') {
        $manifest_raw  = file_get_contents($sync_manifest_path);
        $manifest_data = json_decode($manifest_raw, true);
        if (is_array($manifest_data)) {
          $sync_media_preview_data = DBVC_Media_Sync::preview_manifest_media($manifest_data, 20);
        }
      }
    }
  }

  if (isset($_GET['dbvc_tab']) && sanitize_key($_GET['dbvc_tab']) === 'tab-backups') {
    $active_main_tab = 'tab-backups';
  }
  if ($selected_backup) {
    $active_main_tab = 'tab-backups';
  }

  if (isset($_POST['dbvc_backup_action']) && isset($_POST['dbvc_backup_nonce']) && wp_verify_nonce(wp_unslash($_POST['dbvc_backup_nonce']), 'dbvc_backup_action')) {
    $active_main_tab = 'tab-backups';
    $action          = sanitize_key($_POST['dbvc_backup_action']);
    $folder          = isset($_POST['dbvc_backup_folder']) ? sanitize_text_field(wp_unslash($_POST['dbvc_backup_folder'])) : '';

    if (! class_exists('DBVC_Backup_Manager')) {
      $backup_feedback['error'][] = esc_html__('Backup manager unavailable.', 'dbvc');
    } elseif ($action === 'toggle_lock') {
      $lock_value = isset($_POST['dbvc_backup_lock']) ? sanitize_text_field(wp_unslash($_POST['dbvc_backup_lock'])) : '0';
      $lock_state = $lock_value === '1';
      DBVC_Backup_Manager::set_lock($folder, $lock_state);
      $backup_feedback['success'][] = $lock_state
        ? esc_html__('Backup locked.', 'dbvc')
        : esc_html__('Backup unlocked.', 'dbvc');
      $selected_backup = $folder;
    } elseif ($action === 'delete_backup') {
      $delete_result = DBVC_Backup_Manager::delete_backup($folder);
      if (is_wp_error($delete_result)) {
        $backup_feedback['error'][] = $delete_result->get_error_message();
      } else {
        $backup_feedback['success'][] = esc_html__('Backup deleted.', 'dbvc');
        if ($selected_backup === $folder) {
          $selected_backup = '';
        }
      }
    } elseif ($action === 'restore_backup') {
      $confirmation = isset($_POST['dbvc_backup_confirm']) ? trim((string) wp_unslash($_POST['dbvc_backup_confirm'])) : '';
      if (strcasecmp($confirmation, 'Restore') !== 0) {
        $backup_feedback['error'][] = esc_html__('Type Restore to confirm.', 'dbvc');
      } else {
        $mode_flags = [
          'partial' => ! empty($_POST['dbvc_backup_mode_partial']),
          'full'    => ! empty($_POST['dbvc_backup_mode_full']),
          'copy'    => ! empty($_POST['dbvc_backup_mode_copy']),
        ];
        $selected_modes = array_filter($mode_flags);
        if (count($selected_modes) !== 1) {
          $backup_feedback['error'][] = esc_html__('Select exactly one restore mode.', 'dbvc');
        } else {
          $mode_key = array_key_first($selected_modes);
          $mode_map = [
            'partial' => 'partial',
            'full'    => 'full',
            'copy'    => 'copy',
          ];
          $restore_mode = $mode_map[$mode_key] ?? 'full';
          $result       = DBVC_Sync_Posts::import_backup($folder, ['mode' => $restore_mode]);
          if (is_wp_error($result)) {
            $backup_feedback['error'][] = $result->get_error_message();
          } else {
            $imported = isset($result['imported']) ? absint($result['imported']) : 0;
            $mode_label = ucfirst($restore_mode);
            $backup_feedback['success'][] = sprintf(
              /* translators: 1: mode, 2: count */
              esc_html__('%1$s restore completed. Items processed: %2$d', 'dbvc'),
              esc_html($mode_label),
              $imported
            );
            if (! empty($result['errors'])) {
              foreach ((array) $result['errors'] as $err) {
                $backup_feedback['error'][] = esc_html($err);
              }
            }
            if (! empty($result['media']) && is_array($result['media'])) {
              $media_summary = [];
              if (! empty($result['media']['downloaded'])) {
                $media_summary[] = sprintf(
                  esc_html__('%d media files downloaded', 'dbvc'),
                  (int) $result['media']['downloaded']
                );
              }
              if (! empty($result['media']['reused'])) {
                $media_summary[] = sprintf(
                  esc_html__('%d media files reused', 'dbvc'),
                  (int) $result['media']['reused']
                );
              }
              if (! empty($media_summary)) {
                $backup_feedback['success'][] = implode(' · ', $media_summary);
              }
              if (! empty($result['media']['errors'])) {
                $backup_feedback['error'][] = sprintf(
                  esc_html__('%d media downloads failed. Check the log for details.', 'dbvc'),
                  (int) $result['media']['errors']
                );
              }
              if (! empty($result['media']['blocked'])) {
                $backup_feedback['error'][] = sprintf(
                  esc_html__('%d media sources were blocked by current download restrictions.', 'dbvc'),
                  (int) $result['media']['blocked']
                );
              }
            }
            $selected_backup = $folder;
          }
        }
      }
    }
  }

  if (isset($_POST['dbvc_logging_action']) && isset($_POST['dbvc_logging_nonce']) && wp_verify_nonce(wp_unslash($_POST['dbvc_logging_nonce']), 'dbvc_logging_action')) {
    $active_main_tab = 'tab-backups';
    $logging_action  = sanitize_key($_POST['dbvc_logging_action']);

    if ($logging_action === 'save_logging') {
      $error_count_before = count($backup_feedback['error']);
      $enabled = isset($_POST['dbvc_logging_enabled']) ? '1' : '0';
      update_option(DBVC_Sync_Logger::OPTION_ENABLED, $enabled);
      $logging_enabled = $enabled;

      $max_size_kb = isset($_POST['dbvc_logging_max_size'])
        ? absint(wp_unslash($_POST['dbvc_logging_max_size']))
        : (int) round(DBVC_Sync_Logger::DEFAULT_MAX_SIZE / 1024);
      if ($max_size_kb < 1) {
        $max_size_kb = (int) round(DBVC_Sync_Logger::DEFAULT_MAX_SIZE / 1024);
      }
      $max_size_input = $max_size_kb * 1024;
      update_option(DBVC_Sync_Logger::OPTION_MAX_SIZE, $max_size_input);
      $logging_max_size    = DBVC_Sync_Logger::get_max_size();
      $logging_max_size_kb = max(1, (int) round($logging_max_size / 1024));

      $log_path_input = isset($_POST['dbvc_logging_path']) ? sanitize_text_field(wp_unslash($_POST['dbvc_logging_path'])) : '';
      $log_option     = '';
      $log_path_error = false;

      if ($log_path_input !== '') {
        if (function_exists('dbvc_validate_sync_path')) {
          $validated = dbvc_validate_sync_path($log_path_input);
          if ($validated === false) {
            $log_path_error = true;
            $backup_feedback['error'][] = esc_html__('Log directory is invalid. Use a path inside wp-content without unsafe characters.', 'dbvc');
          } else {
            $log_option = $validated;
          }
        } else {
          $log_option = $log_path_input;
        }
      }

      if (! $log_path_error) {
        update_option(DBVC_Sync_Logger::OPTION_DIRECTORY, $log_option);
        $logging_directory_raw = $log_option;
      }

      $logging_effective_dir  = DBVC_Sync_Logger::get_log_directory();
      $logging_effective_file = $logging_effective_dir ? trailingslashit($logging_effective_dir) . DBVC_Sync_Logger::LOG_FILENAME : '';

      if ($error_count_before === count($backup_feedback['error'])) {
        $backup_feedback['success'][] = esc_html__('Logging settings updated.', 'dbvc');
      }
    } elseif ($logging_action === 'delete_log') {
      if (DBVC_Sync_Logger::delete_log()) {
        $backup_feedback['success'][] = esc_html__('Log file deleted.', 'dbvc');
      } else {
        $backup_feedback['error'][] = esc_html__('Unable to delete log file.', 'dbvc');
      }

      $logging_effective_dir  = DBVC_Sync_Logger::get_log_directory();
      $logging_effective_file = $logging_effective_dir ? trailingslashit($logging_effective_dir) . DBVC_Sync_Logger::LOG_FILENAME : '';
    }
  }


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

    $auto_clear_decisions = ! empty($_POST['dbvc_auto_clear_decisions']) ? '1' : '0';
    update_option('dbvc_auto_clear_decisions', $auto_clear_decisions);

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

    $prefer_entity_uids = ! empty($_POST['dbvc_prefer_entity_uids']) ? '1' : '0';
    update_option('dbvc_prefer_entity_uids', $prefer_entity_uids);

    if (in_array('media', $config_sections_submitted, true)) {
      $media_retrieve_enabled = ! empty($_POST['dbvc_media_retrieve_enabled']) ? '1' : '0';
      update_option(DBVC_Media_Sync::OPTION_ENABLED, $media_retrieve_enabled);

      $media_preserve_names = ! empty($_POST['dbvc_media_preserve_names']) ? '1' : '0';
      update_option(DBVC_Media_Sync::OPTION_PRESERVE_NAMES, $media_preserve_names);

      $media_preview_enabled = ! empty($_POST['dbvc_media_preview_enabled']) ? '1' : '0';
      update_option(DBVC_Media_Sync::OPTION_PREVIEW_ENABLED, $media_preview_enabled);

      $media_allow_external = ! empty($_POST['dbvc_media_allow_external']) ? '1' : '0';
      update_option(DBVC_Media_Sync::OPTION_ALLOW_EXTERNAL, $media_allow_external);

      $transport_mode = isset($_POST['dbvc_media_transport_mode']) ? sanitize_key($_POST['dbvc_media_transport_mode']) : DBVC_Media_Sync::get_transport_mode();
      if (! in_array($transport_mode, ['auto', 'bundled', 'remote'], true)) {
        $transport_mode = 'auto';
      }
      update_option(DBVC_Media_Sync::OPTION_TRANSPORT_MODE, $transport_mode);

      $bundle_enabled = ! empty($_POST['dbvc_media_bundle_enabled']) ? '1' : '0';
      update_option(DBVC_Media_Sync::OPTION_BUNDLE_ENABLED, $bundle_enabled);

      $bundle_chunk = isset($_POST['dbvc_media_bundle_chunk']) ? absint($_POST['dbvc_media_bundle_chunk']) : DBVC_Media_Sync::get_bundle_chunk_size();
      if ($bundle_chunk < 10) {
        $bundle_chunk = 10;
      }
      update_option(DBVC_Media_Sync::OPTION_BUNDLE_CHUNK, $bundle_chunk);

      $config_feedback['media']['success'][] = esc_html__('Media handling settings saved.', 'dbvc');
    }

    $log_import_runs = ! empty($_POST['dbvc_log_import_runs']) ? '1' : '0';
    update_option(DBVC_Sync_Logger::OPTION_IMPORT_EVENTS, $log_import_runs);
    $logging_import_events = $log_import_runs;

    $log_term_runs = ! empty($_POST['dbvc_log_term_imports']) ? '1' : '0';
    update_option(DBVC_Sync_Logger::OPTION_TERM_EVENTS, $log_term_runs);
    $logging_term_events = $log_term_runs;

    $log_upload_runs = ! empty($_POST['dbvc_log_sync_uploads']) ? '1' : '0';
    update_option(DBVC_Sync_Logger::OPTION_UPLOAD_EVENTS, $log_upload_runs);
    $logging_upload_events = $log_upload_runs;

    $log_media_runs = ! empty($_POST['dbvc_log_media_sync']) ? '1' : '0';
    update_option(DBVC_Sync_Logger::OPTION_MEDIA_EVENTS, $log_media_runs);
    $logging_media_events = $log_media_runs;

    $import_review_input = ! empty($_POST['dbvc_import_require_review']) ? '1' : '0';
    update_option('dbvc_import_require_review', $import_review_input);
    $import_require_review = $import_review_input;

    $force_reapply_input = ! empty($_POST['dbvc_force_reapply_new_posts']) ? '1' : '0';
    update_option('dbvc_force_reapply_new_posts', $force_reapply_input);
    $force_reapply_new_posts = $force_reapply_input;

    $diff_ignore_input = isset($_POST['dbvc_diff_ignore_paths'])
        ? sanitize_textarea_field(wp_unslash($_POST['dbvc_diff_ignore_paths']))
        : $diff_ignore_paths;
    update_option('dbvc_diff_ignore_paths', $diff_ignore_input);
    $diff_ignore_paths = $diff_ignore_input;

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
    if (! empty($config_feedback['import']['error'])) {
      $active_config_subtab = 'dbvc-config-import';
    }
  }

  // Handle export form (with Masking Modes / Diff exports)
  if (isset($_POST['dbvc_export_nonce']) && wp_verify_nonce($_POST['dbvc_export_nonce'], 'dbvc_export_action')) {
    $active_main_tab = 'tab-export';
    if (! current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have sufficient permissions to perform this action.', 'dbvc'));
    }

  if (isset($_POST['dbvc_chunk_export_submit'])) {
      $active_export_subtab = 'dbvc-export-snapshots';

      $chunk_size_input = isset($_POST['dbvc_chunk_size']) ? absint($_POST['dbvc_chunk_size']) : 0;
      $chunk_job_id     = isset($_POST['dbvc_chunk_job_id']) ? absint($_POST['dbvc_chunk_job_id']) : null;

      if ($chunk_size_input <= 0) {
        $chunk_feedback_html = '<div class="notice notice-error"><p>' . esc_html__('Chunk size must be greater than zero.', 'dbvc') . '</p></div>';
      } elseif (! class_exists('DBVC_Export_Manager')) {
        $chunk_feedback_html = '<div class="notice notice-error"><p>' . esc_html__('Chunked export manager unavailable.', 'dbvc') . '</p></div>';
      } else {
        $default_chunk_size = $chunk_size_input;
        $chunk_result = DBVC_Export_Manager::run_chunked_export($chunk_size_input, $chunk_job_id ?: null);
        if (is_wp_error($chunk_result)) {
          $chunk_feedback_html = '<div class="notice notice-error"><p>' . esc_html($chunk_result->get_error_message()) . '</p></div>';
        } else {
          $remaining = isset($chunk_result['remaining']) ? (int) $chunk_result['remaining'] : 0;
          $job_id    = isset($chunk_result['job_id']) ? (int) $chunk_result['job_id'] : 0;
          $processed = isset($chunk_result['processed_current']) ? (int) $chunk_result['processed_current'] : 0;

          if ('done' === $chunk_result['status']) {
            $chunk_feedback_html = '<div class="notice notice-success"><p>' . esc_html(sprintf(
              __('Chunked export job %1$d completed. Processed %2$d posts in the final chunk.', 'dbvc'),
              $job_id,
              $processed
            )) . '</p></div>';
          } else {
            $chunk_feedback_html = '<div class="notice notice-info"><p>' . esc_html(sprintf(
              __('Processed chunk for job %1$d. Posts in this chunk: %2$d. Remaining posts: %3$d.', 'dbvc'),
              $job_id,
              $processed,
              $remaining
            )) . '</p></div>';
          }

          // Refresh snapshot and job caches for display
          if (class_exists('DBVC_Database')) {
            $snapshots_history = DBVC_Database::get_snapshots([
              'limit' => 25,
            ]);
            $snapshot_baseline_options = array_filter(
              $snapshots_history,
              static fn($snapshot) => in_array($snapshot->type, ['full_export', 'chunked_export'], true)
            );

            $jobs = DBVC_Database::get_jobs([
              'type'  => 'export_chunked',
              'limit' => 10,
            ]);
            $active_export_jobs = [];
            foreach ($jobs as $job) {
              if (isset($job->status) && 'done' === $job->status) {
                continue;
              }
              $active_export_jobs[] = $job;
            }
          }
        }
      }
    } elseif (isset($_POST['dbvc_diff_export_submit'])) {
      $active_export_subtab = 'dbvc-export-snapshots';

      if (! class_exists('DBVC_Sync_Posts')) {
        echo '<div class="notice notice-error"><p>' . esc_html__('Diff export unavailable: Sync subsystem missing.', 'dbvc') . '</p></div>';
      } else {
        $baseline_raw = isset($_POST['dbvc_diff_baseline']) ? sanitize_text_field(wp_unslash($_POST['dbvc_diff_baseline'])) : 'latest_full';
        $baseline_id  = null;
        if ($baseline_raw && $baseline_raw !== 'latest_full') {
          $baseline_id = absint($baseline_raw);
        }

        $diff_result = DBVC_Sync_Posts::export_posts_diff($baseline_id, dbvc_get_export_filename_format());
        if (is_wp_error($diff_result)) {
          echo '<div class="notice notice-error"><p>' . esc_html($diff_result->get_error_message()) . '</p></div>';
        } else {
          $counts     = $diff_result['counts'] ?? [];
          $created    = isset($counts['created']) ? (int) $counts['created'] : 0;
          $updated    = isset($counts['updated']) ? (int) $counts['updated'] : 0;
          $unchanged  = isset($counts['unchanged']) ? (int) $counts['unchanged'] : 0;
          $snapshot_id = isset($diff_result['snapshot_id']) ? (int) $diff_result['snapshot_id'] : 0;

          echo '<div class="notice notice-success"><p>' . esc_html(sprintf(
            __('Diff export completed. Created: %1$d, Updated: %2$d, Unchanged: %3$d. Snapshot ID: %4$d', 'dbvc'),
            $created,
            $updated,
            $unchanged,
            $snapshot_id
          )) . '</p></div>';
        }
      }
    } else {
      $active_export_subtab = 'dbvc-export-full';

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
      $bundle_checked = ! empty($_POST['dbvc_media_bundle_enabled']);

      // Persist filename format, mirror checkbox, and meta sorting behavior (mirror domain itself is saved in Tab 3)
      update_option('dbvc_export_filename_format', $filename_mode);
      update_option('dbvc_use_slug_in_filenames', $filename_mode === 'id' ? '0' : '1'); // legacy flag
      update_option('dbvc_export_use_mirror_domain', $mirror_checked ? '1' : '0');
      if (class_exists('DBVC_Media_Sync')) {
          update_option(DBVC_Media_Sync::OPTION_BUNDLE_ENABLED, $bundle_checked ? '1' : '0');
      }
      $sort_meta_enabled = ! empty($_POST['dbvc_export_sort_meta']);
      update_option('dbvc_export_sort_meta', $sort_meta_enabled ? '1' : '0');
      $sort_export_meta = $sort_meta_enabled;

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

      $export_totals = [
        'post_types' => $selected,
        'posts'      => 0,
      ];
      $snapshot_items = [];
      $export_time    = current_time('mysql', true);
      $sync_base_path = trailingslashit(dbvc_get_sync_path());

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

                $filename_components = DBVC_Sync_Posts::resolve_filename_components($post_id, $post, $filename_mode);
                $file_path           = trailingslashit(dbvc_get_sync_path($post->post_type)) . $filename_components['filename'];
                if (is_readable($file_path)) {
                  $export_totals['posts']++;
                  $snapshot_items[] = [
                    'object_type'  => 'post',
                    'object_id'    => (int) $post_id,
                    'content_hash' => hash_file('sha256', $file_path),
                    'status'       => 'exported',
                    'payload_path' => ltrim(str_replace($sync_base_path, '', $file_path), '/'),
                    'exported_at'  => $export_time,
                  ];
                }
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

      if (class_exists('DBVC_Backup_Manager')) {
        DBVC_Backup_Manager::generate_manifest(dbvc_get_sync_path());
      }

      if (class_exists('DBVC_Database')) {
        $snapshot_id = DBVC_Database::insert_snapshot([
          'name'         => '',
          'type'         => 'full_export',
          'sync_path'    => dbvc_get_sync_path(),
          'notes'        => wp_json_encode([
            'post_types'      => $export_totals['post_types'],
            'posts_exported'  => $export_totals['posts'],
            'timestamp'       => $export_time,
            'source'          => 'manual',
          ]),
        ]);

        if ($snapshot_id && ! empty($snapshot_items)) {
          DBVC_Database::insert_snapshot_items($snapshot_id, $snapshot_items);
        }

        DBVC_Database::log_activity(
          'full_export_completed',
          'info',
          'Full export completed',
          [
            'snapshot_id'    => $snapshot_id,
            'posts_exported' => $export_totals['posts'],
            'post_types'     => $export_totals['post_types'],
          ]
        );
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

      $download_media = false;
      if (class_exists('DBVC_Media_Sync')) {
        $download_media         = ! empty($_POST['dbvc_import_media']);
        $media_retrieve_enabled = $download_media ? '1' : '0';
        update_option(DBVC_Media_Sync::OPTION_ENABLED, $media_retrieve_enabled);
      }

      $prefer_entity_uids = ! empty($_POST['dbvc_prefer_entity_uids']) ? '1' : '0';
      update_option('dbvc_prefer_entity_uids', $prefer_entity_uids);

      if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
        DBVC_Sync_Logger::log_import('Manual import requested', [
          'smart_import'    => (bool) $smart_import,
          'mode'            => $import_mode,
          'media_requested' => (bool) $download_media,
          'user'            => get_current_user_id(),
        ]);
      }

      $import_result = DBVC_Sync_Posts::import_all(0, $smart_import, $import_mode);

      if ($import_menus) {
        DBVC_Sync_Posts::import_menus_from_json();
      }

      $media_stats_import = null;
      if (
        $download_media
        && class_exists('DBVC_Media_Sync')
        && class_exists('DBVC_Backup_Manager')
        && function_exists('dbvc_get_sync_path')
      ) {
        $manifest_path = trailingslashit(dbvc_get_sync_path()) . DBVC_Backup_Manager::MANIFEST_FILENAME;
        if (file_exists($manifest_path) && is_readable($manifest_path)) {
          $manifest_data = json_decode(file_get_contents($manifest_path), true);
          if (is_array($manifest_data)) {
            $proposal_id = $manifest_data['backup_name'] ?? ($selected_backup ?? 'manual');
            DBVC_Sync_Posts::import_resolver_decisions_from_manifest($manifest_data, sanitize_text_field((string) $proposal_id));
            $media_stats_import = DBVC_Media_Sync::sync_manifest_media($manifest_data, [
              'proposal_id' => $selected_backup ?? 'manual',
              'manifest_dir'=> trailingslashit(dbvc_get_sync_path()),
            ]);
            if ($media_preview_enabled === '1') {
              $sync_media_preview_ready = true;
              $sync_media_preview_data  = DBVC_Media_Sync::preview_manifest_media($manifest_data, 20);
            }
          }
        }
      }

      if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
        DBVC_Sync_Logger::log_import('Manual import completed', [
          'processed'        => isset($import_result['processed']) ? (int) $import_result['processed'] : null,
          'smart_import'     => (bool) $smart_import,
          'mode'             => $import_mode,
          'media_requested'  => (bool) $download_media,
          'media_downloaded' => is_array($media_stats_import) ? (int) ($media_stats_import['downloaded'] ?? 0) : null,
          'media_errors'     => is_array($media_stats_import) ? (int) ($media_stats_import['errors'] ?? 0) : null,
          'user'             => get_current_user_id(),
        ]);
      }

      if (class_exists('DBVC_Database')) {
        $snapshot_id = DBVC_Database::insert_snapshot([
          'type'      => 'manual_import',
          'sync_path' => dbvc_get_sync_path(),
          'notes'     => wp_json_encode([
            'smart_import'   => (bool) $smart_import,
            'import_mode'    => $import_mode,
            'posts_imported' => isset($import_result['processed']) ? (int) $import_result['processed'] : null,
            'media_stats'    => $media_stats_import,
            'timestamp'      => current_time('mysql', true),
            'source'         => 'manual',
          ]),
        ]);

        DBVC_Database::log_activity(
          'manual_import_completed',
          'info',
          'Manual import completed',
          [
            'snapshot_id'    => $snapshot_id,
            'posts_imported' => isset($import_result['processed']) ? (int) $import_result['processed'] : null,
            'smart_import'   => (bool) $smart_import,
            'import_mode'    => $import_mode,
            'media_stats'    => $media_stats_import,
          ]
        );
      }

      echo '<div class="notice notice-success"><p>' . esc_html__('Import completed.', 'dbvc') . '</p></div>';

      if ($media_stats_import && is_array($media_stats_import)) {
        $summary_parts = [];
        if (! empty($media_stats_import['downloaded'])) {
          $summary_parts[] = sprintf(
            esc_html__('%d media downloaded', 'dbvc'),
            (int) $media_stats_import['downloaded']
          );
        }
        if (! empty($media_stats_import['reused'])) {
          $summary_parts[] = sprintf(
            esc_html__('%d media reused', 'dbvc'),
            (int) $media_stats_import['reused']
          );
        }

        $resolver_summary = [];
        $resolver_metrics = isset($media_stats_import['resolver']['metrics']) && is_array($media_stats_import['resolver']['metrics'])
          ? $media_stats_import['resolver']['metrics']
          : null;
        $resolver_conflicts = isset($media_stats_import['resolver']['conflicts']) ? (array) $media_stats_import['resolver']['conflicts'] : [];

        if ($resolver_metrics) {
          if (isset($resolver_metrics['reused'])) {
            $resolver_summary[] = sprintf(
              esc_html__('%d resolved via resolver', 'dbvc'),
              (int) $resolver_metrics['reused']
            );
          }
          if (isset($resolver_metrics['unresolved']) && (int) $resolver_metrics['unresolved'] > 0) {
            $resolver_summary[] = sprintf(
              esc_html__('%d unresolved', 'dbvc'),
              (int) $resolver_metrics['unresolved']
            );
          }
        }
        if (! empty($resolver_conflicts)) {
          $resolver_summary[] = sprintf(
            esc_html__('%d conflicts', 'dbvc'),
            count($resolver_conflicts)
          );
        }

        if (! empty($summary_parts)) {
          echo '<div class="notice notice-info"><p>' . esc_html__('Media sync:', 'dbvc') . ' ' . esc_html(implode(' · ', $summary_parts)) . '</p></div>';
        }
        if (! empty($resolver_summary)) {
          echo '<div class="notice notice-info"><p>' . esc_html__('Media resolver:', 'dbvc') . ' ' . esc_html(implode(' · ', $resolver_summary)) . '</p></div>';
        }

        if (! empty($media_stats_import['errors'])) {
          echo '<div class="notice notice-error"><p>' . esc_html(sprintf(
            __('%d media downloads failed. Check the log for details.', 'dbvc'),
            (int) $media_stats_import['errors']
          )) . '</p></div>';
        }

        if (! empty($media_stats_import['blocked'])) {
          echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(
            __('%d media sources were blocked by current download restrictions.', 'dbvc'),
            (int) $media_stats_import['blocked']
          )) . '</p></div>';
        }
      }
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
    } elseif ($code === 'media_cache_cleared') {
      echo '<div class="notice notice-success"><p>' .
        esc_html__('Media cache cleared.', 'dbvc') .
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
    'tab-backups' => esc_html__('Backup/Archive', 'dbvc'),
    'tab-logs'    => esc_html__('Logs', 'dbvc'),
    'tab-docs' => esc_html__('Docs & Workflows', 'dbvc'),
  ];
  $import_subtabs = [
    'dbvc-import-content' => esc_html__('Content Import', 'dbvc'),
    'dbvc-import-upload'  => esc_html__('Upload', 'dbvc'),
  ];
  $export_subtabs = [
    'dbvc-export-full'      => esc_html__('Full Export', 'dbvc'),
    'dbvc-export-download'  => esc_html__('Download', 'dbvc'),
    'dbvc-export-snapshots' => esc_html__('Snapshots & Diff', 'dbvc'),
  ];
  $config_subtabs = [
    'dbvc-config-post-types' => esc_html__('Post Types', 'dbvc'),
    'dbvc-config-taxonomies' => esc_html__('Taxonomies', 'dbvc'),
    'dbvc-config-masking'    => esc_html__('Masking & Auto-Exports', 'dbvc'),
    'dbvc-config-import'     => esc_html__('Import Defaults', 'dbvc'),
    'dbvc-config-media'      => esc_html__('Media Handling', 'dbvc'),
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

  $logs_feedback = [
    'success' => [],
    'error'   => [],
  ];

  if (isset($_POST['dbvc_clear_logs_nonce']) && wp_verify_nonce($_POST['dbvc_clear_logs_nonce'], 'dbvc_clear_logs_action')) {
    $active_main_tab = 'tab-logs';
    if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::delete_log()) {
      $logs_feedback['success'][] = esc_html__('Log file cleared.', 'dbvc');
    } else {
      $logs_feedback['error'][] = esc_html__('Unable to clear the log file. Check file permissions.', 'dbvc');
    }
  }

  $backup_archives          = class_exists('DBVC_Backup_Manager') ? DBVC_Backup_Manager::list_backups() : [];
  $selected_backup_record   = null;
  foreach ($backup_archives as $archive) {
    if ($archive['name'] === $selected_backup) {
      $selected_backup_record = $archive;
      break;
    }
  }
  $manifest_items     = $selected_backup_record['manifest']['items'] ?? [];
  $items_per_page     = 10;
  $total_manifest     = is_array($manifest_items) ? count($manifest_items) : 0;
  $total_manifest = max(0, $total_manifest);
  $total_pages        = $total_manifest > 0 ? (int) ceil($total_manifest / $items_per_page) : 1;
  if ($selected_backup_page > $total_pages) {
    $selected_backup_page = $total_pages;
  }
  $manifest_page_slice = [];
  if ($total_manifest > 0) {
    $offset = ($selected_backup_page - 1) * $items_per_page;
    $manifest_page_slice = array_slice($manifest_items, $offset, $items_per_page);
  }

  $snapshots_history = [];
  if (class_exists('DBVC_Database')) {
    $snapshots_history = DBVC_Database::get_snapshots([
      'limit' => 25,
    ]);
  }

  $snapshot_baseline_options = array_filter(
    $snapshots_history,
    static fn($snapshot) => in_array($snapshot->type, ['full_export', 'chunked_export'], true)
  );

  $selected_diff_baseline = isset($_POST['dbvc_diff_baseline'])
    ? sanitize_text_field(wp_unslash($_POST['dbvc_diff_baseline']))
    : 'latest_full';

  $default_chunk_size   = isset($_POST['dbvc_chunk_size']) ? max(10, absint($_POST['dbvc_chunk_size'])) : 200;
  $chunk_feedback_html  = '';
  $active_export_jobs = [];
  if (class_exists('DBVC_Database')) {
    $jobs = DBVC_Database::get_jobs([
      'type'  => 'export_chunked',
      'limit' => 10,
    ]);
    foreach ($jobs as $job) {
      if (isset($job->status) && 'done' === $job->status) {
        continue;
      }
      $active_export_jobs[] = $job;
    }
  }

  $format_snapshot_notes = function ($snapshot) {
    if (empty($snapshot->notes)) {
      return ['summary' => '', 'counts' => ''];
    }
    $decoded = json_decode($snapshot->notes, true);
    if (! is_array($decoded)) {
      return ['summary' => '', 'counts' => ''];
    }

    $summary_parts = [];
    if (! empty($decoded['source'])) {
      $summary_parts[] = sprintf(__('source: %s', 'dbvc'), $decoded['source']);
    }
    if (! empty($decoded['post_types']) && is_array($decoded['post_types'])) {
      $summary_parts[] = sprintf(__('post types: %s', 'dbvc'), implode(',', $decoded['post_types']));
    }
    if (! empty($decoded['posts_exported'])) {
      $summary_parts[] = sprintf(__('posts_exported: %d', 'dbvc'), (int) $decoded['posts_exported']);
    }
    if (! empty($decoded['posts_imported'])) {
      $summary_parts[] = sprintf(__('posts_imported: %d', 'dbvc'), (int) $decoded['posts_imported']);
    }
    if (! empty($decoded['total'])) {
      $summary_parts[] = sprintf(__('total: %d', 'dbvc'), (int) $decoded['total']);
    }
    if (! empty($decoded['job_id'])) {
      $summary_parts[] = sprintf(__('job_id: %d', 'dbvc'), (int) $decoded['job_id']);
    }

    $counts_summary = '';
    if (! empty($decoded['counts']) && is_array($decoded['counts'])) {
      $parts = [];
      foreach ($decoded['counts'] as $label => $value) {
        $parts[] = sprintf('%s: %d', $label, (int) $value);
      }
      $counts_summary = implode(' · ', $parts);
    } elseif (! empty($decoded['media_stats']) && is_array($decoded['media_stats'])) {
      $parts = [];
      foreach ($decoded['media_stats'] as $label => $value) {
        if (! is_numeric($value)) {
          continue;
        }
        $parts[] = sprintf('%s: %d', $label, (int) $value);
      }
      $counts_summary = implode(' · ', $parts);
    }

    return [
      'summary' => implode(' · ', $summary_parts),
      'counts'  => $counts_summary,
    ];
  };

  $media_preview_data = null;
  if (
    $selected_backup_record
    && class_exists('DBVC_Media_Sync')
    && DBVC_Media_Sync::is_preview_enabled()
  ) {
    $media_preview_data = DBVC_Media_Sync::preview_manifest_media($selected_backup_record['manifest'] ?? [], 20);
  }

?>
  <div class="wrap">
    <h1><?php esc_html_e('DB Version Control', 'dbvc'); ?></h1>

    <div class="dbvc-tabs" data-dbvc-tabs>
      <nav class="dbvc-tabs__nav" role="tablist" aria-label="<?php esc_attr_e('DBVC Sections', 'dbvc'); ?>">
<?php foreach ($main_tabs as $tab_id => $label) :
  $button_id = 'dbvc-nav-' . $tab_id;
  $is_active = ($active_main_tab === $tab_id);
?>
        <button type="button"
          id="<?php echo esc_attr($button_id); ?>"
          class="dbvc-tabs__item<?php echo $is_active ? ' is-active' : ''; ?>"
          data-dbvc-tab="<?php echo esc_attr($tab_id); ?>"
          role="tab"
          aria-controls="<?php echo esc_attr($tab_id); ?>"
          aria-selected="<?php echo $is_active ? 'true' : 'false'; ?>">
          <?php echo esc_html($label); ?>
        </button>
<?php endforeach; ?>
      </nav>

      <div class="dbvc-tabs__panels">
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
<?php if ($import_require_review === '1') : ?>
              <div class="notice notice-warning">
                <p><?php esc_html_e('Legacy import form disabled. Use the DBVC Proposals UI above to review diffs and apply changes.', 'dbvc'); ?></p>
              </div>
<?php else : ?>
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
                <p>
                  <label>
                    <input type="checkbox" name="dbvc_prefer_entity_uids" value="1" <?php checked($prefer_entity_uids, '1'); ?> />
                    <?php esc_html_e('Prefer entity UIDs when matching posts', 'dbvc'); ?>
                  </label><br>
                  <small><?php esc_html_e('When enabled, DBVC attempts UID-based matching before falling back to IDs or slugs. Disable when importing unrelated JSON dumps.', 'dbvc'); ?></small>
                </p>
<?php if (class_exists('DBVC_Media_Sync')) : ?>
                <fieldset class="dbvc-media-import-options" style="margin:1rem 0;">
                  <legend><strong><?php esc_html_e('Media Retrieval', 'dbvc'); ?></strong></legend>
                  <p>
                    <label>
                      <input type="checkbox" name="dbvc_import_media" value="1" <?php checked($media_retrieve_enabled, '1'); ?> />
                      <?php esc_html_e('Automatically download missing media referenced in manifest.json after import completes', 'dbvc'); ?>
                    </label>
                  </p>
<?php if ($media_preview_enabled === '1') : ?>
  <?php if ($sync_media_preview_data) :
    $preview_items    = $sync_media_preview_data['preview_items'] ?? [];
    $pending_count    = (int) ($sync_media_preview_data['total_candidates'] ?? 0);
    $detected_count   = (int) ($sync_media_preview_data['total_detected'] ?? 0);
    $skipped_existing = (int) ($sync_media_preview_data['skipped_existing'] ?? 0);
    $blocked_entries  = isset($sync_media_preview_data['blocked']) && is_array($sync_media_preview_data['blocked']) ? $sync_media_preview_data['blocked'] : [];
  ?>
                  <div class="dbvc-media-preview">
                    <p>
                      <?php
                      echo esc_html(
                        sprintf(
                          __('Detected %1$d media references (%2$d pending download, %3$d already present, %4$d blocked).', 'dbvc'),
                          $detected_count,
                          $pending_count,
                          $skipped_existing,
                          count($blocked_entries)
                        )
                      );
                      ?>
                    </p>
  <?php if (! empty($preview_items)) : ?>
                    <table class="widefat striped dbvc-media-preview__table">
                      <thead>
                        <tr>
                          <th><?php esc_html_e('Original ID', 'dbvc'); ?></th>
                          <th><?php esc_html_e('Source URL', 'dbvc'); ?></th>
                          <th><?php esc_html_e('Filename', 'dbvc'); ?></th>
                        </tr>
                      </thead>
                      <tbody>
    <?php foreach ($preview_items as $item) : ?>
                        <tr>
                          <td><?php echo isset($item['original_id']) ? esc_html((string) $item['original_id']) : '&mdash;'; ?></td>
                          <td style="word-break:break-word;"><?php echo isset($item['source_url']) ? esc_url($item['source_url']) : '&mdash;'; ?></td>
                          <td><?php echo isset($item['filename']) ? esc_html($item['filename']) : '&mdash;'; ?></td>
                        </tr>
    <?php endforeach; ?>
                      </tbody>
                    </table>
  <?php endif; ?>
  <?php if (! empty($blocked_entries)) : ?>
                    <p class="notice notice-warning" style="padding:.75rem 1rem;">
                      <?php esc_html_e('Some media sources are blocked. Enable external media downloads or update the mirror domain to retrieve them.', 'dbvc'); ?>
                    </p>
                    <ul class="dbvc-media-preview__blocked-list">
      <?php foreach ($blocked_entries as $blocked) :
        $blocked_id  = isset($blocked['original_id']) ? (int) $blocked['original_id'] : 0;
        $blocked_url = isset($blocked['source_url']) ? $blocked['source_url'] : '';
      ?>
                      <li>
                        <?php
                        echo esc_html(
                          sprintf(
                            __('%1$s (ID %2$d)', 'dbvc'),
                            $blocked_url,
                            $blocked_id
                          )
                        );
                        ?>
                      </li>
      <?php endforeach; ?>
                    </ul>
  <?php endif; ?>
                  </div>
  <?php elseif ($sync_media_preview_ready) : ?>
                  <p class="description"><?php esc_html_e('All referenced media already exist locally based on current manifest.', 'dbvc'); ?></p>
  <?php else : ?>
                  <p class="description"><?php esc_html_e('No manifest detected in the sync folder. Run an export to generate manifest.json for media previews.', 'dbvc'); ?></p>
  <?php endif; ?>
<?php else : ?>
                  <p class="description"><?php esc_html_e('Enable "Show media retrieval preview" in Import Defaults to see which assets will be downloaded before running the import.', 'dbvc'); ?></p>
<?php endif; ?>
                </fieldset>
<?php endif; ?>
              <?php submit_button(esc_html__('Run Import', 'dbvc'), 'primary', 'dbvc_import_button'); ?>
            </form>
<?php endif; ?>
<?php if (get_option('dbvc_import_require_review') === '1') : ?>
            <div class="notice notice-warning" style="margin-top:1rem;">
              <p><strong><?php esc_html_e('Proposal review required', 'dbvc'); ?></strong></p>
              <p><?php esc_html_e('All imports must go through the Proposal review workflow. Use the action below to apply the currently selected proposal.', 'dbvc'); ?></p>
              <p>
                <button type="button" class="button button-primary" data-dbvc-open-proposals>
                  <?php esc_html_e('Open Proposal Review', 'dbvc'); ?>
                </button>
              </p>
            </div>
<?php endif; ?>
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

<script>
document.addEventListener('DOMContentLoaded', function () {
  const heartbeat = document.getElementById('dbvc-logging-heartbeat');
  if (heartbeat) {
    heartbeat.addEventListener('click', function () {
      if (!heartbeat.dataset.dbvcHeartbeatNonce) {
        heartbeat.dataset.dbvcHeartbeatNonce = '<?php echo esc_js(wp_create_nonce('dbvc_logging_heartbeat')); ?>';
      }
      fetch(ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8'},
        body: new URLSearchParams({
          action: 'dbvc_logging_heartbeat',
          nonce: heartbeat.dataset.dbvcHeartbeatNonce,
        }),
      }).then(() => {
        heartbeat.blur();
      });
    });
  }
});
</script>
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
<?php if (class_exists('DBVC_Media_Sync')) : ?>
              <label>
                <input type="checkbox" name="dbvc_media_bundle_enabled" value="1" <?php checked(DBVC_Media_Sync::is_bundle_enabled(), '1'); ?> />
                <?php esc_html_e('Include referenced media files in export bundles', 'dbvc'); ?>
              </label>
              <br><small><?php esc_html_e('Copies referenced attachments into sync/media so proposals are self-contained when reviewers download them.', 'dbvc'); ?></small>
              <br><br>
<?php endif; ?>

              <label>
                <input type="checkbox" name="dbvc_export_sort_meta" value="1" <?php checked($sort_export_meta); ?> />
                <?php esc_html_e('Sort meta keys alphabetically before writing JSON', 'dbvc'); ?>
              </label>
              <br><small><?php esc_html_e('Applies to posts/CPTs and taxonomy term meta.', 'dbvc'); ?></small>
              <br><br>

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

            <section id="dbvc-export-snapshots" class="dbvc-subtab-panel<?php echo $active_export_subtab === 'dbvc-export-snapshots' ? ' is-active' : ''; ?>" data-dbvc-subpanel="dbvc-export-snapshots" role="tabpanel" aria-labelledby="dbvc-nav-dbvc-export-snapshots" <?php echo $active_export_subtab === 'dbvc-export-snapshots' ? '' : 'hidden'; ?>>
              <h2><?php esc_html_e('Snapshot History & Differential Export', 'dbvc'); ?></h2>
              <p><?php esc_html_e('Run diff exports against previous snapshots or review recent export/import activity.', 'dbvc'); ?></p>

              <form method="post" class="dbvc-diff-export-form" style="margin-bottom:1.5rem;">
                <?php wp_nonce_field('dbvc_export_action', 'dbvc_export_nonce'); ?>
                <input type="hidden" name="dbvc_diff_export_submit" value="1" />
                <table class="form-table">
                  <tr>
                    <th scope="row"><label for="dbvc_diff_baseline"><?php esc_html_e('Baseline snapshot', 'dbvc'); ?></label></th>
                    <td>
                      <select name="dbvc_diff_baseline" id="dbvc_diff_baseline">
                        <option value="latest_full" <?php selected($selected_diff_baseline, 'latest_full'); ?>><?php esc_html_e('Latest full export', 'dbvc'); ?></option>
<?php foreach ($snapshot_baseline_options as $snapshot) :
  $notes_info = $format_snapshot_notes($snapshot);
  $label = sprintf(
    '#%1$d · %2$s · %3$s',
    (int) $snapshot->id,
    ucwords(str_replace('_', ' ', $snapshot->type)),
    $snapshot->created_at
  );
  if ($notes_info['counts']) {
    $label .= ' · ' . $notes_info['counts'];
  }
?>
                        <option value="<?php echo esc_attr($snapshot->id); ?>" <?php selected($selected_diff_baseline, (string) $snapshot->id); ?>><?php echo esc_html($label); ?></option>
<?php endforeach; ?>
                      </select>
                      <p class="description"><?php esc_html_e('Select the snapshot to compare against. Choose the latest full export to detect changes since the last complete run.', 'dbvc'); ?></p>
                    </td>
                  </tr>
                </table>
                <?php submit_button(esc_html__('Run Diff Export', 'dbvc'), 'primary', 'dbvc_diff_export_button', false); ?>
              </form>

<?php echo $chunk_feedback_html; // phpcs:ignore WordPress.Security.EscapeOutput ?>

              <form method="post" class="dbvc-chunk-export-form" style="margin-bottom:1.5rem;">
                <?php wp_nonce_field('dbvc_export_action', 'dbvc_export_nonce'); ?>
                <input type="hidden" name="dbvc_chunk_export_submit" value="1" />
                <table class="form-table">
                  <tr>
                    <th scope="row"><label for="dbvc_chunk_size"><?php esc_html_e('Chunk size', 'dbvc'); ?></label></th>
                    <td>
                      <input type="number" name="dbvc_chunk_size" id="dbvc_chunk_size" value="<?php echo esc_attr($default_chunk_size); ?>" min="10" step="10" style="width:120px;" />
                      <p class="description"><?php esc_html_e('Number of posts to export in each chunk.', 'dbvc'); ?></p>
                    </td>
                  </tr>
                </table>
                <?php submit_button(esc_html__('Start Chunked Export', 'dbvc'), 'primary', 'dbvc_chunk_export_button', false); ?>
              </form>

<?php if (! empty($snapshots_history)) : ?>
              <table class="widefat striped">
                <thead>
                  <tr>
                    <th><?php esc_html_e('ID', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Type', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Created', 'dbvc'); ?></th>
                    <th><?php esc_html_e('User', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Counts', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Details', 'dbvc'); ?></th>
                  </tr>
                </thead>
                <tbody>
<?php foreach ($snapshots_history as $snapshot) :
  $notes_info = $format_snapshot_notes($snapshot);
  $user_label = '';
  if (! empty($snapshot->initiated_by)) {
    $user_obj  = get_userdata($snapshot->initiated_by);
    $user_label = $user_obj ? $user_obj->display_name : '#' . (int) $snapshot->initiated_by;
  } else {
    $user_label = esc_html__('CLI/Automated', 'dbvc');
  }
?>
                  <tr>
                    <td><?php echo esc_html((string) $snapshot->id); ?></td>
                    <td><?php echo esc_html(ucwords(str_replace('_', ' ', $snapshot->type))); ?></td>
                    <td><?php echo esc_html($snapshot->created_at); ?></td>
                    <td><?php echo esc_html($user_label); ?></td>
                    <td><?php echo esc_html($notes_info['counts']); ?></td>
                    <td><?php echo esc_html($notes_info['summary']); ?></td>
                  </tr>
<?php endforeach; ?>
                </tbody>
              </table>
<?php else : ?>
              <p><?php esc_html_e('No snapshots recorded yet.', 'dbvc'); ?></p>
<?php endif; ?>

<?php if (! empty($active_export_jobs)) : ?>
              <h3 style="margin-top:2rem;"><?php esc_html_e('Active Chunked Export Jobs', 'dbvc'); ?></h3>
              <table class="widefat striped">
                <thead>
                  <tr>
                    <th><?php esc_html_e('Job ID', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Status', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Progress', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Chunk Size', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Processed', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Total', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Actions', 'dbvc'); ?></th>
                  </tr>
                </thead>
                <tbody>
<?php foreach ($active_export_jobs as $job) :
  $context = [];
  if (! empty($job->context)) {
    $decoded = json_decode($job->context, true);
    if (is_array($decoded)) {
      $context = $decoded;
    }
  }
  $chunk_size = isset($context['chunk_size']) ? (int) $context['chunk_size'] : 0;
  $processed  = isset($context['processed']) ? (int) $context['processed'] : 0;
  $total      = isset($context['total']) ? (int) $context['total'] : 0;
  $progress   = isset($job->progress) ? (float) $job->progress : 0;
  $progress_pct = $progress > 0 ? min(100, round($progress * 100, 1)) : 0;
  $resume_command = sprintf('wp dbvc export --chunk-size=%d --job-id=%d', max(1, $chunk_size), (int) $job->id);
?>
                  <tr>
                    <td><?php echo esc_html((string) $job->id); ?></td>
                    <td><?php echo esc_html($job->status); ?></td>
                    <td><?php echo esc_html($progress_pct . '%'); ?></td>
                    <td><?php echo esc_html($chunk_size); ?></td>
                    <td><?php echo esc_html($processed); ?></td>
                    <td><?php echo esc_html($total); ?></td>
                    <td>
                      <form method="post" class="dbvc-inline-form" style="display:inline-block;margin-right:0.5rem;">
                        <?php wp_nonce_field('dbvc_export_action', 'dbvc_export_nonce'); ?>
                        <input type="hidden" name="dbvc_chunk_export_submit" value="1" />
                        <input type="hidden" name="dbvc_chunk_size" value="<?php echo esc_attr($chunk_size); ?>" />
                        <input type="hidden" name="dbvc_chunk_job_id" value="<?php echo esc_attr($job->id); ?>" />
                        <?php submit_button(__('Process Next Chunk', 'dbvc'), 'secondary', 'dbvc_chunk_export_button', false); ?>
                      </form>
                      <code style="display:block;margin-top:0.5rem;opacity:0.7;"><?php echo esc_html($resume_command); ?></code>
                    </td>
                  </tr>
<?php endforeach; ?>
                </tbody>
              </table>
<?php endif; ?>
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
          <label>
            <input type="checkbox" name="dbvc_auto_clear_decisions" value="1" <?php checked(get_option('dbvc_auto_clear_decisions', '1'), '1'); ?> />
            <?php esc_html_e('Auto-clear proposal decisions after successful imports', 'dbvc'); ?>
          </label><br>
          <small><?php esc_html_e('Keeps reviewer selections in sync by clearing Accept/Keep choices once an import completes without errors.', 'dbvc'); ?></small>
        </p>

        <p>
          <label>
            <input type="checkbox" name="dbvc_prefer_entity_uids" value="1" <?php checked($prefer_entity_uids, '1'); ?> />
            <?php esc_html_e('Prefer entity UIDs when matching posts', 'dbvc'); ?>
          </label><br>
          <small><?php esc_html_e('When enabled, DBVC matches proposal entities by their stored UID before falling back to IDs or slugs.', 'dbvc'); ?></small>
        </p>

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

        <h3><?php esc_html_e('Ignore Fields During Proposal Review', 'dbvc'); ?></h3>
        <p class="description">
          <?php esc_html_e('Enter dot-path patterns (one per line or comma separated) for meta or taxonomy fields that should be excluded from diff counts and review queues.', 'dbvc'); ?>
        </p>
        <textarea name="dbvc_diff_ignore_paths" rows="4" style="width:100%;"><?php echo esc_textarea($diff_ignore_paths); ?></textarea>
        <p class="description">
          <?php esc_html_e('Supports wildcards (*) and regular expressions using /pattern/. Example: meta.dbvc_post_history.*', 'dbvc'); ?>
        </p>

        <p>
          <label>
            <input type="checkbox" name="dbvc_import_require_review" value="1" <?php checked($import_require_review, '1'); ?> />
            <?php esc_html_e('Require DBVC Proposal review before running imports', 'dbvc'); ?>
          </label><br>
          <small><?php esc_html_e('When enabled, the legacy “Run Import” form is disabled so reviewers must use the React proposals/diff workflow.', 'dbvc'); ?></small>
        </p>
        <p>
          <label>
            <input type="checkbox" name="dbvc_force_reapply_new_posts" value="1" <?php checked($force_reapply_new_posts, '1'); ?> />
            <?php esc_html_e('When reopening a proposal, auto-mark previously accepted new posts for import', 'dbvc'); ?>
          </label><br>
          <small><?php esc_html_e('Keeps “New post accepted” decisions in place so reopened proposals can re-import media and meta without manually re-selecting each entity.', 'dbvc'); ?></small>
        </p>

        <hr />
        <p class="description">
          <?php esc_html_e('Media ingestion, bundling, and resolver settings moved to Configure → Media Handling.', 'dbvc'); ?>
        </p>

        <hr />

        <h2><?php esc_html_e('Import Logging', 'dbvc'); ?></h2>
        <p class="description"><?php esc_html_e('Requires logging to be enabled under the Backups tab.', 'dbvc'); ?></p>
        <p>
          <label>
            <input type="checkbox" name="dbvc_log_import_runs" value="1" <?php checked($logging_import_events, '1'); ?> />
            <?php esc_html_e('Log content imports (manual and WP-CLI)', 'dbvc'); ?>
          </label>
        </p>
        <p>
          <label>
            <input type="checkbox" name="dbvc_log_term_imports" value="1" <?php checked($logging_term_events, '1'); ?> />
            <?php esc_html_e('Include term-specific events in import logs', 'dbvc'); ?>
          </label><br>
          <small><?php esc_html_e('Adds detailed entries for term matching, parent remapping, and taxonomy creation.', 'dbvc'); ?></small>
        </p>
        <p>
          <label>
            <input type="checkbox" name="dbvc_log_sync_uploads" value="1" <?php checked($logging_upload_events, '1'); ?> />
            <?php esc_html_e('Log sync folder uploads/unpacks', 'dbvc'); ?>
          </label>
        </p>
        <p>
          <label>
            <input type="checkbox" name="dbvc_log_media_sync" value="1" <?php checked($logging_media_events, '1'); ?> />
            <?php esc_html_e('Log automatic media retrieval events', 'dbvc'); ?>
          </label>
        </p>

        <?php submit_button(__('Save Import Settings', 'dbvc'), 'secondary', 'dbvc_config_save[import]', false); ?>
      </section>

      <section id="dbvc-config-media" class="dbvc-subtab-panel<?php echo $active_config_subtab === 'dbvc-config-media' ? ' is-active' : ''; ?>" data-dbvc-subpanel="dbvc-config-media" role="tabpanel" aria-labelledby="dbvc-nav-dbvc-config-media" <?php echo $active_config_subtab === 'dbvc-config-media' ? '' : 'hidden'; ?>>
        <?php $render_config_feedback($config_feedback['media']); ?>
        <h2><?php esc_html_e('Media Handling', 'dbvc'); ?></h2>
        <p class="description"><?php esc_html_e('Control how proposals capture, bundle, and resolve attachments across environments.', 'dbvc'); ?></p>

        <p>
          <label>
            <input type="checkbox" name="dbvc_media_retrieve_enabled" value="1" <?php checked($media_retrieve_enabled, '1'); ?> />
            <?php esc_html_e('Retrieve missing media from proposal bundles or mirror sources during import', 'dbvc'); ?>
          </label>
        </p>
        <p>
          <label>
            <input type="checkbox" name="dbvc_media_preserve_names" value="1" <?php checked($media_preserve_names, '1'); ?> />
            <?php esc_html_e('Preserve original filenames when sideloading media', 'dbvc'); ?>
          </label><br>
          <small><?php esc_html_e('Enable this if other plugins/themes rewrite filenames or convert formats during upload.', 'dbvc'); ?></small>
        </p>
        <p>
          <label>
            <input type="checkbox" name="dbvc_media_preview_enabled" value="1" <?php checked($media_preview_enabled, '1'); ?> />
            <?php esc_html_e('Show media preview in Backup/Archive tab', 'dbvc'); ?>
          </label><br>
          <small><?php esc_html_e('Displays counts and sample assets that would be downloaded so you can review before running a restore.', 'dbvc'); ?></small>
        </p>
        <p>
          <label>
            <input type="checkbox" name="dbvc_media_allow_external" value="1" <?php checked($media_allow_external, '1'); ?> />
            <?php esc_html_e('Allow downloads from external domains (beyond this site or mirror domain)', 'dbvc'); ?>
          </label><br>
          <small><?php esc_html_e('When disabled, only assets hosted on this site or the configured mirror domain will be retrieved.', 'dbvc'); ?></small>
        </p>

        <div class="dbvc-media-transport">
          <label for="dbvc_media_transport_mode"><strong><?php esc_html_e('Media transport mode', 'dbvc'); ?></strong></label>
          <select name="dbvc_media_transport_mode" id="dbvc_media_transport_mode">
            <?php
            $transport_mode = DBVC_Media_Sync::get_transport_mode();
            $transport_options = [
              'auto'    => __('Auto (bundled first, fallback to remote)', 'dbvc'),
              'bundled' => __('Bundled only (require local media files)', 'dbvc'),
              'remote'  => __('Remote only (download from original source)', 'dbvc'),
            ];
            foreach ($transport_options as $mode_key => $label) {
              printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr($mode_key),
                selected($transport_mode, $mode_key, false),
                esc_html($label)
              );
            }
            ?>
          </select>
          <p class="description">
            <?php esc_html_e('Auto mode checks deterministic bundles first, then remote URLs. Bundled mode requires matching files packaged with each proposal.', 'dbvc'); ?>
          </p>
        </div>

        <p>
          <label>
            <input type="checkbox" name="dbvc_media_bundle_enabled" value="1" <?php checked(DBVC_Media_Sync::is_bundle_enabled(), '1'); ?> />
            <?php esc_html_e('Generate per-proposal media bundles during export', 'dbvc'); ?>
          </label><br>
          <small><?php esc_html_e('When enabled, DBVC stores proposal-specific media under sync/media-bundles/<proposal-id>/ for deterministic reuse.', 'dbvc'); ?></small>
        </p>
        <p>
          <label for="dbvc_media_bundle_chunk"><strong><?php esc_html_e('Media bundling chunk size', 'dbvc'); ?></strong></label><br>
          <input type="number" name="dbvc_media_bundle_chunk" id="dbvc_media_bundle_chunk" value="<?php echo esc_attr(DBVC_Media_Sync::get_bundle_chunk_size()); ?>" min="10" step="10" style="width:140px;" />
          <small><?php esc_html_e('Number of media files to copy per batch when building bundles.', 'dbvc'); ?></small>
        </p>

        <?php if ($media_clear_url && $media_clear_url !== '#') : ?>
          <p>
            <a class="button" href="<?php echo esc_url($media_clear_url); ?>">
              <?php esc_html_e('Clear Media Cache', 'dbvc'); ?>
            </a>
          </p>
        <?php endif; ?>

        <?php submit_button(__('Save Media Settings', 'dbvc'), 'secondary', 'dbvc_config_save[media]', false); ?>
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

<section id="tab-backups" class="dbvc-tab-panel<?php echo $active_main_tab === 'tab-backups' ? ' is-active' : ''; ?>" data-dbvc-panel="tab-backups" role="tabpanel" aria-labelledby="dbvc-nav-tab-backups" <?php echo $active_main_tab === 'tab-backups' ? '' : 'hidden'; ?>>
      <h2><?php esc_html_e('Backup & Archive Management', 'dbvc'); ?></h2>

      <?php foreach (array_unique($backup_feedback['error']) as $message) : ?>
        <div class="notice notice-error"><p><?php echo esc_html($message); ?></p></div>
      <?php endforeach; ?>
      <?php foreach (array_unique($backup_feedback['success']) as $message) : ?>
        <div class="notice notice-success"><p><?php echo esc_html($message); ?></p></div>
      <?php endforeach; ?>

      <?php if (! class_exists('DBVC_Backup_Manager')) : ?>
        <div class="notice notice-error"><p><?php esc_html_e('Backup manager is not available. Please ensure the plugin files are intact.', 'dbvc'); ?></p></div>
      <?php else : ?>
        <div class="dbvc-backup-grid">
          <section class="dbvc-backup-list">
            <h3><?php esc_html_e('Available Backups', 'dbvc'); ?></h3>
            <?php if (empty($backup_archives)) : ?>
              <p><?php esc_html_e('No backups have been created yet.', 'dbvc'); ?></p>
            <?php else : ?>
              <table class="widefat striped">
                <thead>
                  <tr>
                    <th><?php esc_html_e('Name', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Created', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Size', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Status', 'dbvc'); ?></th>
                    <th><?php esc_html_e('Actions', 'dbvc'); ?></th>
                  </tr>
                </thead>
                <tbody>
                <?php foreach ($backup_archives as $archive) :
                  $manifest    = $archive['manifest'] ?? [];
                  $created_utc = isset($manifest['generated_at']) ? $manifest['generated_at'] . ' UTC' : esc_html__('Unknown', 'dbvc');
                  $size_readable = function_exists('size_format') ? size_format((float) ($archive['size'] ?? 0)) : esc_html__('n/a', 'dbvc');
                  $status      = $archive['locked'] ? esc_html__('Locked', 'dbvc') : esc_html__('Unlocked', 'dbvc');
                  $view_url    = add_query_arg([
                    'page'         => 'dbvc-export',
                    'dbvc_tab'     => 'tab-backups',
                    'dbvc_backup'  => $archive['name'],
                    'dbvc_backup_page' => 1,
                  ], admin_url('admin.php'));
                  $download_url = wp_nonce_url(
                    add_query_arg([
                      'action' => 'dbvc_download_backup',
                      'backup' => $archive['name'],
                    ], admin_url('admin-post.php')),
                    'dbvc_download_backup_' . $archive['name']
                  );
                  ?>
                  <tr>
                    <td><a href="<?php echo esc_url($view_url); ?>"><?php echo esc_html($archive['name']); ?></a></td>
                    <td><?php echo esc_html($created_utc); ?></td>
                    <td><?php echo esc_html($size_readable); ?></td>
                    <td><?php echo esc_html($status); ?></td>
                    <td style="white-space:nowrap;">
                      <a class="button button-small" href="<?php echo esc_url($view_url); ?>">
                        <?php esc_html_e('View', 'dbvc'); ?>
                      </a>
                      <a class="button button-small" href="<?php echo esc_url($download_url); ?>">
                        <?php esc_html_e('Download', 'dbvc'); ?>
                      </a>
                      <form method="post" style="display:inline;">
                        <?php wp_nonce_field('dbvc_backup_action', 'dbvc_backup_nonce'); ?>
                        <input type="hidden" name="dbvc_backup_action" value="toggle_lock" />
                        <input type="hidden" name="dbvc_backup_folder" value="<?php echo esc_attr($archive['name']); ?>" />
                        <input type="hidden" name="dbvc_backup_lock" value="<?php echo $archive['locked'] ? '0' : '1'; ?>" />
                        <button type="submit" class="button button-small">
                          <?php echo $archive['locked'] ? esc_html__('Unlock', 'dbvc') : esc_html__('Lock', 'dbvc'); ?>
                        </button>
                      </form>
                      <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js(__('Delete this backup? This cannot be undone.', 'dbvc')); ?>');">
                        <?php wp_nonce_field('dbvc_backup_action', 'dbvc_backup_nonce'); ?>
                        <input type="hidden" name="dbvc_backup_action" value="delete_backup" />
                        <input type="hidden" name="dbvc_backup_folder" value="<?php echo esc_attr($archive['name']); ?>" />
                        <button type="submit" class="button button-small button-link-delete"<?php disabled($archive['locked']); ?>>
                          <?php esc_html_e('Delete', 'dbvc'); ?>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </section>

          <section class="dbvc-backup-detail">
            <h3><?php esc_html_e('Backup Detail & Restore', 'dbvc'); ?></h3>
            <?php if (! $selected_backup_record) : ?>
              <p><?php esc_html_e('Select a backup to inspect its manifest and restore options.', 'dbvc'); ?></p>
            <?php else :
              $manifest = $selected_backup_record['manifest'] ?? [];
              $summary  = $manifest['totals'] ?? [];
              $missing  = isset($summary['missing_import_hash']) ? (int) $summary['missing_import_hash'] : 0;
              ?>
              <div class="dbvc-backup-meta">
                <p><strong><?php esc_html_e('Selected Backup:', 'dbvc'); ?></strong> <?php echo esc_html($selected_backup_record['name']); ?></p>
                <p><strong><?php esc_html_e('Created:', 'dbvc'); ?></strong> <?php echo esc_html($manifest['generated_at'] ?? esc_html__('Unknown', 'dbvc')); ?> UTC</p>
                <p><strong><?php esc_html_e('Items:', 'dbvc'); ?></strong> <?php echo esc_html($summary['files'] ?? 0); ?></p>
                <p><strong><?php esc_html_e('Locked:', 'dbvc'); ?></strong> <?php echo $selected_backup_record['locked'] ? esc_html__('Yes', 'dbvc') : esc_html__('No', 'dbvc'); ?></p>
                <?php if ($missing > 0) : ?>
                  <p class="description"><?php echo esc_html(sprintf(_n('%d item is missing its import hash.', '%d items are missing their import hash.', $missing, 'dbvc'), $missing)); ?></p>
                <?php endif; ?>
              </div>

              <?php if ($media_preview_data) :
                $preview_items      = $media_preview_data['preview_items'] ?? [];
                $pending_count      = (int) ($media_preview_data['total_candidates'] ?? 0);
                $detected_count     = (int) ($media_preview_data['total_detected'] ?? 0);
                $skipped_existing   = (int) ($media_preview_data['skipped_existing'] ?? 0);
                $blocked_entries    = isset($media_preview_data['blocked']) && is_array($media_preview_data['blocked']) ? $media_preview_data['blocked'] : [];
                $blocked_count      = count($blocked_entries);
              ?>
                <div class="dbvc-media-preview">
                  <h4><?php esc_html_e('Media Retrieval Preview', 'dbvc'); ?></h4>
                  <p>
                    <?php
                    echo esc_html(
                      sprintf(
                        /* translators: 1: total detected, 2: pending download, 3: already present, 4: blocked */
                        __('Detected %1$d media references (%2$d pending download, %3$d already present, %4$d blocked).', 'dbvc'),
                        $detected_count,
                        $pending_count,
                        $skipped_existing,
                        $blocked_count
                      )
                    );
                    ?>
                  </p>
                  <?php if ($pending_count > 0 && ! empty($preview_items)) : ?>
                    <table class="widefat striped dbvc-media-preview__table">
                      <thead>
                        <tr>
                          <th><?php esc_html_e('Attachment ID', 'dbvc'); ?></th>
                          <th><?php esc_html_e('Host', 'dbvc'); ?></th>
                          <th><?php esc_html_e('Filename', 'dbvc'); ?></th>
                          <th><?php esc_html_e('Source URL', 'dbvc'); ?></th>
                        </tr>
                      </thead>
                      <tbody>
                      <?php foreach ($preview_items as $item) : ?>
                        <tr>
                          <td><?php echo esc_html($item['original_id'] ?? ''); ?></td>
                          <td><?php echo esc_html($item['source_host'] ?? ''); ?></td>
                          <td><?php echo esc_html($item['filename'] ?? ''); ?></td>
                          <td><a href="<?php echo esc_url($item['source_url'] ?? ''); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($item['source_url'] ?? ''); ?></a></td>
                        </tr>
                      <?php endforeach; ?>
                      </tbody>
                    </table>
                    <?php if ($pending_count > count($preview_items)) : ?>
                      <p class="description"><?php echo esc_html(sprintf(__('Showing first %1$d of %2$d pending downloads.', 'dbvc'), count($preview_items), $pending_count)); ?></p>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if ($blocked_count > 0) : ?>
                    <p class="notice notice-warning" style="margin-top:1rem;">
                      <?php esc_html_e('Some media sources are blocked. Enable external downloads to retrieve them.', 'dbvc'); ?>
                    </p>
                    <ul class="dbvc-media-preview__blocked-list">
                      <?php foreach (array_slice($blocked_entries, 0, 5) as $blocked) :
                        $blocked_url = isset($blocked['source_url']) ? esc_url($blocked['source_url']) : '';
                        $blocked_host = $blocked_url ? parse_url($blocked_url, PHP_URL_HOST) : '';
                      ?>
                        <li>
                          <?php echo esc_html($blocked_host ?: $blocked_url); ?>
                          <?php if ($blocked_url) : ?>
                            – <a href="<?php echo esc_url($blocked_url); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('view', 'dbvc'); ?></a>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                      <?php if ($blocked_count > 5) : ?>
                        <li><?php echo esc_html(sprintf(__('...and %d more.', 'dbvc'), $blocked_count - 5)); ?></li>
                      <?php endif; ?>
                    </ul>
                  <?php endif; ?>
                </div>
              <?php endif; ?>

              <form method="post" class="dbvc-backup-restore-form">
                <?php wp_nonce_field('dbvc_backup_action', 'dbvc_backup_nonce'); ?>
                <input type="hidden" name="dbvc_backup_action" value="restore_backup" />
                <input type="hidden" name="dbvc_backup_folder" value="<?php echo esc_attr($selected_backup_record['name']); ?>" />

                <fieldset>
                  <legend><strong><?php esc_html_e('Restore Mode (select one)', 'dbvc'); ?></strong></legend>
                  <label>
                    <input type="checkbox" name="dbvc_backup_mode_partial" value="1" />
                    <?php esc_html_e('Partial: validate and import only changed entries', 'dbvc'); ?>
                  </label><br>
                  <label>
                    <input type="checkbox" name="dbvc_backup_mode_full" value="1" />
                    <?php esc_html_e('Full: rewrite sync folder then import everything', 'dbvc'); ?>
                  </label><br>
                  <label>
                    <input type="checkbox" name="dbvc_backup_mode_copy" value="1" />
                    <?php esc_html_e('Copy: copy backup into sync folder (no import)', 'dbvc'); ?>
                  </label>
                </fieldset>

                <p>
                  <label for="dbvc_backup_confirm">
                    <?php esc_html_e('Type Restore to confirm:', 'dbvc'); ?>
                  </label><br>
                  <input type="text" id="dbvc_backup_confirm" name="dbvc_backup_confirm" value="" style="width:200px;" autocomplete="off" />
                </p>

                <?php submit_button(esc_html__('Execute', 'dbvc'), 'primary', 'dbvc_backup_restore_submit', false); ?>
              </form>

              <?php if (! empty($manifest_page_slice)) : ?>
                <h4><?php esc_html_e('Manifest Preview', 'dbvc'); ?></h4>
                <table class="widefat striped">
                  <thead>
                    <tr>
                      <th><?php esc_html_e('Title / File', 'dbvc'); ?></th>
                      <th><?php esc_html_e('Type', 'dbvc'); ?></th>
                      <th><?php esc_html_e('Published', 'dbvc'); ?></th>
                      <th><?php esc_html_e('Modified', 'dbvc'); ?></th>
                      <th><?php esc_html_e('Hash Status', 'dbvc'); ?></th>
                    </tr>
                  </thead>
                  <tbody>
                  <?php foreach ($manifest_page_slice as $item) :
                    $item_type = $item['item_type'] ?? 'generic';
                    $title     = $item_type === 'post' ? ($item['post_title'] ?: sprintf(__('Post #%d', 'dbvc'), $item['post_id'])) : ucfirst($item_type);
                    $hash_info = ($item['has_import_hash'] ?? false) ? esc_html__('Present', 'dbvc') : esc_html__('Missing', 'dbvc');
                    ?>
                    <tr>
                      <td>
                        <strong><?php echo esc_html($title); ?></strong><br>
                        <code><?php echo esc_html($item['path'] ?? ''); ?></code>
                      </td>
                      <td><?php echo esc_html($item_type); ?></td>
                      <td><?php echo esc_html($item['post_date'] ?? '—'); ?></td>
                      <td><?php echo esc_html($item['post_modified'] ?? '—'); ?></td>
                      <td><?php echo esc_html($hash_info); ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>

                <?php if ($total_pages > 1) :
                  $pagination_base = add_query_arg([
                    'page'                => 'dbvc-export',
                    'dbvc_tab'            => 'tab-backups',
                    'dbvc_backup'         => $selected_backup_record['name'],
                  ], admin_url('admin.php'));
                  ?>
                  <p class="dbvc-backup-pagination">
                    <?php if ($selected_backup_page > 1) :
                      $prev_url = add_query_arg('dbvc_backup_page', $selected_backup_page - 1, $pagination_base); ?>
                      <a class="button button-small" href="<?php echo esc_url($prev_url); ?>">&larr; <?php esc_html_e('Previous', 'dbvc'); ?></a>
                    <?php endif; ?>

                    <span><?php printf(esc_html__('Page %1$d of %2$d', 'dbvc'), $selected_backup_page, $total_pages); ?></span>

                    <?php if ($selected_backup_page < $total_pages) :
                      $next_url = add_query_arg('dbvc_backup_page', $selected_backup_page + 1, $pagination_base); ?>
                      <a class="button button-small" href="<?php echo esc_url($next_url); ?>"><?php esc_html_e('Next', 'dbvc'); ?> &rarr;</a>
                    <?php endif; ?>
                  </p>
                <?php endif; ?>
              <?php else : ?>
                <p><?php esc_html_e('No manifest entries available for preview.', 'dbvc'); ?></p>
              <?php endif; ?>
                <?php endif; ?>
        </section>
        </div>

        <section class="dbvc-logging-controls">
          <h3><?php esc_html_e('Logging Controls', 'dbvc'); ?></h3>
          <form method="post" class="dbvc-logging-form">
            <?php wp_nonce_field('dbvc_logging_action', 'dbvc_logging_nonce'); ?>
            <input type="hidden" name="dbvc_logging_action" value="save_logging" />
            <label style="display:block;margin-bottom:0.75rem;">
              <input type="checkbox" name="dbvc_logging_enabled" value="1" <?php checked($logging_enabled, '1'); ?> />
              <?php esc_html_e('Enable Backup Logging', 'dbvc'); ?>
            </label>
            <label style="display:block;margin-bottom:0.75rem;">
              <?php esc_html_e('Log directory', 'dbvc'); ?><br>
              <input type="text" name="dbvc_logging_path" value="<?php echo esc_attr($logging_directory_raw); ?>" style="width:100%;max-width:420px;" placeholder="wp-content/dbvc-logs" />
              <br><small><?php esc_html_e('Leave blank to use wp-content/. Relative paths resolve from the WordPress root.', 'dbvc'); ?></small>
            </label>
            <label>
              <?php esc_html_e('Max log size (KB)', 'dbvc'); ?><br>
              <input type="number" name="dbvc_logging_max_size" value="<?php echo esc_attr($logging_max_size_kb); ?>" min="10" step="1" style="width:200px;" />
            </label>
            <?php if ($logging_effective_file) : ?>
              <p style="margin-top:0.75rem;"><small><?php printf(esc_html__('Current log file: %s', 'dbvc'), '<code>' . esc_html($logging_effective_file) . '</code>'); ?></small></p>
            <?php endif; ?>
            <?php submit_button(esc_html__('Save Logging Settings', 'dbvc'), 'secondary', 'dbvc_save_logging', false); ?>
          </form>

          <form method="post" style="margin-top:1rem;">
            <?php wp_nonce_field('dbvc_logging_action', 'dbvc_logging_nonce'); ?>
            <input type="hidden" name="dbvc_logging_action" value="delete_log" />
            <?php submit_button(esc_html__('Delete Log File', 'dbvc'), 'delete', 'dbvc_delete_log', false); ?>
          </form>
        </section>
      <?php endif; ?>
        </section>

        <section id="tab-logs" class="dbvc-tab-panel<?php echo $active_main_tab === 'tab-logs' ? ' is-active' : ''; ?>" data-dbvc-panel="tab-logs" role="tabpanel" aria-labelledby="dbvc-nav-tab-logs" <?php echo $active_main_tab === 'tab-logs' ? '' : 'hidden'; ?>>
          <h2><?php esc_html_e('Activity Logs', 'dbvc'); ?></h2>
<?php foreach (['error' => 'error', 'success' => 'success'] as $type => $class) :
  foreach ($logs_feedback[$type] ?? [] as $message) :
?>
          <div class="notice notice-<?php echo esc_attr($class); ?>"><p><?php echo esc_html($message); ?></p></div>
<?php endforeach; endforeach; ?>
<?php if (! class_exists('DBVC_Sync_Logger')) : ?>
          <p><?php esc_html_e('Logging component unavailable.', 'dbvc'); ?></p>
<?php else : ?>
          <p class="description">
            <?php
            if (! DBVC_Sync_Logger::is_core_logging_enabled()) {
                esc_html_e('Logging is currently disabled. Enable it under Configure → Import Defaults → Logging.', 'dbvc');
            } else {
                esc_html_e('Below is the latest DBVC log output. Use this to troubleshoot imports, uploads, and media sync.', 'dbvc');
            }
            ?>
          </p>
<?php
  $log_path    = DBVC_Sync_Logger::get_log_file_path();
  $log_exists  = $log_path && file_exists($log_path);
  if ($log_path) :
?>
          <p><strong><?php esc_html_e('Log file:', 'dbvc'); ?></strong> <code><?php echo esc_html($log_path); ?></code></p>
<?php endif; ?>
<?php if ($log_exists) :
  $log_contents = file_get_contents($log_path);
?>
          <textarea readonly style="width:100%;height:400px;"><?php echo esc_textarea($log_contents); ?></textarea>
          <form method="post" style="margin-top:1rem;">
            <?php wp_nonce_field('dbvc_clear_logs_action', 'dbvc_clear_logs_nonce'); ?>
            <?php submit_button(esc_html__('Clear Log', 'dbvc'), 'secondary', 'dbvc_clear_logs', false); ?>
          </form>
<?php else : ?>
          <p class="description"><?php esc_html_e('No log entries found yet.', 'dbvc'); ?></p>
<?php endif; ?>
<?php endif; ?>
        </section>

        <section id="tab-docs" class="dbvc-tab-panel<?php echo $active_main_tab === 'tab-docs' ? ' is-active' : ''; ?>" data-dbvc-panel="tab-docs" role="tabpanel" aria-labelledby="dbvc-nav-tab-docs" <?php echo $active_main_tab === 'tab-docs' ? '' : 'hidden'; ?>>
      <div class="dbvc-docs">
        <header class="dbvc-docs__header">
          <h2><?php esc_html_e('Docs & Workflows', 'dbvc'); ?></h2>
          <p class="dbvc-docs__summary"><?php esc_html_e('Quick-reference walkthroughs for exporting, importing, bundling media, and monitoring DBVC runs.', 'dbvc'); ?></p>
        </header>

        <nav class="dbvc-docs__quick-links" aria-label="<?php esc_attr_e('Documentation sections', 'dbvc'); ?>">
          <a href="#dbvc-docs-admin-app"><?php esc_html_e('Admin App Overview', 'dbvc'); ?></a>
          <a href="#dbvc-docs-terms"><?php esc_html_e('Taxonomy & Term Workflow', 'dbvc'); ?></a>
          <a href="#dbvc-docs-scenarios"><?php esc_html_e('Example Scenarios', 'dbvc'); ?></a>
          <a href="#dbvc-docs-monitoring"><?php esc_html_e('Monitoring & Logs', 'dbvc'); ?></a>
          <a href="#dbvc-docs-developer"><?php esc_html_e('Developer Integration', 'dbvc'); ?></a>
          <a href="#dbvc-docs-considerations"><?php esc_html_e('Key Considerations', 'dbvc'); ?></a>
        </nav>

        <article class="dbvc-docs__section" id="dbvc-docs-admin-app">
          <h3><?php esc_html_e('Admin App Overview', 'dbvc'); ?></h3>
          <p class="dbvc-docs__summary">
            <?php esc_html_e('The new DBVC Admin App brings the entire review workflow—entity diffs, resolver decisions, bulk tooling, and apply history—into one React experience.', 'dbvc'); ?>
          </p>
          <div class="dbvc-docs__card">
            <ul class="dbvc-docs__list">
              <li><?php esc_html_e('Entity Detail Drawer: Inspect diffs without leaving the table, toggle conflict-only view, and apply bulk accept/keep actions for posts and terms (taxonomy, slug, and parent context included).', 'dbvc'); ?></li>
              <li><?php esc_html_e('Resolver Workbench: Compare proposed vs current media, make per-asset decisions (reuse, download, skip), and batch-apply rules by reason, asset UID, or manifest path.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Global Resolver Rules: Inline add/edit forms with CSV import/export, duplicate detection, and search so recurring conflicts are settled once.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Virtualized Entity Table: Instant filtering/search across hundreds of entities without browser slowdown.', 'dbvc'); ?></li>
            </ul>
          </div>
          <div class="dbvc-docs__card">
            <h4><?php esc_html_e('Share-ready summary', 'dbvc'); ?></h4>
            <p>
              <?php esc_html_e('DBVC vNext gives teams a purpose-built “git for content” UI: reviewers browse diffs, flag conflicts, and sign off media decisions before a single row touches production. Restore safety nets stay in place, but now the entire review runs inside WordPress—with hashes, media checks, and resolver history baked in.', 'dbvc'); ?>
            </p>
            <p>
              <?php esc_html_e('For agencies or distributed teams, it means faster handoffs: export → review → apply becomes one continuous workflow, complete with logs, notifications, and snapshots. It’s the bridge between design/dev environments and editorial production without bolting on extra services.', 'dbvc'); ?>
            </p>
          </div>
        </article>

        <article class="dbvc-docs__section" id="dbvc-docs-terms">
          <h3><?php esc_html_e('Taxonomy & Term Workflow', 'dbvc'); ?></h3>
          <p class="dbvc-docs__summary">
            <?php esc_html_e('Terms now flow through the exact same export → review → import pipeline as posts, complete with hierarchy awareness and dedicated logging.', 'dbvc'); ?>
          </p>
          <div class="dbvc-docs__card">
            <ul class="dbvc-docs__list">
              <li><?php esc_html_e('Exporter writes `entity_refs` (UID + taxonomy/slug fallbacks) and `parent_uid` so the importer can recover matches even when only slugs exist.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Entity table + drawer display taxonomy, slug (`taxonomy/slug`), parent info, Accept/Keep status, and resolver badges for term entities.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Importer resolves parents via UID/slug/ID and queues unresolved parents for a second pass so child terms always attach to the correct hierarchy.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Configure → Import now includes “Include term-specific events in import logs” to capture detailed match/apply messages (requires logging to be enabled).', 'dbvc'); ?></li>
            </ul>
          </div>
          <div class="dbvc-docs__card">
            <h4><?php esc_html_e('QA checklist', 'dbvc'); ?></h4>
            <ol class="dbvc-docs__steps">
              <li><?php esc_html_e('Export a proposal with new + existing terms; confirm manifests list `entity_refs` and `parent_uid`.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Review in the Admin App, verifying taxonomy/parent context and Accept/Keep gating for new terms.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Run the import; inspect logs (when enabled) for term match, skip, and parent-resolve entries.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Reopen the proposal to ensure previously accepted terms remain marked for import.', 'dbvc'); ?></li>
            </ol>
          </div>
        </article>

        <article class="dbvc-docs__section" id="dbvc-docs-scenarios">
          <h3><?php esc_html_e('Example Scenarios', 'dbvc'); ?></h3>
          <p class="dbvc-docs__summary"><?php esc_html_e('Common UI flows with matching CLI notes where available.', 'dbvc'); ?></p>

          <div class="dbvc-docs__card">
            <h4><?php esc_html_e('1. Full Site Export (UI)', 'dbvc'); ?></h4>
            <ol class="dbvc-docs__steps">
              <li><?php esc_html_e('Open DBVC Export → Export/Download → Full Export.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Review filename format, masking, and mirror settings.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Click Run Full Export to regenerate JSON in the sync folder.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Commit or download the updated sync directory for your target site.', 'dbvc'); ?></li>
            </ol>
          </div>

          <div class="dbvc-docs__card">
            <h4><?php esc_html_e('2. Differential Export Between Releases', 'dbvc'); ?></h4>
            <ol class="dbvc-docs__steps">
              <li><?php esc_html_e('Go to Export/Download → Snapshots & Diff.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Select a baseline snapshot or choose the latest full export.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Click Run Diff Export to write only changed JSON files and register a snapshot.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Commit just the files that changed; unchanged content is skipped automatically.', 'dbvc'); ?></li>
            </ol>
            <p class="dbvc-docs__note"><?php esc_html_e('CLI equivalent:', 'dbvc'); ?></p>
            <pre><code>wp dbvc export --baseline=latest
wp dbvc export --baseline=123</code></pre>
          </div>

          <div class="dbvc-docs__card">
            <h4><?php esc_html_e('3. Chunked Export for Large Sites', 'dbvc'); ?></h4>
            <ol class="dbvc-docs__steps">
              <li><?php esc_html_e('Set a chunk size under Snapshots & Diff and click Start Chunked Export.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Process chunks from the job table or resume later via WP-CLI.', 'dbvc'); ?></li>
              <li><?php esc_html_e('When the processed count reaches zero, the manifest refresh and snapshot entry confirm completion.', 'dbvc'); ?></li>
            </ol>
          </div>

          <div class="dbvc-docs__card">
            <h4><?php esc_html_e('4. Importing JSON into Another Environment', 'dbvc'); ?></h4>
            <ol class="dbvc-docs__steps">
              <li><?php esc_html_e('Pull or upload the sync folder onto the target site.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Open Import/Upload → Content Import.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Choose filename filters, enable Smart Import to skip unchanged records, and toggle media retrieval.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Run the import and review the completion notice for content and media counts.', 'dbvc'); ?></li>
            </ol>
          </div>

          <div class="dbvc-docs__card">
            <h4><?php esc_html_e('5. Mirroring Domains & Media Retrieval', 'dbvc'); ?></h4>
            <ol class="dbvc-docs__steps">
              <li><?php esc_html_e('Configure the mirror domain under Configure → Import Defaults.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Pick a media transport mode: Auto, Bundled only, or Remote only.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Enable Retrieve missing media when running an import to sideload attachments.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Review logs for redirects, hash checks, and any blocked downloads.', 'dbvc'); ?></li>
            </ol>
          </div>

          <div class="dbvc-docs__card">
            <h4><?php esc_html_e('6. Restoring from a Backup Snapshot', 'dbvc'); ?></h4>
            <ol class="dbvc-docs__steps">
              <li><?php esc_html_e('Visit the Backup/Archive tab and select a snapshot folder.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Choose Restore or download the bundle before copying.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Run a standard import after the snapshot repopulates the sync directory.', 'dbvc'); ?></li>
              <li><?php esc_html_e('If the bundle includes media, ensure the transport mode allows bundled files.', 'dbvc'); ?></li>
            </ol>
          </div>

          <div class="dbvc-docs__card">
            <h4><?php esc_html_e('7. Media Bundles & Validation', 'dbvc'); ?></h4>
            <ol class="dbvc-docs__steps">
              <li><?php esc_html_e('Enable media bundling under Configure → Import Defaults.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Run an export to populate sync/media/YYYY/MM/ with hashed copies.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Use Clear Media Cache if you need to rebuild the bundle.', 'dbvc'); ?></li>
              <li><?php esc_html_e('During import, mismatches are surfaced in the activity log so you can retry.', 'dbvc'); ?></li>
            </ol>
          </div>
        </article>

        <article class="dbvc-docs__section" id="dbvc-docs-monitoring">
          <h3><?php esc_html_e('Monitoring & Logs', 'dbvc'); ?></h3>
          <ul class="dbvc-docs__list">
            <li><strong><?php esc_html_e('Activity log table', 'dbvc'); ?></strong> <code>wp_dbvc_activity_log</code> — <?php esc_html_e('Structured events for exports, imports, chunk progress, and media sync. Query with your database viewer or run', 'dbvc'); ?> <code>wp db query</code>.</li>
            <li><strong><?php esc_html_e('File log', 'dbvc'); ?></strong> <code>dbvc-backup.log</code> — <?php esc_html_e('Enable in Import Defaults to capture high-level notices. Turn on “Include term-specific events” if you want per-term match/parent logs (requires logging enabled on Backups tab).', 'dbvc'); ?></li>
            <li><strong><?php esc_html_e('Snapshots & Jobs UI', 'dbvc'); ?></strong> — <?php esc_html_e('The Snapshots view lists completed exports/imports and any active chunked jobs with inline resume controls.', 'dbvc'); ?></li>
          </ul>
        </article>

        <article class="dbvc-docs__section" id="dbvc-docs-developer">
          <h3><?php esc_html_e('Developer Integration', 'dbvc'); ?></h3>
          <p class="dbvc-docs__summary"><?php esc_html_e('Filters and actions let you tailor exports, masking, and automation.', 'dbvc'); ?></p>

<pre><code>&lt;?php
add_filter( 'dbvc_supported_post_types', function( $post_types ) {
    $post_types[] = 'my_custom_post_type';
    return $post_types;
});

add_filter( 'dbvc_excluded_option_keys', function( $excluded ) {
    $excluded[] = 'my_secret_api_key';
    return $excluded;
});

add_action( 'dbvc_after_export_post', function( $post_id, $post, $file_path ) {
    // Custom logic after each exported post.
}, 10, 3 );
</code></pre>
        </article>

        <article class="dbvc-docs__section" id="dbvc-docs-considerations">
          <h3><?php esc_html_e('Key Considerations', 'dbvc'); ?></h3>
          <div class="dbvc-docs__card">
            <h4><?php esc_html_e('Security', 'dbvc'); ?></h4>
            <ul class="dbvc-docs__list">
              <li><?php esc_html_e('Ensure the sync folder is writable only by trusted processes.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Review masking defaults so exports avoid sensitive data.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Only administrators (manage_options) can run DBVC tools.', 'dbvc'); ?></li>
            </ul>
          </div>
          <div class="dbvc-docs__card">
            <h4><?php esc_html_e('Performance', 'dbvc'); ?></h4>
            <ul class="dbvc-docs__list">
              <li><?php esc_html_e('Chunked exports and imports prevent timeouts on large datasets.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Built-in delays (0.1s export, 0.25s import) smooth out server load.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Snapshots record counts so you can track impact between runs.', 'dbvc'); ?></li>
            </ul>
          </div>
          <div class="dbvc-docs__card">
            <h4><?php esc_html_e('Data Integrity', 'dbvc'); ?></h4>
            <ul class="dbvc-docs__list">
              <li><?php esc_html_e('Hashes in manifest.json and the media index detect mismatched files.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Backups can restore JSON, manifests, and bundled media together.', 'dbvc'); ?></li>
              <li><?php esc_html_e('Rebuild bundles after moving media outside the sync path to avoid stale assets.', 'dbvc'); ?></li>
            </ul>
          </div>
        </article>
      </div>
    </section>

      </div>
    </div>
  </div><!-- .wrap -->

  <style>
    .dbvc-tabs { display:flex; flex-direction:column; gap:1.5rem; }
    .dbvc-tabs__nav { display:flex; gap:0.75rem; flex-wrap:wrap; margin:0; padding:0; }
    .dbvc-tabs__item { border:1px solid #dcdcde; border-radius:4px 4px 0 0; background:#f6f7f7; padding:0.65rem 1.25rem; font-weight:600; cursor:pointer; transition:box-shadow .15s ease, border-color .15s ease, background .15s ease; }
    .dbvc-tabs__item:hover,
    .dbvc-tabs__item:focus { border-color:#2271b1; color:#2271b1; outline:0; box-shadow:0 0 0 1px #2271b1; background:#fff; }
    .dbvc-tabs__item.is-active { background:#fff; border-color:#2271b1; border-bottom-color:#fff; box-shadow:0 -2px 0 0 #2271b1 inset; color:#1d2327; }
    .dbvc-tabs__panels { background:#fff; border:1px solid #dcdcde; border-radius:4px; padding:1.5rem; }
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
    .dbvc-backup-grid { display:grid; gap:1.5rem; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); margin-bottom:2rem; }
    .dbvc-backup-list table td form { display:inline-block; margin:0 0 0 0.25rem; }
    .dbvc-backup-list table td form:first-of-type { margin-left:0.5rem; }
    .dbvc-backup-detail { background:#f8f9fa; border:1px solid #dcdcde; border-radius:4px; padding:1rem 1.25rem; }
    .dbvc-backup-detail table { margin-top:1rem; }
    .dbvc-backup-meta p { margin:0 0 0.35rem 0; }
    .dbvc-backup-restore-form { margin:1rem 0; padding:1rem; border:1px solid #e2e4e7; border-radius:4px; background:#fff; }
    .dbvc-media-preview { margin:1.5rem 0; padding:1rem; border:1px solid #dcdcde; border-radius:4px; background:#fff; }
    .dbvc-media-preview__table { margin-top:1rem; }
    .dbvc-media-preview__blocked-list { margin:0.75rem 0 0 1.25rem; list-style:disc; }
    .dbvc-backup-pagination { display:flex; align-items:center; gap:0.75rem; margin:0.75rem 0; }
    .dbvc-logging-controls { border-top:1px solid #dcdcde; padding-top:1.5rem; margin-top:1.5rem; }
    .dbvc-docs { display:flex; flex-direction:column; gap:1.5rem; }
    .dbvc-docs__header h2 { margin:0 0 0.25rem; }
    .dbvc-docs__summary { margin:0; color:#50575e; }
    .dbvc-docs__quick-links { display:flex; flex-wrap:wrap; gap:0.5rem; padding:0.75rem 1rem; border:1px solid #dcdcde; border-radius:4px; background:#f6f7f7; }
    .dbvc-docs__quick-links a { font-weight:600; color:#135e96; text-decoration:none; }
    .dbvc-docs__quick-links a:hover,
    .dbvc-docs__quick-links a:focus { color:#0a4b78; text-decoration:underline; }
    .dbvc-docs__section { border:1px solid #dcdcde; border-radius:4px; padding:1.25rem; background:#fff; }
    .dbvc-docs__section h3 { margin-top:0; }
    .dbvc-docs__card { padding-top:1rem; margin-top:1rem; border-top:1px solid #e2e4e7; }
    .dbvc-docs__card:first-of-type { margin-top:0; padding-top:0; border-top:0; }
    .dbvc-docs__card h4 { margin:0 0 0.5rem; }
    .dbvc-docs__steps { margin:0 0 0 1.5rem; padding:0; }
    .dbvc-docs__list { margin:0.5rem 0 0 1.25rem; }
    .dbvc-docs__list li { margin:0.35rem 0; }
    .dbvc-docs__note { margin:0.75rem 0 0; font-style:italic; color:#3c434a; }
    .dbvc-docs pre { margin:0.75rem 0 0; padding:0.75rem 1rem; background:#1e1e1e; color:#f0f0f0; border-radius:4px; overflow:auto; }
    .dbvc-docs code { font-family:Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace; font-size:0.95em; }
    @media (max-width:782px) {
      .dbvc-tabs__nav { flex-direction:column; gap:0.5rem; }
      .dbvc-tabs__item { border-radius:4px; margin-bottom:0; }
      .dbvc-tabs__item.is-active { box-shadow:0 0 0 1px #2271b1; border-bottom-color:#2271b1; }
      .dbvc-subtabs { flex-direction:column; }
      .dbvc-subtabs-nav { flex:0 0 auto; flex-direction:row; flex-wrap:wrap; gap:0.5rem; }
      .dbvc-subtabs-nav__item { flex:1 1 160px; }
      .dbvc-backup-grid { grid-template-columns:1fr; }
      .dbvc-docs__quick-links { flex-direction:column; }
      .dbvc-docs__steps { margin-left:1.25rem; }
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

      const mainContainer = document.querySelector('.dbvc-tabs[data-dbvc-tabs]');
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
