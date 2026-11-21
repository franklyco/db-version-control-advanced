<?php

namespace Dbvc\AgencyControl\Notifications;

/**
 * Change-report notifier (CR-4a). Turns the three report-mode events into
 * deduped notifications on the 7d-1 backbone, so an operator hears about a
 * new Site Changes report, a source that cannot be read stably, or a side that
 * has gone quiet — without polling the hub.
 *
 *   report.generated        — one per report row (`report_uid`).
 *   report.unstable_source  — one per withdrawn release (`source|target|release_uid`).
 *   report.stale            — one per stale EPISODE: the key carries the silent
 *                             side's last_seen_at (last poll or observation),
 *                             frozen while it stays silent, new when it is back.
 *
 * Best-effort like RolloutService::notify_terminal: a notification failure can
 * never break a report cycle. Nothing here applies or writes to a connector.
 */
final class ChangeReportNotifier
{
    public const KIND_GENERATED = 'report.generated';
    public const KIND_UNSTABLE = 'report.unstable_source';
    public const KIND_STALE = 'report.stale';

    /** @var self|null The instance bound to the hooks by register(). */
    private static $registered = null;

    /**
     * @var NotificationService
     */
    private $notifications;

    /**
     * @param NotificationService|null $notifications
     */
    public function __construct($notifications = null)
    {
        $this->notifications = $notifications instanceof NotificationService ? $notifications : new NotificationService();
    }

    /**
     * Bind the handlers (idempotent).
     *
     * @return void
     */
    public static function register()
    {
        if (self::$registered !== null) {
            return;
        }
        self::$registered = new self();
        add_action('dbvc_agency_change_report_generated', [self::$registered, 'on_generated'], 10, 2);
        add_action('dbvc_agency_report_unstable_source', [self::$registered, 'on_unstable_source'], 10, 3);
        add_action('dbvc_agency_report_stale', [self::$registered, 'on_stale'], 10, 3);
    }

    /**
     * @return void
     */
    public static function unregister()
    {
        if (self::$registered === null) {
            return;
        }
        remove_action('dbvc_agency_change_report_generated', [self::$registered, 'on_generated'], 10);
        remove_action('dbvc_agency_report_unstable_source', [self::$registered, 'on_unstable_source'], 10);
        remove_action('dbvc_agency_report_stale', [self::$registered, 'on_stale'], 10);
        self::$registered = null;
    }

    /**
     * A change report row was built (CR-3 ingest).
     *
     * @param array<string, mixed> $report
     * @param array<string, mixed> $policy
     * @return int Channels queued.
     */
    public function on_generated($report, $policy)
    {
        if (! is_array($report) || (string) ($report['report_uid'] ?? '') === '') {
            return 0;
        }
        $policy = is_array($policy) ? $policy : [];
        $counts = isset($report['counts']) && is_array($report['counts']) ? $report['counts'] : [];

        return $this->send(
            (string) ($report['agency_id'] ?? $policy['agency_id'] ?? ''),
            self::KIND_GENERATED,
            (string) $report['report_uid'],
            [
                'report_uid' => (string) $report['report_uid'],
                'source_environment_id' => (string) ($report['source_environment_id'] ?? $policy['source_environment_id'] ?? ''),
                'target_environment_id' => (string) ($report['target_environment_id'] ?? $policy['target_environment_id'] ?? ''),
                'client_id' => (string) ($report['client_id'] ?? $policy['client_id'] ?? ''),
                'release_uid' => (string) ($report['release_uid'] ?? ''),
                'state' => (string) ($report['state'] ?? ''),
                'item_count' => (int) ($report['item_count'] ?? 0),
                'counts' => array_intersect_key(
                    array_map('intval', $counts),
                    array_flip(['created', 'updated', 'removed', 'conflict', 'local_only', 'converged'])
                ),
                'values_included' => ! empty($report['values_included']),
                'generated_at' => (string) ($report['generated_at'] ?? ''),
            ]
        );
    }

    /**
     * A report pair withdrew REPORT_MISMATCH_LIMIT consecutive cycles (CR-2 D5).
     *
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $release The last withdrawn release.
     * @param int                  $cycles
     * @return int Channels queued.
     */
    public function on_unstable_source($policy, $release, $cycles)
    {
        if (! is_array($policy)) {
            return 0;
        }
        $release = is_array($release) ? $release : [];
        $source = (string) ($policy['source_environment_id'] ?? '');
        $target = (string) ($policy['target_environment_id'] ?? '');
        $release_uid = (string) ($release['release_uid'] ?? '');

        return $this->send(
            (string) ($policy['agency_id'] ?? $release['agency_id'] ?? ''),
            self::KIND_UNSTABLE,
            implode('|', [$source, $target, $release_uid]),
            [
                'source_environment_id' => $source,
                'target_environment_id' => $target,
                'client_id' => (string) ($policy['client_id'] ?? ''),
                'release_uid' => $release_uid,
                'mismatch_cycles' => (int) $cycles,
            ]
        );
    }

    /**
     * One side of a report pair has not reported within the freshness window (D12).
     * Fired every idle tick while it stays silent; deduped per episode here.
     *
     * @param array<string, mixed> $policy
     * @param string               $stale_side 'source' or 'target'.
     * @param array<string, mixed> $comparison
     * @return int Channels queued.
     */
    public function on_stale($policy, $stale_side, $comparison)
    {
        if (! is_array($policy)) {
            return 0;
        }
        $stale_side = $stale_side === 'source' ? 'source' : 'target';
        $comparison = is_array($comparison) ? $comparison : [];
        $side = isset($comparison[$stale_side]) && is_array($comparison[$stale_side]) ? $comparison[$stale_side] : [];
        $source = (string) ($policy['source_environment_id'] ?? '');
        $target = (string) ($policy['target_environment_id'] ?? '');
        $since = (string) (($side['last_seen_at'] ?? '') !== '' ? $side['last_seen_at'] : ($side['last_contact_at'] ?? ''));

        return $this->send(
            (string) ($policy['agency_id'] ?? ''),
            self::KIND_STALE,
            implode('|', [$source, $target, $stale_side, $since]),
            [
                'source_environment_id' => $source,
                'target_environment_id' => $target,
                'client_id' => (string) ($policy['client_id'] ?? ''),
                'stale_side' => $stale_side,
                'environment_id' => $stale_side === 'source' ? $source : $target,
                'last_seen_at' => $since,
            ]
        );
    }

    /**
     * @param string               $agency_id
     * @param string               $kind
     * @param string               $dedupe_key
     * @param array<string, mixed> $payload
     * @return int
     */
    private function send($agency_id, $kind, $dedupe_key, array $payload)
    {
        try {
            return (int) $this->notifications->enqueue($agency_id, $kind, $dedupe_key, $payload);
        } catch (\Throwable $e) {
            unset($e);

            return 0;
        }
    }
}
