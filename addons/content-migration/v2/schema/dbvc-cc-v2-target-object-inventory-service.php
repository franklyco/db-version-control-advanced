<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Target_Object_Inventory_Service
{
    /**
     * @var DBVC_CC_V2_Target_Object_Inventory_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Target_Object_Inventory_Service
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
    public function build_inventory($domain, $force_rebuild = false)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $snapshot_source = $this->resolve_snapshot_source((bool) $force_rebuild);
        if (is_wp_error($snapshot_source)) {
            return $snapshot_source;
        }

        $object_types = $this->collect_object_types();
        $taxonomy_types = $this->collect_taxonomy_types();
        $inventory_fingerprint = $this->compute_fingerprint(
            [
                'snapshot_hash' => $snapshot_source['snapshot_hash'],
                'object_types' => $object_types,
                'taxonomy_types' => $taxonomy_types,
            ]
        );

        $existing = $this->read_json_file($context['target_object_inventory_file']);
        if (
            ! $force_rebuild
            && is_array($existing)
            && isset($existing['inventory_fingerprint'])
            && (string) $existing['inventory_fingerprint'] === $inventory_fingerprint
        ) {
            return [
                'status' => 'reused',
                'domain' => $context['domain'],
                'generated_at' => isset($existing['generated_at']) ? (string) $existing['generated_at'] : '',
                'inventory_fingerprint' => $inventory_fingerprint,
                'inventory_file' => $context['target_object_inventory_file'],
                'artifact_relative_path' => $this->get_domain_relative_path($context['target_object_inventory_file'], $context['domain_dir']),
                'snapshot_hash' => $snapshot_source['snapshot_hash'],
                'inventory' => $existing,
            ];
        }

        $inventory = [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'target-object-inventory.v1',
            'domain' => $context['domain'],
            'generated_at' => current_time('c'),
            'inventory_fingerprint' => $inventory_fingerprint,
            'source_artifacts' => [
                'schema_snapshot_file' => $snapshot_source['snapshot_file'],
                'schema_snapshot_hash' => $snapshot_source['snapshot_hash'],
            ],
            'object_types' => $object_types,
            'taxonomy_types' => $taxonomy_types,
            'stats' => [
                'object_type_count' => count($object_types),
                'taxonomy_type_count' => count($taxonomy_types),
                'public_object_type_count' => count(array_filter($object_types, static function ($entry) {
                    return ! empty($entry['public']);
                })),
                'hierarchical_object_type_count' => count(array_filter($object_types, static function ($entry) {
                    return ! empty($entry['hierarchical']);
                })),
            ],
        ];

        if (! DBVC_CC_Artifact_Manager::write_json_file($context['target_object_inventory_file'], $inventory)) {
            return new WP_Error(
                'dbvc_cc_v2_inventory_write_failed',
                __('Could not write the V2 target object inventory artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        return [
            'status' => 'built',
            'domain' => $context['domain'],
            'generated_at' => $inventory['generated_at'],
            'inventory_fingerprint' => $inventory_fingerprint,
            'inventory_file' => $context['target_object_inventory_file'],
            'artifact_relative_path' => $this->get_domain_relative_path($context['target_object_inventory_file'], $context['domain_dir']),
            'snapshot_hash' => $snapshot_source['snapshot_hash'],
            'inventory' => $inventory,
        ];
    }

    /**
     * @param string $domain
     * @param bool   $build_if_missing
     * @return array<string, mixed>|WP_Error
     */
    public function get_inventory($domain, $build_if_missing = true)
    {
        $context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($context)) {
            return $context;
        }

        $existing = $this->read_json_file($context['target_object_inventory_file']);
        if (is_array($existing)) {
            return [
                'status' => 'loaded',
                'domain' => $context['domain'],
                'generated_at' => isset($existing['generated_at']) ? (string) $existing['generated_at'] : '',
                'inventory_fingerprint' => isset($existing['inventory_fingerprint']) ? (string) $existing['inventory_fingerprint'] : '',
                'inventory_file' => $context['target_object_inventory_file'],
                'artifact_relative_path' => $this->get_domain_relative_path($context['target_object_inventory_file'], $context['domain_dir']),
                'inventory' => $existing,
            ];
        }

        if (! $build_if_missing) {
            return new WP_Error(
                'dbvc_cc_v2_inventory_missing',
                __('The V2 target object inventory has not been built for this domain.', 'dbvc'),
                ['status' => 404]
            );
        }

        return $this->build_inventory($domain, false);
    }

    /**
     * @param bool $force_refresh
     * @return array<string, mixed>|WP_Error
     */
    private function resolve_snapshot_source($force_refresh)
    {
        $snapshot_file = DBVC_CC_Schema_Snapshot_Service::get_snapshot_file_path();
        $snapshot_payload = $this->read_json_file($snapshot_file);

        if ($force_refresh || ! is_array($snapshot_payload)) {
            $generated = DBVC_CC_Schema_Snapshot_Service::generate_snapshot();
            if (is_wp_error($generated)) {
                return $generated;
            }

            $snapshot_payload = $this->read_json_file($snapshot_file);
        }

        if (! is_array($snapshot_payload)) {
            return new WP_Error(
                'dbvc_cc_v2_snapshot_missing',
                __('The schema snapshot payload is unavailable.', 'dbvc'),
                ['status' => 500]
            );
        }

        return [
            'snapshot_file' => $snapshot_file,
            'snapshot_hash' => $this->compute_fingerprint($snapshot_payload),
            'snapshot_payload' => $snapshot_payload,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collect_object_types()
    {
        $objects = get_post_types([], 'objects');
        if (! is_array($objects)) {
            return [];
        }

        $inventory = [];
        foreach ($objects as $post_type => $object) {
            if (! ($object instanceof WP_Post_Type)) {
                continue;
            }

            $supports = get_all_post_type_supports((string) $post_type);
            $inventory[] = [
                'object_key' => (string) $post_type,
                'label' => (string) $object->label,
                'type_family' => 'post_type',
                'public' => (bool) $object->public,
                'hierarchical' => (bool) $object->hierarchical,
                'supports' => array_values(array_keys(is_array($supports) ? $supports : [])),
                'taxonomy_refs' => array_values(get_object_taxonomies((string) $post_type)),
            ];
        }

        usort(
            $inventory,
            static function ($left, $right) {
                return strnatcasecmp((string) $left['object_key'], (string) $right['object_key']);
            }
        );

        return $inventory;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collect_taxonomy_types()
    {
        $objects = get_taxonomies([], 'objects');
        if (! is_array($objects)) {
            return [];
        }

        $inventory = [];
        foreach ($objects as $taxonomy => $object) {
            if (! ($object instanceof WP_Taxonomy)) {
                continue;
            }

            $inventory[] = [
                'taxonomy_key' => (string) $taxonomy,
                'label' => (string) $object->label,
                'public' => (bool) $object->public,
                'hierarchical' => (bool) $object->hierarchical,
                'object_refs' => array_values(is_array($object->object_type) ? $object->object_type : []),
            ];
        }

        usort(
            $inventory,
            static function ($left, $right) {
                return strnatcasecmp((string) $left['taxonomy_key'], (string) $right['taxonomy_key']);
            }
        );

        return $inventory;
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
