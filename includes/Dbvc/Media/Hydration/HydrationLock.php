<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Prevents concurrent media hydration apply runs.
 */
final class HydrationLock
{
    private const OPTION_KEY = 'dbvc_media_hydration_apply_lock';
    private const DEFAULT_TTL = 1800;
    private const MIN_TTL = 60;
    private const MAX_TTL = 86400;

    /**
     * Acquire the global media hydration apply lock.
     *
     * @param array $args {
     *   @type int    $ttl_seconds Lock TTL. Default 1800.
     *   @type string $owner       Optional owner label.
     * }
     * @return array<string,mixed>|\WP_Error
     */
    public static function acquire(array $args = [])
    {
        $now = time();
        $ttl = isset($args['ttl_seconds']) ? absint($args['ttl_seconds']) : self::DEFAULT_TTL;
        $ttl = max(self::MIN_TTL, min($ttl, self::MAX_TTL));

        $existing = get_option(self::OPTION_KEY);
        if (is_array($existing)) {
            $expires_at = isset($existing['expires_at_unix']) ? (int) $existing['expires_at_unix'] : 0;
            if ($expires_at > $now) {
                return new \WP_Error('dbvc_media_hydration_apply_locked', __('A media hydration apply run is already active.', 'dbvc'), $existing);
            }

            delete_option(self::OPTION_KEY);
        }

        $lock = [
            'token' => wp_generate_password(32, false, false),
            'owner' => sanitize_text_field((string) ($args['owner'] ?? '')),
            'acquired_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + $ttl),
            'expires_at_unix' => $now + $ttl,
        ];

        if (! add_option(self::OPTION_KEY, $lock, '', 'no')) {
            $existing = get_option(self::OPTION_KEY);
            return new \WP_Error('dbvc_media_hydration_apply_locked', __('A media hydration apply run is already active.', 'dbvc'), is_array($existing) ? $existing : []);
        }

        return $lock;
    }

    /**
     * Release the global media hydration apply lock.
     *
     * @param string $token
     * @return bool
     */
    public static function release(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        $existing = get_option(self::OPTION_KEY);
        if (! is_array($existing) || (string) ($existing['token'] ?? '') !== $token) {
            return false;
        }

        return delete_option(self::OPTION_KEY);
    }

    /**
     * Clear the lock when the token matches or the existing lock is stale.
     *
     * @param string $token
     * @return bool
     */
    public static function force_release_if_stale_or_matching(string $token = ''): bool
    {
        $existing = get_option(self::OPTION_KEY);
        if (! is_array($existing)) {
            return true;
        }

        $matches = $token !== '' && (string) ($existing['token'] ?? '') === trim($token);
        $stale = isset($existing['expires_at_unix']) && (int) $existing['expires_at_unix'] <= time();
        if ($matches || $stale) {
            return delete_option(self::OPTION_KEY);
        }

        return false;
    }
}
