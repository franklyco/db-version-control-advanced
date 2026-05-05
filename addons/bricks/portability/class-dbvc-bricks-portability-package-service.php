<?php

if (! defined('WPINC')) {
    die;
}

final class DBVC_Bricks_Portability_Package_Service
{
    public const PACKAGE_VERSION = 1;
    public const FEATURE_VERSION = '0.1.0';

    /**
     * @param array<int, string> $requested_domains
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    public static function create_export(array $requested_domains, array $args = [])
    {
        if (! class_exists(\ZipArchive::class)) {
            return new \WP_Error('dbvc_bricks_portability_zip_missing', __('PHP ZipArchive is required for Bricks portability export.', 'dbvc'), ['status' => 500]);
        }

        $domains = DBVC_Bricks_Portability_Registry::resolve_requested_domains($requested_domains);
        if (empty($domains)) {
            return new \WP_Error('dbvc_bricks_portability_domains_missing', __('Select at least one supported Bricks portability domain.', 'dbvc'), ['status' => 400]);
        }

        $export_id = DBVC_Bricks_Portability_Utils::generate_id('bricks-portability-export');
        $export_dir = DBVC_Bricks_Portability_Storage::resolve_export_directory($export_id);
        if (is_wp_error($export_dir)) {
            return $export_dir;
        }

        $workspace = wp_normalize_path(trailingslashit($export_dir) . 'workspace');
        if (! wp_mkdir_p($workspace)) {
            return new \WP_Error('dbvc_bricks_portability_workspace_failed', __('Failed to create the Bricks portability export workspace.', 'dbvc'), ['status' => 500]);
        }

        $domains_dir = wp_normalize_path(trailingslashit($workspace) . 'domains');
        $raw_dir = wp_normalize_path(trailingslashit($workspace) . 'raw-options');
        wp_mkdir_p($domains_dir);
        wp_mkdir_p($raw_dir);

        $created_at = gmdate('c');
        $checksums = [];
        $selected_domain_keys = [];
        $normalized_domains = [];

        foreach ($domains as $domain) {
            $domain_key = sanitize_key((string) ($domain['domain_key'] ?? ''));
            if ($domain_key === '') {
                continue;
            }

            $normalized = DBVC_Bricks_Portability_Normalizer::normalize_live_domain($domain);
            $verification_error = self::validate_normalized_domain_for_portability($domain, $normalized, 'export');
            if (is_wp_error($verification_error)) {
                self::cleanup_directory($workspace);
                return $verification_error;
            }
            $normalized_domains[$domain_key] = $normalized;
            $selected_domain_keys[] = $domain_key;

            $domain_payload = [
                'domain' => $domain_key,
                'label' => sanitize_text_field((string) ($domain['label'] ?? $domain_key)),
                'exported_at_gmt' => $created_at,
                'source_option_names' => array_values((array) ($domain['option_names'] ?? [])),
                'normalization_version' => DBVC_Bricks_Portability_Normalizer::NORMALIZATION_VERSION,
                'objects' => array_values((array) ($normalized['objects'] ?? [])),
                'metadata_rows' => array_values((array) ($normalized['metadata_rows'] ?? [])),
                'meta' => [
                    'count' => count((array) ($normalized['objects'] ?? [])),
                    'warnings' => array_values((array) ($normalized['warnings'] ?? [])),
                    'transport' => $normalized['transport'] ?? [],
                    'domain_fingerprint' => (string) ($normalized['domain_fingerprint'] ?? ''),
                ],
            ];

            $domain_file = sanitize_file_name((string) ($domain['file_slug'] ?? $domain_key) . '.json');
            $domain_path = DBVC_Bricks_Portability_Storage::write_json_file($domains_dir, $domain_file, $domain_payload);
            if (is_wp_error($domain_path)) {
                self::cleanup_directory($workspace);
                return $domain_path;
            }
            $checksums['domains/' . $domain_file] = DBVC_Bricks_Portability_Utils::fingerprint($domain_payload);

            foreach ((array) ($normalized['option_values'] ?? []) as $option_name => $value) {
                $option_name = sanitize_key((string) $option_name);
                if ($option_name === '') {
                    continue;
                }
                $raw_file = sanitize_file_name($option_name . '.json');
                $raw_path = DBVC_Bricks_Portability_Storage::write_json_file($raw_dir, $raw_file, [
                    'option_name' => $option_name,
                    'domain' => $domain_key,
                    'value' => $value,
                ]);
                if (is_wp_error($raw_path)) {
                    self::cleanup_directory($workspace);
                    return $raw_path;
                }
                $checksums['raw-options/' . $raw_file] = DBVC_Bricks_Portability_Utils::fingerprint([
                    'option_name' => $option_name,
                    'domain' => $domain_key,
                    'value' => $value,
                ]);
            }
        }

        $site_context = DBVC_Bricks_Portability_Utils::get_site_context();
        $manifest = [
            'package_id' => $export_id,
            'package_version' => self::PACKAGE_VERSION,
            'generator' => [
                'plugin' => 'DBVC',
                'addon' => 'Bricks',
                'feature' => 'Portability & Drift Manager',
                'version' => self::FEATURE_VERSION,
            ],
            'created_at_gmt' => $created_at,
            'source_site' => [
                'home_url' => (string) ($site_context['home_url'] ?? ''),
                'blog_id' => (int) ($site_context['blog_id'] ?? 0),
                'wp_version' => (string) ($site_context['wp_version'] ?? ''),
                'php_version' => (string) ($site_context['php_version'] ?? ''),
                'dbvc_version' => (string) ($site_context['dbvc_version'] ?? ''),
                'bricks_version' => (string) ($site_context['bricks_version'] ?? ''),
                'theme' => (string) ($site_context['theme'] ?? ''),
            ],
            'selected_domains' => $selected_domain_keys,
            'compatibility' => [
                'min_dbvc_version' => (string) ($site_context['dbvc_version'] ?? ''),
                'min_bricks_version' => '',
            ],
        ];
        $site_payload = [
            'site_name' => (string) ($site_context['site_name'] ?? ''),
            'home_url' => (string) ($site_context['home_url'] ?? ''),
            'export_user_id' => (int) ($site_context['export_user_id'] ?? 0),
            'export_user_label' => (string) ($site_context['export_user_label'] ?? ''),
            'notes' => sanitize_textarea_field((string) ($args['notes'] ?? '')),
            'environment' => sanitize_key((string) ($args['environment'] ?? '')),
        ];

        DBVC_Bricks_Portability_Storage::write_json_file($workspace, 'manifest.json', $manifest);
        DBVC_Bricks_Portability_Storage::write_json_file($workspace, 'site.json', $site_payload);
        $checksums['manifest.json'] = DBVC_Bricks_Portability_Utils::fingerprint($manifest);
        $checksums['site.json'] = DBVC_Bricks_Portability_Utils::fingerprint($site_payload);
        DBVC_Bricks_Portability_Storage::write_json_file($workspace, 'checksums.json', $checksums);

        $zip_path = DBVC_Bricks_Portability_Storage::resolve_export_zip_path($export_id);
        if (is_wp_error($zip_path)) {
            self::cleanup_directory($workspace);
            return $zip_path;
        }

        $zip_result = self::build_zip_archive($workspace, $zip_path);
        if (is_wp_error($zip_result)) {
            self::cleanup_directory($workspace);
            return $zip_result;
        }

        $job_id = self::create_job('bricks_portability_export', [
            'export_id' => $export_id,
            'selected_domains' => $selected_domain_keys,
            'progress' => 1,
        ]);

        $record = [
            'record_type' => 'export',
            'export_id' => $export_id,
            'package_id' => $export_id,
            'created_at_gmt' => $created_at,
            'selected_domains' => $selected_domain_keys,
            'summary' => [
                'domain_count' => count($selected_domain_keys),
                'job_id' => $job_id,
            ],
            'manifest' => $manifest,
            'zip_filename' => basename((string) $zip_path),
        ];
        DBVC_Bricks_Portability_Storage::write_json_file($export_dir, 'record.json', $record);

        self::log_activity(
            'portability_export_created',
            'info',
            'Bricks portability export created.',
            [
                'export_id' => $export_id,
                'selected_domains' => $selected_domain_keys,
            ],
            $job_id
        );

        self::cleanup_directory($workspace);

        return array_merge($record, [
            'zip_path' => $zip_path,
        ]);
    }

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>|\WP_Error
     */
    public static function import_uploaded_package(array $file)
    {
        if (! class_exists(\ZipArchive::class)) {
            return new \WP_Error('dbvc_bricks_portability_zip_missing', __('PHP ZipArchive is required for Bricks portability import.', 'dbvc'), ['status' => 500]);
        }

        $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmp_name === '' || ! is_file($tmp_name)) {
            return new \WP_Error('dbvc_bricks_portability_upload_missing', __('Upload a Bricks portability ZIP package first.', 'dbvc'), ['status' => 400]);
        }

