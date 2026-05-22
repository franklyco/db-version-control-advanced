<?php

namespace Dbvc\ConfigurationPortability\Providers;

use Dbvc\ConfigurationPortability\AbstractOptionArrayDomainProvider;
use Dbvc\ConfigurationPortability\Field;

if (! defined('WPINC')) {
    die;
}

final class ContentCollectorProvider extends AbstractOptionArrayDomainProvider
{
    public function get_key(): string
    {
        return 'content_collector';
    }

    public function get_label(): string
    {
        return __('Content Collector', 'dbvc');
    }

    public function get_version(): int
    {
        return 1;
    }

    public function get_groups(): array
    {
        return [
            'storage' => [
                'label' => __('Storage', 'dbvc'),
                'fields' => ['cc_storage_path', 'cc_dev_mode', 'cc_dev_storage_subdir'],
            ],
            'crawl_defaults' => [
                'label' => __('Crawl Defaults', 'dbvc'),
                'fields' => ['cc_request_delay', 'cc_request_timeout', 'cc_user_agent', 'cc_exclude_selectors', 'cc_focus_selectors'],
            ],
            'ai' => [
                'label' => __('AI Defaults', 'dbvc'),
                'fields' => ['cc_prompt_version', 'cc_ai_fallback_mode', 'cc_openai_model', 'cc_openai_api_key', 'cc_ai_request_timeout', 'cc_ai_enable_section_typing', 'cc_ai_section_typing_confidence_threshold'],
            ],
            'import_policy' => [
                'label' => __('Import Policy', 'dbvc'),
                'fields' => ['cc_slug_collision_policy', 'cc_taxonomy_collision_policy'],
            ],
            'explorer' => [
                'label' => __('Explorer', 'dbvc'),
                'fields' => ['cc_explorer_default_depth', 'cc_explorer_max_nodes', 'cc_explorer_cache_ttl'],
            ],
            'capture' => [
                'label' => __('Capture', 'dbvc'),
                'fields' => ['cc_capture_mode', 'cc_capture_include_attribute_context', 'cc_capture_include_dom_path', 'cc_capture_max_elements_per_page', 'cc_capture_max_chars_per_element', 'cc_context_enable_boilerplate_detection', 'cc_context_enable_entity_hints'],
            ],
            'scrub_policy' => [
                'label' => __('Scrub Policy', 'dbvc'),
                'fields' => ['cc_redact_emails', 'cc_redact_phones', 'cc_redact_forms', 'cc_scrub_policy_enabled', 'cc_scrub_profile_mode', 'cc_scrub_attr_action_class', 'cc_scrub_attr_action_id', 'cc_scrub_attr_action_data', 'cc_scrub_attr_action_style', 'cc_scrub_attr_action_aria', 'cc_scrub_custom_allowlist', 'cc_scrub_custom_denylist', 'cc_scrub_ai_suggestion_enabled', 'cc_scrub_preview_sample_size'],
            ],
            'mapping_media' => [
                'label' => __('Mapping And Media', 'dbvc'),
                'fields' => ['cc_mapping_catalog_refresh_strategy', 'cc_mapping_candidate_confidence_threshold', 'cc_media_mapping_confidence_threshold', 'cc_media_discovery_mode', 'cc_media_download_policy', 'cc_media_mime_allowlist', 'cc_media_max_bytes_per_asset', 'cc_media_preview_thumbnail_enabled', 'cc_media_block_private_hosts', 'cc_media_source_domain_allowlist', 'cc_media_source_domain_denylist'],
            ],
        ];
    }

