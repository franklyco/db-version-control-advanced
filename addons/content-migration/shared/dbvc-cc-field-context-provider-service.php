<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Field_Context_Provider_Service
{
    /**
     * @var DBVC_CC_Field_Context_Provider_Service|null
     */
    private static $instance = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private $mapping_index_cache = [];

    /**
     * @return DBVC_CC_Field_Context_Provider_Service
     */
    public static function get_instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * @return bool
     */
    public function is_available()
    {
        return function_exists('vf_field_context_get_service_catalog_payload');
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public function get_mapping_index(array $criteria = [])
    {
        $criteria = $this->normalize_criteria($criteria);
        $cache_key = md5((string) wp_json_encode($criteria));

        if (isset($this->mapping_index_cache[$cache_key]) && is_array($this->mapping_index_cache[$cache_key])) {
            return $this->mapping_index_cache[$cache_key];
        }

        if (! $this->is_available()) {
            $result = $this->build_unavailable_result(
                'vf_field_context_unavailable',
                __('Vertical field context provider is not available in this runtime.', 'dbvc'),
                503
            );
            $this->mapping_index_cache[$cache_key] = $result;

            return $result;
        }

        $payload = vf_field_context_get_service_catalog_payload($criteria, 'mapping');
        if (is_wp_error($payload)) {
            $result = $this->build_error_result($payload);
            $this->mapping_index_cache[$cache_key] = $result;

            return $result;
        }

        $result = $this->normalize_mapping_index_payload($payload, $criteria);
        $this->mapping_index_cache[$cache_key] = $result;

        return $result;
    }

    /**
     * @return void
     */
    public function flush_runtime_cache()
    {
        $this->mapping_index_cache = [];
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private function normalize_criteria(array $criteria)
    {
        $normalized = [];

        foreach ($criteria as $key => $value) {
            $key = sanitize_key((string) $key);
            if ($key === '') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $normalized[$key] = is_string($value)
                    ? sanitize_text_field((string) $value)
                    : $value;
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    private function normalize_mapping_index_payload(array $payload, array $criteria)
    {
        $provider = isset($payload['provider']) && is_array($payload['provider']) ? $payload['provider'] : [];
        $catalog_meta = isset($payload['catalog_meta']) && is_array($payload['catalog_meta']) ? $payload['catalog_meta'] : [];
        $groups = [];
        $entries_by_acf_key = [];
        $group_rows = isset($payload['data']['groups']) && is_array($payload['data']['groups'])
            ? $payload['data']['groups']
            : [];

        foreach ($group_rows as $group_key => $group_row) {
            if (! is_array($group_row)) {
                continue;
            }

            $normalized_group = $this->normalize_group_payload($group_row);
            $acf_group_key = isset($normalized_group['acf_key']) ? (string) $normalized_group['acf_key'] : '';
            if ($acf_group_key === '') {
                $acf_group_key = sanitize_key((string) $group_key);
            }

            if ($acf_group_key === '') {
                continue;
            }

            $groups[$acf_group_key] = $normalized_group;

            $field_rows = isset($group_row['fields']) && is_array($group_row['fields']) ? $group_row['fields'] : [];
            foreach ($field_rows as $field_row) {
                if (! is_array($field_row)) {
                    continue;
                }

                $normalized_entry = $this->normalize_entry_payload($field_row);
                $acf_field_key = isset($normalized_entry['acf_key']) ? (string) $normalized_entry['acf_key'] : '';
                if ($acf_field_key === '') {
                    continue;
                }

                $entries_by_acf_key[$acf_field_key] = $normalized_entry;
            }
        }

        ksort($groups);
        ksort($entries_by_acf_key);

        return [
            'available' => true,
            'criteria' => $criteria,
            'profile' => 'mapping',
            'provider' => $this->normalize_provider_meta($provider),
            'catalog_meta' => $this->normalize_catalog_meta($catalog_meta),
            'groups_by_acf_key' => $groups,
            'entries_by_acf_key' => $entries_by_acf_key,
            'error' => [],
        ];
    }

    /**
     * @param array<string, mixed> $provider
     * @return array<string, mixed>
     */
    private function normalize_provider_meta(array $provider)
    {
        return [
            'name' => isset($provider['name']) ? sanitize_key((string) $provider['name']) : '',
            'provider_version' => isset($provider['provider_version']) ? sanitize_text_field((string) $provider['provider_version']) : '',
            'contract_version' => isset($provider['contract_version']) ? absint($provider['contract_version']) : 0,
            'schema_version' => isset($provider['schema_version']) ? absint($provider['schema_version']) : 0,
            'site_fingerprint' => isset($provider['site_fingerprint']) ? sanitize_text_field((string) $provider['site_fingerprint']) : '',
        ];
    }

    /**
     * @param array<string, mixed> $catalog_meta
     * @return array<string, mixed>
     */
    private function normalize_catalog_meta(array $catalog_meta)
    {
        return [
            'status' => isset($catalog_meta['status']) ? sanitize_key((string) $catalog_meta['status']) : '',
            'resolver_status' => isset($catalog_meta['resolver_status']) ? sanitize_key((string) $catalog_meta['resolver_status']) : '',
            'generated_at' => isset($catalog_meta['generated_at']) ? sanitize_text_field((string) $catalog_meta['generated_at']) : '',
            'source_hash' => isset($catalog_meta['source_hash']) ? sanitize_text_field((string) $catalog_meta['source_hash']) : '',
            'group_count' => isset($catalog_meta['group_count']) ? absint($catalog_meta['group_count']) : 0,
            'entry_count' => isset($catalog_meta['entry_count']) ? absint($catalog_meta['entry_count']) : 0,
            'cache_layer' => isset($catalog_meta['cache_layer']) ? sanitize_key((string) $catalog_meta['cache_layer']) : '',
            'cache_version' => isset($catalog_meta['cache_version']) ? sanitize_text_field((string) $catalog_meta['cache_version']) : '',
        ];
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function normalize_group_payload(array $group)
    {
        return [
            'acf_key' => isset($group['acf_key']) ? sanitize_key((string) $group['acf_key']) : '',
            'name' => isset($group['name']) ? sanitize_key((string) $group['name']) : '',
            'label' => isset($group['label']) ? sanitize_text_field((string) $group['label']) : '',
            'key_path' => isset($group['key_path']) ? sanitize_text_field((string) $group['key_path']) : '',
            'name_path' => isset($group['name_path']) ? sanitize_text_field((string) $group['name_path']) : '',
            'scope' => isset($group['scope']) ? sanitize_key((string) $group['scope']) : '',
            'resolved_purpose' => isset($group['resolved_purpose']) ? sanitize_textarea_field((string) $group['resolved_purpose']) : '',
            'default_purpose' => isset($group['default_purpose']) ? sanitize_textarea_field((string) $group['default_purpose']) : '',
            'status_meta' => isset($group['status_meta']) && is_array($group['status_meta']) ? $group['status_meta'] : [],
            'coverage' => isset($group['coverage']) && is_array($group['coverage']) ? $group['coverage'] : [],
            'has_override' => ! empty($group['has_override']),
            'resolved_from' => isset($group['resolved_from']) ? sanitize_key((string) $group['resolved_from']) : '',
            'context' => isset($group['context']) && is_array($group['context']) ? $group['context'] : [],
            'default_context' => isset($group['default_context']) && is_array($group['default_context']) ? $group['default_context'] : [],
            'legacy' => isset($group['legacy']) && is_array($group['legacy']) ? $group['legacy'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function normalize_entry_payload(array $entry)
    {
        return [
            'acf_key' => isset($entry['acf_key']) ? sanitize_key((string) $entry['acf_key']) : '',
            'acf_name' => isset($entry['acf_name']) ? sanitize_key((string) $entry['acf_name']) : '',
            'name' => isset($entry['name']) ? sanitize_key((string) $entry['name']) : '',
            'label' => isset($entry['label']) ? sanitize_text_field((string) $entry['label']) : '',
            'key_path' => isset($entry['key_path']) ? sanitize_text_field((string) $entry['key_path']) : '',
            'name_path' => isset($entry['name_path']) ? sanitize_text_field((string) $entry['name_path']) : '',
            'parent_key_path' => isset($entry['parent_key_path']) ? sanitize_text_field((string) $entry['parent_key_path']) : '',
            'parent_name_path' => isset($entry['parent_name_path']) ? sanitize_text_field((string) $entry['parent_name_path']) : '',
            'group_key' => isset($entry['group_key']) ? sanitize_key((string) $entry['group_key']) : '',
            'group_name' => isset($entry['group_name']) ? sanitize_key((string) $entry['group_name']) : '',
            'scope' => isset($entry['scope']) ? sanitize_key((string) $entry['scope']) : '',
            'type' => isset($entry['type']) ? sanitize_key((string) $entry['type']) : '',
            'container_type' => isset($entry['container_type']) ? sanitize_key((string) $entry['container_type']) : '',
            'resolved_purpose' => isset($entry['resolved_purpose']) ? sanitize_textarea_field((string) $entry['resolved_purpose']) : '',
            'default_purpose' => isset($entry['default_purpose']) ? sanitize_textarea_field((string) $entry['default_purpose']) : '',
            'status_meta' => isset($entry['status_meta']) && is_array($entry['status_meta']) ? $entry['status_meta'] : [],
            'has_override' => ! empty($entry['has_override']),
            'resolved_from' => isset($entry['resolved_from']) ? sanitize_key((string) $entry['resolved_from']) : '',
            'context' => isset($entry['context']) && is_array($entry['context']) ? $entry['context'] : [],
            'default_context' => isset($entry['default_context']) && is_array($entry['default_context']) ? $entry['default_context'] : [],
            'legacy' => isset($entry['legacy']) && is_array($entry['legacy']) ? $entry['legacy'] : [],
        ];
    }

    /**
     * @param string $code
     * @param string $message
     * @param int    $status
     * @return array<string, mixed>
     */
    private function build_unavailable_result($code, $message, $status)
    {
        return [
            'available' => false,
            'criteria' => [],
            'profile' => 'mapping',
            'provider' => [],
            'catalog_meta' => [
                'status' => 'missing',
                'resolver_status' => '',
                'generated_at' => current_time('c'),
                'source_hash' => '',
                'group_count' => 0,
                'entry_count' => 0,
                'cache_layer' => '',
                'cache_version' => '',
            ],
            'groups_by_acf_key' => [],
            'entries_by_acf_key' => [],
            'error' => [
                'code' => sanitize_key((string) $code),
                'message' => sanitize_text_field((string) $message),
                'status' => absint($status),
            ],
        ];
    }

    /**
     * @param WP_Error $error
     * @return array<string, mixed>
     */
    private function build_error_result($error)
    {
        $status = 500;
        $data = $error->get_error_data();
        if (is_array($data) && isset($data['status'])) {
            $status = absint($data['status']);
        }

        return $this->build_unavailable_result(
            (string) $error->get_error_code(),
            (string) $error->get_error_message(),
            $status
        );
    }
}
