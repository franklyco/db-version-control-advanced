<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Persists dry-run media hydration plans for later apply acknowledgement.
 */
final class HydrationPlanStore
{
    private const DEFAULT_TTL_SECONDS = 86400;

    /**
     * Store a dry-run hydration plan.
     *
     * @param array $plan
     * @param array $args {
     *   @type string $plan_id     Optional plan ID.
     *   @type int    $ttl_seconds Optional TTL. Default 24 hours.
     * }
     * @return array<string,string>|\WP_Error
     */
    public static function write(array $plan, array $args = [])
    {
        if ((string) ($plan['kind'] ?? '') !== 'dbvc_media_hydration_plan') {
            return new \WP_Error('dbvc_media_hydration_plan_store_bad_kind', __('Expected a media hydration plan.', 'dbvc'));
        }

        $dir = self::base_dir();
        if (is_wp_error($dir)) {
            return $dir;
        }

        $now = time();
        $ttl = isset($args['ttl_seconds']) ? absint($args['ttl_seconds']) : self::DEFAULT_TTL_SECONDS;
        if ($ttl <= 0) {
            $ttl = self::DEFAULT_TTL_SECONDS;
        }

        $plan_id = self::sanitize_plan_id((string) ($args['plan_id'] ?? ''));
        $path = trailingslashit($dir) . $plan_id . '.json';
        if (file_exists($path)) {
            return new \WP_Error('dbvc_media_hydration_plan_exists', __('A media hydration plan with this ID already exists.', 'dbvc'));
        }

        $stored = $plan;
        $stored['saved_plan'] = [
            'id' => $plan_id,
            'created_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + $ttl),
            'expires_at_unix' => $now + $ttl,
            'manifest_checksum' => (string) ($plan['source_manifest']['checksum'] ?? ''),
        ];

        $encoded = wp_json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (! is_string($encoded) || $encoded === '') {
            return new \WP_Error('dbvc_media_hydration_plan_encode_failed', __('Unable to encode media hydration plan.', 'dbvc'));
        }

        if (file_put_contents($path, $encoded) === false) {
            return new \WP_Error('dbvc_media_hydration_plan_write_failed', __('Unable to write media hydration plan.', 'dbvc'));
        }

        return [
            'plan_id' => $plan_id,
            'plan_path' => wp_normalize_path($path),
            'expires_at' => gmdate('c', $now + $ttl),
        ];
    }

    /**
     * Load a stored dry-run plan.
     *
     * @param string $plan_id
     * @return array<string,mixed>|\WP_Error
     */
    public static function load(string $plan_id)
    {
        $plan_id = self::sanitize_plan_id($plan_id, false);
        if ($plan_id === '') {
            return new \WP_Error('dbvc_media_hydration_plan_bad_id', __('Invalid media hydration plan ID.', 'dbvc'), ['status' => 400]);
        }

        $dir = self::base_dir();
        if (is_wp_error($dir)) {
            return $dir;
        }

        $path = trailingslashit($dir) . $plan_id . '.json';
        if (! is_file($path) || ! is_readable($path)) {
            return new \WP_Error('dbvc_media_hydration_plan_missing', __('Media hydration dry-run plan was not found.', 'dbvc'), ['status' => 404]);
        }

        $raw = file_get_contents($path);
        if (! is_string($raw) || trim($raw) === '') {
            return new \WP_Error('dbvc_media_hydration_plan_empty', __('Media hydration dry-run plan is empty.', 'dbvc'), ['status' => 400]);
        }

        $plan = json_decode($raw, true);
        if (! is_array($plan)) {
            return new \WP_Error('dbvc_media_hydration_plan_invalid_json', __('Media hydration dry-run plan is not valid JSON.', 'dbvc'), ['status' => 400]);
        }

        if ((string) ($plan['kind'] ?? '') !== 'dbvc_media_hydration_plan') {
            return new \WP_Error('dbvc_media_hydration_plan_bad_kind', __('Stored media hydration plan has an unexpected type.', 'dbvc'), ['status' => 400]);
        }

        $expires_at = isset($plan['saved_plan']['expires_at_unix']) ? (int) $plan['saved_plan']['expires_at_unix'] : 0;
        if ($expires_at > 0 && $expires_at < time()) {
            return new \WP_Error('dbvc_media_hydration_plan_expired', __('Media hydration dry-run plan has expired.', 'dbvc'), ['status' => 410]);
        }

        $plan['saved_plan']['path'] = wp_normalize_path($path);
        return $plan;
    }

    /**
     * Verify that a stored dry-run plan belongs to a manifest.
     *
     * @param string $plan_id
     * @param array  $manifest
     * @return array<string,mixed>|\WP_Error
     */
    public static function verify_for_manifest(string $plan_id, array $manifest)
    {
        $plan = self::load($plan_id);
        if (is_wp_error($plan)) {
            return $plan;
        }

        $plan_checksum = (string) ($plan['saved_plan']['manifest_checksum'] ?? ($plan['source_manifest']['checksum'] ?? ''));
        $manifest_checksum = (string) ($manifest['checksum'] ?? '');
        if ($plan_checksum !== '' && $manifest_checksum !== '' && ! hash_equals($plan_checksum, $manifest_checksum)) {
            return new \WP_Error('dbvc_media_hydration_plan_manifest_mismatch', __('Media hydration dry-run plan does not match the requested manifest.', 'dbvc'), ['status' => 400]);
        }

        return $plan;
    }

    /**
     * @return string|\WP_Error
     */
    public static function base_dir()
    {
        $root = function_exists('dbvc_get_sync_path')
            ? trailingslashit(dbvc_get_sync_path('media-hydration'))
            : trailingslashit(WP_CONTENT_DIR) . 'uploads/dbvc-media-hydration/';

        if (! is_dir($root) && ! wp_mkdir_p($root)) {
            return new \WP_Error('dbvc_media_hydration_plan_root_failed', __('Unable to create media hydration plan root.', 'dbvc'));
        }
        self::ensure_directory_security($root);

        $plans = trailingslashit($root) . 'plans';
        if (! is_dir($plans) && ! wp_mkdir_p($plans)) {
            return new \WP_Error('dbvc_media_hydration_plan_root_failed', __('Unable to create media hydration plan root.', 'dbvc'));
        }
        self::ensure_directory_security($plans);

        return wp_normalize_path($plans);
    }

    /**
     * @param string $plan_id
     * @param bool   $generate
     * @return string
     */
    private static function sanitize_plan_id(string $plan_id, bool $generate = true): string
    {
        $plan_id = sanitize_file_name($plan_id);
        if ($plan_id === '' && $generate) {
            $plan_id = 'plan-' . gmdate('Ymd-His') . '-' . wp_generate_password(8, false, false);
        }

        return $plan_id;
    }

    /**
     * @param string $path
     * @return void
     */
    private static function ensure_directory_security(string $path): void
    {
        if (class_exists('\DBVC_Sync_Posts') && method_exists('\DBVC_Sync_Posts', 'ensure_directory_security')) {
            \DBVC_Sync_Posts::ensure_directory_security($path);
            return;
        }

        $index = trailingslashit($path) . 'index.php';
        if (! file_exists($index)) {
            file_put_contents($index, "<?php\n// Silence is golden.\n");
        }
    }
}
