<?php

namespace Dbvc\AgencyControl\Notifications;

use Dbvc\AgencyControl\Framework\FrameworkStatusService;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;

/**
 * Scheduled drift notifier (M9 slice 7d-2). Unlike a rollout finishing or an
 * override being flagged, a site drifting from the framework has NO event — it
 * is computed on demand by {@see FrameworkStatusService}. This scanner runs on
 * the hub's hourly tick, finds each framework-owned object a client has changed
 * without approval (`drift === 'local_drift'`), and enqueues a `site.drifted`
 * notification, deduped per (environment, definition, domain, instance, state)
 * so a persistent drift is announced once rather than every tick.
 */
final class DriftNotifier
{
    /**
     * @var NotificationService
     */
    private $notifications;

    /**
     * @var callable():array<int, array<string, mixed>>
     */
    private $rows_provider;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    /**
     * @param NotificationService|null       $notifications
     * @param callable|null                  $rows_provider Returns the framework-status rows; defaults to FrameworkStatusService.
     * @param EnvironmentRegistry|null        $environments
     */
    public function __construct($notifications = null, $rows_provider = null, $environments = null)
    {
        $this->notifications = $notifications instanceof NotificationService ? $notifications : new NotificationService();
        $this->environments = $environments instanceof EnvironmentRegistry ? $environments : new EnvironmentRegistry();
        $this->rows_provider = is_callable($rows_provider) ? $rows_provider : static function () {
            $report = (new FrameworkStatusService())->status();

            return isset($report['rows']) && is_array($report['rows']) ? $report['rows'] : [];
        };
    }

    /**
     * Scan framework status and enqueue a notification for each drifted object.
     *
     * @param int $limit Max drifted rows to process this pass.
     * @return array{drifted: int, queued: int}
     */
    public function scan($limit = 200)
    {
        $rows = (array) call_user_func($this->rows_provider);
        $agency = [];
        $drifted = 0;
        $queued = 0;
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['drift'] ?? '') !== 'local_drift') {
                continue;
            }
            $drifted++;
            if ($drifted > (int) $limit) {
                break;
            }
            $env_id = (string) ($row['environment_id'] ?? '');
            if (! array_key_exists($env_id, $agency)) {
                $env = $this->environments->find($env_id);
                $agency[$env_id] = is_array($env) ? (string) $env['agency_id'] : '';
            }
            $queued += $this->notifications->enqueue(
                $agency[$env_id],
                'site.drifted',
                implode('|', [$env_id, (string) ($row['definition_uid'] ?? ''), (string) ($row['domain'] ?? ''), (string) ($row['instance_uid'] ?? ''), 'local_drift']),
                [
                    'environment_id' => $env_id,
                    'client_id' => (string) ($row['client_id'] ?? ''),
                    'definition_uid' => (string) ($row['definition_uid'] ?? ''),
                    'domain' => (string) ($row['domain'] ?? ''),
                    'instance_uid' => (string) ($row['instance_uid'] ?? ''),
                    'drift' => 'local_drift',
                ]
            );
        }

        return ['drifted' => $drifted, 'queued' => $queued];
    }
}
