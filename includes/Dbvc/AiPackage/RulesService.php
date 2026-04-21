<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class RulesService
{
    /**
     * Build the normalized validation-rules artifact for sample generation and AI intake.
     *
     * @param array<string,mixed> $schema_bundle
     * @return array<string,mixed>
     */
    public static function build_validation_rules(array $schema_bundle = []): array
    {
        $settings = Settings::get_all_settings();
        $validation = isset($settings['validation']) && is_array($settings['validation']) ? $settings['validation'] : [];
        $rules = isset($settings['rules']) && is_array($settings['rules']) ? $settings['rules'] : [];

        return [
            'generated_at' => current_time('c'),
            'package_schema_version' => 1,
            'shape_mode' => isset($schema_bundle['shape_mode']) ? (string) $schema_bundle['shape_mode'] : (string) ($settings['generation']['shape_mode'] ?? 'conservative'),
            'validation_defaults' => [
                'warning_policy' => isset($validation['warning_policy']) ? (string) $validation['warning_policy'] : 'confirm',
                'package_mode' => isset($validation['package_mode']) ? (string) $validation['package_mode'] : 'create_and_update',
                'strictness' => isset($validation['strictness']) ? (string) $validation['strictness'] : 'standard',
            ],
            'states' => [
                'valid' => __('Package may proceed to translation/import.', 'dbvc'),
                'valid_with_warnings' => __('Package may proceed only after operator review or explicit warning policy acceptance.', 'dbvc'),
                'blocked' => __('Package must not proceed to translation/import.', 'dbvc'),
            ],
            'post_contract' => [
                'required_fields' => ['ID', 'post_type', 'post_title', 'post_name'],
                'optional_fields' => ['post_status', 'post_content', 'post_excerpt', 'post_date', 'post_date_gmt', 'post_parent', 'menu_order', 'post_author', 'post_password', 'comment_status', 'ping_status', 'meta', 'tax_input'],
                'create_marker' => ['field' => 'ID', 'value' => 0],
                'update_precedence' => ['vf_object_uid', 'slug', 'ID'],
                'conditional_identity_fields' => ['vf_object_uid'],
                'blocked_top_level_fields' => [],
                'blocked_meta_keys' => ['dbvc_post_history', '_dbvc_import_hash'],
            ],
            'term_contract' => [
                'required_fields' => ['term_id', 'taxonomy', 'name', 'slug'],
                'optional_fields' => ['description', 'parent', 'parent_slug', 'meta'],
                'create_marker' => ['field' => 'term_id', 'value' => 0],
                'update_precedence' => ['vf_object_uid', 'slug', 'term_id'],
                'conditional_identity_fields' => ['vf_object_uid'],
                'blocked_top_level_fields' => ['parent_uid'],
                'blocked_meta_keys' => ['dbvc_term_history'],
            ],
            'reference_contract' => [
                'post_relationships' => 'Prefer structured slug refs with `post_type` and `slug`.',
                'taxonomy_relationships' => 'Prefer structured slug refs with `taxonomy` and `slug`.',
                'taxonomy_assignment' => 'Prefer slug strings or structured term refs instead of numeric IDs.',
                'parents' => 'Prefer slug-based parent refs where supported by the package contract.',
            ],
            'acf_contract' => [
                'logical_representation' => [
                    'group' => 'object',
                    'repeater' => 'array_of_row_objects',
                    'flexible_content' => 'array_of_layout_objects_with_acf_fc_layout',
                    'clone' => 'expanded_logically_from_clone_targets',
                ],
                'blocked_storage_keys' => [
                    'underscore_reference_meta' => true,
                ],
                'nonempty_unsupported_complex_policy' => 'block',
                'deferred_media_families' => ['image', 'file', 'gallery'],
                'deferred_media_nonempty_policy' => 'block',
            ],
            'user_rules' => $rules,
        ];
    }
}
