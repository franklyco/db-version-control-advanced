<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Mapping_Decision_Service
{
    /**
     * @var DBVC_CC_Mapping_Decision_Service|null
     */
    private static $instance = null;

    /**
     * @return DBVC_CC_Mapping_Decision_Service
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
     * @param string $path
     * @return array<string, mixed>|WP_Error
     */
    public function get_decision($domain, $path)
    {
        $context = $this->resolve_page_context($domain, $path);
        if (is_wp_error($context)) {
            return $context;
        }

        $existing = $this->read_json_file($context['decision_file']);
        if (is_array($existing)) {
            return [
                'status' => 'loaded',
                'domain' => $context['domain'],
                'path' => $context['path'],
                'decision_file' => $context['decision_file'],
                'mapping_decision' => $existing,
            ];
        }

        return [
            'status' => 'empty',
            'domain' => $context['domain'],
            'path' => $context['path'],
            'decision_file' => $context['decision_file'],
            'mapping_decision' => $this->build_empty_record($context, ''),
        ];
    }

    /**
     * @param string              $domain
     * @param string              $path
     * @param array<string, mixed> $payload
     * @param int                 $user_id
     * @return array<string, mixed>|WP_Error
     */
    public function save_decision($domain, $path, array $payload, $user_id)
    {
        $context = $this->resolve_page_context($domain, $path);
        if (is_wp_error($context)) {
            return $context;
        }

        $existing = $this->read_json_file($context['decision_file']);
        $catalog_fingerprint = isset($payload['catalog_fingerprint']) ? (string) $payload['catalog_fingerprint'] : '';
        if ($catalog_fingerprint === '' && is_array($existing) && isset($existing['catalog_fingerprint'])) {
            $catalog_fingerprint = (string) $existing['catalog_fingerprint'];
        }

        $record = $this->build_empty_record($context, $catalog_fingerprint);
        if (is_array($existing)) {
            $record['generated_at'] = isset($existing['generated_at']) ? (string) $existing['generated_at'] : $record['generated_at'];
        }

        $decision_status = isset($payload['decision_status']) ? sanitize_key((string) $payload['decision_status']) : '';
        $record['decision_status'] = $decision_status !== '' ? $decision_status : 'pending';
        $record['approved_mappings'] = $this->sanitize_rows(isset($payload['approved_mappings']) && is_array($payload['approved_mappings']) ? $payload['approved_mappings'] : []);
        $record['approved_media_mappings'] = $this->sanitize_rows(isset($payload['approved_media_mappings']) && is_array($payload['approved_media_mappings']) ? $payload['approved_media_mappings'] : []);
        $record['overrides'] = $this->sanitize_rows(isset($payload['overrides']) && is_array($payload['overrides']) ? $payload['overrides'] : []);
        $record['rejections'] = $this->sanitize_rows(isset($payload['rejections']) && is_array($payload['rejections']) ? $payload['rejections'] : []);
        $record['unresolved_fields'] = $this->sanitize_rows(isset($payload['unresolved_fields']) && is_array($payload['unresolved_fields']) ? $payload['unresolved_fields'] : []);
        $record['unresolved_media'] = $this->sanitize_rows(isset($payload['unresolved_media']) && is_array($payload['unresolved_media']) ? $payload['unresolved_media'] : []);
        if (array_key_exists('object_hints', $payload) && is_array($payload['object_hints'])) {
            $record['object_hints'] = $this->dbvc_cc_sanitize_object_hints($payload['object_hints'], $user_id);
        } elseif (is_array($existing) && isset($existing['object_hints']) && is_array($existing['object_hints'])) {
            $record['object_hints'] = $this->dbvc_cc_sanitize_object_hints($existing['object_hints'], 0);
        }
        $record['updated_at'] = current_time('c');
        $record['updated_by'] = absint($user_id);
        $record['provenance']['decision_actor'] = $record['updated_by'];
        $record['provenance']['decision_timestamp'] = $record['updated_at'];
        $record['provenance']['decision_policy_mode'] = 'manual_review_required';

        if ($record['decision_status'] === 'pending' && ! empty($record['approved_mappings']) && empty($record['unresolved_fields'])) {
            $record['decision_status'] = 'approved';
        }

        if (! DBVC_CC_Artifact_Manager::write_json_file($context['decision_file'], $record)) {
            return new WP_Error(
                'dbvc_cc_mapping_decision_write_failed',
                __('Could not persist mapping decision artifact.', 'dbvc'),
                ['status' => 500]
            );
        }

        DBVC_CC_Artifact_Manager::log_event(
            [
                'stage' => 'mapping_decision',
                'status' => 'saved',
                'page_url' => $context['source_url'],
                'path' => $context['path'],
                'message' => 'Mapping decision artifact saved.',
            ]
        );

        return [
            'status' => 'saved',
            'domain' => $context['domain'],
            'path' => $context['path'],
            'decision_file' => $context['decision_file'],
            'mapping_decision' => $record,
        ];
    }

    /**
     * @param array<string, string> $context
     * @param string                $catalog_fingerprint
     * @return array<string, mixed>
     */
    private function build_empty_record(array $context, $catalog_fingerprint)
    {
        return [
            'artifact_schema_version' => DBVC_CC_Contracts::ARTIFACT_SCHEMA_VERSION,
            'artifact_type' => 'mapping-decisions.v1',
            'source_url' => $context['source_url'],
            'generated_at' => current_time('c'),
            'catalog_fingerprint' => sanitize_text_field((string) $catalog_fingerprint),
            'decision_status' => 'pending',
            'approved_mappings' => [],
            'approved_media_mappings' => [],
            'overrides' => [],
            'rejections' => [],
            'unresolved_fields' => [],
            'unresolved_media' => [],
            'object_hints' => [
                'override_post_type' => '',
                'source' => 'auto',
                'updated_at' => null,
                'updated_by' => 0,
            ],
            'updated_at' => null,
            'updated_by' => 0,
            'provenance' => [
                'source_url' => $context['source_url'],
                'license_hint' => '',
                'decision_actor' => 0,
                'decision_timestamp' => null,
                'decision_policy_mode' => 'manual_review_required',
            ],
        ];
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sanitize_rows(array $rows)
    {
        $sanitized_rows = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sanitized_rows[] = $this->sanitize_recursive($row);
        }

        return $sanitized_rows;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function sanitize_recursive($value)
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $nested) {
                $safe_key = is_string($key) ? sanitize_key($key) : $key;
                $sanitized[$safe_key] = $this->sanitize_recursive($nested);
            }
            return $sanitized;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            return sanitize_text_field((string) $value);
        }

        return '';
    }

    /**
     * @param string $domain
     * @param string $path
     * @return array<string, string>|WP_Error
     */
    private function resolve_page_context($domain, $path)
    {
        $domain_key = $this->sanitize_domain_key($domain);
        if ($domain_key === '') {
            return new WP_Error(
                'dbvc_cc_mapping_decision_domain_invalid',
                __('A valid domain key is required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $relative_path = $this->normalize_relative_path($path);
        if ($relative_path === '') {
            return new WP_Error(
                'dbvc_cc_mapping_decision_path_invalid',
                __('A valid page path is required.', 'dbvc'),
                ['status' => 400]
            );
        }

        $base_dir = DBVC_CC_Artifact_Manager::get_storage_base_dir();
        if (! is_string($base_dir) || $base_dir === '' || ! is_dir($base_dir)) {
            return new WP_Error(
                'dbvc_cc_mapping_decision_storage_missing',
                __('Content migration storage path is not available.', 'dbvc'),
                ['status' => 500]
            );
        }

        $base_real = realpath($base_dir);
        if (! is_string($base_real)) {
            return new WP_Error(
                'dbvc_cc_mapping_decision_storage_invalid',
                __('Could not resolve content migration storage path.', 'dbvc'),
                ['status' => 500]
            );
        }

        $domain_dir = trailingslashit($base_real) . $domain_key;
        if (! is_dir($domain_dir)) {
            return new WP_Error(
                'dbvc_cc_mapping_decision_domain_missing',
                __('No crawl storage was found for the requested domain.', 'dbvc'),
                ['status' => 404]
            );
        }

        $page_dir = trailingslashit($domain_dir) . $relative_path;
        if (! is_dir($page_dir)) {
            return new WP_Error(
                'dbvc_cc_mapping_decision_path_missing',
                __('No crawl storage was found for the requested path.', 'dbvc'),
                ['status' => 404]
            );
        }

        $slug = basename($relative_path);
        $artifact_file = trailingslashit($page_dir) . $slug . '.json';
        $decision_file = trailingslashit($page_dir) . $slug . DBVC_CC_Contracts::STORAGE_MAPPING_DECISIONS_V1_SUFFIX;
        if (! dbvc_cc_path_is_within($decision_file, $base_real)) {
            return new WP_Error(
                'dbvc_cc_mapping_decision_file_invalid',
                __('Mapping decision file path is invalid.', 'dbvc'),
                ['status' => 500]
            );
        }

        $artifact = $this->read_json_file($artifact_file);
        $source_url = '';
        if (is_array($artifact)) {
            if (isset($artifact['source_url'])) {
                $source_url = esc_url_raw((string) $artifact['source_url']);
            }
            if ($source_url === '' && isset($artifact['provenance']['source_url'])) {
                $source_url = esc_url_raw((string) $artifact['provenance']['source_url']);
            }
        }
        if ($source_url === '') {
            $source_url = 'https://' . $domain_key . '/' . ltrim($relative_path, '/');
        }

        return [
            'domain' => $domain_key,
            'path' => $relative_path,
            'source_url' => $source_url,
            'decision_file' => $decision_file,
        ];
    }

    /**
     * @param array<string, mixed> $hints
     * @param int                  $user_id
     * @return array<string, mixed>
     */
    private function dbvc_cc_sanitize_object_hints(array $hints, $user_id = 0)
    {
        $override_post_type = isset($hints['override_post_type']) ? sanitize_key((string) $hints['override_post_type']) : '';
        if ($override_post_type !== '') {
            $public_post_types = array_values(get_post_types(['public' => true], 'names'));
            if (! in_array($override_post_type, $public_post_types, true)) {
                $override_post_type = '';
            }
        }

        $source = $override_post_type === '' ? 'auto' : 'manual_override';
        $updated_by = absint($user_id);

        $updated_at = '';
        if ($updated_by > 0) {
            $updated_at = current_time('c');
        } elseif (isset($hints['updated_at'])) {
            $updated_at = sanitize_text_field((string) $hints['updated_at']);
        }

        return [
            'override_post_type' => $override_post_type,
            'source' => $source,
            'updated_at' => $updated_at,
            'updated_by' => $updated_by > 0
                ? $updated_by
                : (isset($hints['updated_by']) ? absint($hints['updated_by']) : 0),
        ];
    }

    /**
     * @param string $path
     * @return array<string, mixed>|null
     */
    private function read_json_file($path)
    {
        if (! is_string($path) || $path === '' || ! file_exists($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param string $domain
     * @return string
     */
    private function sanitize_domain_key($domain)
    {
        $value = strtolower(trim((string) $domain));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^a-z0-9.\-]/', '', $value);
        return is_string($value) ? $value : '';
    }

    /**
     * @param string $path
     * @return string
     */
    private function normalize_relative_path($path)
    {
        $value = wp_normalize_path((string) $path);
        $value = trim($value, '/');
        if ($value === '') {
            return 'home';
        }

        if (strpos($value, '..') !== false) {
            return '';
        }

        $segments = array_filter(explode('/', $value), static function ($segment) {
            return $segment !== '';
        });

        $normalized_segments = [];
        foreach ($segments as $segment) {
            $safe_segment = sanitize_title((string) $segment);
            if ($safe_segment === '') {
                continue;
            }
            $normalized_segments[] = $safe_segment;
        }

        return empty($normalized_segments) ? '' : implode('/', $normalized_segments);
    }
}
