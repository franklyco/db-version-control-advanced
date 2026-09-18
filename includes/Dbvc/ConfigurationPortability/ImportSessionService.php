<?php

namespace Dbvc\ConfigurationPortability;

if (! defined('WPINC')) {
    die;
}

final class ImportSessionService
{
    public const REVIEW_DRAFT_VERSION = 1;
    public const REVIEW_DRAFT_TTL_SECONDS = 604800;

    /**
     * @param array<string, mixed> $file
     * @return array<string, mixed>|\WP_Error
     */
    public static function import_uploaded_package(array $file)
    {
        if (! class_exists(\ZipArchive::class)) {
            return new \WP_Error('dbvc_config_portability_zip_missing', __('PHP ZipArchive is required for configuration portability import.', 'dbvc'));
        }

        $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmp_name === '' || ! is_file($tmp_name) || ! is_readable($tmp_name)) {
            return new \WP_Error('dbvc_config_portability_upload_missing', __('Upload a DBVC configuration portability ZIP package first.', 'dbvc'));
        }

        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_OK;
        if ($error !== UPLOAD_ERR_OK) {
            return new \WP_Error('dbvc_config_portability_upload_error', __('Configuration portability package upload failed.', 'dbvc'));
        }

        $original_name = sanitize_file_name((string) ($file['name'] ?? 'dbvc-config-portability.zip'));
        if (strtolower((string) pathinfo($original_name, PATHINFO_EXTENSION)) !== 'zip') {
            return new \WP_Error('dbvc_config_portability_upload_invalid', __('Configuration portability imports accept ZIP packages only.', 'dbvc'));
        }

        $session_id = self::generate_session_id();
        $session_dir = Storage::resolve_session_directory($session_id);
        if (\is_wp_error($session_dir)) {
            return $session_dir;
        }

        $archive_path = wp_normalize_path(trailingslashit($session_dir) . 'package.zip');
        if (! @copy($tmp_name, $archive_path)) {
            self::cleanup_directory($session_dir);
            return new \WP_Error('dbvc_config_portability_upload_copy_failed', __('Failed to stage the uploaded configuration portability ZIP.', 'dbvc'));
        }

        $extract_dir = wp_normalize_path(trailingslashit($session_dir) . 'extracted');
        $extract_dir_result = \Dbvc\AiPackage\Storage::ensure_directory($extract_dir);
        if (\is_wp_error($extract_dir_result)) {
            self::cleanup_directory($session_dir);
            return $extract_dir_result;
        }

        $extract_result = self::extract_zip_archive($archive_path, $extract_dir);
        if (\is_wp_error($extract_result)) {
            self::cleanup_directory($session_dir);
            return $extract_result;
        }

        $manifest = Storage::read_json_file(wp_normalize_path(trailingslashit($extract_dir) . 'manifest.json'));
        if (\is_wp_error($manifest)) {
            self::cleanup_directory($session_dir);
            return $manifest;
        }

        $manifest_validation = self::validate_manifest($manifest);
        if (\is_wp_error($manifest_validation)) {
            self::cleanup_directory($session_dir);
            return $manifest_validation;
        }

        $checksums = Storage::read_json_file(wp_normalize_path(trailingslashit($extract_dir) . 'checksums.json'));
        if (\is_wp_error($checksums)) {
            self::cleanup_directory($session_dir);
            return $checksums;
        }

        $checksum_validation = self::validate_checksums($extract_dir, $checksums);
        if (\is_wp_error($checksum_validation)) {
            self::cleanup_directory($session_dir);
            return $checksum_validation;
        }

        $site_payload = Storage::read_json_file(wp_normalize_path(trailingslashit($extract_dir) . 'site.json'));
        if (\is_wp_error($site_payload)) {
            self::cleanup_directory($session_dir);
            return $site_payload;
        }

        $redactions = Storage::read_json_file(wp_normalize_path(trailingslashit($extract_dir) . 'redactions.json'));
        if (\is_wp_error($redactions)) {
            self::cleanup_directory($session_dir);
            return $redactions;
        }

        $diffs = self::build_domain_diffs($extract_dir, $manifest);
        if (\is_wp_error($diffs)) {
            self::cleanup_directory($session_dir);
            return $diffs;
        }

        $session = [
            'record_type' => 'import_session',
            'session_id' => $session_id,
            'created_at_gmt' => gmdate('c'),
            'original_filename' => $original_name,
            'package_id' => sanitize_key((string) ($manifest['package_id'] ?? '')),
            'manifest' => $manifest,
            'site' => $site_payload,
            'redactions' => $redactions,
            'diffs' => $diffs['diffs'],
            'summary' => $diffs['summary'],
            'skipped_domains' => $diffs['skipped_domains'],
            'compatibility_warnings' => $diffs['compatibility_warnings'],
            'archive_path' => $archive_path,
            'extract_dir' => $extract_dir,
        ];

        $write = Storage::write_json_file($session_dir, 'session.json', $session);
        if (\is_wp_error($write)) {
            self::cleanup_directory($session_dir);
            return $write;
        }

