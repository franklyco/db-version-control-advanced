<?php

/**
 * Get the sync path for exports
 *  
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com
 * @since     1.0.0
 */

// If this file is called directly, abort.
if (! defined('WPINC')) {
    die;
}

/**
 * Get the sync path for exports
 * 
 * @package   DB Version Control
 * @author    Robert DeVore <me@robertdevore.com>
 * @since     1.0.0
 * @return string
 */
class DBVC_Sync_Posts
{
    // Static variables
    protected static $imported_post_id_map = [];
    protected static $suppress_export_on_import = false;
    private static $needs_rewrite_flush = false;
    protected static $allow_clean_export_directory = false;
    protected static $deleting_posts = [];
    private const PROPOSAL_DECISIONS_OPTION = 'dbvc_proposal_decisions';
    private const AUTO_CLEAR_DECISIONS_OPTION = 'dbvc_auto_clear_decisions';
    private const IMPORT_RESULT_APPLIED = 'applied';
    private const IMPORT_RESULT_SKIPPED = 'skipped';
    private const PROPOSAL_NEW_ENTITIES_OPTION = 'dbvc_proposal_new_entities';
    private const MASK_SUPPRESS_OPTION = 'dbvc_masked_field_suppressions';
    private const MASK_OVERRIDES_OPTION = 'dbvc_mask_overrides';
    private static $pending_term_parent_links = [];

    /* @WIP Relationship remapping
    // protected static $relationship_field_keys = []; // You can populate this before calling import
    // protected static $relationship_field_updates = [];
*/

    /**
     * Ensure an entity UID is assigned whenever a post saves.
     *
     * @param int     $post_id
     * @param WP_Post $post
     * @return void
     */
    public static function ensure_post_uid_on_save($post_id, $post)
    {
        if (! $post instanceof \WP_Post) {
            return;
        }

        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        if (! self::is_supported_post_type($post->post_type)) {
            return;
        }

        self::ensure_post_uid($post_id, $post);
    }

    /**
     * Mark a post as being deleted to avoid export side effects during cleanup.
     *
     * @param int $post_id
     * @return void
     */
    public static function mark_post_deleting($post_id): void
    {
        $post_id = (int) $post_id;
        if (! $post_id) {
            return;
        }
        self::$deleting_posts[$post_id] = true;
    }

    /**
     * Check if a post is currently being deleted.
     *
     * @param int $post_id
     * @return bool
     */
    public static function is_post_deleting($post_id): bool
    {
        $post_id = (int) $post_id;
        if (! $post_id) {
            return false;
        }
        return ! empty(self::$deleting_posts[$post_id]);
    }

    /**
     * Update entity registry status for a post without creating new UIDs.
     *
     * @param int $post_id
     * @param string $status
     * @return void
     */
    public static function update_entity_status_for_post($post_id, $status, $force_supported = false): void
    {
        $post_id = (int) $post_id;
        if (! $post_id || ! class_exists('DBVC_Database')) {
            return;
        }

        $post = get_post($post_id);
        if (! $post || ! is_object($post)) {
            return;
        }

        if (! $force_supported && ! self::is_supported_post_type($post->post_type)) {
            return;
        }

        $uid = get_post_meta($post_id, 'vf_object_uid', true);
        $uid = is_string($uid) ? trim($uid) : '';
        if ($uid === '') {
            $history = get_post_meta($post_id, 'dbvc_post_history', true);
            if (is_array($history) && ! empty($history['vf_object_uid'])) {
                $uid = trim((string) $history['vf_object_uid']);
            }
        }
        if ($uid === '') {
            $uid = get_post_meta($post_id, 'uuid', true);
            $uid = is_string($uid) ? trim($uid) : '';
        }
        if ($uid === '' && $force_supported) {
            $uid = wp_generate_uuid4();
            update_post_meta($post_id, 'vf_object_uid', $uid);
        }
        if ($uid === '') {
            return;
        }

        DBVC_Database::upsert_entity([
            'entity_uid'    => $uid,
            'object_id'     => $post_id,
            'object_type'   => $post->post_type,
            'object_status' => $status !== '' ? $status : null,
        ]);
    }

    /**
     * Retrieve or generate a persistent UID for a post.
     *
     * @param int          $post_id
     * @param WP_Post|null $post
     * @return string
     */
    public static function ensure_post_uid($post_id, $post = null)
    {
        $post_id = (int) $post_id;
        if (! $post_id) {
            return '';
        }

        $post_type = $post instanceof \WP_Post ? $post->post_type : get_post_type($post_id);
        if (! self::is_supported_post_type($post_type)) {
            return '';
        }

        $uid = get_post_meta($post_id, 'vf_object_uid', true);
        if (! is_string($uid) || $uid === '') {
            $uid = wp_generate_uuid4();
            update_post_meta($post_id, 'vf_object_uid', $uid);
        }

        self::sync_entity_registry($uid, $post_id, $post_type);

        $history = get_post_meta($post_id, 'dbvc_post_history', true);
        if (is_array($history) && (! isset($history['vf_object_uid']) || $history['vf_object_uid'] === '')) {
            $history['vf_object_uid'] = $uid;
            update_post_meta($post_id, 'dbvc_post_history', $history);
        }

        return $uid;
    }

    /**
     * Resolve the local post ID for a given original ID.
     *
     * @param int $original_id
     * @return int|null
     */
    public static function resolve_local_post_id($original_id, $entity_uid = '', $post_type = '')
    {
        $original_id = (int) $original_id;
        $entity_uid  = is_string($entity_uid) ? trim($entity_uid) : '';

        $use_uid_matching = (get_option('dbvc_prefer_entity_uids', '0') === '1');
        if ($entity_uid !== '' && ($use_uid_matching || $original_id === 0)) {
            $by_uid = self::find_post_id_by_uid($entity_uid, $post_type);
            if ($by_uid) {
                return $by_uid;
            }
        }

        if ($original_id && isset(self::$imported_post_id_map[$original_id])) {
            return (int) self::$imported_post_id_map[$original_id];
        }

        return $original_id ?: null;
    }

    /**
     * Diagnose how (or if) a proposal entity maps to a local post.
     *
     * @param array $context
     * @return array{post_id:?int,match_source:string}
     */
    public static function identify_local_entity(array $context): array
    {
        $vf_object_uid = isset($context['vf_object_uid']) ? trim((string) $context['vf_object_uid']) : '';
        $post_type     = isset($context['post_type']) ? sanitize_key($context['post_type']) : '';
        $original_id   = isset($context['post_id']) ? (int) $context['post_id'] : 0;
        $slug_source   = isset($context['post_name']) ? sanitize_title($context['post_name']) : '';

        $references   = isset($context['entity_refs']) && is_array($context['entity_refs'])
            ? array_values($context['entity_refs'])
            : [];

        $match_source = 'none';
        $post_id      = null;

        if ($vf_object_uid !== '') {
            $found = self::find_post_id_by_uid($vf_object_uid, $post_type);
            if ($found) {
                $post_id = $found;
                $match_source = 'uid';
            }
        }

        if (! $post_id && $original_id) {
            $candidate = get_post($original_id);
            if ($candidate instanceof \WP_Post && ($post_type === '' || $candidate->post_type === $post_type)) {
                $post_id = (int) $candidate->ID;
                $match_source = 'id';
            }
        }

        if (! $post_id && $slug_source !== '') {
            $found = self::find_post_id_by_slug($slug_source, $post_type);
            if ($found) {
                $post_id = $found;
                $match_source = 'slug';
            }
        }

        if (! $post_id && ! empty($references)) {
            foreach ($references as $reference) {
                $type  = isset($reference['type']) ? (string) $reference['type'] : '';
                $value = isset($reference['value']) ? (string) $reference['value'] : '';
                if ($type === '' || $value === '') {
                    continue;
                }
                if ($type === 'post_slug') {
                    [$ref_post_type, $ref_slug] = self::parse_entity_reference_value($value);
                    $ref_slug = sanitize_title($ref_slug);
                    if ($ref_slug === '') {
                        continue;
                    }
                    $candidate = self::find_post_id_by_slug($ref_slug, $ref_post_type ?: $post_type);
                    if ($candidate) {
                        $post_id = $candidate;
                        $match_source = 'slug';
                        break;
                    }
                } elseif ($type === 'post_id') {
                    [$ref_post_type, $ref_id_raw] = self::parse_entity_reference_value($value);
                    $ref_id = (int) $ref_id_raw;
                    if ($ref_id <= 0) {
                        continue;
                    }
                    $candidate = get_post($ref_id);
                    if ($candidate instanceof \WP_Post) {
                        if ($ref_post_type === '' || $candidate->post_type === $ref_post_type || $post_type === '' || $candidate->post_type === $post_type) {
                            $post_id = (int) $candidate->ID;
                            $match_source = 'id';
                            break;
                        }
                    }
                }
            }
        }

        return [
            'post_id'      => $post_id ? (int) $post_id : null,
            'match_source' => $match_source,
        ];
    }

    /**
     * Persist entity UID mapping to custom table.
     *
     * @param string $entity_uid
     * @param int    $post_id
     * @param string $post_type
     * @return void
     */
    private static function sync_entity_registry($entity_uid, $post_id, $post_type = '')
    {
        if ($entity_uid === '' || ! class_exists('DBVC_Database')) {
            return;
        }

        if ($post_type && ! self::is_supported_post_type($post_type)) {
            return;
        }

        DBVC_Database::upsert_entity([
            'entity_uid'    => $entity_uid,
            'object_id'     => (int) $post_id ?: null,
            'object_type'   => $post_type ?: get_post_type($post_id),
            'object_status' => get_post_status($post_id),
        ]);
    }

    /**
     * Locate a local post ID by entity UID (database row or meta).
     *
     * @param string $entity_uid
     * @param string $post_type
     * @return int|null
     */
    private static function find_post_id_by_uid($entity_uid, $post_type = '')
    {
        $entity_uid = trim((string) $entity_uid);
        if ($entity_uid === '') {
            return null;
        }

        if (class_exists('DBVC_Database')) {
            $record = DBVC_Database::get_entity_by_uid($entity_uid);
            if ($record && ! empty($record->object_id)) {
                $candidate = get_post((int) $record->object_id);
                if ($candidate instanceof \WP_Post) {
                    return (int) $candidate->ID;
                }
            }
        }

        $query_args = [
            'post_type'      => $post_type ? [$post_type] : self::get_supported_post_types(),
            'post_status'    => 'any',
            'meta_key'       => 'vf_object_uid',
            'meta_value'     => $entity_uid,
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ];

        $found = get_posts($query_args);
        if (! empty($found)) {
            return (int) $found[0];
        }

        return null;
    }

    /**
     * Try to map a slug to a local post ID.
     *
     * @param string $slug
     * @param string $post_type
     * @return int|null
     */
    private static function find_post_id_by_slug($slug, $post_type = '')
    {
        $slug = sanitize_title($slug);
        if ($slug === '') {
            return null;
        }

        $post_types = $post_type ? [$post_type] : self::get_supported_post_types();

        foreach ($post_types as $type) {
            $candidate = get_page_by_path($slug, OBJECT, $type);
            if ($candidate instanceof \WP_Post) {
                return (int) $candidate->ID;
            }
        }

        $query_args = [
            'post_type'      => $post_types,
            'name'           => $slug,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'suppress_filters' => true,
        ];

        $found = get_posts($query_args);
        if (! empty($found)) {
            return (int) $found[0];
        }

        return null;
    }

    /**
     * Determine if a post type is eligible for DBVC syncing.
     *
     * @param string $post_type
     * @return bool
     */
    private static function is_supported_post_type($post_type)
    {
        if ($post_type === '') {
            return false;
        }

        $supported = self::get_supported_post_types();
        return in_array($post_type, $supported, true);
    }

    /**
     * Check the sync folder path.
     * 
     * @since  1.1.0
     * @return array
     */
    public static function get_sync_folder_path()
    {
        $path = get_option('dbvc_sync_path');

        if (!empty($path)) {
            $absolute_path = ABSPATH . ltrim($path, '/\\');
            return rtrim($absolute_path, DIRECTORY_SEPARATOR);
        }

        // Fallback if path isn't set — optional
        return plugin_dir_path(DBVC_PLUGIN_FILE) . 'sync';
    }

