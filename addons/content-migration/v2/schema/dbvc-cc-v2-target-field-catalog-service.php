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

        $source_artifacts = [
            'schema_snapshot_file' => isset($legacy_catalog['source_artifacts']['schema_snapshot_file']) ? (string) $legacy_catalog['source_artifacts']['schema_snapshot_file'] : DBVC_CC_Schema_Snapshot_Service::get_snapshot_file_path(),
            'schema_snapshot_hash' => isset($legacy_catalog['source_artifacts']['schema_snapshot_hash']) ? (string) $legacy_catalog['source_artifacts']['schema_snapshot_hash'] : '',
            'inventory_file' => $context['target_object_inventory_file'],
            'inventory_fingerprint' => isset($inventory_result['inventory_fingerprint']) ? (string) $inventory_result['inventory_fingerprint'] : '',
            'legacy_catalog_file' => isset($legacy_result['catalog_file']) ? (string) $legacy_result['catalog_file'] : '',
            'legacy_catalog_fingerprint' => isset($legacy_result['catalog_fingerprint']) ? (string) $legacy_result['catalog_fingerprint'] : '',
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
            ],
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
}
