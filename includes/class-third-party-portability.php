<?php

/**
 * Third-party plugin entity portability support.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_Third_Party_Portability')) {
    class DBVC_Third_Party_Portability
    {
        const OPTION_WSFORM_FORMS         = 'dbvc_third_party_portability_wsform_forms';
        const OPTION_WSFORM_SETTINGS      = 'dbvc_third_party_portability_wsform_settings';
        const OPTION_WSFORM_INCLUDE_TRASH = 'dbvc_third_party_portability_wsform_include_trash';
        const META_PORTABILITY_UID        = 'dbvc_portability_uid';
        const PROVIDER_WSFORM            = 'ws_form';
        const SYNC_DIR                   = 'third-party/ws-form';

        public static function get_settings(): array
        {
            return [
                'wsform_forms'         => get_option(self::OPTION_WSFORM_FORMS, '0') === '1' ? '1' : '0',
                'wsform_settings'      => get_option(self::OPTION_WSFORM_SETTINGS, '0') === '1' ? '1' : '0',
                'wsform_include_trash' => get_option(self::OPTION_WSFORM_INCLUDE_TRASH, '0') === '1' ? '1' : '0',
            ];
        }

        public static function save_settings_from_post(array $post): void
        {
            update_option(self::OPTION_WSFORM_FORMS, ! empty($post[self::OPTION_WSFORM_FORMS]) ? '1' : '0');
            update_option(self::OPTION_WSFORM_SETTINGS, ! empty($post[self::OPTION_WSFORM_SETTINGS]) ? '1' : '0');
            update_option(self::OPTION_WSFORM_INCLUDE_TRASH, ! empty($post[self::OPTION_WSFORM_INCLUDE_TRASH]) ? '1' : '0');
        }

        public static function get_wsform_summary(): array
        {
            $summary = [
                'available'      => self::is_wsform_available(),
                'forms'          => 0,
                'forms_in_trash' => 0,
                'settings_found' => [],
                'version'        => defined('WS_FORM_VERSION') ? (string) WS_FORM_VERSION : '',
            ];

            if (! $summary['available']) {
                return $summary;
            }

            global $wpdb;
            $form_table = self::wsform_table('form');
            $summary['forms'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$form_table} WHERE status <> 'trash'");
            $summary['forms_in_trash'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$form_table} WHERE status = 'trash'");

            foreach (self::wsform_setting_option_names() as $option_name) {
                if (get_option($option_name, null) !== null) {
                    $summary['settings_found'][] = $option_name;
                }
            }

            return $summary;
        }

        public static function is_wsform_available(): bool
        {
            return defined('WS_FORM_DB_TABLE_PREFIX')
                && defined('WS_FORM_VERSION')
                && class_exists('WS_Form_Form')
                && class_exists('WS_Form_Common');
        }

        public static function export_selected_entities(): int
        {
            $settings = self::get_settings();
            $exported = 0;

            if ($settings['wsform_forms'] === '1') {
                $exported += self::export_wsform_forms($settings['wsform_include_trash'] === '1');
            }

            if ($settings['wsform_settings'] === '1') {
                $exported += self::export_wsform_settings() ? 1 : 0;
            }

            return $exported;
        }

        public static function import_selected_entities_from_sync(): array
        {
            $settings = self::get_settings();
            $stats = [
                'processed' => 0,
                'imported'  => 0,
                'skipped'   => 0,
                'errors'    => [],
            ];

            if ($settings['wsform_forms'] !== '1' && $settings['wsform_settings'] !== '1') {
                return $stats;
            }

            $base_dir = trailingslashit(dbvc_get_sync_path()) . self::SYNC_DIR;
            if (! is_dir($base_dir)) {
                return $stats;
            }

            if ($settings['wsform_forms'] === '1') {
                $form_files = glob(trailingslashit($base_dir) . 'forms/*.json');
                foreach ((array) $form_files as $path) {
                    $stats['processed']++;
                    $result = self::import_entity_file($path);
                    if (is_wp_error($result)) {
                        $stats['errors'][] = $result->get_error_message();
                    } elseif ($result) {
                        $stats['imported']++;
                    } else {
                        $stats['skipped']++;
                    }
                }
            }

            if ($settings['wsform_settings'] === '1') {
                $settings_path = trailingslashit($base_dir) . 'settings.json';
                if (is_readable($settings_path)) {
                    $stats['processed']++;
                    $result = self::import_entity_file($settings_path);
                    if (is_wp_error($result)) {
                        $stats['errors'][] = $result->get_error_message();
                    } elseif ($result) {
                        $stats['imported']++;
                    } else {
                        $stats['skipped']++;
                    }
                }
            }

            if (class_exists('DBVC_Sync_Logger') && DBVC_Sync_Logger::is_import_logging_enabled()) {
                DBVC_Sync_Logger::log_import('Third-party portability import completed', $stats);
            }

            return $stats;
        }

        public static function import_entity_file(string $path, array $entry = [])
        {
            if (! is_readable($path)) {
                return new WP_Error('dbvc_third_party_unreadable', sprintf(__('Third-party payload is not readable: %s', 'dbvc'), $path));
            }

            $payload = json_decode(file_get_contents($path), true);
            if (! is_array($payload)) {
                return new WP_Error('dbvc_third_party_invalid_json', sprintf(__('Invalid third-party JSON payload: %s', 'dbvc'), $path));
            }

            if (self::is_wsform_settings_payload($payload)) {
                return self::import_wsform_settings($payload);
            }

            if (self::is_wsform_form_payload($payload)) {
                return self::import_wsform_form($payload);
            }

            return new WP_Error('dbvc_third_party_unknown_payload', sprintf(__('Unsupported third-party payload: %s', 'dbvc'), $entry['path'] ?? basename($path)));
        }

        public static function is_third_party_payload(array $payload): bool
        {
            return self::is_wsform_form_payload($payload) || self::is_wsform_settings_payload($payload);
        }

        public static function is_wsform_form_payload(array $payload): bool
        {
            $identifier = isset($payload['identifier']) ? (string) $payload['identifier'] : '';
            $provider = isset($payload['dbvc_portability']['provider']) ? (string) $payload['dbvc_portability']['provider'] : '';
            $object_type = isset($payload['dbvc_portability']['object_type']) ? (string) $payload['dbvc_portability']['object_type'] : '';
            $export_object = isset($payload['meta']['export_object']) ? (string) $payload['meta']['export_object'] : '';

            return ($identifier === self::PROVIDER_WSFORM && $export_object === 'form')
                || ($provider === self::PROVIDER_WSFORM && $object_type === 'form');
        }

        public static function is_wsform_settings_payload(array $payload): bool
        {
            return isset($payload['entity_type'], $payload['provider'], $payload['object_type'])
                && $payload['entity_type'] === 'third_party'
                && $payload['provider'] === self::PROVIDER_WSFORM
                && $payload['object_type'] === 'settings';
        }

        public static function determine_wsform_form_filename_from_payload(array $payload): string
        {
            $source_id = isset($payload['dbvc_portability']['source_id']) ? absint($payload['dbvc_portability']['source_id']) : 0;
            if (! $source_id && isset($payload['id'])) {
                $source_id = absint($payload['id']);
            }

            $label = isset($payload['label']) ? sanitize_title((string) $payload['label']) : '';
            $part = $source_id ? (string) $source_id : ($label !== '' ? $label : 'form');

            if ($source_id && $label !== '') {
                $part = $source_id . '-' . $label;
            }

            return sanitize_file_name('ws-form-' . $part . '.json');
        }

        private static function export_wsform_forms(bool $include_trash = false): int
        {
            if (! self::is_wsform_available()) {
                return 0;
            }

            $form_ids = self::get_wsform_form_ids($include_trash);
            if (empty($form_ids)) {
                return 0;
            }

            $forms_dir = trailingslashit(dbvc_get_sync_path()) . self::SYNC_DIR . '/forms';
            if (! self::ensure_directory($forms_dir)) {
                return 0;
            }

            $exported = 0;
            foreach ($form_ids as $form_id) {
                $payload = self::build_wsform_form_export_payload($form_id);
                if (! is_array($payload)) {
                    continue;
                }

                $filename = self::determine_wsform_form_filename_from_payload($payload);
                $path = trailingslashit($forms_dir) . $filename;
                if (! dbvc_is_safe_file_path($path)) {
                    continue;
                }

                $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    continue;
                }

                if (file_put_contents($path, $json) !== false) {
                    $exported++;
                }
            }

            return $exported;
        }

        private static function export_wsform_settings(): bool
        {
            if (! self::is_wsform_available()) {
                return false;
            }

            $settings_dir = trailingslashit(dbvc_get_sync_path()) . self::SYNC_DIR;
            if (! self::ensure_directory($settings_dir)) {
                return false;
            }

            $options = [];
            $excluded = [];
            foreach (self::wsform_setting_option_names() as $option_name) {
                $value = get_option($option_name, null);
                if ($value === null) {
                    continue;
                }

                $filtered = self::filter_sensitive_settings($value, $excluded_keys);
                $options[$option_name] = $filtered;
                if (! empty($excluded_keys)) {
                    $excluded[$option_name] = $excluded_keys;
                }
            }

            if (empty($options)) {
                return false;
            }

            $payload = [
                'schema'        => 1,
                'entity_type'   => 'third_party',
                'provider'      => self::PROVIDER_WSFORM,
                'object_type'   => 'settings',
                'source_plugin' => [
                    'version' => defined('WS_FORM_VERSION') ? (string) WS_FORM_VERSION : '',
                ],
                'exported_at'   => current_time('mysql', true),
                'options'       => dbvc_normalize_for_json($options),
                'excluded_keys' => $excluded,
            ];

            $path = trailingslashit($settings_dir) . 'settings.json';
            if (! dbvc_is_safe_file_path($path)) {
                return false;
            }

            $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                return false;
            }

            return file_put_contents($path, $json) !== false;
        }

        private static function build_wsform_form_export_payload(int $form_id): ?array
        {
            $uid = self::ensure_wsform_form_uid($form_id);
            if ($uid === '') {
                return null;
            }

            return self::with_wsform_caps(function () use ($form_id, $uid) {
                $ws_form = new WS_Form_Form();
                $ws_form->id = $form_id;
                try {
                    $form_object = $ws_form->db_read(true, true, false, false, true);
                } catch (Throwable $e) {
                    if (class_exists('DBVC_Sync_Logger')) {
                        DBVC_Sync_Logger::log('WS Form export skipped unreadable form', [
                            'form_id' => $form_id,
                            'error'   => $e->getMessage(),
                        ]);
                    }

                    return null;
                }

                if (! is_object($form_object)) {
                    return null;
                }

                $source_status = isset($form_object->status) ? (string) $form_object->status : '';
                unset($form_object->checksum, $form_object->published_checksum);
                $form_object->identifier = self::PROVIDER_WSFORM;
                $form_object->version = defined('WS_FORM_VERSION') ? (string) WS_FORM_VERSION : '';
                $form_object->time = time();
                $form_object->count_submit = 0;
                $form_object->count_submit_unread = 0;

                if (! isset($form_object->meta) || ! is_object($form_object->meta)) {
                    $form_object->meta = new stdClass();
                }
                $form_object->meta->export_object = 'form';
                $form_object->meta->{self::META_PORTABILITY_UID} = $uid;

                $form_object->dbvc_portability = (object) [
                    'schema'        => 1,
                    'provider'      => self::PROVIDER_WSFORM,
                    'object_type'   => 'form',
                    'uid'           => $uid,
                    'source_id'     => $form_id,
                    'source_status' => $source_status,
                    'exported_at'   => current_time('mysql', true),
                ];

                $form_object->checksum = md5(wp_json_encode($form_object));
                $payload = json_decode(wp_json_encode($form_object), true);
                return is_array($payload) ? dbvc_normalize_for_json($payload) : null;
            });
        }

        private static function import_wsform_form(array $payload)
        {
            if (! self::is_wsform_available()) {
                return new WP_Error('dbvc_wsform_unavailable', __('WS Form is not available on this site.', 'dbvc'));
            }

            $uid = self::extract_wsform_uid($payload);
            if ($uid === '') {
                $uid = wp_generate_uuid4();
            }

            $source_status = isset($payload['dbvc_portability']['source_status'])
                ? sanitize_key((string) $payload['dbvc_portability']['source_status'])
                : (isset($payload['status']) ? sanitize_key((string) $payload['status']) : 'draft');

            $form_object = self::prepare_wsform_form_import_object($payload, $uid);
            if (! is_object($form_object)) {
                return new WP_Error('dbvc_wsform_import_prepare_failed', __('Failed to prepare WS Form payload for import.', 'dbvc'));
            }

            return self::with_wsform_caps(function () use ($form_object, $uid, $source_status) {
                $existing_id = self::find_wsform_form_id_by_uid($uid);
                $ws_form = new WS_Form_Form();

                try {
                    if ($existing_id > 0) {
                        $ws_form->id = $existing_id;
                    } else {
                        $ws_form->db_create(false);
                    }

                    $ws_form->db_import_reset();
                    $ws_form->db_update_from_object($form_object, true, true, true);
                    $ws_form->db_conditional_repair();
                    $ws_form->db_action_repair();
                    $ws_form->db_meta_repair();
                    $ws_form->db_checksum();
                    if (method_exists($ws_form, 'db_style_resolve')) {
                        $ws_form->db_style_resolve();
                    }
                    self::write_wsform_form_meta((int) $ws_form->id, self::META_PORTABILITY_UID, $uid);
                    if ($source_status === 'publish') {
                        $ws_form->db_publish(true);
                    }
                } catch (Exception $e) {
                    return new WP_Error('dbvc_wsform_import_failed', $e->getMessage());
                }

                return true;
            });
        }

        private static function import_wsform_settings(array $payload)
        {
            if (empty($payload['options']) || ! is_array($payload['options'])) {
                return false;
            }

            foreach ($payload['options'] as $option_name => $option_value) {
                if (! in_array((string) $option_name, self::wsform_setting_option_names(), true)) {
                    continue;
                }

                $current = get_option($option_name, null);
                $merged = self::merge_preserving_sensitive_settings($current, $option_value);
                update_option((string) $option_name, $merged);
            }

            return true;
        }

        private static function prepare_wsform_form_import_object(array $payload, string $uid)
        {
            unset($payload['checksum'], $payload['published_checksum'], $payload['dbvc_portability']);
            $payload['count_submit'] = 0;
            $payload['count_submit_unread'] = 0;

            if (! isset($payload['meta']) || ! is_array($payload['meta'])) {
                $payload['meta'] = [];
            }
            $payload['meta']['export_object'] = 'form';
            $payload['meta'][self::META_PORTABILITY_UID] = $uid;

            return json_decode(wp_json_encode($payload));
        }

        private static function extract_wsform_uid(array $payload): string
        {
            $candidates = [
                $payload['dbvc_portability']['uid'] ?? '',
                $payload['meta'][self::META_PORTABILITY_UID] ?? '',
            ];

            foreach ($candidates as $candidate) {
                $candidate = sanitize_text_field((string) $candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }

            return '';
        }

        private static function get_wsform_form_ids(bool $include_trash): array
        {
            global $wpdb;
            $form_table = self::wsform_table('form');
            $where = $include_trash ? '' : " WHERE status <> 'trash'";
            $ids = $wpdb->get_col("SELECT id FROM {$form_table}{$where} ORDER BY id ASC");
            return array_map('absint', (array) $ids);
        }

        private static function wsform_setting_option_names(): array
        {
            return apply_filters('dbvc_third_party_wsform_setting_options', ['ws_form', 'ws_form_css']);
        }

        private static function filter_sensitive_settings($value, &$excluded_keys = [])
        {
            $excluded_keys = [];

            if (! is_array($value)) {
                return $value;
            }

            $filtered = [];
            foreach ($value as $key => $item) {
                $key_string = (string) $key;
                if (self::is_sensitive_setting_key($key_string)) {
                    $excluded_keys[] = $key_string;
                    continue;
                }

                if (is_array($item)) {
                    $filtered[$key] = self::filter_sensitive_settings($item, $child_excluded);
                    foreach ($child_excluded as $child_key) {
                        $excluded_keys[] = $key_string . '.' . $child_key;
                    }
                } else {
                    $filtered[$key] = $item;
                }
            }

            return $filtered;
        }

        private static function merge_preserving_sensitive_settings($current, $incoming)
        {
            if (! is_array($incoming)) {
                return $incoming;
            }

            $current = is_array($current) ? $current : [];
            $merged = $current;

            foreach ($incoming as $key => $value) {
                $key_string = (string) $key;
                if (self::is_sensitive_setting_key($key_string)) {
                    continue;
                }

                if (is_array($value)) {
                    $merged[$key] = self::merge_preserving_sensitive_settings($current[$key] ?? [], $value);
                } else {
                    $merged[$key] = $value;
                }
            }

            return $merged;
        }

        private static function is_sensitive_setting_key(string $key): bool
        {
            $patterns = [
                'license',
                'api_key',
                'secret',
                'token',
                'password',
                'private_key',
                'client_secret',
                'publishable_key',
            ];

            $key = strtolower($key);
            foreach ($patterns as $pattern) {
                if (strpos($key, $pattern) !== false) {
                    return true;
                }
            }

            return false;
        }

        private static function find_wsform_form_id_by_uid(string $uid): int
        {
            if ($uid === '') {
                return 0;
            }

            global $wpdb;
            $meta_table = self::wsform_table('form_meta');
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT parent_id FROM {$meta_table} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
                self::META_PORTABILITY_UID,
                $uid
            ));
        }

        private static function ensure_wsform_form_uid(int $form_id): string
        {
            $existing = self::read_wsform_form_meta($form_id, self::META_PORTABILITY_UID);
            if (is_string($existing) && trim($existing) !== '') {
                return sanitize_text_field($existing);
            }

            $uid = wp_generate_uuid4();
            return self::write_wsform_form_meta($form_id, self::META_PORTABILITY_UID, $uid) ? $uid : '';
        }

        private static function read_wsform_form_meta(int $form_id, string $key)
        {
            global $wpdb;
            $meta_table = self::wsform_table('form_meta');
            $value = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$meta_table} WHERE parent_id = %d AND meta_key = %s LIMIT 1",
                $form_id,
                $key
            ));

            return $value === null ? null : maybe_unserialize($value);
        }

        private static function write_wsform_form_meta(int $form_id, string $key, $value): bool
        {
            global $wpdb;
            $meta_table = self::wsform_table('form_meta');
            $serialized = maybe_serialize($value);
            $meta_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$meta_table} WHERE parent_id = %d AND meta_key = %s LIMIT 1",
                $form_id,
                $key
            ));

            if ($meta_id) {
                return $wpdb->update(
                    $meta_table,
                    ['meta_value' => $serialized],
                    ['id' => (int) $meta_id],
                    ['%s'],
                    ['%d']
                ) !== false;
            }

            return $wpdb->insert(
                $meta_table,
                [
                    'parent_id'  => $form_id,
                    'meta_key'   => $key,
                    'meta_value' => $serialized,
                ],
                ['%d', '%s', '%s']
            ) !== false;
        }

        private static function wsform_table(string $suffix): string
        {
            global $wpdb;
            return $wpdb->prefix . WS_FORM_DB_TABLE_PREFIX . $suffix;
        }

        private static function ensure_directory(string $dir): bool
        {
            if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
                return false;
            }

            if (class_exists('DBVC_Sync_Posts')) {
                DBVC_Sync_Posts::ensure_directory_security($dir);
            }

            return true;
        }

        private static function with_wsform_caps(callable $callback)
        {
            $grant = static function ($allcaps) {
                foreach (self::wsform_caps() as $cap) {
                    $allcaps[$cap] = true;
                }
                return $allcaps;
            };

            add_filter('user_has_cap', $grant, 10, 1);
            try {
                return $callback();
            } finally {
                remove_filter('user_has_cap', $grant, 10);
            }
        }

        private static function wsform_caps(): array
        {
            return [
                'create_form',
                'delete_form',
                'edit_form',
                'export_form',
                'import_form',
                'publish_form',
                'read_form',
                'read_forms',
                'edit_forms',
            ];
        }
    }
}