        $original_name = sanitize_file_name((string) ($file['name'] ?? 'bricks-portability.zip'));
        if (strtolower((string) pathinfo($original_name, PATHINFO_EXTENSION)) !== 'zip') {
            return new \WP_Error('dbvc_bricks_portability_upload_invalid', __('Bricks portability import accepts ZIP packages only.', 'dbvc'), ['status' => 400]);
        }

        $session_id = DBVC_Bricks_Portability_Utils::generate_id('bricks-portability-session');
        $session_dir = DBVC_Bricks_Portability_Storage::resolve_session_directory($session_id);
        if (is_wp_error($session_dir)) {
            return $session_dir;
        }

        $archive_path = wp_normalize_path(trailingslashit($session_dir) . 'package.zip');
        if (! @copy($tmp_name, $archive_path)) {
            return new \WP_Error('dbvc_bricks_portability_upload_copy_failed', __('Failed to stage the uploaded Bricks portability ZIP.', 'dbvc'), ['status' => 500]);
        }

        $extract_dir = wp_normalize_path(trailingslashit($session_dir) . 'extracted');
        if (! wp_mkdir_p($extract_dir)) {
            return new \WP_Error('dbvc_bricks_portability_extract_dir_failed', __('Failed to create the Bricks portability import workspace.', 'dbvc'), ['status' => 500]);
        }

        $extract_result = self::extract_zip_archive($archive_path, $extract_dir);
        if (is_wp_error($extract_result)) {
            return $extract_result;
        }

        $manifest = DBVC_Bricks_Portability_Storage::read_json_file(wp_normalize_path(trailingslashit($extract_dir) . 'manifest.json'));
        $checksums = DBVC_Bricks_Portability_Storage::read_json_file(wp_normalize_path(trailingslashit($extract_dir) . 'checksums.json'));
        $site_payload = DBVC_Bricks_Portability_Storage::read_json_file(wp_normalize_path(trailingslashit($extract_dir) . 'site.json'));
        if (is_wp_error($manifest)) {
            return $manifest;
        }
        if (is_wp_error($checksums)) {
            return $checksums;
        }
        if (is_wp_error($site_payload)) {
            return $site_payload;
        }

        $validation = self::validate_manifest($manifest);
        if (is_wp_error($validation)) {
            return $validation;
        }

