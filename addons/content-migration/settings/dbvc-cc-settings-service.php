<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Settings_Service
{
    /**
     * @return void
     */
    public static function bootstrap()
    {
        self::maybe_migrate_legacy_settings();
    }

    /**
     * @return void
     */
    public static function register_settings()
    {
        register_setting(
            DBVC_CC_Contracts::SETTINGS_GROUP,
            DBVC_CC_Contracts::OPTION_SETTINGS,
            [self::class, 'sanitize_settings']
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function get_options()
    {
        $defaults = DBVC_CC_Contracts::get_settings_defaults();
        $stored = get_option(DBVC_CC_Contracts::OPTION_SETTINGS, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        return wp_parse_args($stored, $defaults);
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    public static function sanitize_settings($input)
    {
        $options = self::get_options();
        $input = is_array($input) ? $input : [];

        $sanitized = [];
        $sanitized['storage_path'] = isset($input['storage_path']) ? sanitize_file_name((string) $input['storage_path']) : (string) $options['storage_path'];
        if ($sanitized['storage_path'] === '') {
            $sanitized['storage_path'] = DBVC_CC_Contracts::STORAGE_DEFAULT_PATH;
        }

        $sanitized['request_delay'] = isset($input['request_delay']) ? max(0, absint($input['request_delay'])) : (int) $options['request_delay'];
        $sanitized['request_timeout'] = isset($input['request_timeout']) ? max(1, absint($input['request_timeout'])) : (int) $options['request_timeout'];

        $sanitized['user_agent'] = isset($input['user_agent']) ? sanitize_text_field((string) $input['user_agent']) : (string) $options['user_agent'];
        if ($sanitized['user_agent'] === '') {
            $sanitized['user_agent'] = (string) DBVC_CC_Contracts::get_settings_defaults()['user_agent'];
        }

        $sanitized['exclude_selectors'] = isset($input['exclude_selectors']) ? sanitize_textarea_field((string) $input['exclude_selectors']) : (string) $options['exclude_selectors'];
        $sanitized['focus_selectors'] = isset($input['focus_selectors']) ? sanitize_textarea_field((string) $input['focus_selectors']) : (string) $options['focus_selectors'];

        $sanitized['dev_mode'] = self::sanitize_checkbox($input, 'dev_mode', $options);
        $sanitized['dev_storage_subdir'] = isset($input['dev_storage_subdir']) ? sanitize_file_name((string) $input['dev_storage_subdir']) : (string) $options['dev_storage_subdir'];
        if ($sanitized['dev_storage_subdir'] === '') {
            $sanitized['dev_storage_subdir'] = 'dev-data';
        }

        $sanitized['prompt_version'] = isset($input['prompt_version']) ? sanitize_text_field((string) $input['prompt_version']) : (string) $options['prompt_version'];
        if ($sanitized['prompt_version'] === '') {
            $sanitized['prompt_version'] = 'v1';
        }

        $sanitized['ai_fallback_mode'] = self::sanitize_checkbox($input, 'ai_fallback_mode', $options);
        $sanitized['openai_api_key'] = isset($input['openai_api_key']) ? sanitize_text_field(trim((string) $input['openai_api_key'])) : (string) $options['openai_api_key'];
        $sanitized['openai_model'] = isset($input['openai_model']) ? sanitize_text_field(trim((string) $input['openai_model'])) : (string) $options['openai_model'];
        if ($sanitized['openai_model'] === '') {
            $sanitized['openai_model'] = 'gpt-4o-mini';
        }
        $sanitized['ai_request_timeout'] = isset($input['ai_request_timeout']) ? max(30, min(180, absint($input['ai_request_timeout']))) : (int) $options['ai_request_timeout'];

        $allowed_slug_policies = ['append-path-hash', 'append-increment', 'skip'];
        $slug_policy = isset($input['slug_collision_policy']) ? sanitize_key((string) $input['slug_collision_policy']) : (string) $options['slug_collision_policy'];
        $sanitized['slug_collision_policy'] = in_array($slug_policy, $allowed_slug_policies, true) ? $slug_policy : (string) $options['slug_collision_policy'];

        $allowed_taxonomy_policies = ['match-existing-review', 'match-or-create', 'skip-unmatched'];
        $taxonomy_policy = isset($input['taxonomy_collision_policy']) ? sanitize_key((string) $input['taxonomy_collision_policy']) : (string) $options['taxonomy_collision_policy'];
        $sanitized['taxonomy_collision_policy'] = in_array($taxonomy_policy, $allowed_taxonomy_policies, true) ? $taxonomy_policy : (string) $options['taxonomy_collision_policy'];

        $sanitized['redact_emails'] = self::sanitize_checkbox($input, 'redact_emails', $options);
        $sanitized['redact_phones'] = self::sanitize_checkbox($input, 'redact_phones', $options);
        $sanitized['redact_forms'] = self::sanitize_checkbox($input, 'redact_forms', $options);
        $sanitized['explorer_default_depth'] = isset($input['explorer_default_depth']) ? max(1, min(5, absint($input['explorer_default_depth']))) : (int) $options['explorer_default_depth'];
        $sanitized['explorer_max_nodes'] = isset($input['explorer_max_nodes']) ? max(100, min(2000, absint($input['explorer_max_nodes']))) : (int) $options['explorer_max_nodes'];
        $sanitized['explorer_cache_ttl'] = isset($input['explorer_cache_ttl']) ? max(30, min(3600, absint($input['explorer_cache_ttl']))) : (int) $options['explorer_cache_ttl'];

        $allowed_capture_modes = [DBVC_CC_Contracts::CAPTURE_MODE_STANDARD, DBVC_CC_Contracts::CAPTURE_MODE_DEEP];
        $capture_mode = isset($input['capture_mode']) ? sanitize_key((string) $input['capture_mode']) : (string) $options['capture_mode'];
        $sanitized['capture_mode'] = in_array($capture_mode, $allowed_capture_modes, true) ? $capture_mode : DBVC_CC_Contracts::CAPTURE_MODE_DEEP;
        $sanitized['capture_include_attribute_context'] = self::sanitize_checkbox($input, 'capture_include_attribute_context', $options);
        $sanitized['capture_include_dom_path'] = self::sanitize_checkbox($input, 'capture_include_dom_path', $options);
        $sanitized['capture_max_elements_per_page'] = isset($input['capture_max_elements_per_page']) ? max(100, min(10000, absint($input['capture_max_elements_per_page']))) : (int) $options['capture_max_elements_per_page'];
        $sanitized['capture_max_chars_per_element'] = isset($input['capture_max_chars_per_element']) ? max(100, min(4000, absint($input['capture_max_chars_per_element']))) : (int) $options['capture_max_chars_per_element'];
        $sanitized['context_enable_boilerplate_detection'] = self::sanitize_checkbox($input, 'context_enable_boilerplate_detection', $options);
        $sanitized['context_enable_entity_hints'] = self::sanitize_checkbox($input, 'context_enable_entity_hints', $options);
        $sanitized['ai_enable_section_typing'] = self::sanitize_checkbox($input, 'ai_enable_section_typing', $options);
        $sanitized['ai_section_typing_confidence_threshold'] = self::sanitize_float_between($input, 'ai_section_typing_confidence_threshold', $options, 0.0, 1.0);

        $allowed_scrub_profiles = [
            DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT,
            DBVC_CC_Contracts::SCRUB_PROFILE_CUSTOM,
            DBVC_CC_Contracts::SCRUB_PROFILE_AI_SUGGESTED_APPROVED,
        ];
        $allowed_scrub_actions = [
            DBVC_CC_Contracts::SCRUB_ACTION_KEEP,
            DBVC_CC_Contracts::SCRUB_ACTION_DROP,
            DBVC_CC_Contracts::SCRUB_ACTION_HASH,
            DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE,
        ];

        $scrub_profile_mode = isset($input['scrub_profile_mode']) ? sanitize_key((string) $input['scrub_profile_mode']) : (string) $options['scrub_profile_mode'];
        $sanitized['scrub_policy_enabled'] = self::sanitize_checkbox($input, 'scrub_policy_enabled', $options);
        $sanitized['scrub_profile_mode'] = in_array($scrub_profile_mode, $allowed_scrub_profiles, true) ? $scrub_profile_mode : DBVC_CC_Contracts::SCRUB_PROFILE_DETERMINISTIC_DEFAULT;

        $scrub_attr_action_class = isset($input['scrub_attr_action_class']) ? sanitize_key((string) $input['scrub_attr_action_class']) : (string) $options['scrub_attr_action_class'];
        $scrub_attr_action_id = isset($input['scrub_attr_action_id']) ? sanitize_key((string) $input['scrub_attr_action_id']) : (string) $options['scrub_attr_action_id'];
        $scrub_attr_action_data = isset($input['scrub_attr_action_data']) ? sanitize_key((string) $input['scrub_attr_action_data']) : (string) $options['scrub_attr_action_data'];
        $scrub_attr_action_style = isset($input['scrub_attr_action_style']) ? sanitize_key((string) $input['scrub_attr_action_style']) : (string) $options['scrub_attr_action_style'];
        $scrub_attr_action_aria = isset($input['scrub_attr_action_aria']) ? sanitize_key((string) $input['scrub_attr_action_aria']) : (string) $options['scrub_attr_action_aria'];

        $sanitized['scrub_attr_action_class'] = in_array($scrub_attr_action_class, $allowed_scrub_actions, true) ? $scrub_attr_action_class : DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE;
        $sanitized['scrub_attr_action_id'] = in_array($scrub_attr_action_id, $allowed_scrub_actions, true) ? $scrub_attr_action_id : DBVC_CC_Contracts::SCRUB_ACTION_HASH;
        $sanitized['scrub_attr_action_data'] = in_array($scrub_attr_action_data, $allowed_scrub_actions, true) ? $scrub_attr_action_data : DBVC_CC_Contracts::SCRUB_ACTION_TOKENIZE;
        $sanitized['scrub_attr_action_style'] = in_array($scrub_attr_action_style, $allowed_scrub_actions, true) ? $scrub_attr_action_style : DBVC_CC_Contracts::SCRUB_ACTION_DROP;
        $sanitized['scrub_attr_action_aria'] = in_array($scrub_attr_action_aria, $allowed_scrub_actions, true) ? $scrub_attr_action_aria : DBVC_CC_Contracts::SCRUB_ACTION_KEEP;

        $sanitized['scrub_custom_allowlist'] = isset($input['scrub_custom_allowlist']) ? sanitize_textarea_field((string) $input['scrub_custom_allowlist']) : (string) $options['scrub_custom_allowlist'];
        $sanitized['scrub_custom_denylist'] = isset($input['scrub_custom_denylist']) ? sanitize_textarea_field((string) $input['scrub_custom_denylist']) : (string) $options['scrub_custom_denylist'];
        $sanitized['scrub_ai_suggestion_enabled'] = self::sanitize_checkbox($input, 'scrub_ai_suggestion_enabled', $options);
        $sanitized['scrub_preview_sample_size'] = isset($input['scrub_preview_sample_size']) ? max(1, min(100, absint($input['scrub_preview_sample_size']))) : (int) $options['scrub_preview_sample_size'];

        $allowed_refresh_strategies = [
            DBVC_CC_Contracts::MAPPING_CATALOG_REFRESH_REUSE,
            DBVC_CC_Contracts::MAPPING_CATALOG_REFRESH_ALWAYS,
        ];
        $mapping_refresh_strategy = isset($input['dbvc_cc_mapping_catalog_refresh_strategy']) ? sanitize_key((string) $input['dbvc_cc_mapping_catalog_refresh_strategy']) : (string) $options['dbvc_cc_mapping_catalog_refresh_strategy'];
        $sanitized['dbvc_cc_mapping_catalog_refresh_strategy'] = in_array($mapping_refresh_strategy, $allowed_refresh_strategies, true)
            ? $mapping_refresh_strategy
            : DBVC_CC_Contracts::MAPPING_CATALOG_REFRESH_REUSE;
        $sanitized['dbvc_cc_mapping_candidate_confidence_threshold'] = self::sanitize_float_between($input, 'dbvc_cc_mapping_candidate_confidence_threshold', $options, 0.0, 1.0);
        $sanitized['dbvc_cc_media_mapping_confidence_threshold'] = self::sanitize_float_between($input, 'dbvc_cc_media_mapping_confidence_threshold', $options, 0.0, 1.0);

        $allowed_media_discovery_modes = [
            DBVC_CC_Contracts::MEDIA_DISCOVERY_MODE_METADATA_FIRST,
            DBVC_CC_Contracts::MEDIA_DISCOVERY_MODE_SELECTIVE_DOWNLOAD,
        ];
        $media_discovery_mode = isset($input['dbvc_cc_media_discovery_mode']) ? sanitize_key((string) $input['dbvc_cc_media_discovery_mode']) : (string) $options['dbvc_cc_media_discovery_mode'];
        $sanitized['dbvc_cc_media_discovery_mode'] = in_array($media_discovery_mode, $allowed_media_discovery_modes, true)
            ? $media_discovery_mode
            : DBVC_CC_Contracts::MEDIA_DISCOVERY_MODE_METADATA_FIRST;

        $allowed_media_download_policies = [
            DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY,
            DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_DOWNLOAD_SELECTED,
            DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_SKIP,
        ];
        $media_download_policy = isset($input['dbvc_cc_media_download_policy']) ? sanitize_key((string) $input['dbvc_cc_media_download_policy']) : (string) $options['dbvc_cc_media_download_policy'];
        $sanitized['dbvc_cc_media_download_policy'] = in_array($media_download_policy, $allowed_media_download_policies, true)
            ? $media_download_policy
            : DBVC_CC_Contracts::MEDIA_DOWNLOAD_POLICY_REMOTE_ONLY;

        $sanitized['dbvc_cc_media_mime_allowlist'] = self::sanitize_mime_allowlist($input, 'dbvc_cc_media_mime_allowlist', $options);
        $sanitized['dbvc_cc_media_max_bytes_per_asset'] = isset($input['dbvc_cc_media_max_bytes_per_asset'])
            ? max(10240, min(104857600, absint($input['dbvc_cc_media_max_bytes_per_asset'])))
            : (int) $options['dbvc_cc_media_max_bytes_per_asset'];
        $sanitized['dbvc_cc_media_preview_thumbnail_enabled'] = self::sanitize_checkbox($input, 'dbvc_cc_media_preview_thumbnail_enabled', $options);
        $sanitized['dbvc_cc_media_block_private_hosts'] = self::sanitize_checkbox($input, 'dbvc_cc_media_block_private_hosts', $options);
        $sanitized['dbvc_cc_media_source_domain_allowlist'] = isset($input['dbvc_cc_media_source_domain_allowlist']) ? sanitize_textarea_field((string) $input['dbvc_cc_media_source_domain_allowlist']) : (string) $options['dbvc_cc_media_source_domain_allowlist'];
        $sanitized['dbvc_cc_media_source_domain_denylist'] = isset($input['dbvc_cc_media_source_domain_denylist']) ? sanitize_textarea_field((string) $input['dbvc_cc_media_source_domain_denylist']) : (string) $options['dbvc_cc_media_source_domain_denylist'];

        return $sanitized;
    }

    /**
     * @return void
     */
    private static function maybe_migrate_legacy_settings()
    {
        $existing = get_option(DBVC_CC_Contracts::OPTION_SETTINGS, null);
        if (is_array($existing) && ! empty($existing)) {
            return;
        }

        $legacy = get_option('content_collector_settings', null);
        if (! is_array($legacy)) {
            return;
        }

        $migrated = self::sanitize_settings($legacy);
        update_option(DBVC_CC_Contracts::OPTION_SETTINGS, $migrated);
    }

    /**
     * @param array<string, mixed> $input
     * @param string $key
     * @param array<string, mixed> $options
     * @return int
     */
    private static function sanitize_checkbox(array $input, $key, array $options)
    {
        if (array_key_exists($key, $input)) {
            return ! empty($input[$key]) ? 1 : 0;
        }

        return ! empty($options[$key]) ? 1 : 0;
    }

    /**
     * @param array<string, mixed> $input
     * @param string $key
     * @param array<string, mixed> $options
     * @param float $min
     * @param float $max
     * @return float
     */
    private static function sanitize_float_between(array $input, $key, array $options, $min, $max)
    {
        $fallback = isset($options[$key]) ? (float) $options[$key] : $min;

        if (! array_key_exists($key, $input)) {
            return $fallback;
        }

        $raw_value = is_scalar($input[$key]) ? (string) $input[$key] : '';
        if ($raw_value === '' || ! is_numeric($raw_value)) {
            return $fallback;
        }

        $value = (float) $raw_value;
        if ($value < $min) {
            return $min;
        }
        if ($value > $max) {
            return $max;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $input
     * @param string $key
     * @param array<string, mixed> $options
     * @return string
     */
    private static function sanitize_mime_allowlist(array $input, $key, array $options)
    {
        $fallback = isset($options[$key]) ? (string) $options[$key] : '';
        $raw = array_key_exists($key, $input) ? (string) $input[$key] : $fallback;
        $raw = strtolower($raw);
        if ($raw === '') {
            return $fallback;
        }

        $tokens = preg_split('/[\s,]+/', $raw);
        if (! is_array($tokens)) {
            return $fallback;
        }

        $allowed_top_types = ['image', 'video', 'audio', 'application'];
        $allowlist = [];
        foreach ($tokens as $token) {
            $mime = trim((string) $token);
            if ($mime === '') {
                continue;
            }
            if (! preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+*-]+$/', $mime)) {
                continue;
            }
            $parts = explode('/', $mime, 2);
            $top_type = isset($parts[0]) ? $parts[0] : '';
            if (! in_array($top_type, $allowed_top_types, true)) {
                continue;
            }

            $allowlist[$mime] = true;
        }

        if (empty($allowlist)) {
            return $fallback;
        }

        return implode(',', array_keys($allowlist));
    }
}
