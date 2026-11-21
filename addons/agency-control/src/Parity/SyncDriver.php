<?php

namespace Dbvc\AgencyControl\Parity;

use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Release\PreparationService;
use Dbvc\AgencyControl\Release\ReleaseService;
use Dbvc\AgencyControl\Release\RolloutService;
use Dbvc\AgencyControl\Storage\OverrideStore;
use Dbvc\AgencyControl\Storage\PreparationStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\RolloutStore;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\Protocol;

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
 * - report (CR-2, change report): tracked like auto, but the cycle ends at the
 *   target's dry run — propose a `purpose=report` release, wait for the seal,
 *   request a prepare (no rollout, no approval, nothing applied), and when the
 *   receipt arrives hand it to the report sink (`dbvc_agency_report_cycle_complete`)
 *   and clear. Selection follows D4 (removals, conflicts and creations are always
 *   transported because nothing applies); a payload mismatch withdraws and
 *   re-proposes next tick (`unstable_source` after three in a row); a stale side
 *   or an unchanged comparison creates nothing.
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
    /** Modes the hub drives on the parity tick and tracks on the policy row. */
    public const TRACKED_MODES = ['auto', 'report'];
    /** Consecutive report cycles withdrawn for a payload mismatch before the run is recorded `unstable_source` (D5). */
    public const REPORT_MISMATCH_LIMIT = 3;

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
        $selection = $policy['mode'] === 'report' ? $this->select_objects_report($comparison, $policy) : $this->select_objects($comparison, $policy);

        return [
            'source' => (string) $source,
            'target' => (string) $target,
            'mode' => $policy['mode'],
            'dry_run' => true,
            'counts' => $comparison['counts'] ?? [],
            'proposed' => $selection['proposed'],
            'proposed_count' => count($selection['proposed']),
            'skipped' => $selection['skipped'],
            'listed' => $selection['listed'] ?? [],
            'active_release_uid' => $policy['active_release_uid'],
            'active_rollout_uid' => $policy['active_rollout_uid'],
            'active_operation_id' => $policy['active_operation_id'],
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
        if (in_array($policy['mode'], self::TRACKED_MODES, true)) {
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
        if (! in_array($policy['mode'], self::TRACKED_MODES, true)) {
            return new \WP_Error('dbvc_agency_sync_mode_not_auto', 'advance is for auto or report mode; assisted uses sync-now to propose.', ['status' => 409]);
        }
        if ($policy['mode'] === 'report') {
            return $this->advance_report($policy);
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
     * Report reconcile (CR-2): drive the pair one step toward a change report.
     * Idempotent and re-entrant like {@see advance()}. The cycle: propose a
     * `purpose=report` release → wait for the source to seal it → request the
     * target's dry run → hand the receipt to the report sink and clear. Never
     * creates a rollout or an approval.
     *
     * @param array<string, mixed> $policy
     * @return array<string, mixed>|\WP_Error
     */
    private function advance_report(array $policy)
    {
        $source = (string) $policy['source_environment_id'];
        $target = (string) $policy['target_environment_id'];
        $store = $this->policies->store();
        $cycles = (int) $policy['mismatch_cycles'];

        // 1) A prepare request in flight: the cycle completes when its receipt lands (or expires).
        if ($policy['active_operation_id'] !== '') {
            $preparations = new PreparationStore();
            $preparation = $preparations->find($policy['active_operation_id']);
            $release = $policy['active_release_uid'] !== '' ? (new ReleaseStore())->find($policy['active_release_uid']) : null;
            if ($preparation === null || $release === null) {
                $store->set_report_state($source, $target, '', '', $cycles, 'preparation_missing');

                return $this->result($source, $target, 'report', 'preparation_missing', [], null, null);
            }
            if ($preparation['state'] === PreparationStore::STATE_REQUESTED) {
                $requested = strtotime((string) $preparation['requested_at'] . ' GMT');
                if ($requested !== false && time() - $requested > Protocol::PREPARE_RECEIPT_TTL_SECONDS) {
                    // The target never answered inside the receipt window: close the cycle without a receipt.
                    $preparations->cancel((int) $preparation['preparation_id'], 'report_expired');
                    $preparation = $preparations->find($policy['active_operation_id']) ?: $preparation;
                    $this->complete_report_cycle($policy, $release, $preparation, 'report_expired');

                    return $this->result($source, $target, 'report', 'report_expired', [], $release, null, ['operation_id' => (string) $preparation['operation_id']]);
                }
                $store->set_report_state($source, $target, $policy['active_release_uid'], $policy['active_operation_id'], $cycles, 'awaiting_receipt');

                return $this->result($source, $target, 'report', 'awaiting_receipt', [], $release, null, ['operation_id' => (string) $preparation['operation_id']]);
            }
            $note = $preparation['state'] === PreparationStore::STATE_RECEIVED ? 'report_ready' : 'preparation_' . (string) $preparation['state'];
            $this->complete_report_cycle($policy, $release, $preparation, $note);

            return $this->result($source, $target, 'report', $note, [], $release, null, ['operation_id' => (string) $preparation['operation_id'], 'receipt_outcome' => (string) ($preparation['outcome'] ?? '')]);
        }

        // 2) A release in flight: request the dry run once the source has sealed it.
        if ($policy['active_release_uid'] !== '') {
            $releases = new ReleaseStore();
            $release = $releases->find($policy['active_release_uid']);
            if ($release === null || $release['state'] === ReleaseStore::STATE_WITHDRAWN) {
                $store->set_report_state($source, $target, '', '', $cycles, 'release_gone');
                // fall through to propose again below.
            } elseif ($release['state'] === ReleaseStore::STATE_SEALED) {
                $requested = (new PreparationService())->request($release['release_uid'], $target);
                if (is_wp_error($requested)) {
                    if ($requested->get_error_code() === 'dbvc_agency_environment_frozen') {
                        // Freeze is respected (D: no special case): keep the sealed release and retry after the window.
                        $store->set_report_state($source, $target, $policy['active_release_uid'], '', $cycles, 'frozen_target');

                        return $this->result($source, $target, 'report', 'frozen_target', [], $release, null);
                    }
                    if ($requested->get_error_code() === 'dbvc_agency_preparation_pending') {
                        // An earlier tick's request is still outstanding (e.g. after a crash before the state write): adopt it.
                        foreach ((new PreparationStore())->all($release['release_uid'], $target, 50) as $existing) {
                            if ($existing['state'] === PreparationStore::STATE_REQUESTED) {
                                $store->set_report_state($source, $target, $policy['active_release_uid'], (string) $existing['operation_id'], $cycles, 'prepare_requested');

                                return $this->result($source, $target, 'report', 'prepare_requested', [], $release, null, ['operation_id' => (string) $existing['operation_id']]);
                            }
                        }
                    }

                    return $requested;
                }
                $store->set_report_state($source, $target, $policy['active_release_uid'], (string) $requested['operation_id'], $cycles, 'prepare_requested');

                return $this->result($source, $target, 'report', 'prepare_requested', [], $release, null, ['operation_id' => (string) $requested['operation_id']]);
            } else {
                // Still open. A payload mismatch means the source edited the object between propose
                // and upload — normal while staging is under active review (D5): withdraw, count the
                // cycle, and re-propose with current hashes next tick.
                $mismatched = 0;
                foreach ($releases->items((int) $release['release_id']) as $item) {
                    if ((string) ($item['payload_state'] ?? '') === ReleaseStore::PAYLOAD_MISMATCH) {
                        $mismatched++;
                    }
                }
                if ($mismatched > 0) {
                    (new ReleaseService())->withdraw($release['release_uid']);
                    $cycles++;
                    $note = $cycles >= self::REPORT_MISMATCH_LIMIT ? 'unstable_source' : 'mismatch_withdrawn';
                    $store->set_report_state($source, $target, '', '', $cycles, $note);
                    if ($note === 'unstable_source') {
                        /**
                         * A report pair has withdrawn REPORT_MISMATCH_LIMIT consecutive cycles because the
                         * source kept changing between propose and upload (CR-2, D5). CR-4 notifies on it.
                         *
                         * @param array<string, mixed> $policy
                         * @param array<string, mixed> $release The last withdrawn release.
                         * @param int                  $cycles
                         */
                        do_action('dbvc_agency_report_unstable_source', $policy, $release, $cycles);
                    }

                    return $this->result($source, $target, 'report', $note, [], $release, null, ['mismatched_items' => $mismatched, 'mismatch_cycles' => $cycles]);
                }
                $store->set_report_state($source, $target, $policy['active_release_uid'], '', $cycles, 'awaiting_payloads');

                return $this->result($source, $target, 'report', 'awaiting_payloads', [], $release, null);
            }
        }

        // 3) Nothing in flight: compare, and propose a report release only when something changed.
        $comparison = (new ComparisonService())->compare($source, $target);
        if (is_wp_error($comparison)) {
            return $comparison;
        }
        $stale = empty($comparison['source']['fresh']) ? 'stale_source' : (empty($comparison['target']['fresh']) ? 'stale_target' : null);
        if ($stale !== null) {
            // Honest states (D12): a stale side must not read as "in sync"; nothing is created.
            $store->set_report_state($source, $target, '', '', $cycles, $stale);
            /**
             * A report pair cannot run because one side has not reported within the
             * freshness window (CR-2 D12). Fired on every idle tick while the side stays
             * silent; CR-4's notifier dedupes on the stale side's last_contact_at so an
             * outage is announced once per episode.
             *
             * @param array<string, mixed> $policy     Sync-policy row.
             * @param string               $stale_side 'source' or 'target'.
             * @param array<string, mixed> $comparison The fresh comparison (source/target last_contact_at inside).
             */
            do_action('dbvc_agency_report_stale', $policy, $stale === 'stale_source' ? 'source' : 'target', $comparison);

            return $this->result($source, $target, 'report', $stale, [], null, null, ['source_last_contact_at' => (string) ($comparison['source']['last_contact_at'] ?? ''), 'target_last_contact_at' => (string) ($comparison['target']['last_contact_at'] ?? ''), 'source_last_seen_at' => (string) ($comparison['source']['last_seen_at'] ?? ''), 'target_last_seen_at' => (string) ($comparison['target']['last_seen_at'] ?? '')]);
        }
        /**
         * A fresh (both sides reporting) comparison for a report pair, every idle tick
         * (CR-2/CR-3): the change report verifies carried decisions and settles converged
         * items on it without needing a release.
         *
         * @param array<string, mixed> $policy
         * @param array<string, mixed> $comparison
         */
        do_action('dbvc_agency_report_comparison', $policy, $comparison);
        $selection = $this->select_objects_report($comparison, $policy);
        $digest = self::comparison_digest($comparison);
        if ($cycles === 0 && $digest !== '' && $digest === $policy['last_comparison_digest']) {
            // Same comparison as the last completed cycle: a tick with no change creates nothing.
            $store->set_report_state($source, $target, '', '', 0, 'no_change');

            return $this->result($source, $target, 'report', 'no_change', $selection, null, null, ['comparison_digest' => $digest]);
        }
        if ($selection['proposed'] === []) {
            $store->set_report_state($source, $target, '', '', 0, 'in_sync', $digest);

            return $this->result($source, $target, 'report', 'in_sync', $selection, null, null, ['comparison_digest' => $digest]);
        }
        $created = (new ReleaseService())->create($source, $selection['proposed'], 'report:' . $source . '->' . $target, ReleaseStore::PURPOSE_REPORT);
        if (is_wp_error($created)) {
            return $created;
        }
        $store->set_report_state($source, $target, (string) $created['release']['release_uid'], '', $cycles, 'release_open', $digest);

        return $this->result($source, $target, 'report', 'release_open', $selection, $created['release'], null, ['comparison_digest' => $digest]);
    }

    /**
     * Close a report cycle: hand the release + preparation (receipt, when one
     * arrived) to the report sink and clear the policy's in-flight state. The
     * mismatch counter resets because the cycle completed.
     *
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $release
     * @param array<string, mixed> $preparation
     * @param string               $note
     * @return void
     */
    private function complete_report_cycle(array $policy, array $release, array $preparation, $note)
    {
        $this->policies->store()->set_report_state((string) $policy['source_environment_id'], (string) $policy['target_environment_id'], '', '', 0, $note . ':' . (string) $preparation['operation_id']);
        /**
         * A report cycle completed (CR-2): the target's dry-run receipt for a report-purpose
         * release is stored on the preparation (or the request expired). CR-3's
         * ChangeReportService::ingest hooks here to build the durable report.
         *
         * @param array<string, mixed> $policy      The sync policy row (mode=report).
         * @param array<string, mixed> $release     The report-purpose release.
         * @param array<string, mixed> $preparation The preparation row: state received (receipt + hub_notes) or cancelled (expired).
         * @param string               $note        report_ready | report_expired | preparation_cancelled
         */
        do_action('dbvc_agency_report_cycle_complete', $policy, $release, $preparation, $note);
    }

    /**
     * Report-mode selection (D4). Nothing applies, so the apply-side policy flags
     * (conflict_policy, propagate_deletions, create_new) are ignored: removals,
     * conflicts and creations are always transported so the report can show them.
     * `incoming` (LocalWP-only), target-locked and `unknown` rows are listed
     * hash-level but never transported; scope / include / exclude and the cap apply.
     *
     * @param array<string, mixed> $comparison
     * @param array<string, mixed> $policy
     * @return array{proposed: array<int, array<string, mixed>>, skipped: array<string, int>, listed: array<int, array<string, mixed>>}
     */
    private function select_objects_report(array $comparison, array $policy)
    {
        $scope = array_fill_keys($policy['scope_domains'], true);
        $include = array_fill_keys($policy['include_uids'], true);
        $exclude = array_fill_keys($policy['exclude_uids'], true);
        $locked = (new OverrideStore())->locked_for_environment((string) $policy['target_environment_id']);

        $proposed = [];
        $listed = [];
        $skipped = ['local_only' => 0, 'unknown' => 0, 'baseline_required' => 0, 'incomplete' => 0, 'excluded' => 0, 'approved_override' => 0, 'capped' => 0];

        foreach ((array) ($comparison['rows'] ?? []) as $row) {
            $domain = (string) ($row['domain'] ?? '');
            $uid = (string) ($row['source_instance_uid'] ?? '');
            $target_uid = (string) ($row['target_instance_uid'] ?? '');
            $state = (string) ($row['state'] ?? '');
            if ($scope !== [] && ! isset($scope[$domain])) {
                continue;
            }
            if (($uid === '' && $target_uid === '') || $uid === ComparisonService::ORDER_INSTANCE_UID || $target_uid === ComparisonService::ORDER_INSTANCE_UID) {
                continue;
            }
            $key = $uid !== '' ? $uid : $target_uid;
            if ($include !== [] && ! isset($include[$key])) {
                $skipped['excluded']++;
                continue;
            }
            if (isset($exclude[$key])) {
                $skipped['excluded']++;
                continue;
            }
            $entry = ['domain' => $domain, 'instance_uid' => $uid, 'target_instance_uid' => $target_uid, 'state' => $state, 'source_hash' => $row['source_hash'] ?? null, 'target_hash' => $row['target_hash'] ?? null];
            if ($target_uid !== '' && isset($locked[$domain . '|' . $target_uid])) {
                $skipped['approved_override']++;
                $listed[] = $entry + ['listed_as' => 'locked'];
                continue;
            }
            $operation = null;
            if ($state === 'outgoing') {
                if (empty($row['complete'])) {
                    $skipped['incomplete']++;
                } elseif ($row['source_exists'] === true && $row['target_exists'] === false) {
                    // Baselined as absent on the target (baseline-confirm --accept-absent),
                    // now present on the source: a creation, not a replace — the target has
                    // no identity to replace and would refuse the dry run (CR-4c live finding).
                    $operation = 'create';
                } elseif ($row['source_exists'] === true) {
                    $operation = 'replace';
                } elseif ($row['source_exists'] === false) {
                    $operation = 'delete';
                }
            } elseif ($state === 'conflict') {
                if ($row['source_exists'] === true && ! empty($row['complete'])) {
                    $operation = 'replace';
                } elseif ($row['source_exists'] === false && ! empty($row['complete'])) {
                    $operation = 'delete';
                } else {
                    $skipped['incomplete']++;
                }
            } elseif ($state === 'baseline_required' && $row['source_exists'] === true && $row['target_exists'] === false && ! empty($row['complete'])) {
                $operation = 'create';
            } elseif ($state === 'baseline_required') {
                // No agreed starting point and not a plain creation (e.g. an object only the
                // target has): listed so the report can say so, never transported.
                $skipped['baseline_required']++;
                $listed[] = $entry + ['listed_as' => 'baseline_required'];
            } elseif ($state === 'incoming') {
                $skipped['local_only']++;
                $listed[] = $entry + ['listed_as' => 'local_only'];
            } elseif ($state === 'unknown') {
                $skipped['unknown']++;
                $listed[] = $entry + ['listed_as' => 'unknown'];
            }
            if ($operation === null || $uid === '') {
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

        return ['proposed' => $proposed, 'skipped' => $skipped, 'listed' => $listed];
    }

    /**
     * Stable digest of what a comparison says about a pair: one line per object
     * (domain, both uids, state, both hashes), sorted. Two ticks that see the same
     * digest saw the same pair, so the second creates nothing.
     *
     * @param array<string, mixed> $comparison
     * @return string
     */
    public static function comparison_digest(array $comparison)
    {
        $lines = [];
        foreach ((array) ($comparison['rows'] ?? []) as $row) {
            $lines[] = implode('|', [
                (string) ($row['domain'] ?? ''),
                (string) ($row['source_instance_uid'] ?? ''),
                (string) ($row['target_instance_uid'] ?? ''),
                (string) ($row['state'] ?? ''),
                (string) ($row['source_hash'] ?? ''),
                (string) ($row['target_hash'] ?? ''),
            ]);
        }
        sort($lines, SORT_STRING);

        return $lines === [] ? '' : Canonicalizer::hash(Canonicalizer::encode($lines));
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
    private function result($source, $target, $mode, $note, array $selection, $release, $rollout, array $extra = [])
    {
        return array_merge([
            'source' => (string) $source,
            'target' => (string) $target,
            'mode' => $mode,
            'dry_run' => false,
            'note' => $note,
            'proposed' => $selection['proposed'] ?? [],
            'proposed_count' => count($selection['proposed'] ?? []),
            'skipped' => $selection['skipped'] ?? [],
            'listed' => $selection['listed'] ?? [],
            'release' => $release,
            'rollout' => $rollout,
        ], $extra);
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
                return new \WP_Error('dbvc_agency_sync_mode_manual', 'Manual mode does not auto-propose; set mode=assisted, auto or report.', ['status' => 409]);
            }
        }

        return $policy;
    }
}
