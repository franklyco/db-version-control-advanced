<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class Settings
{
    public const OPTION_SETTINGS = 'dbvc_ai_package_settings';
    public const SETTINGS_VERSION = 1;
    public const DEFAULT_OBSERVED_SCAN_CAP = 20;
    public const MIN_OBSERVED_SCAN_CAP = 1;
    public const MAX_OBSERVED_SCAN_CAP = 100;
    public const DEFAULT_PROVIDER_KEY = 'openai';
    public const DEFAULT_PROVIDER_LABEL = 'OpenAI';
    public const DEFAULT_MODEL_ID = 'gpt-5.4';

    /**
     * Ensure the option exists with default values.
     *
     * @return void
     */
    public static function ensure_defaults(): void
    {
        add_option(self::OPTION_SETTINGS, self::get_default_values(), '', false);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_default_values(): array
    {
        return [
            'schema_version' => self::SETTINGS_VERSION,
            'generation'     => [
                'shape_mode'        => 'conservative',
                'value_style'       => 'blank',
                'variant_set'       => 'single',
                'observed_scan_cap' => self::DEFAULT_OBSERVED_SCAN_CAP,
                'included_docs'     => array_keys(self::get_included_doc_options()),
            ],
            'validation'     => [
                'warning_policy' => 'confirm',
                'package_mode'   => 'create_and_update',
                'strictness'     => 'standard',
            ],
            'guidance'       => [
                'global_ai_guidance'      => '',
                'starter_prompt_template' => '',
                'global_notes_markdown'   => '',
            ],
            'rules'          => [
                'global'     => [
                    'required_post_fields' => ['post_title', 'post_name'],
                    'blocked_meta_keys'    => [],
                    'blocked_field_paths'  => [],
                    'allowed_statuses'     => ['draft', 'publish'],
                    'validation'           => [
                        'unknown_extra_fields' => 'warn',
                        'invalid_choice_values' => 'block',
                    ],
                ],
                'post_types' => [],
                'taxonomies' => [],
            ],
            'providers'      => [
                'provider_key'   => self::DEFAULT_PROVIDER_KEY,
                'provider_label' => self::DEFAULT_PROVIDER_LABEL,
                'model_default'  => self::DEFAULT_MODEL_ID,
                'service_mode'   => 'provider_api',
                'api_key'        => '',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_all_settings(): array
    {
        self::ensure_defaults();

        $stored = get_option(self::OPTION_SETTINGS, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        return self::merge_defaults(self::get_default_values(), self::sanitize_settings($stored, self::get_default_values()));
    }

    /**
     * Persist sanitized settings from a request payload.
     *
     * @param array<string, mixed> $request_data
     * @return array<string, mixed>
     */
    public static function save_settings(array $request_data): array
    {
        $current = self::get_all_settings();
        $raw = isset($request_data['dbvc_ai_settings']) && is_array($request_data['dbvc_ai_settings'])
            ? $request_data['dbvc_ai_settings']
            : $request_data;

        $values = self::sanitize_settings($raw, $current);

        update_option(self::OPTION_SETTINGS, $values, false);

        return [
            'values' => $values,
            'errors' => [],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function get_shape_mode_options(): array
    {
        return [
            'conservative'  => __('Conservative', 'dbvc'),
            'observed_shape' => __('Observed Shape', 'dbvc'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function get_value_style_options(): array
    {
        return [
            'blank' => __('Blank', 'dbvc'),
            'dummy' => __('Dummy', 'dbvc'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function get_variant_set_options(): array
    {
        return [
            'single' => __('Single', 'dbvc'),
            'minimal' => __('Minimal only', 'dbvc'),
            'typical' => __('Typical only', 'dbvc'),
            'maximal' => __('Maximal only', 'dbvc'),
            'full_set' => __('Minimal + Typical + Maximal', 'dbvc'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function get_warning_policy_options(): array
    {
        return [
            'confirm'        => __('Require confirmation', 'dbvc'),
            'auto_continue'  => __('Continue automatically', 'dbvc'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function get_package_mode_options(): array
    {
        return [
            'create_and_update' => __('Create and update', 'dbvc'),
            'create_only'       => __('Create only', 'dbvc'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function get_strictness_options(): array
    {
        return [
            'standard' => __('Standard', 'dbvc'),
            'strict'   => __('Strict', 'dbvc'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function get_included_doc_options(): array
    {
        return [
            'readme'           => __('README.md', 'dbvc'),
            'agents'           => __('AGENTS.md', 'dbvc'),
            'starter_prompt'   => __('STARTER_PROMPT.md', 'dbvc'),
            'output_contract'  => __('OUTPUT_CONTRACT.md', 'dbvc'),
            'user_rules'       => __('USER_RULES.md', 'dbvc'),
            'validation_rules' => __('VALIDATION_RULES.md', 'dbvc'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function get_service_mode_options(): array
    {
        return [
            'browser_managed' => __('Browser-managed workflow', 'dbvc'),
            'provider_api'    => __('Provider API', 'dbvc'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function get_provider_options(): array
    {
        return [
            self::DEFAULT_PROVIDER_KEY => __(self::DEFAULT_PROVIDER_LABEL, 'dbvc'),
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    private static function sanitize_settings(array $raw, array $current): array
    {
        $defaults = self::get_default_values();

        $generation = isset($raw['generation']) && is_array($raw['generation'])
            ? $raw['generation']
            : (isset($current['generation']) && is_array($current['generation']) ? $current['generation'] : []);
        $validation = isset($raw['validation']) && is_array($raw['validation'])
            ? $raw['validation']
            : (isset($current['validation']) && is_array($current['validation']) ? $current['validation'] : []);
        $guidance = isset($raw['guidance']) && is_array($raw['guidance'])
            ? $raw['guidance']
            : (isset($current['guidance']) && is_array($current['guidance']) ? $current['guidance'] : []);
        $rules = isset($raw['rules']) && is_array($raw['rules'])
            ? $raw['rules']
            : (isset($current['rules']) && is_array($current['rules']) ? $current['rules'] : []);
        $providers = isset($raw['providers']) && is_array($raw['providers'])
            ? $raw['providers']
            : (isset($current['providers']) && is_array($current['providers']) ? $current['providers'] : []);

        $allowed_docs = array_keys(self::get_included_doc_options());
        $included_docs = isset($generation['included_docs']) && is_array($generation['included_docs'])
            ? array_map('sanitize_key', wp_unslash($generation['included_docs']))
            : (array) ($defaults['generation']['included_docs'] ?? []);
        $included_docs = array_values(array_intersect($included_docs, $allowed_docs));
        if (empty($included_docs)) {
            $included_docs = (array) ($defaults['generation']['included_docs'] ?? []);
        }

        $shape_mode = isset($generation['shape_mode']) ? sanitize_key((string) wp_unslash($generation['shape_mode'])) : '';
        if (! array_key_exists($shape_mode, self::get_shape_mode_options())) {
            $shape_mode = (string) $defaults['generation']['shape_mode'];
        }

        $value_style = isset($generation['value_style']) ? sanitize_key((string) wp_unslash($generation['value_style'])) : '';
        if (! array_key_exists($value_style, self::get_value_style_options())) {
            $value_style = (string) $defaults['generation']['value_style'];
        }

        $variant_set = isset($generation['variant_set']) ? sanitize_key((string) wp_unslash($generation['variant_set'])) : '';
        if (! array_key_exists($variant_set, self::get_variant_set_options())) {
            $variant_set = (string) $defaults['generation']['variant_set'];
        }

        $observed_scan_cap = isset($generation['observed_scan_cap']) ? absint($generation['observed_scan_cap']) : self::DEFAULT_OBSERVED_SCAN_CAP;
        if ($observed_scan_cap < self::MIN_OBSERVED_SCAN_CAP) {
            $observed_scan_cap = self::MIN_OBSERVED_SCAN_CAP;
        } elseif ($observed_scan_cap > self::MAX_OBSERVED_SCAN_CAP) {
            $observed_scan_cap = self::MAX_OBSERVED_SCAN_CAP;
        }

        $warning_policy = isset($validation['warning_policy']) ? sanitize_key((string) wp_unslash($validation['warning_policy'])) : '';
        if (! array_key_exists($warning_policy, self::get_warning_policy_options())) {
            $warning_policy = (string) $defaults['validation']['warning_policy'];
        }

        $package_mode = isset($validation['package_mode']) ? sanitize_key((string) wp_unslash($validation['package_mode'])) : '';
        if (! array_key_exists($package_mode, self::get_package_mode_options())) {
            $package_mode = (string) $defaults['validation']['package_mode'];
        }

        $strictness = isset($validation['strictness']) ? sanitize_key((string) wp_unslash($validation['strictness'])) : '';
        if (! array_key_exists($strictness, self::get_strictness_options())) {
            $strictness = (string) $defaults['validation']['strictness'];
        }

        $service_mode = isset($providers['service_mode']) ? sanitize_key((string) wp_unslash($providers['service_mode'])) : '';
        if (! array_key_exists($service_mode, self::get_service_mode_options())) {
            $service_mode = (string) $defaults['providers']['service_mode'];
        }

        $provider_key = isset($providers['provider_key']) ? sanitize_key((string) wp_unslash($providers['provider_key'])) : '';
        if (! array_key_exists($provider_key, self::get_provider_options())) {
            $provider_key = (string) $defaults['providers']['provider_key'];
        }

        $provider_label = (string) (self::get_provider_options()[$provider_key] ?? self::DEFAULT_PROVIDER_LABEL);
        $model_default = isset($providers['model_default']) ? sanitize_text_field((string) wp_unslash($providers['model_default'])) : '';
        if ($model_default === '') {
            $model_default = (string) ($current['providers']['model_default'] ?? $defaults['providers']['model_default']);
        }

        $api_key = isset($current['providers']['api_key']) ? (string) $current['providers']['api_key'] : (string) $defaults['providers']['api_key'];
        if (! empty($providers['clear_api_key'])) {
            $api_key = '';
        } elseif (isset($providers['api_key'])) {
            $api_key_input = trim((string) wp_unslash($providers['api_key']));
            if ($api_key_input !== '') {
                $api_key = $api_key_input;
            }
        }

        $sanitized_rules = self::sanitize_rules(
            $rules,
            isset($current['rules']) && is_array($current['rules']) ? $current['rules'] : [],
            isset($defaults['rules']) && is_array($defaults['rules']) ? $defaults['rules'] : []
        );

        return [
            'schema_version' => self::SETTINGS_VERSION,
            'generation'     => [
                'shape_mode'        => $shape_mode,
                'value_style'       => $value_style,
                'variant_set'       => $variant_set,
                'observed_scan_cap' => $observed_scan_cap,
                'included_docs'     => $included_docs,
            ],
            'validation'     => [
                'warning_policy' => $warning_policy,
                'package_mode'   => $package_mode,
                'strictness'     => $strictness,
            ],
            'guidance'       => [
                'global_ai_guidance'      => isset($guidance['global_ai_guidance']) ? sanitize_textarea_field((string) wp_unslash($guidance['global_ai_guidance'])) : '',
                'starter_prompt_template' => isset($guidance['starter_prompt_template']) ? sanitize_textarea_field((string) wp_unslash($guidance['starter_prompt_template'])) : '',
                'global_notes_markdown'   => isset($guidance['global_notes_markdown']) ? sanitize_textarea_field((string) wp_unslash($guidance['global_notes_markdown'])) : '',
            ],
            'rules'          => $sanitized_rules,
            'providers'      => [
                'provider_key'   => $provider_key,
                'provider_label' => $provider_label,
                'model_default'  => $model_default,
                'service_mode'   => $service_mode,
                'api_key'        => $api_key,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $defaults
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private static function merge_defaults(array $defaults, array $values): array
    {
        foreach ($defaults as $key => $default_value) {
            if (! array_key_exists($key, $values)) {
                $values[$key] = $default_value;
                continue;
            }

            if (is_array($default_value) && is_array($values[$key])) {
                $values[$key] = self::merge_defaults($default_value, $values[$key]);
            }
        }

        return $values;
    }

    /**
     * @param array<string,mixed> $rules
     * @param array<string,mixed> $current
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    private static function sanitize_rules(array $rules, array $current, array $defaults): array
    {
        $default_global = isset($defaults['global']) && is_array($defaults['global']) ? $defaults['global'] : [];
        $current_global = isset($current['global']) && is_array($current['global']) ? $current['global'] : $default_global;

        return [
            'global' => self::sanitize_rule_node(
                isset($rules['global']) && is_array($rules['global']) ? $rules['global'] : $current_global,
                $current_global,
                $default_global
            ),
            'post_types' => self::sanitize_rule_map(
                isset($rules['post_types']) && is_array($rules['post_types']) ? $rules['post_types'] : [],
                isset($current['post_types']) && is_array($current['post_types']) ? $current['post_types'] : [],
                $default_global
            ),
            'taxonomies' => self::sanitize_rule_map(
                isset($rules['taxonomies']) && is_array($rules['taxonomies']) ? $rules['taxonomies'] : [],
                isset($current['taxonomies']) && is_array($current['taxonomies']) ? $current['taxonomies'] : [],
                $default_global
            ),
        ];
    }

    /**
     * @param array<string,mixed> $map
     * @param array<string,mixed> $current_map
     * @param array<string,mixed> $default_node
     * @return array<string,array<string,mixed>>
     */
    private static function sanitize_rule_map(array $map, array $current_map, array $default_node): array
    {
        $sanitized = [];

        foreach ($map as $object_key => $rule_node) {
            $object_key = sanitize_key((string) $object_key);
            if ($object_key === '' || ! is_array($rule_node)) {
                continue;
            }

            $current_node = isset($current_map[$object_key]) && is_array($current_map[$object_key]) ? $current_map[$object_key] : $default_node;
            $sanitized[$object_key] = self::sanitize_rule_node($rule_node, $current_node, $default_node);
        }

        ksort($sanitized);

        return $sanitized;
    }

    /**
     * @param array<string,mixed> $node
     * @param array<string,mixed> $current
     * @param array<string,mixed> $defaults
     * @return array<string,mixed>
     */
    private static function sanitize_rule_node(array $node, array $current, array $defaults): array
    {
        $required_post_fields = isset($node['required_post_fields']) && is_array($node['required_post_fields'])
            ? array_values(array_filter(array_map('sanitize_key', $node['required_post_fields'])))
            : (isset($current['required_post_fields']) && is_array($current['required_post_fields']) ? $current['required_post_fields'] : (array) ($defaults['required_post_fields'] ?? []));
        $blocked_meta_keys = isset($node['blocked_meta_keys']) && is_array($node['blocked_meta_keys'])
            ? array_values(array_filter(array_map('sanitize_key', $node['blocked_meta_keys'])))
            : (isset($current['blocked_meta_keys']) && is_array($current['blocked_meta_keys']) ? $current['blocked_meta_keys'] : (array) ($defaults['blocked_meta_keys'] ?? []));
        $blocked_field_paths = isset($node['blocked_field_paths']) && is_array($node['blocked_field_paths'])
            ? array_values(array_filter(array_map(static function ($value): string {
                return sanitize_text_field((string) wp_unslash($value));
            }, $node['blocked_field_paths'])))
            : (isset($current['blocked_field_paths']) && is_array($current['blocked_field_paths']) ? $current['blocked_field_paths'] : (array) ($defaults['blocked_field_paths'] ?? []));
        $allowed_statuses = isset($node['allowed_statuses']) && is_array($node['allowed_statuses'])
            ? array_values(array_filter(array_map('sanitize_key', $node['allowed_statuses'])))
            : (isset($current['allowed_statuses']) && is_array($current['allowed_statuses']) ? $current['allowed_statuses'] : (array) ($defaults['allowed_statuses'] ?? []));

        if (empty($required_post_fields)) {
            $required_post_fields = (array) ($defaults['required_post_fields'] ?? []);
        }

        $validation = isset($node['validation']) && is_array($node['validation'])
            ? $node['validation']
            : (isset($current['validation']) && is_array($current['validation']) ? $current['validation'] : (isset($defaults['validation']) && is_array($defaults['validation']) ? $defaults['validation'] : []));
        $allowed_validation_modes = ['warn', 'block'];

        $unknown_extra_fields = isset($validation['unknown_extra_fields']) ? sanitize_key((string) $validation['unknown_extra_fields']) : '';
        if (! in_array($unknown_extra_fields, $allowed_validation_modes, true)) {
            $unknown_extra_fields = (string) (($current['validation']['unknown_extra_fields'] ?? $defaults['validation']['unknown_extra_fields']) ?? 'warn');
        }

        $invalid_choice_values = isset($validation['invalid_choice_values']) ? sanitize_key((string) $validation['invalid_choice_values']) : '';
        if (! in_array($invalid_choice_values, $allowed_validation_modes, true)) {
            $invalid_choice_values = (string) (($current['validation']['invalid_choice_values'] ?? $defaults['validation']['invalid_choice_values']) ?? 'block');
        }

        return [
            'required_post_fields' => $required_post_fields,
            'blocked_meta_keys' => $blocked_meta_keys,
            'blocked_field_paths' => $blocked_field_paths,
            'allowed_statuses' => $allowed_statuses,
            'validation' => [
                'unknown_extra_fields' => $unknown_extra_fields,
                'invalid_choice_values' => $invalid_choice_values,
            ],
        ];
    }
}
