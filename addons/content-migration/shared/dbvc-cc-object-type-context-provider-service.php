<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Object_Type_Context_Provider_Service
{
    /**
     * @var DBVC_CC_Object_Type_Context_Provider_Service|null
     */
    private static $instance = null;

    /**
     * @var array<string, array<string, mixed>>
     */
    private $catalog_cache = [];

    /**
     * @return DBVC_CC_Object_Type_Context_Provider_Service
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

        if (! function_exists('vf_object_type_context_get_catalog_payload')) {
            $result = $this->build_unavailable_result('missing_local_helpers', $profile);
            $this->catalog_cache[$cache_key] = $result;
            return $result;
        }

        $raw = vf_object_type_context_get_catalog_payload($criteria, $profile);
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
        return $this->summarize_provider($this->get_catalog($criteria, $profile));
    }

    /**
     * @param string                    $post_type
     * @param array<string, mixed>|null $catalog
     * @return array<string, mixed>
     */
    public function get_post_type_context($post_type, $catalog = null)
    {
        $post_type = sanitize_key((string) $post_type);
        if ($post_type === '') {
            return [];
        }

        $catalog = is_array($catalog) ? $catalog : $this->get_catalog([], 'mapping');
        $entries = isset($catalog['post_types_by_key']) && is_array($catalog['post_types_by_key'])
            ? $catalog['post_types_by_key']
            : [];

        return isset($entries[$post_type]) && is_array($entries[$post_type]) ? $entries[$post_type] : [];
    }

    /**
     * @param string                    $taxonomy
     * @param array<string, mixed>|null $catalog
     * @return array<string, mixed>
     */
    public function get_taxonomy_context($taxonomy, $catalog = null)
    {
        $taxonomy = sanitize_key((string) $taxonomy);
        if ($taxonomy === '') {
            return [];
        }

        $catalog = is_array($catalog) ? $catalog : $this->get_catalog([], 'mapping');
        $entries = isset($catalog['taxonomies_by_key']) && is_array($catalog['taxonomies_by_key'])
            ? $catalog['taxonomies_by_key']
            : [];

        return isset($entries[$taxonomy]) && is_array($entries[$taxonomy]) ? $entries[$taxonomy] : [];
    }

    /**
     * @param array<string, mixed>      $object_context
     * @param array<string, mixed>|null $catalog
     * @return array<string, mixed>
     */
    public function resolve_context_for_object_context(array $object_context, $catalog = null)
    {
        $catalog = is_array($catalog) ? $catalog : $this->get_catalog([], 'mapping');
        $post_types = isset($object_context['post_types']) && is_array($object_context['post_types'])
            ? array_values($object_context['post_types'])
            : [];
        foreach ($post_types as $post_type) {
            $entry = $this->get_post_type_context($post_type, $catalog);
            if (! empty($entry)) {
                return $entry;
            }
        }

        $taxonomies = isset($object_context['taxonomies']) && is_array($object_context['taxonomies'])
            ? array_values($object_context['taxonomies'])
            : [];
        foreach ($taxonomies as $taxonomy) {
            $entry = $this->get_taxonomy_context($taxonomy, $catalog);
            if (! empty($entry)) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $provider
     * @return array<string, mixed>
     */
    public function summarize_provider(array $provider)
    {
        if (empty($provider)) {
            return [];
        }

        $warnings = isset($provider['warnings']) && is_array($provider['warnings'])
            ? array_values(
                array_filter(
                    array_map(
                        static function ($warning) {
                            return is_scalar($warning) ? sanitize_text_field((string) $warning) : '';
                        },
                        $provider['warnings']
                    )
                )
            )
            : [];

        return [
            'status' => isset($provider['status']) ? sanitize_key((string) $provider['status']) : 'unavailable',
            'reason' => isset($provider['reason']) ? sanitize_key((string) $provider['reason']) : '',
            'provider' => isset($provider['provider']) ? sanitize_key((string) $provider['provider']) : 'vertical-object-type-context',
            'provider_version' => isset($provider['provider_version']) ? sanitize_text_field((string) $provider['provider_version']) : '',
            'transport' => isset($provider['transport']) ? sanitize_key((string) $provider['transport']) : 'local',
            'contract_version' => isset($provider['contract_version']) ? sanitize_text_field((string) $provider['contract_version']) : '',
            'source_hash' => isset($provider['source_hash']) ? sanitize_text_field((string) $provider['source_hash']) : '',
            'schema_version' => isset($provider['schema_version']) ? sanitize_text_field((string) $provider['schema_version']) : '',
            'site_fingerprint' => isset($provider['site_fingerprint']) ? sanitize_text_field((string) $provider['site_fingerprint']) : '',
            'catalog_status' => isset($provider['catalog_status']) ? sanitize_key((string) $provider['catalog_status']) : '',
            'entry_count' => isset($provider['entry_count']) ? absint($provider['entry_count']) : 0,
            'complete_count' => isset($provider['complete_count']) ? absint($provider['complete_count']) : 0,
            'partial_count' => isset($provider['partial_count']) ? absint($provider['partial_count']) : 0,
            'override_count' => isset($provider['override_count']) ? absint($provider['override_count']) : 0,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param mixed  $raw
     * @param string $profile
     * @return array<string, mixed>
     */
    private function normalize_catalog_payload($raw, $profile)
    {
        if (is_wp_error($raw)) {
            return $this->build_unavailable_result($raw->get_error_code(), $profile);
        }

        if (! is_array($raw)) {
            return $this->build_unavailable_result('invalid_payload', $profile);
        }

        $provider_meta = isset($raw['provider']) && is_array($raw['provider']) ? $raw['provider'] : [];
        $catalog_meta = isset($raw['catalog_meta']) && is_array($raw['catalog_meta']) ? $raw['catalog_meta'] : [];
        $data = isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;
        $raw_entries = $this->extract_entry_collection($data);
        $entries_by_object_id = [];
        $post_types_by_key = [];
        $taxonomies_by_key = [];

        foreach ($raw_entries as $entry) {
            $normalized = $this->normalize_entry($entry);
            if (empty($normalized['object_id'])) {
                continue;
            }

            $object_id = (string) $normalized['object_id'];
            $entries_by_object_id[$object_id] = $normalized;

            if (($normalized['object_kind'] ?? '') === 'post_type' && ! empty($normalized['object_key'])) {
                $post_types_by_key[(string) $normalized['object_key']] = $normalized;
            } elseif (($normalized['object_kind'] ?? '') === 'taxonomy' && ! empty($normalized['object_key'])) {
                $taxonomies_by_key[(string) $normalized['object_key']] = $normalized;
            }
        }

        ksort($entries_by_object_id);
        ksort($post_types_by_key);
        ksort($taxonomies_by_key);

        $status = $this->normalize_status(isset($catalog_meta['status']) ? (string) $catalog_meta['status'] : '');
        if ($status === '') {
            $status = empty($entries_by_object_id) ? 'unavailable' : 'available';
        }

        $warnings = $this->normalize_warning_list(isset($catalog_meta['warnings']) ? $catalog_meta['warnings'] : []);
        if (! empty($catalog_meta['partial_count'])) {
            $warnings[] = 'object_type_context_partial_entries';
        }

        return [
            'status' => $status,
            'reason' => $status === 'unavailable' && empty($entries_by_object_id) ? 'empty_catalog' : '',
            'transport' => 'local',
            'profile' => $profile,
            'provider' => isset($provider_meta['name']) ? sanitize_key((string) $provider_meta['name']) : 'vertical-object-type-context',
            'provider_version' => isset($provider_meta['provider_version']) ? sanitize_text_field((string) $provider_meta['provider_version']) : '',
            'contract_version' => isset($provider_meta['contract_version']) ? sanitize_text_field((string) $provider_meta['contract_version']) : '',
            'catalog_status' => isset($catalog_meta['status']) ? sanitize_key((string) $catalog_meta['status']) : '',
            'source_hash' => isset($catalog_meta['source_hash']) ? sanitize_text_field((string) $catalog_meta['source_hash']) : '',
            'schema_version' => isset($provider_meta['schema_version']) ? sanitize_text_field((string) $provider_meta['schema_version']) : '',
            'site_fingerprint' => ! empty($provider_meta['site_fingerprint']) ? sanitize_text_field((string) $provider_meta['site_fingerprint']) : $this->build_site_fingerprint(),
            'entry_count' => isset($catalog_meta['entry_count']) ? absint($catalog_meta['entry_count']) : count($entries_by_object_id),
            'complete_count' => isset($catalog_meta['complete_count']) ? absint($catalog_meta['complete_count']) : 0,
            'partial_count' => isset($catalog_meta['partial_count']) ? absint($catalog_meta['partial_count']) : 0,
            'override_count' => isset($catalog_meta['override_count']) ? absint($catalog_meta['override_count']) : 0,
            'warnings' => array_values(array_unique($warnings)),
            'entries_by_object_id' => $entries_by_object_id,
            'post_types_by_key' => $post_types_by_key,
            'taxonomies_by_key' => $taxonomies_by_key,
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
            'provider' => 'vertical-object-type-context',
            'provider_version' => '',
            'contract_version' => '',
            'catalog_status' => '',
            'source_hash' => '',
            'schema_version' => '',
            'site_fingerprint' => $this->build_site_fingerprint(),
            'entry_count' => 0,
            'complete_count' => 0,
            'partial_count' => 0,
            'override_count' => 0,
            'warnings' => [],
            'entries_by_object_id' => [],
            'post_types_by_key' => [],
            'taxonomies_by_key' => [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function extract_entry_collection(array $data)
    {
        if (isset($data['entries']) && is_array($data['entries'])) {
            return $this->flatten_collection($data['entries']);
        }

        if (! empty($data['object_id'])) {
            return [$data];
        }

        return [];
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
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function normalize_entry(array $entry)
    {
        $status_meta = isset($entry['status_meta']) && is_array($entry['status_meta']) ? $entry['status_meta'] : [];
        $registration = isset($entry['registration']) && is_array($entry['registration']) ? $entry['registration'] : [];
        $relationships = isset($entry['relationships']) && is_array($entry['relationships']) ? $entry['relationships'] : [];

        $normalized = [
            'object_id' => isset($entry['object_id']) ? sanitize_text_field((string) $entry['object_id']) : '',
            'object_kind' => isset($entry['object_kind']) ? sanitize_key((string) $entry['object_kind']) : '',
            'object_key' => isset($entry['object_key']) ? sanitize_key((string) $entry['object_key']) : '',
            'label' => isset($entry['label']) ? sanitize_text_field((string) $entry['label']) : '',
            'singular_label' => isset($entry['singular_label']) ? sanitize_text_field((string) $entry['singular_label']) : '',
            'resolved_purpose' => isset($entry['resolved_purpose']) ? sanitize_textarea_field((string) $entry['resolved_purpose']) : '',
            'entity_role' => isset($entry['entity_role']) ? sanitize_key((string) $entry['entity_role']) : '',
            'content_model' => isset($entry['content_model']) ? sanitize_key((string) $entry['content_model']) : '',
            'supports_generation' => ! empty($entry['supports_generation']),
            'supports_migration_target' => ! empty($entry['supports_migration_target']),
            'requires_manual_review' => ! empty($entry['requires_manual_review']),
            'status' => isset($status_meta['code']) ? sanitize_key((string) $status_meta['code']) : '',
            'status_meta' => $status_meta,
            'resolved_from' => isset($entry['resolved_from']) ? sanitize_key((string) $entry['resolved_from']) : '',
            'has_override' => ! empty($entry['has_override']),
            'registration' => [
                'public' => ! empty($registration['public']),
                'publicly_queryable' => ! empty($registration['publicly_queryable']),
                'show_ui' => ! empty($registration['show_ui']),
                'show_in_rest' => ! empty($registration['show_in_rest']),
                'hierarchical' => ! empty($registration['hierarchical']),
                'has_archive' => ! empty($registration['has_archive']),
                'rewrite_slug' => isset($registration['rewrite_slug']) ? sanitize_text_field((string) $registration['rewrite_slug']) : '',
                'taxonomies' => isset($registration['taxonomies']) && is_array($registration['taxonomies'])
                    ? array_values(array_map('sanitize_key', $registration['taxonomies']))
                    : [],
                'object_type' => isset($registration['object_type']) && is_array($registration['object_type'])
                    ? array_values(array_map('sanitize_key', $registration['object_type']))
                    : [],
            ],
            'relationships' => $relationships,
        ];

        foreach (['term_role', 'assignment_behavior', 'is_classification_only', 'is_user_facing_filter', 'hierarchical_meaning', 'owner_feature'] as $key) {
            if (array_key_exists($key, $entry)) {
                $normalized[$key] = is_bool($entry[$key]) ? (bool) $entry[$key] : sanitize_text_field((string) $entry[$key]);
            }
        }

        return $normalized;
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
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param string $status
     * @return string
     */
    private function normalize_status($status)
    {
        $status = sanitize_key((string) $status);
        return $status === '' ? '' : $status;
    }

    /**
     * @param string $profile
     * @return string
     */
    private function normalize_profile($profile)
    {
        $profile = sanitize_key((string) $profile);
        return in_array($profile, ['summary', 'mapping', 'full'], true) ? $profile : 'mapping';
    }

    /**
     * @return string
     */
    private function build_site_fingerprint()
    {
        return hash('sha256', (string) home_url('/'));
    }
}
