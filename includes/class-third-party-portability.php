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
        const OPTION_WSFORM_PRESERVE_IDS  = 'dbvc_third_party_portability_wsform_preserve_ids';
        const META_PORTABILITY_UID        = 'dbvc_portability_uid';
        const PROVIDER_WSFORM            = 'ws_form';
        const SYNC_DIR                   = 'third-party/ws-form';

        /**
         * Report for the most recent export_selected_entities() run.
         *
         * @var array{forms_considered: int, forms_written: int, forms_skipped: array, settings_written: bool}
         */
        private static $wsform_export_report = [
            'forms_considered' => 0,
            'forms_written'    => 0,
            'forms_skipped'    => [],
            'settings_written' => false,
        ];

        public static function get_settings(): array
        {
            return [
                'wsform_forms'         => get_option(self::OPTION_WSFORM_FORMS, '0') === '1' ? '1' : '0',
                'wsform_settings'      => get_option(self::OPTION_WSFORM_SETTINGS, '0') === '1' ? '1' : '0',
                'wsform_include_trash' => get_option(self::OPTION_WSFORM_INCLUDE_TRASH, '0') === '1' ? '1' : '0',
                'wsform_preserve_ids'  => get_option(self::OPTION_WSFORM_PRESERVE_IDS, '0') === '1' ? '1' : '0',
            ];
        }

        public static function save_settings_from_post(array $post): void
        {
            update_option(self::OPTION_WSFORM_FORMS, ! empty($post[self::OPTION_WSFORM_FORMS]) ? '1' : '0');
            update_option(self::OPTION_WSFORM_SETTINGS, ! empty($post[self::OPTION_WSFORM_SETTINGS]) ? '1' : '0');
            update_option(self::OPTION_WSFORM_INCLUDE_TRASH, ! empty($post[self::OPTION_WSFORM_INCLUDE_TRASH]) ? '1' : '0');
            update_option(self::OPTION_WSFORM_PRESERVE_IDS, ! empty($post[self::OPTION_WSFORM_PRESERVE_IDS]) ? '1' : '0');
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

            self::$wsform_export_report = self::empty_wsform_export_report();

            if ($settings['wsform_forms'] === '1') {
                $exported += self::export_wsform_forms($settings['wsform_include_trash'] === '1');
            }

            if ($settings['wsform_settings'] === '1') {
                $written = self::export_wsform_settings();
                self::$wsform_export_report['settings_written'] = $written;
                $exported += $written ? 1 : 0;
            }

            if (! empty(self::$wsform_export_report['forms_skipped']) && class_exists('DBVC_Sync_Logger')) {
                DBVC_Sync_Logger::log('WS Form export skipped forms', self::$wsform_export_report);
            }

            return $exported;
        }

        /**
         * What the most recent export_selected_entities() call actually produced.
         *
         * export_selected_entities() returns a file count for backward
         * compatibility, which cannot express a form that was asked for but could
         * not be written. Callers that need to tell the operator read this.
         *
         * @return array{forms_considered: int, forms_written: int, forms_skipped: array, settings_written: bool}
         */
        public static function get_last_wsform_export_report(): array
        {
            return self::$wsform_export_report;
        }

        /**
         * Shape of an empty export report.
         */
        private static function empty_wsform_export_report(): array
        {
            return [
                'forms_considered' => 0,
                'forms_written'    => 0,
                'forms_skipped'    => [],
                'settings_written' => false,
            ];
        }

        /**
         * Describe one skipped form, classifying why.
         *
         * A trashed form is called out separately: WS Form's db_read() refuses it,
         * so no amount of retrying will export it and the include-trash setting
         * cannot deliver what its label implies.
         *
         * @return array{form_id: int, label: string, status: string, reason: string, message: string}
         */
        public static function describe_wsform_export_skip(int $form_id, string $status, string $label, string $reason = 'unreadable'): array
        {
            $status = sanitize_key($status);
            $reason = sanitize_key($reason);

            if ($reason === 'unreadable' && $status === 'trash') {
                $reason = 'trash_unreadable';
            }

            $messages = [
                'trash_unreadable' => __('WS Form cannot read a trashed form, so it cannot be exported. Restore it first.', 'dbvc'),
                'unreadable'       => __('WS Form could not read this form.', 'dbvc'),
                'unsafe_path'      => __('The target filename resolved outside the sync folder.', 'dbvc'),
                'encode_failed'    => __('The form payload could not be encoded as JSON.', 'dbvc'),
                'write_failed'     => __('The form file could not be written.', 'dbvc'),
            ];

            return [
                'form_id' => absint($form_id),
                'label'   => sanitize_text_field($label),
                'status'  => $status,
                'reason'  => $reason,
                'message' => $messages[$reason] ?? $messages['unreadable'],
            ];
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

        public static function is_wsform_payload(array $payload): bool
        {
            $identifier = isset($payload['identifier']) ? (string) $payload['identifier'] : '';
            $provider = isset($payload['provider']) ? (string) $payload['provider'] : '';
            $dbvc_provider = isset($payload['dbvc_portability']['provider']) ? (string) $payload['dbvc_portability']['provider'] : '';

            return self::is_wsform_provider_value($identifier)
                || self::is_wsform_provider_value($provider)
                || self::is_wsform_provider_value($dbvc_provider);
        }

        public static function is_wsform_form_payload(array $payload): bool
        {
            $identifier = isset($payload['identifier']) ? (string) $payload['identifier'] : '';
            $provider = isset($payload['dbvc_portability']['provider'])
                ? (string) $payload['dbvc_portability']['provider']
                : (isset($payload['provider']) ? (string) $payload['provider'] : '');
            $object_type = isset($payload['dbvc_portability']['object_type'])
                ? self::normalize_wsform_payload_type((string) $payload['dbvc_portability']['object_type'])
                : (isset($payload['object_type']) ? self::normalize_wsform_payload_type((string) $payload['object_type']) : '');
            $export_object = isset($payload['meta']['export_object'])
                ? self::normalize_wsform_payload_type((string) $payload['meta']['export_object'])
                : '';

            if (self::is_wsform_provider_value($provider) && $object_type !== '') {
                return $object_type === 'form';
            }

            if (! self::is_wsform_provider_value($identifier)) {
                return false;
            }

            if ($export_object !== '') {
                return $export_object === 'form';
            }

            if ($object_type !== '') {
                return $object_type === 'form';
            }

            return self::looks_like_wsform_form_payload($payload);
        }

        public static function is_wsform_settings_payload(array $payload): bool
        {
            return isset($payload['entity_type'], $payload['provider'], $payload['object_type'])
                && $payload['entity_type'] === 'third_party'
                && self::is_wsform_provider_value((string) $payload['provider'])
                && self::normalize_wsform_payload_type((string) $payload['object_type']) === 'settings';
        }

        public static function get_wsform_payload_object_type(array $payload): string
        {
            $object_type = isset($payload['dbvc_portability']['object_type'])
                ? self::normalize_wsform_payload_type((string) $payload['dbvc_portability']['object_type'])
                : (isset($payload['object_type']) ? self::normalize_wsform_payload_type((string) $payload['object_type']) : '');
            if ($object_type !== '') {
                return $object_type;
            }

            $export_object = isset($payload['meta']['export_object'])
                ? self::normalize_wsform_payload_type((string) $payload['meta']['export_object'])
                : '';
            if ($export_object !== '') {
                return $export_object;
            }

            if (self::is_wsform_settings_payload($payload)) {
                return 'settings';
            }

            if (self::is_wsform_form_payload($payload)) {
                return 'form';
            }

            return self::is_wsform_payload($payload) ? 'unknown' : '';
        }

        private static function is_wsform_provider_value(string $value): bool
        {
            return self::normalize_wsform_payload_type($value) === self::PROVIDER_WSFORM;
        }

        private static function normalize_wsform_payload_type(string $value): string
        {
            $value = strtolower(trim($value));
            $value = str_replace(['-', ' '], '_', $value);

            return sanitize_key($value);
        }

        private static function looks_like_wsform_form_payload(array $payload): bool
        {
            $label = isset($payload['label']) ? trim((string) $payload['label']) : '';
            if ($label === '' || ! isset($payload['meta']) || ! is_array($payload['meta'])) {
                return false;
            }

            return (isset($payload['groups']) && is_array($payload['groups']))
                || isset($payload['version'])
                || isset($payload['status'])
                || isset($payload['checksum'])
                || isset($payload['count_submit']);
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

        public static function extract_wsform_form_uid(array $payload): string
        {
            return self::extract_wsform_uid($payload);
        }

        public static function summarize_wsform_form_payload(array $payload): array
        {
            return [
                'label'         => isset($payload['label']) ? sanitize_text_field((string) $payload['label']) : '',
                'uid'           => self::extract_wsform_uid($payload),
                'source_id'     => isset($payload['dbvc_portability']['source_id']) ? absint($payload['dbvc_portability']['source_id']) : absint($payload['id'] ?? 0),
                'source_status' => isset($payload['dbvc_portability']['source_status'])
                    ? sanitize_key((string) $payload['dbvc_portability']['source_status'])
                    : sanitize_key((string) ($payload['status'] ?? '')),
                'counts'        => self::count_wsform_payload_graph($payload),
            ];
        }

        public static function get_wsform_form_match_by_uid(string $uid): ?array
        {
            $uid = sanitize_text_field($uid);
            if ($uid === '' || ! self::is_wsform_available()) {
                return null;
            }

            $form_id = self::find_wsform_form_id_by_uid($uid);
            if ($form_id <= 0) {
                return null;
            }

            return self::format_wsform_form_entity($form_id, 'dbvc_portability_uid');
        }

        public static function import_wsform_form_for_entity_editor(array $payload)
        {
            return self::import_wsform_form($payload, true);
        }

        public static function export_wsform_form_snapshot_for_entity_editor(int $form_id)
        {
            $form_id = absint($form_id);
            if ($form_id <= 0) {
                return new WP_Error('dbvc_wsform_snapshot_invalid_form', __('Invalid WS Form ID for snapshot.', 'dbvc'));
            }

            $filtered = apply_filters('dbvc_third_party_wsform_form_snapshot_payload', null, $form_id);
            if (is_wp_error($filtered)) {
                return $filtered;
            }
            if (is_array($filtered)) {
                return function_exists('dbvc_normalize_for_json') ? dbvc_normalize_for_json($filtered) : $filtered;
            }

            if (! self::is_wsform_available()) {
                return new WP_Error('dbvc_wsform_unavailable', __('WS Form is not available on this site.', 'dbvc'));
            }

            $payload = self::build_wsform_form_export_payload($form_id);
            if (! is_array($payload)) {
                return new WP_Error('dbvc_wsform_snapshot_failed', __('Unable to capture the current WS Form before update.', 'dbvc'));
            }

            return $payload;
        }

        public static function import_wsform_settings_for_entity_editor(array $payload)
        {
            $result = self::import_wsform_settings($payload);
            if (! $result) {
                return new WP_Error('dbvc_wsform_settings_import_skipped', __('No WS Form settings were imported.', 'dbvc'));
            }

            return [
                'status'      => 'applied',
                'provider'    => self::PROVIDER_WSFORM,
                'object_type' => 'settings',
                'merged'      => true,
                'option_names' => array_values(array_intersect(
                    array_map('strval', array_keys((array) ($payload['options'] ?? []))),
                    self::wsform_setting_option_names()
                )),
            ];
        }

        public static function get_wsform_setting_option_names(): array
        {
            return self::wsform_setting_option_names();
        }

        /**
         * Portable uid for a form, minted on first read.
         *
         * Shares the identity used by the form export, so a post-meta sidecar
         * and the form payload always agree on the same uid.
         *
         * Returns '' for a form ID that does not exist locally. Minting there
         * would write an orphan meta row and, worse, claim that uid against an
         * ID a later form could occupy, producing a false identity match. Post
         * meta carrying a foreign site's form ID reaches this method routinely:
         * the auto-export on post status transition runs before an import has
         * remapped the reference.
         */
        public static function get_wsform_form_uid(int $form_id): string
        {
            $form_id = absint($form_id);
            if ($form_id <= 0 || ! self::is_wsform_available()) {
                return '';
            }

            if (! self::wsform_form_exists($form_id)) {
                return '';
            }

            return self::ensure_wsform_form_uid($form_id);
        }

        /**
         * Whether a WS Form form row exists locally.
         */
        public static function wsform_form_exists(int $form_id): bool
        {
            $form_id = absint($form_id);
            if ($form_id <= 0 || ! self::is_wsform_available()) {
                return false;
            }

            global $wpdb;
            $form_table = self::wsform_table('form');

            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$form_table} WHERE id = %d LIMIT 1",
                $form_id
            )) === $form_id;
        }

        /**
         * Resolve a portable uid to the local form ID, or 0 when absent.
         */
        public static function find_wsform_form_id_for_uid(string $uid): int
        {
            $uid = sanitize_text_field($uid);
            if ($uid === '' || ! self::is_wsform_available()) {
                return 0;
            }

            return self::find_wsform_form_id_by_uid($uid);
        }

        /**
         * Describe a form by local ID (label, status, edit URL).
         */
        public static function get_wsform_form_entity(int $form_id): array
        {
            return self::format_wsform_form_entity(absint($form_id), 'id');
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

            $statuses = self::get_wsform_form_statuses($form_ids);

            $exported = 0;
            foreach ($form_ids as $form_id) {
                $status = (string) ($statuses[$form_id]['status'] ?? '');
                $label  = (string) ($statuses[$form_id]['label'] ?? '');

                $payload = self::build_wsform_form_export_payload($form_id);
                if (! is_array($payload)) {
                    // WS Form's db_read() refuses a trashed form outright, so an
                    // enabled include-trash setting cannot actually produce one.
                    // Record it rather than dropping it silently.
                    self::record_wsform_export_skip($form_id, $status, $label, 'unreadable');
                    continue;
                }

                $filename = self::determine_wsform_form_filename_from_payload($payload);
                $path = trailingslashit($forms_dir) . $filename;
                if (! dbvc_is_safe_file_path($path)) {
                    self::record_wsform_export_skip($form_id, $status, $label, 'unsafe_path');
                    continue;
                }

                $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if ($json === false) {
                    self::record_wsform_export_skip($form_id, $status, $label, 'encode_failed');
                    continue;
                }

                if (file_put_contents($path, $json) === false) {
                    self::record_wsform_export_skip($form_id, $status, $label, 'write_failed');
                    continue;
                }

                $exported++;
            }

            self::$wsform_export_report['forms_written'] = $exported;
            self::$wsform_export_report['forms_considered'] = count($form_ids);

            return $exported;
        }

        /**
         * Status and label for each form ID, for skip reporting.
         *
         * @param int[] $form_ids
         * @return array<int, array{status: string, label: string}>
         */
        private static function get_wsform_form_statuses(array $form_ids): array
        {
            global $wpdb;

            $ids = array_values(array_filter(array_map('absint', $form_ids)));
            if (empty($ids)) {
                return [];
            }

            $form_table = self::wsform_table('form');
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, status, label FROM {$form_table} WHERE id IN ({$placeholders})",
                ...$ids
            ), ARRAY_A);

            $out = [];
            foreach ((array) $rows as $row) {
                $out[(int) $row['id']] = [
                    'status' => (string) ($row['status'] ?? ''),
                    'label'  => (string) ($row['label'] ?? ''),
                ];
            }

            return $out;
        }

        /**
         * Add one skipped form to the current export report.
         */
        private static function record_wsform_export_skip(int $form_id, string $status, string $label, string $reason): void
        {
            self::$wsform_export_report['forms_skipped'][] = self::describe_wsform_export_skip($form_id, $status, $label, $reason);
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

        /**
         * Whether a uid-matched form import keeps the payload's object IDs.
         *
         * Renumbering stays the default. Preserving IDs matters because WS Form
         * submission meta keys are `field_<id>` and `#field(id)` references are
         * stored by ID, so renumbering an updated form orphans both.
         */
        public static function wsform_preserve_ids_enabled(): bool
        {
            $enabled = get_option(self::OPTION_WSFORM_PRESERVE_IDS, '0') === '1';

            return (bool) apply_filters('dbvc_third_party_wsform_preserve_ids', $enabled);
        }

        /**
         * Object IDs carried by an import payload or a read form object.
         *
         * Sections nest, so this walks `sections` recursively rather than assuming
         * one level under each group.
         *
         * @param mixed $tree Decoded payload or WS Form object.
         * @return array{group: int[], section: int[], field: int[]}
         */
        public static function collect_wsform_object_ids($tree): array
        {
            $ids = ['group' => [], 'section' => [], 'field' => []];

            $collect_sections = static function ($sections) use (&$ids, &$collect_sections): void {
                foreach ((array) $sections as $section) {
                    $section = (array) $section;
                    if (isset($section['id'])) {
                        $ids['section'][] = absint($section['id']);
                    }
                    foreach ((array) ($section['fields'] ?? []) as $field) {
                        $field = (array) $field;
                        if (isset($field['id'])) {
                            $ids['field'][] = absint($field['id']);
                        }
                    }
                    if (! empty($section['sections'])) {
                        $collect_sections($section['sections']);
                    }
                }
            };

            $tree = (array) $tree;
            foreach ((array) ($tree['groups'] ?? []) as $group) {
                $group = (array) $group;
                if (isset($group['id'])) {
                    $ids['group'][] = absint($group['id']);
                }
                $collect_sections($group['sections'] ?? []);
            }

            foreach ($ids as $level => $values) {
                $values = array_values(array_unique(array_filter($values, static function ($v) {
                    return $v > 0;
                })));
                sort($values);
                $ids[$level] = $values;
            }

            return $ids;
        }

        /**
         * Compute colliding object IDs: present in the table but owned by another form.
         *
         * Deliberately set arithmetic rather than a join. A nested section can carry
         * `group_id = 0` and be reachable only through its parent chain, so a
         * field -> section -> group -> form join misses nested subtrees and would
         * under-report ownership.
         *
         * @param array $incoming IDs in the payload, keyed by level.
         * @param array $existing IDs that exist in each table, keyed by level.
         * @param array $owned    IDs owned by the target form, keyed by level.
         * @return array<string, int[]> Colliding IDs per level; empty when safe.
         */
        public static function compute_wsform_id_collisions(array $incoming, array $existing, array $owned): array
        {
            $collisions = [];

            foreach (['group', 'section', 'field'] as $level) {
                $in    = array_map('absint', (array) ($incoming[$level] ?? []));
                $table = array_map('absint', (array) ($existing[$level] ?? []));
                $mine  = array_map('absint', (array) ($owned[$level] ?? []));

                $hit = array_values(array_diff(array_intersect($in, $table), $mine));
                sort($hit);
                if (! empty($hit)) {
                    $collisions[$level] = $hit;
                }
            }

            return $collisions;
        }

        /**
         * Render a collision report for an error message.
         */
        public static function describe_wsform_id_collisions(array $collisions): string
        {
            $parts = [];
            foreach ($collisions as $level => $ids) {
                $parts[] = $level . ' ' . implode(', ', array_map('absint', (array) $ids));
            }

            return implode('; ', $parts);
        }

        /**
         * Detect collisions for an ID-preserving import into $form_id.
         *
         * @param object $form_object Prepared import object.
         * @param int    $form_id     Target (uid-matched) form.
         * @return array<string, int[]>
         */
        private static function detect_wsform_id_collisions($form_object, int $form_id): array
        {
            $incoming = self::collect_wsform_object_ids(json_decode(wp_json_encode($form_object), true));

            $owned = ['group' => [], 'section' => [], 'field' => []];
            $target = new WS_Form_Form();
            $target->id = $form_id;
            try {
                $read = $target->db_read(true, true, false, false, true);
                if (is_object($read)) {
                    $owned = self::collect_wsform_object_ids(json_decode(wp_json_encode($read), true));
                }
            } catch (Throwable $e) {
                // An unreadable target owns nothing we can prove; the guard then
                // treats every existing ID as foreign, which fails closed.
                if (class_exists('DBVC_Sync_Logger')) {
                    DBVC_Sync_Logger::log('WS Form collision guard could not read target form', [
                        'form_id' => $form_id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            return self::compute_wsform_id_collisions($incoming, self::wsform_existing_object_ids($incoming), $owned);
        }

        /**
         * Which of the given IDs already exist in each WS Form child table.
         *
         * @param array $ids_by_level
         * @return array{group: int[], section: int[], field: int[]}
         */
        private static function wsform_existing_object_ids(array $ids_by_level): array
        {
            global $wpdb;
            $existing = ['group' => [], 'section' => [], 'field' => []];

            foreach (['group', 'section', 'field'] as $level) {
                $ids = array_values(array_filter(array_map('absint', (array) ($ids_by_level[$level] ?? []))));
                if (empty($ids)) {
                    continue;
                }

                $table = self::wsform_table($level);
                $placeholders = implode(',', array_fill(0, count($ids), '%d'));
                $rows = $wpdb->get_col($wpdb->prepare(
                    "SELECT id FROM {$table} WHERE id IN ({$placeholders})",
                    ...$ids
                ));
                $existing[$level] = array_map('absint', (array) $rows);
            }

            return $existing;
        }

        /**
         * Whether every WS Form table this import writes is InnoDB.
         *
         * Transactions are pointless on MyISAM, so the caller degrades to
         * guard-only rather than claiming a rollback it cannot deliver.
         */
        private static function wsform_tables_are_innodb(): bool
        {
            global $wpdb;

            $tables = [];
            foreach (['form', 'form_meta', 'group', 'group_meta', 'section', 'section_meta', 'field', 'field_meta'] as $suffix) {
                $tables[] = self::wsform_table($suffix);
            }

            $placeholders = implode(',', array_fill(0, count($tables), '%s'));
            $engines = $wpdb->get_col($wpdb->prepare(
                "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$placeholders})",
                ...$tables
            ));

            if (empty($engines)) {
                return false;
            }

            foreach ($engines as $engine) {
                if (strtoupper((string) $engine) !== 'INNODB') {
                    return false;
                }
            }

            return true;
        }

        /**
         * Advance each table's AUTO_INCREMENT past the highest inserted ID.
         *
         * Explicit-ID inserts already advance the counter on InnoDB, so this guards
         * against engine and version differences. Failure is logged, never fatal.
         */
        private static function bump_wsform_auto_increment_watermarks(): void
        {
            global $wpdb;

            foreach (['group', 'group_meta', 'section', 'section_meta', 'field', 'field_meta'] as $suffix) {
                $table = self::wsform_table($suffix);
                $next = (int) $wpdb->get_var("SELECT IFNULL(MAX(id), 0) + 1 FROM {$table}");
                if ($next <= 1) {
                    continue;
                }

                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifier and integer are both derived locally.
                $result = $wpdb->query("ALTER TABLE {$table} AUTO_INCREMENT = {$next}");
                if ($result === false && class_exists('DBVC_Sync_Logger')) {
                    DBVC_Sync_Logger::log('WS Form AUTO_INCREMENT watermark bump failed', [
                        'table' => $table,
                        'next'  => $next,
                    ]);
                }
            }
        }

        private static function import_wsform_form(array $payload, bool $return_details = false)
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

            return self::with_wsform_caps(function () use ($form_object, $uid, $source_status, $return_details) {
                global $wpdb;

                $existing_id = self::find_wsform_form_id_by_uid($uid);

                // ID preservation applies only when updating a uid-matched form.
                // With no match the payload's own form ID would be adopted (WS Form
                // takes $form_object->id when $new is false), which on this site may
                // belong to an unrelated form. Cloning to a new identity is the
                // renumbering case, so fall back to it.
                $preserve = self::wsform_preserve_ids_enabled() && $existing_id > 0;
                $preserve_skipped = self::wsform_preserve_ids_enabled() && $existing_id <= 0;
                $use_transaction = false;
                $ws_form = new WS_Form_Form();

                try {
                    if ($existing_id > 0) {
                        $ws_form->id = $existing_id;
                    } else {
                        $ws_form->db_create(false);
                    }

                    if ($preserve) {
                        // Point the form row at the matched local form rather than the
                        // source site's ID. Child objects keep their payload IDs.
                        $form_object->id = $existing_id;

                        // Validate before mutating: db_import_reset() destroys the
                        // target's tree, so a collision found afterwards would leave
                        // no clean rollback point.
                        $collisions = self::detect_wsform_id_collisions($form_object, $existing_id);
                        if (! empty($collisions)) {
                            if (class_exists('DBVC_Sync_Logger')) {
                                DBVC_Sync_Logger::log('WS Form ID-preserving import blocked by collisions', [
                                    'uid'        => $uid,
                                    'form_id'    => $existing_id,
                                    'collisions' => $collisions,
                                ]);
                            }

                            return new WP_Error(
                                'dbvc_wsform_id_collision',
                                sprintf(
                                    /* translators: %s: comma separated list of colliding object IDs. */
                                    __('WS Form ID-preserving import refused: these IDs belong to other forms on this site: %s', 'dbvc'),
                                    self::describe_wsform_id_collisions($collisions)
                                ),
                                [
                                    'status'     => 409,
                                    'form_id'    => $existing_id,
                                    'collisions' => $collisions,
                                ]
                            );
                        }

                        $use_transaction = self::wsform_tables_are_innodb();
                        if ($use_transaction) {
                            $wpdb->query('START TRANSACTION');
                        }
                    }

                    $ws_form->db_import_reset();

                    /**
                     * Fires after the target form's tree has been torn down and
                     * before it is rebuilt from the payload.
                     *
                     * This is the point of no return: the form's groups, sections
                     * and fields are gone and have not been replaced yet. Throwing
                     * from this hook aborts the import, which rolls back when a
                     * transaction is active (ID preservation on InnoDB) and
                     * otherwise leaves the form torn down. Intended for
                     * fault-injection in tests and for callers that need to
                     * observe the mid-import boundary; do not mutate the form here.
                     *
                     * @param int    $form_id  Form being imported into.
                     * @param bool   $preserve Whether IDs are being preserved.
                     * @param string $uid      Portability uid of the payload.
                     */
                    do_action('dbvc_third_party_wsform_import_after_reset', (int) $ws_form->id, $preserve, $uid);

                    $ws_form->db_update_from_object($form_object, true, ! $preserve, true);
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

                    if ($use_transaction) {
                        $wpdb->query('COMMIT');
                        $use_transaction = false;
                    }

                    if ($preserve) {
                        // DDL is not transactional, so this runs after COMMIT and a
                        // failure is logged rather than fatal.
                        self::bump_wsform_auto_increment_watermarks();
                    }
                } catch (Throwable $e) {
                    if ($use_transaction) {
                        $wpdb->query('ROLLBACK');
                    }

                    if (class_exists('DBVC_Sync_Logger')) {
                        DBVC_Sync_Logger::log('WS Form import failed', [
                            'uid'           => $uid,
                            'source_status' => $source_status,
                            'existing_id'   => $existing_id,
                            'form_id'       => isset($ws_form->id) ? (int) $ws_form->id : 0,
                            'preserve_ids'  => $preserve,
                            'rolled_back'   => $use_transaction,
                            'error_class'   => get_class($e),
                            'error'         => $e->getMessage(),
                        ]);
                    }

                    return new WP_Error(
                        'dbvc_wsform_import_failed',
                        sprintf(__('WS Form import failed: %s', 'dbvc'), $e->getMessage()),
                        [
                            'status'      => 500,
                            'error_class' => get_class($e),
                        ]
                    );
                }

                if (! $return_details) {
                    return true;
                }

                $form_id = (int) $ws_form->id;
                $created = $existing_id <= 0;

                return [
                    'status'        => 'applied',
                    'provider'      => self::PROVIDER_WSFORM,
                    'object_type'   => 'form',
                    'uid'           => $uid,
                    'form_id'       => $form_id,
                    'action'        => $created ? 'create_form' : 'update_matched_form',
                    'created'       => $created,
                    'updated'       => ! $created,
                    'source_status' => $source_status,
                    'wp_entity'     => self::format_wsform_form_entity($form_id, 'dbvc_portability_uid'),
                ];
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

        private static function count_wsform_payload_graph(array $payload): array
        {
            $groups = isset($payload['groups']) && is_array($payload['groups']) ? $payload['groups'] : [];
            $group_count = 0;
            $section_count = 0;
            $field_count = 0;

            foreach ($groups as $group) {
                if (! is_array($group)) {
                    continue;
                }

                $group_count++;
                $sections = isset($group['sections']) && is_array($group['sections']) ? $group['sections'] : [];
                foreach ($sections as $section) {
                    if (! is_array($section)) {
                        continue;
                    }

                    $section_count++;
                    $fields = isset($section['fields']) && is_array($section['fields']) ? $section['fields'] : [];
                    foreach ($fields as $field) {
                        if (is_array($field)) {
                            $field_count++;
                        }
                    }
                }
            }

            $actions = [];
            if (isset($payload['meta']['action']) && is_array($payload['meta']['action'])) {
                $actions = $payload['meta']['action'];
            } elseif (isset($payload['meta']['actions']) && is_array($payload['meta']['actions'])) {
                $actions = $payload['meta']['actions'];
            }

            return [
                'groups'   => $group_count,
                'sections' => $section_count,
                'fields'   => $field_count,
                'actions'  => count($actions),
            ];
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

        private static function format_wsform_form_entity(int $form_id, string $match_source = ''): array
        {
            $entity = [
                'id'           => $form_id,
                'kind'         => 'third_party',
                'provider'     => self::PROVIDER_WSFORM,
                'object_type'  => 'form',
                'label'        => sprintf(__('WS Form #%d', 'dbvc'), $form_id),
                'status'       => '',
                'edit_url'     => admin_url('admin.php?page=ws-form-edit&id=' . $form_id),
                'match_source' => $match_source,
            ];

            if (! self::is_wsform_available()) {
                return $entity;
            }

            global $wpdb;
            $form_table = self::wsform_table('form');
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT label, status FROM {$form_table} WHERE id = %d LIMIT 1",
                $form_id
            ), ARRAY_A);

            if (is_array($row)) {
                if (isset($row['label']) && (string) $row['label'] !== '') {
                    $entity['label'] = (string) $row['label'];
                }
                if (isset($row['status'])) {
                    $entity['status'] = (string) $row['status'];
                }
            }

            return $entity;
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
