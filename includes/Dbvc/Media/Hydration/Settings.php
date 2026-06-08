<?php

namespace Dbvc\Media\Hydration;

if (! defined('WPINC')) {
    die;
}

/**
 * Central option definitions for media hydration.
 */
final class Settings
{
    public const OPTION_ENABLED = 'dbvc_media_hydration_enabled';
    public const OPTION_SOURCE = 'dbvc_media_hydration_source';
    public const OPTION_MATCH_POLICY = 'dbvc_media_hydration_match_policy';
    public const OPTION_OVERWRITE_POLICY = 'dbvc_media_hydration_overwrite_policy';
    public const OPTION_METADATA_POLICY = 'dbvc_media_hydration_metadata_policy';
    public const OPTION_ALLOWED_MIME_GROUPS = 'dbvc_media_hydration_allowed_mime_groups';
    public const OPTION_BATCH_SIZE = 'dbvc_media_hydration_batch_size';
    public const OPTION_REQUIRE_DRY_RUN = 'dbvc_media_hydration_require_dry_run';
    public const OPTION_RECEIPTS_ENABLED = 'dbvc_media_hydration_receipts_enabled';
    public const OPTION_STRICT_HASHES = 'dbvc_media_hydration_strict_hashes';
    public const OPTION_CLONE_CONFIRMATION = 'dbvc_media_hydration_clone_confirmation';
    public const OPTION_LOCK_TIMEOUT_MINUTES = 'dbvc_media_hydration_lock_timeout_minutes';

    private const DEFAULTS = [
        self::OPTION_ENABLED => '0',
        self::OPTION_SOURCE => 'bundle_only',
        self::OPTION_MATCH_POLICY => 'same_id_then_uid',
        self::OPTION_OVERWRITE_POLICY => 'never',
        self::OPTION_METADATA_POLICY => 'regenerate_missing',
        self::OPTION_ALLOWED_MIME_GROUPS => ['image', 'video', 'audio', 'font', 'document', 'other'],
        self::OPTION_BATCH_SIZE => 50,
        self::OPTION_REQUIRE_DRY_RUN => '1',
        self::OPTION_RECEIPTS_ENABLED => '1',
        self::OPTION_STRICT_HASHES => '1',
        self::OPTION_CLONE_CONFIRMATION => '1',
        self::OPTION_LOCK_TIMEOUT_MINUTES => 30,
    ];

    /**
     * @return array<string,mixed>
     */
    public static function get_all(): array
    {
        return [
            self::OPTION_ENABLED => self::get_bool(self::OPTION_ENABLED),
            self::OPTION_SOURCE => self::get_source(),
            self::OPTION_MATCH_POLICY => self::get_match_policy(),
            self::OPTION_OVERWRITE_POLICY => self::get_overwrite_policy(),
            self::OPTION_METADATA_POLICY => self::get_metadata_policy(),
            self::OPTION_ALLOWED_MIME_GROUPS => self::get_allowed_mime_groups(),
            self::OPTION_BATCH_SIZE => self::get_batch_size(),
            self::OPTION_REQUIRE_DRY_RUN => self::get_bool(self::OPTION_REQUIRE_DRY_RUN),
            self::OPTION_RECEIPTS_ENABLED => self::get_bool(self::OPTION_RECEIPTS_ENABLED),
            self::OPTION_STRICT_HASHES => self::get_bool(self::OPTION_STRICT_HASHES),
            self::OPTION_CLONE_CONFIRMATION => self::get_bool(self::OPTION_CLONE_CONFIRMATION),
            self::OPTION_LOCK_TIMEOUT_MINUTES => self::get_lock_timeout_minutes(),
        ];
    }

    /**
     * Save settings from an admin POST payload.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function save_from_post(array $data): array
    {
        update_option(self::OPTION_ENABLED, ! empty($data[self::OPTION_ENABLED]) ? '1' : '0');
        update_option(self::OPTION_REQUIRE_DRY_RUN, ! empty($data[self::OPTION_REQUIRE_DRY_RUN]) ? '1' : '0');
        update_option(self::OPTION_RECEIPTS_ENABLED, ! empty($data[self::OPTION_RECEIPTS_ENABLED]) ? '1' : '0');
        update_option(self::OPTION_STRICT_HASHES, ! empty($data[self::OPTION_STRICT_HASHES]) ? '1' : '0');
        update_option(self::OPTION_CLONE_CONFIRMATION, ! empty($data[self::OPTION_CLONE_CONFIRMATION]) ? '1' : '0');

        update_option(self::OPTION_SOURCE, self::sanitize_allowed_key($data[self::OPTION_SOURCE] ?? '', self::allowed_sources(), (string) self::DEFAULTS[self::OPTION_SOURCE]));
        update_option(self::OPTION_MATCH_POLICY, self::sanitize_allowed_key($data[self::OPTION_MATCH_POLICY] ?? '', self::allowed_match_policies(), (string) self::DEFAULTS[self::OPTION_MATCH_POLICY]));
        update_option(self::OPTION_OVERWRITE_POLICY, self::sanitize_allowed_key($data[self::OPTION_OVERWRITE_POLICY] ?? '', self::allowed_overwrite_policies(), (string) self::DEFAULTS[self::OPTION_OVERWRITE_POLICY]));
        update_option(self::OPTION_METADATA_POLICY, self::sanitize_allowed_key($data[self::OPTION_METADATA_POLICY] ?? '', self::allowed_metadata_policies(), (string) self::DEFAULTS[self::OPTION_METADATA_POLICY]));
        update_option(self::OPTION_ALLOWED_MIME_GROUPS, self::sanitize_mime_groups($data[self::OPTION_ALLOWED_MIME_GROUPS] ?? []));
        update_option(self::OPTION_BATCH_SIZE, self::sanitize_int($data[self::OPTION_BATCH_SIZE] ?? self::DEFAULTS[self::OPTION_BATCH_SIZE], 1, 500, (int) self::DEFAULTS[self::OPTION_BATCH_SIZE]));
        update_option(self::OPTION_LOCK_TIMEOUT_MINUTES, self::sanitize_int($data[self::OPTION_LOCK_TIMEOUT_MINUTES] ?? self::DEFAULTS[self::OPTION_LOCK_TIMEOUT_MINUTES], 1, 1440, (int) self::DEFAULTS[self::OPTION_LOCK_TIMEOUT_MINUTES]));

        return self::get_all();
    }

    /**
     * @param string $option
     * @return string
     */
    public static function get_bool(string $option): string
    {
        $default = isset(self::DEFAULTS[$option]) ? (string) self::DEFAULTS[$option] : '0';
        return get_option($option, $default) === '1' ? '1' : '0';
    }

