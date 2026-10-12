<?php

namespace Dbvc\Connected\Lifecycle;

/**
 * Operational connector/hub state is never content or configuration.
 *
 * Attached to DBVC's generic option export (`dbvc_excluded_option_keys`) and
 * the options.json import guard (`dbvc_import_options_data`). Custom tables
 * (`dbvc_ce_*`) are outside the option exporters already; this predicate
 * covers the gate options and schema-version markers. Full-database cloning
 * tools copy everything regardless; clone safety is an enrollment concern.
 */
final class TransferExclusions
{
    /**
     * @var array<int, string>
     */
    private const EXACT_KEYS = [
        'dbvc_addon_connected_environments_enabled',
        'dbvc_addon_connected_environments_apply_enabled',
        'dbvc_addon_agency_control_enabled',
        'dbvc_connected_schema_version',
        'dbvc_agency_control_schema_version',
    ];

    /**
     * @var array<int, string>
     */
    private const PREFIXES = ['dbvc_ce_', 'dbvc_ac_', 'dbvc_connected_', 'dbvc_agency_control_'];

    /**
     * @param string $key
     * @return bool
     */
    public static function is_operational_option($key)
    {
        $key = (string) $key;
        if (in_array($key, self::EXACT_KEYS, true)) {
            return true;
        }
        foreach (self::PREFIXES as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public static function exact_keys()
    {
        return self::EXACT_KEYS;
    }

    /**
     * Filter callback for `dbvc_excluded_option_keys`.
     *
     * @param mixed $excluded
     * @return array<int, string>
     */
    public static function filter_excluded_option_keys($excluded)
    {
        $excluded = is_array($excluded) ? $excluded : [];
        foreach (self::EXACT_KEYS as $key) {
            if (! in_array($key, $excluded, true)) {
                $excluded[] = $key;
            }
        }
        foreach (array_keys(wp_load_alloptions()) as $key) {
            if (self::is_operational_option($key) && ! in_array($key, $excluded, true)) {
                $excluded[] = $key;
            }
        }

        return $excluded;
    }

    /**
     * Filter callback for `dbvc_import_options_data`.
     *
     * @param mixed $options
     * @return array<string, mixed>
     */
    public static function filter_import_options_data($options)
    {
        if (! is_array($options)) {
            return [];
        }
        foreach (array_keys($options) as $key) {
            if (self::is_operational_option((string) $key)) {
                unset($options[$key]);
            }
        }

        return $options;
    }
}