        $checksum_validation = self::validate_checksums($extract_dir, $checksums);
        if (is_wp_error($checksum_validation)) {
            return $checksum_validation;
        }

        $selected_domains = isset($manifest['selected_domains']) && is_array($manifest['selected_domains'])
            ? array_values(array_map('sanitize_key', $manifest['selected_domains']))
            : [];
        $domains = DBVC_Bricks_Portability_Registry::resolve_requested_domains($selected_domains);
        if (empty($domains)) {
            return new \WP_Error('dbvc_bricks_portability_manifest_domains_invalid', __('The uploaded Bricks portability package does not contain any supported domains.', 'dbvc'), ['status' => 400]);
        }

        $source_domains = [];
        $target_domains = [];
        foreach ($domains as $domain) {
            $domain_key = sanitize_key((string) ($domain['domain_key'] ?? ''));
            $raw_values = [];
            foreach ((array) ($domain['option_names'] ?? []) as $option_name) {
                $option_name = sanitize_key((string) $option_name);
                if ($option_name === '') {
                    continue;
                }
                $raw_path = wp_normalize_path(trailingslashit($extract_dir) . 'raw-options/' . sanitize_file_name($option_name . '.json'));
                $raw_payload = DBVC_Bricks_Portability_Storage::read_json_file($raw_path);
                if (is_wp_error($raw_payload)) {
                    return new \WP_Error(
                        'dbvc_bricks_portability_package_raw_missing',
                        sprintf(__('Missing expected raw option payload for `%s` in the uploaded package.', 'dbvc'), $option_name),
                        ['status' => 400]
                    );
                }
                $raw_values[$option_name] = $raw_payload['value'] ?? null;
            }

            $source_domains[$domain_key] = DBVC_Bricks_Portability_Normalizer::normalize_package_domain($domain, $raw_values);
            $verification_error = self::validate_normalized_domain_for_portability($domain, $source_domains[$domain_key], 'import');
            if (is_wp_error($verification_error)) {
                return $verification_error;
            }
            $target_domains[$domain_key] = DBVC_Bricks_Portability_Normalizer::normalize_live_domain($domain);
        }

        $review = self::build_review_payload($manifest, $source_domains, $target_domains);
        $job_id = self::create_job('bricks_portability_import', [
            'session_id' => $session_id,
            'package_id' => sanitize_key((string) ($manifest['package_id'] ?? '')),
            'selected_domains' => $selected_domains,
            'progress' => 1,
        ]);

        $session = [
            'record_type' => 'session',
            'session_id' => $session_id,
            'created_at_gmt' => gmdate('c'),
            'refreshed_at_gmt' => gmdate('c'),
            'package_id' => sanitize_key((string) ($manifest['package_id'] ?? '')),
            'job_id' => $job_id,
            'manifest' => $manifest,
            'site' => $site_payload,
            'summary' => $review['summary'],
            'domain_summaries' => $review['domain_summaries'],
            'rows' => $review['rows'],
            'source_domains' => $source_domains,
            'target_domains' => $target_domains,
            'archive_path' => $archive_path,
            'extract_dir' => $extract_dir,
            'draft' => [],
            'approval' => [],
            'rollback' => [],
        ];
        $persist = self::persist_session($session);
        if (is_wp_error($persist)) {
            return $persist;
        }

        self::log_activity(
            'portability_import_completed',
            'info',
            'Bricks portability package imported and compared.',
            [
                'session_id' => $session_id,
                'package_id' => $session['package_id'],
                'selected_domains' => $selected_domains,
                'summary' => $session['summary'],
            ],
            $job_id
        );

        return self::build_session_view($session);
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, string> $effective_decisions
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    public static function persist_session_approval(array $session, array $effective_decisions, array $args = [])
    {
        $approved_at_gmt = sanitize_text_field((string) ($args['approved_at_gmt'] ?? gmdate('c')));
        if ($approved_at_gmt === '') {
            $approved_at_gmt = gmdate('c');
        }

        $applied_to_site = ! empty($args['applied_to_site']);
        $backup_id = sanitize_key((string) ($args['backup_id'] ?? ''));
        $job_id = isset($args['job_id']) ? (int) $args['job_id'] : (int) ($session['job_id'] ?? 0);
        $rows = isset($session['rows']) && is_array($session['rows']) ? $session['rows'] : [];
        $manual_row_ids = self::normalize_manual_row_ids((array) ($args['manual_row_ids'] ?? []), $rows);

        $session['draft'] = [
            'saved_at_gmt' => $approved_at_gmt,
            'decision_count' => count($effective_decisions),
            'manual_decision_count' => count($manual_row_ids),
            'manual_rows' => $manual_row_ids,
            'decisions' => $effective_decisions,
        ];
        $session['approval'] = [
            'approved_at_gmt' => $approved_at_gmt,
            'decision_count' => count($effective_decisions),
            'mutating_decision_count' => self::count_mutating_decisions($effective_decisions),
            'manual_decision_count' => count($manual_row_ids),
            'manual_rows' => $manual_row_ids,
            'applied_to_site' => $applied_to_site,
            'backup_id' => $backup_id,
            'job_id' => $job_id,
            'rows' => self::build_row_approval_state($rows, $effective_decisions, $approved_at_gmt, $applied_to_site, $manual_row_ids),
        ];

        $persist = self::persist_session($session);
        if (is_wp_error($persist)) {
            return $persist;
        }

        return $session;
    }

