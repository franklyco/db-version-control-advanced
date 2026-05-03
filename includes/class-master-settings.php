<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Master_Settings
{
    public const OPTION_SETTINGS = 'dbvc_master_settings';
    public const SETTINGS_VERSION = 1;
    private const TOOL_DOWNLOAD_SAMPLE_ENTITIES = 'download_sample_entities';

    /**
     * @return void
     */
    public static function ensure_defaults(): void
    {
        add_option(self::OPTION_SETTINGS, self::get_default_values(), '', false);
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_all_settings(): array
    {
        self::ensure_defaults();

        $stored = get_option(self::OPTION_SETTINGS, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        return self::sanitize_settings($stored);
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_download_sample_entities_settings(): array
    {
        $settings = self::get_all_settings();
        $tools = isset($settings['tools']) && is_array($settings['tools']) ? $settings['tools'] : [];
        $download_settings = isset($tools[self::TOOL_DOWNLOAD_SAMPLE_ENTITIES]) && is_array($tools[self::TOOL_DOWNLOAD_SAMPLE_ENTITIES])
            ? $tools[self::TOOL_DOWNLOAD_SAMPLE_ENTITIES]
            : [];

        return wp_parse_args($download_settings, self::get_download_sample_entities_defaults());
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function save_download_sample_entities_settings(array $values): array
    {
        $settings = self::get_all_settings();
        $sanitized = self::sanitize_download_sample_entities_settings($values);
        $sanitized['last_saved_at'] = gmdate('c');

        $settings['schema_version'] = self::SETTINGS_VERSION;
        if (! isset($settings['tools']) || ! is_array($settings['tools'])) {
            $settings['tools'] = [];
        }
        $settings['tools'][self::TOOL_DOWNLOAD_SAMPLE_ENTITIES] = $sanitized;

        update_option(self::OPTION_SETTINGS, $settings, false);

        return $sanitized;
    }

    /**
     * @return array<string,mixed>
     */
    public static function get_default_values(): array
    {
        return [
            'schema_version' => self::SETTINGS_VERSION,
            'tools' => [
                self::TOOL_DOWNLOAD_SAMPLE_ENTITIES => self::get_download_sample_entities_defaults(),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_download_sample_entities_defaults(): array
    {
        return [
            'last_saved_at' => '',
            'post_types' => [],
            'taxonomies' => [],
            'package_profile' => '',
            'shape_mode' => '',
            'value_style' => '',
            'variant_set' => '',
            'observed_scan_cap' => 0,
            'included_docs' => [],
        ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private static function sanitize_settings(array $raw): array
    {
        $sanitized = self::get_default_values();
        $sanitized['schema_version'] = self::SETTINGS_VERSION;

        $tools = isset($raw['tools']) && is_array($raw['tools']) ? $raw['tools'] : [];
        $download_settings = isset($tools[self::TOOL_DOWNLOAD_SAMPLE_ENTITIES]) && is_array($tools[self::TOOL_DOWNLOAD_SAMPLE_ENTITIES])
            ? $tools[self::TOOL_DOWNLOAD_SAMPLE_ENTITIES]
            : [];

        $sanitized['tools'][self::TOOL_DOWNLOAD_SAMPLE_ENTITIES] = self::sanitize_download_sample_entities_settings($download_settings);

        return $sanitized;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private static function sanitize_download_sample_entities_settings(array $raw): array
    {
        $defaults = self::get_download_sample_entities_defaults();

        $shape_options = class_exists('\Dbvc\AiPackage\Settings')
            ? array_keys(\Dbvc\AiPackage\Settings::get_shape_mode_options())
            : [];
        $profile_options = class_exists('\Dbvc\AiPackage\Settings')
            ? array_keys(\Dbvc\AiPackage\Settings::get_package_profile_options())
            : [];
        $value_options = class_exists('\Dbvc\AiPackage\Settings')
            ? array_keys(\Dbvc\AiPackage\Settings::get_value_style_options())
            : [];
        $variant_options = class_exists('\Dbvc\AiPackage\Settings')
            ? array_keys(\Dbvc\AiPackage\Settings::get_variant_set_options())
            : [];
        $doc_options = class_exists('\Dbvc\AiPackage\Settings')
            ? array_keys(\Dbvc\AiPackage\Settings::get_included_doc_options())
            : [];

        $observed_scan_cap = isset($raw['observed_scan_cap']) ? absint($raw['observed_scan_cap']) : 0;
        if (class_exists('\Dbvc\AiPackage\Settings')) {
            if ($observed_scan_cap > 0 && $observed_scan_cap < \Dbvc\AiPackage\Settings::MIN_OBSERVED_SCAN_CAP) {
                $observed_scan_cap = \Dbvc\AiPackage\Settings::MIN_OBSERVED_SCAN_CAP;
            }
            if ($observed_scan_cap > \Dbvc\AiPackage\Settings::MAX_OBSERVED_SCAN_CAP) {
                $observed_scan_cap = \Dbvc\AiPackage\Settings::MAX_OBSERVED_SCAN_CAP;
            }
        }

        return [
            'last_saved_at' => isset($raw['last_saved_at']) ? sanitize_text_field((string) $raw['last_saved_at']) : (string) $defaults['last_saved_at'],
            'post_types' => self::sanitize_string_array(isset($raw['post_types']) && is_array($raw['post_types']) ? $raw['post_types'] : []),
            'taxonomies' => self::sanitize_string_array(isset($raw['taxonomies']) && is_array($raw['taxonomies']) ? $raw['taxonomies'] : []),
            'package_profile' => self::sanitize_allowed_key($raw['package_profile'] ?? '', $profile_options),
            'shape_mode' => self::sanitize_allowed_key($raw['shape_mode'] ?? '', $shape_options),
            'value_style' => self::sanitize_allowed_key($raw['value_style'] ?? '', $value_options),
            'variant_set' => self::sanitize_allowed_key($raw['variant_set'] ?? '', $variant_options),
            'observed_scan_cap' => $observed_scan_cap,
            'included_docs' => self::sanitize_allowed_string_array(isset($raw['included_docs']) && is_array($raw['included_docs']) ? $raw['included_docs'] : [], $doc_options),
        ];
    }

    /**
     * @param mixed              $value
     * @param array<int,string>  $allowed
     * @return string
     */
    private static function sanitize_allowed_key($value, array $allowed): string
    {
        $value = sanitize_key((string) $value);
        if ($value === '') {
            return '';
        }

        if (! empty($allowed) && ! in_array($value, $allowed, true)) {
            return '';
        }

        return $value;
    }

    /**
     * @param array<int|string,mixed> $values
     * @param array<int,string>       $allowed
     * @return array<int,string>
     */
    private static function sanitize_allowed_string_array(array $values, array $allowed): array
    {
        $sanitized = self::sanitize_string_array($values);
        if (empty($allowed)) {
            return $sanitized;
        }

        return array_values(array_intersect($sanitized, $allowed));
    }

    /**
     * @param array<int|string,mixed> $values
     * @return array<int,string>
     */
    private static function sanitize_string_array(array $values): array
    {
        $sanitized = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $string = sanitize_key((string) $value);
            if ($string === '') {
                continue;
            }

            $sanitized[$string] = $string;
        }

        return array_values($sanitized);
    }
}
