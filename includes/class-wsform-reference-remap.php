<?php

/**
 * Portable WS Form references carried in post meta.
 *
 * Fields such as the Vertical framework's `custom_wsform` store a raw WS Form
 * form ID. Form IDs are per-site autoincrement values, so the raw ID is
 * meaningless on any other install. Rather than rewriting the meta value into a
 * token — which would render literally through ACF on any site that does not
 * detokenize it — we leave the raw ID in place and export an additive sidecar
 * recording the form's portable uid. On import the sidecar is resolved back to
 * the local form ID; when it cannot be resolved the raw ID survives untouched.
 *
 * @package DB Version Control
 */

if (! defined('WPINC')) {
    die;
}

if (! class_exists('DBVC_WSForm_Reference_Remap')) {
    class DBVC_WSForm_Reference_Remap
    {
        const OPTION_ENABLED = 'dbvc_wsform_reference_remap';
        const PAYLOAD_KEY    = 'dbvc_wsform_refs';

        /**
         * Whether the sidecar is written on export and consumed on import.
         *
         * Defaults to on: the sidecar is additive, so anything that ignores it
         * behaves exactly as before.
         */
        public static function is_enabled(): bool
        {
            return get_option(self::OPTION_ENABLED, '1') === '1';
        }

        /**
         * Persist the toggle from an admin form submission.
         */
        public static function save_settings_from_post(array $post): void
        {
            update_option(self::OPTION_ENABLED, ! empty($post[self::OPTION_ENABLED]) ? '1' : '0');
        }

        /**
         * Meta keys whose values hold a WS Form form ID.
         *
         * ACF stores a companion `_`-prefixed key holding the field key rather
         * than a value; those are filtered out and can never be candidates.
         *
         * @return string[]
         */
        public static function get_reference_meta_keys(): array
        {
            $keys = apply_filters('dbvc_wsform_reference_meta_keys', [
                'custom_wsform',
                'core_allpostypes_controls_objects_custom_wsform',
            ]);

            $clean = [];
            foreach ((array) $keys as $key) {
                $key = (string) $key;
                if ($key === '' || strpos($key, '_') === 0) {
                    continue;
                }
                $clean[$key] = true;
            }

            return array_keys($clean);
        }

        /**
         * Build the export sidecar for a post's meta payload.
         *
         * Reads the already-filtered export meta, so a reference removed by
         * masking produces no sidecar entry.
         *
         * @param array $meta Export-shaped meta (key => array of values).
         * @return array<int, array<string, mixed>>
         */
        public static function build_export_refs(array $meta): array
        {
            if (! self::is_enabled() || ! self::provider_available()) {
                return [];
            }

            $refs = [];
            foreach (self::get_reference_meta_keys() as $meta_key) {
                if (! array_key_exists($meta_key, $meta)) {
                    continue;
                }

                $values = is_array($meta[$meta_key]) ? $meta[$meta_key] : [$meta[$meta_key]];
                foreach ($values as $index => $value) {
                    foreach (self::extract_form_ids($value) as $path => $form_id) {
                        $uid = self::lookup_uid($form_id);
                        if ($uid === '') {
                            continue;
                        }

                        $entity = self::lookup_entity($form_id);
                        $refs[] = [
                            'meta_key'       => $meta_key,
                            'index'          => (int) $index,
                            'path'           => $path === '' ? null : (string) $path,
                            'source_form_id' => $form_id,
                            'uid'            => $uid,
                            'label'          => (string) ($entity['label'] ?? ''),
                            'status'         => (string) ($entity['status'] ?? ''),
                        ];
                    }
                }
            }

            return $refs;
        }

        /**
         * Resolve exported references against local forms and rewrite the meta.
         *
         * @param int   $post_id Target post.
         * @param array $refs    Sidecar entries from the payload.
         * @param array $meta    Payload meta, updated in place so the rewritten
         *                       sync file matches the local database.
         * @return array{remapped: array, unresolved: array, unchanged: array}
         */
        public static function apply_import_refs(int $post_id, array $refs, array &$meta): array
        {
            $report = ['remapped' => [], 'unresolved' => [], 'unchanged' => []];

            $post_id = absint($post_id);
            if ($post_id <= 0 || empty($refs) || ! self::is_enabled()) {
                return $report;
            }

            if (! self::provider_available()) {
                return $report;
            }

            $allowed = array_fill_keys(self::get_reference_meta_keys(), true);

            // Collapse to one source_form_id => local_form_id map per meta key.
            $plans = [];
            foreach ($refs as $ref) {
                if (! is_array($ref)) {
                    continue;
                }

                $meta_key = isset($ref['meta_key']) ? (string) $ref['meta_key'] : '';
                $uid      = isset($ref['uid']) ? sanitize_text_field((string) $ref['uid']) : '';
                $source   = isset($ref['source_form_id']) ? absint($ref['source_form_id']) : 0;

                if ($meta_key === '' || $uid === '' || $source <= 0 || ! isset($allowed[$meta_key])) {
                    continue;
                }

                $local = self::resolve_uid($uid);
                if ($local <= 0) {
                    $report['unresolved'][] = [
                        'meta_key'       => $meta_key,
                        'uid'            => $uid,
                        'source_form_id' => $source,
                        'label'          => isset($ref['label']) ? (string) $ref['label'] : '',
                    ];
                    continue;
                }

                if ($local === $source) {
                    $report['unchanged'][] = ['meta_key' => $meta_key, 'form_id' => $local];
                    continue;
                }

                $plans[$meta_key][$source] = $local;
            }

            foreach ($plans as $meta_key => $map) {
                $stored = get_post_meta($post_id, $meta_key, false);
                if (! is_array($stored) || empty($stored)) {
                    continue;
                }

                $rewritten = [];
                $changed   = false;
                foreach ($stored as $value) {
                    $new = self::replace_form_ids($value, $map);
                    if ($new !== $value) {
                        $changed = true;
                    }
                    $rewritten[] = $new;
                }

                if (! $changed) {
                    continue;
                }

                if (count($rewritten) === 1) {
                    update_post_meta($post_id, $meta_key, $rewritten[0]);
                } else {
                    delete_post_meta($post_id, $meta_key);
                    foreach ($rewritten as $value) {
                        add_post_meta($post_id, $meta_key, $value);
                    }
                }

                // Keep the rewritten sync file consistent with local state, the
                // same way vf_object_uid and dbvc_post_history already are.
                if (isset($meta[$meta_key])) {
                    $meta[$meta_key] = $rewritten;
                }

                foreach ($map as $source => $local) {
                    $report['remapped'][] = [
                        'meta_key'       => $meta_key,
                        'source_form_id' => (int) $source,
                        'local_form_id'  => (int) $local,
                    ];
                }
            }

            return $report;
        }

        /**
         * Whether a WS Form provider is present to resolve references against.
         *
         * Filterable so another forms provider — or a test — can supply the
         * identity lookups below without WS Form installed.
         */
        private static function provider_available(): bool
        {
            $override = apply_filters('dbvc_wsform_reference_provider_available', null);
            if (is_bool($override)) {
                return $override;
            }

            return class_exists('DBVC_Third_Party_Portability')
                && DBVC_Third_Party_Portability::is_wsform_available();
        }

        /**
         * Portable uid for a local form ID.
         */
        private static function lookup_uid(int $form_id): string
        {
            $uid = apply_filters('dbvc_wsform_reference_form_uid', null, $form_id);
            if (is_string($uid)) {
                return sanitize_text_field($uid);
            }

            return class_exists('DBVC_Third_Party_Portability')
                ? DBVC_Third_Party_Portability::get_wsform_form_uid($form_id)
                : '';
        }

        /**
         * Descriptive fields (label, status) for a local form ID.
         */
        private static function lookup_entity(int $form_id): array
        {
            $entity = apply_filters('dbvc_wsform_reference_form_entity', null, $form_id);
            if (is_array($entity)) {
                return $entity;
            }

            return class_exists('DBVC_Third_Party_Portability')
                ? DBVC_Third_Party_Portability::get_wsform_form_entity($form_id)
                : [];
        }

        /**
         * Local form ID for a portable uid, or 0 when it is not present here.
         */
        private static function resolve_uid(string $uid): int
        {
            $resolved = apply_filters('dbvc_wsform_reference_resolve_uid', null, $uid);
            if (is_int($resolved) || (is_string($resolved) && preg_match('/^[0-9]+$/', $resolved))) {
                return absint($resolved);
            }

            return class_exists('DBVC_Third_Party_Portability')
                ? DBVC_Third_Party_Portability::find_wsform_form_id_for_uid($uid)
                : 0;
        }

        /**
         * Pull positive integer form IDs out of a meta value.
         *
         * Returns a path => id map; the path is '' for a scalar and the array
         * key for a flat list, so multi-select references stay addressable.
         *
         * @param mixed $value
         * @return array<string, int>
         */
        private static function extract_form_ids($value): array
        {
            $found = [];

            if (is_array($value)) {
                foreach ($value as $key => $item) {
                    if (is_array($item)) {
                        continue;
                    }
                    $id = self::to_form_id($item);
                    if ($id > 0) {
                        $found[(string) $key] = $id;
                    }
                }

                return $found;
            }

            $id = self::to_form_id($value);
            if ($id > 0) {
                $found[''] = $id;
            }

            return $found;
        }

        /**
         * Apply a source => local map to a meta value, preserving its shape.
         *
         * String inputs come back as strings so an untouched export round-trips
         * byte-for-byte.
         *
         * @param mixed $value
         * @param array $map
         * @return mixed
         */
        private static function replace_form_ids($value, array $map)
        {
            if (is_array($value)) {
                $out = [];
                foreach ($value as $key => $item) {
                    $out[$key] = is_array($item) ? $item : self::replace_form_ids($item, $map);
                }

                return $out;
            }

            $id = self::to_form_id($value);
            if ($id <= 0 || ! isset($map[$id])) {
                return $value;
            }

            return is_string($value) ? (string) $map[$id] : (int) $map[$id];
        }

        /**
         * Strictly interpret a meta value as a form ID.
         *
         * Rejects empty strings, floats and ids-with-suffixes so that only an
         * unambiguous integer reference is ever rewritten.
         */
        private static function to_form_id($value): int
        {
            if (is_int($value)) {
                return $value > 0 ? $value : 0;
            }

            if (! is_string($value)) {
                return 0;
            }

            $trimmed = trim($value);
            if ($trimmed === '' || ! preg_match('/^[1-9][0-9]*$/', $trimmed)) {
                return 0;
            }

            return (int) $trimmed;
        }
    }
}
