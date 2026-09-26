<?php

namespace Dbvc\AgencyControl\Parity;

use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Release\ReleaseService;

/**
 * The parity engine (M8 slice 3, assisted). For an enabled sync policy it runs
 * the read-only {@see ComparisonService}, selects the `outgoing` objects the
 * policy covers, and — in assisted/auto mode — auto-proposes a release for them
 * through the EXISTING {@see ReleaseService} (which stays `open` until the source
 * connector delivers payloads and seals it; the operator still approves). There
 * is NO new write path here: the driver only decides *what* to release and calls
 * the same release pipeline an operator would. Conflicts hold unless the policy
 * says `source_wins`; new-object creation and deletion propagation are out of
 * scope for this slice (reported as skipped).
 */
final class SyncDriver
{
    /**
     * @var SyncPolicyService
     */
    private $policies;

    public function __construct()
    {
        $this->policies = new SyncPolicyService();
    }

    /**
     * Read-only parity view for a pair: the comparison counts plus what a sync
     * would propose right now (a dry run). Never writes.
     *
     * @param string $source
     * @param string $target
     * @return array<string, mixed>|\WP_Error
     */
    public function parity($source, $target)
    {
        return $this->run((string) $source, (string) $target, true);
    }

    /**
     * Auto-propose a release for the pair's `outgoing` objects (assisted). Creates
     * an `open` release through ReleaseService; sealing happens when the source
     * connector delivers payloads, and approval remains a separate operator step.
     *
     * @param string $source
     * @param string $target
     * @param bool   $dry_run When true, only previews the proposal.
     * @return array<string, mixed>|\WP_Error
     */
    public function sync_now($source, $target, $dry_run = false)
    {
        return $this->run((string) $source, (string) $target, (bool) $dry_run);
    }

    /**
     * @param string $source
     * @param string $target
     * @param bool   $dry_run
     * @return array<string, mixed>|\WP_Error
     */
    private function run($source, $target, $dry_run)
    {
        $policy = $this->policies->get($source, $target);
        if ($policy === null) {
            return new \WP_Error('dbvc_agency_sync_policy_missing', 'No sync policy for that pair; create one first.', ['status' => 404]);
        }
        if (! $policy['enabled']) {
            return new \WP_Error('dbvc_agency_sync_policy_disabled', 'The sync policy for that pair is disabled.', ['status' => 409]);
        }
        if ($policy['mode'] === 'manual') {
            return new \WP_Error('dbvc_agency_sync_mode_manual', 'Manual mode does not auto-propose; set mode=assisted or auto.', ['status' => 409]);
        }

        $comparison = (new ComparisonService())->compare($source, $target);
        if (is_wp_error($comparison)) {
            return $comparison;
        }

        $scope = array_fill_keys($policy['scope_domains'], true);
        $include = array_fill_keys($policy['include_uids'], true);
        $exclude = array_fill_keys($policy['exclude_uids'], true);
        $source_wins = $policy['conflict_policy'] === 'source_wins';

        $eligible = [];
        $skipped = ['held_conflict' => 0, 'deletion' => 0, 'new_object' => 0, 'incomplete' => 0, 'excluded' => 0];
        foreach ((array) ($comparison['rows'] ?? []) as $row) {
            $domain = (string) ($row['domain'] ?? '');
            $uid = (string) ($row['source_instance_uid'] ?? '');
            $state = (string) ($row['state'] ?? '');
            // Scope filter (empty scope = every subscribed domain).
            if ($scope !== [] && ! isset($scope[$domain])) {
                continue;
            }
            if ($state !== 'outgoing' && ! ($state === 'conflict' && $source_wins)) {
                // Count the notable non-proposed cases for the operator.
                if ($state === 'conflict') {
                    $skipped['held_conflict']++;
                }
                continue;
            }
            // A source that is verified-absent is a deletion; that is propagate_deletions (later slice).
            if ($row['source_exists'] !== true) {
                $skipped['deletion']++;
                continue;
            }
            // A new object (never on the target) is create_new (later slice).
            if ($row['target_exists'] === false && $state !== 'conflict') {
                $skipped['new_object']++;
                continue;
            }
            if (empty($row['complete'])) {
                // ReleaseService refuses an incomplete projection; never propose it.
                $skipped['incomplete']++;
                continue;
            }
            if ($uid === '') {
                continue;
            }
            if ($include !== [] && ! isset($include[$uid])) {
                $skipped['excluded']++;
                continue;
            }
            if (isset($exclude[$uid])) {
                $skipped['excluded']++;
                continue;
            }
            $eligible[] = ['domain' => $domain, 'instance_uid' => $uid, 'operation' => 'replace'];
        }

        // Deterministic order, then cap.
        usort($eligible, static function ($a, $b) {
            return [$a['domain'], $a['instance_uid']] <=> [$b['domain'], $b['instance_uid']];
        });
        $capped = 0;
        if (count($eligible) > $policy['max_objects']) {
            $capped = count($eligible) - $policy['max_objects'];
            $eligible = array_slice($eligible, 0, $policy['max_objects']);
        }
        $skipped['capped'] = $capped;

        $result = [
            'source' => $source,
            'target' => $target,
            'mode' => $policy['mode'],
            'dry_run' => $dry_run,
            'counts' => $comparison['counts'] ?? [],
            'proposed' => $eligible,
            'proposed_count' => count($eligible),
            'skipped' => $skipped,
            'release' => null,
            'note' => '',
        ];

        if ($eligible === []) {
            $result['note'] = 'nothing_to_propose';

            return $result;
        }
        if ($dry_run) {
            $result['note'] = 'dry_run';

            return $result;
        }

        $created = (new ReleaseService())->create($source, $eligible, 'parity:' . $source . '->' . $target);
        if (is_wp_error($created)) {
            return $created;
        }
        $result['release'] = $created['release'];
        $result['note'] = 'release_open_awaiting_payloads';

        return $result;
    }
}
