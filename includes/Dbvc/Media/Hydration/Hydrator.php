<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Applies media hydration plans against existing WordPress attachment rows.
 */
final class Hydrator
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 500;

    /**
     * Apply a media mirror manifest from disk.
     *
     * @param string $manifest_path
     * @param array  $args
     * @return array<string,mixed>|\WP_Error
     */
    public static function apply_from_manifest_file(string $manifest_path, array $args = [])
    {
        $manifest_path = wp_normalize_path($manifest_path);
        if ($manifest_path === '' || ! is_file($manifest_path) || ! is_readable($manifest_path)) {
            return new \WP_Error('dbvc_media_hydration_manifest_unreadable', __('Media mirror manifest is missing or unreadable.', 'dbvc'));
        }

        $raw = file_get_contents($manifest_path);
        if (! is_string($raw) || trim($raw) === '') {
            return new \WP_Error('dbvc_media_hydration_manifest_empty', __('Media mirror manifest is empty.', 'dbvc'));
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest)) {
            return new \WP_Error('dbvc_media_hydration_manifest_invalid_json', __('Media mirror manifest is not valid JSON.', 'dbvc'));
        }

        $package_root = isset($args['package_root']) && (string) $args['package_root'] !== ''
            ? (string) $args['package_root']
            : dirname($manifest_path);

        return self::apply($manifest, $package_root, $args);
    }

    /**
     * Apply a media mirror manifest using files from a local package root.
     *
     * @param array  $manifest
     * @param string $package_root Directory containing media/.
     * @param array  $args {
     *   @type bool $clone_confirmation Require source and target attachment IDs to match. Default true.
     *   @type bool $strict_hashes      Compare target hashes during planning. Default true.
     *   @type bool $require_hashes     Require package files to match manifest SHA-256 hashes. Default true.
     *   @type bool $overwrite_existing Replace existing target files. Default false.
     *   @type bool $repair_metadata    Regenerate attachment metadata when planned. Default true.
     *   @type bool $normalize_media_urls_to_https Rewrite exact http media URL references to https. Default false.
     *   @type int  $limit              Max planned items to apply in this run. Default 100, max 500.
     *   @type int  $offset             Planned item offset. Default 0.
     * }
     * @return array<string,mixed>|\WP_Error
     */
    public static function apply(array $manifest, string $package_root, array $args = [])
    {
        $plan = HydrationPlanner::plan($manifest, [
            'clone_confirmation' => array_key_exists('clone_confirmation', $args) ? (bool) $args['clone_confirmation'] : true,
            'strict_hashes' => array_key_exists('strict_hashes', $args) ? (bool) $args['strict_hashes'] : true,
            'match_policy' => isset($args['match_policy']) ? (string) $args['match_policy'] : 'same_id_then_uid',
        ]);

        if (is_wp_error($plan)) {
            return $plan;
        }

        $package = self::normalize_package_root($package_root);
        if (is_wp_error($package)) {
            return $package;
        }

        $entries = array_values((array) ($manifest['attachments'] ?? []));
        $items = array_values((array) ($plan['items'] ?? []));
        $offset = isset($args['offset']) ? absint($args['offset']) : 0;
        $limit = isset($args['limit']) ? absint($args['limit']) : self::DEFAULT_LIMIT;
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        $limit = min($limit, self::MAX_LIMIT);

        $total_items = count($items);
        $selected = array_slice($items, $offset, $limit, true);
        $selected_count = count($selected);
        $processed_total = min($total_items, $offset + $selected_count);
        $remaining = max(0, $total_items - $processed_total);
        $has_more = $processed_total < $total_items;
        $progress_percent = $total_items > 0 ? round(($processed_total / $total_items) * 100, 2) : 100.0;
        $summary = self::empty_apply_summary();
        $report_items = [];

        foreach ($selected as $index => $item) {
            $entry = isset($entries[$index]) && is_array($entries[$index]) ? $entries[$index] : [];
            $result = self::apply_item($item, $entry, $package, $args);
            $report_items[] = $result;
            self::accumulate_apply_summary($summary, $result);
        }

        return [
            'kind' => 'dbvc_media_hydration_apply_report',
            'schema' => 1,
            'generated_at' => gmdate('c'),
            'package_root' => $package['root'],
            'policy' => [
                'clone_confirmation' => array_key_exists('clone_confirmation', $args) ? (bool) $args['clone_confirmation'] : true,
                'strict_hashes' => array_key_exists('strict_hashes', $args) ? (bool) $args['strict_hashes'] : true,
                'require_hashes' => array_key_exists('require_hashes', $args) ? (bool) $args['require_hashes'] : true,
                'overwrite_existing' => ! empty($args['overwrite_existing']),
                'repair_metadata' => array_key_exists('repair_metadata', $args) ? (bool) $args['repair_metadata'] : true,
                'normalize_media_urls_to_https' => ! empty($args['normalize_media_urls_to_https']),
            ],
            'pagination' => [
                'offset' => $offset,
                'limit' => $limit,
                'selected' => $selected_count,
                'processed_this_batch' => $selected_count,
                'processed_total' => $processed_total,
                'next_offset' => $processed_total,
                'remaining' => $remaining,
                'total_plan_items' => $total_items,
                'progress_percent' => $progress_percent,
                'has_more' => $has_more,
            ],
            'progress' => [
                'offset' => $offset,
                'limit' => $limit,
                'processed_this_batch' => $selected_count,
                'processed_total' => $processed_total,
                'next_offset' => $processed_total,
                'remaining' => $remaining,
                'total_plan_items' => $total_items,
                'percent' => $progress_percent,
                'has_more' => $has_more,
            ],
            'plan_summary' => isset($plan['summary']) && is_array($plan['summary']) ? $plan['summary'] : [],
            'summary' => $summary,
            'items' => $report_items,
        ];
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $entry
     * @param array<string,string> $package
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function apply_item(array $item, array $entry, array $package, array $args): array
    {
        $result = self::apply_item_without_url_normalization($item, $entry, $package, $args);
        return self::maybe_normalize_https_urls($result, $entry, $args);
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $entry
     * @param array<string,string> $package
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function apply_item_without_url_normalization(array $item, array $entry, array $package, array $args): array
    {
        $action = (string) ($item['planned_action'] ?? 'block');
        $target_id = isset($item['target_id']) ? absint($item['target_id']) : 0;
        $result = [
            'source_id' => isset($item['source_id']) ? (int) $item['source_id'] : 0,
            'target_id' => $target_id,
            'planned_status' => (string) ($item['status'] ?? 'blocked'),
            'planned_action' => $action,
            'result' => 'skipped',
            'reason' => '',
            'relative_path' => (string) ($item['relative_path'] ?? ''),
        ];

        if ($action === 'none') {
            $result['result'] = 'skipped';
            $result['reason'] = 'already_ok';
            return $result;
        }

        if ($target_id <= 0 || get_post_type($target_id) !== 'attachment') {
            $result['result'] = 'blocked';
            $result['reason'] = 'target_attachment_missing';
            return $result;
        }

        if ($action === 'hydrate_existing_attachment') {
            return self::hydrate_file($result, $item, $entry, $package, $args);
        }

        if ($action === 'repair_metadata') {
            if (array_key_exists('repair_metadata', $args) && ! (bool) $args['repair_metadata']) {
                $result['result'] = 'skipped';
                $result['reason'] = 'metadata_repair_disabled';
                return $result;
            }

            return self::repair_metadata($result, $target_id);
        }

        $result['result'] = 'blocked';
        $result['reason'] = (string) ($item['reason'] ?? 'planned_action_blocked');
        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $entry
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function maybe_normalize_https_urls(array $result, array $entry, array $args): array
    {
        if (empty($args['normalize_media_urls_to_https']) || ! class_exists(__NAMESPACE__ . '\MediaUrlHttpsNormalizer')) {
            return $result;
        }

        $target_id = isset($result['target_id']) ? absint($result['target_id']) : 0;
        if ($target_id <= 0 || get_post_type($target_id) !== 'attachment') {
            return $result;
        }

        $result_state = (string) ($result['result'] ?? '');
        if (! in_array($result_state, ['hydrated', 'metadata_repaired', 'skipped'], true)) {
            return $result;
        }

        $normalization = MediaUrlHttpsNormalizer::normalize_attachment($target_id, $entry);
        $result['https_url_normalization'] = $normalization;

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @param array<string,mixed> $item
     * @param array<string,mixed> $entry
     * @param array<string,string> $package
     * @param array<string,mixed> $args
     * @return array<string,mixed>
     */
    private static function hydrate_file(array $result, array $item, array $entry, array $package, array $args): array
    {
        $target_id = (int) $result['target_id'];
        $source_relative = FileStateService::normalize_relative_path((string) ($entry['relative_path'] ?? ($item['relative_path'] ?? '')));
        $target_state = FileStateService::inspect($target_id);
        $target_relative = FileStateService::normalize_relative_path((string) ($target_state['relative_path'] ?? ''));
        $target_relative = $target_relative !== '' ? $target_relative : $source_relative;

        if ($source_relative === '' || $target_relative === '') {
            $result['result'] = 'blocked';
            $result['reason'] = 'missing_relative_path';
            return $result;
        }

        if ($source_relative !== $target_relative && (string) ($target_state['relative_path'] ?? '') !== '') {
            $result['result'] = 'blocked';
            $result['reason'] = 'target_relative_path_differs';
            return $result;
        }

        $source = self::resolve_package_file($package, $source_relative);
        if (is_wp_error($source)) {
            $result['result'] = 'error';
            $result['reason'] = $source->get_error_code();
            return $result;
        }

        $expected_hash = self::normalize_hash((string) ($item['source_hash'] ?? ($entry['file_hash'] ?? '')));
        if ($expected_hash === '' && (array_key_exists('require_hashes', $args) ? (bool) $args['require_hashes'] : true)) {
            $result['result'] = 'blocked';
            $result['reason'] = 'source_hash_required';
            return $result;
        }

        if ($expected_hash !== '') {
            $source_hash = self::hash_file($source);
            if ($source_hash === '' || ! hash_equals(self::strip_hash_prefix($expected_hash), self::strip_hash_prefix($source_hash))) {
                $result['result'] = 'error';
                $result['reason'] = 'source_package_hash_mismatch';
                return $result;
            }
        }

        $target = self::resolve_upload_file($target_relative);
        if (is_wp_error($target)) {
            $result['result'] = 'blocked';
            $result['reason'] = $target->get_error_code();
            return $result;
        }

        if (file_exists($target) && empty($args['overwrite_existing'])) {
            $result['result'] = 'blocked';
            $result['reason'] = 'target_file_exists';
            return $result;
        }

        $copied = self::copy_verified($source, $target, $expected_hash);
        if (is_wp_error($copied)) {
            $result['result'] = 'error';
            $result['reason'] = $copied->get_error_code();
            return $result;
        }

        update_attached_file($target_id, $target);
        self::sync_identity_meta($target_id, $entry, $expected_hash);

        $result['result'] = 'hydrated';
        $result['reason'] = '';
        $result['relative_path'] = $target_relative;
        $result['bytes'] = (int) filesize($target);

        if (array_key_exists('repair_metadata', $args) ? (bool) $args['repair_metadata'] : true) {
            $metadata = self::repair_metadata($result, $target_id);
            if ($metadata['result'] === 'metadata_repaired') {
                $result['metadata_result'] = 'metadata_repaired';
            } elseif ($metadata['result'] === 'error') {
                $result['metadata_result'] = 'metadata_error';
                $result['metadata_reason'] = (string) ($metadata['reason'] ?? '');
            }
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $result
     * @param int                 $target_id
     * @return array<string,mixed>
     */
    private static function repair_metadata(array $result, int $target_id): array
    {
        $state = FileStateService::inspect($target_id);
        if ((string) ($state['status'] ?? '') !== 'exists') {
            $result['result'] = 'blocked';
            $result['reason'] = 'target_file_not_available';
            return $result;
        }

        $path = (string) ($state['absolute_path'] ?? '');
        if ($path === '' || ! is_readable($path) || is_link($path)) {
            $result['result'] = 'blocked';
            $result['reason'] = 'target_file_unreadable';
            return $result;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        try {
            $metadata = wp_generate_attachment_metadata($target_id, $path);
        } catch (\Throwable $throwable) {
            $result['result'] = 'error';
            $result['reason'] = 'metadata_generation_failed';
            $result['error_message'] = $throwable->getMessage();
            return $result;
        }

        if (is_wp_error($metadata)) {
            $result['result'] = 'error';
            $result['reason'] = $metadata->get_error_code();
            return $result;
        }

        if (! is_array($metadata) || empty($metadata)) {
            $result['result'] = 'skipped';
            $result['reason'] = 'metadata_not_generated';
            return $result;
        }

        wp_update_attachment_metadata($target_id, $metadata);
        $result['result'] = 'metadata_repaired';
        $result['reason'] = '';
        return $result;
    }

    /**
     * @param string $package_root
     * @return array<string,string>|\WP_Error
     */
    private static function normalize_package_root(string $package_root)
    {
        if (strpos($package_root, chr(0)) !== false) {
            return new \WP_Error('dbvc_media_hydration_bad_package_root', __('Invalid media package path.', 'dbvc'));
        }

        $root = realpath(wp_normalize_path($package_root));
        if (! is_string($root) || $root === '' || ! is_dir($root) || ! is_readable($root)) {
            return new \WP_Error('dbvc_media_hydration_package_unreadable', __('Media package root is missing or unreadable.', 'dbvc'));
        }

        $media_root = realpath(trailingslashit($root) . 'media');
        if (! is_string($media_root) || $media_root === '' || ! is_dir($media_root) || ! is_readable($media_root)) {
            return new \WP_Error('dbvc_media_hydration_media_dir_unreadable', __('Media package does not contain a readable media directory.', 'dbvc'));
        }

        return [
            'root' => wp_normalize_path($root),
            'media_root' => wp_normalize_path($media_root),
        ];
    }

    /**
     * @param array<string,string> $package
     * @param string               $relative
     * @return string|\WP_Error
     */
    private static function resolve_package_file(array $package, string $relative)
    {
        $relative = FileStateService::normalize_relative_path($relative);
        if ($relative === '') {
            return new \WP_Error('dbvc_media_hydration_bad_package_path', __('Invalid package media path.', 'dbvc'));
        }

        $path = trailingslashit($package['media_root']) . $relative;
        $real = realpath($path);
        if (! is_string($real) || $real === '' || ! is_file($real) || ! is_readable($real) || is_link($real)) {
            return new \WP_Error('dbvc_media_hydration_package_file_unreadable', __('Package media file is missing or unreadable.', 'dbvc'));
        }

        $real = wp_normalize_path($real);
        if (! self::path_starts_with($real, $package['media_root'])) {
            return new \WP_Error('dbvc_media_hydration_package_path_escape', __('Package media path escapes the package media directory.', 'dbvc'));
        }

        return $real;
    }

    /**
     * @param string $relative
     * @return string|\WP_Error
     */
    private static function resolve_upload_file(string $relative)
    {
        $relative = FileStateService::normalize_relative_path($relative);
        if ($relative === '') {
            return new \WP_Error('dbvc_media_hydration_bad_upload_path', __('Invalid upload media path.', 'dbvc'));
        }

        $uploads = wp_get_upload_dir();
        $base = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
        if ($base === '') {
            return new \WP_Error('dbvc_media_hydration_uploads_unavailable', __('WordPress uploads directory is unavailable.', 'dbvc'));
        }

        if (! is_dir($base) && ! wp_mkdir_p($base)) {
            return new \WP_Error('dbvc_media_hydration_uploads_unwritable', __('WordPress uploads directory is not writable.', 'dbvc'));
        }

        $target = trailingslashit($base) . $relative;
        if (! self::path_starts_with($target, $base)) {
            return new \WP_Error('dbvc_media_hydration_upload_path_escape', __('Upload media path escapes the uploads directory.', 'dbvc'));
        }

        $dir = dirname($target);
        if (! is_dir($dir) && ! wp_mkdir_p($dir)) {
            return new \WP_Error('dbvc_media_hydration_target_dir_unwritable', __('Unable to create target media directory.', 'dbvc'));
        }

        if (! is_writable($dir)) {
            return new \WP_Error('dbvc_media_hydration_target_dir_unwritable', __('Target media directory is not writable.', 'dbvc'));
        }

        $real_base = realpath($base);
        $real_dir = realpath($dir);
        if (! is_string($real_base) || ! is_string($real_dir) || ! self::path_starts_with($real_dir, $real_base)) {
            return new \WP_Error('dbvc_media_hydration_upload_path_escape', __('Upload media path escapes the uploads directory.', 'dbvc'));
        }

        if (is_link($target)) {
            return new \WP_Error('dbvc_media_hydration_target_symlink', __('Target media file is a symlink.', 'dbvc'));
        }

        return wp_normalize_path($target);
    }

    /**
     * @param string $source
     * @param string $target
     * @param string $expected_hash
     * @return true|\WP_Error
     */
    private static function copy_verified(string $source, string $target, string $expected_hash)
    {
        $dir = dirname($target);
        $temp = trailingslashit($dir) . '.' . basename($target) . '.dbvc-' . wp_generate_password(8, false, false) . '.tmp';

        if (! @copy($source, $temp)) {
            return new \WP_Error('dbvc_media_hydration_copy_failed', __('Unable to copy package media file.', 'dbvc'));
        }

        if ($expected_hash !== '') {
            $copied_hash = self::hash_file($temp);
            if ($copied_hash === '' || ! hash_equals(self::strip_hash_prefix($expected_hash), self::strip_hash_prefix($copied_hash))) {
                @unlink($temp);
                return new \WP_Error('dbvc_media_hydration_copied_hash_mismatch', __('Copied media file hash does not match manifest.', 'dbvc'));
            }
        }

        if (file_exists($target) && ! @unlink($target)) {
            @unlink($temp);
            return new \WP_Error('dbvc_media_hydration_target_replace_failed', __('Unable to replace target media file.', 'dbvc'));
        }

        if (! @rename($temp, $target)) {
            @unlink($temp);
            return new \WP_Error('dbvc_media_hydration_move_failed', __('Unable to move media file into uploads.', 'dbvc'));
        }

        return true;
    }

    /**
     * @param int                 $target_id
     * @param array<string,mixed> $entry
     * @param string              $hash
     * @return void
     */
    private static function sync_identity_meta(int $target_id, array $entry, string $hash): void
    {
        $asset_uid = trim((string) ($entry['asset_uid'] ?? ($entry['dbvc']['vf_asset_uid'] ?? '')));
        $existing_uid = trim((string) get_post_meta($target_id, 'vf_asset_uid', true));
        if ($asset_uid !== '' && ($existing_uid === '' || hash_equals($existing_uid, $asset_uid))) {
            update_post_meta($target_id, 'vf_asset_uid', $asset_uid);
        }

        if ($hash !== '') {
            update_post_meta($target_id, 'vf_file_hash', $hash);
        }

        $source_id = isset($entry['source_id']) ? absint($entry['source_id']) : 0;
        if ($source_id > 0) {
            update_post_meta($target_id, '_dbvc_original_attachment_id', $source_id);
        }
    }

    /**
     * @return array<string,int>
     */
    private static function empty_apply_summary(): array
    {
        return [
            'items' => 0,
            'hydrated' => 0,
            'metadata_repaired' => 0,
            'skipped' => 0,
            'blocked' => 0,
            'errors' => 0,
            'bytes' => 0,
            'https_url_replacements' => 0,
        ];
    }

    /**
     * @param array<string,int> $summary
     * @param array<string,mixed> $item
     * @return void
     */
    private static function accumulate_apply_summary(array &$summary, array $item): void
    {
        $summary['items']++;
        $result = (string) ($item['result'] ?? 'skipped');
        if ($result === 'hydrated') {
            $summary['hydrated']++;
        } elseif ($result === 'metadata_repaired') {
            $summary['metadata_repaired']++;
        } elseif ($result === 'blocked') {
            $summary['blocked']++;
        } elseif ($result === 'error') {
            $summary['errors']++;
        } else {
            $summary['skipped']++;
        }

        if (isset($item['metadata_result']) && $item['metadata_result'] === 'metadata_repaired') {
            $summary['metadata_repaired']++;
        }

        if (isset($item['bytes'])) {
            $summary['bytes'] += (int) $item['bytes'];
        }

        if (isset($item['https_url_normalization']) && is_array($item['https_url_normalization'])) {
            $summary['https_url_replacements'] += (int) ($item['https_url_normalization']['replacements'] ?? 0);
        }
    }

    /**
     * @param string $hash
     * @return string
     */
    private static function normalize_hash(string $hash): string
    {
        $hash = trim($hash);
        if ($hash === '') {
            return '';
        }

        return strpos($hash, ':') === false ? 'sha256:' . $hash : $hash;
    }

    /**
     * @param string $path
     * @return string
     */
    private static function hash_file(string $path): string
    {
        $hash = is_file($path) && is_readable($path) ? hash_file('sha256', $path) : false;
        return is_string($hash) ? 'sha256:' . $hash : '';
    }

    /**
     * @param string $hash
     * @return string
     */
    private static function strip_hash_prefix(string $hash): string
    {
        $hash = trim($hash);
        if (strpos($hash, ':') === false) {
            return $hash;
        }

        [, $value] = explode(':', $hash, 2);
        return trim($value);
    }

    /**
     * @param string $path
     * @param string $base
     * @return bool
     */
    private static function path_starts_with(string $path, string $base): bool
    {
        $path = rtrim(wp_normalize_path($path), '/') . '/';
        $base = rtrim(wp_normalize_path($base), '/') . '/';
        return strpos($path, $base) === 0;
    }
}