    /**
     * @param string $session_id
     * @return array<string, mixed>|\WP_Error
     */
    public static function refresh_session($session_id)
    {
        $session = self::load_session($session_id);
        if (is_wp_error($session)) {
            return $session;
        }

        $compared_domains = self::collect_compared_domain_keys($session);
        $target_domains = [];
        foreach ($compared_domains as $domain_key) {
            $definition = DBVC_Bricks_Portability_Registry::get_domain($domain_key);
            if (! is_array($definition)) {
                continue;
            }
            $target_domains[$domain_key] = DBVC_Bricks_Portability_Normalizer::normalize_live_domain($definition);
        }

        $review = self::build_review_payload(
            isset($session['manifest']) && is_array($session['manifest']) ? $session['manifest'] : [],
            isset($session['source_domains']) && is_array($session['source_domains']) ? $session['source_domains'] : [],
            $target_domains
        );

        $session['target_domains'] = $target_domains;
        $session['summary'] = isset($review['summary']) && is_array($review['summary']) ? $review['summary'] : [];
        $session['domain_summaries'] = isset($review['domain_summaries']) && is_array($review['domain_summaries']) ? $review['domain_summaries'] : [];
        $session['rows'] = isset($review['rows']) && is_array($review['rows']) ? $review['rows'] : [];
        $session['refreshed_at_gmt'] = gmdate('c');

        $persist = self::persist_session($session);
        if (is_wp_error($persist)) {
            return $persist;
        }

        self::log_activity(
            'portability_session_refreshed',
            'info',
            'Bricks portability session refreshed against the current site.',
            [
                'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
                'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
                'compared_domains' => $compared_domains,
            ],
            (int) ($session['job_id'] ?? 0)
        );

        return self::build_session_view($session);
    }

    /**
     * @param string $session_id
     * @param array<string, mixed> $rollback
     * @return array<string, mixed>|\WP_Error
     */
    public static function mark_session_rollback($session_id, array $rollback)
    {
        $session = self::load_session($session_id);
        if (is_wp_error($session)) {
            return $session;
        }

        $session['rollback'] = [
            'rolled_back_at_gmt' => sanitize_text_field((string) ($rollback['rolled_back_at_gmt'] ?? gmdate('c'))),
            'backup_id' => sanitize_key((string) ($rollback['backup_id'] ?? '')),
            'job_id' => (int) ($rollback['job_id'] ?? 0),
            'option_count' => count((array) ($rollback['option_names'] ?? [])),
        ];

        $persist = self::persist_session($session);
        if (is_wp_error($persist)) {
            return $persist;
        }

        return $session;
    }

    /**
     * @param string $session_id
     * @param array<string, string> $requested_decisions
     * @return array<string, mixed>|\WP_Error
     */
    public static function save_session_draft($session_id, array $requested_decisions, array $manual_row_ids = [])
    {
        $session = self::load_session($session_id);
        if (is_wp_error($session)) {
            return $session;
        }

        $rows = isset($session['rows']) && is_array($session['rows']) ? $session['rows'] : [];
        $effective_decisions = DBVC_Bricks_Portability_Apply_Service::prepare_review_decisions($rows, $requested_decisions);
        if (is_wp_error($effective_decisions)) {
            return $effective_decisions;
        }
        $manual_row_ids = self::normalize_manual_row_ids($manual_row_ids, $rows);

        $session['draft'] = [
            'saved_at_gmt' => gmdate('c'),
            'decision_count' => count($effective_decisions),
            'manual_decision_count' => count($manual_row_ids),
            'manual_rows' => $manual_row_ids,
            'decisions' => $effective_decisions,
        ];

        $persist = self::persist_session($session);
        if (is_wp_error($persist)) {
            return $persist;
        }

        self::log_activity(
            'portability_draft_saved',
            'info',
            'Bricks portability draft decisions saved.',
            [
                'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
                'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
                'decision_count' => count($effective_decisions),
            ],
            (int) ($session['job_id'] ?? 0)
        );

        return self::build_session_view($session);
    }

