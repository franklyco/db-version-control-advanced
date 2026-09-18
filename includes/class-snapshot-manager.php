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
         *
         * Readers pass false so path lookup does not create or harden storage.
         * Capture writers retain the historical create-and-harden behavior.
         */
        public static function get_base_path(bool $create = true): string
        {
            $upload_dir = $create ? wp_upload_dir() : wp_get_upload_dir();
            $base       = trailingslashit($upload_dir['basedir']) . 'sync/' . self::SNAPSHOT_DIR;

            if ($create && ! is_dir($base)) {
                wp_mkdir_p($base);
            }

            if ($create) {
                self::ensure_directory_security($base);
            }

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
                $item_type = isset($item['item_type']) ? (string) $item['item_type'] : 'post';

                if ($item_type === 'term') {
                    $taxonomy = isset($item['term_taxonomy'])
                        ? sanitize_key($item['term_taxonomy'])
                        : (isset($item['taxonomy']) ? sanitize_key($item['taxonomy']) : '');
                    if ($taxonomy === '' || ! taxonomy_exists($taxonomy)) {
                        continue;
                    }

                    $local_term_id = self::resolve_local_term_id($item, $taxonomy);
                    if (! $local_term_id) {
                        continue;
                    }

                    $term_uid = isset($item['vf_object_uid']) ? (string) $item['vf_object_uid'] : '';
                    self::capture_term_snapshot($proposal_id, $local_term_id, $taxonomy, $term_uid);
                    continue;
                }

                if ($item_type !== 'post') {
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
            if (! $path || ! file_exists($path) || ! is_readable($path)) {
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
         * Return safe file metadata without exposing the snapshot path.
         */
        public static function get_snapshot_metadata(string $proposal_id, string $vf_object_uid): array
        {
            $path = self::get_snapshot_file_path($proposal_id, $vf_object_uid);
            if (! $path || ! file_exists($path)) {
                return [
                    'exists'             => false,
                    'readable'           => false,
                    'captured_at'        => null,
                    'captured_timestamp' => null,
                ];
            }

            $modified = filemtime($path);

            return [
                'exists'             => true,
                'readable'           => is_readable($path),
                'captured_at'        => $modified ? gmdate('c', $modified) : null,
                'captured_timestamp' => $modified ?: null,
            ];
        }

        /**
         * Internal: capture snapshot for a single post ID.
         */
        public static function capture_post_snapshot(string $proposal_id, int $post_id, string $vf_object_uid = ''): void
        {
            self::capture_post_snapshot_result($proposal_id, $post_id, $vf_object_uid);
        }

        /**
         * Capture a post snapshot and report the exact outcome.
         *
         * The legacy void method above remains unchanged for existing callers.
         *
         * @return array|\WP_Error
         */
        public static function capture_post_snapshot_result(string $proposal_id, int $post_id, string $vf_object_uid = '')
        {
            $post = get_post($post_id);
            if (! $post instanceof \WP_Post) {
                self::delete_snapshot($proposal_id, $vf_object_uid !== '' ? $vf_object_uid : (string) $post_id);
                return new \WP_Error('dbvc_snapshot_post_missing', __('The local post is not available for snapshot capture.', 'dbvc'));
            }

            $payload = self::build_post_payload($post);
            if (is_wp_error($payload)) {
                return $payload;
            }
            if (empty($payload)) {
                return new \WP_Error('dbvc_snapshot_empty_payload', __('The local post produced an empty snapshot payload.', 'dbvc'));
            }

            $key = $vf_object_uid !== '' ? $vf_object_uid : (string) $post_id;
            $file_path = self::get_snapshot_file_path($proposal_id, $key, true);
            if (! $file_path) {
                return new \WP_Error('dbvc_snapshot_path_failed', __('The snapshot file path could not be created.', 'dbvc'));
            }
            $dir = dirname($file_path);
            if (! is_dir($dir)) {
                wp_mkdir_p($dir);
                self::ensure_directory_security($dir);
            }
            if (! is_dir($dir) || ! is_writable($dir)) {
                return new \WP_Error('dbvc_snapshot_directory_failed', __('The snapshot directory is not writable.', 'dbvc'));
            }

            $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return new \WP_Error('dbvc_snapshot_encode_failed', __('The post snapshot could not be encoded.', 'dbvc'));
            }

            if (file_put_contents($file_path, $json) === false) {
                return new \WP_Error('dbvc_snapshot_write_failed', __('The post snapshot could not be written.', 'dbvc'));
            }

            return [
                'snapshot' => $payload,
                'metadata' => self::get_snapshot_metadata($proposal_id, $key),
            ];
        }

        /**
         * Internal: capture snapshot for a taxonomy term.
         */
        public static function capture_term_snapshot(string $proposal_id, int $term_id, string $taxonomy, string $vf_object_uid = ''): void
        {
            self::capture_term_snapshot_result($proposal_id, $term_id, $taxonomy, $vf_object_uid);
        }

        /**
         * Capture a term snapshot and report the exact outcome.
         *
         * @return array|\WP_Error
         */
        public static function capture_term_snapshot_result(string $proposal_id, int $term_id, string $taxonomy, string $vf_object_uid = '')
        {
            $term = get_term($term_id, $taxonomy);
            if (! $term || is_wp_error($term)) {
                if ($vf_object_uid !== '' || $term_id) {
                    self::delete_snapshot($proposal_id, $vf_object_uid !== '' ? $vf_object_uid : (string) $term_id);
                }
                return new \WP_Error('dbvc_snapshot_term_missing', __('The local term is not available for snapshot capture.', 'dbvc'));
            }

            $payload = self::build_term_payload($term);
            if (empty($payload)) {
                return new \WP_Error('dbvc_snapshot_empty_payload', __('The local term produced an empty snapshot payload.', 'dbvc'));
            }

            $key = $vf_object_uid !== '' ? $vf_object_uid : (string) $term->term_id;
            $file_path = self::get_snapshot_file_path($proposal_id, $key, true);
            if (! $file_path) {
                return new \WP_Error('dbvc_snapshot_path_failed', __('The snapshot file path could not be created.', 'dbvc'));
            }

            $dir = dirname($file_path);
            if (! is_dir($dir)) {
                wp_mkdir_p($dir);
                self::ensure_directory_security($dir);
            }
            if (! is_dir($dir) || ! is_writable($dir)) {
                return new \WP_Error('dbvc_snapshot_directory_failed', __('The snapshot directory is not writable.', 'dbvc'));
            }

            $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return new \WP_Error('dbvc_snapshot_encode_failed', __('The term snapshot could not be encoded.', 'dbvc'));
            }

            if (file_put_contents($file_path, $json) === false) {
                return new \WP_Error('dbvc_snapshot_write_failed', __('The term snapshot could not be written.', 'dbvc'));
            }

            return [
                'snapshot' => $payload,
                'metadata' => self::get_snapshot_metadata($proposal_id, $key),
            ];
        }

        /**
         * Compare a stored post snapshot with the current local post payload.
         */
        public static function inspect_post_snapshot(string $proposal_id, int $post_id, string $vf_object_uid = ''): array
        {
            $key = $vf_object_uid !== '' ? $vf_object_uid : (string) $post_id;
            $post = get_post($post_id);
            $current = $post instanceof \WP_Post
                ? self::build_post_payload($post, false)
                : new \WP_Error('dbvc_snapshot_post_missing', __('The local post is no longer available.', 'dbvc'));

            return self::inspect_snapshot_payload($proposal_id, $key, $current);
        }

        /**
         * Compare a stored term snapshot with the current local term payload.
         */
        public static function inspect_term_snapshot(string $proposal_id, int $term_id, string $taxonomy, string $vf_object_uid = ''): array
        {
            $key = $vf_object_uid !== '' ? $vf_object_uid : (string) $term_id;
            $term = get_term($term_id, $taxonomy);
            $current = ($term && ! is_wp_error($term))
                ? self::build_term_payload($term, false)
                : new \WP_Error('dbvc_snapshot_term_missing', __('The local term is no longer available.', 'dbvc'));

            return self::inspect_snapshot_payload($proposal_id, $key, $current);
        }

        /**
         * Build export-like payload for a term.
         */
        private static function build_term_payload(\WP_Term $term, bool $ensure_identity = true): array
        {
            $term_id = (int) $term->term_id;
            if (! $term_id) {
                return [];
            }

            $taxonomy = sanitize_key($term->taxonomy);
            $slug     = sanitize_title($term->slug);
            $name     = sanitize_text_field($term->name);
            $payload  = [
                'term_id'   => $term_id,
                'taxonomy'  => $taxonomy,
                'slug'      => $slug,
                'name'      => $name,
                'description' => wp_kses_post($term->description),
            ];

            if ($ensure_identity && class_exists('DBVC_Sync_Taxonomies')) {
                $payload['vf_object_uid'] = DBVC_Sync_Taxonomies::ensure_term_uid($term_id, $taxonomy);
            } else {
                $uid = get_term_meta($term_id, 'vf_object_uid', true);
                if (is_string($uid) && $uid !== '') {
                    $payload['vf_object_uid'] = $uid;
                }
            }

            $include_parent = get_option('dbvc_tax_export_parent_slugs', '1') === '1';
            if ($include_parent) {
                $payload['parent'] = (int) $term->parent;
                if ($term->parent) {
                    $parent = get_term($term->parent, $taxonomy);
                    if ($parent && ! is_wp_error($parent)) {
                        $payload['parent_slug'] = sanitize_title($parent->slug);
                        if ($ensure_identity && class_exists('DBVC_Sync_Taxonomies')) {
                            $payload['parent_uid'] = DBVC_Sync_Taxonomies::ensure_term_uid($parent->term_id, $taxonomy);
                        } else {
                            $parent_uid = get_term_meta($parent->term_id, 'vf_object_uid', true);
                            if (is_string($parent_uid) && $parent_uid !== '') {
                                $payload['parent_uid'] = $parent_uid;
                            }
                        }
                    }
                }
            }

            $include_meta = get_option('dbvc_tax_export_meta', '1') === '1';
            if ($include_meta) {
                $meta = get_term_meta($term_id);
                $payload['meta'] = self::normalize_term_meta_payload($meta);
            }

            if (get_option('dbvc_export_sort_meta', '0') === '1'
                && isset($payload['meta'])
                && is_array($payload['meta'])
                && function_exists('dbvc_sort_array_recursive')
            ) {
                $payload['meta'] = dbvc_sort_array_recursive($payload['meta']);
            }

            if (! empty($payload['vf_object_uid'])) {
                $payload['entity_refs'] = self::build_term_entity_references($payload);
            }

            if (function_exists('dbvc_normalize_for_json')) {
                $payload = dbvc_normalize_for_json($payload);
            }

            return $payload;
        }

        /**
         * Build export-like payload for the current post state.
         */
        private static function build_post_payload(\WP_Post $post, bool $ensure_identity = true)
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

            $entity_uid = $ensure_identity
                ? DBVC_Sync_Posts::ensure_post_uid($post_id, $post)
                : get_post_meta($post_id, 'vf_object_uid', true);
            $entity_uid = is_string($entity_uid) ? $entity_uid : '';

            $data = [
                'ID'           => $post_id,
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

        private static function normalize_term_meta_payload($meta): array
        {
            if (function_exists('dbvc_sanitize_post_meta_safe')) {
                $meta = dbvc_sanitize_post_meta_safe($meta);
            } elseif (is_array($meta)) {
                $meta = array_map(static function ($values) {
                    if (! is_array($values)) {
                        $values = [$values];
                    }
                    foreach ($values as $index => $value) {
                        if (is_string($value) && is_serialized($value)) {
                            $values[$index] = maybe_unserialize($value);
                        }
                    }
                    return $values;
                }, $meta);
            }

            return is_array($meta) ? $meta : [];
        }

        /**
         * Compare one stored snapshot with a freshly built local payload.
         *
         * @param array|\WP_Error $current
         */
        private static function inspect_snapshot_payload(string $proposal_id, string $key, $current): array
        {
            $metadata = self::get_snapshot_metadata($proposal_id, $key);
            if (empty($metadata['exists'])) {
                return array_merge($metadata, [
                    'valid'   => false,
                    'stale'   => false,
                    'message' => __('No snapshot has been captured.', 'dbvc'),
                ]);
            }

            $snapshot = self::read_snapshot($proposal_id, $key);
            if (! is_array($snapshot) || empty($snapshot)) {
                return array_merge($metadata, [
                    'valid'   => false,
                    'stale'   => false,
                    'message' => __('The stored snapshot is unreadable or invalid.', 'dbvc'),
                ]);
            }

            if (is_wp_error($current)) {
                return array_merge($metadata, [
                    'valid'   => false,
                    'stale'   => false,
                    'message' => $current->get_error_message(),
                ]);
            }
            if (! is_array($current) || empty($current)) {
                return array_merge($metadata, [
                    'valid'   => false,
                    'stale'   => false,
                    'message' => __('The current local entity could not be inspected.', 'dbvc'),
                ]);
            }

            $stale = ! hash_equals(self::hash_snapshot_payload($snapshot), self::hash_snapshot_payload($current));

            return array_merge($metadata, [
                'valid'   => true,
                'stale'   => $stale,
                'message' => $stale
                    ? __('The local entity changed after this snapshot was captured.', 'dbvc')
                    : __('The snapshot matches the current local entity.', 'dbvc'),
            ]);
        }

        private static function hash_snapshot_payload(array $payload): string
        {
            $normalize = static function ($value) use (&$normalize) {
                if (! is_array($value)) {
                    return $value;
                }
                foreach ($value as $key => $item) {
                    $value[$key] = $normalize($item);
                }
                ksort($value, SORT_STRING);
                return $value;
            };

            return hash('sha256', (string) wp_json_encode($normalize($payload), JSON_UNESCAPED_SLASHES));
        }

        private static function build_term_entity_references(array $payload): array
        {
            $refs     = [];
            $taxonomy = isset($payload['taxonomy']) ? sanitize_key($payload['taxonomy']) : '';
            $slug     = isset($payload['slug']) ? sanitize_title($payload['slug']) : '';
            $term_id  = isset($payload['term_id']) ? (int) $payload['term_id'] : 0;

            $append = static function ($type, $value, $path) use (&$refs) {
                if ($value === '' || $path === '') {
                    return;
                }
                foreach ($refs as $entry) {
                    if ($entry['path'] === $path) {
                        return;
                    }
                }
                $refs[] = [
                    'type'  => $type,
                    'value' => $value,
                    'path'  => $path,
                ];
            };

            $uid = isset($payload['vf_object_uid']) ? (string) $payload['vf_object_uid'] : '';
            if ($uid !== '') {
                $append('uid', $uid, self::format_entity_reference_path('term', 'uid', $uid));
            }
            if ($taxonomy !== '' && $slug !== '') {
                $append('taxonomy_slug', $taxonomy . '/' . $slug, self::format_entity_reference_path('term', $taxonomy, $slug));
            }
            if ($taxonomy !== '' && $term_id > 0) {
                $append('taxonomy_id', $taxonomy . '/' . $term_id, self::format_entity_reference_path('term', $taxonomy, (string) $term_id));
            }

            return $refs;
        }

        private static function resolve_local_term_id(array $item, string $taxonomy): ?int
        {
            $vf_object_uid = isset($item['vf_object_uid']) ? (string) $item['vf_object_uid'] : '';
            $term_id       = isset($item['term_id']) ? (int) $item['term_id'] : 0;
            $slug          = isset($item['term_slug'])
                ? sanitize_title($item['term_slug'])
                : (isset($item['slug']) ? sanitize_title($item['slug']) : '');

            if ($vf_object_uid !== '' && class_exists('DBVC_Sync_Posts') && method_exists('DBVC_Sync_Posts', 'find_term_id_by_uid')) {
                $found = DBVC_Sync_Posts::find_term_id_by_uid($vf_object_uid, $taxonomy);
                if ($found) {
                    return (int) $found;
                }
            }

            if ($vf_object_uid !== '' && class_exists('DBVC_Sync_Posts') && ! DBVC_Sync_Posts::is_uid_fallback_matching_allowed()) {
                return null;
            }

            if ($term_id) {
                $candidate = get_term($term_id, $taxonomy);
                if ($candidate && ! is_wp_error($candidate)) {
                    return (int) $candidate->term_id;
                }
            }

            if ($slug !== '' && taxonomy_exists($taxonomy)) {
                $candidate = get_term_by('slug', $slug, $taxonomy);
                if ($candidate && ! is_wp_error($candidate)) {
                    return (int) $candidate->term_id;
                }
            }

            $references = isset($item['entity_refs']) && is_array($item['entity_refs']) ? $item['entity_refs'] : [];
            foreach ($references as $reference) {
                if (! is_array($reference)) {
                    continue;
                }
                $type  = isset($reference['type']) ? (string) $reference['type'] : '';
                $value = isset($reference['value']) ? (string) $reference['value'] : '';
                if ($type === '' || $value === '') {
                    continue;
                }

                if ($type === 'taxonomy_slug') {
                    [$ref_taxonomy, $ref_slug] = self::parse_reference_value($value);
                    $ref_taxonomy = sanitize_key($ref_taxonomy ?: $taxonomy);
                    $ref_slug     = sanitize_title($ref_slug);
                    if ($ref_taxonomy === '' || $ref_slug === '' || ! taxonomy_exists($ref_taxonomy)) {
                        continue;
                    }
                    $candidate = get_term_by('slug', $ref_slug, $ref_taxonomy);
                    if ($candidate && ! is_wp_error($candidate)) {
                        return (int) $candidate->term_id;
                    }
                } elseif ($type === 'taxonomy_id') {
                    [$ref_taxonomy, $ref_id] = self::parse_reference_value($value);
                    $ref_taxonomy = sanitize_key($ref_taxonomy ?: $taxonomy);
                    $ref_id       = (int) $ref_id;
                    if ($ref_taxonomy === '' || $ref_id <= 0 || ! taxonomy_exists($ref_taxonomy)) {
                        continue;
                    }
                    $candidate = get_term($ref_id, $ref_taxonomy);
                    if ($candidate && ! is_wp_error($candidate)) {
                        return (int) $candidate->term_id;
                    }
                }
            }

            return null;
        }

        private static function format_entity_reference_path(string $entity_group, string $first_segment, ?string $second_segment = null): string
        {
            $segments = ['entities', trim($entity_group ?: 'term'), trim($first_segment)];
            if ($second_segment !== null && $second_segment !== '') {
                $segments[] = trim((string) $second_segment);
            }

            $segments = array_map(static function ($segment) {
                return rawurlencode((string) $segment);
            }, $segments);

            return implode('/', $segments);
        }

        private static function parse_reference_value(string $value): array
        {
            $parts = explode('/', (string) $value, 2);
            if (count($parts) === 2) {
                return [trim($parts[0]), trim($parts[1])];
            }
            return ['', trim($parts[0])];
        }

        private static function get_snapshot_file_path(
            string $proposal_id,
            string $vf_object_uid,
            bool $create_base = false
        ): string
        {
            $dir  = trailingslashit(self::get_base_path($create_base)) . sanitize_file_name($proposal_id);
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
