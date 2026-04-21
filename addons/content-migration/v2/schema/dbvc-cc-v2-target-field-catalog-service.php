<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Target_Field_Catalog_Service
{
    /**
     * @var DBVC_CC_V2_Target_Field_Catalog_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Target_Field_Catalog_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @param string $domain
     * @param bool   $force_rebuild
     * @return array<string, mixed>|WP_Error
     */
    public function build_catalog($domain, $force_rebuild = false)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $inventory_result = DBVC_CC_V2_Target_Object_Inventory_Service::get_instance()->build_inventory($context['domain'], $force_rebuild);
        if (is_wp_error($inventory_result)) {
            return $inventory_result;
        }

        $legacy_result = DBVC_CC_Target_Field_Catalog_Service::get_instance()->build_catalog($context['domain'], $force_rebuild);
        if (is_wp_error($legacy_result)) {
            return $legacy_result;
        }

        $legacy_catalog = isset($legacy_result['catalog']) && is_array($legacy_result['catalog']) ? $legacy_result['catalog'] : [];
        $catalog_payload = $this->build_v2_catalog_payload($context, $inventory_result, $legacy_result, $legacy_catalog);

        $existing = $this->read_json_file($context['target_field_catalog_file']);
        if (
            ! $force_rebuild
            && is_array($existing)
            && isset($existing['catalog_fingerprint'])
            && (string) $existing['catalog_fingerprint'] === $catalog_payload['catalog_fingerprint']
        ) {
            return [
                'status' => 'reused',
                'domain' => $context['domain'],
                'generated_at' => isset($existing['generated_at']) ? (string) $existing['generated_at'] : '',
                'inventory_fingerprint' => $catalog_payload['inventory_fingerprint'],
                'catalog_fingerprint' => $catalog_payload['catalog_fingerprint'],
                'catalog_file' => $context['target_field_catalog_file'],
                'artifact_relative_path' => $this->get_domain_relative_path($context['target_field_catalog_file'], $context['domain_dir']),
                'source_snapshot_hash' => isset($catalog_payload['source_artifacts']['schema_snapshot_hash']) ? (string) $catalog_payload['source_artifacts']['schema_snapshot_hash'] : '',
                'catalog' => $existing,
            ];
        }

        if (! DBVC_CC_Artifact_Manager::write_json_file($context['target_field_catalog_file'], $catalog_payload)) {
            return new WP_Error(
                'dbvc_cc_v2_catalog_write_failed',
                __('Could not write the V2 target field catalog artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        return [
            'status' => 'built',
            'domain' => $context['domain'],
            'generated_at' => $catalog_payload['generated_at'],
            'inventory_fingerprint' => $catalog_payload['inventory_fingerprint'],
            'catalog_fingerprint' => $catalog_payload['catalog_fingerprint'],
            'catalog_file' => $context['target_field_catalog_file'],
            'artifact_relative_path' => $this->get_domain_relative_path($context['target_field_catalog_file'], $context['domain_dir']),
            'source_snapshot_hash' => isset($catalog_payload['source_artifacts']['schema_snapshot_hash']) ? (string) $catalog_payload['source_artifacts']['schema_snapshot_hash'] : '',
            'catalog' => $catalog_payload,
        ];
    }

    /**
     * @param string $domain
     * @param bool   $build_if_missing
     * @return array<string, mixed>|WP_Error
     */
    public function get_catalog($domain, $build_if_missing = true)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $existing = $this->read_json_file($context['target_field_catalog_file']);
        if (is_array($existing)) {
            return [
                'status' => 'loaded',
                'domain' => $context['domain'],
                'generated_at' => isset($existing['generated_at']) ? (string) $existing['generated_at'] : '',
                'inventory_fingerprint' => isset($existing['inventory_fingerprint']) ? (string) $existing['inventory_fingerprint'] : '',
                'catalog_fingerprint' => isset($existing['catalog_fingerprint']) ? (string) $existing['catalog_fingerprint'] : '',
                'catalog_file' => $context['target_field_catalog_file'],
                'artifact_relative_path' => $this->get_domain_relative_path($context['target_field_catalog_file'], $context['domain_dir']),
                'catalog' => $existing,
            ];
        }

        if (! $build_if_missing) {
            return new WP_Error(
                'dbvc_cc_v2_catalog_missing',
                __('The V2 target field catalog has not been built for this domain.', 'dbvc'),
                ['status' => 404]
            );
        }

        return $this->build_catalog($domain, false);
    }

    /**
     * @param array<string, string> $context
     * @param array<string, mixed>  $inventory_result
     * @param array<string, mixed>  $legacy_result
     * @param array<string, mixed>  $legacy_catalog
     * @return array<string, mixed>
     */
    private function build_v2_catalog_payload(array $context, array $inventory_result, array $legacy_result, array $legacy_catalog)
    {
        $object_catalog = isset($legacy_catalog['cpt_catalog']) && is_array($legacy_catalog['cpt_catalog']) ? $legacy_catalog['cpt_catalog'] : [];
        $taxonomy_catalog = isset($legacy_catalog['taxonomy_catalog']) && is_array($legacy_catalog['taxonomy_catalog']) ? $legacy_catalog['taxonomy_catalog'] : [];
        $term_catalog = isset($legacy_catalog['term_catalog']) && is_array($legacy_catalog['term_catalog']) ? $legacy_catalog['term_catalog'] : [];
        $meta_catalog = isset($legacy_catalog['meta_catalog']) && is_array($legacy_catalog['meta_catalog']) ? $legacy_catalog['meta_catalog'] : [];
        $acf_catalog = isset($legacy_catalog['acf_catalog']) && is_array($legacy_catalog['acf_catalog']) ? $legacy_catalog['acf_catalog'] : [];
        $media_field_catalog = isset($legacy_catalog['media_field_catalog']) && is_array($legacy_catalog['media_field_catalog']) ? $legacy_catalog['media_field_catalog'] : [];
        $field_context_index = $this->get_field_context_index($context);
        $field_context_provider = $this->build_field_context_provider_meta($field_context_index);
        $acf_catalog = $this->enrich_acf_catalog_with_field_context($acf_catalog, $field_context_index);

        $source_artifacts = [
            'schema_snapshot_file' => isset($legacy_catalog['source_artifacts']['schema_snapshot_file']) ? (string) $legacy_catalog['source_artifacts']['schema_snapshot_file'] : DBVC_CC_Schema_Snapshot_Service::get_snapshot_file_path(),
            'schema_snapshot_hash' => isset($legacy_catalog['source_artifacts']['schema_snapshot_hash']) ? (string) $legacy_catalog['source_artifacts']['schema_snapshot_hash'] : '',
            'inventory_file' => $context['target_object_inventory_file'],
            'inventory_fingerprint' => isset($inventory_result['inventory_fingerprint']) ? (string) $inventory_result['inventory_fingerprint'] : '',
            'legacy_catalog_file' => isset($legacy_result['catalog_file']) ? (string) $legacy_result['catalog_file'] : '',
            'legacy_catalog_fingerprint' => isset($legacy_result['catalog_fingerprint']) ? (string) $legacy_result['catalog_fingerprint'] : '',
            'field_context_source_hash' => isset($field_context_provider['source_hash']) ? (string) $field_context_provider['source_hash'] : '',
            'field_context_site_fingerprint' => isset($field_context_provider['site_fingerprint']) ? (string) $field_context_provider['site_fingerprint'] : '',
        ];

        $catalog_fingerprint = $this->compute_fingerprint(
            [
                'inventory_fingerprint' => $source_artifacts['inventory_fingerprint'],
                'schema_snapshot_hash' => $source_artifacts['schema_snapshot_hash'],
                'object_catalog' => $object_catalog,
                'taxonomy_catalog' => $taxonomy_catalog,
                'term_catalog' => $term_catalog,
                'meta_catalog' => $meta_catalog,
                'acf_catalog' => $acf_catalog,
                'media_field_catalog' => $media_field_catalog,
                'field_context_provider' => $field_context_provider,
            ]
        );

        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'target-field-catalog.v2',
            'domain' => $context['domain'],
            'generated_at' => current_time('c'),
            'inventory_fingerprint' => $source_artifacts['inventory_fingerprint'],
            'catalog_fingerprint' => $catalog_fingerprint,
            'source_artifacts' => $source_artifacts,
            'field_context_provider' => $field_context_provider,
            'object_catalog' => $object_catalog,
            'taxonomy_catalog' => $taxonomy_catalog,
            'term_catalog' => $term_catalog,
            'meta_catalog' => $meta_catalog,
            'acf_catalog' => $acf_catalog,
            'media_field_catalog' => $media_field_catalog,
            'stats' => [
                'object_type_count' => count($object_catalog),
                'taxonomy_count' => count($taxonomy_catalog),
                'term_schema_count' => count($term_catalog),
                'meta_object_type_count' => count($meta_catalog),
                'acf_group_count' => isset($acf_catalog['groups']) && is_array($acf_catalog['groups']) ? count($acf_catalog['groups']) : 0,
                'media_field_count' => count($media_field_catalog),
                'field_context_group_count' => isset($field_context_provider['group_count']) ? absint($field_context_provider['group_count']) : 0,
                'field_context_entry_count' => isset($field_context_provider['entry_count']) ? absint($field_context_provider['entry_count']) : 0,
            ],
        ];
    }

    /**
     * @param array<string, string> $context
     * @return array<string, mixed>
     */
    private function get_field_context_index(array $context)
    {
        if (! class_exists('DBVC_CC_Field_Context_Provider_Service')) {
            return [
                'available' => false,
                'transport' => 'unavailable',
                'provider' => [],
                'catalog_meta' => [
                    'status' => 'missing',
                    'resolver_status' => '',
                    'source_hash' => '',
                    'generated_at' => current_time('c'),
                    'group_count' => 0,
                    'entry_count' => 0,
                ],
                'diagnostics' => [
                    'degraded' => true,
                    'blocked' => false,
                    'warnings' => [
                        [
                            'code' => 'field_context_provider_class_missing',
                            'message' => __('DBVC field context provider service is not loaded.', 'dbvc'),
                        ],
                    ],
                ],
                'groups_by_acf_key' => [],
                'entries_by_acf_key' => [],
                'error' => [],
            ];
        }

        return DBVC_CC_Field_Context_Provider_Service::get_instance()->get_mapping_index([]);
    }

    /**
     * @param array<string, mixed> $index
     * @return array<string, mixed>
     */
    private function build_field_context_provider_meta(array $index)
    {
        $provider = isset($index['provider']) && is_array($index['provider']) ? $index['provider'] : [];
        $catalog_meta = isset($index['catalog_meta']) && is_array($index['catalog_meta']) ? $index['catalog_meta'] : [];
        $diagnostics = isset($index['diagnostics']) && is_array($index['diagnostics']) ? $index['diagnostics'] : [];

        return [
            'available' => ! empty($index['available']),
            'transport' => isset($index['transport']) ? sanitize_key((string) $index['transport']) : '',
            'provider' => isset($provider['name']) ? sanitize_key((string) $provider['name']) : '',
            'provider_version' => isset($provider['provider_version']) ? sanitize_text_field((string) $provider['provider_version']) : '',
            'contract_version' => isset($provider['contract_version']) ? absint($provider['contract_version']) : 0,
            'schema_version' => isset($provider['schema_version']) ? absint($provider['schema_version']) : 0,
            'site_fingerprint' => isset($provider['site_fingerprint']) ? sanitize_text_field((string) $provider['site_fingerprint']) : '',
            'catalog_status' => isset($catalog_meta['status']) ? sanitize_key((string) $catalog_meta['status']) : '',
            'resolver_status' => isset($catalog_meta['resolver_status']) ? sanitize_key((string) $catalog_meta['resolver_status']) : '',
            'generated_at' => isset($catalog_meta['generated_at']) ? sanitize_text_field((string) $catalog_meta['generated_at']) : '',
            'source_hash' => isset($catalog_meta['source_hash']) ? sanitize_text_field((string) $catalog_meta['source_hash']) : '',
            'cache_layer' => isset($catalog_meta['cache_layer']) ? sanitize_key((string) $catalog_meta['cache_layer']) : '',
            'cache_version' => isset($catalog_meta['cache_version']) ? sanitize_text_field((string) $catalog_meta['cache_version']) : '',
            'group_count' => isset($catalog_meta['group_count']) ? absint($catalog_meta['group_count']) : 0,
            'entry_count' => isset($catalog_meta['entry_count']) ? absint($catalog_meta['entry_count']) : 0,
            'degraded' => ! empty($diagnostics['degraded']),
            'blocked' => ! empty($diagnostics['blocked']),
            'diagnostics' => $diagnostics,
            'error' => isset($index['error']) && is_array($index['error']) ? $index['error'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $acf_catalog
     * @param array<string, mixed> $field_context_index
     * @return array<string, mixed>
     */
    private function enrich_acf_catalog_with_field_context(array $acf_catalog, array $field_context_index)
    {
        $groups = isset($acf_catalog['groups']) && is_array($acf_catalog['groups']) ? $acf_catalog['groups'] : [];
        if (empty($groups) || empty($field_context_index['available'])) {
            return $acf_catalog;
        }

        $provider_meta = $this->build_field_context_provider_meta($field_context_index);
        $context_groups = isset($field_context_index['groups_by_acf_key']) && is_array($field_context_index['groups_by_acf_key'])
            ? $field_context_index['groups_by_acf_key']
            : [];
        $context_entries = isset($field_context_index['entries_by_acf_key']) && is_array($field_context_index['entries_by_acf_key'])
            ? $field_context_index['entries_by_acf_key']
            : [];
        $context_entries_by_group = isset($field_context_index['entries_by_group_and_acf_key']) && is_array($field_context_index['entries_by_group_and_acf_key'])
            ? $field_context_index['entries_by_group_and_acf_key']
            : [];

        foreach ($groups as $group_key => $group) {
            if (! is_array($group)) {
                continue;
            }

            $group_key_string = sanitize_key((string) $group_key);
            if ($group_key_string !== '' && isset($context_groups[$group_key_string]) && is_array($context_groups[$group_key_string])) {
                $group['field_context'] = $this->build_group_field_context_trace($context_groups[$group_key_string], $provider_meta);
            }

            $fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [];
            foreach ($fields as $field_key => $field) {
                if (! is_array($field)) {
                    continue;
                }

                $field_key_string = sanitize_key((string) $field_key);
                if ($field_key_string === '') {
                    continue;
                }

                $entry_context = isset($context_entries_by_group[$group_key_string][$field_key_string]) && is_array($context_entries_by_group[$group_key_string][$field_key_string])
                    ? $context_entries_by_group[$group_key_string][$field_key_string]
                    : [];
                if (empty($entry_context) && isset($context_entries[$field_key_string]) && is_array($context_entries[$field_key_string])) {
                    $entry_context = $context_entries[$field_key_string];
                }
                if (empty($entry_context)) {
                    continue;
                }

                $fields[$field_key]['field_context'] = $this->build_entry_field_context_trace($entry_context, $provider_meta, $group);
            }

            $group['fields'] = $fields;
            $groups[$group_key] = $group;
        }

        $acf_catalog['groups'] = $groups;

        return $acf_catalog;
    }

    /**
     * @param array<string, mixed> $group_context
     * @param array<string, mixed> $provider_meta
     * @return array<string, mixed>
     */
    private function build_group_field_context_trace(array $group_context, array $provider_meta)
    {
        return [
            'provider' => isset($provider_meta['provider']) ? (string) $provider_meta['provider'] : '',
            'contract_version' => isset($provider_meta['contract_version']) ? absint($provider_meta['contract_version']) : 0,
            'schema_version' => isset($provider_meta['schema_version']) ? absint($provider_meta['schema_version']) : 0,
            'site_fingerprint' => isset($provider_meta['site_fingerprint']) ? (string) $provider_meta['site_fingerprint'] : '',
            'source_hash' => isset($provider_meta['source_hash']) ? (string) $provider_meta['source_hash'] : '',
            'catalog_status' => isset($provider_meta['catalog_status']) ? (string) $provider_meta['catalog_status'] : '',
            'resolver_status' => isset($provider_meta['resolver_status']) ? (string) $provider_meta['resolver_status'] : '',
            'key_path' => isset($group_context['key_path']) ? (string) $group_context['key_path'] : '',
            'name_path' => isset($group_context['name_path']) ? (string) $group_context['name_path'] : '',
            'location' => isset($group_context['location']) && is_array($group_context['location']) ? $group_context['location'] : [],
            'object_context' => isset($group_context['object_context']) && is_array($group_context['object_context']) ? $group_context['object_context'] : [],
            'resolved_purpose' => isset($group_context['resolved_purpose']) ? (string) $group_context['resolved_purpose'] : '',
            'default_purpose' => isset($group_context['default_purpose']) ? (string) $group_context['default_purpose'] : '',
            'effective_purpose' => isset($group_context['effective_purpose']) ? (string) $group_context['effective_purpose'] : '',
            'resolved_from' => isset($group_context['resolved_from']) ? (string) $group_context['resolved_from'] : '',
            'status_meta' => isset($group_context['status_meta']) && is_array($group_context['status_meta']) ? $group_context['status_meta'] : [],
            'coverage' => isset($group_context['coverage']) && is_array($group_context['coverage']) ? $group_context['coverage'] : [],
            'value_contract' => isset($group_context['value_contract']) && is_array($group_context['value_contract']) ? $group_context['value_contract'] : [],
            'clone_context' => isset($group_context['clone_context']) && is_array($group_context['clone_context']) ? $group_context['clone_context'] : [],
            'has_override' => ! empty($group_context['has_override']),
        ];
    }

    /**
     * @param array<string, mixed> $entry_context
     * @param array<string, mixed> $provider_meta
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function build_entry_field_context_trace(array $entry_context, array $provider_meta, array $group)
    {
        $group_context = isset($group['field_context']) && is_array($group['field_context']) ? $group['field_context'] : [];
        $value_contract = isset($entry_context['value_contract']) && is_array($entry_context['value_contract']) ? $entry_context['value_contract'] : [];
        $clone_context = isset($entry_context['clone_context']) && is_array($entry_context['clone_context']) ? $entry_context['clone_context'] : [];
        $warnings = $this->build_entry_field_context_warnings($entry_context);

        return [
            'provider' => isset($provider_meta['provider']) ? (string) $provider_meta['provider'] : '',
            'contract_version' => isset($provider_meta['contract_version']) ? absint($provider_meta['contract_version']) : 0,
            'schema_version' => isset($provider_meta['schema_version']) ? absint($provider_meta['schema_version']) : 0,
            'site_fingerprint' => isset($provider_meta['site_fingerprint']) ? (string) $provider_meta['site_fingerprint'] : '',
            'source_hash' => isset($provider_meta['source_hash']) ? (string) $provider_meta['source_hash'] : '',
            'catalog_status' => isset($provider_meta['catalog_status']) ? (string) $provider_meta['catalog_status'] : '',
            'resolver_status' => isset($provider_meta['resolver_status']) ? (string) $provider_meta['resolver_status'] : '',
            'matched_by' => isset($entry_context['matched_by']) ? (string) $entry_context['matched_by'] : 'acf_key',
            'resolved_from' => isset($entry_context['resolved_from']) ? (string) $entry_context['resolved_from'] : '',
            'status_meta' => isset($entry_context['status_meta']) && is_array($entry_context['status_meta']) ? $entry_context['status_meta'] : [],
            'group_purpose' => isset($group_context['effective_purpose']) ? (string) $group_context['effective_purpose'] : '',
            'field_purpose' => isset($entry_context['effective_purpose']) ? (string) $entry_context['effective_purpose'] : '',
            'resolved_purpose' => isset($entry_context['resolved_purpose']) ? (string) $entry_context['resolved_purpose'] : '',
            'default_purpose' => isset($entry_context['default_purpose']) ? (string) $entry_context['default_purpose'] : '',
            'key_path' => isset($entry_context['key_path']) ? (string) $entry_context['key_path'] : '',
            'name_path' => isset($entry_context['name_path']) ? (string) $entry_context['name_path'] : '',
            'parent_key_path' => isset($entry_context['parent_key_path']) ? (string) $entry_context['parent_key_path'] : '',
            'parent_name_path' => isset($entry_context['parent_name_path']) ? (string) $entry_context['parent_name_path'] : '',
            'object_context' => isset($group_context['object_context']) && is_array($group_context['object_context']) ? $group_context['object_context'] : [],
            'value_contract' => $value_contract,
            'clone_context' => $clone_context,
            'has_override' => ! empty($entry_context['has_override']),
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $entry_context
     * @return array<int, array<string, string>>
     */
    private function build_entry_field_context_warnings(array $entry_context)
    {
        $warnings = [];
        $status_meta = isset($entry_context['status_meta']) && is_array($entry_context['status_meta']) ? $entry_context['status_meta'] : [];
        $status_code = isset($status_meta['code']) ? sanitize_key((string) $status_meta['code']) : '';
        if (in_array($status_code, ['legacy_only', 'missing'], true)) {
            $warnings[] = [
                'code' => 'field_context_' . $status_code,
                'message' => sprintf(__('Field context status is `%s`.', 'dbvc'), $status_code),
            ];
        }

        $value_contract = isset($entry_context['value_contract']) && is_array($entry_context['value_contract']) ? $entry_context['value_contract'] : [];
        if (array_key_exists('writable', $value_contract) && ! $value_contract['writable']) {
            $warnings[] = [
                'code' => 'field_context_non_writable',
                'message' => __('Field context marks this target as non-writable control or container metadata.', 'dbvc'),
            ];
        }

        $clone_context = isset($entry_context['clone_context']) && is_array($entry_context['clone_context']) ? $entry_context['clone_context'] : [];
        $publish_policy = isset($clone_context['publish_policy']) && is_array($clone_context['publish_policy']) ? $clone_context['publish_policy'] : [];
        if (! empty($clone_context['is_clone_projection']) && array_key_exists('framework_default_writable', $publish_policy) && ! $publish_policy['framework_default_writable']) {
            $warnings[] = [
                'code' => 'field_context_clone_framework_default_blocked',
                'message' => __('Clone-projected context should publish from the source default or a site override, not the consumer projection.', 'dbvc'),
            ];
        }

        return $warnings;
    }

    /**
     * @param string $path
     * @param string $domain_dir
     * @return string
     */
    private function get_domain_relative_path($path, $domain_dir)
    {
        return ltrim(str_replace(wp_normalize_path((string) $domain_dir), '', wp_normalize_path((string) $path)), '/');
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function compute_fingerprint($value)
    {
        return hash('sha256', (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param string $path
     * @return array<string, mixed>|null
     */
    private function read_json_file($path)
    {
        $path = (string) $path;
        if ($path === '' || ! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
