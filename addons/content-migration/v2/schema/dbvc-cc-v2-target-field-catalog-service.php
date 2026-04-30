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
        // Vertical field-context catalogs are ACF-object scoped, not crawl-domain scoped.
        // Passing a DBVC domain into the provider collapses ACF group resolution to zero groups.
        $field_context_provider = DBVC_CC_Field_Context_Provider_Service::get_instance()->get_catalog([], 'mapping');
        $acf_catalog = $this->enrich_acf_catalog($acf_catalog, $field_context_provider);

        $source_artifacts = [
            'schema_snapshot_file' => isset($legacy_catalog['source_artifacts']['schema_snapshot_file']) ? (string) $legacy_catalog['source_artifacts']['schema_snapshot_file'] : DBVC_CC_Schema_Snapshot_Service::get_snapshot_file_path(),
            'schema_snapshot_hash' => isset($legacy_catalog['source_artifacts']['schema_snapshot_hash']) ? (string) $legacy_catalog['source_artifacts']['schema_snapshot_hash'] : '',
            'inventory_file' => $context['target_object_inventory_file'],
            'inventory_fingerprint' => isset($inventory_result['inventory_fingerprint']) ? (string) $inventory_result['inventory_fingerprint'] : '',
            'legacy_catalog_file' => isset($legacy_result['catalog_file']) ? (string) $legacy_result['catalog_file'] : '',
            'legacy_catalog_fingerprint' => isset($legacy_result['catalog_fingerprint']) ? (string) $legacy_result['catalog_fingerprint'] : '',
            'field_context_source_hash' => isset($field_context_provider['source_hash']) ? (string) $field_context_provider['source_hash'] : '',
            'field_context_schema_version' => isset($field_context_provider['schema_version']) ? (string) $field_context_provider['schema_version'] : '',
            'field_context_site_fingerprint' => isset($field_context_provider['site_fingerprint']) ? (string) $field_context_provider['site_fingerprint'] : '',
        ];

        $catalog_fingerprint = $this->compute_fingerprint(
            [
                'inventory_fingerprint' => $source_artifacts['inventory_fingerprint'],
                'schema_snapshot_hash' => $source_artifacts['schema_snapshot_hash'],
                'field_context_provider' => $field_context_provider,
                'object_catalog' => $object_catalog,
                'taxonomy_catalog' => $taxonomy_catalog,
                'term_catalog' => $term_catalog,
                'meta_catalog' => $meta_catalog,
                'acf_catalog' => $acf_catalog,
                'media_field_catalog' => $media_field_catalog,
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
                'field_context_group_count' => isset($field_context_provider['groups_by_key']) && is_array($field_context_provider['groups_by_key']) ? count($field_context_provider['groups_by_key']) : 0,
                'field_context_entry_count' => isset($field_context_provider['entries_by_key_path']) && is_array($field_context_provider['entries_by_key_path']) ? count($field_context_provider['entries_by_key_path']) : 0,
                'media_field_count' => count($media_field_catalog),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $acf_catalog
     * @param array<string, mixed> $field_context_provider
     * @return array<string, mixed>
     */
    private function enrich_acf_catalog(array $acf_catalog, array $field_context_provider)
    {
        $groups = isset($acf_catalog['groups']) && is_array($acf_catalog['groups']) ? $acf_catalog['groups'] : [];
        if (empty($groups)) {
            $acf_catalog['groups'] = [];
            return $acf_catalog;
        }

        foreach ($groups as $group_key => $group) {
            if (! is_array($group)) {
                continue;
            }

            $groups[$group_key] = $this->enrich_acf_group($group, $field_context_provider);
        }

        $acf_catalog['groups'] = $groups;
        return $acf_catalog;
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed> $field_context_provider
     * @return array<string, mixed>
     */
    private function enrich_acf_group(array $group, array $field_context_provider)
    {
        $group_key = isset($group['key']) ? sanitize_key((string) $group['key']) : '';
        $provider_groups = isset($field_context_provider['groups_by_key']) && is_array($field_context_provider['groups_by_key'])
            ? $field_context_provider['groups_by_key']
            : [];
        $provider_group = ($group_key !== '' && isset($provider_groups[$group_key]) && is_array($provider_groups[$group_key]))
            ? $provider_groups[$group_key]
            : [];

        $group['group_name'] = ! empty($provider_group['group_name'])
            ? sanitize_key((string) $provider_group['group_name'])
            : (isset($group['group_name']) ? sanitize_key((string) $group['group_name']) : sanitize_title((string) ($group['title'] ?? $group_key)));
        $group['object_context'] = ! empty($provider_group['object_context']) && is_array($provider_group['object_context'])
            ? $provider_group['object_context']
            : DBVC_CC_Field_Context_Provider_Service::get_instance()->normalize_object_context(
                isset($group['location']) && is_array($group['location']) ? $group['location'] : []
            );
        $group['field_context'] = $this->build_group_field_context($group, $provider_group, $field_context_provider);

        $fields = isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [];
        foreach ($fields as $field_key => $field) {
            if (! is_array($field)) {
                continue;
            }

            $fields[$field_key] = $this->enrich_acf_field($group, $field, $field_context_provider);
        }
        $group['fields'] = $fields;

        return $group;
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed> $field
     * @param array<string, mixed> $field_context_provider
     * @return array<string, mixed>
     */
    private function enrich_acf_field(array $group, array $field, array $field_context_provider)
    {
        $group_key = isset($group['key']) ? sanitize_key((string) $group['key']) : '';
        $field_key = isset($field['key']) ? sanitize_key((string) $field['key']) : '';
        $provider_entries = isset($field_context_provider['entries_by_group_and_acf_key']) && is_array($field_context_provider['entries_by_group_and_acf_key'])
            ? $field_context_provider['entries_by_group_and_acf_key']
            : [];
        $provider_entry = ($group_key !== '' && $field_key !== '' && isset($provider_entries[$group_key][$field_key]) && is_array($provider_entries[$group_key][$field_key]))
            ? $provider_entries[$group_key][$field_key]
            : [];

        $field['field_context'] = $this->build_field_entry_context($group, $field, $provider_entry, $field_context_provider);
        return $field;
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed> $provider_group
     * @param array<string, mixed> $field_context_provider
     * @return array<string, mixed>
     */
    private function build_group_field_context(array $group, array $provider_group, array $field_context_provider)
    {
        $warnings = isset($provider_group['warnings']) && is_array($provider_group['warnings']) ? array_values($provider_group['warnings']) : [];
        $status = isset($field_context_provider['status']) ? sanitize_key((string) $field_context_provider['status']) : 'unavailable';
        if ($this->is_provider_catalog_unavailable($status)) {
            $warnings[] = 'provider_unavailable';
        }

        $resolved_purpose = isset($provider_group['resolved_purpose']) ? sanitize_text_field((string) $provider_group['resolved_purpose']) : '';
        $default_purpose = isset($provider_group['default_purpose']) ? sanitize_text_field((string) $provider_group['default_purpose']) : '';
        $effective_purpose = isset($provider_group['effective_purpose']) ? sanitize_text_field((string) $provider_group['effective_purpose']) : '';
        $resolved_from = isset($provider_group['resolved_from']) ? sanitize_key((string) $provider_group['resolved_from']) : '';
        $runtime_purpose = $this->extract_runtime_purpose($group);

        if ($resolved_purpose === '' && $runtime_purpose !== '') {
            $resolved_purpose = $runtime_purpose;
            if ($effective_purpose === '') {
                $effective_purpose = $runtime_purpose;
            }
            if ($default_purpose === '') {
                $default_purpose = $runtime_purpose;
            }
            if ($resolved_from === '') {
                $resolved_from = 'acf_runtime_field_context';
            }
            $warnings[] = 'runtime_field_context_fallback';
        }

        return [
            'status' => $status,
            'provider' => isset($field_context_provider['provider']) ? sanitize_key((string) $field_context_provider['provider']) : 'vertical-field-context',
            'transport' => isset($field_context_provider['transport']) ? sanitize_key((string) $field_context_provider['transport']) : 'local',
            'contract_version' => isset($field_context_provider['contract_version']) ? sanitize_text_field((string) $field_context_provider['contract_version']) : '',
            'source_hash' => isset($field_context_provider['source_hash']) ? sanitize_text_field((string) $field_context_provider['source_hash']) : '',
            'schema_version' => isset($field_context_provider['schema_version']) ? sanitize_text_field((string) $field_context_provider['schema_version']) : '',
            'site_fingerprint' => isset($field_context_provider['site_fingerprint']) ? sanitize_text_field((string) $field_context_provider['site_fingerprint']) : '',
            'key_path' => isset($provider_group['key_path']) ? sanitize_text_field((string) $provider_group['key_path']) : '',
            'name_path' => isset($provider_group['name_path']) ? sanitize_text_field((string) $provider_group['name_path']) : '',
            'resolved_purpose' => $resolved_purpose,
            'default_purpose' => $default_purpose,
            'effective_purpose' => $effective_purpose,
            'status_meta' => isset($provider_group['status_meta']) && is_array($provider_group['status_meta']) ? $provider_group['status_meta'] : [],
            'resolved_from' => $resolved_from,
            'object_context' => ! empty($provider_group['object_context']) && is_array($provider_group['object_context'])
                ? $provider_group['object_context']
                : DBVC_CC_Field_Context_Provider_Service::get_instance()->normalize_object_context(
                    isset($group['location']) && is_array($group['location']) ? $group['location'] : []
                ),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @param array<string, mixed> $field
     * @param array<string, mixed> $provider_entry
     * @param array<string, mixed> $field_context_provider
     * @return array<string, mixed>
     */
    private function build_field_entry_context(array $group, array $field, array $provider_entry, array $field_context_provider)
    {
        $group_context = isset($group['field_context']) && is_array($group['field_context']) ? $group['field_context'] : [];
        $warnings = isset($provider_entry['warnings']) && is_array($provider_entry['warnings']) ? array_values($provider_entry['warnings']) : [];
        $status = isset($field_context_provider['status']) ? sanitize_key((string) $field_context_provider['status']) : 'unavailable';
        if ($this->is_provider_catalog_unavailable($status)) {
            $warnings[] = 'provider_unavailable';
        }

        $field_type = isset($field['type']) ? sanitize_key((string) $field['type']) : '';
        $container_type = isset($field['container_type']) ? sanitize_key((string) $field['container_type']) : '';
        $default_value_contract = [
            'field_type' => $field_type,
            'container_type' => $container_type,
            'writable' => ! in_array($field_type, ['group', 'repeater', 'flexible_content', 'accordion', 'tab', 'message'], true),
        ];
        $resolved_purpose = isset($provider_entry['resolved_purpose']) ? sanitize_text_field((string) $provider_entry['resolved_purpose']) : '';
        $default_purpose = isset($provider_entry['default_purpose']) ? sanitize_text_field((string) $provider_entry['default_purpose']) : '';
        $effective_purpose = isset($provider_entry['effective_purpose']) ? sanitize_text_field((string) $provider_entry['effective_purpose']) : '';
        $resolved_from = isset($provider_entry['resolved_from']) ? sanitize_key((string) $provider_entry['resolved_from']) : '';
        $matched_by = isset($provider_entry['matched_by']) ? sanitize_key((string) $provider_entry['matched_by']) : ($provider_entry ? 'group_and_acf_key' : '');
        $runtime_purpose = $this->extract_runtime_purpose($field);

        if ($resolved_purpose === '' && $runtime_purpose !== '') {
            $resolved_purpose = $runtime_purpose;
            if ($effective_purpose === '') {
                $effective_purpose = $runtime_purpose;
            }
            if ($default_purpose === '') {
                $default_purpose = $runtime_purpose;
            }
            if ($resolved_from === '') {
                $resolved_from = 'acf_runtime_field_context';
            }
            if ($matched_by === '') {
                $matched_by = 'acf_runtime_field_context';
            }
            $warnings[] = 'runtime_field_context_fallback';
        }

        return [
            'status' => $status,
            'provider' => isset($field_context_provider['provider']) ? sanitize_key((string) $field_context_provider['provider']) : 'vertical-field-context',
            'transport' => isset($field_context_provider['transport']) ? sanitize_key((string) $field_context_provider['transport']) : 'local',
            'contract_version' => isset($field_context_provider['contract_version']) ? sanitize_text_field((string) $field_context_provider['contract_version']) : '',
            'source_hash' => isset($field_context_provider['source_hash']) ? sanitize_text_field((string) $field_context_provider['source_hash']) : '',
            'schema_version' => isset($field_context_provider['schema_version']) ? sanitize_text_field((string) $field_context_provider['schema_version']) : '',
            'site_fingerprint' => isset($field_context_provider['site_fingerprint']) ? sanitize_text_field((string) $field_context_provider['site_fingerprint']) : '',
            'matched_by' => $matched_by,
            'resolved_from' => $resolved_from,
            'key_path' => isset($provider_entry['key_path']) ? sanitize_text_field((string) $provider_entry['key_path']) : '',
            'name_path' => isset($provider_entry['name_path']) ? sanitize_text_field((string) $provider_entry['name_path']) : '',
            'parent_key_path' => isset($provider_entry['parent_key_path']) ? sanitize_text_field((string) $provider_entry['parent_key_path']) : '',
            'parent_name_path' => isset($provider_entry['parent_name_path']) ? sanitize_text_field((string) $provider_entry['parent_name_path']) : '',
            'resolved_purpose' => $resolved_purpose,
            'default_purpose' => $default_purpose,
            'effective_purpose' => $effective_purpose,
            'status_meta' => isset($provider_entry['status_meta']) && is_array($provider_entry['status_meta']) ? $provider_entry['status_meta'] : [],
            'object_context' => ! empty($provider_entry['object_context']) && is_array($provider_entry['object_context'])
                ? $provider_entry['object_context']
                : (isset($group_context['object_context']) && is_array($group_context['object_context']) ? $group_context['object_context'] : []),
            'value_contract' => isset($provider_entry['value_contract']) && is_array($provider_entry['value_contract'])
                ? array_merge($default_value_contract, $provider_entry['value_contract'])
                : $default_value_contract,
            'clone_context' => isset($provider_entry['clone_context']) && is_array($provider_entry['clone_context'])
                ? $provider_entry['clone_context']
                : [
                    'is_clone_projected' => false,
                    'is_directly_writable' => true,
                ],
            'warnings' => array_values(array_unique($warnings)),
        ];
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

    /**
     * @param string $status
     * @return bool
     */
    private function is_provider_catalog_unavailable($status)
    {
        $status = sanitize_key((string) $status);
        if ($status === '') {
            return true;
        }

        return ! in_array($status, ['available', 'fresh', 'stale', 'degraded', 'legacy_only', 'partial'], true);
    }

    /**
     * @param array<string, mixed> $source
     * @return string
     */
    private function extract_runtime_purpose(array $source)
    {
        foreach (['resolved_purpose', 'effective_purpose', 'purpose', 'default_purpose'] as $key) {
            if (! empty($source[$key])) {
                return sanitize_text_field((string) $source[$key]);
            }
        }

        foreach (['vf_field_context', 'context', 'default_context'] as $nested_key) {
            if (isset($source[$nested_key]) && is_array($source[$nested_key])) {
                $purpose = $this->extract_runtime_purpose($source[$nested_key]);
                if ($purpose !== '') {
                    return $purpose;
                }
            }
        }

        if (! empty($source['gardenai_field_purpose'])) {
            return sanitize_text_field((string) $source['gardenai_field_purpose']);
        }

        return '';
    }
}