    /**
     * @param string $session_id
     * @return array<string, mixed>|\WP_Error
     */
    public static function load_session($session_id)
    {
        $session_dir = DBVC_Bricks_Portability_Storage::resolve_session_directory($session_id);
        if (is_wp_error($session_dir)) {
            return $session_dir;
        }

        $session = DBVC_Bricks_Portability_Storage::read_json_file(wp_normalize_path(trailingslashit($session_dir) . 'session.json'));
        if (is_wp_error($session)) {
            return $session;
        }

        return $session;
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function list_recent_exports($limit = 10)
    {
        return DBVC_Bricks_Portability_Storage::list_records('exports', $limit);
    }

    /**
     * @param int $limit
     * @return array<int, array<string, mixed>>
     */
    public static function list_recent_sessions($limit = 10)
    {
        return DBVC_Bricks_Portability_Storage::list_records('sessions', $limit);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public static function build_session_view(array $session)
    {
        $review = self::build_review_payload(
            isset($session['manifest']) && is_array($session['manifest']) ? $session['manifest'] : [],
            isset($session['source_domains']) && is_array($session['source_domains']) ? $session['source_domains'] : [],
            isset($session['target_domains']) && is_array($session['target_domains']) ? $session['target_domains'] : []
        );

        $rows = (isset($review['rows']) && is_array($review['rows'])
            ? $review['rows']
            : (isset($session['rows']) && is_array($session['rows']) ? $session['rows'] : []));
        $approval = self::build_session_approval_view(
            isset($session['approval']) && is_array($session['approval']) ? $session['approval'] : [],
            true,
            $rows
        );
        $rows = self::merge_row_approval_view($rows, isset($approval['rows']) && is_array($approval['rows']) ? $approval['rows'] : []);
        $rollback = self::build_session_rollback_view(isset($session['rollback']) && is_array($session['rollback']) ? $session['rollback'] : []);

        return [
            'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
            'created_at_gmt' => sanitize_text_field((string) ($session['created_at_gmt'] ?? '')),
            'refreshed_at_gmt' => sanitize_text_field((string) ($session['refreshed_at_gmt'] ?? ($session['created_at_gmt'] ?? ''))),
            'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
            'manifest' => isset($session['manifest']) && is_array($session['manifest']) ? $session['manifest'] : [],
            'site' => isset($session['site']) && is_array($session['site']) ? $session['site'] : [],
            'summary' => isset($review['summary']) && is_array($review['summary'])
                ? $review['summary']
                : (isset($session['summary']) && is_array($session['summary']) ? $session['summary'] : []),
            'domain_summaries' => isset($review['domain_summaries']) && is_array($review['domain_summaries'])
                ? $review['domain_summaries']
                : (isset($session['domain_summaries']) && is_array($session['domain_summaries']) ? $session['domain_summaries'] : []),
            'rows' => $rows,
            'draft' => self::build_session_draft_view(
                isset($session['draft']) && is_array($session['draft']) ? $session['draft'] : [],
                true,
                $rows
            ),
            'approval' => $approval,
            'rollback' => $rollback,
            'freshness' => self::build_session_freshness_view($session, $rollback),
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, array<string, mixed>> $source_domains
     * @param array<string, array<string, mixed>> $target_domains
     * @return array<string, mixed>
     */
    private static function build_review_payload(array $manifest, array $source_domains, array $target_domains)
    {
        if (empty($manifest) || empty($source_domains)) {
            return [
                'summary' => [],
                'domain_summaries' => [],
                'rows' => [],
            ];
        }

        return DBVC_Bricks_Portability_Diff_Engine::build_review_session(
            $manifest,
            $source_domains,
            self::augment_target_lookup_domains($target_domains)
        );
    }

    /**
     * @param array<string, array<string, mixed>> $target_domains
     * @return array<string, array<string, mixed>>
     */
    private static function augment_target_lookup_domains(array $target_domains)
    {
        foreach (['global_variables', 'global_classes'] as $domain_key) {
            if (isset($target_domains[$domain_key]) && is_array($target_domains[$domain_key])) {
                continue;
            }

            $definition = DBVC_Bricks_Portability_Registry::get_domain($domain_key);
            if (! is_array($definition)) {
                continue;
            }

            $target_domains[$domain_key] = DBVC_Bricks_Portability_Normalizer::normalize_live_domain($definition);
        }

        return $target_domains;
    }

    /**
     * @param array<string, mixed> $domain
     * @param array<string, mixed> $normalized
     * @param string $operation
     * @return true|\WP_Error
     */
    private static function validate_normalized_domain_for_portability(array $domain, array $normalized, $operation)
    {
        $operation = sanitize_key((string) $operation);
        $verification = isset($normalized['verification']) && is_array($normalized['verification']) ? $normalized['verification'] : [];
        if ($verification === []) {
            return true;
        }

        $blocks = DBVC_Bricks_Portability_Domain_Verifier::blocks_export($verification);
        if (! $blocks) {
            return true;
        }

        $label = sanitize_text_field((string) ($domain['label'] ?? ($normalized['label'] ?? 'Selected domain')));
        $warning = sanitize_text_field((string) (($verification['warnings'][0] ?? '')));
        $message = sprintf(__('The Bricks portability %1$s for `%2$s` was blocked because its storage shape could not be verified safely.', 'dbvc'), $operation, $label);
        if ($warning !== '') {
            $message .= ' ' . $warning;
        }

        return new \WP_Error(
            'dbvc_bricks_portability_domain_verification_failed',
            $message,
            ['status' => 400, 'domain_key' => sanitize_key((string) ($domain['domain_key'] ?? ''))]
        );
    }

    /**
     * @param array<string, mixed> $session
     * @return true|\WP_Error
     */
    private static function persist_session(array $session)
    {
        $session_id = sanitize_key((string) ($session['session_id'] ?? ''));
        if ($session_id === '') {
            return new \WP_Error('dbvc_bricks_portability_session_invalid', __('Bricks portability session identifier is invalid.', 'dbvc'), ['status' => 500]);
        }

        $session_dir = DBVC_Bricks_Portability_Storage::resolve_session_directory($session_id);
        if (is_wp_error($session_dir)) {
            return $session_dir;
        }

        $record = self::build_session_record($session);
        $record_result = DBVC_Bricks_Portability_Storage::write_json_file($session_dir, 'record.json', $record);
        if (is_wp_error($record_result)) {
            return $record_result;
        }

        $session_result = DBVC_Bricks_Portability_Storage::write_json_file($session_dir, 'session.json', $session);
        if (is_wp_error($session_result)) {
            return $session_result;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private static function build_session_record(array $session)
    {
        return [
            'record_type' => 'session',
            'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
            'created_at_gmt' => sanitize_text_field((string) ($session['created_at_gmt'] ?? '')),
            'refreshed_at_gmt' => sanitize_text_field((string) ($session['refreshed_at_gmt'] ?? ($session['created_at_gmt'] ?? ''))),
            'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
            'summary' => isset($session['summary']) && is_array($session['summary']) ? $session['summary'] : [],
            'domain_summaries' => isset($session['domain_summaries']) && is_array($session['domain_summaries']) ? $session['domain_summaries'] : [],
            'job_id' => (int) ($session['job_id'] ?? 0),
            'draft' => self::build_session_draft_view(isset($session['draft']) && is_array($session['draft']) ? $session['draft'] : [], false),
            'approval' => self::build_session_approval_view(isset($session['approval']) && is_array($session['approval']) ? $session['approval'] : [], false),
            'rollback' => self::build_session_rollback_view(isset($session['rollback']) && is_array($session['rollback']) ? $session['rollback'] : []),
        ];
    }

    /**
     * @param array<string, mixed> $draft
     * @param bool $include_decisions
     * @return array<string, mixed>
     */
    private static function build_session_draft_view(array $draft, $include_decisions, array $rows = [])
    {
        $view = [
            'saved_at_gmt' => sanitize_text_field((string) ($draft['saved_at_gmt'] ?? '')),
            'decision_count' => (int) ($draft['decision_count'] ?? count((array) ($draft['decisions'] ?? []))),
            'manual_decision_count' => (int) ($draft['manual_decision_count'] ?? count((array) ($draft['manual_rows'] ?? []))),
        ];
        if (! $include_decisions) {
            return $view;
        }
        $decisions = [];
        $draft_decisions = (array) ($draft['decisions'] ?? []);
        if (! empty($rows)) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $row_id = self::normalize_row_id($row['row_id'] ?? '');
                if ($row_id === '') {
                    continue;
                }
                $decision = self::find_draft_decision($draft_decisions, $row_id);
                if ($decision === '') {
                    continue;
                }
                $decisions[$row_id] = $decision;
            }
        } else {
            foreach ($draft_decisions as $row_id => $decision) {
                $row_id = self::normalize_row_id($row_id);
                $decision = sanitize_key((string) $decision);
                if ($row_id === '' || $decision === '') {
                    continue;
                }
                $decisions[$row_id] = $decision;
            }
        }
        $view['decisions'] = $decisions;
        $view['manual_rows'] = self::normalize_manual_row_ids((array) ($draft['manual_rows'] ?? []), $rows);

        return $view;
    }

    /**
     * @param array<string, mixed> $approval
     * @param bool $include_rows
     * @return array<string, mixed>
     */
    private static function build_session_approval_view(array $approval, $include_rows, array $rows = [])
    {
        $view = [
            'approved_at_gmt' => sanitize_text_field((string) ($approval['approved_at_gmt'] ?? '')),
            'decision_count' => (int) ($approval['decision_count'] ?? count((array) ($approval['rows'] ?? []))),
            'mutating_decision_count' => (int) ($approval['mutating_decision_count'] ?? 0),
            'manual_decision_count' => (int) ($approval['manual_decision_count'] ?? count((array) ($approval['manual_rows'] ?? []))),
            'applied_to_site' => ! empty($approval['applied_to_site']),
            'backup_id' => sanitize_key((string) ($approval['backup_id'] ?? '')),
            'job_id' => (int) ($approval['job_id'] ?? 0),
        ];
        if (! $include_rows) {
            return $view;
        }

        $approval_rows = (array) ($approval['rows'] ?? []);
        $view['manual_rows'] = self::normalize_manual_row_ids((array) ($approval['manual_rows'] ?? []), $rows);
        $row_views = [];
        if (! empty($rows)) {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $row_id = self::normalize_row_id($row['row_id'] ?? '');
                if ($row_id === '') {
                    continue;
                }
                $row_views[$row_id] = self::find_row_approval($approval_rows, $row_id);
            }
        } else {
            foreach ($approval_rows as $row_id => $row_approval) {
                $row_id = self::normalize_row_id($row_id);
                if ($row_id === '') {
                    continue;
                }
                $row_views[$row_id] = self::find_row_approval($approval_rows, $row_id);
            }
        }
        $view['rows'] = $row_views;

        return $view;
    }

    /**
     * @param array<string, mixed> $rollback
     * @return array<string, mixed>
     */
    private static function build_session_rollback_view(array $rollback)
    {
        return [
            'rolled_back_at_gmt' => sanitize_text_field((string) ($rollback['rolled_back_at_gmt'] ?? '')),
            'backup_id' => sanitize_key((string) ($rollback['backup_id'] ?? '')),
            'job_id' => (int) ($rollback['job_id'] ?? 0),
            'option_count' => (int) ($rollback['option_count'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $session
     * @param array<string, mixed> $rollback
     * @return array<string, mixed>
     */
    private static function build_session_freshness_view(array $session, array $rollback = [])
    {
        $changed_domains = [];
        foreach (self::collect_compared_domain_keys($session) as $domain_key) {
            $definition = DBVC_Bricks_Portability_Registry::get_domain($domain_key);
            if (! is_array($definition)) {
                continue;
            }
            $expected = isset($session['target_domains'][$domain_key]) && is_array($session['target_domains'][$domain_key])
                ? $session['target_domains'][$domain_key]
                : [];
            $live = DBVC_Bricks_Portability_Normalizer::normalize_live_domain($definition);
            $expected_fp = sanitize_text_field((string) ($expected['domain_fingerprint'] ?? ''));
            $live_fp = sanitize_text_field((string) ($live['domain_fingerprint'] ?? ''));
            if ($expected_fp !== '' && $live_fp !== '' && ! hash_equals($expected_fp, $live_fp)) {
                $changed_domains[] = $domain_key;
            }
        }

        return [
            'state' => empty($changed_domains) ? 'fresh' : 'stale',
            'changed_domains' => array_values($changed_domains),
            'last_compared_at_gmt' => sanitize_text_field((string) ($session['refreshed_at_gmt'] ?? ($session['created_at_gmt'] ?? ''))),
            'has_rollback' => ! empty($rollback['rolled_back_at_gmt']),
        ];
    }

    /**
     * @param array<string, string> $draft_decisions
     * @param string $row_id
     * @return string
     */
    private static function find_draft_decision(array $draft_decisions, $row_id)
    {
        $row_id = self::normalize_row_id($row_id);
        if ($row_id === '') {
            return '';
        }

        if (isset($draft_decisions[$row_id])) {
            return sanitize_key((string) $draft_decisions[$row_id]);
        }

        $legacy_key = sanitize_key($row_id);
        if ($legacy_key !== '' && isset($draft_decisions[$legacy_key])) {
            return sanitize_key((string) $draft_decisions[$legacy_key]);
        }

        return '';
    }

    /**
     * @param array<string, mixed> $approval_rows
     * @param string $row_id
     * @return array<string, mixed>
     */
    private static function find_row_approval(array $approval_rows, $row_id)
    {
        $row_id = self::normalize_row_id($row_id);
        if ($row_id === '') {
            return [];
        }

        $row_approval = [];
        if (isset($approval_rows[$row_id]) && is_array($approval_rows[$row_id])) {
            $row_approval = $approval_rows[$row_id];
        } else {
            $legacy_key = sanitize_key($row_id);
            if ($legacy_key !== '' && isset($approval_rows[$legacy_key]) && is_array($approval_rows[$legacy_key])) {
                $row_approval = $approval_rows[$legacy_key];
            }
        }

        if (empty($row_approval)) {
            return [];
        }

        return [
            'decision' => sanitize_key((string) ($row_approval['decision'] ?? '')),
            'approved_at_gmt' => sanitize_text_field((string) ($row_approval['approved_at_gmt'] ?? '')),
            'status' => sanitize_key((string) ($row_approval['status'] ?? 'approved')),
            'manual' => ! empty($row_approval['manual']),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, array<string, mixed>> $row_approval_views
     * @return array<int, array<string, mixed>>
     */
    private static function merge_row_approval_view(array $rows, array $row_approval_views)
    {
        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $row_id = self::normalize_row_id($row['row_id'] ?? '');
            if ($row_id === '' || empty($row_approval_views[$row_id]) || ! is_array($row_approval_views[$row_id])) {
                $rows[$index]['approved_action'] = '';
                $rows[$index]['approved_at_gmt'] = '';
                $rows[$index]['approval_status'] = '';
                continue;
            }

            $row_approval = $row_approval_views[$row_id];
            $rows[$index]['approved_action'] = sanitize_key((string) ($row_approval['decision'] ?? ''));
            $rows[$index]['approved_at_gmt'] = sanitize_text_field((string) ($row_approval['approved_at_gmt'] ?? ''));
            $rows[$index]['approval_status'] = sanitize_key((string) ($row_approval['status'] ?? 'approved'));
            $rows[$index]['manual_decision'] = ! empty($row_approval['manual']);
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, string> $effective_decisions
     * @param string $approved_at_gmt
     * @param bool $applied_to_site
     * @return array<string, array<string, mixed>>
     */
    private static function build_row_approval_state(array $rows, array $effective_decisions, $approved_at_gmt, $applied_to_site, array $manual_row_ids = [])
    {
        $row_approval = [];
        $manual_lookup = array_fill_keys($manual_row_ids, true);
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row_id = self::normalize_row_id($row['row_id'] ?? '');
            if ($row_id === '') {
                continue;
            }

            $decision = self::find_draft_decision($effective_decisions, $row_id);
            if ($decision === '') {
                continue;
            }

            $row_approval[$row_id] = [
                'decision' => $decision,
                'approved_at_gmt' => sanitize_text_field((string) $approved_at_gmt),
                'status' => ($applied_to_site && in_array($decision, ['add_incoming', 'replace_with_incoming'], true)) ? 'applied' : 'approved',
                'manual' => isset($manual_lookup[$row_id]),
            ];
        }

        return $row_approval;
    }

    /**
     * @param array<string, string> $effective_decisions
     * @return int
     */
    private static function count_mutating_decisions(array $effective_decisions)
    {
        return count(array_filter($effective_decisions, static function ($decision) {
            return in_array($decision, ['add_incoming', 'replace_with_incoming'], true);
        }));
    }

    /**
     * @param array<int, mixed> $manual_row_ids
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private static function normalize_manual_row_ids(array $manual_row_ids, array $rows = [])
    {
        $lookup = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row_id = self::normalize_row_id($row['row_id'] ?? '');
            if ($row_id === '') {
                continue;
            }
            $lookup[$row_id] = $row_id;
            $legacy_key = sanitize_key($row_id);
            if ($legacy_key !== '') {
                $lookup[$legacy_key] = $row_id;
            }
        }

        $normalized = [];
        foreach ($manual_row_ids as $manual_row_id) {
            $manual_row_id = self::normalize_row_id($manual_row_id);
            if ($manual_row_id === '') {
                continue;
            }

            if (! empty($lookup)) {
                if (isset($lookup[$manual_row_id])) {
                    $normalized[$lookup[$manual_row_id]] = $lookup[$manual_row_id];
                    continue;
                }
                $legacy_key = sanitize_key($manual_row_id);
                if ($legacy_key !== '' && isset($lookup[$legacy_key])) {
                    $normalized[$lookup[$legacy_key]] = $lookup[$legacy_key];
                }
                continue;
            }

            $normalized[$manual_row_id] = $manual_row_id;
        }

        return array_values($normalized);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<int, string>
     */
    private static function collect_compared_domain_keys(array $session)
    {
        $selected_domains = isset($session['manifest']['selected_domains']) && is_array($session['manifest']['selected_domains'])
            ? array_values(array_map('sanitize_key', $session['manifest']['selected_domains']))
            : [];
        if (! empty($selected_domains)) {
            return $selected_domains;
        }

        return array_values(array_filter(array_map('sanitize_key', array_keys((array) ($session['source_domains'] ?? [])))));
    }

    /**
     * @param mixed $row_id
     * @return string
     */
    private static function normalize_row_id($row_id)
    {
        return trim(sanitize_text_field((string) $row_id));
    }

    /**
     * @param string $workspace
     * @param string $zip_path
     * @return true|\WP_Error
     */
    private static function build_zip_archive($workspace, $zip_path)
    {
        $workspace = wp_normalize_path((string) $workspace);
        $zip_path = wp_normalize_path((string) $zip_path);

        $zip = new \ZipArchive();
        $open = $zip->open($zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($open !== true) {
            return new \WP_Error('dbvc_bricks_portability_zip_open_failed', __('Unable to create the Bricks portability ZIP archive.', 'dbvc'), ['status' => 500]);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $file_info) {
            $absolute = wp_normalize_path((string) $file_info->getPathname());
            $relative = ltrim(str_replace($workspace, '', $absolute), '/');
            if ($relative === '') {
                continue;
            }
            if ($file_info->isDir()) {
                $zip->addEmptyDir($relative);
                continue;
            }
            $zip->addFile($absolute, $relative);
        }

        $zip->close();
        return true;
    }

    /**
     * @param string $archive_path
     * @param string $extract_dir
     * @return true|\WP_Error
     */
    private static function extract_zip_archive($archive_path, $extract_dir)
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive_path) !== true) {
            return new \WP_Error('dbvc_bricks_portability_zip_open_failed', __('Unable to open the uploaded Bricks portability ZIP package.', 'dbvc'), ['status' => 400]);
        }

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);
            if (
                $name === ''
                || strpos($name, '../') !== false
                || strpos($name, '..\\') !== false
                || preg_match('#^([A-Za-z]:)?[\\\\/]#', $name)
            ) {
                $zip->close();
                return new \WP_Error('dbvc_bricks_portability_zip_entry_invalid', __('The uploaded Bricks portability ZIP contains an invalid path.', 'dbvc'), ['status' => 400]);
            }
        }

        if (! $zip->extractTo($extract_dir)) {
            $zip->close();
            return new \WP_Error('dbvc_bricks_portability_zip_extract_failed', __('Failed to extract the uploaded Bricks portability ZIP package.', 'dbvc'), ['status' => 400]);
        }
        $zip->close();
        return true;
    }

    /**
     * @param array<string, mixed> $manifest
     * @return true|\WP_Error
     */
    private static function validate_manifest(array $manifest)
    {
        $package_version = isset($manifest['package_version']) ? (int) $manifest['package_version'] : 0;
        if ($package_version !== self::PACKAGE_VERSION) {
            return new \WP_Error('dbvc_bricks_portability_package_version_invalid', __('This Bricks portability package version is not supported.', 'dbvc'), ['status' => 400]);
        }

        $selected_domains = isset($manifest['selected_domains']) && is_array($manifest['selected_domains']) ? $manifest['selected_domains'] : [];
        if (empty($selected_domains)) {
            return new \WP_Error('dbvc_bricks_portability_manifest_invalid', __('The Bricks portability manifest does not include any selected domains.', 'dbvc'), ['status' => 400]);
        }

        return true;
    }

    /**
     * @param string $extract_dir
     * @param array<string, mixed> $checksums
     * @return true|\WP_Error
     */
    private static function validate_checksums($extract_dir, array $checksums)
    {
        foreach ($checksums as $relative_path => $expected) {
            $relative_path = ltrim(str_replace('\\', '/', (string) $relative_path), '/');
            $expected = sanitize_text_field((string) $expected);
            if ($relative_path === '' || $expected === '') {
                continue;
            }
            $absolute = wp_normalize_path(trailingslashit($extract_dir) . $relative_path);
            if (! is_file($absolute)) {
                return new \WP_Error('dbvc_bricks_portability_checksum_missing', sprintf(__('Missing package file `%s` while validating checksums.', 'dbvc'), $relative_path), ['status' => 400]);
            }
            $raw = file_get_contents($absolute);
            if (! is_string($raw)) {
                return new \WP_Error('dbvc_bricks_portability_checksum_read_failed', sprintf(__('Failed to read package file `%s` while validating checksums.', 'dbvc'), $relative_path), ['status' => 400]);
            }
            $actual = 'sha256:' . hash('sha256', $raw);
            if (! hash_equals($expected, $actual)) {
                return new \WP_Error('dbvc_bricks_portability_checksum_mismatch', sprintf(__('Checksum mismatch detected for `%s`.', 'dbvc'), $relative_path), ['status' => 400]);
            }
        }

        return true;
    }

    /**
     * @param string $type
     * @param array<string, mixed> $context
     * @return int
     */
    private static function create_job($type, array $context = [])
    {
        if (! class_exists('DBVC_Database') || ! method_exists('DBVC_Database', 'create_job')) {
            return 0;
        }

        return (int) DBVC_Database::create_job($type, $context, 'completed');
    }

    /**
     * @param string $event
     * @param string $severity
     * @param string $message
     * @param array<string, mixed> $context
     * @param int $job_id
     * @return void
     */
    private static function log_activity($event, $severity, $message, array $context = [], $job_id = 0)
    {
        do_action('dbvc_bricks_audit_event', $event, $context);
        if (! class_exists('DBVC_Database') || ! method_exists('DBVC_Database', 'log_activity')) {
            return;
        }

        DBVC_Database::log_activity(
            'dbvc_bricks_' . sanitize_key((string) $event),
            sanitize_key((string) $severity),
            sanitize_text_field((string) $message),
            $context,
            ['job_id' => (int) $job_id]
        );
    }

    /**
     * @param string $directory
     * @return void
     */
    private static function cleanup_directory($directory)
    {
        $directory = wp_normalize_path((string) $directory);
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file_info) {
            $path = wp_normalize_path((string) $file_info->getPathname());
            if ($file_info->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
