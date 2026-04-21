<?php

namespace Dbvc\AiPackage;

if (! defined('WPINC')) {
    die;
}

final class SiteFingerprintService
{
    private const PACKAGE_SCHEMA_VERSION = 1;

    /**
     * Build the current schema fingerprint and include the resolved schema bundle.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    public static function build_current_context(array $args = []): array
    {
        $schema_bundle = SchemaDiscoveryService::build_schema_bundle($args);
        $fingerprint = self::build_from_schema_bundle($schema_bundle);
        $fingerprint['schema_bundle'] = $schema_bundle;

        return $fingerprint;
    }

    /**
     * Build the site fingerprint from a resolved schema bundle.
     *
     * @param array<string,mixed> $schema_bundle
     * @return array<string,mixed>
     */
    public static function build_from_schema_bundle(array $schema_bundle): array
    {
        $settings = Settings::get_all_settings();
        $rules = isset($settings['rules']) && is_array($settings['rules']) ? $settings['rules'] : [];
        $object_inventory = isset($schema_bundle['object_inventory']) && is_array($schema_bundle['object_inventory']) ? $schema_bundle['object_inventory'] : [];
        $field_catalog = isset($schema_bundle['field_catalog']) && is_array($schema_bundle['field_catalog']) ? $schema_bundle['field_catalog'] : [];

        $components = [
            'post_types' => hash('sha256', (string) wp_json_encode(self::normalize_for_hash($object_inventory['post_types'] ?? []))),
            'taxonomies' => hash('sha256', (string) wp_json_encode(self::normalize_for_hash($object_inventory['taxonomies'] ?? []))),
            'core_fields' => hash(
                'sha256',
                (string) wp_json_encode(
                    self::normalize_for_hash(
                        [
                            'post' => SchemaDiscoveryService::get_post_core_field_catalog(),
                            'term' => SchemaDiscoveryService::get_term_core_field_catalog(),
                        ]
                    )
                )
            ),
            'registered_meta' => hash('sha256', (string) wp_json_encode(self::normalize_for_hash(self::extract_registered_meta_catalog($field_catalog)))),
            'acf_fields' => hash('sha256', (string) wp_json_encode(self::normalize_for_hash(self::extract_acf_catalog($field_catalog)))),
            'rules' => hash('sha256', (string) wp_json_encode(self::normalize_for_hash($rules))),
            'package_schema_version' => (string) self::PACKAGE_SCHEMA_VERSION,
        ];

        $site_fingerprint = hash('sha256', (string) wp_json_encode(self::normalize_for_hash($components)));

        return [
            'site_fingerprint' => $site_fingerprint,
            'components' => $components,
            'origin' => [
                'home_url' => trailingslashit(home_url('/')),
                'site_name' => (string) get_bloginfo('name'),
                'blog_id' => function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $field_catalog
     * @return array<string,mixed>
     */
    private static function extract_registered_meta_catalog(array $field_catalog): array
    {
        return [
            'post_types' => self::extract_catalog_branch($field_catalog, 'post_types', 'registered_meta'),
            'taxonomies' => self::extract_catalog_branch($field_catalog, 'taxonomies', 'registered_meta'),
        ];
    }

    /**
     * @param array<string,mixed> $field_catalog
     * @return array<string,mixed>
     */
    private static function extract_acf_catalog(array $field_catalog): array
    {
        return [
            'post_types' => self::extract_catalog_branch($field_catalog, 'post_types', 'acf'),
            'taxonomies' => self::extract_catalog_branch($field_catalog, 'taxonomies', 'acf'),
        ];
    }

    /**
     * @param array<string,mixed> $field_catalog
     * @param string              $branch
     * @param string              $key
     * @return array<string,mixed>
     */
    private static function extract_catalog_branch(array $field_catalog, string $branch, string $key): array
    {
        $entries = isset($field_catalog[$branch]) && is_array($field_catalog[$branch]) ? $field_catalog[$branch] : [];
        $extracted = [];
        foreach ($entries as $object_key => $entry) {
            if (! is_array($entry) || ! isset($entry[$key])) {
                continue;
            }

            $extracted[(string) $object_key] = $entry[$key];
        }

        ksort($extracted);
        return $extracted;
    }

    /**
     * Recursively sort hash inputs so signatures remain stable.
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalize_for_hash($value)
    {
        if (is_array($value)) {
            $keys = array_keys($value);
            $is_list = $keys === array_keys($keys);
            if ($is_list) {
                $normalized = [];
                foreach ($value as $item) {
                    $normalized[] = self::normalize_for_hash($item);
                }

                return $normalized;
            }

            ksort($value);
            foreach ($value as $key => $item) {
                $value[$key] = self::normalize_for_hash($item);
            }

            return $value;
        }

        if (is_object($value)) {
            return self::normalize_for_hash((array) $value);
        }

        return $value;
    }
}
