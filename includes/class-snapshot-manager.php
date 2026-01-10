<?php
/**
 * Manage live snapshots used for before/after comparisons.
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_Snapshot_Manager')) {
    class DBVC_Snapshot_Manager
    {
        private const SNAPSHOT_DIR = 'db-version-control-snapshots';

        /**
         * Return the absolute base path for stored snapshots.
         */
        public static function get_base_path(): string
        {
            $upload_dir = wp_upload_dir();
            $base       = trailingslashit($upload_dir['basedir']) . 'sync/' . self::SNAPSHOT_DIR;

            if (! is_dir($base)) {
                wp_mkdir_p($base);
            }

            self::ensure_directory_security($base);

            return $base;
        }

        /**
         * Capture current-state snapshots for each manifest entity.
         */
        public static function capture_for_proposal(string $proposal_id, array $manifest): void
        {
            $enabled = apply_filters('dbvc_enable_snapshot_capture', true, $proposal_id, $manifest);
            if (! $enabled) {
                return;
            }

            $items = isset($manifest['items']) && is_array($manifest['items']) ? $manifest['items'] : [];
            if (empty($items)) {
                return;
            }

            foreach ($items as $item) {
                if (($item['item_type'] ?? '') !== 'post') {
                    continue;
                }

                $original_id = isset($item['post_id']) ? (int) $item['post_id'] : 0;
                $vf_object_uid = isset($item['vf_object_uid'])
                    ? (string) $item['vf_object_uid']
                    : (string) $original_id;

                $local_post_id = DBVC_Sync_Posts::resolve_local_post_id($original_id, $vf_object_uid, $item['post_type'] ?? '');
                if (! $local_post_id) {
                    continue;
                }

                self::capture_post_snapshot($proposal_id, $local_post_id, $vf_object_uid);
            }
        }

        /**
         * Read snapshot for the requested entity.
         */
        public static function read_snapshot(string $proposal_id, string $vf_object_uid)
        {
            $path = self::get_snapshot_file_path($proposal_id, $vf_object_uid);
            if (! $path || ! file_exists($path)) {
                return null;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                return null;
            }

            $decoded = json_decode($contents, true);
            return is_array($decoded) ? $decoded : null;
        }

        /**
         * Internal: capture snapshot for a single post ID.
         */
        public static function capture_post_snapshot(string $proposal_id, int $post_id, string $vf_object_uid = ''): void
        {
            $post = get_post($post_id);
            if (! $post instanceof \WP_Post) {
                self::delete_snapshot($proposal_id, $vf_object_uid !== '' ? $vf_object_uid : (string) $post_id);
                return;
            }

            $payload = self::build_post_payload($post);
            if (is_wp_error($payload) || empty($payload)) {
                return;
            }

            $key = $vf_object_uid !== '' ? $vf_object_uid : (string) $post_id;
            $file_path = self::get_snapshot_file_path($proposal_id, $key);
            if (! $file_path) {
                return;
            }
            $dir = dirname($file_path);
            if (! is_dir($dir)) {
                wp_mkdir_p($dir);
                self::ensure_directory_security($dir);
            }

            $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return;
            }

            file_put_contents($file_path, $json);
        }

        /**
         * Build export-like payload for the current post state.
         */
        private static function build_post_payload(\WP_Post $post)
        {
            $post_id = (int) $post->ID;
            if (! $post_id) {
                return new \WP_Error('dbvc_snapshot_invalid_id', __('Invalid post ID for snapshot.', 'dbvc'));
            }

            $allowed_statuses = ['publish'];
            if (in_array($post->post_type, ['wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation'], true)) {
                $allowed_statuses[] = 'draft';
                $allowed_statuses[] = 'auto-draft';
            }
            $allowed_statuses = apply_filters('dbvc_allowed_statuses_for_export', $allowed_statuses, $post);
            if (! in_array($post->post_status, $allowed_statuses, true)) {
                return new \WP_Error('dbvc_snapshot_skipped_status', __('Post status not allowed for snapshot.', 'dbvc'));
            }

            $supported_types = DBVC_Sync_Posts::get_supported_post_types();
            if (! in_array($post->post_type, $supported_types, true)) {
                return new \WP_Error('dbvc_snapshot_skipped_type', __('Post type not supported for snapshot.', 'dbvc'));
            }

            $raw_meta       = get_post_meta($post_id);
            $sanitized_meta = function_exists('dbvc_sanitize_post_meta_safe')
                ? dbvc_sanitize_post_meta_safe($raw_meta)
                : $raw_meta;

            $domain_to_strip = get_option('dbvc_strip_domain_urls') === '1' ? untrailingslashit(home_url()) : '';
            $post_content    = $post->post_content;
            $post_excerpt    = $post->post_excerpt;
            if ($domain_to_strip) {
                $needles      = [$domain_to_strip, $domain_to_strip . '/'];
                $post_content = is_string($post_content) ? str_replace($needles, '', $post_content) : '';
                $post_excerpt = is_string($post_excerpt) ? str_replace($needles, '', $post_excerpt) : '';
                $sanitized_meta = self::strip_domain_from_meta_urls($sanitized_meta, $domain_to_strip);
            }

            $tax_input = DBVC_Sync_Posts::export_tax_input_portable($post_id, $post->post_type);

            $data = [
                'ID'           => $post_id,
                'vf_object_uid'=> DBVC_Sync_Posts::ensure_post_uid($post_id, $post),
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

            if (in_array($post->post_type, ['wp_template', 'wp_template_part'], true)) {
                $data['theme']  = get_stylesheet();
                $data['slug']   = $post->post_name;
                $data['source'] = get_post_meta($post_id, 'origin', true) ?: 'custom';
            }

            $data = apply_filters('dbvc_export_post_data', $data, $post_id, $post);

            if (get_option('dbvc_export_sort_meta', '0') === '1'
                && isset($data['meta'])
                && is_array($data['meta'])
                && function_exists('dbvc_sort_array_recursive')
            ) {
                $data['meta'] = dbvc_sort_array_recursive($data['meta']);
            }

            if (function_exists('dbvc_normalize_for_json')) {
                $data = dbvc_normalize_for_json($data);
            }

            return $data;
        }

        private static function get_snapshot_file_path(string $proposal_id, string $vf_object_uid): string
        {
            $dir  = trailingslashit(self::get_base_path()) . sanitize_file_name($proposal_id);
            $key  = sanitize_file_name($vf_object_uid !== '' ? $vf_object_uid : uniqid('entity_', true));
            return trailingslashit($dir) . $key . '.json';
        }

        private static function delete_snapshot(string $proposal_id, string $vf_object_uid): void
        {
            $path = self::get_snapshot_file_path($proposal_id, $vf_object_uid);
            if ($path && file_exists($path)) {
                @unlink($path);
            }
        }

        private static function ensure_directory_security(string $path): void
        {
            if (! is_dir($path)) {
                return;
            }

            $htaccess = trailingslashit($path) . '.htaccess';
            if (! file_exists($htaccess)) {
                $contents = "# Block direct access to DBVC snapshots\nOrder allow,deny\nDeny from all\n\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n\nOptions -Indexes\n";
                file_put_contents($htaccess, $contents);
            }

            $index = trailingslashit($path) . 'index.php';
            if (! file_exists($index)) {
                file_put_contents($index, "<?php\n// Silence is golden.\nexit;\n");
            }
        }

        private static function strip_domain_from_meta_urls($meta, string $domain)
        {
            if (function_exists('dbvc_recursive_str_replace')) {
                $meta = dbvc_recursive_str_replace($domain . '/', '', $meta);
                $meta = dbvc_recursive_str_replace($domain, '', $meta);
                return $meta;
            }

            if (! is_array($meta)) {
                return $meta;
            }

            $needles = [$domain, $domain . '/'];
            foreach ($meta as $key => $values) {
                if (is_array($values)) {
                    $meta[$key] = self::strip_domain_from_meta_urls($values, $domain);
                } elseif (is_string($values)) {
                    $meta[$key] = str_replace($needles, '', $values);
                }
            }
            return $meta;
        }
    }
}