    /**
     * Get the selected post types for export/import.
     * 
     * @since  1.0.0
     * @return array
     */
    public static function get_supported_post_types()
    {
        $selected_types = get_option('dbvc_post_types', []);

        // If no post types are selected, default to post, page, and FSE types.
        if (empty($selected_types)) {
            $selected_types = ['post', 'page', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation'];
        }

        // Allow other plugins to modify supported post types.
        return apply_filters('dbvc_supported_post_types', $selected_types);
    }

    /**
     * Persist an import hash/meta state for a post.
     *
     * @param int    $post_id
     * @param string $content_hash
     * @return bool
     */
    public static function store_import_hash($post_id, $content_hash)
    {
        $post_id = (int) $post_id;
        $content_hash = (string) $content_hash;
        if (! $post_id || $content_hash === '') {
            return false;
        }

        update_post_meta($post_id, '_dbvc_import_hash', $content_hash);

        $history = get_post_meta($post_id, 'dbvc_post_history', true);
        if (! is_array($history)) {
            $history = [];
        }

        $history['hash']        = $content_hash;
        $history['status']      = isset($history['status']) ? $history['status'] : 'existing';
        $history['imported_at'] = current_time('mysql');

        update_post_meta($post_id, 'dbvc_post_history', $history);

        return true;
    }

    /**
     * Ensure .htaccess and index.php exist in the given directory to block public access.
     *
     * @param string $path Full absolute path to the directory.
     * @since 1.1.0
     */
    public static function ensure_directory_security($path)
    {
        if (function_exists('dbvc_is_sync_ftp_window_active') && dbvc_is_sync_ftp_window_active()) {
            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $htaccess_path = $path . '/.htaccess';
        $index_path    = $path . '/index.php';

        if (! file_exists($htaccess_path)) {
            $htaccess = <<<HT
# Block direct access to DBVC sync files
Order allow,deny
Deny from all

<IfModule mod_authz_core.c>
    Require all denied
</IfModule>

Options -Indexes
HT;
            file_put_contents($htaccess_path, $htaccess);
            error_log('[DBVC] Secured folder with .htaccess: ' . $path);
        }

        if (! file_exists($index_path)) {
            file_put_contents($index_path, "<?php\n// Silence is golden.\nexit;");
            error_log('[DBVC] Added index.php to prevent directory listing: ' . $path);
        }
    }

    // Helper Function for Folder Path Checking
    public static function is_safe_file_path($path)
    {
        $base = realpath(self::get_sync_folder_path());
        $real = realpath($path);
        return $base && $real && strpos($real, $base) === 0;
    }

    /**
     * Normalize a requested filename mode into one of the allowed values.
     *
     * @param mixed $mode Potential mode from UI, filters, or legacy boolean.
     * @since 1.2.0
     */
    protected static function normalize_filename_mode($mode = null)
    {
        $allowed = apply_filters('dbvc_allowed_export_filename_formats', ['id', 'slug', 'slug_id']);
        if (! is_array($allowed) || empty($allowed)) {
            $allowed = ['id', 'slug', 'slug_id'];
        }

        if (is_string($mode) && in_array($mode, $allowed, true)) {
            return $mode;
        }

        if ($mode === true && in_array('slug', $allowed, true)) {
            return 'slug';
        }

        if ($mode === false || $mode === null || $mode === '') {
            $preferred = dbvc_get_export_filename_format();
            if (in_array($preferred, $allowed, true)) {
                return $preferred;
            }
        }

        return in_array('id', $allowed, true) ? 'id' : reset($allowed);
    }

    /**
     * Determine the filename fragment to use before sanitization.
     *
     * @param int      $post_id
     * @param \WP_Post $post
     * @param string   $mode     id|slug|slug_id
     * @param bool     $log_fallback Whether to log when falling back to ID.
     * @since 1.2.0
     */
    protected static function determine_filename_part($post_id, $post, $mode, $log_fallback = true)
    {
        $post_id = absint($post_id);
        $slug    = is_object($post) ? sanitize_title($post->post_name) : '';

        if ($mode === 'slug_id') {
            if ($slug !== '' && ! is_numeric($slug)) {
                return $slug . '-' . $post_id;
            }
            if ($log_fallback) {
                error_log("[DBVC] Warning: Falling back to ID for post {$post_id} due to invalid slug for slug+ID format: '{$slug}'");
            }
            return (string) $post_id;
        }

        if ($mode === 'slug') {
            if ($slug !== '' && ! is_numeric($slug)) {
                return $slug;
            }
            if ($log_fallback) {
                error_log("[DBVC] Warning: Falling back to ID for post {$post_id} due to invalid slug: '{$slug}'");
            }
            return (string) $post_id;
        }

        return (string) $post_id;
    }

    /**
     * Resolve export filename components for a post.
     *
     * @param int      $post_id
     * @param \WP_Post $post
     * @param mixed    $mode_request Requested mode from UI/filter.
     * @param bool     $log_fallback Whether to log when falling back to ID.
     * @since 1.2.0
     * @return array{mode:string,filename_part:string,filename:string}
     */
    public static function resolve_filename_components($post_id, $post, $mode_request = null, $log_fallback = true)
    {
        $normalized = self::normalize_filename_mode($mode_request);
        $filtered   = apply_filters('dbvc_export_filename_mode', $normalized, $post_id, $post);
        $final_mode = self::normalize_filename_mode($filtered);

        $part = self::determine_filename_part($post_id, $post, $final_mode, $log_fallback);
        $part = apply_filters('dbvc_export_filename_part', $part, $post_id, $post, $final_mode);

        if (! is_string($part) || $part === '') {
            $part = (string) absint($post_id);
        }

        $filename = sanitize_file_name($post->post_type . '-' . $part . '.json');

        return [
            'mode'          => $final_mode,
            'filename_part' => $part,
            'filename'      => $filename,
        ];
    }

    /**
     * Extract the filename token (between post type prefix and extension).
     *
     * @param string $filepath  Absolute path to the JSON file.
     * @param string $post_type Expected post type.
     * @since 1.2.0
     * @return string
     */
    protected static function extract_filename_token($filepath, $post_type)
    {
        $basename = basename($filepath);
        if (substr($basename, -5) === '.json') {
            $basename = substr($basename, 0, -5);
        }

        $prefix = sanitize_key($post_type) . '-';
        if (strpos($basename, $prefix) !== 0) {
            return $basename;
        }

        return substr($basename, strlen($prefix));
    }

    /**
     * Analyse an import filename against JSON data to detect its format.
     *
     * @param string $filepath
     * @param string $post_type
     * @param array  $data       Decoded JSON payload.
     * @since 1.2.0
     * @return array{mode:string,part:string,slug:string,id:string}
     */
    protected static function analyze_import_filename($filepath, $post_type, array $data)
    {
        $part = self::extract_filename_token($filepath, $post_type);
        $id   = isset($data['ID']) ? (string) absint($data['ID']) : '';
        $slug = isset($data['post_name']) ? sanitize_title($data['post_name']) : '';

        if ($slug !== '' && $id !== '' && $part === $slug . '-' . $id) {
            return ['mode' => 'slug_id', 'part' => $part, 'slug' => $slug, 'id' => $id];
        }

        if ($slug !== '' && $part === $slug) {
            return ['mode' => 'slug', 'part' => $part, 'slug' => $slug, 'id' => $id];
        }

        if ($id !== '' && $part === $id) {
            return ['mode' => 'id', 'part' => $part, 'slug' => $slug, 'id' => $id];
        }

        if ($slug !== '' && $id !== '' && strpos($part, $slug . '-') === 0 && preg_match('/-\d+$/', $part)) {
            return ['mode' => 'slug_id', 'part' => $part, 'slug' => $slug, 'id' => $id];
        }

        if (preg_match('/^\d+$/', $part)) {
            return ['mode' => 'id', 'part' => $part, 'slug' => $slug, 'id' => $id];
        }

        return ['mode' => 'slug', 'part' => $part, 'slug' => $slug, 'id' => $id];
    }

    /**
     * Determine if an import filename should be processed for the requested mode.
     *
     * @param string      $target_mode Normalized target mode or null for all.
     * @param string      $filepath
     * @param string      $post_type
     * @param array       $data        Decoded JSON payload.
     * @since 1.2.0
     * @return bool
     */
    protected static function import_filename_matches_mode($target_mode, $filepath, $post_type, array $data)
    {
        if ($target_mode === null) {
            return true;
        }

        $analysis = self::analyze_import_filename($filepath, $post_type, $data);
        if ($analysis['mode'] === $target_mode) {
            return true;
        }

        // When exporting with slug format but slug is empty/numeric we fall back to ID.
        if (
            $target_mode === 'slug'
            && $analysis['mode'] === 'id'
            && ($analysis['slug'] === '' || is_numeric($analysis['slug']))
        ) {
            return true;
        }

        return false;
    }


    /* 
    * @WIP Relationship remapping
    *
    Remapping for ACF relationship fields based on newly created posts if applicable
    // Added: 08-14-2025
    
    public static function remap_relationship_fields(array $relationship_field_keys = [])
    {
        if (empty(self::$imported_post_id_map) || empty($relationship_field_keys)) {
            return;
        }

        foreach (self::$imported_post_id_map as $old_id => $new_id) {
            foreach ($relationship_field_keys as $field_key) {
                $value = get_post_meta($new_id, $field_key, true);

                if (empty($value)) {
                    continue;
                }

                $remapped_value = self::recursive_remap_relationships($value);

                if ($remapped_value !== $value) {
                    update_post_meta($new_id, $field_key, $remapped_value);
                    self::$relationship_field_updates[$new_id][] = $field_key;
                    error_log("[DBVC] Remapped field '{$field_key}' for post {$new_id}");
                }
            }
        }
    }

    // Helper to handle remapping nested values in relationship fields
    protected static function recursive_remap_relationships($value)
    {
        if (is_numeric($value)) {
            return self::$imported_post_id_map[$value] ?? $value;
        }

        if (is_array($value)) {
            $new = [];
            foreach ($value as $k => $v) {
                $new[$k] = self::recursive_remap_relationships($v);
            }
            return $new;
        }

        return $value;
    }

    // Remaps across all posts
    public static function remap_relationship_ids_across_posts($remap_ids, $post_ids)
    {
        foreach ($post_ids as $post_id) {
            $meta = get_post_meta($post_id);
            foreach ($meta as $key => $value) {
                $updated_value = self::deep_replace_ids($value, $remap_ids);
                if ($updated_value !== $value) {
                    update_post_meta($post_id, $key, $updated_value);
                }
            }
        }
    }
    */

    // Export Taxonomy
    public static function export_tax_input_portable($post_id, $post_type)
    {
        $out = [];
        $taxes = get_object_taxonomies($post_type, 'objects');
        foreach ($taxes as $tax => $tx) {
            $terms = wp_get_object_terms($post_id, $tax, ['hide_empty' => false]);
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }
            foreach ($terms as $t) {
                $parent_slug = '';
                if ($t->parent) {
                    $p = get_term($t->parent, $tax);
                    if ($p && !is_wp_error($p)) {
                        $parent_slug = $p->slug;
                    }
                }
                $out[$tax][] = [
                    'slug'   => $t->slug,
                    'name'   => $t->name,
                    'parent' => $parent_slug ?: null,
                ];
            }
        }
        return $out;
    }


    /**
     * Process backups folder to uploads - up to 10 timestamped backups with auto delete oldest
     * 
     * @since  1.1.0
     * @return void
     */
    public static function dbvc_create_backup_folder_and_copy_exports()
    {
        $export_dir  = dbvc_get_sync_path();
        $upload_dir  = wp_upload_dir();
        $sync_dir    = $upload_dir['basedir'] . '/sync';
        $backup_base = $sync_dir . '/db-version-control-backups';
        $timestamp   = date('m-d-Y-His');
        $backup_path = $backup_base . '/' . $timestamp;

        error_log('[DBVC] Attempting backup to: ' . $backup_path);

        // Ensure top-level /sync/ folder has .htaccess to disable indexing
        if (is_dir($sync_dir)) {
            $sync_htaccess = $sync_dir . '/.htaccess';
            if (! file_exists($sync_htaccess)) {
                file_put_contents($sync_htaccess, "Options -Indexes\n");
                error_log('[DBVC] Created .htaccess in /sync/ folder');
            }
        }

        // Create backup folder
        if (! file_exists($backup_path)) {
            if (wp_mkdir_p($backup_path)) {
                error_log('[DBVC] Created backup folder: ' . $backup_path);

                // Add enhanced .htaccess file to restrict all access (Apache 2.2 + 2.4+ compatible)
                $htaccess = <<<HT
# Protect DBVC backup files from direct web access
Order allow,deny
Deny from all

<IfModule mod_authz_core.c>
    Require all denied
</IfModule>

Options -Indexes
HT;
                file_put_contents($backup_path . '/.htaccess', $htaccess);
                error_log('[DBVC] Created .htaccess in backup folder');

                // Add index.php to prevent directory browsing
                $index_php = "<?php\n// Silence is golden.\nexit;";
                file_put_contents($backup_path . '/index.php', $index_php);
                error_log('[DBVC] Created index.php in backup folder');
            } else {
                error_log('[DBVC] ERROR: Failed to create backup folder: ' . $backup_path);
                return;
            }
        }

        // Copy JSON files from export directory recursively
        $json_files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($export_dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($json_files as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $relative_path = str_replace($export_dir, '', $file->getPathname());
            $dest_path     = $backup_path . '/' . ltrim($relative_path, '/');

            // Ensure destination subdirectory exists
            $dest_dir = dirname($dest_path);
            if (! file_exists($dest_dir)) {
                wp_mkdir_p($dest_dir);
            }

            $copy_result = copy($file->getPathname(), $dest_path);
            if ($copy_result) {
                error_log("[DBVC] Copied {$relative_path} to {$dest_path}");
            } else {
                error_log("[DBVC] ERROR: Failed to copy {$relative_path} to {$dest_path}");
            }
        }

        // Generate manifest snapshot for this backup
        if (class_exists('DBVC_Backup_Manager')) {
            DBVC_Backup_Manager::generate_manifest($backup_path);
        }

        // Cleanup: Keep only 10 latest backup folders
        $folders = glob($backup_base . '/*', GLOB_ONLYDIR);
        usort($folders, function ($a, $b) {
            return filemtime($b) <=> filemtime($a);
        });

        $locked_names = [];
        if (class_exists('DBVC_Backup_Manager')) {
            $locked_names = array_flip(DBVC_Backup_Manager::get_locked());
        }

        $kept       = 0;
        $old_folders = [];

        foreach ($folders as $folder_path) {
            $folder_name = basename($folder_path);
            if (isset($locked_names[$folder_name])) {
                continue; // Locked backups never count toward rotation.
            }

            $kept++;
            if ($kept > 10) {
                $old_folders[] = $folder_path;
            }
        }

        foreach ($old_folders as $old_folder) {
            self::delete_folder_recursive($old_folder);
            error_log("[DBVC] Deleted old backup folder: $old_folder");
        }
    }

    private static function delete_folder_recursive($folder)
    {
        if (! is_dir($folder)) return;
        foreach (array_diff(scandir($folder), ['.', '..']) as $item) {
            $path = $folder . '/' . $item;
            is_dir($path) ? self::delete_folder_recursive($path) : unlink($path);
        }
        rmdir($folder);
    }

    /**
     * Copy an entire backup folder to the active sync directory.
     *
     * @param string $backup_path Absolute backup path.
     * @param bool   $wipe_first  Whether to empty the sync directory first.
     * @return void
     */
    public static function copy_backup_to_sync($backup_path, $wipe_first = true)
    {
        $sync_dir = dbvc_get_sync_path();
        if ($wipe_first && is_dir($sync_dir)) {
            self::delete_folder_contents($sync_dir);
        }
        self::recursive_copy($backup_path, $sync_dir);

        // Remove manifest file if copied over.
        $manifest_path = trailingslashit($sync_dir) . DBVC_Backup_Manager::MANIFEST_FILENAME;
        if (file_exists($manifest_path)) {
            @unlink($manifest_path);
        }
    }

    public static function import_resolver_decisions_from_manifest(array $manifest, $proposal_id = ''): void
    {
        if (empty($manifest['resolver_decisions']) || ! is_array($manifest['resolver_decisions'])) {
            return;
        }

        $bundle = $manifest['resolver_decisions'];
        $store  = get_option('dbvc_resolver_decisions', []);

        if ($proposal_id === '' && ! empty($manifest['backup_name'])) {
            $proposal_id = sanitize_text_field($manifest['backup_name']);
        }

        if ($proposal_id !== '' && isset($bundle['proposal']) && is_array($bundle['proposal'])) {
            $store[$proposal_id] = $bundle['proposal'];
        }

        if (isset($bundle['global']) && is_array($bundle['global'])) {
            if (! isset($store['__global']) || ! is_array($store['__global'])) {
                $store['__global'] = [];
            }
            foreach ($bundle['global'] as $original_id => $decision) {
                $store['__global'][$original_id] = $decision;
            }
        }

        update_option('dbvc_resolver_decisions', $store, false);
    }

    /**
     * Restore content from a stored backup folder.
     *
     * @param string $backup_name Folder name inside the backup base directory.
     * @param array  $options     Supported keys: partial (bool), mode (full|copy|partial).
     * @return array|WP_Error
     */
    public static function import_backup($backup_name, array $options = [])
    {
        if (! current_user_can('manage_options')) {
            return new WP_Error('dbvc_forbidden', __('You do not have permission to restore backups.', 'dbvc'));
        }

        if (! class_exists('DBVC_Backup_Manager')) {
            return new WP_Error('dbvc_missing_manager', __('Backup manager is unavailable.', 'dbvc'));
        }

        $backup_name = sanitize_text_field($backup_name);
        $base        = DBVC_Backup_Manager::get_base_path();
        $backup_path = trailingslashit($base) . $backup_name;

        if (! is_dir($backup_path)) {
            return new WP_Error('dbvc_backup_missing', __('The selected backup could not be found.', 'dbvc'));
        }

        $defaults = [
            'mode'    => 'full', // full | partial | copy
        ];
        $options = wp_parse_args($options, $defaults);
        $force_reapply_new_posts = array_key_exists('force_reapply_new_posts', $options)
            ? (bool) $options['force_reapply_new_posts']
            : (get_option('dbvc_force_reapply_new_posts', '0') === '1');
        $allow_term_creation_option = get_option('dbvc_auto_create_terms', '1');
        $allow_term_creation = in_array($allow_term_creation_option, ['1', 1, true], true);

        $manifest = DBVC_Backup_Manager::read_manifest($backup_path);
        if (! $manifest) {
            return new WP_Error('dbvc_manifest_missing', __('Backup manifest is missing or unreadable.', 'dbvc'));
        }

        if (class_exists('\Dbvc\Media\BundleManager')) {
            \Dbvc\Media\BundleManager::ingest_from_backup($backup_name, $backup_path);
        }

        self::import_resolver_decisions_from_manifest($manifest, $backup_name);

        $decision_store = get_option(self::PROPOSAL_DECISIONS_OPTION, []);
        if (! is_array($decision_store)) {
            $decision_store = [];
        }
        $proposal_decisions = isset($decision_store[$backup_name]) && is_array($decision_store[$backup_name])
            ? $decision_store[$backup_name]
            : [];
        $auto_clear_decisions = get_option(self::AUTO_CLEAR_DECISIONS_OPTION, '1') === '1';
        $has_entity_decisions = false;
        if (! empty($proposal_decisions)) {
            foreach ($proposal_decisions as $entity_key => $entity_decision) {
                if (! is_array($entity_decision)) {
                    continue;
                }
                $entity_key = (string) $entity_key;
                if ($entity_key !== '' && strpos($entity_key, '__') === 0) {
                    continue;
                }
                if (! empty($entity_decision)) {
                    $has_entity_decisions = true;
                    break;
                }
            }
        }
        $proposal_processed = false;

        $mask_suppress_store = get_option(self::MASK_SUPPRESS_OPTION, []);
        $mask_override_store = get_option(self::MASK_OVERRIDES_OPTION, []);
        $proposal_mask_suppress = isset($mask_suppress_store[$backup_name]) && is_array($mask_suppress_store[$backup_name])
            ? $mask_suppress_store[$backup_name]
            : [];
        $proposal_mask_overrides = isset($mask_override_store[$backup_name]) && is_array($mask_override_store[$backup_name])
            ? $mask_override_store[$backup_name]
            : [];

        $proposal_mask_suppress = self::normalize_mask_directive_store($proposal_mask_suppress);
        $proposal_mask_overrides = self::normalize_mask_directive_store($proposal_mask_overrides);

        $mode        = $options['mode'];
        $ignore_missing_hash = ! empty($options['ignore_missing_hash']);
        $imported    = 0;
        $skipped     = 0;
        $errors      = [];
        $items_total = isset($manifest['items']) ? count($manifest['items']) : 0;
        $media_reconcile = [];

        if ($mode === 'copy') {
            self::copy_backup_to_sync($backup_path, true);
            DBVC_Sync_Logger::log('Backup copied to sync directory', ['backup' => $backup_name]);

            return [
                'imported' => 0,
                'skipped'  => $items_total,
                'mode'     => $mode,
            ];
        }

        $targets = [];
        if ($mode === 'partial') {
            if (! $ignore_missing_hash && ! empty($manifest['totals']['missing_import_hash'])) {
                return new WP_Error(
                    'dbvc_partial_blocked',
                    __('Partial restore aborted: at least one item is missing its import hash.', 'dbvc')
                );
            }
            if ($ignore_missing_hash && ! empty($manifest['totals']['missing_import_hash'])) {
                if (class_exists('DBVC_Sync_Logger') && method_exists('DBVC_Sync_Logger', 'log_import')) {
                    DBVC_Sync_Logger::log_import('Partial restore ignoring missing hashes', [
                        'proposal' => $backup_name,
                        'missing'  => (int) $manifest['totals']['missing_import_hash'],
                    ]);
                }
            }

            foreach ($manifest['items'] as $item) {
                if (($item['item_type'] ?? '') !== 'post') {
                    $skipped++;
                    continue;
                }

                $post_id = (int) ($item['post_id'] ?? 0);
                if (! $post_id) {
                    $skipped++;
                    continue;
                }

                $current_hash = get_post_meta($post_id, '_dbvc_import_hash', true);
                $content_hash = $item['content_hash'] ?? '';

                if ($content_hash && $current_hash === $content_hash) {
                    $skipped++;
                    continue;
                }

                $targets[] = $item;
            }

            if (empty($targets)) {
                return [
                    'imported' => 0,
                    'skipped'  => $items_total,
                    'mode'     => $mode,
                    'message'  => __('No changes detected – nothing to restore.', 'dbvc'),
                ];
            }
        } else {
            $targets = $manifest['items'];
        }

        if ($mode === 'full') {
            self::copy_backup_to_sync($backup_path, true);
        }

        if (class_exists('\Dbvc\Media\Reconciler')) {
            try {
                $media_reconcile = \Dbvc\Media\Reconciler::enqueue($backup_name, $manifest, [
                    'allow_remote' => class_exists('DBVC_Media_Sync') ? DBVC_Media_Sync::allow_external_sources() : false,
                    'backup_path'  => $backup_path,
                ]);
            } catch (\Throwable $media_exception) {
                if (class_exists('DBVC_Sync_Logger') && method_exists('DBVC_Sync_Logger', 'log_media')) {
                    DBVC_Sync_Logger::log_media('Media reconciliation failed', [
                        'proposal' => $backup_name,
                        'error'    => $media_exception->getMessage(),
                    ]);
                }
            }
        }

        self::$suppress_export_on_import = true;
        try {
            self::$pending_term_parent_links = [];
            $applied_entities = 0;
            foreach ($targets as $entry) {
            $path = trailingslashit($backup_path) . $entry['path'];
            if (! file_exists($path)) {
                $errors[] = sprintf(__('File missing: %s', 'dbvc'), $entry['path']);
                DBVC_Sync_Logger::log('Restore skipped missing file', ['file' => $entry['path']]);
                continue;
            }

            if (($entry['item_type'] ?? '') === 'post') {
                $proposal_processed = true;
                $tmp = self::get_temp_file();
                if (! $tmp) {
                    $errors[] = sprintf(__('Failed to create temp file for %s', 'dbvc'), $entry['path']);
                    continue;
                }

                if (! copy($path, $tmp)) {
                    @unlink($tmp);
                    $errors[] = sprintf(__('Failed to stage %s', 'dbvc'), $entry['path']);
                    continue;
                }

                $decoded = json_decode(file_get_contents($tmp), true);
                if (! is_array($decoded)) {
                    @unlink($tmp);
                    $errors[] = sprintf(__('Invalid JSON: %s', 'dbvc'), $entry['path']);
                    continue;
                }

                $vf_object_uid = isset($entry['vf_object_uid'])
                    ? (string) $entry['vf_object_uid']
                    : (string) ($entry['post_id'] ?? '');
                $entity_refs = self::extract_entity_references($entry, $decoded);
            $entity_decisions = null;
            if ($vf_object_uid !== '' && isset($proposal_decisions[$vf_object_uid]) && is_array($proposal_decisions[$vf_object_uid])) {
                $entity_decisions = $proposal_decisions[$vf_object_uid];
            }

            $new_entity_decision = '';
            if (
                $vf_object_uid !== ''
                && isset($entity_decisions[DBVC_NEW_ENTITY_DECISION_KEY])
                && is_string($entity_decisions[DBVC_NEW_ENTITY_DECISION_KEY])
            ) {
                $new_entity_decision = $entity_decisions[DBVC_NEW_ENTITY_DECISION_KEY];
            }
            $has_field_decisions = self::entity_has_field_decisions($entity_decisions);
            $identity = self::identify_local_entity([
                'vf_object_uid' => $vf_object_uid,
                'post_id'       => $entry['post_id'] ?? 0,
                'post_type'     => $entry['post_type'] ?? '',
                'post_name'     => $entry['post_name'] ?? '',
                'entity_refs'   => $entity_refs,
            ]);

            $is_new_entity = empty($identity['post_id']);
            $should_force_new_accept = ($new_entity_decision === 'accept_new') && ($is_new_entity || $force_reapply_new_posts);
            if ($is_new_entity && $new_entity_decision !== 'accept_new') {
                $skipped++;
                if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
                    DBVC_Sync_Logger::log_import('Post import skipped – new entity not approved', [
                        'file'     => $entry['path'],
                        'post_id'  => $vf_object_uid,
                        'proposal' => $backup_name,
                    ]);
                }
                continue;
            }
            if (! $is_new_entity && ! $has_field_decisions && ! $should_force_new_accept) {
                $skipped++;
                if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
                    DBVC_Sync_Logger::log_import('Post import skipped – no reviewer selections for existing entity', [
                        'file'     => $entry['path'],
                        'post_id'  => $vf_object_uid,
                        'proposal' => $backup_name,
                    ]);
                }
                continue;
            }

            $entity_mask_directives = [
                'overrides'    => isset($proposal_mask_overrides[$vf_object_uid]) && is_array($proposal_mask_overrides[$vf_object_uid])
                    ? $proposal_mask_overrides[$vf_object_uid]
                    : [],
                'suppressions' => isset($proposal_mask_suppress[$vf_object_uid]) && is_array($proposal_mask_suppress[$vf_object_uid])
                    ? $proposal_mask_suppress[$vf_object_uid]
                    : [],
            ];

            $result = self::import_post_from_json(
                $tmp,
                $mode === 'partial',
                null,
                $decoded,
                $entity_decisions,
                $vf_object_uid,
                $should_force_new_accept,
                $entity_mask_directives
            );
            @unlink($tmp);

            if ($result === self::IMPORT_RESULT_APPLIED) {
                    $imported++;
                    $applied_entities++;
                    if ($is_new_entity && $should_force_new_accept && $backup_name !== '' && $vf_object_uid !== '') {
                        self::record_proposal_new_entity($backup_name, $vf_object_uid);
                    }
                    if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
                        $selection_keys = [];
                        if (is_array($entity_decisions)) {
                            foreach ($entity_decisions as $path => $action) {
                                if ($path === DBVC_NEW_ENTITY_DECISION_KEY) {
                                    continue;
                                }
                                if (in_array($action, ['accept', 'keep'], true)) {
                                    $selection_keys[] = $path;
                                }
                            }
                        }

                        DBVC_Sync_Logger::log_import('Entity applied', [
                            'proposal'   => $backup_name,
                            'post_uid'   => $vf_object_uid,
                            'post_id'    => $identity['post_id'] ?? null,
                            'new_entity' => $is_new_entity,
                            'selections' => $selection_keys,
                        ]);
                    }
                } elseif ($result === self::IMPORT_RESULT_SKIPPED) {
                    $skipped++;
                    if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
                        DBVC_Sync_Logger::log_import('Post import skipped by selections', [
                            'file'     => $entry['path'],
                            'post_id'  => $vf_object_uid,
                            'proposal' => $backup_name,
                        ]);
                    }
                } elseif (is_wp_error($result)) {
                    $errors[] = $result->get_error_message();
                    DBVC_Sync_Logger::log_import('Post import failed', [
                        'file'  => $entry['path'],
                        'error' => $result->get_error_message(),
                    ]);
                } else {
                    $errors[] = sprintf(__('Post import failed for %s', 'dbvc'), $entry['path']);
                    DBVC_Sync_Logger::log_import('Post import failed', ['file' => $entry['path']]);
                }
            } elseif (($entry['item_type'] ?? '') === 'term') {
                $proposal_processed = true;
                $term_payload = self::read_term_payload_from_manifest($backup_path, $entry);
                if (! is_array($term_payload)) {
                    $errors[] = sprintf(__('Invalid term JSON: %s', 'dbvc'), $entry['path']);
                    if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
                    DBVC_Sync_Logger::log_term_import('Term import skipped – invalid payload', [
                            'file'     => $entry['path'],
                            'proposal' => $backup_name,
                        ]);
                    }
                    continue;
                }

                $vf_object_uid = isset($entry['vf_object_uid']) ? (string) $entry['vf_object_uid'] : '';
                if ($vf_object_uid === '' && ! empty($term_payload['vf_object_uid'])) {
                    $vf_object_uid = (string) $term_payload['vf_object_uid'];
                }

                $entity_refs = self::extract_entity_references($entry, $term_payload);
                $entity_decisions = null;
                if ($vf_object_uid !== '' && isset($proposal_decisions[$vf_object_uid]) && is_array($proposal_decisions[$vf_object_uid])) {
                    $entity_decisions = $proposal_decisions[$vf_object_uid];
                }

                $new_entity_decision = '';
                if (
                    $vf_object_uid !== ''
                    && isset($entity_decisions[DBVC_NEW_ENTITY_DECISION_KEY])
                    && is_string($entity_decisions[DBVC_NEW_ENTITY_DECISION_KEY])
                ) {
                    $new_entity_decision = $entity_decisions[DBVC_NEW_ENTITY_DECISION_KEY];
                }

                $has_field_decisions = self::entity_has_field_decisions($entity_decisions);
                $identity = self::identify_local_term([
                    'vf_object_uid' => $vf_object_uid,
                    'term_id'       => $term_payload['term_id'] ?? 0,
                    'taxonomy'      => $term_payload['taxonomy'] ?? '',
                    'slug'          => $term_payload['slug'] ?? '',
                    'entity_refs'   => $entity_refs,
                ]);
                $existing_term_id = isset($identity['term_id']) ? (int) $identity['term_id'] : 0;
                $is_new_term = ! $existing_term_id;
                $should_force_new_accept = ($new_entity_decision === 'accept_new') && ($is_new_term || $force_reapply_new_posts);

                if ($is_new_term && $new_entity_decision !== 'accept_new') {
                    $skipped++;
                    if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
                    DBVC_Sync_Logger::log_term_import('Term import skipped – new entity not approved', [
                            'file'     => $entry['path'],
                            'term_uid' => $vf_object_uid,
                            'proposal' => $backup_name,
                        ]);
                    }
                    continue;
                }

