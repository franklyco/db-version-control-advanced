<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Artifacts
{
    /**
     * Registry for Bricks artifact types.
     *
     * @return array<string, array<string, string>>
     */
    public static function get_registry()
    {
        return [
            'bricks_template' => [
                'artifact_type' => 'bricks_template',
                'storage'       => 'entity',
                'identity'      => 'vf_object_uid',
                'default_policy' => 'REQUIRE_MANUAL_ACCEPT',
                'include_mode'  => 'include',
            ],
            'bricks_components' => self::option_registry_item('bricks_components', 'REQUEST_REVIEW'),
            'bricks_global_classes' => self::option_registry_item('bricks_global_classes', 'REQUEST_REVIEW'),
            'bricks_color_palette' => self::option_registry_item('bricks_color_palette', 'REQUEST_REVIEW'),
            'bricks_typography' => self::option_registry_item('bricks_typography', 'REQUEST_REVIEW'),
            'bricks_theme_styles' => self::option_registry_item('bricks_theme_styles', 'REQUEST_REVIEW'),
            'bricks_element_defaults' => self::option_registry_item('bricks_element_defaults', 'REQUEST_REVIEW'),
            'bricks_breakpoints' => self::option_registry_item('bricks_breakpoints', 'REQUIRE_MANUAL_ACCEPT'),
            'bricks_custom_fonts' => self::option_registry_item('bricks_custom_fonts', 'REQUEST_REVIEW'),
            'bricks_icon_fonts' => self::option_registry_item('bricks_icon_fonts', 'REQUEST_REVIEW'),
            'bricks_custom_css' => self::option_registry_item('bricks_custom_css', 'REQUEST_REVIEW'),
            'bricks_custom_scripts_header' => self::option_registry_item('bricks_custom_scripts_header', 'REQUIRE_MANUAL_ACCEPT'),
            'bricks_custom_scripts_footer' => self::option_registry_item('bricks_custom_scripts_footer', 'REQUIRE_MANUAL_ACCEPT'),
            'bricks_global_settings' => self::option_registry_item('bricks_global_settings', 'REQUEST_REVIEW'),
            'bricks_global_classes_locked' => self::option_registry_item('bricks_global_classes_locked', 'REQUEST_REVIEW'),
        ];
    }

    /**
     * Option keys excluded from packages.
     *
     * @return array<int, string>
     */
    public static function get_excluded_option_keys()
    {
        return [
            'bricks_license_key',
            'bricks_license_status',
            'bricks_remote_templates',
        ];
    }

    /**
     * Canonicalize payload by artifact type.
     *
     * @param string $artifact_type
     * @param mixed $payload
     * @return mixed
     */
    public static function canonicalize($artifact_type, $payload)
    {
        $artifact_type = (string) $artifact_type;
        if ($artifact_type === 'bricks_template') {
            return self::canonicalize_entity_payload(is_array($payload) ? $payload : []);
        }

        return self::canonicalize_option_payload($artifact_type, $payload);
    }

    /**
     * Canonicalize Entity payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function canonicalize_entity_payload(array $payload)
    {
        $volatile_fields = [
            'post_date',
            'post_date_gmt',
            'post_modified',
            'post_modified_gmt',
        ];
        foreach ($volatile_fields as $field) {
            unset($payload[$field]);
        }

        if (isset($payload['meta']) && is_array($payload['meta'])) {
            unset($payload['meta']['_edit_lock'], $payload['meta']['_edit_last']);
        }

        return self::sort_recursive($payload);
    }

    /**
     * Canonicalize option payload.
     *
     * @param string $artifact_type
     * @param mixed $payload
     * @return mixed
     */
    public static function canonicalize_option_payload($artifact_type, $payload)
    {
        $canonical = self::strip_volatile_values($payload);
        $canonical = self::sort_recursive($canonical);

        if (in_array($artifact_type, ['bricks_custom_css', 'bricks_custom_scripts_header', 'bricks_custom_scripts_footer'], true)) {
            if (is_string($canonical)) {
                return self::normalize_script_text($canonical);
            }
            if (is_array($canonical)) {
                array_walk_recursive($canonical, static function (&$value) {
                    if (is_string($value)) {
                        $value = self::normalize_script_text($value);
                    }
                });
            }
        }

        return $canonical;
    }

    /**
     * Build deterministic fingerprint for canonical payload.
     *
     * @param mixed $canonical_payload
     * @return string
     */
    public static function fingerprint($canonical_payload)
    {
        $encoded = wp_json_encode($canonical_payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (! is_string($encoded)) {
            $encoded = '';
        }
        return 'sha256:' . hash('sha256', $encoded);
    }

    /**
     * Compare hashes and return diagnostics.
     *
     * @param string $expected_hash
     * @param string $actual_hash
     * @return array<string, mixed>
     */
    public static function hash_diagnostics($expected_hash, $actual_hash)
    {
        $match = hash_equals((string) $expected_hash, (string) $actual_hash);
        return [
            'match' => $match,
            'expected' => (string) $expected_hash,
            'actual' => (string) $actual_hash,
            'message' => $match ? 'hash_match' : 'hash_mismatch',
        ];
    }

    /**
     * Build fixtures for each registered artifact type.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function build_fixtures()
    {
        $fixtures = [];
        foreach (self::get_registry() as $artifact_type => $registry_item) {
            if ($registry_item['storage'] === 'entity') {
                $payload = [
                    'post_type' => 'bricks_template',
                    'post_title' => 'Header Entity',
                    'post_status' => 'publish',
                    'post_date' => '2026-02-14 12:00:00',
                    'post_modified' => '2026-02-14 12:10:00',
                    'meta' => [
                        '_edit_lock' => '1234',
                        '_edit_last' => '1',
                        '_bricks_page_content_2' => [
                            ['id' => 'a1', 'name' => 'Heading', 'settings' => ['label' => 'Title']],
                        ],
                    ],
                ];
            } else {
                $payload = [
                    ['name' => 'zeta', 'id' => 'z2', 'updated_at' => '2026-02-14T12:00:00Z'],
                    ['id' => 'a1', 'name' => 'alpha', 'time' => 12345],
                ];
                if (in_array($artifact_type, ['bricks_custom_css', 'bricks_custom_scripts_header', 'bricks_custom_scripts_footer'], true)) {
                    $payload = "line-1\r\nline-2  \n";
                }
            }

            $canonical = self::canonicalize($artifact_type, $payload);
            $fixtures[$artifact_type] = [
                'artifact_type' => $artifact_type,
                'payload' => $payload,
                'canonical' => $canonical,
                'hash' => self::fingerprint($canonical),
            ];
        }

        return $fixtures;
    }

    /**
     * Validate fixture structure against schema assumptions.
     *
     * @param array<string, array<string, mixed>> $fixtures
     * @return array<string, array<int, string>>
     */
    public static function validate_fixtures(array $fixtures)
    {
        $errors = [];
        foreach ($fixtures as $artifact_type => $fixture) {
            $fixture_errors = [];
            if (($fixture['artifact_type'] ?? '') !== $artifact_type) {
                $fixture_errors[] = 'artifact_type mismatch';
            }
            if (! array_key_exists('canonical', $fixture)) {
                $fixture_errors[] = 'missing canonical payload';
            }
            if (! isset($fixture['hash']) || ! preg_match('/^sha256:[a-f0-9]{64}$/', (string) $fixture['hash'])) {
                $fixture_errors[] = 'invalid hash format';
            }

            if (! empty($fixture_errors)) {
                $errors[$artifact_type] = $fixture_errors;
            }
        }
        return $errors;
    }

    /**
     * @param string $option_key
     * @param string $default_policy
     * @return array<string, string>
     */
    private static function option_registry_item($option_key, $default_policy)
    {
        return [
            'artifact_type' => $option_key,
            'storage'       => 'option',
            'identity'      => 'option:' . $option_key,
            'default_policy' => $default_policy,
            'include_mode'  => in_array($option_key, self::get_excluded_option_keys(), true) ? 'exclude' : 'include',
        ];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function strip_volatile_values($value)
    {
        if (is_array($value)) {
            $clean = [];
            foreach ($value as $key => $child) {
                if (is_string($key) && in_array($key, ['time', 'timestamp', 'updated_at', 'modified_at', 'generated_at'], true)) {
                    continue;
                }
                $clean[$key] = self::strip_volatile_values($child);
            }
            return $clean;
        }

        return $value;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private static function sort_recursive($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $is_list = array_keys($value) === range(0, count($value) - 1);
        if (! $is_list) {
            ksort($value, SORT_STRING);
            foreach ($value as $key => $child) {
                $value[$key] = self::sort_recursive($child);
            }
            return $value;
        }

        foreach ($value as $index => $child) {
            $value[$index] = self::sort_recursive($child);
        }

        usort($value, static function ($left, $right) {
            $left_key = self::list_sort_key($left);
            $right_key = self::list_sort_key($right);
            return strcmp($left_key, $right_key);
        });

        return $value;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private static function list_sort_key($value)
    {
        if (is_array($value)) {
            $id = isset($value['id']) ? (string) $value['id'] : '';
            $name = isset($value['name']) ? (string) $value['name'] : '';
            $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            return $id . '|' . $name . '|' . (is_string($json) ? $json : '');
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        $json = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '';
    }

    /**
     * @param string $value
     * @return string
     */
    private static function normalize_script_text($value)
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $lines = explode("\n", $value);
        $lines = array_map('rtrim', $lines);
        return implode("\n", $lines);
    }
}