    public function get_fields(): array
    {
        $defaults = $this->get_default_option_value();

        return [
            'cc_storage_path' => Field::file_name('cc_storage_path', __('Storage folder', 'dbvc'), 'storage', (string) ($defaults['storage_path'] ?? 'contentcollector'), $this->path_args('storage_path', [
                'default_export' => Field::POLICY_PROMPT,
                'environment_policy' => Field::POLICY_PROMPT,
                'placeholder' => '${DBVC_CC_STORAGE_PATH}',
                'requires_confirmation' => true,
            ])),
            'cc_dev_mode' => Field::bool('cc_dev_mode', __('Dev mode', 'dbvc'), 'storage', (string) ($defaults['dev_mode'] ?? '0'), $this->path_args('dev_mode')),
            'cc_dev_storage_subdir' => Field::file_name('cc_dev_storage_subdir', __('Dev storage subfolder', 'dbvc'), 'storage', (string) ($defaults['dev_storage_subdir'] ?? 'dev-data'), $this->path_args('dev_storage_subdir')),
            'cc_request_delay' => Field::integer('cc_request_delay', __('Request delay', 'dbvc'), 'crawl_defaults', (int) ($defaults['request_delay'] ?? 500), 0, 10000, $this->path_args('request_delay')),
            'cc_request_timeout' => Field::integer('cc_request_timeout', __('Request timeout', 'dbvc'), 'crawl_defaults', (int) ($defaults['request_timeout'] ?? 30), 1, 300, $this->path_args('request_timeout')),
            'cc_user_agent' => Field::text('cc_user_agent', __('User agent', 'dbvc'), 'crawl_defaults', (string) ($defaults['user_agent'] ?? ''), $this->path_args('user_agent')),
            'cc_exclude_selectors' => Field::textarea('cc_exclude_selectors', __('Exclude selectors', 'dbvc'), 'crawl_defaults', '', $this->path_args('exclude_selectors')),
            'cc_focus_selectors' => Field::textarea('cc_focus_selectors', __('Focus selectors', 'dbvc'), 'crawl_defaults', '', $this->path_args('focus_selectors')),
            'cc_prompt_version' => Field::text('cc_prompt_version', __('Prompt version', 'dbvc'), 'ai', (string) ($defaults['prompt_version'] ?? 'v1'), $this->path_args('prompt_version')),
            'cc_ai_fallback_mode' => Field::bool('cc_ai_fallback_mode', __('AI fallback mode', 'dbvc'), 'ai', (string) ($defaults['ai_fallback_mode'] ?? '1'), $this->path_args('ai_fallback_mode')),
            'cc_openai_model' => Field::text('cc_openai_model', __('OpenAI model', 'dbvc'), 'ai', (string) ($defaults['openai_model'] ?? 'gpt-4o-mini'), $this->path_args('openai_model')),
            'cc_openai_api_key' => Field::text('cc_openai_api_key', __('OpenAI API key', 'dbvc'), 'ai', '', $this->path_args('openai_api_key', [
                'default_export' => Field::POLICY_EXCLUDE,
                'environment_policy' => Field::POLICY_EXCLUDE,
                'apply_strategy' => Field::STRATEGY_KEEP_EXISTING_UNLESS_SUPPLIED,
                'sensitive' => true,
                'requires_confirmation' => true,
            ])),
            'cc_ai_request_timeout' => Field::integer('cc_ai_request_timeout', __('AI request timeout', 'dbvc'), 'ai', (int) ($defaults['ai_request_timeout'] ?? 90), 30, 180, $this->path_args('ai_request_timeout')),
            'cc_ai_enable_section_typing' => Field::bool('cc_ai_enable_section_typing', __('Enable section typing', 'dbvc'), 'ai', (string) ($defaults['ai_enable_section_typing'] ?? '0'), $this->path_args('ai_enable_section_typing')),
            'cc_ai_section_typing_confidence_threshold' => Field::decimal('cc_ai_section_typing_confidence_threshold', __('Section typing confidence threshold', 'dbvc'), 'ai', (string) ($defaults['ai_section_typing_confidence_threshold'] ?? '0.65'), 0.0, 1.0, $this->path_args('ai_section_typing_confidence_threshold')),
            'cc_slug_collision_policy' => Field::select('cc_slug_collision_policy', __('Slug collision policy', 'dbvc'), 'import_policy', (string) ($defaults['slug_collision_policy'] ?? 'append-path-hash'), ['append-path-hash', 'append-increment', 'skip'], $this->path_args('slug_collision_policy')),
            'cc_taxonomy_collision_policy' => Field::select('cc_taxonomy_collision_policy', __('Taxonomy collision policy', 'dbvc'), 'import_policy', (string) ($defaults['taxonomy_collision_policy'] ?? 'match-existing-review'), ['match-existing-review', 'match-or-create', 'skip-unmatched'], $this->path_args('taxonomy_collision_policy')),
            'cc_redact_emails' => Field::bool('cc_redact_emails', __('Redact emails', 'dbvc'), 'scrub_policy', (string) ($defaults['redact_emails'] ?? '1'), $this->path_args('redact_emails')),
            'cc_redact_phones' => Field::bool('cc_redact_phones', __('Redact phones', 'dbvc'), 'scrub_policy', (string) ($defaults['redact_phones'] ?? '1'), $this->path_args('redact_phones')),
            'cc_redact_forms' => Field::bool('cc_redact_forms', __('Redact forms', 'dbvc'), 'scrub_policy', (string) ($defaults['redact_forms'] ?? '1'), $this->path_args('redact_forms')),
            'cc_explorer_default_depth' => Field::integer('cc_explorer_default_depth', __('Explorer default depth', 'dbvc'), 'explorer', (int) ($defaults['explorer_default_depth'] ?? 2), 1, 5, $this->path_args('explorer_default_depth')),
            'cc_explorer_max_nodes' => Field::integer('cc_explorer_max_nodes', __('Explorer max nodes', 'dbvc'), 'explorer', (int) ($defaults['explorer_max_nodes'] ?? 600), 100, 2000, $this->path_args('explorer_max_nodes')),
            'cc_explorer_cache_ttl' => Field::integer('cc_explorer_cache_ttl', __('Explorer cache TTL', 'dbvc'), 'explorer', (int) ($defaults['explorer_cache_ttl'] ?? 300), 30, 3600, $this->path_args('explorer_cache_ttl')),
            'cc_capture_mode' => Field::select('cc_capture_mode', __('Capture mode', 'dbvc'), 'capture', (string) ($defaults['capture_mode'] ?? 'deep'), $this->contract_values(['CAPTURE_MODE_STANDARD', 'CAPTURE_MODE_DEEP'], ['standard', 'deep']), $this->path_args('capture_mode')),
            'cc_capture_include_attribute_context' => Field::bool('cc_capture_include_attribute_context', __('Include attribute context', 'dbvc'), 'capture', (string) ($defaults['capture_include_attribute_context'] ?? '1'), $this->path_args('capture_include_attribute_context')),
            'cc_capture_include_dom_path' => Field::bool('cc_capture_include_dom_path', __('Include DOM path', 'dbvc'), 'capture', (string) ($defaults['capture_include_dom_path'] ?? '1'), $this->path_args('capture_include_dom_path')),
            'cc_capture_max_elements_per_page' => Field::integer('cc_capture_max_elements_per_page', __('Max elements per page', 'dbvc'), 'capture', (int) ($defaults['capture_max_elements_per_page'] ?? 2000), 100, 10000, $this->path_args('capture_max_elements_per_page')),
            'cc_capture_max_chars_per_element' => Field::integer('cc_capture_max_chars_per_element', __('Max characters per element', 'dbvc'), 'capture', (int) ($defaults['capture_max_chars_per_element'] ?? 1000), 100, 4000, $this->path_args('capture_max_chars_per_element')),
            'cc_context_enable_boilerplate_detection' => Field::bool('cc_context_enable_boilerplate_detection', __('Enable boilerplate detection', 'dbvc'), 'capture', (string) ($defaults['context_enable_boilerplate_detection'] ?? '1'), $this->path_args('context_enable_boilerplate_detection')),
            'cc_context_enable_entity_hints' => Field::bool('cc_context_enable_entity_hints', __('Enable entity hints', 'dbvc'), 'capture', (string) ($defaults['context_enable_entity_hints'] ?? '1'), $this->path_args('context_enable_entity_hints')),
            'cc_scrub_policy_enabled' => Field::bool('cc_scrub_policy_enabled', __('Enable scrub policy', 'dbvc'), 'scrub_policy', (string) ($defaults['scrub_policy_enabled'] ?? '1'), $this->path_args('scrub_policy_enabled')),
            'cc_scrub_profile_mode' => Field::select('cc_scrub_profile_mode', __('Scrub profile mode', 'dbvc'), 'scrub_policy', (string) ($defaults['scrub_profile_mode'] ?? 'deterministic-default'), $this->contract_values(['SCRUB_PROFILE_DETERMINISTIC_DEFAULT', 'SCRUB_PROFILE_CUSTOM', 'SCRUB_PROFILE_AI_SUGGESTED_APPROVED'], ['deterministic-default', 'custom', 'ai-suggested-approved']), $this->path_args('scrub_profile_mode')),
            'cc_scrub_attr_action_class' => $this->scrub_action_field('cc_scrub_attr_action_class', __('Scrub class attribute', 'dbvc'), 'scrub_attr_action_class', (string) ($defaults['scrub_attr_action_class'] ?? 'tokenize')),
            'cc_scrub_attr_action_id' => $this->scrub_action_field('cc_scrub_attr_action_id', __('Scrub id attribute', 'dbvc'), 'scrub_attr_action_id', (string) ($defaults['scrub_attr_action_id'] ?? 'hash')),
            'cc_scrub_attr_action_data' => $this->scrub_action_field('cc_scrub_attr_action_data', __('Scrub data attributes', 'dbvc'), 'scrub_attr_action_data', (string) ($defaults['scrub_attr_action_data'] ?? 'tokenize')),
            'cc_scrub_attr_action_style' => $this->scrub_action_field('cc_scrub_attr_action_style', __('Scrub style attribute', 'dbvc'), 'scrub_attr_action_style', (string) ($defaults['scrub_attr_action_style'] ?? 'drop')),
            'cc_scrub_attr_action_aria' => $this->scrub_action_field('cc_scrub_attr_action_aria', __('Scrub ARIA attributes', 'dbvc'), 'scrub_attr_action_aria', (string) ($defaults['scrub_attr_action_aria'] ?? 'keep')),
            'cc_scrub_custom_allowlist' => Field::textarea('cc_scrub_custom_allowlist', __('Scrub custom allowlist', 'dbvc'), 'scrub_policy', '', $this->path_args('scrub_custom_allowlist')),
            'cc_scrub_custom_denylist' => Field::textarea('cc_scrub_custom_denylist', __('Scrub custom denylist', 'dbvc'), 'scrub_policy', '', $this->path_args('scrub_custom_denylist')),
            'cc_scrub_ai_suggestion_enabled' => Field::bool('cc_scrub_ai_suggestion_enabled', __('Enable scrub AI suggestions', 'dbvc'), 'scrub_policy', (string) ($defaults['scrub_ai_suggestion_enabled'] ?? '0'), $this->path_args('scrub_ai_suggestion_enabled')),
            'cc_scrub_preview_sample_size' => Field::integer('cc_scrub_preview_sample_size', __('Scrub preview sample size', 'dbvc'), 'scrub_policy', (int) ($defaults['scrub_preview_sample_size'] ?? 20), 1, 100, $this->path_args('scrub_preview_sample_size')),
            'cc_mapping_catalog_refresh_strategy' => Field::select('cc_mapping_catalog_refresh_strategy', __('Mapping catalog refresh strategy', 'dbvc'), 'mapping_media', (string) ($defaults['dbvc_cc_mapping_catalog_refresh_strategy'] ?? 'reuse-until-fingerprint-change'), $this->contract_values(['MAPPING_CATALOG_REFRESH_REUSE', 'MAPPING_CATALOG_REFRESH_ALWAYS'], ['reuse-until-fingerprint-change', 'always-rebuild']), $this->path_args('dbvc_cc_mapping_catalog_refresh_strategy')),
            'cc_mapping_candidate_confidence_threshold' => Field::decimal('cc_mapping_candidate_confidence_threshold', __('Mapping candidate confidence threshold', 'dbvc'), 'mapping_media', (string) ($defaults['dbvc_cc_mapping_candidate_confidence_threshold'] ?? '0.65'), 0.0, 1.0, $this->path_args('dbvc_cc_mapping_candidate_confidence_threshold')),
            'cc_media_mapping_confidence_threshold' => Field::decimal('cc_media_mapping_confidence_threshold', __('Media mapping confidence threshold', 'dbvc'), 'mapping_media', (string) ($defaults['dbvc_cc_media_mapping_confidence_threshold'] ?? '0.70'), 0.0, 1.0, $this->path_args('dbvc_cc_media_mapping_confidence_threshold')),
            'cc_media_discovery_mode' => Field::select('cc_media_discovery_mode', __('Media discovery mode', 'dbvc'), 'mapping_media', (string) ($defaults['dbvc_cc_media_discovery_mode'] ?? 'metadata-first'), $this->contract_values(['MEDIA_DISCOVERY_MODE_METADATA_FIRST', 'MEDIA_DISCOVERY_MODE_SELECTIVE_DOWNLOAD'], ['metadata-first', 'selective-download']), $this->path_args('dbvc_cc_media_discovery_mode')),
            'cc_media_download_policy' => Field::select('cc_media_download_policy', __('Media download policy', 'dbvc'), 'mapping_media', (string) ($defaults['dbvc_cc_media_download_policy'] ?? 'remote_only'), $this->contract_values(['MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY', 'MEDIA_DOWNLOAD_POLICY_DOWNLOAD_SELECTED', 'MEDIA_DOWNLOAD_POLICY_SKIP'], ['remote_only', 'download_selected', 'skip']), $this->path_args('dbvc_cc_media_download_policy')),
            'cc_media_mime_allowlist' => Field::textarea('cc_media_mime_allowlist', __('Media MIME allowlist', 'dbvc'), 'mapping_media', (string) ($defaults['dbvc_cc_media_mime_allowlist'] ?? ''), $this->path_args('dbvc_cc_media_mime_allowlist')),
            'cc_media_max_bytes_per_asset' => Field::integer('cc_media_max_bytes_per_asset', __('Media max bytes per asset', 'dbvc'), 'mapping_media', (int) ($defaults['dbvc_cc_media_max_bytes_per_asset'] ?? 8388608), 10240, 104857600, $this->path_args('dbvc_cc_media_max_bytes_per_asset')),
            'cc_media_preview_thumbnail_enabled' => Field::bool('cc_media_preview_thumbnail_enabled', __('Enable media preview thumbnails', 'dbvc'), 'mapping_media', (string) ($defaults['dbvc_cc_media_preview_thumbnail_enabled'] ?? '1'), $this->path_args('dbvc_cc_media_preview_thumbnail_enabled')),
            'cc_media_block_private_hosts' => Field::bool('cc_media_block_private_hosts', __('Block private media hosts', 'dbvc'), 'mapping_media', (string) ($defaults['dbvc_cc_media_block_private_hosts'] ?? '1'), $this->path_args('dbvc_cc_media_block_private_hosts')),
            'cc_media_source_domain_allowlist' => Field::textarea('cc_media_source_domain_allowlist', __('Media source domain allowlist', 'dbvc'), 'mapping_media', '', $this->path_args('dbvc_cc_media_source_domain_allowlist')),
            'cc_media_source_domain_denylist' => Field::textarea('cc_media_source_domain_denylist', __('Media source domain denylist', 'dbvc'), 'mapping_media', '', $this->path_args('dbvc_cc_media_source_domain_denylist')),
        ];
    }