    public static function get_source(): string
    {
        return self::sanitize_allowed_key(get_option(self::OPTION_SOURCE, self::DEFAULTS[self::OPTION_SOURCE]), self::allowed_sources(), (string) self::DEFAULTS[self::OPTION_SOURCE]);
    }

    public static function get_match_policy(): string
    {
        return self::sanitize_allowed_key(get_option(self::OPTION_MATCH_POLICY, self::DEFAULTS[self::OPTION_MATCH_POLICY]), self::allowed_match_policies(), (string) self::DEFAULTS[self::OPTION_MATCH_POLICY]);
    }

    public static function get_overwrite_policy(): string
    {
        return self::sanitize_allowed_key(get_option(self::OPTION_OVERWRITE_POLICY, self::DEFAULTS[self::OPTION_OVERWRITE_POLICY]), self::allowed_overwrite_policies(), (string) self::DEFAULTS[self::OPTION_OVERWRITE_POLICY]);
    }

    public static function get_metadata_policy(): string
    {
        return self::sanitize_allowed_key(get_option(self::OPTION_METADATA_POLICY, self::DEFAULTS[self::OPTION_METADATA_POLICY]), self::allowed_metadata_policies(), (string) self::DEFAULTS[self::OPTION_METADATA_POLICY]);
    }

    /**
     * @return string[]
     */
    public static function get_allowed_mime_groups(): array
    {
        return self::sanitize_mime_groups(get_option(self::OPTION_ALLOWED_MIME_GROUPS, self::DEFAULTS[self::OPTION_ALLOWED_MIME_GROUPS]));
    }

    public static function get_batch_size(): int
    {
        return self::sanitize_int(get_option(self::OPTION_BATCH_SIZE, self::DEFAULTS[self::OPTION_BATCH_SIZE]), 1, 500, (int) self::DEFAULTS[self::OPTION_BATCH_SIZE]);
    }

    public static function get_lock_timeout_minutes(): int
    {
        return self::sanitize_int(get_option(self::OPTION_LOCK_TIMEOUT_MINUTES, self::DEFAULTS[self::OPTION_LOCK_TIMEOUT_MINUTES]), 1, 1440, (int) self::DEFAULTS[self::OPTION_LOCK_TIMEOUT_MINUTES]);
    }

    /**
     * @return string[]
     */
    public static function allowed_sources(): array
    {
        return ['bundle_only', 'bundle_first'];
    }

    /**
     * @return string[]
     */
    public static function allowed_match_policies(): array
    {
        return ['same_id_then_uid', 'uid_then_path'];
    }

    /**
     * @return string[]
     */
    public static function allowed_overwrite_policies(): array
    {
        return ['never'];
    }

    /**
     * @return string[]
     */
    public static function allowed_metadata_policies(): array
    {
        return ['skip', 'regenerate_missing'];
    }

    /**
     * @return string[]
     */
    public static function allowed_mime_groups(): array
    {
        return ['image', 'video', 'audio', 'font', 'document', 'other'];
    }

    /**
     * @param mixed    $value
     * @param string[] $allowed
     * @param string   $fallback
     * @return string
     */
    private static function sanitize_allowed_key($value, array $allowed, string $fallback): string
    {
        $value = sanitize_key((string) $value);
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function sanitize_mime_groups($value): array
    {
        if (! is_array($value)) {
            $value = is_string($value) && $value !== '' ? explode(',', $value) : [];
        }

        $groups = [];
        foreach ($value as $group) {
            $group = sanitize_key((string) $group);
            if (in_array($group, self::allowed_mime_groups(), true)) {
                $groups[] = $group;
            }
        }

        $groups = array_values(array_unique($groups));
        return ! empty($groups) ? $groups : self::DEFAULTS[self::OPTION_ALLOWED_MIME_GROUPS];
    }

    /**
     * @param mixed $value
     * @param int   $min
     * @param int   $max
     * @param int   $fallback
     * @return int
     */
    private static function sanitize_int($value, int $min, int $max, int $fallback): int
    {
        $value = absint($value);
        if ($value < $min) {
            return $fallback;
        }

        return min($value, $max);
    }
}
