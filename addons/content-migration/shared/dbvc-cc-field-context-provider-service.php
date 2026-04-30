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
    private $catalog_cache = [];

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
     * @param array<string, mixed> $criteria
     * @param string               $profile
     * @return array<string, mixed>
     */
    public function get_catalog(array $criteria = [], $profile = 'mapping')
    {
        $profile = $this->normalize_profile($profile);
        $cache_key = md5((string) wp_json_encode([$criteria, $profile]));
        if (isset($this->catalog_cache[$cache_key]) && is_array($this->catalog_cache[$cache_key])) {
            return $this->catalog_cache[$cache_key];
        }

        if (! function_exists('vf_field_context_get_service_catalog_payload')) {
            $result = $this->build_unavailable_result('missing_local_helpers', $profile);
            $this->catalog_cache[$cache_key] = $result;
            return $result;
        }

        $raw = vf_field_context_get_service_catalog_payload($criteria, $profile);
        $normalized = $this->normalize_catalog_payload($raw, $profile);
        $this->catalog_cache[$cache_key] = $normalized;

        return $normalized;
    }

    /**
     * @param array<string, mixed> $criteria
     * @param string               $profile
     * @return array<string, mixed>
     */
    public function get_status(array $criteria = [], $profile = 'mapping')
    {
        $catalog = $this->get_catalog($criteria, $profile);

        return [
            'status' => isset($catalog['status']) ? (string) $catalog['status'] : 'unavailable',
            'reason' => isset($catalog['reason']) ? (string) $catalog['reason'] : '',
            'transport' => isset($catalog['transport']) ? (string) $catalog['transport'] : 'local',
            'provider' => isset($catalog['provider']) ? (string) $catalog['provider'] : 'vertical-field-context',
            'contract_version' => isset($catalog['contract_version']) ? (string) $catalog['contract_version'] : '',
            'catalog_status' => isset($catalog['catalog_status']) ? (string) $catalog['catalog_status'] : '',
            'source_hash' => isset($catalog['source_hash']) ? (string) $catalog['source_hash'] : '',
            'schema_version' => isset($catalog['schema_version']) ? (string) $catalog['schema_version'] : '',
            'site_fingerprint' => isset($catalog['site_fingerprint']) ? (string) $catalog['site_fingerprint'] : '',
            'warnings' => isset($catalog['warnings']) && is_array($catalog['warnings']) ? array_values($catalog['warnings']) : [],
        ];
    }

    /**
     * @param mixed  $raw
     * @param string $profile
     * @return array<string, mixed>
     */
    private function normalize_catalog_payload($raw, $profile)
    {
        if (! is_array($raw)) {
            return $this->build_unavailable_result('invalid_payload', $profile);
        }

        $provider_meta = isset($raw['provider']) && is_array($raw['provider']) ? $raw['provider'] : [];
        $catalog_meta = isset($raw['catalog_meta']) && is_array($raw['catalog_meta']) ? $raw['catalog_meta'] : [];
        $data = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;

        $raw_groups = $this->extract_group_collection($data);
        $groups_by_key = [];
        foreach ($raw_groups as $group) {
            $normalized_group = $this->normalize_group($group);
            if (! empty($normalized_group['group_key'])) {
                $groups_by_key[(string) $normalized_group['group_key']] = $normalized_group;
            }
        }

        $raw_entries = $this->extract_entry_collection($data, $raw_groups);
        $entries_by_key_path = [];
        $entries_by_name_path = [];
        $entries_by_group_and_acf_key = [];
        $entries_by_acf_key = [];

        foreach ($raw_entries as $entry) {
            $normalized_entry = $this->normalize_entry($entry, $groups_by_key);
            if (empty($normalized_entry['acf_key']) && empty($normalized_entry['key_path'])) {
                continue;
            }

            $key_path = isset($normalized_entry['key_path']) ? (string) $normalized_entry['key_path'] : '';
            $name_path = isset($normalized_entry['name_path']) ? (string) $normalized_entry['name_path'] : '';
            $group_key = isset($normalized_entry['group_key']) ? (string) $normalized_entry['group_key'] : '';
            $acf_key = isset($normalized_entry['acf_key']) ? (string) $normalized_entry['acf_key'] : '';

            if ($key_path !== '') {
                $entries_by_key_path[$key_path] = $normalized_entry;
            }
            if ($name_path !== '') {
                $entries_by_name_path[$name_path] = $normalized_entry;
            }
            if ($group_key !== '' && $acf_key !== '') {
                if (! isset($entries_by_group_and_acf_key[$group_key])) {
                    $entries_by_group_and_acf_key[$group_key] = [];
                }
                $entries_by_group_and_acf_key[$group_key][$acf_key] = $normalized_entry;
            }
            if ($acf_key !== '') {
                if (! isset($entries_by_acf_key[$acf_key])) {
                    $entries_by_acf_key[$acf_key] = [];
                }
                $entries_by_acf_key[$acf_key][] = $normalized_entry;
            }
        }

        ksort($groups_by_key);
        ksort($entries_by_key_path);
        ksort($entries_by_name_path);
        ksort($entries_by_group_and_acf_key);
        ksort($entries_by_acf_key);

        $status = $this->normalize_status(isset($catalog_meta['status']) ? (string) $catalog_meta['status'] : '');
        if ($status === '') {
            $status = empty($entries_by_key_path) && empty($groups_by_key) ? 'unavailable' : 'available';
        }

        $source_hash = $this->first_non_empty_string(
            isset($catalog_meta['source_hash']) ? $catalog_meta['source_hash'] : '',
            isset($provider_meta['source_hash']) ? $provider_meta['source_hash'] : ''
        );
        $schema_version = $this->first_non_empty_string(
            isset($catalog_meta['schema_version']) ? $catalog_meta['schema_version'] : '',
            isset($provider_meta['schema_version']) ? $provider_meta['schema_version'] : ''
        );
        $site_fingerprint = $this->first_non_empty_string(
            isset($catalog_meta['site_fingerprint']) ? $catalog_meta['site_fingerprint'] : '',
            isset($provider_meta['site_fingerprint']) ? $provider_meta['site_fingerprint'] : ''
        );

        return [
            'status' => $status,
            'reason' => $status === 'unavailable' && empty($entries_by_key_path) && empty($groups_by_key) ? 'empty_catalog' : '',
            'transport' => 'local',
            'profile' => $profile,
            'provider' => isset($provider_meta['name']) ? sanitize_key((string) $provider_meta['name']) : 'vertical-field-context',
            'contract_version' => isset($provider_meta['contract_version']) ? sanitize_text_field((string) $provider_meta['contract_version']) : '',
            'catalog_status' => isset($catalog_meta['status']) ? sanitize_key((string) $catalog_meta['status']) : '',
            'source_hash' => $source_hash,
            'schema_version' => $schema_version,
            'site_fingerprint' => $site_fingerprint !== '' ? $site_fingerprint : $this->build_site_fingerprint(),
            'cache_layer' => isset($catalog_meta['cache_layer']) ? sanitize_key((string) $catalog_meta['cache_layer']) : '',
            'cache_version' => isset($catalog_meta['cache_version']) ? sanitize_text_field((string) $catalog_meta['cache_version']) : '',
            'warnings' => $this->normalize_warning_list(isset($catalog_meta['warnings']) ? $catalog_meta['warnings'] : []),
            'groups_by_key' => $groups_by_key,
            'entries_by_key_path' => $entries_by_key_path,
            'entries_by_name_path' => $entries_by_name_path,
            'entries_by_group_and_acf_key' => $entries_by_group_and_acf_key,
            'entries_by_acf_key' => $entries_by_acf_key,
        ];
    }

    /**
     * @param string $reason
     * @param string $profile
     * @return array<string, mixed>
     */
    private function build_unavailable_result($reason, $profile)
    {
        return [
            'status' => 'unavailable',
            'reason' => sanitize_key((string) $reason),
            'transport' => 'local',
            'profile' => $this->normalize_profile($profile),
            'provider' => 'vertical-field-context',
            'contract_version' => '',
            'catalog_status' => '',
            'source_hash' => '',
            'schema_version' => '',
            'site_fingerprint' => $this->build_site_fingerprint(),
            'cache_layer' => '',
            'cache_version' => '',
            'warnings' => [],
            'groups_by_key' => [],
            'entries_by_key_path' => [],
            'entries_by_name_path' => [],
            'entries_by_group_and_acf_key' => [],
            'entries_by_acf_key' => [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function extract_group_collection(array $data)
    {
        $candidates = [];
        foreach (['groups', 'group_map', 'catalog_groups'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $candidates = $this->flatten_collection($data[$key]);
                if (! empty($candidates)) {
                    return $candidates;
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed>                  $data
     * @param array<int, array<string, mixed>> $raw_groups
     * @return array<int, array<string, mixed>>
     */
    private function extract_entry_collection(array $data, array $raw_groups)
    {
        foreach (['entries', 'fields', 'catalog_entries', 'items'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $entries = $this->flatten_collection($data[$key]);
                if (! empty($entries)) {
                    return $entries;
                }
            }
        }

        $entries = [];
        foreach ($raw_groups as $group) {
            if (! is_array($group)) {
                continue;
            }

            $group_key = isset($group['group_key']) ? sanitize_key((string) $group['group_key']) : sanitize_key((string) ($group['key'] ?? ''));
            $group_name = isset($group['group_name']) ? sanitize_key((string) $group['group_name']) : sanitize_key((string) ($group['name'] ?? ''));
            $entries = array_merge($entries, $this->extract_group_entries($group, $group_key, $group_name));
        }

        return $entries;
    }

    /**
     * @param mixed $collection
     * @return array<int, array<string, mixed>>
     */
    private function flatten_collection($collection)
    {
        $items = [];
        if (! is_array($collection)) {
            return $items;
        }

        foreach ($collection as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $group
     * @param string               $group_key
     * @param string               $group_name
     * @return array<int, array<string, mixed>>
     */
    private function extract_group_entries(array $group, $group_key, $group_name)
    {
        $entries = [];
        $field_collections = [];
        foreach (['fields', 'entries', 'items'] as $key) {
            if (isset($group[$key]) && is_array($group[$key])) {
                $field_collections[] = $group[$key];
            }
        }

        foreach ($field_collections as $collection) {
            foreach ($collection as $field) {
                if (! is_array($field)) {
                    continue;
                }

                if ($group_key !== '' && empty($field['group_key'])) {
                    $field['group_key'] = $group_key;
                }
                if ($group_name !== '' && empty($field['group_name'])) {
                    $field['group_name'] = $group_name;
                }
                $entries[] = $field;

                if (isset($field['sub_fields']) && is_array($field['sub_fields'])) {
                    $entries = array_merge($entries, $this->extract_group_entries($field, $group_key, $group_name));
                }
                if (isset($field['layouts']) && is_array($field['layouts'])) {
                    foreach ($field['layouts'] as $layout) {
                        if (! is_array($layout)) {
                            continue;
                        }
                        if (! empty($layout['sub_fields']) && is_array($layout['sub_fields'])) {
                            $entries = array_merge($entries, $this->extract_group_entries($layout, $group_key, $group_name));
                        }
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $group
     * @return array<string, mixed>
     */
    private function normalize_group(array $group)
    {
        $group_key = sanitize_key((string) ($group['group_key'] ?? $group['acf_key'] ?? $group['key'] ?? ''));
        if ($group_key === '') {
            return [];
        }

        $location = isset($group['location']) && is_array($group['location']) ? $group['location'] : [];

        return [
            'group_key' => $group_key,
            'group_name' => sanitize_key((string) ($group['group_name'] ?? $group['acf_name'] ?? $group['name'] ?? '')),
            'label' => sanitize_text_field((string) ($group['title'] ?? $group['label'] ?? '')),
            'key_path' => sanitize_text_field((string) ($group['key_path'] ?? '')),
            'name_path' => sanitize_text_field((string) ($group['name_path'] ?? '')),
            'location' => $location,
            'object_context' => $this->normalize_object_context($location),
            'resolved_purpose' => $this->extract_purpose($group),
            'default_purpose' => sanitize_text_field((string) ($group['default_purpose'] ?? '')),
            'effective_purpose' => sanitize_text_field((string) ($group['effective_purpose'] ?? '')),
            'status_meta' => $this->normalize_assoc(isset($group['status_meta']) ? $group['status_meta'] : []),
            'resolved_from' => sanitize_key((string) ($group['resolved_from'] ?? '')),
            'matched_by' => sanitize_key((string) ($group['matched_by'] ?? '')),
            'warnings' => $this->normalize_warning_list(isset($group['warnings']) ? $group['warnings'] : []),
        ];
    }

    /**
     * @param array<string, mixed>                 $entry
     * @param array<string, array<string, mixed>> $groups_by_key
     * @return array<string, mixed>
     */
    private function normalize_entry(array $entry, array $groups_by_key)
    {
        $group_key = sanitize_key((string) ($entry['group_key'] ?? $entry['group'] ?? ''));
        $acf_key = sanitize_key((string) ($entry['acf_key'] ?? $entry['key'] ?? ''));
        $key_path = sanitize_text_field((string) ($entry['key_path'] ?? ''));
        if ($group_key === '' && $acf_key === '' && $key_path === '') {
            return [];
        }

        $group_context = ($group_key !== '' && isset($groups_by_key[$group_key]) && is_array($groups_by_key[$group_key]))
            ? $groups_by_key[$group_key]
            : [];
        $location = isset($entry['location']) && is_array($entry['location'])
            ? $entry['location']
            : (isset($group_context['location']) && is_array($group_context['location']) ? $group_context['location'] : []);
        $value_contract = isset($entry['value_contract']) && is_array($entry['value_contract']) ? $entry['value_contract'] : [];
        $clone_context = isset($entry['clone_context']) && is_array($entry['clone_context']) ? $entry['clone_context'] : [];
        $container_type = sanitize_key((string) ($entry['container_type'] ?? ''));
        $type = sanitize_key((string) ($entry['type'] ?? ''));

        if ($container_type === '' && in_array($type, ['group', 'repeater', 'flexible_content', 'clone'], true)) {
            $container_type = $type;
        }

        if (! isset($value_contract['writable'])) {
            $value_contract['writable'] = ! in_array($type, ['group', 'repeater', 'flexible_content', 'accordion', 'tab', 'message'], true);
        }

        return [
            'group_key' => $group_key,
            'group_name' => sanitize_key((string) ($entry['group_name'] ?? $entry['group'] ?? (isset($group_context['group_name']) ? $group_context['group_name'] : ''))),
            'acf_key' => $acf_key,
            'acf_name' => sanitize_key((string) ($entry['acf_name'] ?? $entry['name'] ?? '')),
            'label' => sanitize_text_field((string) ($entry['label'] ?? $entry['title'] ?? '')),
            'key_path' => $key_path,
            'name_path' => sanitize_text_field((string) ($entry['name_path'] ?? '')),
            'parent_key_path' => sanitize_text_field((string) ($entry['parent_key_path'] ?? '')),
            'parent_name_path' => sanitize_text_field((string) ($entry['parent_name_path'] ?? '')),
            'type' => $type,
            'container_type' => $container_type,
            'location' => $location,
            'object_context' => $this->normalize_object_context($location),
            'resolved_purpose' => $this->extract_purpose($entry),
            'default_purpose' => sanitize_text_field((string) ($entry['default_purpose'] ?? '')),
            'effective_purpose' => sanitize_text_field((string) ($entry['effective_purpose'] ?? '')),
            'status_meta' => $this->normalize_assoc(isset($entry['status_meta']) ? $entry['status_meta'] : []),
            'resolved_from' => sanitize_key((string) ($entry['resolved_from'] ?? '')),
            'matched_by' => sanitize_key((string) ($entry['matched_by'] ?? '')),
            'warnings' => $this->normalize_warning_list(isset($entry['warnings']) ? $entry['warnings'] : []),
            'value_contract' => $this->normalize_value_contract($value_contract, $type),
            'clone_context' => $this->normalize_clone_context($clone_context),
        ];
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalize_assoc($value)
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @param mixed  $value_contract
     * @param string $type
     * @return array<string, mixed>
     */
    private function normalize_value_contract($value_contract, $type)
    {
        $contract = is_array($value_contract) ? $value_contract : [];
        if (! isset($contract['field_type']) && $type !== '') {
            $contract['field_type'] = $type;
        }
        if (! isset($contract['writable'])) {
            $contract['writable'] = ! in_array($type, ['group', 'repeater', 'flexible_content', 'accordion', 'tab', 'message'], true);
        }

        return $contract;
    }

    /**
     * @param mixed $clone_context
     * @return array<string, mixed>
     */
    private function normalize_clone_context($clone_context)
    {
        $context = is_array($clone_context) ? $clone_context : [];
        if (! isset($context['is_clone_projected'])) {
            $context['is_clone_projected'] = false;
        }
        if (! isset($context['is_directly_writable'])) {
            $context['is_directly_writable'] = true;
        }

        return $context;
    }

    /**
     * @param mixed $warnings
     * @return array<int, string>
     */
    private function normalize_warning_list($warnings)
    {
        $normalized = [];
        if (! is_array($warnings)) {
            return $normalized;
        }

        foreach ($warnings as $warning) {
            if (is_string($warning) || is_numeric($warning)) {
                $message = sanitize_text_field((string) $warning);
                if ($message !== '') {
                    $normalized[] = $message;
                }
                continue;
            }

            if (is_array($warning)) {
                $message = '';
                if (isset($warning['message'])) {
                    $message = sanitize_text_field((string) $warning['message']);
                } elseif (isset($warning['code'])) {
                    $message = sanitize_key((string) $warning['code']);
                }

                if ($message !== '') {
                    $normalized[] = $message;
                }
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<string, mixed> $source
     * @return string
     */
    private function extract_purpose(array $source)
    {
        foreach (['resolved_purpose', 'effective_purpose', 'purpose', 'default_purpose'] as $key) {
            if (! empty($source[$key])) {
                return sanitize_text_field((string) $source[$key]);
            }
        }

        foreach (['context', 'default_context'] as $context_key) {
            if (isset($source[$context_key]) && is_array($source[$context_key])) {
                $nested_purpose = $this->extract_purpose($source[$context_key]);
                if ($nested_purpose !== '') {
                    return $nested_purpose;
                }
            }
        }

        if (isset($source['vf_field_context']) && is_array($source['vf_field_context']) && ! empty($source['vf_field_context']['purpose'])) {
            return sanitize_text_field((string) $source['vf_field_context']['purpose']);
        }

        if (! empty($source['gardenai_field_purpose'])) {
            return sanitize_text_field((string) $source['gardenai_field_purpose']);
        }

        return '';
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $location
     * @return array<string, mixed>
     */
    public function normalize_object_context(array $location)
    {
        $object_context = [
            'post_types' => [],
            'taxonomies' => [],
            'options_pages' => [],
            'unknown_rules' => [],
        ];

        foreach ($location as $rule_group) {
            if (! is_array($rule_group)) {
                continue;
            }

            foreach ($rule_group as $rule) {
                if (! is_array($rule)) {
                    continue;
                }

                $parameter = sanitize_key((string) ($rule['param'] ?? ''));
                $operator = sanitize_text_field((string) ($rule['operator'] ?? ''));
                $value = sanitize_key((string) ($rule['value'] ?? ''));

                if ($parameter === '' || $operator === '' || $value === '') {
                    continue;
                }

                if ($parameter === 'post_type' && $operator === '==') {
                    $object_context['post_types'][] = $value;
                    continue;
                }

                if ($parameter === 'taxonomy' && $operator === '==') {
                    $object_context['taxonomies'][] = $value;
                    continue;
                }

                if (in_array($parameter, ['options_page', 'options_page_key'], true) && $operator === '==') {
                    $object_context['options_pages'][] = $value;
                    continue;
                }

                $object_context['unknown_rules'][] = [
                    'param' => $parameter,
                    'operator' => $operator,
                    'value' => $value,
                ];
            }
        }

        $object_context['post_types'] = array_values(array_unique($object_context['post_types']));
        $object_context['taxonomies'] = array_values(array_unique($object_context['taxonomies']));
        $object_context['options_pages'] = array_values(array_unique($object_context['options_pages']));

        return $object_context;
    }

    /**
     * @param string $status
     * @return string
     */
    private function normalize_status($status)
    {
        $status = sanitize_key((string) $status);
        if ($status === '') {
            return '';
        }

        return in_array($status, ['available', 'fresh', 'stale', 'degraded', 'unavailable', 'missing', 'legacy_only', 'partial'], true)
            ? $status
            : $status;
    }

    /**
     * @param mixed ...$values
     * @return string
     */
    private function first_non_empty_string(...$values)
    {
        foreach ($values as $value) {
            $string_value = sanitize_text_field((string) $value);
            if ($string_value !== '') {
                return $string_value;
            }
        }

        return '';
    }

    /**
     * @param string $profile
     * @return string
     */
    private function normalize_profile($profile)
    {
        $profile = sanitize_key((string) $profile);

        return in_array($profile, ['summary', 'mapping', 'full'], true)
            ? $profile
            : 'mapping';
    }

    /**
     * @return string
     */
    private function build_site_fingerprint()
    {
        return hash(
            'sha256',
            (string) wp_json_encode(
                [
                    'home' => home_url('/'),
                    'stylesheet' => function_exists('get_stylesheet') ? (string) get_stylesheet() : '',
                    'template' => function_exists('get_template') ? (string) get_template() : '',
                ],
                JSON_UNESCAPED_SLASHES
            )
        );
    }
}
