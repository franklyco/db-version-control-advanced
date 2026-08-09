<?php
/**
 * Exact, allowlisted Bricks registry option export.
 *
 * This provider intentionally stays separate from the generic DBVC options
 * export. Bricks stores these registries as non-autoloaded options, so
 * wp_load_alloptions() cannot be used as their source of truth.
 */

if (! defined('ABSPATH')) {
    exit;
}

class DBVC_Bricks_Registry_Options_Provider
{
    const SCHEMA = 'dbvc-bricks-registry-options.v1';
    const FILENAME = 'bricks-registry-options.json';
    const OPTION_KEYS = [
        'bricks_global_classes_categories',
        'bricks_global_classes',
        'bricks_global_variables_categories',
        'bricks_global_variables',
    ];

    /**
     * Refresh the dedicated registry source after an authorized options export.
     *
     * @return void
     */
    public static function register()
    {
        add_action('dbvc_after_export_options', [__CLASS__, 'handle_options_export'], 10, 0);
    }

    /**
     * @return void
     */
    public static function handle_options_export()
    {
        $result = self::export();
        if (is_wp_error($result)) {
            error_log('DBVC Bricks registry export: ' . $result->get_error_message());
        }
    }

    /**
     * Export only the four allowlisted Bricks registry arrays.
     *
     * @param string|null $destination Optional test or alternate destination.
     * @return array|WP_Error
     */
    public static function export($destination = null)
    {
        if (! self::is_authorized()) {
            return new WP_Error(
                'dbvc_bricks_registry_export_forbidden',
                __('You are not allowed to export Bricks registry options.', 'dbvc')
            );
        }

        $options = [];
        foreach (self::OPTION_KEYS as $option_key) {
            $options[$option_key] = get_option($option_key, null);
        }

        $envelope = self::build_envelope($options);
        if (is_wp_error($envelope)) {
            return $envelope;
        }

        if ($destination === null || $destination === '') {
            $destination = trailingslashit(dbvc_get_sync_path()) . self::FILENAME;
        }

        $write_result = self::write_atomic($destination, $envelope);
        if (is_wp_error($write_result)) {
            return $write_result;
        }

        return [
            'written' => true,
            'path' => $destination,
            'payload_sha256' => $envelope['hashes']['payload_sha256'],
            'source_complete' => $envelope['validation']['source_complete'],
            'registry_integrity_ready' => $envelope['validation']['registry_integrity_ready'],
            'counts' => $envelope['counts'],
            'validation' => $envelope['validation'],
        ];
    }

    /**
     * Build a deterministic diagnostic envelope without changing WordPress.
     *
     * @param array $options Exact option values keyed by OPTION_KEYS.
     * @return array|WP_Error
     */
    public static function build_envelope($options)
    {
        $allowlisted = [];
        $missing_or_invalid = [];
        $counts = [];
        $duplicate_ids = [];
        $records_missing_ids = [];
        $component_hashes = [];

        foreach (self::OPTION_KEYS as $option_key) {
            $value = array_key_exists($option_key, $options) ? $options[$option_key] : null;
            $allowlisted[$option_key] = $value;

            if (! is_array($value)) {
                $missing_or_invalid[] = $option_key;
                $counts[$option_key] = null;
                $duplicate_ids[$option_key] = [];
                $records_missing_ids[$option_key] = [];
                $component_hashes[$option_key] = null;
                continue;
            }

            $counts[$option_key] = count($value);
            $id_diagnostics = self::inspect_ids($value);
            $duplicate_ids[$option_key] = $id_diagnostics['duplicate_ids'];
            $records_missing_ids[$option_key] = $id_diagnostics['records_missing_ids'];
            $component_hashes[$option_key] = self::hash_value($value);
        }

        $class_category_ids = self::record_ids($allowlisted['bricks_global_classes_categories']);
        $variable_category_ids = self::record_ids($allowlisted['bricks_global_variables_categories']);
        $class_category_references = self::category_references($allowlisted['bricks_global_classes']);
        $variable_category_references = self::category_references($allowlisted['bricks_global_variables']);

        $orphan_class_category_ids = array_values(array_diff($class_category_references, $class_category_ids));
        $orphan_variable_category_ids = array_values(array_diff($variable_category_references, $variable_category_ids));
        sort($orphan_class_category_ids, SORT_STRING);
        sort($orphan_variable_category_ids, SORT_STRING);

        $has_duplicate_ids = self::has_nonempty_diagnostics($duplicate_ids);
        $has_missing_ids = self::has_nonempty_diagnostics($records_missing_ids);
        $source_complete = $missing_or_invalid === [] && ! $has_duplicate_ids && ! $has_missing_ids;
        $registry_integrity_ready = $source_complete
            && $orphan_class_category_ids === []
            && $orphan_variable_category_ids === [];

        $payload_json = self::encode_for_hash($allowlisted);
        if (! is_string($payload_json)) {
            return new WP_Error(
                'dbvc_bricks_registry_encode_failed',
                __('Failed to encode the exact Bricks registry option payload.', 'dbvc')
            );
        }

        return [
            'schema' => self::SCHEMA,
            'generated_at' => gmdate(DATE_ATOM),
            'source' => [
                'provider' => 'dbvc_exact_allowlisted_bricks_registry_provider',
                'home_url' => function_exists('home_url') ? home_url('/') : null,
                'site_url' => function_exists('site_url') ? site_url('/') : null,
                'read_api' => 'get_option',
                'generic_options_export_modified' => false,
            ],
            'option_keys' => self::OPTION_KEYS,
            'counts' => $counts,
            'hashes' => [
                'algorithm' => 'sha256',
                'payload_sha256' => hash('sha256', $payload_json),
                'components' => $component_hashes,
            ],
            'validation' => [
                'source_complete' => $source_complete,
                'registry_integrity_ready' => $registry_integrity_ready,
                'missing_or_invalid_arrays' => $missing_or_invalid,
                'duplicate_ids' => $duplicate_ids,
                'records_missing_ids' => $records_missing_ids,
                'orphan_class_category_ids' => $orphan_class_category_ids,
                'orphan_variable_category_ids' => $orphan_variable_category_ids,
            ],
            'scope' => [
                'credentials_included' => false,
                'unrelated_options_included' => false,
                'writes_wordpress' => false,
                'writes_bricks' => false,
                'writes_generic_options_export' => false,
            ],
            'options' => $allowlisted,
        ];
    }

