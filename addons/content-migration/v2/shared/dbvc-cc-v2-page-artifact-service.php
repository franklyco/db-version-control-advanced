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

        return $this->build_page_context($domain_context, $inventory, $page_id, '');
    }

    /**
     * @param string $run_id
     * @param string $page_id
     * @return array<string, mixed>|WP_Error
     */
    public function resolve_page_context_for_run($run_id, $page_id)
    {
        $run_id = sanitize_text_field((string) $run_id);
        if ($run_id === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $journey = DBVC_CC_V2_Domain_Journey_Service::get_instance();
        $domain = $journey->find_domain_by_journey_id($run_id);
        if ($domain === '') {
            return new WP_Error(
                'dbvc_cc_v2_run_missing',
                __('The requested V2 run could not be found.', 'dbvc'),
                ['status' => 404]
            );
        }

        $domain_context = $journey->get_domain_context($domain);
        if (is_wp_error($domain_context)) {
            return $domain_context;
        }

        $inventory = DBVC_CC_V2_URL_Inventory_Service::get_instance()->get_inventory_for_run($run_id);
        if (is_wp_error($inventory)) {
            return $inventory;
        }

        return $this->build_page_context($domain_context, $inventory, $page_id, $run_id);
    }

    /**
     * @param string               $path
     * @param array<string, mixed> $artifact
     * @param string               $run_id
     * @return bool
     */
    public function write_page_artifact($path, array $artifact, $run_id = '')
    {
        $path = (string) $path;
        if ($path === '') {
            return false;
        }

        if ($this->is_run_scoped_artifact_path($path)) {
            $run_scoped_dir = dirname($path);
            if (! dbvc_cc_create_security_files($run_scoped_dir)) {
                return false;
            }

            return DBVC_CC_Artifact_Manager::write_json_file($path, $artifact);
        }

        if (! DBVC_CC_Artifact_Manager::write_json_file($path, $artifact)) {
            return false;
        }

        $normalized_run_id = $this->normalize_run_id($run_id);
        if ($normalized_run_id === '') {
            return true;
        }

        $run_scoped_path = $this->get_run_scoped_artifact_path($path, $normalized_run_id);
        if ($run_scoped_path === '') {
            return false;
        }

        $run_scoped_dir = dirname($run_scoped_path);
        if (! dbvc_cc_create_security_files($run_scoped_dir)) {
            return false;
        }

        return DBVC_CC_Artifact_Manager::write_json_file($run_scoped_path, $artifact);
    }

    /**
     * @param array<string, string> $domain_context
     * @param array<string, mixed>  $inventory
     * @param string                $page_id
     * @param string                $run_id
     * @return array<string, mixed>|WP_Error
     */
    private function build_page_context(array $domain_context, array $inventory, $page_id, $run_id = '')
    {
        $rows = isset($inventory['urls']) && is_array($inventory['urls']) ? $inventory['urls'] : [];
        $page_id = sanitize_text_field((string) $page_id);
        $run_id = $this->normalize_run_id($run_id);

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
        $current_artifact_paths = [
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
        $run_artifact_paths = $run_id !== ''
            ? $this->build_run_scoped_artifact_paths($current_artifact_paths, $run_id)
            : [];
        $current_page_belongs_to_run = $run_id !== ''
            ? $this->artifact_matches_run($current_artifact_paths['raw'], $run_id)
            : true;
        $artifact_paths = $run_id !== ''
            ? $this->resolve_run_scoped_artifact_paths($current_artifact_paths, $run_artifact_paths, $run_id)
            : $current_artifact_paths;
        $write_artifact_paths = $run_id !== '' && ! $current_page_belongs_to_run
            ? $run_artifact_paths
            : $current_artifact_paths;

        $artifact_relatives = [];
        foreach ($artifact_paths as $key => $path) {
            $artifact_relatives[$key] = $this->get_domain_relative_path($path, $domain_context['domain_dir']);
        }
        $write_artifact_relatives = [];
        foreach ($write_artifact_paths as $key => $path) {
            $write_artifact_relatives[$key] = $this->get_domain_relative_path($path, $domain_context['domain_dir']);
        }

        return [
            'domain' => $domain_context['domain'],
            'domain_dir' => $domain_context['domain_dir'],
            'inventory' => $inventory,
            'inventory_fingerprint' => isset($inventory['inventory_fingerprint']) ? (string) $inventory['inventory_fingerprint'] : '',
            'row' => $row,
            'run_id' => $run_id,
            'page_id' => $page_id,
            'page_dir' => $page_dir,
            'slug' => $slug,
            'source_url' => isset($row['source_url']) ? (string) $row['source_url'] : '',
            'normalized_url' => $normalized_url,
            'path' => isset($row['path']) ? (string) $row['path'] : '',
            'current_page_belongs_to_run' => $current_page_belongs_to_run,
            'current_artifact_paths' => $current_artifact_paths,
            'run_artifact_paths' => $run_artifact_paths,
            'artifact_paths' => $artifact_paths,
            'artifact_relatives' => $artifact_relatives,
            'write_artifact_paths' => $write_artifact_paths,
            'write_artifact_relatives' => $write_artifact_relatives,
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

    /**
     * @param array<string, string> $current_artifact_paths
     * @param string                $run_id
     * @return array<string, string>
     */
    private function build_run_scoped_artifact_paths(array $current_artifact_paths, $run_id)
    {
        $run_scoped_paths = [];
        foreach ($current_artifact_paths as $key => $path) {
            $run_scoped_paths[$key] = $this->get_run_scoped_artifact_path($path, $run_id);
        }

        return $run_scoped_paths;
    }

    /**
     * @param array<string, string> $current_artifact_paths
     * @param array<string, string> $run_artifact_paths
     * @param string                $run_id
     * @return array<string, string>
     */
    private function resolve_run_scoped_artifact_paths(array $current_artifact_paths, array $run_artifact_paths, $run_id)
    {
        $resolved_paths = [];

        foreach ($current_artifact_paths as $key => $current_path) {
            $run_scoped_path = isset($run_artifact_paths[$key]) ? (string) $run_artifact_paths[$key] : '';
            if ($this->artifact_matches_run($current_path, $run_id)) {
                $resolved_paths[$key] = $current_path;
                continue;
            }

            $resolved_paths[$key] = ($run_scoped_path !== '' && file_exists($run_scoped_path))
                ? $run_scoped_path
                : $current_path;
        }

        return $resolved_paths;
    }

    /**
     * @param string $path
     * @param string $run_id
     * @return bool
     */
    private function artifact_matches_run($path, $run_id)
    {
        $artifact = $this->read_json_file($path);
        if (! is_array($artifact)) {
            return false;
        }

        return isset($artifact['journey_id']) && sanitize_text_field((string) $artifact['journey_id']) === $this->normalize_run_id($run_id);
    }

    /**
     * @param string $path
     * @param string $run_id
     * @return string
     */
    private function get_run_scoped_artifact_path($path, $run_id)
    {
        $normalized_run_id = $this->normalize_run_id($run_id);
        $path = (string) $path;
        if ($normalized_run_id === '' || $path === '') {
            return '';
        }

        return trailingslashit(dirname($path))
            . DBVC_CC_V2_Contracts::STORAGE_PAGE_RUNS_SUBDIR
            . '/'
            . $normalized_run_id
            . '/'
            . basename($path);
    }

    /**
     * @param string $path
     * @return bool
     */
    private function is_run_scoped_artifact_path($path)
    {
        $path = wp_normalize_path((string) $path);
        if ($path === '') {
            return false;
        }

        return strpos($path, '/' . DBVC_CC_V2_Contracts::STORAGE_PAGE_RUNS_SUBDIR . '/') !== false;
    }

    /**
     * @param string $run_id
     * @return string
     */
    private function normalize_run_id($run_id)
    {
        $run_id = sanitize_text_field((string) $run_id);
        $run_id = preg_replace('/[^A-Za-z0-9_-]/', '_', $run_id);

        return is_string($run_id) ? $run_id : '';
    }
}
