<?php

namespace Dbvc\AgencyControl\Parity;

use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Release\ReleaseService;
use Dbvc\AgencyControl\Release\RolloutService;
use Dbvc\AgencyControl\Storage\OverrideStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\RolloutStore;

/**
 * The parity engine (M8). For an enabled sync policy it runs the read-only
 * {@see ComparisonService}, selects the objects the policy covers, and drives
 * them to `synchronized` through the EXISTING guarded pipeline:
 *
 * - assisted: auto-propose an `open` release (the operator approves later).
 * - auto: {@see advance()} reconciles the pair one tick at a time — propose an
 *   open release, wait for the source connector to seal it, then roll it out
 *   (prepare → approve → the target's own guarded apply) via {@see RolloutService},
 *   and clear the cycle when the rollout finishes.
 *
 * There is NO new write path: the driver only decides *what* to release and
 * calls the same release/rollout machinery an operator uses. It is
 * apply-gate-authoritative by construction — a target whose apply gate is off
 * never retrieves or executes the approval, so its rollout target simply waits;
 * auto never forces or bypasses prepare/approve/verify. Conflicts hold unless
 * `source_wins`; deletions trash (never hard-delete) only with
 * `propagate_deletions`; new-object creation only with `create_new` (and remains
 * subject to the apply pipeline's creation support).
 *
 * An approved client override always takes precedence: an object the target has
 * locked ({@see OverrideStore} state `approved` or `needs_rebase_review`) is never
 * selected for overwrite — not even under `source_wins` — so a fleet push can
 * never silently clobber approved client work. Unlocking (detach) lets the object
 * rejoin parity and converge on the next push.
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
     * Read-only parity view for a pair: comparison counts + a dry-run proposal.
     *
     * @param string $source
     * @param string $target
     * @return array<string, mixed>|\WP_Error
     */
    public function parity($source, $target)
    {
        $policy = $this->require_policy((string) $source, (string) $target, false);
        if (is_wp_error($policy)) {
            return $policy;
        }
        $comparison = (new ComparisonService())->compare((string) $source, (string) $target);
        if (is_wp_error($comparison)) {
            return $comparison;
        }
        $selection = $this->select_objects($comparison, $policy);

        return [
            'source' => (string) $source,
            'target' => (string) $target,
            'mode' => $policy['mode'],
            'dry_run' => true,
            'counts' => $comparison['counts'] ?? [],
            'proposed' => $selection['proposed'],
            'proposed_count' => count($selection['proposed']),
            'skipped' => $selection['skipped'],
            'active_release_uid' => $policy['active_release_uid'],
            'active_rollout_uid' => $policy['active_rollout_uid'],
            'release' => null,
            'rollout' => null,
            'note' => 'dry_run',
        ];
    }

    /**
     * Propose (assisted) or drive (auto) a sync for the pair.
     *
     * @param string $source
     * @param string $target
     * @param bool   $dry_run When true, only previews.
     * @return array<string, mixed>|\WP_Error
     */
    public function sync_now($source, $target, $dry_run = false)
    {
        if ($dry_run) {
            return $this->parity((string) $source, (string) $target);
        }
        $policy = $this->require_policy((string) $source, (string) $target, true);
        if (is_wp_error($policy)) {
            return $policy;
        }
        if ($policy['mode'] === 'auto') {
            return $this->advance((string) $source, (string) $target);
        }

        // assisted: create the open release only; approval/apply stay with the operator.
        $comparison = (new ComparisonService())->compare((string) $source, (string) $target);
        if (is_wp_error($comparison)) {
            return $comparison;
        }
        $selection = $this->select_objects($comparison, $policy);

        return $this->propose($policy, $comparison, $selection, false);
    }

    /**
     * Auto reconcile: drive the pair one step toward parity. Idempotent and
     * re-entrant — safe to call repeatedly (cron or "Sync now"). Requires an
     * enabled `auto` policy.
     *
     * @param string $source
     * @param string $target
     * @return array<string, mixed>|\WP_Error
     */
    public function advance($source, $target)
    {
        $policy = $this->require_policy((string) $source, (string) $target, true);
        if (is_wp_error($policy)) {
            return $policy;
        }
        if ($policy['mode'] !== 'auto') {
            return new \WP_Error('dbvc_agency_sync_mode_not_auto', 'advance is for auto mode; assisted uses sync-now to propose.', ['status' => 409]);
        }

        // 1) An in-flight rollout: drive it and clear the cycle when it finishes.
        if ($policy['active_rollout_uid'] !== '') {
            $status = (new RolloutService())->advance($policy['active_rollout_uid']);
            if (is_wp_error($status)) {
                // The rollout vanished (e.g. pruned): drop the reference and re-propose next tick.
                $this->policies->store()->set_state((string) $source, (string) $target, '', '', 'rollout_missing');

                return $this->result($source, $target, 'auto', 'rollout_missing', [], null, null);
            }
            $state = (string) $status['rollout']['state'];
            $terminal = in_array($state, [RolloutStore::STATE_COMPLETED, RolloutStore::STATE_FAILED, RolloutStore::STATE_WITHDRAWN], true);
            if ($terminal) {
                $this->policies->store()->set_state((string) $source, (string) $target, '', '', 'rollout_' . $state);
            } else {
                $this->policies->store()->set_state((string) $source, (string) $target, $policy['active_release_uid'], $policy['active_rollout_uid'], 'rollout_' . $state);
            }

            return $this->result($source, $target, 'auto', 'rollout_' . $state, [], null, $status);
        }

        // 2) An in-flight release: roll it out once the source has sealed it.
        if ($policy['active_release_uid'] !== '') {
            $release = (new ReleaseStore())->find($policy['active_release_uid']);
            if ($release === null || $release['state'] === ReleaseStore::STATE_WITHDRAWN) {
                $this->policies->store()->set_state((string) $source, (string) $target, '', '', 'release_gone');
                // fall through to propose again below.
            } elseif ($release['state'] === ReleaseStore::STATE_SEALED) {
                $rollout = (new RolloutService())->create($release['release_uid'], [[(string) $target]], 'parity:' . $source . '->' . $target);
                if (is_wp_error($rollout)) {
                    return $rollout;
                }
                $rollout_uid = (string) $rollout['rollout']['rollout_uid'];
                $this->policies->store()->set_state((string) $source, (string) $target, $policy['active_release_uid'], $rollout_uid, 'rollout_created');
                $status = (new RolloutService())->advance($rollout_uid);

                return $this->result($source, $target, 'auto', 'rollout_started', [], $release, is_wp_error($status) ? null : $status);
            } else {
                // Still open: waiting for the source connector to deliver payloads and seal it.
                $this->policies->store()->set_state((string) $source, (string) $target, $policy['active_release_uid'], '', 'awaiting_payloads');

                return $this->result($source, $target, 'auto', 'awaiting_payloads', [], $release, null);
            }
        }

        // 3) Nothing in flight: propose a fresh release for the pair's covered objects.
        $comparison = (new ComparisonService())->compare((string) $source, (string) $target);
        if (is_wp_error($comparison)) {
            return $comparison;
        }
        $selection = $this->select_objects($comparison, $policy);

        return $this->propose($policy, $comparison, $selection, true);
    }

    /**
     * Create the open release for a selection (or report nothing to do), tracking
     * the release on the policy when driving auto.
     *
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $comparison
     * @param array{proposed: array<int, array<string, mixed>>, skipped: array<string, int>} $selection
     * @param bool                 $track
     * @return array<string, mixed>|\WP_Error
     */
    private function propose(array $policy, array $comparison, array $selection, $track)
    {
        $source = (string) $policy['source_environment_id'];
        $target = (string) $policy['target_environment_id'];
        if ($selection['proposed'] === []) {
            if ($track) {
                $this->policies->store()->set_state($source, $target, '', '', 'in_sync');
            }

            return $this->result($source, $target, $policy['mode'], 'in_sync', $selection, null, null);
        }

        $created = (new ReleaseService())->create($source, $selection['proposed'], 'parity:' . $source . '->' . $target);
        if (is_wp_error($created)) {
            return $created;
        }
        if ($track) {
            $this->policies->store()->set_state($source, $target, (string) $created['release']['release_uid'], '', 'release_open');
        }

        return $this->result($source, $target, $policy['mode'], 'release_open', $selection, $created['release'], null);
    }

    /**
     * Choose the objects to release for a policy from a comparison.
     *
     * @param array<string, mixed> $comparison
     * @param array<string, mixed> $policy
     * @return array{proposed: array<int, array<string, mixed>>, skipped: array<string, int>}
     */
    private function select_objects(array $comparison, array $policy)
    {
        $scope = array_fill_keys($policy['scope_domains'], true);
        $include = array_fill_keys($policy['include_uids'], true);
        $exclude = array_fill_keys($policy['exclude_uids'], true);
        $source_wins = $policy['conflict_policy'] === 'source_wins';
        // Approved client work on the target is protected: an object the target has
        // locked is never proposed for overwrite, even under source_wins.
        $locked = (new OverrideStore())->locked_for_environment((string) $policy['target_environment_id']);

        $proposed = [];
        $skipped = ['held_conflict' => 0, 'deletion' => 0, 'new_object' => 0, 'incomplete' => 0, 'excluded' => 0, 'approved_override' => 0, 'capped' => 0];

        foreach ((array) ($comparison['rows'] ?? []) as $row) {
            $domain = (string) ($row['domain'] ?? '');
            $uid = (string) ($row['source_instance_uid'] ?? '');
            $state = (string) ($row['state'] ?? '');
            if ($scope !== [] && ! isset($scope[$domain])) {
                continue;
            }
            if ($uid === '' || $uid === ComparisonService::ORDER_INSTANCE_UID) {
                // The collection-order projection is coverage metadata, not a releasable object.
                continue;
            }
            $operation = null;
            if ($state === 'outgoing') {
                if (empty($row['complete'])) {
                    $skipped['incomplete']++;
                } elseif ($row['source_exists'] === true) {
                    $operation = 'replace';
                } elseif ($row['source_exists'] === false) {
                    // Source verified absent = a deletion to propagate (trash on the target).
                    if ($policy['propagate_deletions']) {
                        $operation = 'delete';
                    } else {
                        $skipped['deletion']++;
                    }
                }
            } elseif ($state === 'conflict') {
                if ($source_wins && $row['source_exists'] === true && ! empty($row['complete'])) {
                    $operation = 'replace';
                } else {
                    $skipped['held_conflict']++;
                }
            } elseif ($state === 'baseline_required' && $row['source_exists'] === true && $row['target_exists'] === false && ! empty($row['complete'])) {
                // A source object the target has never had = a new object: the guarded
                // creation apply lands it (the connector allocates a local id, inserts
                // into the collection and adopts the framework object's identity).
                if ($policy['create_new']) {
                    $operation = 'create';
                } else {
                    $skipped['new_object']++;
                }
            }
            if ($operation === null) {
                continue;
            }
            // Never overwrite (or trash) an object the target has locked — approved
            // client work always wins, even under source_wins. The lock is keyed on
            // the target's own (resolved) instance UID.
            $target_uid = (string) ($row['target_instance_uid'] ?? '');
            if ($target_uid !== '' && isset($locked[$domain . '|' . $target_uid])) {
                $skipped['approved_override']++;
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
            $proposed[] = ['domain' => $domain, 'instance_uid' => $uid, 'operation' => $operation];
        }

        usort($proposed, static function ($a, $b) {
            return [$a['domain'], $a['instance_uid']] <=> [$b['domain'], $b['instance_uid']];
        });
        if (count($proposed) > $policy['max_objects']) {
            $skipped['capped'] = count($proposed) - $policy['max_objects'];
            $proposed = array_slice($proposed, 0, $policy['max_objects']);
        }

        return ['proposed' => $proposed, 'skipped' => $skipped];
    }

    /**
     * @param string $source
     * @param string $target
     * @param string $mode
     * @param string $note
     * @param array{proposed?: array<int, array<string, mixed>>, skipped?: array<string, int>} $selection
     * @param array<string, mixed>|null $release
     * @param array<string, mixed>|null $rollout
     * @return array<string, mixed>
     */
    private function result($source, $target, $mode, $note, array $selection, $release, $rollout)
    {
        return [
            'source' => (string) $source,
            'target' => (string) $target,
            'mode' => $mode,
            'dry_run' => false,
            'note' => $note,
            'proposed' => $selection['proposed'] ?? [],
            'proposed_count' => count($selection['proposed'] ?? []),
            'skipped' => $selection['skipped'] ?? [],
            'release' => $release,
            'rollout' => $rollout,
        ];
    }

    /**
     * @param string $source
     * @param string $target
     * @param bool   $require_enabled
     * @return array<string, mixed>|\WP_Error
     */
    private function require_policy($source, $target, $require_enabled)
    {
        $policy = $this->policies->get($source, $target);
        if ($policy === null) {
            return new \WP_Error('dbvc_agency_sync_policy_missing', 'No sync policy for that pair; create one first.', ['status' => 404]);
        }
        if ($require_enabled) {
            if (! $policy['enabled']) {
                return new \WP_Error('dbvc_agency_sync_policy_disabled', 'The sync policy for that pair is disabled.', ['status' => 409]);
            }
            if ($policy['mode'] === 'manual') {
                return new \WP_Error('dbvc_agency_sync_mode_manual', 'Manual mode does not auto-propose; set mode=assisted or auto.', ['status' => 409]);
            }
        }

        return $policy;
    }
}