                if (! $is_new_term && ! $has_field_decisions && ! $should_force_new_accept) {
                    $skipped++;
                    if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
                    DBVC_Sync_Logger::log_term_import('Term import skipped – no reviewer selections for existing entity', [
                            'file'     => $entry['path'],
                            'term_uid' => $vf_object_uid,
                            'proposal' => $backup_name,
                        ]);
                    }
                    continue;
                }

                $normalized_decisions = self::normalize_entity_decisions($entity_decisions);
                if ($should_force_new_accept) {
                    $normalized_decisions = null;
                }

                $term_import = self::apply_term_entity(
                    $existing_term_id,
                    $term_payload,
                    $normalized_decisions,
                    $allow_term_creation,
                    $vf_object_uid,
                    $entity_refs
                );

                if (is_wp_error($term_import)) {
                    $errors[] = $term_import->get_error_message();
                    if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
                    DBVC_Sync_Logger::log_term_import('Term import failed', [
                            'file'   => $entry['path'],
                            'error'  => $term_import->get_error_message(),
                            'proposal' => $backup_name,
                        ]);
                    }
                    continue;
                }

                $imported++;
                $applied_entities++;
                if ($is_new_term && $should_force_new_accept && $backup_name !== '' && $vf_object_uid !== '') {
                    self::record_proposal_new_entity($backup_name, $vf_object_uid);
                }

                if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
                    $selection_keys = [];
                    if (is_array($entity_decisions)) {
                        foreach ($entity_decisions as $path => $action) {
                            if ($path === DBVC_NEW_ENTITY_DECISION_KEY) {
                                continue;
                            }
                            if (in_array($action, ['accept', 'keep'], true)) {
                                $selection_keys[] = $path;
                            }
                        }
                    }

                    DBVC_Sync_Logger::log_term_import('Term entity applied', [
                        'proposal'   => $backup_name,
                        'term_uid'   => $vf_object_uid,
                        'term_id'    => $term_import['term_id'] ?? null,
                        'taxonomy'   => $term_payload['taxonomy'] ?? '',
                        'new_entity' => $is_new_term,
                        'selections' => $selection_keys,
                    ]);
                }
            } elseif (($entry['item_type'] ?? '') === 'options') {
                $options = json_decode(file_get_contents($path), true);
                if (! empty($options) && is_array($options)) {
                    foreach ($options as $key => $value) {
                        update_option($key, maybe_unserialize($value));
                    }
                    $imported++;
                }
            } elseif (($entry['item_type'] ?? '') === 'options_group') {
                if (class_exists('DBVC_Options_Groups') && DBVC_Options_Groups::import_group_from_file($path)) {
                    $imported++;
                }
            } elseif (($entry['item_type'] ?? '') === 'menus') {
                $payload = json_decode(file_get_contents($path), true);
                if (! empty($payload) && is_array($payload)) {
                    $tmp = self::get_temp_file();
                    if ($tmp && file_put_contents($tmp, wp_json_encode($payload)) !== false) {
                        // Temporarily place file in sync dir for existing importer to reuse.
                        $sync_dir = trailingslashit(dbvc_get_sync_path());
                        wp_mkdir_p($sync_dir);
                        $sync_file = $sync_dir . 'menus.json';
                        copy($path, $sync_file);
                        self::import_menus_from_json();
                        @unlink($sync_file);
                        $imported++;
                    }
                }
            }
            }

            self::process_pending_term_parent_links();

            if (self::$needs_rewrite_flush) {
                flush_rewrite_rules(false);
                self::$needs_rewrite_flush = false;
            }
        } finally {
            self::$suppress_export_on_import = false;
        }

        if (! empty($errors)) {
            DBVC_Sync_Logger::log('Restore completed with warnings', ['errors' => $errors]);
        } elseif (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
            DBVC_Sync_Logger::log_import('Proposal import summary', [
                'proposal'      => $backup_name,
                'mode'          => $mode,
                'entities_applied' => $applied_entities,
                'entities_skipped' => $skipped,
                'media_downloaded' => $media_stats['downloaded'] ?? 0,
            ]);
        }

        if (
            $auto_clear_decisions
            && $proposal_processed
            && $has_entity_decisions
            && empty($errors)
        ) {
            $latest_store = get_option(self::PROPOSAL_DECISIONS_OPTION, []);
            if (is_array($latest_store) && isset($latest_store[$backup_name])) {
                unset($latest_store[$backup_name]);
                update_option(self::PROPOSAL_DECISIONS_OPTION, $latest_store, false);

                if (
                    class_exists('DBVC_Sync_Logger')
                    && method_exists('DBVC_Sync_Logger', 'log_import')
                ) {
                    DBVC_Sync_Logger::log_import('Cleared proposal decisions after import', [
                        'proposal'   => $backup_name,
                        'auto_clear' => true,
                    ]);
                }
            }
        }

        $media_stats      = [];
        $resolver_payload = [
            'attachments' => [],
            'id_map'      => [],
            'conflicts'   => [],
            'metrics'     => [],
        ];
        if (class_exists('DBVC_Media_Sync') && DBVC_Media_Sync::is_enabled()) {
            $media_stats = DBVC_Media_Sync::sync_manifest_media($manifest, [
                'proposal_id' => $backup_name,
                'manifest_dir'=> $backup_path,
            ]);
        }

        if (isset($media_stats['resolver']) && is_array($media_stats['resolver'])) {
            $resolver_payload = $media_stats['resolver'];
            unset($media_stats['resolver']);
        } elseif (class_exists('\Dbvc\Media\Resolver')) {
            $resolver_options = [
                'allow_remote' => class_exists('DBVC_Media_Sync') ? DBVC_Media_Sync::allow_external_sources() : false,
                'dry_run'      => true,
            ];

            try {
                $resolver_payload = \Dbvc\Media\Resolver::resolve_manifest($manifest, $resolver_options);

                if (
                    class_exists('DBVC_Sync_Logger')
                    && method_exists('DBVC_Sync_Logger', 'log_media')
                ) {
                    DBVC_Sync_Logger::log_media('Resolver dry run completed', [
                        'metrics'   => $resolver_payload['metrics'] ?? [],
                        'conflicts' => isset($resolver_payload['conflicts']) ? count($resolver_payload['conflicts']) : 0,
                    ]);
                }
            } catch (\Throwable $resolver_error) {
                if (
                    class_exists('DBVC_Sync_Logger')
                    && method_exists('DBVC_Sync_Logger', 'log_media')
                ) {
                    DBVC_Sync_Logger::log_media('Resolver dry run failed', [
                        'error' => $resolver_error->getMessage(),
                    ]);
                }
            }
        }

        return [
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'mode'     => $mode,
            'media'    => $media_stats,
            'media_resolver' => $resolver_payload,
            'media_reconcile' => $media_reconcile,
        ];
    }

    /**
     * Return total count of exportable posts across supported types.
     *
     * @return int
     */
    public static function get_total_export_post_count()
    {
        return self::wp_count_posts_by_type(self::get_supported_post_types());
    }

    /**
     * Create a temporary file path that works even when wp_tempnam is unavailable.
     *
     * @return string|false
     */
    private static function get_temp_file()
    {
        if (function_exists('wp_tempnam')) {
            return wp_tempnam();
        }

        $dir = sys_get_temp_dir();
        if (! is_dir($dir) || ! is_writable($dir)) {
            return false;
        }

        return tempnam($dir, 'dbvc_');
    }

    /* Import taxonomy inputs based on post meta
    * Added: 08-14-2025
    */
    public static function import_tax_input_for_post(int $post_id, string $post_type, array $tax_input, bool $create_terms): void
    {
        foreach ($tax_input as $taxonomy => $items) {
            if (! taxonomy_exists($taxonomy)) {
                DBVC_Sync_Logger::log("[DBVC] Skipping taxonomy '{$taxonomy}' – not registered.");
                continue;
            }

            if (! is_object_in_taxonomy($post_type, $taxonomy)) {
                DBVC_Sync_Logger::log("[DBVC] Taxonomy '{$taxonomy}' is not attached to post type '{$post_type}' – skipping.");
                continue;
            }

            $term_ids = [];

            foreach ($items as $term) {
                if (is_array($term)) {
                    $slug        = sanitize_title($term['slug'] ?? '');
                    $name        = sanitize_text_field($term['name'] ?? $slug);
                    $parent_slug = sanitize_title($term['parent'] ?? '');
                } elseif (is_string($term)) {
                    $slug        = sanitize_title($term);
                    $name        = $slug;
                    $parent_slug = '';
                } else {
                    continue;
                }

                if (! $slug) {
                    continue;
                }

                $term_obj = get_term_by('slug', $slug, $taxonomy);

                if (! $term_obj && $create_terms) {
                    $args = [];

                    // Handle parent term
                    if ($parent_slug) {
                        $parent_obj = get_term_by('slug', $parent_slug, $taxonomy);
                        if (! $parent_obj && $create_terms) {
                            $parent_created = wp_insert_term($parent_slug, $taxonomy, ['slug' => $parent_slug]);
                            if (! is_wp_error($parent_created)) {
                                $parent_obj = get_term($parent_created['term_id']);
                            }
                        }

                        if ($parent_obj && ! is_wp_error($parent_obj)) {
                            $args['parent'] = $parent_obj->term_id;
                        }
                    }

                    $term_created = wp_insert_term($name, $taxonomy, array_merge(['slug' => $slug], $args));
                    if (! is_wp_error($term_created)) {
                        $term_obj = get_term($term_created['term_id']);
                    }
                }

                if ($term_obj && ! is_wp_error($term_obj)) {
                    $term_ids[] = $term_obj->term_id;
                }
            }

            if (! empty($term_ids)) {
                wp_set_object_terms($post_id, $term_ids, $taxonomy, false);
            }
        }
    }


    /**
     * Replace old domain references in postmeta values with the current site domain.
     * Useful for ACF link fields or serialized arrays with URL values.
     *
     * @param int $post_id
     * @param string $old_domain (e.g., 'https://stagingsite.local')
     */
    public static function remap_post_meta_domains($post_id, $old_domain)
    {
        $current_domain = home_url(); // e.g., https://productionsite.com
        $meta = get_post_meta($post_id);

        foreach ($meta as $key => $values) {
            foreach ($values as $index => $original_value) {
                $maybe_updated_value = maybe_unserialize($original_value);

                if (is_array($maybe_updated_value)) {
                    // Recursively replace domains in nested array
                    $new_value = self::replace_domain_recursive($maybe_updated_value, $old_domain, $current_domain);
                } elseif (is_string($maybe_updated_value) && str_contains($maybe_updated_value, $old_domain)) {
                    $new_value = str_replace($old_domain, $current_domain, $maybe_updated_value);
                } else {
                    continue;
                }

                // Only update if it changed
                if ($new_value !== $maybe_updated_value) {
                    update_post_meta($post_id, $key, $new_value);
                    error_log("[DBVC] Updated domain in postmeta key '{$key}' for post ID {$post_id}");
                }
            }
        }
    }

    /**
     * Recursively replace domain in arrays.
     */
    private static function replace_domain_recursive($data, $old_domain, $new_domain)
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::replace_domain_recursive($value, $old_domain, $new_domain);
            } elseif (is_string($value) && str_contains($value, $old_domain)) {
                $data[$key] = str_replace($old_domain, $new_domain, $value);
            }
        }
        return $data;
    }

    /**
     * Normalize a raw decision map to a sanitized array.
     *
     * @param mixed $decisions
     * @return array|null
     */
    private static function normalize_entity_decisions($decisions): ?array
    {
        if (! is_array($decisions)) {
            return null;
        }

        $normalized = [];

        foreach ($decisions as $path => $action) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            if (defined('DBVC_NEW_ENTITY_DECISION_KEY') && $path === DBVC_NEW_ENTITY_DECISION_KEY) {
                continue;
            }

            $normalized[$path] = ($action === 'accept') ? 'accept' : 'keep';
        }

        return $normalized;
    }

    /**
     * Determine if at least one decision is marked as accepted.
     *
     * @param array|null $decisions
     * @return bool
     */
    private static function decisions_have_accept(?array $decisions): bool
    {
        if ($decisions === null) {
            return true;
        }

        foreach ($decisions as $action) {
            if ($action === 'accept') {
                return true;
            }
        }

        return false;
    }

    /**
     * Evaluate whether the provided decision map allows applying the value at a given path.
     *
     * @param array|null $decisions
     * @param string     $path
     * @return bool
     */
    private static function decision_allows_path(?array $decisions, string $path): bool
    {
        if ($decisions === null) {
            return true;
        }

        $path = trim($path);
        if ($path === '') {
            return false;
        }

        $candidates = [$path];
        if (strpos($path, 'meta.') !== 0 && strpos($path, 'post.') !== 0 && strpos($path, '.') === false) {
            $candidates[] = 'post.' . $path;
        }

        foreach ($candidates as $candidate) {
            $action = self::resolve_decision_action($decisions, $candidate);
            if ($action !== null) {
                return $action === 'accept';
            }
        }

        return false;
    }

    private static function resolve_decision_action(array $decisions, string $path): ?string
    {
        $best_action = null;
        $best_length = -1;

        foreach ($decisions as $decision_path => $action) {
            if (! is_string($decision_path) || $decision_path === '') {
                continue;
            }

            if ($decision_path === $path) {
                $length = strlen($decision_path);
            } elseif (strpos($decision_path, $path . '.') === 0) {
                $length = strlen($decision_path);
            } elseif (strpos($path, $decision_path . '.') === 0) {
                $length = strlen($decision_path);
            } else {
                continue;
            }

            if ($length > $best_length) {
                $best_length = $length;
                $best_action = $action;
            }
        }

        return $best_action;
    }

    /**
     * Check if any Accept/Keep selections exist for an entity.
     *
     * @param array|null $decisions
     * @return bool
     */
    private static function entity_has_field_decisions($decisions): bool
    {
        if (! is_array($decisions)) {
            return false;
        }

        foreach ($decisions as $path => $action) {
            if ($path === DBVC_NEW_ENTITY_DECISION_KEY) {
                continue;
            }
            if (in_array($action, ['accept', 'keep'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read a term payload from the backup manifest entry.
     *
     * @param string $backup_path
     * @param array  $entry
     * @return array|null
     */
    private static function read_term_payload_from_manifest(string $backup_path, array $entry): ?array
    {
        $relative = isset($entry['path']) ? (string) $entry['path'] : '';
        if ($relative === '') {
            return null;
        }

        $absolute = trailingslashit($backup_path) . ltrim($relative, '/\\');
        if (! file_exists($absolute) || ! is_readable($absolute)) {
            return null;
        }

        $contents = file_get_contents($absolute);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function extract_entity_references(array $entry, ?array $payload = null): array
    {
        $refs = [];
        $sources = [$entry];
        if (is_array($payload)) {
            $sources[] = $payload;
        }

        foreach ($sources as $source) {
            if (! is_array($source) || empty($source['entity_refs']) || ! is_array($source['entity_refs'])) {
                continue;
            }
            foreach ($source['entity_refs'] as $reference) {
                if (! is_array($reference)) {
                    continue;
                }
                $type  = isset($reference['type']) ? sanitize_key($reference['type']) : '';
                $value = isset($reference['value']) ? (string) $reference['value'] : '';
                $path  = isset($reference['path']) ? (string) $reference['path'] : '';
                if ($type === '' || $value === '' || $path === '') {
                    continue;
                }
                $refs[$path] = [
                    'type'  => $type,
                    'value' => $value,
                    'path'  => $path,
                ];
            }
        }

        return array_values($refs);
    }

    private static function parse_entity_reference_value(string $value): array
    {
        $value = (string) $value;
        if ($value === '') {
            return ['', ''];
        }
        $parts = explode('/', $value, 2);
        if (count($parts) === 2) {
            return [trim($parts[0]), trim($parts[1])];
        }
        return ['', trim($parts[0])];
    }

    /**
     * Attempt to locate a local term for an imported entity.
     *
     * @param array $context
     * @return array{term_id:?int,match_source:string}
     */
    private static function identify_local_term(array $context): array
    {
        $vf_object_uid = isset($context['vf_object_uid']) ? trim((string) $context['vf_object_uid']) : '';
        $taxonomy      = isset($context['taxonomy']) ? sanitize_key($context['taxonomy']) : '';
        $term_id       = isset($context['term_id']) ? (int) $context['term_id'] : 0;
        $slug          = isset($context['slug']) ? sanitize_title($context['slug']) : '';

        $references   = isset($context['entity_refs']) && is_array($context['entity_refs'])
            ? array_values($context['entity_refs'])
            : [];

        $match_source = 'none';
        $local_id     = null;

        if ($vf_object_uid !== '' && class_exists('DBVC_Database')) {
            $record = DBVC_Database::get_entity_by_uid($vf_object_uid);
            if ($record && ! empty($record->object_id) && is_string($record->object_type) && strpos($record->object_type, 'term:') === 0) {
                $local_id = (int) $record->object_id;
                $match_source = 'uid';
            }
        }

        if (! $local_id && $term_id) {
            $term = get_term($term_id);
            if ($term && ! is_wp_error($term)) {
                $local_id = (int) $term->term_id;
                $match_source = 'id';
            }
        }

        if (! $local_id && $slug !== '' && $taxonomy !== '' && taxonomy_exists($taxonomy)) {
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term && ! is_wp_error($term)) {
                $local_id = (int) $term->term_id;
                $match_source = 'slug';
            }
        }

        if (! $local_id && ! empty($references)) {
            foreach ($references as $reference) {
                $type  = isset($reference['type']) ? (string) $reference['type'] : '';
                $value = isset($reference['value']) ? (string) $reference['value'] : '';
                if ($type === '' || $value === '') {
                    continue;
                }
                if ($type === 'taxonomy_slug') {
                    [$ref_taxonomy, $ref_slug] = self::parse_entity_reference_value($value);
                    $ref_taxonomy = sanitize_key($ref_taxonomy ?: $taxonomy);
                    $ref_slug     = sanitize_title($ref_slug);
                    if ($ref_taxonomy === '' || $ref_slug === '' || ! taxonomy_exists($ref_taxonomy)) {
                        continue;
                    }
                    $term = get_term_by('slug', $ref_slug, $ref_taxonomy);
                    if ($term && ! is_wp_error($term)) {
                        $local_id = (int) $term->term_id;
                        $match_source = 'slug';
                        break;
                    }
                } elseif ($type === 'taxonomy_id') {
                    [$ref_taxonomy, $ref_id_raw] = self::parse_entity_reference_value($value);
                    $ref_taxonomy = sanitize_key($ref_taxonomy ?: $taxonomy);
                    $ref_id = (int) $ref_id_raw;
                    if ($ref_taxonomy === '' || $ref_id <= 0 || ! taxonomy_exists($ref_taxonomy)) {
                        continue;
                    }
                    $term = get_term($ref_id, $ref_taxonomy);
                    if ($term && ! is_wp_error($term)) {
                        $local_id = (int) $term->term_id;
                        $match_source = 'id';
                        break;
                    }
                }
            }
        }

        return [
            'term_id'      => $local_id ?: null,
            'match_source' => $match_source,
        ];
    }

    /**
     * Create or update a term entity from payload data.
     *
     * @param int|null $existing_term_id
     * @param array    $payload
     * @param array|null $decisions
     * @param bool     $allow_create
     * @param string   $vf_object_uid
     * @return array|\WP_Error
     */
    private static function apply_term_entity(?int $existing_term_id, array $payload, ?array $decisions, bool $allow_create, string $vf_object_uid, ?array $entity_refs = null)
    {
        $taxonomy = isset($payload['taxonomy']) ? sanitize_key($payload['taxonomy']) : '';
        if ($taxonomy === '' || ! taxonomy_exists($taxonomy)) {
            return new WP_Error('dbvc_term_taxonomy_invalid', __('Term taxonomy is missing or invalid.', 'dbvc'));
        }

        $name = sanitize_text_field($payload['name'] ?? '');
        $slug = sanitize_title($payload['slug'] ?? '');
        if ($name === '' && $slug === '' && $vf_object_uid !== '') {
            $name = $vf_object_uid;
        }
        if ($slug === '' && $name !== '') {
            $slug = sanitize_title($name);
        }

        $description = isset($payload['description']) ? wp_kses_post($payload['description']) : '';

        $term_id = $existing_term_id ? (int) $existing_term_id : 0;
        $created = false;

        if (! $term_id) {
            if (! $allow_create) {
                return new WP_Error('dbvc_term_creation_disabled', __('Term creation is disabled for this import.', 'dbvc'));
            }

            $args = ['slug' => $slug !== '' ? $slug : sanitize_title($name !== '' ? $name : wp_generate_uuid4())];

            if ($decisions === null || self::decision_allows_path($decisions, 'description')) {
                $args['description'] = $description;
            }
            $insert = wp_insert_term($name !== '' ? $name : $slug, $taxonomy, $args);
            if (is_wp_error($insert)) {
                return $insert;
            }

            $term_id = (int) $insert['term_id'];
            $created = true;
        } else {
            $args = [];
            if ($decisions === null || self::decision_allows_path($decisions, 'name')) {
                $args['name'] = $name !== '' ? $name : $slug;
            }
            if ($slug !== '' && ($decisions === null || self::decision_allows_path($decisions, 'slug'))) {
                $args['slug'] = $slug;
            }
            if ($decisions === null || self::decision_allows_path($decisions, 'description')) {
                $args['description'] = $description;
            }
            if (! empty($args)) {
                $updated = wp_update_term($term_id, $taxonomy, $args);
                if (is_wp_error($updated)) {
                    return $updated;
                }
            }
        }

        $allow_parent_update = ($decisions === null || self::decision_allows_path($decisions, 'parent'));

        $effective_uid = $vf_object_uid;
        if ($effective_uid !== '') {
            self::sync_term_registry($effective_uid, $term_id, $taxonomy);
        } elseif (class_exists('DBVC_Sync_Taxonomies')) {
            $effective_uid = DBVC_Sync_Taxonomies::ensure_term_uid($term_id, $taxonomy);
        } else {
            $effective_uid = wp_generate_uuid4();
            self::sync_term_registry($effective_uid, $term_id, $taxonomy);
        }

        update_term_meta($term_id, 'dbvc_term_history', [
            'imported_from'    => 'proposal',
            'original_term_id' => isset($payload['term_id']) ? absint($payload['term_id']) : 0,
            'original_slug'    => isset($payload['slug']) ? sanitize_title($payload['slug']) : '',
            'taxonomy'         => $taxonomy,
            'imported_at'      => current_time('mysql'),
            'imported_by'      => get_current_user_id(),
            'status'           => $created ? 'imported' : 'existing',
            'vf_object_uid'    => $effective_uid,
        ]);

        self::assign_term_parent(
            $term_id,
            $taxonomy,
            $payload,
            $entity_refs,
            $allow_parent_update
        );

		if (isset($payload['meta']) && is_array($payload['meta'])) {
			$term_mask_overrides = isset($proposal_mask_overrides[$vf_object_uid]) && is_array($proposal_mask_overrides[$vf_object_uid])
				? self::flatten_mask_meta_entries($proposal_mask_overrides[$vf_object_uid])
				: ['meta' => [], 'post' => []];
			self::apply_term_meta(
				$term_id,
				$payload['meta'],
				$decisions,
				$term_mask_overrides['meta'] ?? []
			);
		}

        return [
            'term_id'  => $term_id,
            'taxonomy' => $taxonomy,
            'created'  => $created,
        ];
    }

    /**
     * Apply meta values to a term entity based on reviewer decisions.
     *
     * @param int        $term_id
     * @param array      $meta
     * @param array|null $decisions
     * @return void
     */
    private static function apply_term_meta(int $term_id, array $meta, ?array $decisions, array $mask_overrides = []): void
    {
        $mask_overrides = is_array($mask_overrides) ? $mask_overrides : [];
        foreach ($meta as $key => $values) {
            $meta_path = 'meta.' . $key;
            if ($decisions !== null && ! self::decision_allows_path($decisions, $meta_path)) {
                continue;
            }

            $meta_key = sanitize_key($key);
            delete_term_meta($term_id, $meta_key);

            $values = is_array($values) ? $values : [$values];
            if (isset($mask_overrides[$meta_key]['value'])) {
                $values = [$mask_overrides[$meta_key]['value']];
            }
            foreach ($values as $value) {
                add_term_meta($term_id, $meta_key, maybe_unserialize($value));
            }
        }
    }

    /**
     * Resolve a parent term ID from payload data.
     *
     * @param string $taxonomy
     * @param array  $payload
     * @return int
     */
    private static function resolve_term_parent_id(string $taxonomy, array $payload, ?array $entity_refs = null): int
    {
        $parent_uid = isset($payload['parent_uid']) ? trim((string) $payload['parent_uid']) : '';
        if ($parent_uid !== '' && class_exists('DBVC_Database')) {
            $record = DBVC_Database::get_entity_by_uid($parent_uid);
            if ($record && ! empty($record->object_id) && is_string($record->object_type) && strpos($record->object_type, 'term:') === 0) {
                $term = get_term((int) $record->object_id, $taxonomy);
                if ($term && ! is_wp_error($term)) {
                    return (int) $term->term_id;
                }
            }
        }

        $parent_slug = isset($payload['parent_slug']) ? sanitize_title($payload['parent_slug']) : '';
        $parent_id   = isset($payload['parent']) ? (int) $payload['parent'] : 0;

        if ($parent_slug !== '' && taxonomy_exists($taxonomy)) {
            $parent_term = get_term_by('slug', $parent_slug, $taxonomy);
            if ($parent_term && ! is_wp_error($parent_term)) {
                return (int) $parent_term->term_id;
            }
        }

        if ($parent_id) {
            $term = get_term($parent_id, $taxonomy);
            if ($term && ! is_wp_error($term)) {
                return (int) $term->term_id;
            }
        }

        return 0;
    }

    private static function assign_term_parent(int $term_id, string $taxonomy, array $payload, ?array $entity_refs, bool $allow_update): void
    {
        if (! $term_id || ! $allow_update || ! taxonomy_exists($taxonomy)) {
            return;
        }

        $parent_id = self::resolve_term_parent_id($taxonomy, $payload, $entity_refs);
        $child_slug = isset($payload['slug']) ? (string) $payload['slug'] : (isset($payload['term_slug']) ? (string) $payload['term_slug'] : '');
        $has_parent_request = false;
        if (! empty($payload['parent_uid']) || ! empty($payload['parent_slug']) || ! empty($payload['parent'])) {
            $has_parent_request = true;
        }

        if ($parent_id > 0 || ! $has_parent_request) {
            $desired_parent = $parent_id > 0 ? $parent_id : 0;
            wp_update_term($term_id, $taxonomy, ['parent' => $desired_parent]);
            if (isset(self::$pending_term_parent_links[$term_id])) {
                unset(self::$pending_term_parent_links[$term_id]);
            }
            if ($has_parent_request && class_exists('DBVC_Sync_Logger')) {
                $action = $desired_parent > 0 ? 'Term parent applied' : 'Term parent cleared';
                DBVC_Sync_Logger::log_term_import($action, [
                    'child_id'    => $term_id,
                    'child_slug'  => $child_slug,
                    'taxonomy'    => $taxonomy,
                    'parent_id'   => $desired_parent,
                    'parent_uid'  => $payload['parent_uid'] ?? '',
                    'parent_slug' => $payload['parent_slug'] ?? '',
                ]);
            }
            return;
        }

        $parent_uid  = isset($payload['parent_uid']) ? trim((string) $payload['parent_uid']) : '';
        $parent_slug = isset($payload['parent_slug']) ? sanitize_title($payload['parent_slug']) : '';
        $parent_num  = isset($payload['parent']) ? (int) $payload['parent'] : 0;

        if ($parent_uid === '' && $parent_slug === '' && $parent_num <= 0) {
            return;
        }

        self::$pending_term_parent_links[$term_id] = [
            'child_id'    => $term_id,
            'taxonomy'    => $taxonomy,
            'parent_uid'  => $parent_uid,
            'parent_slug' => $parent_slug,
            'parent'      => $parent_num,
            'entity_refs' => $entity_refs,
            'child_slug'  => $child_slug,
        ];
        if (class_exists('DBVC_Sync_Logger')) {
            DBVC_Sync_Logger::log_term_import('Term parent deferred', [
                'child_id'    => $term_id,
                'child_slug'  => $child_slug,
                'taxonomy'    => $taxonomy,
                'parent_uid'  => $parent_uid,
                'parent_slug' => $parent_slug,
                'parent_hint' => $parent_num,
            ]);
        }
    }

    private static function process_pending_term_parent_links(): void
    {
        if (empty(self::$pending_term_parent_links)) {
            return;
        }

        foreach (self::$pending_term_parent_links as $link) {
            $child_id = isset($link['child_id']) ? (int) $link['child_id'] : 0;
            $taxonomy = isset($link['taxonomy']) ? sanitize_key($link['taxonomy']) : '';
            if (! $child_id || $taxonomy === '' || ! taxonomy_exists($taxonomy)) {
                continue;
            }

            $payload = [
                'parent_uid'  => $link['parent_uid'] ?? '',
                'parent_slug' => $link['parent_slug'] ?? '',
                'parent'      => $link['parent'] ?? 0,
            ];
            $parent_id = self::resolve_term_parent_id($taxonomy, $payload, $link['entity_refs'] ?? null);
            if ($parent_id > 0) {
                wp_update_term($child_id, $taxonomy, ['parent' => $parent_id]);
                if (class_exists('DBVC_Sync_Logger')) {
                    DBVC_Sync_Logger::log_term_import('Term parent resolved after import', [
                        'child_id'    => $child_id,
                        'child_slug'  => $link['child_slug'] ?? '',
                        'taxonomy'    => $taxonomy,
                        'parent_id'   => $parent_id,
                        'parent_uid'  => $payload['parent_uid'] ?? '',
                        'parent_slug' => $payload['parent_slug'] ?? '',
                    ]);
                }
            } else {
                if (class_exists('DBVC_Sync_Logger')) {
                    DBVC_Sync_Logger::log_term_import('Term parent unresolved after import', [
                        'child_id'    => $child_id,
                        'child_slug'  => $link['child_slug'] ?? '',
                        'taxonomy'    => $taxonomy,
                        'parent_uid'  => $payload['parent_uid'] ?? '',
                        'parent_slug' => $payload['parent_slug'] ?? '',
                        'parent_hint' => $payload['parent'] ?? 0,
                    ]);
                }
            }
        }

        self::$pending_term_parent_links = [];
    }

    /**
     * Persist term UID + registry mapping.
     *
     * @param string $entity_uid
     * @param int    $term_id
     * @param string $taxonomy
     * @return void
     */
    private static function sync_term_registry(string $entity_uid, int $term_id, string $taxonomy): void
    {
        $entity_uid = trim($entity_uid);
        if ($entity_uid === '' || ! class_exists('DBVC_Database')) {
            return;
        }

        update_term_meta($term_id, 'vf_object_uid', $entity_uid);
        DBVC_Database::upsert_entity([
            'entity_uid'    => $entity_uid,
            'object_type'   => 'term:' . sanitize_key($taxonomy),
            'object_id'     => $term_id,
            'object_status' => null,
        ]);
    }

    /**
     * Re-double only isolated single backslashes in strings (idempotent).
     * - Arrays/objects are handled recursively.
     * - Existing "\\" sequences are left as-is.
     *
     * @param mixed $value
     * @return mixed
     * @since 1.1.5
     */
    private static function reslash_isolated_backslashes($value)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = self::reslash_isolated_backslashes($v);
            }
            return $value;
        }
        if (is_object($value)) {
            foreach ($value as $k => $v) {
                $value->{$k} = self::reslash_isolated_backslashes($v);
            }
            return $value;
        }
        if (!is_string($value)) {
            return $value;
        }
        // Replace any single "\" not already part of "\\" with "\\"
        // (?<!\\)  = not preceded by backslash
        // (?!\\)   = not followed by backslash
        return preg_replace('/(?<!\\\\)\\\\(?!\\\\)/', '\\\\\\\\', $value);
    }

    /* Import all posts */
    public static function import_all($offset = 0, $smart_import = false, $filename_mode = null)
    {
        $path = dbvc_get_sync_path(); // Do not append 'posts' subfolder

        if (! is_dir($path)) {
            if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
                DBVC_Sync_Logger::log_import('Import aborted: sync path not found', [
                    'path' => $path,
                ]);
            }
            error_log('[DBVC] Import path not found: ' . $path);
            return [
                'processed' => 0,
                'remaining' => 0,
                'total'     => 0,
                'offset'    => $offset,
            ];
        }

        $supported_types = self::get_supported_post_types();
        $processed       = 0;
        $normalized_mode = ($filename_mode !== null) ? self::normalize_filename_mode($filename_mode) : null;

        if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
            DBVC_Sync_Logger::log_import('Import run started', [
                'smart_import' => (bool) $smart_import,
                'mode'         => $normalized_mode ?: 'all',
                'post_types'   => $supported_types,
            ]);
        }

        self::$suppress_export_on_import = true;
        try {
        foreach ($supported_types as $post_type) {
            $folder = trailingslashit($path) . sanitize_key($post_type);
            if (! is_dir($folder)) {
                continue;
            }

            $json_files = glob($folder . '/' . sanitize_key($post_type) . '-*.json');
            if (class_exists('DBVC_Sync_Logger')) {
                $scan_payload = [
                    'post_type' => $post_type,
                    'count'     => is_array($json_files) ? count($json_files) : 0,
                    'files'     => is_array($json_files) ? array_map('basename', $json_files) : [],
                ];
                if (DBVC_Sync_Logger::is_import_logging_enabled()) {
                    DBVC_Sync_Logger::log_import('Import scan found files', $scan_payload);
                } else {
                    DBVC_Sync_Logger::log_upload('Import scan found files (fallback)', $scan_payload);
                }
            }
            foreach ($json_files as $filepath) {
                $result = self::import_post_from_json($filepath, $smart_import, $normalized_mode, null, null, null, false, []);
                if ($result === self::IMPORT_RESULT_APPLIED) {
                    $processed++;
                }
                if (class_exists('DBVC_Sync_Logger')) {
                    $file_payload = [
                        'post_type' => $post_type,
                        'file'      => basename($filepath),
                        'result'    => is_string($result) ? $result : (is_wp_error($result) ? $result->get_error_message() : $result),
                    ];
                    if (DBVC_Sync_Logger::is_import_logging_enabled()) {
                        DBVC_Sync_Logger::log_import('Import file result', $file_payload);
                    } else {
                        DBVC_Sync_Logger::log_upload('Import file result (fallback)', $file_payload);
                    }
                }
            }

            if (class_exists('DBVC_Sync_Logger')) {
                $remaining = glob($folder . '/' . sanitize_key($post_type) . '-*.json');
                $remaining_payload = [
                    'post_type' => $post_type,
                    'count'     => is_array($remaining) ? count($remaining) : 0,
                    'files'     => is_array($remaining) ? array_map('basename', $remaining) : [],
                ];
                if (DBVC_Sync_Logger::is_import_logging_enabled()) {
                    DBVC_Sync_Logger::log_import('Import scan remaining files', $remaining_payload);
                } else {
                    DBVC_Sync_Logger::log_upload('Import scan remaining files (fallback)', $remaining_payload);
                }
            }
        }


        } finally {
            self::$suppress_export_on_import = false;
        }

        /*      @WIP Relationship remapping   
$acf_relationship_fields = [
            'related_services',
            'connected_team_members',
            'case_study_featured_post',
        ];

        self::remap_relationship_fields($acf_relationship_fields); */


        $result = [
            'processed' => $processed,
            'remaining' => 0,
            'total'     => $processed,
            'offset'    => $offset + $processed,
        ];

        if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
            DBVC_Sync_Logger::log_import('Import run completed', [
                'processed'     => $processed,
                'smart_import'  => (bool) $smart_import,
                'mode'          => $normalized_mode ?: 'all',
                'post_types'    => $supported_types,
            ]);
        }

        if (self::$needs_rewrite_flush) {
            flush_rewrite_rules(false);
            self::$needs_rewrite_flush = false;
        }

        return $result;
    }

    /**
     * Build import posts from Json
     * Added: 08-12-2025
     */
    public static function import_post_from_json($filepath, $smart_import = false, $filename_mode = null, ?array $decoded_json = null, ?array $field_decisions = null, ?string $entity_uid = null, bool $force_new_entity_accept = false, array $mask_directives = [])
    {
        if (! file_exists($filepath)) {
            return new WP_Error('dbvc_import_missing_file', sprintf(__('Import source missing: %s', 'dbvc'), $filepath));
        }

        $json_raw = $decoded_json ? wp_json_encode($decoded_json) : file_get_contents($filepath);
        if ($json_raw === false) {
            return new WP_Error('dbvc_import_read_failed', sprintf(__('Unable to read JSON file: %s', 'dbvc'), $filepath));
        }

        $json = $decoded_json ?? json_decode($json_raw, true);

        if (
            empty($json) ||
            ! is_array($json) ||
            ! isset($json['ID'], $json['post_type'], $json['post_title'])
        ) {
            return new WP_Error('dbvc_import_invalid_json', sprintf(__('Skipped non-post JSON file: %s', 'dbvc'), basename($filepath)));
        }

        $original_id = absint($json['ID']);
        $post_type = sanitize_text_field($json['post_type']);
        $incoming_entity_uid = $entity_uid ?? ($json['vf_object_uid'] ?? '');
        if ($incoming_entity_uid === '' && isset($json['meta']['dbvc_post_history']['vf_object_uid'])) {
            $incoming_entity_uid = $json['meta']['dbvc_post_history']['vf_object_uid'];
        }
        $incoming_entity_uid = is_string($incoming_entity_uid) ? trim($incoming_entity_uid) : '';
        $normalized_mode = ($filename_mode !== null) ? self::normalize_filename_mode($filename_mode) : null;

        if (! self::import_filename_matches_mode($normalized_mode, $filepath, $post_type, $json)) {
            if ($normalized_mode !== null) {
                error_log("[DBVC] Skipping file due to filename filter ({$normalized_mode}): " . basename($filepath));
            }
            return self::IMPORT_RESULT_SKIPPED;
        }

        $mirror_domain = rtrim(get_option('dbvc_mirror_domain', ''), '/');
        $current_domain = rtrim(home_url(), '/');

        // Exit early if already processed
        $existing_hash = $json['meta']['dbvc_post_history']['hash'] ?? '';
        $current_hash = md5($json_raw);
        $status = $json['meta']['dbvc_post_history']['status'] ?? '';

        if ($existing_hash === $current_hash && in_array($status, ['existing', 'imported'])) {
            error_log("[DBVC] Skipping unchanged JSON for post ID {$original_id} (status: {$status})");
            return self::IMPORT_RESULT_SKIPPED;
        }

        $resolved_post_id = null;
        if ($incoming_entity_uid !== '') {
            $resolved_post_id = self::find_post_id_by_uid($incoming_entity_uid, $post_type);
        }

        $existing = $resolved_post_id ? get_post($resolved_post_id) : get_post($original_id);
        if ($resolved_post_id && $existing) {
            self::$imported_post_id_map[$original_id] = (int) $existing->ID;
        }
        if (! $existing && $original_id && $original_id !== $resolved_post_id) {
            $existing = get_post($original_id);
        }
        if ($existing && $incoming_entity_uid !== '') {
            update_post_meta($existing->ID, 'vf_object_uid', $incoming_entity_uid);
            self::sync_entity_registry($incoming_entity_uid, $existing->ID, $existing->post_type);
        }
        $post_id = null;
        $normalized_decisions = self::normalize_entity_decisions($field_decisions);
        if ($force_new_entity_accept) {
            $normalized_decisions = null;
        }
        $mask_overrides = isset($mask_directives['overrides']) && is_array($mask_directives['overrides'])
            ? $mask_directives['overrides']
            : [];
        $mask_overrides = self::flatten_mask_meta_entries($mask_overrides);
        $mask_suppressions_raw = isset($mask_directives['suppressions']) && is_array($mask_directives['suppressions'])
            ? $mask_directives['suppressions']
            : [];
        $mask_suppressions = self::flatten_mask_meta_entries($mask_suppressions_raw);

        $meta_overrides = $mask_overrides['meta'] ?? [];
        $post_field_overrides = $mask_overrides['post'] ?? [];
        $meta_suppressions = $mask_suppressions['meta'] ?? [];
        $post_field_suppressions = $mask_suppressions['post'] ?? [];

        // Smart import hash check
        if ($smart_import && $existing) {
            $meta = $json['meta'] ?? [];
            unset($meta['_dbvc_import_hash']);

            $new_hash = md5(serialize([$json['post_content'], $meta]));
            $existing_hash = get_post_meta($existing->ID, '_dbvc_import_hash', true);

            if ($new_hash === $existing_hash) {
                if (!empty($mirror_domain) && $mirror_domain !== $current_domain) {
                    self::remap_post_meta_domains($existing->ID, $mirror_domain);
                    error_log("[DBVC] Remapped post meta for unchanged post ID {$original_id} due to mirror domain.");
                }

                error_log("[DBVC] Skipping unchanged post ID {$original_id}");
                return self::IMPORT_RESULT_SKIPPED;
            }
        }

        if ($normalized_decisions !== null && ! self::decisions_have_accept($normalized_decisions)) {
            error_log("[DBVC] Skipped post ID {$original_id} — no accepted fields in reviewer selections.");
            return self::IMPORT_RESULT_SKIPPED;
        }

        $allow_create = get_option('dbvc_allow_new_posts') === '1';
        $target_status = get_option('dbvc_new_post_status', 'draft');
        $whitelist = (array) get_option('dbvc_new_post_types_whitelist', []);
        $limit_to_types = ! empty($whitelist);
        $did_apply_post_fields = false;
        $did_apply_meta = false;
        $did_apply_tax = false;

        // Update existing
        if ($existing && $existing->post_type === $post_type) {
            $post_id = $existing->ID;
            $post_array = [
                'ID'        => $post_id,
                'post_type' => $post_type,
            ];

            $field_map = [
                'post_title'   => static function () use ($json) { return sanitize_text_field($json['post_title']); },
                'post_content' => static function () use ($json) { return wp_kses_post($json['post_content'] ?? ''); },
                'post_excerpt' => static function () use ($json) { return sanitize_textarea_field($json['post_excerpt'] ?? ''); },
                'post_status'  => static function () use ($json) { return sanitize_text_field($json['post_status'] ?? 'draft'); },
                'post_name'    => static function () use ($json) { return sanitize_text_field($json['post_name'] ?? ''); },
            ];

            if (! empty($json['post_date'])) {
                $field_map['post_date'] = static function () use ($json) {
                    return sanitize_text_field($json['post_date']);
                };
            }

            if (! empty($json['post_modified'])) {
                $field_map['post_modified'] = static function () use ($json) {
                    return sanitize_text_field($json['post_modified']);
                };
            }

            foreach ($field_map as $path => $callback) {
                if ($normalized_decisions !== null && ! self::decision_allows_path($normalized_decisions, $path)) {
                    continue;
                }

                $bucket_key = self::build_mask_storage_key('post', $path);
                if (! empty($post_field_suppressions[$bucket_key])) {
                    continue;
                }

                $value = $callback();
                if (isset($post_field_overrides[$bucket_key]['value'])) {
                    $value = $post_field_overrides[$bucket_key]['value'];
                }
                if ($value !== null) {
                    $post_array[$path] = $value;
                }
            }

            if (count($post_array) > 2) {
                $result = wp_insert_post($post_array);
                if (is_wp_error($result)) {
                    return $result;
                }
                $post_id = $result;
                $did_apply_post_fields = true;
                error_log("[DBVC] Updated existing post ID {$post_id}");
            } else {
                $post_id = $existing->ID;
            }
        } else {
            // Treat decisions as default accept for creations to avoid blocking required fields.
            $normalized_decisions = null;

            if (! $allow_create) {
                error_log("[DBVC] Skipped creation of missing post ID {$original_id} — creation disabled.");
                return self::IMPORT_RESULT_SKIPPED;
            }

            if ($limit_to_types && ! in_array($post_type, $whitelist, true)) {
                error_log("[DBVC] Skipped creation of post type '{$post_type}' — not whitelisted.");
                return self::IMPORT_RESULT_SKIPPED;
            }

            $content = wp_kses_post($json['post_content'] ?? '');
            if (!empty($mirror_domain) && $mirror_domain !== $current_domain) {
                $content = str_replace($mirror_domain, $current_domain, $content);
            }

            $post_array = [
                'post_title'   => sanitize_text_field($json['post_title']),
                'post_content' => $content,
                'post_excerpt' => sanitize_textarea_field($json['post_excerpt'] ?? ''),
                'post_type'    => $post_type,
                'post_status'  => $target_status,
            ];

            if (! empty($json['post_date'])) {
                $post_array['post_date'] = sanitize_text_field($json['post_date']);
            }
            if (! empty($json['post_modified'])) {
                $post_array['post_modified'] = sanitize_text_field($json['post_modified']);
            }

            $post_id = wp_insert_post($post_array);

            if (is_wp_error($post_id)) {
                error_log("[DBVC] Failed to create post from {$filepath}: " . $post_id->get_error_message());
                return $post_id;
            }

            self::$needs_rewrite_flush = true;
            self::$imported_post_id_map[$original_id] = $post_id;
            error_log("[DBVC] Created new post ID {$post_id} (from original ID {$original_id})");

            // Overwrite JSON ID with actual new ID
            $json['ID'] = $post_id;
        }

        // Import meta
        if (! is_wp_error($post_id) && isset($json['meta']) && is_array($json['meta'])) {

            // Bricks meta keys to protect
            $bricks_keys = apply_filters('dbvc_bricks_meta_keys', [
                '_bricks_page_content_2',
                '_bricks_page_header_2',
                '_bricks_page_footer_2',
                '_bricks_page_css',
                '_bricks_page_custom_code',
            ]);

            // Temporarily disable sanitize callbacks for these keys (defensive)
            $null_cb = static function ($v) {
                return $v;
            };
            $hooked  = [];
            foreach ($bricks_keys as $bk) {
                $tag = 'sanitize_post_meta_' . $bk;
                add_filter($tag, $null_cb, 10, 1);
                $hooked[] = $tag;
            }

            foreach ($json['meta'] as $key => $values) {
                $meta_path = 'meta.' . $key;
                if ($normalized_decisions !== null && ! self::decision_allows_path($normalized_decisions, $meta_path)) {
                    continue;
                }

                // Use sanitize_key for the meta key (preserves underscores)
                $meta_key = sanitize_key($key);
                $bucket_key = self::build_mask_storage_key('meta', $meta_key);

                if (! empty($meta_suppressions[$bucket_key])) {
                    continue;
                }

                if (! is_array($values)) {
                    $values = [$values];
                }

                if (isset($meta_overrides[$bucket_key]['value'])) {
                    $values = [$meta_overrides[$bucket_key]['value']];
                }

                foreach ($values as $value) {

                    // Re-double isolated singles for Bricks keys only
                    if (in_array($meta_key, $bricks_keys, true)) {
                        $value = self::reslash_isolated_backslashes($value);
                    }

                    // Do not unslash/sanitize the value here; just maybe_unserialize
                    update_post_meta($post_id, $meta_key, maybe_unserialize($value));
                }

                $did_apply_meta = true;
            }

            // Remove temporary sanitize bypass
            foreach ($hooked as $tag) {
                remove_filter($tag, $null_cb, 10);
            }

            // 🔁 Remap old domain in postmeta if mirror domain was set (unchanged)
            if (!empty($mirror_domain) && $mirror_domain !== $current_domain) {
                self::remap_post_meta_domains($post_id, $mirror_domain);
            }
        }

        $raw = get_post_meta($post_id, '_bricks_page_footer_2', true);
        if (is_array($raw)) {
            $code = $raw[0][0]['settings']['code'] ?? '';
            // Expect to see \\Bricks\\
            error_log('[DBVC] AFTER IMPORT snippet=' . substr($code, max(0, strpos($code, '\\Bricks\\')), 14));
        }

        // Import taxonomies
        if (! is_wp_error($post_id) && isset($json['tax_input']) && is_array($json['tax_input'])) {
            $create_terms = (bool) get_option('dbvc_auto_create_terms', true);
            $tax_subset = [];

            foreach ($json['tax_input'] as $taxonomy => $items) {
                $tax_path = 'tax_input.' . $taxonomy;
                if ($normalized_decisions !== null && ! self::decision_allows_path($normalized_decisions, $tax_path)) {
                    continue;
                }
                $tax_subset[$taxonomy] = $items;
            }

            if (! empty($tax_subset)) {
                self::import_tax_input_for_post($post_id, $post_type, $tax_subset, $create_terms);
                $did_apply_tax = true;
            }
        }

        $stored_entity_uid = $incoming_entity_uid;
        if ($post_id) {
            if ($stored_entity_uid) {
                update_post_meta($post_id, 'vf_object_uid', $stored_entity_uid);
                self::sync_entity_registry($stored_entity_uid, $post_id, $post_type);
            } else {
                $stored_entity_uid = self::ensure_post_uid($post_id);
            }
            $json['vf_object_uid'] = $stored_entity_uid;
        }

        $did_apply = $did_apply_post_fields || $did_apply_meta || $did_apply_tax;

        if (! $did_apply) {
            error_log("[DBVC] Skipped applying changes for post ID {$original_id} — no accepted fields.");
            return self::IMPORT_RESULT_SKIPPED;
        }

        // Save import hash
        if ($post_id) {
            $meta = $json['meta'] ?? [];
            unset($meta['_dbvc_import_hash']);
            $hash = md5(serialize([$json['post_content'], $meta]));
            update_post_meta($post_id, '_dbvc_import_hash', $hash);
        }

        // Save post history meta
        $post_history = [
            'imported_from'    => 'local-json',
            'original_post_id' => $original_id,
            'original_slug'    => $json['post_name'] ?? '',
            'imported_at'      => current_time('mysql'),
            'imported_by'      => get_current_user_id(),
            'mirror_domain'    => $mirror_domain,
            'hash'             => $current_hash,
            'json_filename'    => basename($filepath),
            'status'           => ($post_id === $original_id) ? 'existing' : 'imported',
            'vf_object_uid'    => $stored_entity_uid,
        ];
        update_post_meta($post_id, 'dbvc_post_history', $post_history);
        $json['meta']['dbvc_post_history'] = $post_history;

        // Rewrite updated JSON
        $new_json = wp_json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($new_json && self::is_safe_file_path($filepath)) {
            file_put_contents($filepath, $new_json);
        }

        error_log("[DBVC] Imported post ID {$post_id}");
        return self::IMPORT_RESULT_APPLIED;
    }

    /**
     * Recursively strip the site domain from any string values in the given data array.
     *
     * @param array  $meta            The post meta array to process.
     * @param string $domain_to_strip The domain to remove (e.g., https://example.com).
     * @return array Sanitized array with domain stripped.
     */
    private static function strip_domain_from_meta_urls($data, $domain)
    {
        if (empty($domain)) {
            return $data;
        }
        $needles = [untrailingslashit($domain), untrailingslashit($domain) . '/'];

        $recur = static function ($val) use (&$recur, $needles) {
            if (is_array($val)) {
                foreach ($val as $k => $v) {
                    $val[$k] = $recur($v);
                }
                return $val;
            }
            if (is_object($val)) {
                foreach ($val as $k => $v) {
                    $val->{$k} = $recur($v);
                }
                return $val;
            }
            if (is_string($val)) {
                // Plain replace; DO NOT unslash.
                return str_replace($needles, '', $val);
            }
            return $val;
        };

        return $recur($data);
    }
    /* ORIGINAL VERSION 
    public static function strip_domain_from_meta_urls($meta, $domain_to_strip)
    {
        if (empty($domain_to_strip) || empty($meta)) {
            return $meta;
        }

        array_walk_recursive($meta, function (&$value) use ($domain_to_strip) {
            if (is_string($value) && strpos($value, $domain_to_strip) !== false) {
                $value = str_replace($domain_to_strip, '', $value);
            }
        });

        return $meta;
    } */

    /**
     * Export a single post to JSON file.
     *
     * @param int    $post_id Post ID.
     * @param object $post    WP_Post object.
     * @param mixed  $filename_mode Preferred filename format (id|slug|slug_id|legacy bool).
     *
     * @since  1.0.0
     * @return void
     */
    public static function export_post_to_json($post_id, $post, $filename_mode = null)
    {
        if (self::$suppress_export_on_import || self::is_post_deleting($post_id)) {
            return;
        }
        $prepared = self::prepare_post_export($post_id, $post, $filename_mode);
        if (is_wp_error($prepared) || empty($prepared)) {
            return;
        }

        if (self::$allow_clean_export_directory) {
            self::ensure_clean_export_directory($post->post_type);
        }
        self::write_export_payload($prepared, $post_id, $post);
    }

    /**
     * Prepare export payload without writing to disk.
     *
     * @param int    $post_id
     * @param object $post
     * @param mixed  $filename_mode
     * @return array|\WP_Error
     */

    public static function begin_full_export(): void
    {
        self::$allow_clean_export_directory = true;
    }

    public static function end_full_export(): void
    {
        self::$allow_clean_export_directory = false;
    }
    private static function prepare_post_export($post_id, $post, $filename_mode = null)
    {
        // Validate inputs.
        if (! is_numeric($post_id) || $post_id <= 0) {
            return new WP_Error('dbvc_invalid_post', __('Invalid post ID supplied for export.', 'dbvc'));
        }
        if (! is_object($post) || ! isset($post->post_type)) {
            return new WP_Error('dbvc_invalid_post', __('Invalid post object supplied for export.', 'dbvc'));
        }
        if (wp_is_post_revision($post_id)) {
            return new WP_Error('dbvc_skipped_revision', __('Skipping revision export.', 'dbvc'));
        }

        // Allowed statuses (FSE types may include draft/auto-draft) + filter.
        $allowed_statuses = ['publish'];
        if (in_array($post->post_type, ['wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation'], true)) {
            $allowed_statuses[] = 'draft';
            $allowed_statuses[] = 'auto-draft';
        }
        $allowed_statuses = apply_filters('dbvc_allowed_statuses_for_export', $allowed_statuses, $post);
        if (! in_array($post->post_status, $allowed_statuses, true)) {
            return new WP_Error('dbvc_skipped_status', __('Post status not allowed for export.', 'dbvc'));
        }

        // Only export supported types.
        $supported_types = self::get_supported_post_types();
        if (! in_array($post->post_type, $supported_types, true)) {
            return new WP_Error('dbvc_skipped_type', __('Post type not supported for export.', 'dbvc'));
        }

        // Capability check (skip if WP-CLI or filter instructs to skip).
        $skip_caps = apply_filters('dbvc_skip_read_cap_check', false, $post->ID, $post);
        if ((! defined('WP_CLI') || ! WP_CLI) && ! $skip_caps) {
            $pto = get_post_type_object($post->post_type);
            if (! $pto || ! current_user_can($pto->cap->read_post, $post_id)) {
                return new WP_Error('dbvc_skipped_cap', __('Insufficient capability to export post.', 'dbvc'));
            }
        }

        // Ensure entity UID is available for downstream matching.
        $entity_uid = self::ensure_post_uid($post_id, $post);

        // Domain strip option (current site).
        $domain_to_strip = get_option('dbvc_strip_domain_urls') === '1' ? untrailingslashit(home_url()) : '';

        // Fetch raw meta and safely sanitize/normalize (preserves backslashes).
        $raw_meta       = get_post_meta($post_id);
        $sanitized_meta = function_exists('dbvc_sanitize_post_meta_safe')
            ? dbvc_sanitize_post_meta_safe($raw_meta)
            : $raw_meta;

        // Content/excerpt, optionally strip current domain.
        if ($domain_to_strip) {
            $needles      = [$domain_to_strip, $domain_to_strip . '/'];
            $post_content = is_string($post->post_content) ? str_replace($needles, '', $post->post_content) : '';
            $post_excerpt = is_string($post->post_excerpt) ? str_replace($needles, '', $post->post_excerpt) : '';
            $sanitized_meta = self::strip_domain_from_meta_urls($sanitized_meta, $domain_to_strip);
        } else {
            $post_content = $post->post_content;
            $post_excerpt = $post->post_excerpt;
        }

        // Taxonomies → portable payload (slug/name/parent).
        $tax_input = self::export_tax_input_portable($post_id, $post->post_type);

        // Assemble base payload (title/status/name lightly sanitized; meta untouched).
        $data = [
            'ID'           => absint($post_id),
            'vf_object_uid'=> $entity_uid,
            'post_title'   => sanitize_text_field($post->post_title),
            'post_content' => wp_kses_post($post_content),
            'post_excerpt' => sanitize_textarea_field($post_excerpt),
            'post_type'    => sanitize_text_field($post->post_type),
            'post_status'  => sanitize_text_field($post->post_status),
            'post_name'    => sanitize_text_field($post->post_name),
            'post_date'    => isset($post->post_date) ? sanitize_text_field($post->post_date) : '',
            'post_modified'=> isset($post->post_modified) ? sanitize_text_field($post->post_modified) : '',
            'meta'         => $sanitized_meta,
            'tax_input'    => $tax_input,
        ];

        // FSE extras.
        if (in_array($post->post_type, ['wp_template', 'wp_template_part'], true)) {
            $data['theme']  = get_stylesheet();
            $data['slug']   = $post->post_name;
            $data['source'] = get_post_meta($post_id, 'origin', true) ?: 'custom';
        }

        // Let mirror/masking etc. adjust the payload.
        $data = apply_filters('dbvc_export_post_data', $data, $post_id, $post);

        if (get_option('dbvc_export_sort_meta', '0') === '1' && isset($data['meta']) && is_array($data['meta']) && function_exists('dbvc_sort_array_recursive')) {
            $data['meta'] = dbvc_sort_array_recursive($data['meta']);
        }

        // FINAL: Lossless normalize ONLY (no unslash).
        $data = dbvc_normalize_for_json($data);

        // Resolve path and ensure directory exists.
        $path = trailingslashit(dbvc_get_sync_path($post->post_type));
        if (! is_dir($path)) {
            if (! wp_mkdir_p($path)) {
                error_log('DBVC: Failed to create directory: ' . $path);
                return new WP_Error('dbvc_directory_failed', __('Unable to create export directory.', 'dbvc'));
            }
        }
        self::ensure_directory_security($path);

        // Filename selection (ID, slug, or slug+ID).
        $filename_components = self::resolve_filename_components($post_id, $post, $filename_mode);
        $file_path           = $path . $filename_components['filename'];

        // Allow path filter + validate.
        $file_path = apply_filters('dbvc_export_post_file_path', $file_path, $post->ID, $post);
        if (! dbvc_is_safe_file_path($file_path)) {
            error_log('DBVC: Unsafe file path detected: ' . $file_path);
            return new WP_Error('dbvc_unsafe_path', __('Unsafe file path detected.', 'dbvc'));
        }

        // Pick the exact path you care about:
        $code = $data['meta']['_bricks_page_footer_2'][0][0]['settings']['code'] ?? null;
        if (is_string($code)) {
            error_log('[DBVC] PRE backslash count=' . substr_count($code, '\\'));
            $pos = strpos($code, '\\Bricks\\');
            if ($pos !== false) {
                $snip = substr($code, $pos, 14);
                error_log('[DBVC] PRE snip="' . $snip . '" hex=' . bin2hex($snip));
            }
        }

        // Encode and write (slashes in BACKSLASH are preserved; forward slashes are unescaped).
        $json_content = wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $json_content) {
            error_log('DBVC: Failed to encode JSON for post ' . $post_id);
            return new WP_Error('dbvc_json_failure', __('Failed to encode post JSON.', 'dbvc'));
        }

        $hash = hash('sha256', $json_content);

        return [
            'post_id'      => $post_id,
            'post'         => $post,
            'file_path'    => $file_path,
            'json_content' => $json_content,
            'data'         => $data,
            'hash'         => $hash,
        ];
    }

    /**
     * Persist prepared export payload to disk.
     *
     * @param array  $prepared
     * @param int    $post_id
     * @param object $post
     * @return bool
     */
    private static function write_export_payload(array $prepared, $post_id, $post)
    {
        if (false === file_put_contents($prepared['file_path'], $prepared['json_content'])) {
            error_log('DBVC: Failed to write file: ' . $prepared['file_path']);
            return false;
        }

        do_action('dbvc_after_export_post', $post_id, $post, $prepared['file_path']);
        return true;
    }

    /**
     * Import all JSON files for supported post types.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function import_all_json_files($filename_mode = null)
    {
        if (class_exists('DBVC_Sync_Taxonomies')) {
            DBVC_Sync_Taxonomies::import_taxonomies();
        }

        return self::import_all(0, false, $filename_mode);
    }

    /**
     * Export only posts changed since a baseline snapshot.
     *
     * @param int|null $baseline_snapshot_id
     * @param mixed    $filename_mode
     * @return array|\WP_Error
     */
    public static function export_posts_diff($baseline_snapshot_id = null, $filename_mode = null)
    {
        if (! class_exists('DBVC_Database')) {
            return new WP_Error('dbvc_missing_database', __('Snapshot database layer unavailable.', 'dbvc'));
        }

        if (! $baseline_snapshot_id) {
            $latest = DBVC_Database::get_latest_snapshot('full_export');
            if ($latest) {
                $baseline_snapshot_id = (int) $latest->id;
            }
        }

        $baseline_hashes = $baseline_snapshot_id ? DBVC_Database::get_snapshot_item_hashes($baseline_snapshot_id, 'post') : [];

        $selected = (array) get_option('dbvc_post_types', []);
        if (empty($selected)) {
            $selected = method_exists(__CLASS__, 'get_supported_post_types')
                ? self::get_supported_post_types()
                : array_keys(dbvc_get_available_post_types());
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

        $snapshot_items = [];
        $export_time    = current_time('mysql', true);
        $sync_root      = trailingslashit(dbvc_get_sync_path());
        $counts         = [
            'created'   => 0,
            'updated'   => 0,
            'unchanged' => 0,
        ];

        foreach ($selected as $pt) {
            $paged = 1;
            do {
                $query = new WP_Query([
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

                if (! empty($query->posts)) {
                    foreach ($query->posts as $post_id) {
                        $post = get_post($post_id);
                        if (! $post) {
                            continue;
                        }

                        $prepared = self::prepare_post_export($post_id, $post, $filename_mode);
                        if (is_wp_error($prepared)) {
                            continue;
                        }

                        $hash          = $prepared['hash'];
                        $baseline_hash = isset($baseline_hashes[$post_id]) ? (string) $baseline_hashes[$post_id] : null;
                        $status        = $baseline_hash ? 'updated' : 'created';

                        if ($baseline_hash && hash_equals($baseline_hash, $hash)) {
                            $status = 'unchanged';
                        } else {
                            self::write_export_payload($prepared, $post_id, $post);
                        }

                        $counts[$status]++;

                        $relative = ltrim(str_replace($sync_root, '', $prepared['file_path']), '/');
                        $snapshot_items[] = [
                            'object_type'  => 'post',
                            'object_id'    => (int) $post_id,
                            'entity_uid'   => $prepared['data']['vf_object_uid'] ?? '',
                            'content_hash' => $hash,
                            'media_hash'   => null,
                            'status'       => $status,
                            'payload_path' => $relative,
                            'exported_at'  => $export_time,
                        ];
                    }
                }
                $paged++;
            } while (! empty($query->posts));
        }

        if (class_exists('DBVC_Backup_Manager')) {
            DBVC_Backup_Manager::generate_manifest(dbvc_get_sync_path());
        }

        $snapshot_id = DBVC_Database::insert_snapshot([
            'type'         => 'diff_export',
            'sync_path'    => dbvc_get_sync_path(),
            'notes'        => wp_json_encode([
                'baseline_snapshot_id' => $baseline_snapshot_id,
                'counts'               => $counts,
                'timestamp'            => $export_time,
            ]),
        ]);

        if ($snapshot_id && ! empty($snapshot_items)) {
            DBVC_Database::insert_snapshot_items($snapshot_id, $snapshot_items);
        }

        DBVC_Database::log_activity(
            'diff_export_completed',
            'info',
            'Diff export completed',
            [
                'snapshot_id'          => $snapshot_id,
                'baseline_snapshot_id' => $baseline_snapshot_id,
                'counts'               => $counts,
            ]
        );

        return [
            'snapshot_id'          => $snapshot_id,
            'baseline_snapshot_id' => $baseline_snapshot_id,
            'counts'               => $counts,
        ];
    }



    /**
     * Export options to JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function export_options_to_json()
    {
        // Check user capabilities for options export (skip for WP-CLI)
        if (! defined('WP_CLI') || ! WP_CLI) {
            if (! current_user_can('manage_options')) {
                return;
            }
        }

        $all_options = wp_load_alloptions();
        $excluded_keys = [
            'siteurl',
            'home',
            'blogname',
            'blogdescription',
            'admin_email',
            'users_can_register',
            'start_of_week',
            'upload_path',
            'upload_url_path',
            'cron',
            'recently_edited',
            'rewrite_rules',
            // Security-sensitive options
            'auth_key',
            'auth_salt',
            'logged_in_key',
            'logged_in_salt',
            'nonce_key',
            'nonce_salt',
            'secure_auth_key',
            'secure_auth_salt',
            'secret_key',
            'db_version',
            'initial_db_version',
        ];

        // Allow other plugins to modify excluded keys
        $excluded_keys = apply_filters('dbvc_excluded_option_keys', $excluded_keys);

        $filtered = array_diff_key($all_options, array_flip($excluded_keys));

        // Sanitize options data
        $filtered = self::sanitize_options_data($filtered);

        // Allow other plugins to modify the options data before export
        $filtered = apply_filters('dbvc_export_options_data', $filtered);

        $path = dbvc_get_sync_path();
        if (! is_dir($path)) {
            if (! wp_mkdir_p($path)) {
                error_log('DBVC: Failed to create directory: ' . $path);
                return;
            }
        }

        self::ensure_directory_security($path);

        $file_path = $path . 'options.json';

        // Allow other plugins to modify the options file path.
        $file_path = apply_filters('dbvc_export_options_file_path', $file_path);

        // Validate file path
        if (! dbvc_is_safe_file_path($file_path)) {
            error_log('DBVC: Unsafe file path detected: ' . $file_path);
            return;
        }

        $json_content = wp_json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (false === $json_content) {
            error_log('DBVC: Failed to encode options JSON');
            return;
        }

        $result = file_put_contents($file_path, $json_content);
        if (false === $result) {
            error_log('DBVC: Failed to write options file: ' . $file_path);
            return;
        }

        // Allow other plugins to perform additional actions after options export
        do_action('dbvc_after_export_options', $file_path, $filtered);
    }

    /**
     * Sanitize post meta data.
     * 
     * @param array $meta_data Raw meta data.
     * 
     * @since  1.0.0
     * @return array Sanitized meta data.
     */
    private static function sanitize_post_meta($meta_data)
    {
        $sanitized = [];

        // Define keywords that suggest the value may contain HTML
        $allow_html_if_key_contains = [
            'section',
            'description',
            'wysiwyg',
            'text',
            'textarea',
            'details',
            'content',
            'info',
            'header',
            'name'
        ];

        foreach ($meta_data as $key => $values) {
            $key = sanitize_text_field($key);
            $sanitized[$key] = [];

            foreach ($values as $value) {
                if (is_serialized($value)) {
                    $unserialized = maybe_unserialize($value);
                    $sanitized[$key][] = dbvc_sanitize_json_data($unserialized);
                } else {
                    // Check if key contains any of the wildcard keywords
                    $allow_html = false;
                    foreach ($allow_html_if_key_contains as $match) {
                        if (stripos($key, $match) !== false) {
                            $allow_html = true;
                            break;
                        }
                    }

                    if ($allow_html) {
                        $sanitized[$key][] = wp_kses_post($value);
                    } else {
                        $sanitized[$key][] = sanitize_textarea_field($value);
                    }
                }
            }
        }

        return $sanitized;
    }


    /**
     * Sanitize options data.
     * 
     * @param array $options_data Raw options data.
     * 
     * @since  1.0.0
     * @return array Sanitized options data.
     */
    private static function sanitize_options_data($options_data)
    {
        $sanitized = [];

        foreach ($options_data as $key => $value) {
            $key = sanitize_text_field($key);

            if (is_serialized($value)) {
                $unserialized = maybe_unserialize($value);
                $sanitized[$key] = dbvc_sanitize_json_data($unserialized);
            } else {
                $sanitized[$key] = dbvc_sanitize_json_data($value);
            }
        }

        return $sanitized;
    }

    /**
     * Import options from JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function import_options_from_json()
    {
        $file_path = dbvc_get_sync_path() . 'options.json';
        if (! file_exists($file_path)) {
            return;
        }

        $options = json_decode(file_get_contents($file_path), true);
        if (empty($options)) {
            return;
        }

        foreach ($options as $key => $value) {
            update_option($key, maybe_unserialize($value));
        }
    }

    /**
     * Export all menus to JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function export_menus_to_json()
    {
        $menus = wp_get_nav_menus();
        $data  = [];

        foreach ($menus as $menu) {
            $items = wp_get_nav_menu_items($menu->term_id, ['post_status' => 'any']);
            $formatted_items = [];

            foreach ($items as $item) {
                $post_array = get_post($item->ID, ARRAY_A);
                $meta       = get_post_meta($item->ID);
                $original_id = isset($meta['_dbvc_original_id'][0]) ? (int) $meta['_dbvc_original_id'][0] : (int) $item->object_id;

                $post_array['db_id']            = $item->db_id;
                $post_array['menu_item_parent'] = $item->menu_item_parent;
                $post_array['object_id']        = $item->object_id;
                $post_array['object']           = $item->object;
                $post_array['type']             = $item->type;
                $post_array['type_label']       = $item->type_label;
                $post_array['title']            = $item->title;
                $post_array['url']              = $item->url;
                $post_array['target']           = $item->target;
                $post_array['attr_title']       = $item->attr_title;
                $post_array['description']      = $item->description;
                $post_array['classes'] = is_array($item->classes) ? $item->classes : explode(' ', $item->classes);
                $post_array['xfn']              = $item->xfn;
                $post_array['original_id']      = $original_id;
                $post_array['meta']             = $meta;

                $formatted_items[] = $post_array;
            }

            $menu_data = [
                'name'      => $menu->name,
                'slug'      => $menu->slug,
                'locations' => array_keys(
                    array_filter(
                        get_nav_menu_locations(),
                        fn($id) => $id === $menu->term_id
                    )
                ),
                'items'     => $formatted_items,
            ];

            $data[] = $menu_data;
        }

        $path = dbvc_get_sync_path();
        if (! is_dir($path)) {
            wp_mkdir_p($path);
        }

        self::ensure_directory_security($path);

        file_put_contents(
            $path . 'menus.json',
            wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        error_log('[DBVC] Exported menus to menus.json');
    }




    /**
     * Import menus from JSON file.
     * 
     * @since  1.0.0
     * @return void
     */
    public static function import_menus_from_json()
    {
        $file = dbvc_get_sync_path() . 'menus.json';
        if (! file_exists($file)) {
            error_log('[DBVC] Menus JSON file not found at: ' . $file);
            return;
        }

        $menus = json_decode(file_get_contents($file), true);
        if (! is_array($menus)) {
            error_log('[DBVC] Invalid JSON format in menus.json');
            return;
        }

        global $wpdb;

        // Build post ID map for remapping referenced objects
        $post_map = [];
        $imported_ids = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_dbvc_original_id'"
        );
        foreach ($imported_ids as $row) {
            $post_map[$row->meta_value] = $row->post_id;
        }

        foreach ($menus as $menu_data) {
            if (! isset($menu_data['name']) || ! is_array($menu_data['items'] ?? null)) {
                error_log('[DBVC] Skipped invalid menu structure.');
                continue;
            }

            $existing_menu = wp_get_nav_menu_object($menu_data['name']);
            $menu_id = $existing_menu ? $existing_menu->term_id : wp_create_nav_menu($menu_data['name']);

            if (is_wp_error($menu_id)) {
                error_log('[DBVC] Failed to create/reuse menu "' . $menu_data['name'] . '": ' . $menu_id->get_error_message());
                continue;
            }

            if ($existing_menu) {
                $old_items = wp_get_nav_menu_items($menu_id);
                if ($old_items) {
                    foreach ($old_items as $old_item) {
                        wp_delete_post($old_item->ID, true);
                    }
                    error_log("[DBVC] Cleared existing menu items for '{$menu_data['name']}'");
                }
            }

            $created_items = [];     // old_id => new_id
            $pending_parents = [];   // child => original parent

            foreach ($menu_data['items'] as $item) {
                $original_id = (int)($item['db_id'] ?? 0);
                $object_id   = (int)($item['object_id'] ?? 0);
                $type        = $item['type'] ?? '';
                $object      = $item['object'] ?? '';

                $mapped_object_id = 0;

                // Handle only post_type or taxonomy object ID mapping
                if (in_array($type, ['post_type', 'taxonomy'], true)) {
                    $mapped_object_id = $post_map[$object_id] ?? $object_id;
                    if (! get_post_status($mapped_object_id) && $type === 'post_type') {
                        error_log("[DBVC] Skipping menu item due to missing post object ID: $mapped_object_id");
                        continue;
                    }
                }

                $classes = is_array($item['classes']) ? implode(' ', $item['classes']) : (string) $item['classes'];

                $item_args = [
                    'menu-item-title'      => $item['title'] ?? '',
                    'menu-item-object'     => $object,
                    'menu-item-object-id'  => $mapped_object_id,
                    'menu-item-type'       => $type,
                    'menu-item-status'     => 'publish',
                    'menu-item-url'        => $item['url'] ?? '',
                    'menu-item-classes'    => $classes,
                    'menu-item-xfn'        => $item['xfn'] ?? '',
                    'menu-item-target'     => $item['target'] ?? '',
                    'menu-item-attr-title' => $item['attr_title'] ?? '',
                    'menu-item-description' => $item['description'] ?? '',
                    'menu-item-position'   => $item['menu_order'] ?? 0,
                    'menu-item-parent-id'  => 0,
                ];

                $item_id = wp_update_nav_menu_item($menu_id, 0, $item_args);

                if (is_wp_error($item_id)) {
                    error_log('[DBVC] Failed to add menu item "' . $item['title'] . '": ' . $item_id->get_error_message());
                    continue;
                }

                $created_items[$original_id] = $item_id;
                update_post_meta($item_id, '_dbvc_original_id', $original_id);

                // ✅ Restore all other meta fields
                if (! empty($item['meta']) && is_array($item['meta'])) {
                    foreach ($item['meta'] as $meta_key => $meta_values) {
                        if ($meta_key === '_dbvc_original_id') {
                            continue;
                        }
                        delete_post_meta($item_id, $meta_key);
                        foreach ($meta_values as $meta_value) {
                            add_post_meta($item_id, $meta_key, maybe_unserialize($meta_value));
                        }
                    }
                }

                if (! empty($item['menu_item_parent'])) {
                    $pending_parents[] = [
                        'child_id'           => $item_id,
                        'original_parent_id' => $item['menu_item_parent'],
                    ];
                }

                error_log('[DBVC] Imported menu item ID: ' . $item_id);
            }

            foreach ($pending_parents as $pending) {
                $child_id  = $pending['child_id'];
                $parent_id = $created_items[$pending['original_parent_id']] ?? 0;

                if ($parent_id) {
                    wp_update_post([
                        'ID'          => $child_id,
                        'post_parent' => $parent_id,
                    ]);
                    update_post_meta($child_id, '_menu_item_menu_item_parent', $parent_id);
                    error_log("[DBVC] Set parent for menu item ID $child_id to $parent_id");
                }
            }

            if (isset($menu_data['locations']) && is_array($menu_data['locations'])) {
                $locations = get_nav_menu_locations();
                foreach ($menu_data['locations'] as $loc) {
                    $locations[$loc] = $menu_id;
                    error_log('[DBVC] Set menu "' . $menu_data['name'] . '" to location "' . $loc . '"');
                }
                set_theme_mod('nav_menu_locations', $locations);
            }
        }
    }





    /**
     * Export posts in batches for better performance.
     * 
     * @param int $batch_size Number of posts to process per batch.
     * @param int $offset     Starting offset for the batch.
     * 
     * @since  1.0.0
     * @return array Results with processed count and remaining count.
     */
    public static function export_posts_batch($batch_size = 100, $offset = 0)
    {
        $supported_types = self::get_supported_post_types();

        $posts = get_posts([
            'post_type'      => $supported_types,
            'posts_per_page' => $batch_size,
            'offset'         => $offset,
            'post_status'    => 'any',
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $processed = 0;
        foreach ($posts as $post) {
            self::export_post_to_json($post->ID, $post);
            $processed++;
        }

        // Get total count for progress tracking
        $total_posts = self::wp_count_posts_by_type($supported_types);
        $remaining = max(0, $total_posts - ($offset + $processed));

        return [
            'processed' => $processed,
            'remaining' => $remaining,
            'total'     => $total_posts,
            'offset'    => $offset + $processed,
        ];
    }

    /**
     * Import posts in batches for better performance.
     * 
     * @param int $batch_size Number of files to process per batch.
     * @param int $offset     Starting offset for the batch.
     * 
     * @since  1.0.0
     * @return array Results with processed count and remaining count.
     */
    public static function import_posts_batch($batch_size = 50, $offset = 0, $filename_mode = null)
    {
        $supported_types = self::get_supported_post_types();
        $entries         = [];
        $normalized_mode = ($filename_mode !== null) ? self::normalize_filename_mode($filename_mode) : null;

        foreach ($supported_types as $post_type) {
            $path  = dbvc_get_sync_path($post_type);
            $files = glob($path . '*.json');
            if (empty($files)) {
                continue;
            }

            sort($files);
            foreach ($files as $file) {
                $raw  = file_get_contents($file);
                $json = json_decode($raw, true);
                if (empty($json) || ! is_array($json)) {
                    continue;
                }

                if (! self::import_filename_matches_mode($normalized_mode, $file, $post_type, $json)) {
                    continue;
                }

                $entries[] = $file;
            }
        }

        $total = count($entries);

        if ($batch_size <= 0) {
            $batch_files = $entries;
            $next_offset = $total;
        } else {
            $batch_files = array_slice($entries, $offset, $batch_size);
            $next_offset = $offset + count($batch_files);
        }

        $processed = 0;
        foreach ($batch_files as $file) {
            $result = self::import_post_from_json($file, false, $normalized_mode, null, null, null, false, []);
            if ($result === self::IMPORT_RESULT_APPLIED) {
                $processed++;
            }
        }

        $remaining = max(0, $total - $next_offset);

        $result = [
            'processed' => $processed,
            'remaining' => $remaining,
            'total'     => $total,
            'offset'    => $next_offset,
        ];

        if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
            DBVC_Sync_Logger::log_import('Batch import processed', [
                'batch_size'      => $batch_size,
                'requested_offset'=> $offset,
                'next_offset'     => $next_offset,
                'processed'       => $processed,
                'remaining'       => $remaining,
                'total'           => $total,
                'mode'            => $normalized_mode ?: 'all',
            ]);
        }

        return $result;
    }

    /**
     * Get total count of posts for all supported post types.
     * 
     * @param array $post_types Post types to count.
     * 
     * @since  1.0.0
     * @return int Total post count.
     */
    private static function wp_count_posts_by_type($post_types)
    {
        $total = 0;

        foreach ($post_types as $post_type) {
            $counts = wp_count_posts($post_type);
            if ($counts) {
                foreach ($counts as $status => $count) {
                    $total += $count;
                }
            }
        }

        return $total;
    }

    /**
     * Export FSE theme data to JSON.
     * 
     * @since  1.1.0
     * @return void
     */
    public static function export_fse_theme_data()
    {
        if (! did_action('wp_loaded')) {
            return;
        }

        /* 
        @WIP
        *
        $existing = get_post(absint($json['ID']));

        if ($smart_import && $existing) {
            $hash_key = '_dbvc_import_hash';
            $new_hash = md5(serialize([$json['post_content'], $json['meta'] ?? []]));
            $existing_hash = get_post_meta($existing->ID, $hash_key, true);

            if ($new_hash === $existing_hash) {
                return; // Skip unchanged post
            }
        } */


        if (!wp_is_block_theme()) {
            return;
        }

        // Skip during admin page loads to prevent conflicts.
        if (is_admin() && !wp_doing_ajax() && !defined('WP_CLI')) {
            return;
        }

        if (!defined('WP_CLI') || !WP_CLI) {
            if (!current_user_can('edit_theme_options')) {
                return;
            }
        }

        // Load current theme data
        $theme_data = [
            'theme_name' => get_stylesheet(),
            'custom_css' => wp_get_custom_css(),
        ];

        // Load theme.json content
        if (class_exists('WP_Theme_JSON_Resolver')) {
            try {
                if (did_action('init') && !is_admin()) {
                    $theme_json = WP_Theme_JSON_Resolver::get_merged_data();
                    $theme_data['theme_json'] = method_exists($theme_json, 'get_raw_data')
                        ? $theme_json->get_raw_data()
                        : [];
                } else {
                    $theme_data['theme_json'] = [];
                }
            } catch (Exception | Error $e) {
                error_log('DBVC: Error loading theme JSON: ' . $e->getMessage());
                $theme_data['theme_json'] = [];
            }
        } else {
            $theme_data['theme_json'] = [];
        }

        // Allow other plugins to modify FSE theme data.
        $theme_data = apply_filters('dbvc_export_fse_theme_data', $theme_data);

        // Prepare file path
        $path = dbvc_get_sync_path('theme');
        if (!is_dir($path) && !wp_mkdir_p($path)) {
            error_log('DBVC: Failed to create theme directory: ' . $path);
            return;
        }
        self::ensure_directory_security($path);

        $file_path = apply_filters('dbvc_export_fse_theme_file_path', $path . 'theme-data.json');
        if (!dbvc_is_safe_file_path($file_path)) {
            error_log('DBVC: Unsafe file path detected: ' . $file_path);
            return;
        }

        // Check if unchanged to avoid re-exporting
        $new_hash = md5(serialize($theme_data));
        $existing_post = get_page_by_path('theme-data', OBJECT, 'attachment'); // Optional fallback

        if ($existing_post) {
            $existing_history = get_post_meta($existing_post->ID, 'dbvc_post_history', true);
            $existing_hash = is_array($existing_history) ? ($existing_history['hash'] ?? '') : '';

            if ($new_hash === $existing_hash) {
                return; // Skip export if no changes
            }

            // Update history
            $history = $existing_history;
            $history['hash'] = $new_hash;
            $history['updated_at'] = current_time('mysql');
            update_post_meta($existing_post->ID, 'dbvc_post_history', $history);
        }

        // Save theme JSON to file
        $json_content = wp_json_encode($theme_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json_content === false) {
            error_log('DBVC: Failed to encode FSE theme JSON');
            return;
        }

        if (file_put_contents($file_path, $json_content) === false) {
            error_log('DBVC: Failed to write theme-data.json');
            return;
        }

        do_action('dbvc_after_export_fse_theme_data', $file_path, $theme_data);
    }

    /**
     * Import FSE theme data from JSON.
     * 
     * @since  1.1.0
     * @return void
     */
    public static function import_fse_theme_data()
    {
        // Check user capabilities for FSE import.
        if (! current_user_can('edit_theme_options')) {
            return;
        }

        $file_path = dbvc_get_sync_path('theme') . 'theme-data.json';
        if (! file_exists($file_path)) {
            return;
        }

        $theme_data = json_decode(file_get_contents($file_path), true);
        if (empty($theme_data)) {
            return;
        }

        // Import custom CSS.
        if (isset($theme_data['custom_css']) && ! empty($theme_data['custom_css'])) {
            wp_update_custom_css_post($theme_data['custom_css']);
        }

        // Allow other plugins to handle additional FSE import data.
        do_action('dbvc_after_import_fse_theme_data', $theme_data);
    }

    /**
     * Download the latest sync files as dbvc-sync-{date}.zip
     */
    public static function handle_download_sync()
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'dbvc'));
        }

        if (! wp_verify_nonce($_GET['_wpnonce'] ?? '', 'dbvc_download_sync')) {
            wp_die(__('Nonce check failed.', 'dbvc'));
        }

        $sync_dir = dbvc_get_sync_path();
        if (! is_dir($sync_dir)) {
            wp_die(__('Sync directory does not exist.', 'dbvc'));
        }

        $tmp_zip = self::get_temp_file();
        $zip     = new ZipArchive();

        if (true !== $zip->open($tmp_zip, ZipArchive::OVERWRITE)) {
            wp_die(__('Could not create ZIP.', 'dbvc'));
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sync_dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $rel_path = ltrim(str_replace($sync_dir, '', $file), '/');
            $zip->addFile($file, $rel_path);
        }
        $zip->close();

        $filename = 'dbvc-sync-' . gmdate('Ymd-His') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp_zip));
        readfile($tmp_zip);
        @unlink($tmp_zip);
        exit;
    }

    /**
     * Upload a ZIP or JSON file into the sync folder.
     * If ZIP → it is unpacked; if single JSON → copied.
     */
    function dbvc_handle_upload_sync()
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'dbvc'));
        }

        if (! wp_verify_nonce($_POST['dbvc_upload_sync_nonce'] ?? '', 'dbvc_upload_sync')) {
            wp_die(__('Nonce check failed.', 'dbvc'));
        }

        if (empty($_FILES['dbvc_sync_upload']['tmp_name'])) {
            wp_redirect(add_query_arg('dbvc_upload', 'empty', wp_get_referer()));
            exit;
        }

        $sync_dir = dbvc_get_sync_path();
        wp_mkdir_p($sync_dir);

        $tmp = $_FILES['dbvc_sync_upload']['tmp_name'];
        $ext = strtolower(pathinfo($_FILES['dbvc_sync_upload']['name'], PATHINFO_EXTENSION));

        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();

        global $wp_filesystem;

        if ('zip' === $ext) {
            $unzip = unzip_file($tmp, $sync_dir);
        } elseif ('json' === $ext) {
            $dest = trailingslashit($sync_dir) . basename($_FILES['dbvc_sync_upload']['name']);
            $unzip = $wp_filesystem->put_contents($dest, file_get_contents($tmp), FS_CHMOD_FILE);
        } else {
            $unzip = false;
        }

        $query_var = $unzip ? 'success' : 'fail';
        wp_redirect(add_query_arg('dbvc_upload', $query_var, wp_get_referer()));
        exit;
    }

    /**
     * Accept an uploaded ZIP or JSON and unpack it into the sync folder.
     */
    public static function handle_upload_sync()
    {
        if (! current_user_can('manage_options')) {
            wp_die(__('Permission denied.', 'dbvc'));
        }

        if (! wp_verify_nonce($_POST['dbvc_upload_sync_nonce'] ?? '', 'dbvc_upload_sync')) {
            wp_die(__('Nonce check failed.', 'dbvc'));
        }

        $raw_name = isset($_FILES['dbvc_sync_upload']['name']) ? wp_unslash($_FILES['dbvc_sync_upload']['name']) : '';
        if (is_array($raw_name)) {
            $raw_name = reset($raw_name) ?: '';
        }
        $filename = sanitize_file_name($raw_name);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $current_user = get_current_user_id();

        $uploads = class_exists('DBVC_Import_Router')
            ? DBVC_Import_Router::normalize_uploads($_FILES['dbvc_sync_upload'])
            : [];
        if (empty($uploads)) {
            if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_upload_logging_enabled()) {
                DBVC_Sync_Logger::log_upload('Sync upload aborted: no file supplied', [
                    'filename' => $filename,
                    'extension'=> $extension,
                    'user'     => $current_user,
                ]);
            }
            wp_redirect(add_query_arg('dbvc_upload', 'empty', wp_get_referer()));
            exit;
        }

        $primary  = $uploads[0];
        $tmp      = $primary['tmp_name'];
        $sync_dir = dbvc_get_sync_path();
        wp_mkdir_p($sync_dir);

        $log_context = [
            'filename'  => $filename,
            'extension' => $extension,
            'sync_dir'  => $sync_dir,
            'user'      => $current_user,
        ];

        if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_upload_logging_enabled()) {
            DBVC_Sync_Logger::log_upload('Sync upload started', $log_context);
        }

        $upload_names = [];
        foreach ($uploads as $upload) {
            if (! empty($upload['name'])) {
                $upload_names[] = (string) $upload['name'];
            }
        }
        if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_upload_logging_enabled()) {
            DBVC_Sync_Logger::log_upload('Sync upload files received', [
                'count' => count($upload_names),
                'files' => $upload_names,
            ]);
        }

        $success = false;
        $failure_reason = '';
        $dry_run = ! empty($_POST['dbvc_sync_dry_run']);
        $route_report = null;
        $has_multiple = count($uploads) > 1;
        $has_zip = false;
        foreach ($uploads as $upload) {
            $ext = strtolower(pathinfo($upload['name'] ?? '', PATHINFO_EXTENSION));
            if ($ext === 'zip') {
                $has_zip = true;
                break;
            }
        }

        if (! $dry_run) {
            $should_clear = true;
            if ($has_multiple && $extension === 'json') {
                $should_clear = false;
            }
            // Always clear existing sync folder first.
            if ($should_clear) {
                self::delete_folder_contents($sync_dir);
                if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_upload_logging_enabled()) {
                    DBVC_Sync_Logger::log_upload('Sync folder cleared prior to upload', [
                        'sync_dir' => $sync_dir,
                    ]);
                }
            } elseif (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_upload_logging_enabled()) {
                DBVC_Sync_Logger::log_upload('Sync folder retained for multi-file JSON upload', [
                    'sync_dir' => $sync_dir,
                ]);
            }
        }

        if ($dry_run && $has_zip) {
            $failure_reason = 'dry_run_zip_unsupported';
        } elseif ($has_multiple && $has_zip) {
            $failure_reason = 'mixed_upload_types';
        } elseif ('zip' === $extension && class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            $open_result = $zip->open($tmp);

            if ($open_result === true) {
                $tmp_dir = trailingslashit(self::get_temp_file() . '-extract');
                wp_mkdir_p($tmp_dir);

                if ($zip->extractTo($tmp_dir)) {
                    $zip->close();

                    $entries = array_diff(scandir($tmp_dir), ['.', '..']);
                    if (count($entries) === 1 && is_dir($tmp_dir . $entries[0])) {
                        $tmp_dir .= $entries[0] . '/';
                    }

                    self::recursive_copy($tmp_dir, $sync_dir);
                    self::delete_folder_contents(dirname($tmp_dir));
                    @rmdir(dirname($tmp_dir));

                    $success = true;
                } else {
                    $zip->close();
                    $failure_reason = 'zip_extract_failed';
                }
            } else {
                $failure_reason = 'zip_open_failed';
            }
        } elseif ('json' === $extension && class_exists('DBVC_Import_Router')) {
            $route_stats = DBVC_Import_Router::route_uploaded_json($uploads, [
                'sync_dir'          => $sync_dir,
                'overwrite'         => true,
                'log_enabled'       => class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_upload_logging_enabled(),
                'generate_manifest' => false,
                'dry_run'           => $dry_run,
            ]);
            $route_report = $route_stats;
            $success = ($route_stats['errors'] ?? 0) === 0;
            if (! $success) {
                $failure_reason = ($route_stats['errors'] ?? 0) > 0 ? 'json_route_failed' : 'json_route_skipped';
            }
        } elseif ('json' === $extension) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
            global $wp_filesystem;

            $dest    = trailingslashit($sync_dir) . $filename;
            $success = (bool) $wp_filesystem->put_contents($dest, file_get_contents($tmp), FS_CHMOD_FILE);
            if (! $success) {
                $failure_reason = 'json_write_failed';
            }
        } else {
            $failure_reason = 'unsupported_extension';
        }

        if (! $success && class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_upload_logging_enabled()) {
            DBVC_Sync_Logger::log_upload('Sync upload failed', array_merge($log_context, [
                'reason' => $failure_reason ?: 'unknown',
            ]));
        } elseif ($success && class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_upload_logging_enabled()) {
            DBVC_Sync_Logger::log_upload('Sync upload completed', $log_context);
        }

        if ($route_report !== null) {
            $route_report['dry_run'] = $dry_run;
            $route_report['timestamp'] = current_time('mysql');
            if (! empty($upload_names)) {
                $route_report['files'] = $upload_names;
            }
            update_option('dbvc_sync_upload_report', $route_report, false);
        }

        if ($success && class_exists('DBVC_Sync_Taxonomies') && method_exists('DBVC_Sync_Taxonomies', 'normalize_term_json_files')) {
            DBVC_Sync_Taxonomies::normalize_term_json_files();
        }

        $status = $success ? 'success' : 'fail';
        wp_redirect(add_query_arg('dbvc_upload', $status, wp_get_referer()));
        exit;
    }


    /**
     * Recursively copy files and folders.
     */
    public static function recursive_copy($src, $dst)
    {
        $dir = opendir($src);
        wp_mkdir_p($dst);

        while (false !== ($file = readdir($dir))) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $src_path = $src . '/' . $file;
            $dst_path = $dst . '/' . $file;

            if (is_dir($src_path)) {
                self::recursive_copy($src_path, $dst_path);
            } else {
                copy($src_path, $dst_path);
            }
        }

        closedir($dir);
    }


    /**
     * Recursively delete all contents of a folder.
     */
    public static function delete_folder_contents($folder_path)
    {
        if (! is_dir($folder_path)) {
            return;
        }

        $items = new \FilesystemIterator($folder_path, \FilesystemIterator::SKIP_DOTS);
        foreach ($items as $item) {
            if ($item->isDir()) {
                self::delete_folder_contents($item->getPathname());
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
    }


    public static function delete_post_json_for_entity($post_id, $preserve_entity = false, $force_supported = false): void
    {
        $post_id = (int) $post_id;
        if (! $post_id) {
            return;
        }
        self::mark_post_deleting($post_id);
        $preserve_entity = (bool) $preserve_entity;
        $force_supported = (bool) $force_supported;

        $post = get_post($post_id);
        if (! $post || ! is_object($post)) {
            return;
        }

        if (! $force_supported && ! self::is_supported_post_type($post->post_type)) {
            return;
        }

        $folder = trailingslashit(dbvc_get_sync_path($post->post_type));
        if (! is_dir($folder)) {
            return;
        }

        // keep logging minimal for deletes

        $slug = sanitize_title($post->post_name);
        $uuid = get_post_meta($post_id, 'uuid', true);
        $uuid = is_string($uuid) ? trim($uuid) : '';
        $uid = get_post_meta($post_id, 'vf_object_uid', true);
        $uid = is_string($uid) ? trim($uid) : '';

        $files = glob($folder . '*.json');
        if (empty($files)) {
        if (! $preserve_entity && class_exists('DBVC_Database')) {
            DBVC_Database::delete_entity_by_object('post:' . sanitize_key($post->post_type), $post_id);
        }
        return;
        }

        // 1) Match by UUID
        if ($uuid !== '') {
            $matches = [];
            foreach ($files as $file) {
                $raw = file_get_contents($file);
                if ($raw === false) {
                    continue;
                }
                $payload = json_decode($raw, true);
                if (! is_array($payload)) {
                    continue;
                }
                $payload_uuid = isset($payload['uuid']) ? (string) $payload['uuid'] : '';
                if ($payload_uuid === '' && isset($payload['meta']['uuid'])) {
                    $payload_uuid = is_array($payload['meta']['uuid']) ? (string) ($payload['meta']['uuid'][0] ?? '') : (string) $payload['meta']['uuid'];
                }
                if ($payload_uuid !== '' && $payload_uuid === $uuid) {
                    $matches[] = $file;
                }
            }
            if (count($matches) === 1) {
                @unlink($matches[0]);
                if (! $preserve_entity && class_exists('DBVC_Database')) {
                    DBVC_Database::delete_entity_by_uid($uuid);
                }
                return;
            }
        }

        // 2) Match by vf_object_uid (payload or history)
        if ($uid !== '') {
            $matches = [];
            foreach ($files as $file) {
                $raw = file_get_contents($file);
                if ($raw === false) {
                    continue;
                }
                $payload = json_decode($raw, true);
                if (! is_array($payload)) {
                    continue;
                }
                $payload_uid = isset($payload['vf_object_uid']) ? (string) $payload['vf_object_uid'] : '';
                if ($payload_uid === '' && isset($payload['meta']['dbvc_post_history']['vf_object_uid'])) {
                    $payload_uid = (string) $payload['meta']['dbvc_post_history']['vf_object_uid'];
                }
                if ($payload_uid !== '' && $payload_uid === $uid) {
                    $matches[] = $file;
                }
            }
            if (count($matches) === 1) {
                @unlink($matches[0]);
                if (! $preserve_entity && class_exists('DBVC_Database')) {
                    DBVC_Database::delete_entity_by_uid($uid);
                }
                return;
            }
        }

        $basename_prefix = sanitize_key($post->post_type) . '-';
        $slug_id = ($slug !== '' && $post_id) ? $basename_prefix . $slug . '-' . $post_id . '.json' : '';
        $id_name = $basename_prefix . $post_id . '.json';
        $slug_name = ($slug !== '') ? $basename_prefix . $slug . '.json' : '';

        // 3) slug_id
        if ($slug_id !== '' && file_exists($folder . $slug_id)) {
            @unlink($folder . $slug_id);
            if (! $preserve_entity && class_exists('DBVC_Database')) {
                DBVC_Database::delete_entity_by_object('post:' . sanitize_key($post->post_type), $post_id);
            }
            return;
        }

        // 4) id
        if (file_exists($folder . $id_name)) {
            @unlink($folder . $id_name);
            if (! $preserve_entity && class_exists('DBVC_Database')) {
                DBVC_Database::delete_entity_by_object('post:' . sanitize_key($post->post_type), $post_id);
            }
            return;
        }

        // 5) slug
        if ($slug_name !== '' && file_exists($folder . $slug_name)) {
            @unlink($folder . $slug_name);
            if (! $preserve_entity && class_exists('DBVC_Database')) {
                DBVC_Database::delete_entity_by_object('post:' . sanitize_key($post->post_type), $post_id);
            }
            return;
        }

        if (! $preserve_entity && class_exists('DBVC_Database')) {
            DBVC_Database::delete_entity_by_object('post:' . sanitize_key($post->post_type), $post_id);
        }
    }

    private static function ensure_clean_export_directory(string $post_type): void
    {
        static $cleaned = [];
        if (isset($cleaned[$post_type])) {
            return;
        }

        $path = trailingslashit(dbvc_get_sync_path($post_type));
        if (is_dir($path)) {
            self::delete_folder_contents($path);
        }
        wp_mkdir_p($path);
        self::ensure_directory_security($path);

        $cleaned[$post_type] = true;
    }

    private static function record_proposal_new_entity(string $proposal_id, string $entity_uid): void
    {
        $proposal_id = sanitize_text_field($proposal_id);
        $entity_uid = is_string($entity_uid) ? trim($entity_uid) : '';
        if ($proposal_id === '' || $entity_uid === '') {
            return;
        }

        $store = get_option(self::PROPOSAL_NEW_ENTITIES_OPTION, []);
        if (! is_array($store)) {
            $store = [];
        }
        if (! isset($store[$proposal_id]) || ! is_array($store[$proposal_id])) {
            $store[$proposal_id] = [];
        }

        $store[$proposal_id][$entity_uid] = true;
        update_option(self::PROPOSAL_NEW_ENTITIES_OPTION, $store, false);
    }

    /**
     * Remove a previously-recorded new entity so reopen automation honours reviewer intent.
     *
     * @param string $proposal_id
     * @param string $entity_uid
     * @return void
     */
    public static function remove_proposal_new_entity(string $proposal_id, string $entity_uid): void
    {
        $proposal_id = sanitize_text_field($proposal_id);
        $entity_uid  = is_string($entity_uid) ? trim($entity_uid) : '';
        if ($proposal_id === '' || $entity_uid === '') {
            return;
        }

        $store = get_option(self::PROPOSAL_NEW_ENTITIES_OPTION, []);
        if (! is_array($store) || ! isset($store[$proposal_id]) || ! is_array($store[$proposal_id])) {
            return;
        }

        if (isset($store[$proposal_id][$entity_uid])) {
            unset($store[$proposal_id][$entity_uid]);
            if (empty($store[$proposal_id])) {
                unset($store[$proposal_id]);
            }
            update_option(self::PROPOSAL_NEW_ENTITIES_OPTION, $store, false);
        }
    }

    public static function get_proposal_new_entities(string $proposal_id): array
    {
        $proposal_id = sanitize_text_field($proposal_id);
        if ($proposal_id === '') {
            return [];
        }

        $store = get_option(self::PROPOSAL_NEW_ENTITIES_OPTION, []);
        if (! is_array($store) || ! isset($store[$proposal_id]) || ! is_array($store[$proposal_id])) {
            return [];
        }

        return array_keys($store[$proposal_id]);
    }

    private static function normalize_mask_directive_store(array $store): array
    {
        $normalized = [];

        foreach ($store as $vf_object_uid => $meta_entries) {
            if (! is_array($meta_entries)) {
                continue;
            }

            $scoped = self::normalize_mask_meta_entry_bucket($meta_entries);
            if (! empty($scoped)) {
                $normalized[$vf_object_uid] = $scoped;
            }
        }

        return $normalized;
    }

    private static function normalize_mask_meta_entry_bucket(array $entries): array
    {
        $normalized = [];
        $has_scopes = false;

        foreach (['meta', 'post'] as $scope_key) {
            if (isset($entries[$scope_key]) && is_array($entries[$scope_key])) {
                $has_scopes = true;
                $bucket = self::normalize_mask_meta_entries($entries[$scope_key], $scope_key);
                if (! empty($bucket)) {
                    $normalized[$scope_key] = $bucket;
                }
            }
        }

        if (! $has_scopes) {
            $bucket = self::normalize_mask_meta_entries($entries, 'meta');
            if (! empty($bucket)) {
                $normalized['meta'] = $bucket;
            }
        }

        return $normalized;
    }

    private static function is_mask_directive_leaf(array $entry): bool
    {
        return array_key_exists('path', $entry) && ! array_key_exists(0, $entry);
    }

    private static function flatten_mask_meta_entries(array $entries): array
    {
        $flattened = [
            'meta' => [],
            'post' => [],
        ];

        $has_scopes = isset($entries['meta']) || isset($entries['post']);
        if ($has_scopes) {
            foreach (['meta', 'post'] as $scope_key) {
                if (! isset($entries[$scope_key]) || ! is_array($entries[$scope_key])) {
                    continue;
                }
                $flattened[$scope_key] = self::flatten_mask_scope_bucket($entries[$scope_key]);
            }
        } else {
            $flattened['meta'] = self::flatten_mask_scope_bucket($entries);
        }

        return $flattened;
    }

    private static function flatten_mask_scope_bucket(array $entries): array
    {
        $flattened = [];

        foreach ($entries as $meta_key => $bucket) {
            if (! is_array($bucket)) {
                continue;
            }

            if (self::is_mask_directive_leaf($bucket)) {
                $flattened[$meta_key] = $bucket;
                continue;
            }

            $first = reset($bucket);
            if (is_array($first)) {
                $flattened[$meta_key] = $first;
            }
        }

        return $flattened;
    }

    private static function normalize_mask_meta_entries(array $entries, string $scope = 'meta'): array
    {
        $normalized = [];

        foreach ($entries as $meta_key => $bucket) {
            if (! is_array($bucket)) {
                continue;
            }

            if (self::is_mask_directive_leaf($bucket)) {
                $bucket = [($bucket['path'] ?? (string) $meta_key) => $bucket];
            }

            foreach ($bucket as $path_key => $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $meta_path = isset($entry['path']) ? (string) $entry['path'] : (string) $path_key;
                $meta_path = $meta_path !== '' ? $meta_path : (string) $path_key;
                $resolved_meta_key = isset($entry['meta_key']) && $entry['meta_key'] !== ''
                    ? (string) $entry['meta_key']
                    : (is_string($meta_key) && $meta_key !== '' ? (string) $meta_key : self::extract_meta_key_from_mask_path($meta_path));

                if ($resolved_meta_key === '') {
                    continue;
                }

                $bucket_key = $resolved_meta_key;
                if ($scope === 'post' && strpos($bucket_key, 'post:') !== 0) {
                    $bucket_key = self::build_mask_storage_key('post', $resolved_meta_key);
                }

                if (! isset($normalized[$bucket_key]) || ! is_array($normalized[$bucket_key])) {
                    $normalized[$bucket_key] = [];
                }

                $entry['path'] = $meta_path;
                $entry['meta_key'] = $bucket_key;
                $entry['scope'] = ($scope === 'post') ? 'post' : 'meta';
                if (! isset($entry['field_key']) || $entry['field_key'] === '') {
                    $parsed = self::parse_mask_path_info($meta_path);
                    $entry['field_key'] = $parsed['field'];
                }

                $normalized[$bucket_key][$meta_path] = $entry;
            }
        }

        return $normalized;
    }

    private static function extract_meta_key_from_mask_path(string $path): string
    {
        $parsed = self::parse_mask_path_info($path);
        return $parsed['bucket_key'];
    }

    private static function parse_mask_path_info(string $path): array
    {
        $path = (string) $path;
        $scope = 'meta';
        $field = '';

        if (strpos($path, 'post.') === 0) {
            $scope = 'post';
            $field = substr($path, 5);
        } elseif (strpos($path, 'meta.') === 0) {
            $parts = explode('.', $path);
            $field = $parts[1] ?? '';
        } elseif ($path !== '') {
            $field = $path;
        }

        $field = trim($field);
        if ($field === '') {
            $field = $path;
        }

        return [
            'scope'      => ($scope === 'post') ? 'post' : 'meta',
            'field'      => $field,
            'bucket_key' => self::build_mask_storage_key($scope, $field),
        ];
    }

    private static function build_mask_storage_key(string $scope, string $field): string
    {
        return ($scope === 'post') ? 'post:' . (string) $field : (string) $field;
    }
}
