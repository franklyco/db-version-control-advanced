<?php

namespace Dbvc\AgencyControl\Governance;

use Dbvc\AgencyControl\Storage\EnvironmentRegistry;

/**
 * Per-environment maintenance-freeze windows (M9 slice 7c). A freeze suppresses
 * new applies to a site during a time window: the hub refuses to prepare or
 * approve a release for a frozen target ({@see EnvironmentRegistry::is_frozen}
 * checked in PreparationService/ApprovalService). It is an ADVISORY governance
 * control — the connector's own apply gate stays authoritative downstream — so a
 * freeze is a hub-side convenience, not the sole safeguard. Stored as two UTC
 * datetime bounds plus a reason on `dbvc_ac_environments`.
 */
final class FreezeService
{
    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    public function __construct(?EnvironmentRegistry $environments = null)
    {
        $this->environments = $environments ?: new EnvironmentRegistry();
    }

    /**
     * Set (or replace) the freeze window for one environment.
     *
     * @param string $environment_id
     * @param string $from   A datetime string parsed as UTC (e.g. 2026-10-06T00:00:00Z).
     * @param string $until  A datetime string parsed as UTC; must be after $from.
     * @param string $reason Optional operator note.
     * @return array<string, mixed>|\WP_Error
     */
    public function set($environment_id, $from, $until, $reason = '')
    {
        $environment = $this->environments->find((string) $environment_id);
        if ($environment === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Environment not found.', ['status' => 404]);
        }
        $from_ts = strtotime((string) $from . ' UTC');
        $until_ts = strtotime((string) $until . ' UTC');
        if ($from_ts === false || $until_ts === false) {
            return new \WP_Error('dbvc_agency_invalid_freeze_window', 'from and until must be valid datetimes.', ['status' => 400]);
        }
        if ($until_ts <= $from_ts) {
            return new \WP_Error('dbvc_agency_invalid_freeze_window', 'The freeze window must end after it begins.', ['status' => 400]);
        }
        $from_mysql = gmdate('Y-m-d H:i:s', $from_ts);
        $until_mysql = gmdate('Y-m-d H:i:s', $until_ts);
        $this->environments->update((string) $environment_id, [
            'freeze_from' => $from_mysql,
            'freeze_until' => $until_mysql,
            'freeze_reason' => substr((string) $reason, 0, 128),
        ]);
        $fresh = $this->environments->find((string) $environment_id);

        return [
            'environment_id' => (string) $environment_id,
            'freeze_from' => $from_mysql,
            'freeze_until' => $until_mysql,
            'freeze_reason' => substr((string) $reason, 0, 128),
            'frozen_now' => is_array($fresh) ? EnvironmentRegistry::is_frozen($fresh) : false,
        ];
    }

    /**
     * Clear any freeze window for one environment.
     *
     * @param string $environment_id
     * @return array<string, mixed>|\WP_Error
     */
    public function clear($environment_id)
    {
        $environment = $this->environments->find((string) $environment_id);
        if ($environment === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Environment not found.', ['status' => 404]);
        }
        $this->environments->update((string) $environment_id, [
            'freeze_from' => null,
            'freeze_until' => null,
            'freeze_reason' => '',
        ]);

        return ['environment_id' => (string) $environment_id, 'cleared' => true];
    }
}
