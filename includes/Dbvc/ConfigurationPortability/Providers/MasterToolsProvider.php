<?php

namespace Dbvc\ConfigurationPortability\Providers;

use Dbvc\ConfigurationPortability\AbstractOptionArrayDomainProvider;
use Dbvc\ConfigurationPortability\Field;

if (! defined('WPINC')) {
    die;
}

final class MasterToolsProvider extends AbstractOptionArrayDomainProvider
{
    private const TOOL_DOWNLOAD_SAMPLE_ENTITIES = 'download_sample_entities';

    public function get_key(): string
    {
        return 'master_tools';
    }

    public function get_label(): string
    {
        return __('Master Tools', 'dbvc');
    }

    public function get_version(): int
    {
        return 1;
    }

    public function get_groups(): array
    {
        return [
            'download_sample_entities' => [
                'label' => __('Download Sample Entities', 'dbvc'),
                'fields' => [
                    'sample_post_types',
                    'sample_taxonomies',
                    'sample_package_profile',
                    'sample_shape_mode',
                    'sample_value_style',
                    'sample_variant_set',
                    'sample_observed_scan_cap',
                    'sample_included_docs',
                ],
            ],
        ];
    }

    public function get_fields(): array
    {
        return [
            'sample_post_types' => Field::key_list('sample_post_types', __('Sample post types', 'dbvc'), 'download_sample_entities', [], $this->path_args('post_types')),
            'sample_taxonomies' => Field::key_list('sample_taxonomies', __('Sample taxonomies', 'dbvc'), 'download_sample_entities', [], $this->path_args('taxonomies')),
            'sample_package_profile' => Field::select('sample_package_profile', __('Sample package profile', 'dbvc'), 'download_sample_entities', '', $this->option_keys('get_package_profile_options'), $this->path_args('package_profile')),
            'sample_shape_mode' => Field::select('sample_shape_mode', __('Sample shape mode', 'dbvc'), 'download_sample_entities', '', $this->option_keys('get_shape_mode_options'), $this->path_args('shape_mode')),
            'sample_value_style' => Field::select('sample_value_style', __('Sample value style', 'dbvc'), 'download_sample_entities', '', $this->option_keys('get_value_style_options'), $this->path_args('value_style')),
            'sample_variant_set' => Field::select('sample_variant_set', __('Sample variant set', 'dbvc'), 'download_sample_entities', '', $this->option_keys('get_variant_set_options'), $this->path_args('variant_set')),
            'sample_observed_scan_cap' => Field::integer('sample_observed_scan_cap', __('Observed scan cap', 'dbvc'), 'download_sample_entities', 0, 0, $this->observed_scan_cap_max(), $this->path_args('observed_scan_cap')),
            'sample_included_docs' => Field::key_list('sample_included_docs', __('Included docs', 'dbvc'), 'download_sample_entities', [], array_merge($this->path_args('included_docs'), ['allowed' => $this->option_keys('get_included_doc_options')])),
        ];
    }

    protected function get_option_key(): string
    {
        return class_exists('DBVC_Master_Settings') ? \DBVC_Master_Settings::OPTION_SETTINGS : 'dbvc_master_settings';
    }

    protected function get_default_option_value(): array
    {
        return class_exists('DBVC_Master_Settings') ? \DBVC_Master_Settings::get_default_values() : [];
    }

    protected function sanitize_option_array(array $candidate, array $current): array
    {
        unset($current);

        $defaults = $this->get_default_option_value();
        $settings = $this->merge_defaults($candidate, $defaults);
        $settings['schema_version'] = class_exists('DBVC_Master_Settings') ? \DBVC_Master_Settings::SETTINGS_VERSION : 1;

        return $settings;
    }

    /**
     * @param string $field
     * @return array<string, mixed>
     */
    private function path_args($field): array
    {
        return [
            'option_path' => ['tools', self::TOOL_DOWNLOAD_SAMPLE_ENTITIES, $field],
        ];
    }

    /**
     * @param string $method
     * @return array<int, string>
     */
    private function option_keys($method): array
    {
        if (! class_exists('\Dbvc\AiPackage\Settings') || ! method_exists('\Dbvc\AiPackage\Settings', $method)) {
            return [''];
        }

        $options = call_user_func(['\Dbvc\AiPackage\Settings', $method]);

        return is_array($options) ? array_values(array_unique(array_merge([''], array_keys($options)))) : [''];
    }

    private function observed_scan_cap_max(): int
    {
        return class_exists('\Dbvc\AiPackage\Settings') ? \Dbvc\AiPackage\Settings::MAX_OBSERVED_SCAN_CAP : 100;
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function merge_defaults(array $values, array $defaults): array
    {
        foreach ($defaults as $key => $default_value) {
            if (! array_key_exists($key, $values)) {
                $values[$key] = $default_value;
                continue;
            }

            if (is_array($default_value) && is_array($values[$key])) {
                $values[$key] = $this->merge_defaults($values[$key], $default_value);
            }
        }

        return $values;
    }
}
