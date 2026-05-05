<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class CompactSchemaBuilder
{
    /**
     * @param array<string,mixed> $schema_bundle
     * @param array<string,mixed> $fingerprint
     * @param array<string,mixed> $validation_rules
     * @return array<string,mixed>
     */
    public static function build(array $schema_bundle, array $fingerprint, array $validation_rules): array
    {
        $selection = isset($schema_bundle['selection']) && is_array($schema_bundle['selection']) ? $schema_bundle['selection'] : [
            'post_types' => [],
            'taxonomies' => [],
        ];
        $object_inventory = isset($schema_bundle['object_inventory']) && is_array($schema_bundle['object_inventory']) ? $schema_bundle['object_inventory'] : [];
        $field_catalog = isset($schema_bundle['field_catalog']) && is_array($schema_bundle['field_catalog']) ? $field_catalog = $schema_bundle['field_catalog'] : [];

        return [
            'package_schema_version' => 1,
            'generated_at' => isset($schema_bundle['generated_at']) ? (string) $schema_bundle['generated_at'] : current_time('c'),
            'site_fingerprint' => isset($fingerprint['site_fingerprint']) ? (string) $fingerprint['site_fingerprint'] : '',
            'selection' => $selection,
            'return_contract' => [
                'package_type' => 'dbvc_ai_submission_package',
                'package_schema_version' => 1,
                'required_paths' => [
                    'dbvc-ai-manifest.json',
                    'entities/posts/{post_type}/{slug}.json',
                    'entities/terms/{taxonomy}/{slug}.json',
                ],
                'optional_paths' => [
                    'docs/NOTES.md',
                    'reports/generation-summary.md',
                ],
            ],
            'validation_defaults' => isset($validation_rules['validation_defaults']) && is_array($validation_rules['validation_defaults'])
                ? $validation_rules['validation_defaults']
                : [],
            'objects' => self::build_objects($selection, $object_inventory, $field_catalog),
        ];
    }

    /**
     * @param array<string,mixed> $selection
     * @param array<string,mixed> $object_inventory
     * @param array<string,mixed> $field_catalog
     * @return array<string,mixed>
     */
    private static function build_objects(array $selection, array $object_inventory, array $field_catalog): array
    {
        $objects = [];
        $post_inventory = isset($object_inventory['post_types']) && is_array($object_inventory['post_types']) ? $object_inventory['post_types'] : [];
        $term_inventory = isset($object_inventory['taxonomies']) && is_array($object_inventory['taxonomies']) ? $object_inventory['taxonomies'] : [];
        $post_catalog = isset($field_catalog['post_types']) && is_array($field_catalog['post_types']) ? $field_catalog['post_types'] : [];
        $term_catalog = isset($field_catalog['taxonomies']) && is_array($field_catalog['taxonomies']) ? $field_catalog['taxonomies'] : [];

        foreach ((array) ($selection['post_types'] ?? []) as $post_type) {
            $post_type = sanitize_key((string) $post_type);
            if ($post_type === '') {
                continue;
            }

            $objects[$post_type] = [
                'entity_kind' => 'post',
                'inventory' => isset($post_inventory[$post_type]) && is_array($post_inventory[$post_type]) ? $post_inventory[$post_type] : [],
                'field_catalog' => isset($post_catalog[$post_type]) && is_array($post_catalog[$post_type]) ? $post_catalog[$post_type] : [],
            ];
        }

        foreach ((array) ($selection['taxonomies'] ?? []) as $taxonomy) {
            $taxonomy = sanitize_key((string) $taxonomy);
            if ($taxonomy === '') {
                continue;
            }

            $objects[$taxonomy] = [
                'entity_kind' => 'term',
                'inventory' => isset($term_inventory[$taxonomy]) && is_array($term_inventory[$taxonomy]) ? $term_inventory[$taxonomy] : [],
                'field_catalog' => isset($term_catalog[$taxonomy]) && is_array($term_catalog[$taxonomy]) ? $term_catalog[$taxonomy] : [],
            ];
        }

        ksort($objects);

        return $objects;
    }
}
