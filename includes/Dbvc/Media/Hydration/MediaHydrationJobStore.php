<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Thin adapter for media hydration rows in the shared DBVC jobs table.
 */
final class MediaHydrationJobStore
{
    public const JOB_TYPE = 'media_hydration';

    /**
     * @return string[]
     */
    public static function allowed_statuses(): array
    {
        return ['queued', 'running', 'paused', 'completed', 'failed', 'cancelled'];
    }

    /**
     * @param array<string,mixed> $context
     * @param string              $status
     * @return array<string,mixed>|\WP_Error
     */
    public static function create(array $context, string $status = 'queued')
    {
        if (! class_exists('\DBVC_Database')) {
            return new \WP_Error('dbvc_media_hydration_jobs_unavailable', __('DBVC job storage is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $status = self::sanitize_status($status, 'queued');
        $context = self::sanitize_context($context);
        $context['progress'] = isset($context['progress']) ? (float) $context['progress'] : 0.0;

        $job_id = \DBVC_Database::create_job(self::JOB_TYPE, $context, $status);
        if ($job_id <= 0) {
            return new \WP_Error('dbvc_media_hydration_job_create_failed', __('Unable to create media hydration job.', 'dbvc'), ['status' => 500]);
        }

        if (method_exists('\DBVC_Database', 'log_activity')) {
            \DBVC_Database::log_activity('media_hydration_job_created', 'info', __('Media hydration job created.', 'dbvc'), [
                'job_id' => $job_id,
                'manifest_checksum' => (string) ($context['manifest_checksum'] ?? ''),
                'total_plan_items' => (int) ($context['total_plan_items'] ?? 0),
            ], [
                'job_id' => $job_id,
            ]);
        }

        return self::get($job_id);
    }

    /**
     * @param int  $job_id
     * @param bool $include_private_context
     * @return array<string,mixed>|\WP_Error
     */
    public static function get(int $job_id, bool $include_private_context = false)
    {
        $row = self::row($job_id);
        if (is_wp_error($row)) {
            return $row;
        }

        return self::format_row($row, $include_private_context);
    }

    /**
     * @param int $job_id
     * @return array<string,mixed>|\WP_Error
     */
    public static function context(int $job_id)
    {
        $row = self::row($job_id);
        if (is_wp_error($row)) {
            return $row;
        }

        return self::decode_context($row);
    }

    /**
     * @param int                 $job_id
     * @param array<string,mixed> $fields
     * @param array<string,mixed>|null $context
     * @return array<string,mixed>|\WP_Error
     */
    public static function update(int $job_id, array $fields = [], ?array $context = null)
    {
        if (! class_exists('\DBVC_Database')) {
            return new \WP_Error('dbvc_media_hydration_jobs_unavailable', __('DBVC job storage is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $row = self::row($job_id);
        if (is_wp_error($row)) {
            return $row;
        }

        if (isset($fields['status'])) {
            $fields['status'] = self::sanitize_status((string) $fields['status'], (string) $row->status);
        }

        if (isset($fields['progress'])) {
            $fields['progress'] = max(0, min(100, (float) $fields['progress']));
        }

        $context = $context === null ? null : self::sanitize_context($context);
        \DBVC_Database::update_job($job_id, $fields, $context);

        return self::get($job_id);
    }

    /**
     * @param int    $job_id
     * @param string $status
     * @return array<string,mixed>|\WP_Error
     */
    public static function transition(int $job_id, string $status)
    {
        return self::update($job_id, ['status' => $status]);
    }

    /**
     * @param int $job_id
     * @return object|\WP_Error
     */
    private static function row(int $job_id)
    {
        if (! class_exists('\DBVC_Database')) {
            return new \WP_Error('dbvc_media_hydration_jobs_unavailable', __('DBVC job storage is unavailable.', 'dbvc'), ['status' => 500]);
        }

        $job_id = absint($job_id);
        if ($job_id <= 0) {
            return new \WP_Error('dbvc_media_hydration_bad_job_id', __('Invalid media hydration job ID.', 'dbvc'), ['status' => 400]);
        }

        $row = \DBVC_Database::get_job($job_id);
        if (! is_object($row)) {
            return new \WP_Error('dbvc_media_hydration_job_missing', __('Media hydration job was not found.', 'dbvc'), ['status' => 404]);
        }

        if ((string) ($row->job_type ?? '') !== self::JOB_TYPE) {
            return new \WP_Error('dbvc_media_hydration_job_type_mismatch', __('The requested job is not a media hydration job.', 'dbvc'), ['status' => 404]);
        }

        return $row;
    }

    /**
     * @param object $row
     * @param bool   $include_private_context
     * @return array<string,mixed>
     */
    private static function format_row($row, bool $include_private_context): array
    {
        $context = self::decode_context($row);
        $source_ids = isset($context['source_ids']) && is_array($context['source_ids']) ? $context['source_ids'] : [];
        if (! $include_private_context) {
            unset($context['source_ids']);
        }
        $context['source_id_count'] = count($source_ids);

        return [
            'job_id' => (int) ($row->id ?? 0),
            'job_type' => (string) ($row->job_type ?? ''),
            'status' => (string) ($row->status ?? ''),
            'progress' => (float) ($row->progress ?? 0),
            'created_at' => (string) ($row->created_at ?? ''),
            'updated_at' => (string) ($row->updated_at ?? ''),
            'context' => $context,
        ];
    }

    /**
     * @param object $row
     * @return array<string,mixed>
     */
    private static function decode_context($row): array
    {
        $raw = isset($row->context) ? (string) $row->context : '';
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param string $status
     * @param string $fallback
     * @return string
     */
    private static function sanitize_status(string $status, string $fallback): string
    {
        $status = sanitize_key($status);
        return in_array($status, self::allowed_statuses(), true) ? $status : $fallback;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function sanitize_context(array $context): array
    {
        $policy = isset($context['policy']) && is_array($context['policy']) ? $context['policy'] : [];
        $match_policy = sanitize_key((string) ($policy['match_policy'] ?? Settings::get_match_policy()));
        if (! in_array($match_policy, Settings::allowed_match_policies(), true)) {
            $match_policy = Settings::get_match_policy();
        }
        $receipt_ids = [];
        foreach ((array) ($context['receipt_ids'] ?? []) as $receipt_id) {
            $receipt_id = sanitize_file_name((string) $receipt_id);
            if ($receipt_id !== '') {
                $receipt_ids[] = $receipt_id;
            }
        }

        return [
            'manifest_path' => isset($context['manifest_path']) ? wp_normalize_path((string) $context['manifest_path']) : '',
            'manifest_checksum' => sanitize_text_field((string) ($context['manifest_checksum'] ?? '')),
            'package_root' => isset($context['package_root']) ? wp_normalize_path((string) $context['package_root']) : '',
            'plan_id' => sanitize_file_name((string) ($context['plan_id'] ?? '')),
            'plan_checksum' => sanitize_text_field((string) ($context['plan_checksum'] ?? '')),
            'retry_receipt_id' => sanitize_file_name((string) ($context['retry_receipt_id'] ?? '')),
            'source_ids' => self::normalize_source_ids($context['source_ids'] ?? []),
            'offset' => absint($context['offset'] ?? 0),
            'limit' => max(1, min(500, absint($context['limit'] ?? Settings::get_batch_size()))),
            'total_plan_items' => absint($context['total_plan_items'] ?? 0),
            'processed_total' => absint($context['processed_total'] ?? 0),
            'cumulative_summary' => self::sanitize_summary((array) ($context['cumulative_summary'] ?? [])),
            'policy' => [
                'clone_confirmation' => ! empty($policy['clone_confirmation']),
                'strict_hashes' => ! empty($policy['strict_hashes']),
                'match_policy' => $match_policy,
                'repair_metadata' => ! empty($policy['repair_metadata']),
                'normalize_media_urls_to_https' => ! empty($policy['normalize_media_urls_to_https']),
                'overwrite_existing' => false,
            ],
            'created_by' => absint($context['created_by'] ?? 0),
            'last_error' => sanitize_text_field((string) ($context['last_error'] ?? '')),
            'receipt_ids' => array_values(array_unique($receipt_ids)),
            'progress' => max(0, min(100, (float) ($context['progress'] ?? 0))),
        ];
    }

    /**
     * @param mixed $value
     * @return int[]
     */
    private static function normalize_source_ids($value): array
    {
        if (! is_array($value)) {
            $value = is_string($value) && $value !== '' ? explode(',', $value) : [];
        }

        $ids = [];
        foreach ($value as $id) {
            $id = absint($id);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<string,int>
     */
    private static function sanitize_summary(array $summary): array
    {
        $keys = ['items', 'hydrated', 'metadata_repaired', 'skipped', 'blocked', 'errors', 'bytes'];
        $clean = [];
        foreach ($keys as $key) {
            $clean[$key] = absint($summary[$key] ?? 0);
        }

        return $clean;
    }
}
