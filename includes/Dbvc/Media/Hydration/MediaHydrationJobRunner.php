<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Processes persisted media hydration jobs in bounded batches.
 */
final class MediaHydrationJobRunner
{
    private const CRON_HOOK = 'dbvc_media_hydration_run_job';

    public static function init(): void
    {
        add_action(self::CRON_HOOK, [__CLASS__, 'run_scheduled'], 10, 1);
    }

    public static function schedule(int $job_id): void
    {
        $job_id = absint($job_id);
        if ($job_id <= 0 || ! function_exists('wp_schedule_single_event')) {
            return;
        }

        if (! wp_next_scheduled(self::CRON_HOOK, [$job_id])) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK, [$job_id]);
        }
    }

    public static function run_scheduled(int $job_id): void
    {
        self::run($job_id);
    }

    /**
     * @param int $job_id
     * @return array<string,mixed>|\WP_Error
     */
    public static function run(int $job_id)
    {
        $job = MediaHydrationJobStore::get($job_id, true);
        if (is_wp_error($job)) {
            return $job;
        }

        $status = (string) ($job['status'] ?? '');
        if (! in_array($status, ['queued', 'running'], true)) {
            return new \WP_Error('dbvc_media_hydration_job_not_runnable', __('Media hydration job is not runnable in its current status.', 'dbvc'), ['status' => 409]);
        }

        $context = isset($job['context']) && is_array($job['context']) ? $job['context'] : [];
        $validated = self::validate_context($context);
        if (is_wp_error($validated)) {
            $context['last_error'] = $validated->get_error_code();
            MediaHydrationJobStore::update($job_id, ['status' => 'failed'], $context);
            return $validated;
        }

        $lock = HydrationLock::acquire([
            'owner' => 'media_hydration_job:' . $job_id,
            'ttl_seconds' => Settings::get_lock_timeout_minutes() * 60,
        ]);
        if (is_wp_error($lock)) {
            return $lock;
        }

        $report = null;
        try {
            MediaHydrationJobStore::update($job_id, ['status' => 'running'], $context);
            $args = self::apply_args_from_context($context);
            $report = Hydrator::apply_from_manifest_file((string) $context['manifest_path'], $args);
        } finally {
            HydrationLock::release((string) ($lock['token'] ?? ''));
        }

        if (is_wp_error($report)) {
            $context['last_error'] = $report->get_error_code();
            MediaHydrationJobStore::update($job_id, ['status' => 'failed'], $context);
            return $report;
        }

        $updated = self::context_after_report($context, $report);
        $progress = isset($updated['progress']) ? (float) $updated['progress'] : 0.0;
        $next_status = ! empty($report['progress']['has_more']) ? 'running' : 'completed';

        if (Settings::get_bool(Settings::OPTION_RECEIPTS_ENABLED) === '1') {
            $receipt = HydrationReceiptStore::write('apply', $report, [
                'run_id' => 'job-' . $job_id . '-' . gmdate('Ymd-His'),
            ]);
            if (is_wp_error($receipt)) {
                $updated['last_error'] = $receipt->get_error_code();
            } else {
                $ids = isset($updated['receipt_ids']) && is_array($updated['receipt_ids']) ? $updated['receipt_ids'] : [];
                $ids[] = (string) ($receipt['receipt_id'] ?? '');
                $updated['receipt_ids'] = array_values(array_filter(array_unique($ids)));
            }
        }

        $saved = MediaHydrationJobStore::update($job_id, [
            'status' => $next_status,
            'progress' => $progress,
        ], $updated);
        if (is_wp_error($saved)) {
            return $saved;
        }

        if ($next_status === 'running') {
            self::schedule($job_id);
        }

        if (class_exists('\DBVC_Database') && method_exists('\DBVC_Database', 'log_activity')) {
            \DBVC_Database::log_activity($next_status === 'completed' ? 'media_hydration_job_completed' : 'media_hydration_job_batch', 'info', __('Media hydration job batch processed.', 'dbvc'), [
                'job_id' => $job_id,
                'status' => $next_status,
                'progress' => $progress,
            ], [
                'job_id' => $job_id,
            ]);
        }

        return [
            'job' => $saved,
            'report' => $report,
        ];
    }

    /**
     * @param array<string,mixed> $context
     * @return true|\WP_Error
     */
    private static function validate_context(array $context)
    {
        $manifest_path = self::normalize_manifest_path((string) ($context['manifest_path'] ?? ''));
        if (is_wp_error($manifest_path)) {
            return $manifest_path;
        }

        $package_root = self::normalize_package_root((string) ($context['package_root'] ?? ''));
        if (is_wp_error($package_root)) {
            return $package_root;
        }

        $raw = file_get_contents($manifest_path);
        $manifest = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($manifest)) {
            return new \WP_Error('dbvc_media_hydration_manifest_invalid_json', __('Media mirror manifest is not valid JSON.', 'dbvc'), ['status' => 400]);
        }

        $expected_manifest_checksum = (string) ($context['manifest_checksum'] ?? '');
        $manifest_checksum = (string) ($manifest['checksum'] ?? '');
        if ($expected_manifest_checksum !== '' && $manifest_checksum !== '' && ! hash_equals($expected_manifest_checksum, $manifest_checksum)) {
            return new \WP_Error('dbvc_media_hydration_job_manifest_changed', __('Media hydration job manifest no longer matches the queued job.', 'dbvc'), ['status' => 409]);
        }

        $plan_id = (string) ($context['plan_id'] ?? '');
        if ($plan_id !== '') {
            $plan = HydrationPlanStore::verify_for_manifest($plan_id, $manifest);
            if (is_wp_error($plan)) {
                return $plan;
            }

            $expected_plan_checksum = (string) ($context['plan_checksum'] ?? '');
            if ($expected_plan_checksum !== '' && ! hash_equals($expected_plan_checksum, self::plan_checksum($plan))) {
                return new \WP_Error('dbvc_media_hydration_job_plan_changed', __('Media hydration dry-run plan no longer matches the queued job.', 'dbvc'), ['status' => 409]);
            }
        }

        return true;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function apply_args_from_context(array $context): array
    {
        $policy = isset($context['policy']) && is_array($context['policy']) ? $context['policy'] : [];
        $match_policy = sanitize_key((string) ($policy['match_policy'] ?? Settings::get_match_policy()));
        if (! in_array($match_policy, Settings::allowed_match_policies(), true)) {
            $match_policy = Settings::get_match_policy();
        }
        $args = [
            'package_root' => (string) ($context['package_root'] ?? ''),
            'offset' => absint($context['offset'] ?? 0),
            'limit' => max(1, min(500, absint($context['limit'] ?? Settings::get_batch_size()))),
            'clone_confirmation' => ! empty($policy['clone_confirmation']),
            'strict_hashes' => ! empty($policy['strict_hashes']),
            'match_policy' => $match_policy,
            'repair_metadata' => ! empty($policy['repair_metadata']),
            'normalize_media_urls_to_https' => ! empty($policy['normalize_media_urls_to_https']),
            'overwrite_existing' => false,
        ];

        if (! empty($context['source_ids']) && is_array($context['source_ids'])) {
            $args['source_ids'] = $context['source_ids'];
        }

        return $args;
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private static function context_after_report(array $context, array $report): array
    {
        $progress = isset($report['progress']) && is_array($report['progress']) ? $report['progress'] : [];
        $summary = isset($report['summary']) && is_array($report['summary']) ? $report['summary'] : [];
        $cumulative = isset($context['cumulative_summary']) && is_array($context['cumulative_summary']) ? $context['cumulative_summary'] : [];
        foreach (['items', 'hydrated', 'metadata_repaired', 'skipped', 'blocked', 'errors', 'bytes'] as $key) {
            $cumulative[$key] = absint($cumulative[$key] ?? 0) + absint($summary[$key] ?? 0);
        }

        $context['offset'] = absint($progress['next_offset'] ?? $context['offset'] ?? 0);
        $context['processed_total'] = absint($progress['processed_total'] ?? $context['processed_total'] ?? 0);
        $context['total_plan_items'] = absint($progress['total_plan_items'] ?? $context['total_plan_items'] ?? 0);
        $context['cumulative_summary'] = $cumulative;
        $context['progress'] = max(0, min(100, (float) ($progress['percent'] ?? 0)));
        $context['last_error'] = '';

        return $context;
    }

    /**
     * @param array<string,mixed> $plan
     * @return string
     */
    public static function plan_checksum(array $plan): string
    {
        $encoded = wp_json_encode($plan, JSON_UNESCAPED_SLASHES);
        return 'sha256:' . hash('sha256', is_string($encoded) ? $encoded : '');
    }

    /**
     * @param string $path
     * @return string|\WP_Error
     */
    private static function normalize_manifest_path(string $path)
    {
        $path = self::normalize_allowed_local_path($path, true);
        if (is_wp_error($path)) {
            return $path;
        }

        if (basename($path) !== MirrorManifestBuilder::MANIFEST_FILENAME) {
            return new \WP_Error('dbvc_media_hydration_bad_manifest_name', __('Expected a DBVC media mirror manifest file.', 'dbvc'), ['status' => 400]);
        }

        return $path;
    }

    /**
     * @param string $path
     * @return string|\WP_Error
     */
    private static function normalize_package_root(string $path)
    {
        return self::normalize_allowed_local_path($path, false);
    }

    /**
     * @param string $path
     * @param bool   $must_be_file
     * @return string|\WP_Error
     */
    private static function normalize_allowed_local_path(string $path, bool $must_be_file)
    {
        if ($path === '' || strpos($path, chr(0)) !== false) {
            return new \WP_Error('dbvc_media_hydration_bad_path', __('Invalid media hydration path.', 'dbvc'), ['status' => 400]);
        }

        $real = realpath(wp_normalize_path($path));
        if (! is_string($real) || $real === '') {
            return new \WP_Error('dbvc_media_hydration_path_missing', __('Media hydration path does not exist.', 'dbvc'), ['status' => 404]);
        }

        if ($must_be_file && (! is_file($real) || ! is_readable($real))) {
            return new \WP_Error('dbvc_media_hydration_path_unreadable', __('Media hydration file is unreadable.', 'dbvc'), ['status' => 400]);
        }

        if (! $must_be_file && (! is_dir($real) || ! is_readable($real))) {
            return new \WP_Error('dbvc_media_hydration_path_unreadable', __('Media hydration directory is unreadable.', 'dbvc'), ['status' => 400]);
        }

        $real = wp_normalize_path($real);
        foreach (self::allowed_roots() as $root) {
            if (self::path_starts_with($real, $root)) {
                return $real;
            }
        }

        return new \WP_Error('dbvc_media_hydration_path_not_allowed', __('Media hydration path is outside allowed DBVC media roots.', 'dbvc'), ['status' => 403]);
    }

    /**
     * @return string[]
     */
    private static function allowed_roots(): array
    {
        $roots = [];
        if (function_exists('dbvc_get_sync_path')) {
            $sync_root = realpath(dbvc_get_sync_path());
            if (is_string($sync_root) && $sync_root !== '') {
                $roots[] = wp_normalize_path($sync_root);
            }
        }

        $uploads = wp_get_upload_dir();
        if (! empty($uploads['basedir'])) {
            $upload_root = realpath((string) $uploads['basedir']);
            if (is_string($upload_root) && $upload_root !== '') {
                $roots[] = wp_normalize_path($upload_root);
            }
        }

        return array_values(array_unique($roots));
    }

    private static function path_starts_with(string $path, string $base): bool
    {
        $path = rtrim(wp_normalize_path($path), '/') . '/';
        $base = rtrim(wp_normalize_path($base), '/') . '/';
        return strpos($path, $base) === 0;
    }
}
