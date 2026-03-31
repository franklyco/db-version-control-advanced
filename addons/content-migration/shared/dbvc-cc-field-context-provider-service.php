<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_CC_Field_Context_Provider_Service
{
    private const REMOTE_ROUTE_PATH = '/wp-json/vertical-framework/v1/field-context';

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
        return $this->is_local_available() || $this->has_remote_provider_config();
    }

    /**
     * @return bool
     */
    public function is_local_available()
    {
        return function_exists('vf_field_context_get_service_catalog_payload');
    }

    /**
     * @return array<string, mixed>
     */
    public function get_consumer_policy()
    {
        if (! class_exists('DBVC_CC_V2_Contracts')) {
            return [
                'integration_mode' => 'auto',
                'use_legacy_fallback' => true,
                'warn_on_degraded' => true,
                'block_on_missing' => false,
            ];
        }

        $settings = DBVC_CC_V2_Contracts::get_field_context_settings();

        return [
            'integration_mode' => isset($settings['integrationMode']) ? sanitize_key((string) $settings['integrationMode']) : DBVC_CC_V2_Contracts::FIELD_CONTEXT_MODE_AUTO,
            'use_legacy_fallback' => ! empty($settings['useLegacyFallback']),
            'warn_on_degraded' => ! empty($settings['warnOnDegraded']),
            'block_on_missing' => ! empty($settings['blockOnMissing']),
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     * @return array<string, mixed>
     */
    public function get_mapping_index(array $criteria = [])
    {
        $criteria = $this->normalize_criteria($criteria);
        $policy = $this->get_consumer_policy();
        $transport = $this->resolve_transport($criteria, $policy);
        $remote_config = 'remote' === $transport ? $this->get_remote_provider_config($criteria, $policy) : [];
        $cache_key = md5((string) wp_json_encode([
            'criteria' => $criteria,
            'policy' => $policy,
            'transport' => $transport,
            'remote' => $this->get_remote_cache_fingerprint($remote_config),
        ]));

        if (isset($this->mapping_index_cache[$cache_key]) && is_array($this->mapping_index_cache[$cache_key])) {
            return $this->mapping_index_cache[$cache_key];
        }

        if (($policy['integration_mode'] ?? DBVC_CC_V2_Contracts::FIELD_CONTEXT_MODE_AUTO) === DBVC_CC_V2_Contracts::FIELD_CONTEXT_MODE_OFF) {
            $result = $this->build_unavailable_result(
                'dbvc_cc_field_context_disabled',
                __('DBVC field context integration is disabled in Content Collector add-on settings.', 'dbvc'),
                200
            );
            $result['criteria'] = $criteria;
            $result['consumer_policy'] = $policy;
            $result['transport'] = 'disabled';
            $result['diagnostics'] = [
                'degraded' => false,
                'blocked' => false,
                'legacy_only_count' => 0,
                'missing_count' => 0,
                'warnings' => [],
            ];
            $this->mapping_index_cache[$cache_key] = $result;

            return $result;
        }

        if ('remote' === $transport) {
            $payload = $this->fetch_remote_mapping_index_payload($criteria, $remote_config);
            if (is_wp_error($payload)) {
                $result = $this->build_error_result($payload, 'remote', $remote_config);
                $result['criteria'] = $criteria;
                $result['consumer_policy'] = $policy;
                $result['diagnostics'] = $this->build_diagnostics($result, 0, 0, $policy);
                $this->mapping_index_cache[$cache_key] = $result;

                return $result;
            }

            $result = $this->normalize_mapping_index_payload($payload, $criteria, $policy, 'remote', $remote_config);
            $this->mapping_index_cache[$cache_key] = $result;

            return $result;
        }

        if (! $this->is_local_available()) {
            $result = $this->build_unavailable_result(
                'vf_field_context_unavailable',
                __('Vertical field context provider is not available in this runtime, and no remote provider endpoint is configured.', 'dbvc'),
                503,
                'unavailable'
            );
            $result['criteria'] = $criteria;
            $result['consumer_policy'] = $policy;
            $result['diagnostics'] = $this->build_diagnostics($result, 0, 0, $policy);
            $this->mapping_index_cache[$cache_key] = $result;

            return $result;
        }

        $payload = vf_field_context_get_service_catalog_payload($criteria, 'mapping');
        if (is_wp_error($payload)) {
            $result = $this->build_error_result($payload, 'local');
            $result['criteria'] = $criteria;
            $result['consumer_policy'] = $policy;
            $result['diagnostics'] = $this->build_diagnostics($result, 0, 0, $policy);
            $this->mapping_index_cache[$cache_key] = $result;

            return $result;
        }

        $result = $this->normalize_mapping_index_payload($payload, $criteria, $policy, 'local');
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
    private function normalize_mapping_index_payload(array $payload, array $criteria, array $policy, $transport = 'local', array $remote_config = [])
    {
        $provider = isset($payload['provider']) && is_array($payload['provider']) ? $payload['provider'] : [];
        $catalog_meta = isset($payload['catalog_meta']) && is_array($payload['catalog_meta']) ? $payload['catalog_meta'] : [];
        $groups = [];
        $entries_by_acf_key = [];
        $legacy_only_count = 0;
        $missing_count = 0;
        $group_rows = isset($payload['data']['groups']) && is_array($payload['data']['groups'])
            ? $payload['data']['groups']
            : [];

        foreach ($group_rows as $group_key => $group_row) {
            if (! is_array($group_row)) {
                continue;
            }

            $normalized_group = $this->normalize_group_payload($group_row, $policy);
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

                $normalized_entry = $this->normalize_entry_payload($field_row, $policy);
                $acf_field_key = isset($normalized_entry['acf_key']) ? (string) $normalized_entry['acf_key'] : '';
                if ($acf_field_key === '') {
                    continue;
                }

                $status_code = isset($normalized_entry['status_meta']['code']) ? sanitize_key((string) $normalized_entry['status_meta']['code']) : '';
                if ($status_code === 'legacy_only') {
                    $legacy_only_count++;
                } elseif ($status_code === 'missing') {
                    $missing_count++;
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
            'transport' => sanitize_key((string) $transport),
            'remote' => $this->build_remote_meta($remote_config),
            'provider' => $this->normalize_provider_meta($provider),
            'catalog_meta' => $this->normalize_catalog_meta($catalog_meta),
            'consumer_policy' => $policy,
            'groups_by_acf_key' => $groups,
            'entries_by_acf_key' => $entries_by_acf_key,
            'diagnostics' => $this->build_diagnostics(
                [
                    'available' => true,
                    'catalog_meta' => $catalog_meta,
                    'error' => [],
                ],
                $legacy_only_count,
                $missing_count,
                $policy
            ),
            'error' => [],
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $policy
     * @return string
     */
    private function resolve_transport(array $criteria, array $policy)
    {
        if (($policy['integration_mode'] ?? DBVC_CC_V2_Contracts::FIELD_CONTEXT_MODE_AUTO) === DBVC_CC_V2_Contracts::FIELD_CONTEXT_MODE_OFF) {
            return 'disabled';
        }

        if ($this->is_local_available()) {
            return 'local';
        }

        return $this->has_remote_provider_config($criteria, $policy) ? 'remote' : 'unavailable';
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $policy
     * @return bool
     */
    private function has_remote_provider_config(array $criteria = [], array $policy = [])
    {
        $config = $this->get_remote_provider_config($criteria, $policy);

        return ! empty($config['endpoint_url']);
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    private function get_remote_provider_config(array $criteria = [], array $policy = [])
    {
        $config = apply_filters('dbvc_cc_field_context_remote_provider_config', [], $criteria, $policy, $this);
        if (! is_array($config)) {
            $config = [];
        }

        return $this->normalize_remote_provider_config($config);
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalize_remote_provider_config(array $config)
    {
        $endpoint_url = isset($config['endpoint_url']) ? esc_url_raw((string) $config['endpoint_url']) : '';
        $base_url = isset($config['base_url']) ? esc_url_raw((string) $config['base_url']) : '';
        if ($endpoint_url === '' && $base_url !== '') {
            $endpoint_url = untrailingslashit($base_url) . self::REMOTE_ROUTE_PATH;
        }

        $headers = [];
        if (isset($config['headers']) && is_array($config['headers'])) {
            foreach ($config['headers'] as $header_key => $header_value) {
                $header_key = sanitize_text_field((string) $header_key);
                if ($header_key === '' || ! is_scalar($header_value)) {
                    continue;
                }

                $headers[$header_key] = trim((string) $header_value);
            }
        }

        $basic_auth_user = isset($config['basic_auth_user']) ? (string) $config['basic_auth_user'] : '';
        $basic_auth_password = isset($config['basic_auth_password']) ? (string) $config['basic_auth_password'] : '';
        if ($basic_auth_user !== '' && $basic_auth_password !== '' && empty($headers['Authorization'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($basic_auth_user . ':' . $basic_auth_password);
        }

        return [
            'base_url' => $base_url,
            'endpoint_url' => $endpoint_url,
            'headers' => $headers,
            'timeout' => isset($config['timeout']) ? max(1, (int) $config['timeout']) : 10,
            'verify_ssl' => ! array_key_exists('verify_ssl', $config) || ! empty($config['verify_ssl']),
        ];
    }

    /**
     * @param array<string, mixed> $criteria
     * @param array<string, mixed> $remote_config
     * @return array<string, mixed>|WP_Error
     */
    private function fetch_remote_mapping_index_payload(array $criteria, array $remote_config)
    {
        $endpoint_url = isset($remote_config['endpoint_url']) ? esc_url_raw((string) $remote_config['endpoint_url']) : '';
        if ($endpoint_url === '') {
            return new WP_Error(
                'dbvc_cc_field_context_remote_unconfigured',
                __('Remote field-context provider is not configured.', 'dbvc'),
                ['status' => 503]
            );
        }

        $query_args = ['profile' => 'mapping'];
        foreach (['group', 'post_id', 'key_path', 'name_path', 'acf_key', 'acf_name'] as $key) {
            if (! array_key_exists($key, $criteria)) {
                continue;
            }

            $value = $criteria[$key];
            if ($value === null || $value === '' || $value === 0 || $value === '0') {
                continue;
            }

            $query_args[$key] = $value;
        }

        $request_args = [
            'timeout' => isset($remote_config['timeout']) ? max(1, (int) $remote_config['timeout']) : 10,
            'sslverify' => ! array_key_exists('verify_ssl', $remote_config) || ! empty($remote_config['verify_ssl']),
            'headers' => isset($remote_config['headers']) && is_array($remote_config['headers']) ? $remote_config['headers'] : [],
        ];
        $request_args = apply_filters('dbvc_cc_field_context_remote_request_args', $request_args, $criteria, $remote_config, $this);

        $response = wp_remote_get(add_query_arg($query_args, $endpoint_url), $request_args);
        if (is_wp_error($response)) {
            return $response;
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);

        if (304 === $status) {
            return new WP_Error(
                'dbvc_cc_field_context_remote_not_modified',
                __('Remote field-context provider returned 304 without a reusable cached payload.', 'dbvc'),
                ['status' => 502]
            );
        }

        if ($status < 200 || $status >= 300) {
            $error_payload = json_decode($body, true);
            $error_message = is_array($error_payload) && ! empty($error_payload['message'])
                ? (string) $error_payload['message']
                : sprintf(__('Remote field-context provider returned HTTP %d.', 'dbvc'), $status);

            return new WP_Error(
                'dbvc_cc_field_context_remote_http_' . $status,
                $error_message,
                ['status' => $status]
            );
        }

        $payload = json_decode($body, true);
        if (! is_array($payload)) {
            return new WP_Error(
                'dbvc_cc_field_context_remote_invalid_json',
                __('Remote field-context provider returned invalid JSON.', 'dbvc'),
                ['status' => 502]
            );
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $remote_config
     * @return string
     */
    private function get_remote_cache_fingerprint(array $remote_config)
    {
        return md5((string) wp_json_encode($this->build_remote_meta($remote_config)));
    }

    /**
     * @param array<string, mixed> $remote_config
     * @return array<string, mixed>
     */
    private function build_remote_meta(array $remote_config)
    {
        return [
            'base_url' => isset($remote_config['base_url']) ? esc_url_raw((string) $remote_config['base_url']) : '',
            'endpoint_url' => isset($remote_config['endpoint_url']) ? esc_url_raw((string) $remote_config['endpoint_url']) : '',
            'timeout' => isset($remote_config['timeout']) ? max(1, (int) $remote_config['timeout']) : 10,
            'verify_ssl' => ! array_key_exists('verify_ssl', $remote_config) || ! empty($remote_config['verify_ssl']),
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
    private function normalize_group_payload(array $group, array $policy)
    {
        $resolved_purpose = isset($group['resolved_purpose']) ? sanitize_textarea_field((string) $group['resolved_purpose']) : '';
        $default_purpose = isset($group['default_purpose']) ? sanitize_textarea_field((string) $group['default_purpose']) : '';
        $legacy = ! empty($policy['use_legacy_fallback']) && isset($group['legacy']) && is_array($group['legacy']) ? $group['legacy'] : [];

        return [
            'acf_key' => isset($group['acf_key']) ? sanitize_key((string) $group['acf_key']) : '',
            'name' => isset($group['name']) ? sanitize_key((string) $group['name']) : '',
            'label' => isset($group['label']) ? sanitize_text_field((string) $group['label']) : '',
            'key_path' => isset($group['key_path']) ? sanitize_text_field((string) $group['key_path']) : '',
            'name_path' => isset($group['name_path']) ? sanitize_text_field((string) $group['name_path']) : '',
            'scope' => isset($group['scope']) ? sanitize_key((string) $group['scope']) : '',
            'resolved_purpose' => $resolved_purpose,
            'default_purpose' => $default_purpose,
            'effective_purpose' => $this->build_effective_purpose($resolved_purpose, $default_purpose, $legacy),
            'status_meta' => isset($group['status_meta']) && is_array($group['status_meta']) ? $group['status_meta'] : [],
            'coverage' => isset($group['coverage']) && is_array($group['coverage']) ? $group['coverage'] : [],
            'has_override' => ! empty($group['has_override']),
            'resolved_from' => isset($group['resolved_from']) ? sanitize_key((string) $group['resolved_from']) : '',
            'context' => isset($group['context']) && is_array($group['context']) ? $group['context'] : [],
            'default_context' => isset($group['default_context']) && is_array($group['default_context']) ? $group['default_context'] : [],
            'legacy' => $legacy,
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function normalize_entry_payload(array $entry, array $policy)
    {
        $resolved_purpose = isset($entry['resolved_purpose']) ? sanitize_textarea_field((string) $entry['resolved_purpose']) : '';
        $default_purpose = isset($entry['default_purpose']) ? sanitize_textarea_field((string) $entry['default_purpose']) : '';
        $legacy = ! empty($policy['use_legacy_fallback']) && isset($entry['legacy']) && is_array($entry['legacy']) ? $entry['legacy'] : [];

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
            'resolved_purpose' => $resolved_purpose,
            'default_purpose' => $default_purpose,
            'effective_purpose' => $this->build_effective_purpose($resolved_purpose, $default_purpose, $legacy),
            'status_meta' => isset($entry['status_meta']) && is_array($entry['status_meta']) ? $entry['status_meta'] : [],
            'has_override' => ! empty($entry['has_override']),
            'resolved_from' => isset($entry['resolved_from']) ? sanitize_key((string) $entry['resolved_from']) : '',
            'context' => isset($entry['context']) && is_array($entry['context']) ? $entry['context'] : [],
            'default_context' => isset($entry['default_context']) && is_array($entry['default_context']) ? $entry['default_context'] : [],
            'legacy' => $legacy,
        ];
    }

    /**
     * @param string               $resolved_purpose
     * @param string               $default_purpose
     * @param array<string, mixed> $legacy
     * @return string
     */
    private function build_effective_purpose($resolved_purpose, $default_purpose, array $legacy)
    {
        $resolved_purpose = sanitize_textarea_field((string) $resolved_purpose);
        if ($resolved_purpose !== '') {
            return $resolved_purpose;
        }

        $default_purpose = sanitize_textarea_field((string) $default_purpose);
        if ($default_purpose !== '') {
            return $default_purpose;
        }

        if (! empty($legacy['gardenai_field_purpose'])) {
            return sanitize_textarea_field((string) $legacy['gardenai_field_purpose']);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $result
     * @param int                  $legacy_only_count
     * @param int                  $missing_count
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    private function build_diagnostics(array $result, $legacy_only_count, $missing_count, array $policy)
    {
        $catalog_meta = isset($result['catalog_meta']) && is_array($result['catalog_meta']) ? $result['catalog_meta'] : [];
        $catalog_status = isset($catalog_meta['status']) ? sanitize_key((string) $catalog_meta['status']) : '';
        $warnings = [];
        $degraded = ! empty($result['error']) || in_array($catalog_status, ['missing', 'partial', 'stale'], true) || $legacy_only_count > 0 || $missing_count > 0;
        $blocked = false;

        if (! empty($result['error'])) {
            $warnings[] = [
                'code' => isset($result['error']['code']) ? sanitize_key((string) $result['error']['code']) : 'field_context_error',
                'message' => isset($result['error']['message']) ? sanitize_text_field((string) $result['error']['message']) : __('Field context provider returned an error.', 'dbvc'),
            ];
        }

        if (in_array($catalog_status, ['missing', 'partial', 'stale'], true)) {
            $warnings[] = [
                'code' => 'field_context_catalog_' . $catalog_status,
                'message' => sprintf(__('Field context catalog status is `%s`.', 'dbvc'), $catalog_status),
            ];
        }

        if ($legacy_only_count > 0) {
            $warnings[] = [
                'code' => 'field_context_legacy_only_entries',
                'message' => sprintf(__('Field context still relies on legacy-only hints for %d entries.', 'dbvc'), absint($legacy_only_count)),
            ];
        }

        if ($missing_count > 0) {
            $warnings[] = [
                'code' => 'field_context_missing_entries',
                'message' => sprintf(__('Field context is still missing for %d entries.', 'dbvc'), absint($missing_count)),
            ];
        }

        if (! empty($policy['block_on_missing']) && (! empty($result['error']) || $missing_count > 0 || in_array($catalog_status, ['missing', 'partial'], true))) {
            $blocked = true;
        }

        return [
            'degraded' => $degraded,
            'blocked' => $blocked,
            'legacy_only_count' => absint($legacy_only_count),
            'missing_count' => absint($missing_count),
            'warnings' => ! empty($policy['warn_on_degraded']) ? array_values($warnings) : [],
        ];
    }

    /**
     * @param string $code
     * @param string $message
     * @param int    $status
     * @return array<string, mixed>
     */
    private function build_unavailable_result($code, $message, $status, $transport = 'unavailable', array $remote_config = [])
    {
        return [
            'available' => false,
            'criteria' => [],
            'profile' => 'mapping',
            'transport' => sanitize_key((string) $transport),
            'remote' => $this->build_remote_meta($remote_config),
            'consumer_policy' => $this->get_consumer_policy(),
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
            'diagnostics' => [
                'degraded' => true,
                'blocked' => false,
                'legacy_only_count' => 0,
                'missing_count' => 0,
                'warnings' => [],
            ],
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
    private function build_error_result($error, $transport = 'local', array $remote_config = [])
    {
        $status = 500;
        $data = $error->get_error_data();
        if (is_array($data) && isset($data['status'])) {
            $status = absint($data['status']);
        }

        return $this->build_unavailable_result(
            (string) $error->get_error_code(),
            (string) $error->get_error_message(),
            $status,
            $transport,
            $remote_config
        );
    }
}
