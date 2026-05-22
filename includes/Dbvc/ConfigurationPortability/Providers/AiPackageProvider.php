<?php

namespace Dbvc\ConfigurationPortability\Providers;

use Dbvc\ConfigurationPortability\AbstractOptionArrayDomainProvider;
use Dbvc\ConfigurationPortability\Field;

if (! defined('WPINC')) {
    die;
}

final class AiPackageProvider extends AbstractOptionArrayDomainProvider
{
    public function get_key(): string
    {
        return 'ai_package';
    }

    public function get_label(): string
    {
        return __('AI Package', 'dbvc');
    }

    public function get_version(): int
    {
        return 1;
    }

    public function get_groups(): array
    {
        return [
            'generation' => [
                'label' => __('Generation', 'dbvc'),
                'fields' => [
                    'ai_generation_package_profile',
                    'ai_generation_shape_mode',
                    'ai_generation_value_style',
                    'ai_generation_variant_set',
                    'ai_generation_observed_scan_cap',
                    'ai_generation_included_docs',
                ],
            ],
            'validation' => [
                'label' => __('Validation', 'dbvc'),
                'fields' => [
                    'ai_validation_warning_policy',
                    'ai_validation_package_mode',
                    'ai_validation_strictness',
                ],
            ],
            'guidance' => [
                'label' => __('Guidance', 'dbvc'),
                'fields' => [
                    'ai_guidance_global',
                    'ai_guidance_starter_prompt',
                    'ai_guidance_notes',
                ],
            ],
            'rules' => [
                'label' => __('Rules', 'dbvc'),
                'fields' => [
                    'ai_rules_global',
                    'ai_rules_post_types',
                    'ai_rules_taxonomies',
                ],
            ],
            'providers' => [
                'label' => __('Provider Defaults', 'dbvc'),
                'fields' => [
                    'ai_provider_key',
                    'ai_provider_model_default',
                    'ai_provider_service_mode',
                    'ai_provider_api_key',
                ],
            ],
        ];
    }

    public function get_fields(): array
    {
        $defaults = $this->get_default_option_value();

        return [
            'ai_generation_package_profile' => Field::select('ai_generation_package_profile', __('Package profile', 'dbvc'), 'generation', (string) ($defaults['generation']['package_profile'] ?? 'compact_ai_chat'), $this->option_keys('get_package_profile_options'), $this->path_args('generation', 'package_profile')),
            'ai_generation_shape_mode' => Field::select('ai_generation_shape_mode', __('Shape mode', 'dbvc'), 'generation', (string) ($defaults['generation']['shape_mode'] ?? 'conservative'), $this->option_keys('get_shape_mode_options'), $this->path_args('generation', 'shape_mode')),
            'ai_generation_value_style' => Field::select('ai_generation_value_style', __('Value style', 'dbvc'), 'generation', (string) ($defaults['generation']['value_style'] ?? 'blank'), $this->option_keys('get_value_style_options'), $this->path_args('generation', 'value_style')),
            'ai_generation_variant_set' => Field::select('ai_generation_variant_set', __('Variant set', 'dbvc'), 'generation', (string) ($defaults['generation']['variant_set'] ?? 'single'), $this->option_keys('get_variant_set_options'), $this->path_args('generation', 'variant_set')),
            'ai_generation_observed_scan_cap' => Field::integer('ai_generation_observed_scan_cap', __('Observed scan cap', 'dbvc'), 'generation', (int) ($defaults['generation']['observed_scan_cap'] ?? 20), $this->observed_scan_cap_min(), $this->observed_scan_cap_max(), $this->path_args('generation', 'observed_scan_cap')),
            'ai_generation_included_docs' => Field::key_list('ai_generation_included_docs', __('Included docs', 'dbvc'), 'generation', (array) ($defaults['generation']['included_docs'] ?? []), array_merge($this->path_args('generation', 'included_docs'), ['allowed' => $this->option_keys('get_included_doc_options')])),
            'ai_validation_warning_policy' => Field::select('ai_validation_warning_policy', __('Warning policy', 'dbvc'), 'validation', (string) ($defaults['validation']['warning_policy'] ?? 'confirm'), $this->option_keys('get_warning_policy_options'), $this->path_args('validation', 'warning_policy')),
            'ai_validation_package_mode' => Field::select('ai_validation_package_mode', __('Package mode', 'dbvc'), 'validation', (string) ($defaults['validation']['package_mode'] ?? 'create_and_update'), $this->option_keys('get_package_mode_options'), $this->path_args('validation', 'package_mode')),
            'ai_validation_strictness' => Field::select('ai_validation_strictness', __('Strictness', 'dbvc'), 'validation', (string) ($defaults['validation']['strictness'] ?? 'standard'), $this->option_keys('get_strictness_options'), $this->path_args('validation', 'strictness')),
            'ai_guidance_global' => Field::textarea('ai_guidance_global', __('Global AI guidance', 'dbvc'), 'guidance', '', $this->path_args('guidance', 'global_ai_guidance')),
            'ai_guidance_starter_prompt' => Field::textarea('ai_guidance_starter_prompt', __('Starter prompt template', 'dbvc'), 'guidance', '', $this->path_args('guidance', 'starter_prompt_template')),
            'ai_guidance_notes' => Field::textarea('ai_guidance_notes', __('Global notes markdown', 'dbvc'), 'guidance', '', $this->path_args('guidance', 'global_notes_markdown')),
            'ai_rules_global' => Field::map('ai_rules_global', __('Global rules', 'dbvc'), 'rules', (array) ($defaults['rules']['global'] ?? []), $this->path_args('rules', 'global')),
            'ai_rules_post_types' => Field::map('ai_rules_post_types', __('Post type rules', 'dbvc'), 'rules', [], $this->path_args('rules', 'post_types')),
            'ai_rules_taxonomies' => Field::map('ai_rules_taxonomies', __('Taxonomy rules', 'dbvc'), 'rules', [], $this->path_args('rules', 'taxonomies')),
            'ai_provider_key' => Field::select('ai_provider_key', __('Provider', 'dbvc'), 'providers', (string) ($defaults['providers']['provider_key'] ?? 'openai'), $this->option_keys('get_provider_options'), $this->path_args('providers', 'provider_key')),
            'ai_provider_model_default' => Field::text('ai_provider_model_default', __('Default model', 'dbvc'), 'providers', (string) ($defaults['providers']['model_default'] ?? 'gpt-5.4'), $this->path_args('providers', 'model_default')),
            'ai_provider_service_mode' => Field::select('ai_provider_service_mode', __('Service mode', 'dbvc'), 'providers', (string) ($defaults['providers']['service_mode'] ?? 'provider_api'), $this->option_keys('get_service_mode_options'), $this->path_args('providers', 'service_mode')),
            'ai_provider_api_key' => Field::text(
                'ai_provider_api_key',
                __('Provider API key', 'dbvc'),
                'providers',
                '',
                array_merge(
                    $this->path_args('providers', 'api_key'),
                    [
                        'default_export' => Field::POLICY_EXCLUDE,
                        'environment_policy' => Field::POLICY_EXCLUDE,
                        'apply_strategy' => Field::STRATEGY_KEEP_EXISTING_UNLESS_SUPPLIED,
                        'sensitive' => true,
                        'requires_confirmation' => true,
                    ]
                )
            ),
        ];
    }

