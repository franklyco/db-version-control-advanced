<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_V2_Page_Artifact_Service
{
    /**
     * @var DBVC_CC_V2_Page_Artifact_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_V2_Page_Artifact_Service
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
     * @param string $page_id
     * @return array<string, mixed>|WP_Error
     */
    public function resolve_page_context($domain, $page_id)
    {
        $domain_context = DBVC_CC_V2_Domain_Journey_Service::get_instance()->get_domain_context($domain);
        if (is_wp_error($domain_context)) {
            return $domain_context;
        }

        $inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory($domain_context['domain']);
        if (is_wp_error($inventory)) {
            return $inventory;
        }

        $rows = isset($inventory['urls']) && is_array($inventory['urls']) ? $inventory['urls'] : [];
        $page_id = sanitize_text_field((string) $page_id);

        $row = null;
        foreach ($rows as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            if (isset($candidate['page_id']) && (string) $candidate['page_id'] === $page_id) {
                $row = $candidate;
                break;
            }
        }

        if (! is_array($row)) {
            return new WP_Error(
                'dbvc_cc_v2_page_missing',
                __('The requested V2 page artifact context could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $normalized_url = isset($row['normalized_url']) ? (string) $row['normalized_url'] : '';
        $page_dir = trailingslashit(DBVC_CC_Artifact_Manager::get_page_dir($normalized_url));
        $slug = isset($row['slug']) ? (string) $row['slug'] : DBVC_CC_Artifact_Manager::get_slug_from_url($normalized_url);

        $artifact_paths = [
            'raw' => $page_dir . $slug . '.json',
            'source_normalization' => $page_dir . $slug . DBVC_CC_V2_Contracts::STORAGE_SOURCE_NORMALIZATION_SUFFIX,
            'elements' => $page_dir . $slug . DBVC_CC_Contracts::STORAGE_ELEMENTS_V2_SUFFIX,
            'sections' => $page_dir . $slug . DBVC_CC_Contracts::STORAGE_SECTIONS_V2_SUFFIX,
            'ingestion_package' => $page_dir . $slug . DBVC_CC_Contracts::STORAGE_INGESTION_PACKAGE_V2_SUFFIX,
            'context_creation' => $page_dir . $slug . DBVC_CC_V2_Contracts::STORAGE_CONTEXT_CREATION_SUFFIX,
            'initial_classification' => $page_dir . $slug . DBVC_CC_V2_Contracts::STORAGE_INITIAL_CLASSIFICATION_SUFFIX,
            'mapping_index' => $page_dir . $slug . DBVC_CC_V2_Contracts::STORAGE_MAPPING_INDEX_SUFFIX,
            'target_transform' => $page_dir . $slug . DBVC_CC_V2_Contracts::STORAGE_TARGET_TRANSFORM_SUFFIX,
            'mapping_recommendations' => $page_dir . $slug . DBVC_CC_V2_Contracts::STORAGE_MAPPING_RECOMMENDATIONS_SUFFIX,
            'mapping_decisions' => $page_dir . $slug . DBVC_CC_V2_Contracts::STORAGE_MAPPING_DECISIONS_SUFFIX,
            'media_candidates' => $page_dir . $slug . DBVC_CC_V2_Contracts::STORAGE_MEDIA_CANDIDATES_SUFFIX,
            'media_decisions' => $page_dir . $slug . DBVC_CC_V2_Contracts::STORAGE_MEDIA_DECISIONS_SUFFIX,
            'qa_report' => $page_dir . $slug . DBVC_CC_V2_Contracts::STORAGE_QA_REPORT_SUFFIX,
        ];

        $artifact_relatives = [];
        foreach ($artifact_paths as $key => $path) {
            $artifact_relatives[$key] = $this->get_domain_relative_path($path, $domain_context['domain_dir']);
        }

        return [
            'domain' => $domain_context['domain'],
            'domain_dir' => $domain_context['domain_dir'],
            'inventory' => $inventory,
            'inventory_fingerprint' => isset($inventory['inventory_fingerprint']) ? (string) $inventory['inventory_fingerprint'] : '',
            'row' => $row,
            'page_id' => $page_id,
            'page_dir' => $page_dir,
            'slug' => $slug,
            'source_url' => isset($row['source_url']) ? (string) $row['source_url'] : '',
            'normalized_url' => $normalized_url,
            'path' => isset($row['path']) ? (string) $row['path'] : '',
            'artifact_paths' => $artifact_paths,
            'artifact_relatives' => $artifact_relatives,
        ];
    }

    /**
     * @param string $path
     * @param string $error_code
     * @param string $label
     * @return array<string, mixed>|WP_Error
     */
    public function read_required_artifact($path, $error_code, $label)
    {
        $artifact = $this->read_json_file($path);
        if (is_array($artifact)) {
            return $artifact;
        }

        return new WP_Error(
            sanitize_key((string) $error_code),
            sprintf(__('The V2 %s artifact is missing or invalid.', 'dbvc'), sanitize_text_field((string) $label)),
            ['status' => 404]
        );
    }

    /**
     * @param mixed $value
     * @return string
     */
    public function compute_fingerprint($value)
    {
        return hash('sha256', (string) wp_json_encode($value, JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param string $path
     * @param string $domain_dir
     * @return string
     */
    public function get_domain_relative_path($path, $domain_dir)
    {
        return ltrim(str_replace(wp_normalize_path((string) $domain_dir), '', wp_normalize_path((string) $path)), '/');
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