    protected function get_option_key(): string
    {
        return class_exists('DBVC_CC_Contracts') ? \DBVC_CC_Contracts::OPTION_SETTINGS : 'dbvc_cc_settings';
    }

    protected function get_default_option_value(): array
    {
        return class_exists('DBVC_CC_Contracts') ? \DBVC_CC_Contracts::get_settings_defaults() : [];
    }

    protected function get_option_array(): array
    {
        if (class_exists('DBVC_CC_Settings_Service')) {
            return \DBVC_CC_Settings_Service::get_options();
        }

        return parent::get_option_array();
    }

    protected function sanitize_option_array(array $candidate, array $current): array
    {
        unset($current);

        if (class_exists('DBVC_CC_Settings_Service')) {
            return \DBVC_CC_Settings_Service::sanitize_settings($candidate);
        }

        return $candidate;
    }

    /**
     * @param string               $field
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    private function path_args($field, array $args = []): array
    {
        return array_merge(['option_path' => [$field]], $args);
    }

    /**
     * @param string $key
     * @param string $label
     * @param string $option_key
     * @param string $default
     * @return array<string, mixed>
     */
    private function scrub_action_field($key, $label, $option_key, $default): array
    {
        return Field::select(
            $key,
            $label,
            'scrub_policy',
            $default,
            $this->contract_values(['SCRUB_ACTION_KEEP', 'SCRUB_ACTION_DROP', 'SCRUB_ACTION_HASH', 'SCRUB_ACTION_TOKENIZE'], ['keep', 'drop', 'hash', 'tokenize']),
            $this->path_args($option_key)
        );
    }

    /**
     * @param array<int, string> $constant_names
     * @param array<int, string> $fallback
     * @return array<int, string>
     */
    private function contract_values(array $constant_names, array $fallback): array
    {
        if (! class_exists('DBVC_CC_Contracts')) {
            return $fallback;
        }

        $values = [];
        foreach ($constant_names as $constant_name) {
            $full_name = 'DBVC_CC_Contracts::' . $constant_name;
            if (defined($full_name)) {
                $values[] = constant($full_name);
            }
        }

        return ! empty($values) ? $values : $fallback;
    }
}