        return $session;
    }

    /**
     * @param string $session_id
     * @return array<string, mixed>|\WP_Error
     */
    public static function get_session($session_id)
    {
        $session_id = sanitize_key((string) $session_id);
        if ($session_id === '') {
            return new \WP_Error('dbvc_config_portability_session_id_invalid', __('Configuration portability session id is invalid.', 'dbvc'));
        }

        $session_dir = Storage::resolve_session_directory($session_id);
        if (\is_wp_error($session_dir)) {
            return $session_dir;
        }

        return Storage::read_json_file(wp_normalize_path(trailingslashit($session_dir) . 'session.json'));
    }

    /**
     * @param array<string, mixed> $session
     * @return string
     */
    public static function get_review_fingerprint(array $session): string
    {
        $payload = [
            'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
            'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
            'created_at_gmt' => (string) ($session['created_at_gmt'] ?? ''),
            'manifest' => isset($session['manifest']) && is_array($session['manifest']) ? $session['manifest'] : [],
            'diffs' => isset($session['diffs']) && is_array($session['diffs']) ? $session['diffs'] : [],
        ];
        $json = wp_json_encode($payload);

        return hash('sha256', is_string($json) ? $json : '');
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public static function get_review_draft(array $session): array
    {
        $draft = isset($session['review_draft']) && is_array($session['review_draft']) ? $session['review_draft'] : [];
        if (empty($draft)) {
            return [
                'status' => 'none',
                'decisions' => [],
            ];
        }

        $fingerprint = self::get_review_fingerprint($session);
        $draft_fingerprint = (string) ($draft['session_fingerprint'] ?? '');
        if (
            (int) ($draft['version'] ?? 0) !== self::REVIEW_DRAFT_VERSION
            || $draft_fingerprint === ''
            || ! hash_equals($fingerprint, $draft_fingerprint)
        ) {
            return [
                'status' => 'stale',
                'decisions' => [],
            ];
        }

        $expires_at = strtotime((string) ($draft['expires_at_gmt'] ?? ''));
        if ($expires_at === false || $expires_at <= time()) {
            return [
                'status' => 'expired',
                'decisions' => [],
            ];
        }

        return [
            'status' => 'active',
            'saved_at_gmt' => (string) ($draft['saved_at_gmt'] ?? ''),
            'expires_at_gmt' => (string) ($draft['expires_at_gmt'] ?? ''),
            'session_fingerprint' => $draft_fingerprint,
            'decisions' => isset($draft['decisions']) && is_array($draft['decisions']) ? $draft['decisions'] : [],
        ];
    }

    /**
     * @param string                                             $session_id
     * @param array<string, array<string, array<string, mixed>>> $decisions
     * @param string                                             $expected_fingerprint
     * @return array<string, mixed>|\WP_Error
     */
    public static function save_review_draft($session_id, array $decisions, $expected_fingerprint)
    {
        $session = self::get_session($session_id);
        if (\is_wp_error($session)) {
            return $session;
        }

        if (! empty($session['applied_at_gmt']) || ! empty($session['rolled_back_at_gmt'])) {
            return new \WP_Error('dbvc_config_portability_review_draft_closed', __('Review drafts cannot be changed after a session is applied or rolled back.', 'dbvc'));
        }

        $current_fingerprint = self::get_review_fingerprint($session);
        $expected_fingerprint = strtolower(trim((string) $expected_fingerprint));
        if ($expected_fingerprint === '' || ! hash_equals($current_fingerprint, $expected_fingerprint)) {
            return new \WP_Error('dbvc_config_portability_review_draft_stale', __('This review changed after the form was loaded. Reload the session before saving decisions.', 'dbvc'));
        }

        $validated = self::validate_review_draft_decisions($session, $decisions);
        if (empty($validated)) {
            unset($session['review_draft']);
        } else {
            $saved_at = time();
            $session['review_draft'] = [
                'version' => self::REVIEW_DRAFT_VERSION,
                'saved_at_gmt' => gmdate('c', $saved_at),
                'expires_at_gmt' => gmdate('c', $saved_at + self::REVIEW_DRAFT_TTL_SECONDS),
                'session_fingerprint' => $current_fingerprint,
                'decisions' => $validated,
            ];
        }

        $write = self::write_session($session);
        if (\is_wp_error($write)) {
            return $write;
        }

        return $session;
    }

    /**
     * @param string                                             $session_id
     * @param array<string, array<string, array<string, mixed>>> $environment_decisions
     * @param array<string, mixed>                               $args
     * @return array<string, mixed>|\WP_Error
     */
    public static function apply_session($session_id, array $environment_decisions, array $args = [])
    {
        $session = self::get_session($session_id);
        if (\is_wp_error($session)) {
            return $session;
        }

        if (empty($args['confirm_apply'])) {
            return new \WP_Error('dbvc_config_portability_apply_confirmation_missing', __('Confirm the configuration import before applying settings.', 'dbvc'));
        }

        if (! empty($session['applied_at_gmt']) && empty($args['allow_reapply'])) {
            return new \WP_Error('dbvc_config_portability_session_already_applied', __('This configuration portability import session has already been applied.', 'dbvc'));
        }

        $prepared = self::prepare_apply_payloads($session, $environment_decisions);
        if (\is_wp_error($prepared)) {
            return $prepared;
        }

        $backup_id = self::generate_backup_id();
        $backup_dir = Storage::resolve_backup_directory($backup_id);
        if (\is_wp_error($backup_dir)) {
            return $backup_dir;
        }

        $backup = [
            'record_type' => 'configuration_portability_backup',
            'backup_id' => $backup_id,
            'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
            'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
            'created_at_gmt' => gmdate('c'),
            'domains' => [],
        ];

        foreach ($prepared['domains'] as $domain_key => $domain_payload) {
            if (! is_array($domain_payload) || empty($domain_payload['provider']) || ! $domain_payload['provider'] instanceof DomainProviderInterface) {
                continue;
            }

            $backup['domains'][$domain_key] = $domain_payload['provider']->capture_backup([
                'session_id' => $backup['session_id'],
                'package_id' => $backup['package_id'],
            ]);
        }

        $backup_path = Storage::write_json_file($backup_dir, 'backup.json', $backup);
        if (\is_wp_error($backup_path)) {
            return $backup_path;
        }

        $session['status'] = 'backup_captured';
        $session['backup_captured_at_gmt'] = gmdate('c');
        $session['backup_id'] = $backup_id;
        $session['backup_path'] = $backup_path;
        $backup_session_write = self::write_session($session);
        if (\is_wp_error($backup_session_write)) {
            return $backup_session_write;
        }

        $apply_results = [];
        foreach ($prepared['domains'] as $domain_key => $domain_payload) {
            if (! is_array($domain_payload) || empty($domain_payload['provider']) || ! $domain_payload['provider'] instanceof DomainProviderInterface) {
                continue;
            }

            $apply_results[$domain_key] = $domain_payload['provider']->apply(
                is_array($domain_payload['sanitized'] ?? null) ? $domain_payload['sanitized'] : [],
                [
                    'session_id' => $backup['session_id'],
                    'package_id' => $backup['package_id'],
                    'backup_id' => $backup_id,
                ]
            );
        }

        $session['status'] = 'applied';
        $session['applied_at_gmt'] = gmdate('c');
        $session['apply_result'] = $apply_results;
        $session['apply_summary'] = self::summarize_apply_results($apply_results);
        $session['environment_decision_summary'] = $prepared['environment_decision_summary'];
        unset($session['review_draft']);

        $write = self::write_session($session);
        if (\is_wp_error($write)) {
            return $write;
        }

        return $session;
    }

    /**
     * @param string               $session_id
     * @param array<string, mixed> $args
     * @return array<string, mixed>|\WP_Error
     */
    public static function rollback_session($session_id, array $args = [])
    {
        $session = self::get_session($session_id);
        if (\is_wp_error($session)) {
            return $session;
        }

        if (empty($args['confirm_rollback'])) {
            return new \WP_Error('dbvc_config_portability_rollback_confirmation_missing', __('Confirm rollback before restoring the previous configuration.', 'dbvc'));
        }

        if (empty($session['applied_at_gmt']) || empty($session['backup_id'])) {
            return new \WP_Error('dbvc_config_portability_rollback_unavailable', __('This configuration portability session does not have an applied backup to roll back.', 'dbvc'));
        }

        if (! empty($session['rolled_back_at_gmt']) && empty($args['allow_rerollback'])) {
            return new \WP_Error('dbvc_config_portability_session_already_rolled_back', __('This configuration portability import session has already been rolled back.', 'dbvc'));
        }

        $backup_id = sanitize_key((string) $session['backup_id']);
        $backup_dir = Storage::resolve_backup_directory($backup_id);
        if (\is_wp_error($backup_dir)) {
            return $backup_dir;
        }

        $backup = Storage::read_json_file(wp_normalize_path(trailingslashit($backup_dir) . 'backup.json'));
        if (\is_wp_error($backup)) {
            return $backup;
        }

        $rollback_results = [];
        $backup_domains = isset($backup['domains']) && is_array($backup['domains']) ? $backup['domains'] : [];
        foreach ($backup_domains as $domain_key => $domain_backup) {
            $domain_key = sanitize_key((string) $domain_key);
            if ($domain_key === '' || ! is_array($domain_backup)) {
                continue;
            }

            $provider = Registry::get_provider($domain_key);
            if (! $provider) {
                $rollback_results[$domain_key] = [
                    'domain' => $domain_key,
                    'restored' => [],
                    'deleted' => [],
                    'skipped' => ['provider_unavailable'],
                ];
                continue;
            }

            $rollback_results[$domain_key] = $provider->rollback($domain_backup, [
                'session_id' => sanitize_key((string) ($session['session_id'] ?? '')),
                'package_id' => sanitize_key((string) ($session['package_id'] ?? '')),
                'backup_id' => $backup_id,
            ]);
        }

        $session['status'] = 'rolled_back';
        $session['rolled_back_at_gmt'] = gmdate('c');
        $session['rollback_result'] = $rollback_results;
        $session['rollback_summary'] = self::summarize_rollback_results($rollback_results);

        $write = self::write_session($session);
        if (\is_wp_error($write)) {
            return $write;
        }

        return $session;
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
            return new \WP_Error('dbvc_config_portability_zip_open_failed', __('Unable to open the uploaded configuration portability ZIP package.', 'dbvc'));
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
                return new \WP_Error('dbvc_config_portability_zip_entry_invalid', __('The uploaded configuration portability ZIP contains an invalid path.', 'dbvc'));
            }
        }

        if (! $zip->extractTo($extract_dir)) {
            $zip->close();
            return new \WP_Error('dbvc_config_portability_zip_extract_failed', __('Failed to extract the uploaded configuration portability ZIP package.', 'dbvc'));
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
        if ((string) ($manifest['package_type'] ?? '') !== ExportPackageBuilder::PACKAGE_TYPE) {
            return new \WP_Error('dbvc_config_portability_manifest_type_invalid', __('The uploaded ZIP is not a DBVC configuration portability package.', 'dbvc'));
        }

        if ((int) ($manifest['package_schema_version'] ?? 0) !== ExportPackageBuilder::PACKAGE_SCHEMA_VERSION) {
            return new \WP_Error('dbvc_config_portability_manifest_version_invalid', __('This configuration portability package version is not supported.', 'dbvc'));
        }

        $selected_domains = isset($manifest['selected_domains']) && is_array($manifest['selected_domains']) ? $manifest['selected_domains'] : [];
        if (empty($selected_domains)) {
            return new \WP_Error('dbvc_config_portability_manifest_domains_missing', __('The uploaded configuration package does not include any selected domains.', 'dbvc'));
        }

        return true;
    }

    /**
     * @param string               $extract_dir
     * @param array<string, mixed> $checksums
     * @return true|\WP_Error
     */
    private static function validate_checksums($extract_dir, array $checksums)
    {
        foreach ($checksums as $relative_path => $expected_hash) {
            $relative_path = (string) $relative_path;
            $expected_hash = strtolower((string) $expected_hash);
            if ($relative_path === '' || $expected_hash === '') {
                return new \WP_Error('dbvc_config_portability_checksum_invalid', __('The configuration package checksum manifest is invalid.', 'dbvc'));
            }

            $path = Storage::resolve_child_path($extract_dir, $relative_path);
            if (\is_wp_error($path)) {
                return $path;
            }
            if (! is_file($path)) {
                return new \WP_Error('dbvc_config_portability_checksum_file_missing', sprintf(__('The configuration package is missing `%s`.', 'dbvc'), $relative_path));
            }

            $actual = hash_file('sha256', $path);
            if (! is_string($actual) || strtolower($actual) !== $expected_hash) {
                return new \WP_Error('dbvc_config_portability_checksum_mismatch', sprintf(__('The configuration package checksum failed for `%s`.', 'dbvc'), $relative_path));
            }
        }

        foreach (['manifest.json', 'site.json', 'redactions.json'] as $required_file) {
            if (! isset($checksums[$required_file])) {
                return new \WP_Error('dbvc_config_portability_checksum_required_missing', sprintf(__('The configuration package checksum manifest is missing `%s`.', 'dbvc'), $required_file));
            }
        }

        return true;
    }

    /**
     * @param string               $extract_dir
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>|\WP_Error
     */
    private static function build_domain_diffs($extract_dir, array $manifest)
    {
        $selected_domains = isset($manifest['selected_domains']) && is_array($manifest['selected_domains'])
            ? array_values(array_map('sanitize_key', $manifest['selected_domains']))
            : [];
        $manifest_domains = isset($manifest['domains']) && is_array($manifest['domains']) ? $manifest['domains'] : [];
        $diffs = [];
        $skipped = [];
        $preflight_warnings = [];
        $summary = [
            'domain_count' => 0,
            'row_count' => 0,
            'statuses' => [],
            'warning_count' => 0,
            'blocking_dependency_count' => 0,
        ];

        foreach ($selected_domains as $domain_key) {
            $provider = Registry::get_provider($domain_key);
            if (! $provider) {
                $skipped[$domain_key] = 'provider_unavailable';
                continue;
            }

            $domain_meta = isset($manifest_domains[$domain_key]) && is_array($manifest_domains[$domain_key]) ? $manifest_domains[$domain_key] : [];
            $domain_file = (string) ($domain_meta['file'] ?? ('domains/' . $domain_key . '.json'));
            $domain_path = Storage::resolve_child_path($extract_dir, $domain_file);
            if (\is_wp_error($domain_path)) {
                return $domain_path;
            }

            $incoming = Storage::read_json_file($domain_path);
            if (\is_wp_error($incoming)) {
                $skipped[$domain_key] = 'domain_file_missing';
                continue;
            }

            $preflight_warnings = array_merge(
                $preflight_warnings,
                self::build_domain_preflight_warnings($domain_key, $provider, $incoming)
            );

            $diff = $provider->diff($incoming, $provider->get_current_values(), [
                'package_id' => sanitize_key((string) ($manifest['package_id'] ?? '')),
            ]);
            $diffs[$domain_key] = $diff;
            $summary['domain_count']++;

            $rows = isset($diff['rows']) && is_array($diff['rows']) ? $diff['rows'] : [];
            $summary['row_count'] += count($rows);
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $status = sanitize_key((string) ($row['status'] ?? 'unknown'));
                if ($status === '') {
                    $status = 'unknown';
                }
                $summary['statuses'][$status] = (int) ($summary['statuses'][$status] ?? 0) + 1;
            }
        }

        ksort($summary['statuses'], SORT_STRING);
        $warnings = self::build_compatibility_warnings($manifest, $selected_domains, $diffs, $skipped, $preflight_warnings);
        $summary['warning_count'] = count($warnings);
        $summary['blocking_dependency_count'] = count(array_filter($warnings, static function ($warning): bool {
            return is_array($warning)
                && ($warning['code'] ?? '') === 'missing_dependency'
                && ($warning['severity'] ?? '') === 'blocked';
        }));

        return [
            'diffs' => $diffs,
            'summary' => $summary,
            'skipped_domains' => $skipped,
            'compatibility_warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed>  $manifest
     * @param array<int, string>    $selected_domains
     * @param array<string, mixed>  $diffs
     * @param array<string, string> $skipped_domains
     * @param array<int, array<string, string>> $preflight_warnings
     * @return array<int, array<string, string>>
     */
    private static function build_compatibility_warnings(array $manifest, array $selected_domains, array $diffs, array $skipped_domains, array $preflight_warnings = []): array
    {
        $warnings = [];
        $compatibility = isset($manifest['compatibility']) && is_array($manifest['compatibility']) ? $manifest['compatibility'] : [];
        $generator = isset($manifest['generator']) && is_array($manifest['generator']) ? $manifest['generator'] : [];
        $current_dbvc_version = defined('DBVC_PLUGIN_VERSION') ? (string) DBVC_PLUGIN_VERSION : '';
        $minimum_dbvc_version = (string) ($compatibility['min_dbvc_version'] ?? ($generator['dbvc_version'] ?? ''));

        if (
            $current_dbvc_version !== ''
            && $minimum_dbvc_version !== ''
            && version_compare($current_dbvc_version, $minimum_dbvc_version, '<')
        ) {
            $warnings[] = [
                'code' => 'package_requires_newer_dbvc',
                'severity' => 'warning',
                'domain' => '',
                'message' => sprintf(
                    __('This package was created for DBVC %1$s or newer. Current DBVC version is %2$s.', 'dbvc'),
                    $minimum_dbvc_version,
                    $current_dbvc_version
                ),
            ];
        }

        $manifest_domains = isset($manifest['domains']) && is_array($manifest['domains']) ? $manifest['domains'] : [];
        foreach ($selected_domains as $domain_key) {
            $domain_key = sanitize_key((string) $domain_key);
            if ($domain_key === '') {
                continue;
            }

            if (isset($skipped_domains[$domain_key])) {
                $warnings[] = [
                    'code' => 'domain_skipped',
                    'severity' => 'warning',
                    'domain' => $domain_key,
                    'message' => sprintf(
                        __('Domain `%1$s` was skipped during import review: %2$s.', 'dbvc'),
                        $domain_key,
                        (string) $skipped_domains[$domain_key]
                    ),
                ];
                continue;
            }

            $provider = Registry::get_provider($domain_key);
            if (! $provider) {
                continue;
            }

            $domain_meta = isset($manifest_domains[$domain_key]) && is_array($manifest_domains[$domain_key]) ? $manifest_domains[$domain_key] : [];
            $incoming_version = (int) ($domain_meta['domain_version'] ?? 0);
            $current_version = $provider->get_version();
            if ($incoming_version > $current_version) {
                $warnings[] = [
                    'code' => 'domain_version_newer',
                    'severity' => 'warning',
                    'domain' => $domain_key,
                    'message' => sprintf(
                        __('Domain `%1$s` was exported with version %2$d, but this site supports version %3$d.', 'dbvc'),
                        $domain_key,
                        $incoming_version,
                        $current_version
                    ),
                ];
            }

            if (empty($provider->get_fields())) {
                $warnings[] = [
                    'code' => 'target_domain_has_no_fields',
                    'severity' => 'warning',
                    'domain' => $domain_key,
                    'message' => sprintf(
                        __('Domain `%s` has no configurable fields available on this site. The related add-on may be inactive or unavailable.', 'dbvc'),
                        $domain_key
                    ),
                ];
            }
        }

        return array_merge($warnings, $preflight_warnings);
    }

    /**
     * Surface unsupported incoming fields and provider-owned missing dependencies.
     *
     * @param string                  $domain_key
     * @param DomainProviderInterface $provider
     * @param array<string, mixed>    $incoming
     * @return array<int, array<string, string>>
     */
    private static function build_domain_preflight_warnings($domain_key, DomainProviderInterface $provider, array $incoming): array
    {
        $domain_key = sanitize_key((string) $domain_key);
        $warnings = [];
        $incoming_fields = self::flatten_incoming_fields($incoming);
        $supported_fields = array_fill_keys(array_map('sanitize_key', array_keys($provider->get_fields())), true);

        foreach (array_keys($incoming_fields) as $field_key) {
            if (isset($supported_fields[$field_key])) {
                continue;
            }

            $warnings[] = [
                'code' => 'unsupported_field',
                'severity' => 'warning',
                'domain' => $domain_key,
                'field' => $field_key,
                'message' => sprintf(
                    __('Field `%1$s` in domain `%2$s` is not supported on this site and will be skipped.', 'dbvc'),
                    $field_key,
                    $domain_key
                ),
            ];
        }

        if (! method_exists($provider, 'get_import_dependencies')) {
            return $warnings;
        }

        $dependencies = $provider->get_import_dependencies($incoming);
        if (! is_array($dependencies)) {
            return $warnings;
        }

        $seen = [];
        foreach ($dependencies as $dependency) {
            if (! is_array($dependency)) {
                continue;
            }

            $type = sanitize_key((string) ($dependency['type'] ?? ''));
            $identifier = $type === 'acf_options_group'
                ? sanitize_text_field((string) ($dependency['identifier'] ?? ''))
                : ltrim((string) ($dependency['identifier'] ?? ''), '\\');
            $field_key = sanitize_key((string) ($dependency['field'] ?? ''));
            if ($type === '' || $identifier === '' || self::dependency_is_available($type, $identifier)) {
                continue;
            }

            $dedupe_key = $type . ':' . $identifier . ':' . $field_key;
            if (isset($seen[$dedupe_key])) {
                continue;
            }
            $seen[$dedupe_key] = true;

            $label = sanitize_text_field((string) ($dependency['label'] ?? ''));
            $display = $label !== '' ? $label . ' (' . $identifier . ')' : $identifier;
            $warnings[] = [
                'code' => 'missing_dependency',
                'severity' => 'blocked',
                'domain' => $domain_key,
                'field' => $field_key,
                'dependency_type' => $type,
                'dependency' => $identifier,
                'message' => sprintf(
                    __('Domain `%1$s` requires missing %2$s `%3$s` for field `%4$s`; apply is blocked.', 'dbvc'),
                    $domain_key,
                    str_replace('_', ' ', $type),
                    $display,
                    $field_key
                ),
            ];
        }

        return $warnings;
    }

    /**
     * @param string $type
     * @param string $identifier
     * @return bool
     */
    private static function dependency_is_available($type, $identifier): bool
    {
        if ($type === 'post_type') {
            return post_type_exists(sanitize_key((string) $identifier));
        }

        if ($type === 'taxonomy') {
            return taxonomy_exists(sanitize_key((string) $identifier));
        }

        if ($type === 'class') {
            return class_exists(ltrim((string) $identifier, '\\'));
        }

        if ($type === 'acf_options_group') {
            if (! class_exists('DBVC_Options_Groups')) {
                return false;
            }

            foreach (\DBVC_Options_Groups::get_available_groups() as $group) {
                if (is_array($group) && (string) ($group['id'] ?? '') === (string) $identifier) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $incoming
     * @return array<string, array<string, mixed>>
     */
    private static function flatten_incoming_fields(array $incoming): array
    {
        $groups = isset($incoming['groups']) && is_array($incoming['groups']) ? $incoming['groups'] : [];
        $fields = [];
        foreach ($groups as $group) {
            if (! is_array($group) || ! isset($group['fields']) || ! is_array($group['fields'])) {
                continue;
            }
            foreach ($group['fields'] as $field_key => $payload) {
                $field_key = sanitize_key((string) $field_key);
                if ($field_key !== '' && is_array($payload)) {
                    $fields[$field_key] = $payload;
                }
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed>                               $session
     * @param array<string, array<string, array<string, mixed>>> $environment_decisions
     * @return array<string, mixed>|\WP_Error
     */
    private static function prepare_apply_payloads(array $session, array $environment_decisions)
    {
        $manifest = isset($session['manifest']) && is_array($session['manifest']) ? $session['manifest'] : [];
        $extract_dir = isset($session['extract_dir']) ? (string) $session['extract_dir'] : '';
        if ($extract_dir === '' || ! is_dir($extract_dir)) {
            return new \WP_Error('dbvc_config_portability_session_extract_missing', __('The staged configuration package files are unavailable.', 'dbvc'));
        }

        $diffs = isset($session['diffs']) && is_array($session['diffs']) ? $session['diffs'] : [];
        if (empty($diffs)) {
            return new \WP_Error('dbvc_config_portability_apply_domains_missing', __('This configuration portability session does not contain any domains to apply.', 'dbvc'));
        }

        $domains = [];
        $errors = [];
        $decision_summary = [];
        foreach ($diffs as $domain_key => $diff) {
            $domain_key = sanitize_key((string) $domain_key);
            if ($domain_key === '') {
                continue;
            }

            $provider = Registry::get_provider($domain_key);
            if (! $provider) {
                $errors[$domain_key]['domain'] = 'provider_unavailable';
                continue;
            }

            $incoming = self::read_domain_payload($extract_dir, $manifest, $domain_key);
            if (\is_wp_error($incoming)) {
                $errors[$domain_key]['domain'] = $incoming->get_error_message();
                continue;
            }

            foreach (self::build_domain_preflight_warnings($domain_key, $provider, $incoming) as $warning) {
                if (($warning['code'] ?? '') !== 'missing_dependency') {
                    continue;
                }

                $field_key = sanitize_key((string) ($warning['field'] ?? ''));
                if ($field_key === '') {
                    $field_key = 'dependency';
                }
                $errors[$domain_key][$field_key] = sprintf(
                    'missing_dependency:%s:%s',
                    sanitize_key((string) ($warning['dependency_type'] ?? 'unknown')),
                    sanitize_text_field((string) ($warning['dependency'] ?? 'unknown'))
                );
            }

            $domain_decisions = isset($environment_decisions[$domain_key]) && is_array($environment_decisions[$domain_key])
                ? $environment_decisions[$domain_key]
                : [];
            $resolved = self::resolve_environment_values_for_domain(is_array($diff) ? $diff : [], $domain_decisions, $errors, $decision_summary, $domain_key);
            $current = $provider->get_current_values();
            $sanitized = $provider->sanitize_for_apply($incoming, $resolved, $current);

            if (! empty($sanitized['errors']) && is_array($sanitized['errors'])) {
                foreach ($sanitized['errors'] as $field_key => $message) {
                    $errors[$domain_key][sanitize_key((string) $field_key)] = (string) $message;
                }
            }

            $skipped = isset($sanitized['skipped']) && is_array($sanitized['skipped']) ? $sanitized['skipped'] : [];
            foreach ($skipped as $field_key => $reason) {
                if ($reason !== 'environment_value_required') {
                    continue;
                }

                $field_key = sanitize_key((string) $field_key);
                $action = isset($domain_decisions[$field_key]['action']) ? sanitize_key((string) $domain_decisions[$field_key]['action']) : '';
                if ($action !== 'keep') {
                    $errors[$domain_key][$field_key] = 'environment_decision_required';
                }
            }

            $domains[$domain_key] = [
                'provider' => $provider,
                'incoming' => $incoming,
                'sanitized' => $sanitized,
            ];
        }

        if (! empty($errors)) {
            return new \WP_Error(
                'dbvc_config_portability_apply_preflight_failed',
                __('Resolve required configuration portability import fields before applying settings.', 'dbvc'),
                [
                    'errors' => $errors,
                ]
            );
        }

        return [
            'domains' => $domains,
            'environment_decision_summary' => $decision_summary,
        ];
    }

    /**
     * @param string               $extract_dir
     * @param array<string, mixed> $manifest
     * @param string               $domain_key
     * @return array<string, mixed>|\WP_Error
     */
    private static function read_domain_payload($extract_dir, array $manifest, $domain_key)
    {
        $domain_key = sanitize_key((string) $domain_key);
        $manifest_domains = isset($manifest['domains']) && is_array($manifest['domains']) ? $manifest['domains'] : [];
        $domain_meta = isset($manifest_domains[$domain_key]) && is_array($manifest_domains[$domain_key]) ? $manifest_domains[$domain_key] : [];
        $domain_file = (string) ($domain_meta['file'] ?? ('domains/' . $domain_key . '.json'));
        $domain_path = Storage::resolve_child_path($extract_dir, $domain_file);
        if (\is_wp_error($domain_path)) {
            return $domain_path;
        }

        return Storage::read_json_file($domain_path);
    }

    /**
     * @param array<string, mixed>                               $diff
     * @param array<string, array<string, mixed>>                $domain_decisions
     * @param array<string, array<string, string>>               $errors
     * @param array<string, array<string, array<string, string>>> $decision_summary
     * @param string                                             $domain_key
     * @return array<string, mixed>
     */
    private static function resolve_environment_values_for_domain(array $diff, array $domain_decisions, array &$errors, array &$decision_summary, $domain_key): array
    {
        $resolved = [];
        $rows = isset($diff['rows']) && is_array($diff['rows']) ? $diff['rows'] : [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $field_key = sanitize_key((string) ($row['field'] ?? ''));
            if ($field_key === '') {
                continue;
            }

            $status = sanitize_key((string) ($row['status'] ?? ''));
            if ($status !== 'needs_environment_value') {
                continue;
            }

            $decision = isset($domain_decisions[$field_key]) && is_array($domain_decisions[$field_key])
                ? $domain_decisions[$field_key]
                : [];
            $action = sanitize_key((string) ($decision['action'] ?? ''));
            if ($action === 'keep') {
                $decision_summary[$domain_key][$field_key] = [
                    'action' => 'keep',
                ];
                continue;
            }

            if ($action === 'replace') {
                $resolved[$field_key] = $decision['value'] ?? '';
                $decision_summary[$domain_key][$field_key] = [
                    'action' => 'replace',
                ];
                continue;
            }

            $errors[$domain_key][$field_key] = 'environment_decision_required';
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed>                               $session
     * @param array<string, array<string, array<string, mixed>>> $decisions
     * @return array<string, array<string, array<string, string>>>
     */
    private static function validate_review_draft_decisions(array $session, array $decisions): array
    {
        $allowed = [];
        $diffs = isset($session['diffs']) && is_array($session['diffs']) ? $session['diffs'] : [];
        foreach ($diffs as $domain_key => $diff) {
            $domain_key = sanitize_key((string) $domain_key);
            $rows = is_array($diff) && isset($diff['rows']) && is_array($diff['rows']) ? $diff['rows'] : [];
            foreach ($rows as $row) {
                if (! is_array($row) || sanitize_key((string) ($row['status'] ?? '')) !== 'needs_environment_value') {
                    continue;
                }

                $field_key = sanitize_key((string) ($row['field'] ?? ''));
                if ($domain_key !== '' && $field_key !== '') {
                    $allowed[$domain_key][$field_key] = [
                        'persist_value' => ! in_array((string) ($row['type'] ?? 'text'), ['secret', 'key_id'], true),
                    ];
                }
            }
        }

        $validated = [];
        foreach ($decisions as $domain_key => $domain_decisions) {
            $domain_key = sanitize_key((string) $domain_key);
            if ($domain_key === '' || ! is_array($domain_decisions)) {
                continue;
            }

            foreach ($domain_decisions as $field_key => $decision) {
                $field_key = sanitize_key((string) $field_key);
                if ($field_key === '' || empty($allowed[$domain_key][$field_key]) || ! is_array($decision)) {
                    continue;
                }

                $action = sanitize_key((string) ($decision['action'] ?? ''));
                if (! in_array($action, ['keep', 'replace'], true)) {
                    continue;
                }

                $validated[$domain_key][$field_key] = [
                    'action' => $action,
                    'value' => $action === 'replace'
                        && ! empty($allowed[$domain_key][$field_key]['persist_value'])
                        && isset($decision['value'])
                        && is_scalar($decision['value'])
                        ? sanitize_text_field((string) $decision['value'])
                        : '',
                ];
            }
        }

        return $validated;
    }

    /**
     * @param array<string, array<string, mixed>> $apply_results
     * @return array<string, int>
     */
    private static function summarize_apply_results(array $apply_results): array
    {
        $summary = [
            'domains' => count($apply_results),
            'applied_fields' => 0,
            'skipped_fields' => 0,
        ];

        foreach ($apply_results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $summary['applied_fields'] += isset($result['applied']) && is_array($result['applied']) ? count($result['applied']) : 0;
            $summary['skipped_fields'] += isset($result['skipped']) && is_array($result['skipped']) ? count($result['skipped']) : 0;
        }

        return $summary;
    }

    /**
     * @param array<string, array<string, mixed>> $rollback_results
     * @return array<string, int>
     */
    private static function summarize_rollback_results(array $rollback_results): array
    {
        $summary = [
            'domains' => count($rollback_results),
            'restored_fields' => 0,
            'deleted_fields' => 0,
        ];

        foreach ($rollback_results as $result) {
            if (! is_array($result)) {
                continue;
            }

            $summary['restored_fields'] += isset($result['restored']) && is_array($result['restored']) ? count($result['restored']) : 0;
            $summary['deleted_fields'] += isset($result['deleted']) && is_array($result['deleted']) ? count($result['deleted']) : 0;
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $session
     * @return string|\WP_Error
     */
    private static function write_session(array $session)
    {
        $session_id = sanitize_key((string) ($session['session_id'] ?? ''));
        if ($session_id === '') {
            return new \WP_Error('dbvc_config_portability_session_id_invalid', __('Configuration portability session id is invalid.', 'dbvc'));
        }

        $session_dir = Storage::resolve_session_directory($session_id);
        if (\is_wp_error($session_dir)) {
            return $session_dir;
        }

        return Storage::write_json_file($session_dir, 'session.json', $session);
    }

    /**
     * @return string
     */
    private static function generate_session_id(): string
    {
        $token = function_exists('wp_generate_uuid4')
            ? substr(str_replace('-', '', wp_generate_uuid4()), 0, 8)
            : substr(md5((string) microtime(true)), 0, 8);

        return sanitize_key('dbvc-config-session-' . gmdate('Ymd-His') . '-' . strtolower($token));
    }

    /**
     * @return string
     */
    private static function generate_backup_id(): string
    {
        $token = function_exists('wp_generate_uuid4')
            ? substr(str_replace('-', '', wp_generate_uuid4()), 0, 8)
            : substr(md5((string) microtime(true)), 0, 8);

        return sanitize_key('dbvc-config-backup-' . gmdate('Ymd-His') . '-' . strtolower($token));
    }

    /**
     * @param string $directory
     * @return void
     */
    private static function cleanup_directory($directory): void
    {
        $directory = wp_normalize_path((string) $directory);
        if ($directory === '' || strpos($directory, '/dbvc-config-portability/sessions/') === false || ! is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if (! $item instanceof \SplFileInfo) {
                continue;
            }

            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }
}
