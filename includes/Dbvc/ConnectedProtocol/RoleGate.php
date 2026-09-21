<?php

namespace Dbvc\ConnectedProtocol;

/**
 * Pure module gate decision. Host integration supplies the trusted inputs
 * (option value, kill-switch constant, schema readiness, runtime support).
 */
final class RoleGate
{
    public const EMERGENCY_DISABLED = 'emergency_disabled';
    public const DISABLED = 'disabled';
    public const UNSUPPORTED_RUNTIME = 'unsupported_runtime';
    public const MIGRATION_REQUIRED = 'migration_required';
    public const READY = 'ready';

    /**
     * @param bool $enabled
     * @param bool $killed
     * @param bool $schema_ready
     * @param bool $supported_runtime
     * @return string One of the class constants.
     */
    public static function state($enabled, $killed, $schema_ready, $supported_runtime)
    {
        if ($killed) {
            return self::EMERGENCY_DISABLED;
        }
        if (! $enabled) {
            return self::DISABLED;
        }
        if (! $supported_runtime) {
            return self::UNSUPPORTED_RUNTIME;
        }
        if (! $schema_ready) {
            return self::MIGRATION_REQUIRED;
        }

        return self::READY;
    }
}