    /**
     * @param array $records
     * @return array
     */
    private static function inspect_ids($records)
    {
        $seen = [];
        $duplicates = [];
        $missing = [];

        foreach ($records as $index => $record) {
            $id = is_array($record) && isset($record['id']) ? trim((string) $record['id']) : '';
            if ($id === '') {
                $missing[] = (int) $index;
                continue;
            }
            if (isset($seen[$id])) {
                $duplicates[$id] = true;
            }
            $seen[$id] = true;
        }

        $duplicate_ids = array_keys($duplicates);
        sort($duplicate_ids, SORT_STRING);

        return [
            'duplicate_ids' => $duplicate_ids,
            'records_missing_ids' => $missing,
        ];
    }

    /**
     * @param mixed $records
     * @return array
     */
    private static function record_ids($records)
    {
        if (! is_array($records)) {
            return [];
        }

        $ids = [];
        foreach ($records as $record) {
            $id = is_array($record) && isset($record['id']) ? trim((string) $record['id']) : '';
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        $result = array_keys($ids);
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param mixed $records
     * @return array
     */
    private static function category_references($records)
    {
        if (! is_array($records)) {
            return [];
        }

        $ids = [];
        foreach ($records as $record) {
            if (! is_array($record) || ! isset($record['category'])) {
                continue;
            }

            $references = is_array($record['category']) ? $record['category'] : [$record['category']];
            foreach ($references as $reference) {
                $id = trim((string) $reference);
                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
        }

        $result = array_keys($ids);
        sort($result, SORT_STRING);
        return $result;
    }

    /**
     * @param array $diagnostics
     * @return bool
     */
    private static function has_nonempty_diagnostics($diagnostics)
    {
        foreach ($diagnostics as $items) {
            if (is_array($items) && $items !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function hash_value($value)
    {
        return hash('sha256', (string) self::encode_for_hash($value));
    }

    /**
     * Keep hashes portable when LocalWP and shell PHP use different
     * serialize_precision settings.
     *
     * @param mixed $value
     * @return string|false
     */
    private static function encode_for_hash($value)
    {
        $precision_before = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES);
        if ($precision_before !== false) {
            ini_set('serialize_precision', (string) $precision_before);
        }
        return $encoded;
    }

    /**
     * @param string $destination
     * @param array  $envelope
     * @return true|WP_Error
     */
    private static function write_atomic($destination, $envelope)
    {
        if (! function_exists('dbvc_is_safe_file_path') || ! dbvc_is_safe_file_path($destination)) {
            return new WP_Error(
                'dbvc_bricks_registry_unsafe_path',
                __('The Bricks registry export path is not inside the DBVC sync boundary.', 'dbvc')
            );
        }

        $directory = dirname($destination);
        if (! is_dir($directory) && ! wp_mkdir_p($directory)) {
            return new WP_Error(
                'dbvc_bricks_registry_directory_failed',
                __('Failed to create the Bricks registry export directory.', 'dbvc')
            );
        }

        if (class_exists('DBVC_Sync_Posts') && method_exists('DBVC_Sync_Posts', 'ensure_directory_security')) {
            DBVC_Sync_Posts::ensure_directory_security($directory);
        }

        $json = wp_json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($json)) {
            return new WP_Error(
                'dbvc_bricks_registry_envelope_encode_failed',
                __('Failed to encode the Bricks registry source envelope.', 'dbvc')
            );
        }

        $temporary = $destination . '.tmp-' . wp_generate_uuid4();
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            return new WP_Error(
                'dbvc_bricks_registry_write_failed',
                __('Failed to write the temporary Bricks registry source file.', 'dbvc')
            );
        }

        if (! rename($temporary, $destination)) {
            @unlink($temporary);
            return new WP_Error(
                'dbvc_bricks_registry_replace_failed',
                __('Failed to atomically replace the Bricks registry source file.', 'dbvc')
            );
        }

        return true;
    }

    /**
     * @return bool
     */
    private static function is_authorized()
    {
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        return function_exists('current_user_can') && current_user_can('manage_options');
    }
}