    public function apply(array $sanitized, array $context): array
    {
        unset($context);

        if (! class_exists('\Dbvc\AiPackage\Settings')) {
            return [
                'domain' => $this->get_key(),
                'applied' => [],
                'skipped' => ['ai_package' => 'unavailable'],
            ];
        }

        $values = isset($sanitized['values']) && is_array($sanitized['values']) ? $sanitized['values'] : [];
        $fields = $this->get_fields();
        $candidate = $this->get_option_array();
        $applied = [];
        $skipped = [];

        foreach ($values as $field_key => $value) {
            if (! isset($fields[$field_key])) {
                $skipped[$field_key] = 'unknown_field';
                continue;
            }

            $path = $this->get_field_path($field_key, $fields[$field_key]);
            $this->set_path_value($candidate, $path, $value);
            $applied[$field_key] = $this->get_option_key() . ':' . implode('.', $path);
        }

        if (! empty($applied)) {
            \Dbvc\AiPackage\Settings::save_settings($candidate);
        }

        return [
            'domain' => $this->get_key(),
            'applied' => $applied,
            'skipped' => $skipped,
        ];
    }

    protected function get_option_key(): string
    {
        return class_exists('\Dbvc\AiPackage\Settings') ? \Dbvc\AiPackage\Settings::OPTION_SETTINGS : 'dbvc_ai_package_settings';
    }

    protected function get_default_option_value(): array
    {
        return class_exists('\Dbvc\AiPackage\Settings') ? \Dbvc\AiPackage\Settings::get_default_values() : [];
    }

    protected function get_option_array(): array
    {
        if (class_exists('\Dbvc\AiPackage\Settings')) {
            return \Dbvc\AiPackage\Settings::get_all_settings();
        }

        return parent::get_option_array();
    }

    /**
     * @param string $section
     * @param string $field
     * @return array<string, mixed>
     */
    private function path_args($section, $field): array
    {
        return [
            'option_path' => [$section, $field],
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

        return is_array($options) ? array_keys($options) : [''];
    }

    private function observed_scan_cap_min(): int
    {
        return class_exists('\Dbvc\AiPackage\Settings') ? \Dbvc\AiPackage\Settings::MIN_OBSERVED_SCAN_CAP : 1;
    }

    private function observed_scan_cap_max(): int
    {
        return class_exists('\Dbvc\AiPackage\Settings') ? \Dbvc\AiPackage\Settings::MAX_OBSERVED_SCAN_CAP : 100;
    }
}
