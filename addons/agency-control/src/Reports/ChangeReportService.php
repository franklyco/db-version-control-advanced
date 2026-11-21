<?php

namespace Dbvc\AgencyControl\Reports;

use Dbvc\AgencyControl\Comparison\BaselineService;
use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Parity\SyncPolicyService;
use Dbvc\AgencyControl\Release\ReleaseService;
use Dbvc\AgencyControl\Storage\ChangeReportStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\MediaBlobStore;
use Dbvc\AgencyControl\Storage\OverrideStore;
use Dbvc\AgencyControl\Storage\PreparationStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\SyncPolicyStore;
use Dbvc\ConnectedProtocol\DesignReviewAnnotation;

/**
 * The durable change report (CR-3). Built from report-mode cycles (CR-2):
 * when a cycle completes, {@see ingest()} turns the report-purpose release,
 * the target's dry-run receipt (nested paths with values when the target
 * opted in, CR-1) and a fresh comparison into one report row (the run) and
 * one live item per object (the inbox), applying the decisions carried from
 * earlier runs (D11). Every idle tick {@see verify()} settles those
 * decisions and converged objects against the fresh comparison, so "Done"
 * is verified without a release.
 *
 * Decisions (CR-M f/g/i): `reconciled` ("Done" — the operator ported it by
 * hand; verified when the pair converges, reopened as still_differs when
 * not), `ignored` ("Leave it" — muted until the source hash moves again →
 * changed_again), `snoozed` ("Remind me tomorrow" — hidden until due),
 * `promoted` (selected into a real release through the existing publisher
 * pipeline). Nothing here applies anything.
 *
 * Retention (D1): report-purpose releases older than
 * {@see REPORT_RELEASE_RETENTION_DAYS} whose report row exists are purged
 * (release, items, preparations; media blobs by the existing GC); report
 * rows are kept to the newest {@see REPORT_KEEP_PER_PAIR} per pair, each with
 * its per-run snapshot (CR-4e: how that run saw every object it touched or
 * settled, so the console stays historical while the inbox stays live).
 */
final class ChangeReportService
{
    public const REPORT_RELEASE_RETENTION_DAYS = 7;
    public const REPORT_KEEP_PER_PAIR = 20;
    public const SNOOZE_SECONDS = 86400;

    public const LABEL_CREATED = 'created';
    public const LABEL_UPDATED = 'updated';
    public const LABEL_REMOVED = 'removed';
    public const LABEL_CONFLICT = 'conflict';
    public const LABEL_LOCAL_ONLY = 'local_only';
    public const LABEL_CONVERGED = 'converged';
    public const LABEL_LOCKED = 'locked';
    public const LABEL_UNKNOWN = 'unknown';
    public const LABEL_BASELINE_REQUIRED = 'baseline_required';

    /** Plain-language label map (CR-M label map; the simple page's words). */
    public const LABEL_TEXT = [
        self::LABEL_CREATED => 'New on staging',
        self::LABEL_UPDATED => 'Changed on staging',
        self::LABEL_REMOVED => 'Removed on staging',
        self::LABEL_CONFLICT => 'Changed on both sites',
        self::LABEL_LOCAL_ONLY => 'Changed on LocalWP only',
        self::LABEL_CONVERGED => 'Both sites match',
        self::LABEL_LOCKED => 'Protected on LocalWP',
        self::LABEL_UNKNOWN => 'Not reporting',
        self::LABEL_BASELINE_REQUIRED => 'No agreed starting point',
    ];

    /**
     * @var ChangeReportStore
     */
    private $store;

    public function __construct()
    {
        $this->store = new ChangeReportStore();
    }

    /**
     * @return void
     */
    public static function register()
    {
        add_action('dbvc_agency_report_cycle_complete', [self::class, 'on_cycle_complete'], 10, 4);
        add_action('dbvc_agency_report_comparison', [self::class, 'on_comparison'], 10, 2);
    }

    /**
     * @return void
     */
    public static function unregister()
    {
        remove_action('dbvc_agency_report_cycle_complete', [self::class, 'on_cycle_complete'], 10);
        remove_action('dbvc_agency_report_comparison', [self::class, 'on_comparison'], 10);
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $release
     * @param array<string, mixed> $preparation
     * @param string               $note
     * @return void
     */
    public static function on_cycle_complete(array $policy, array $release, array $preparation, $note)
    {
        (new self())->ingest($policy, $release, $preparation, (string) $note);
    }

    /**
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $comparison
     * @return void
     */
    public static function on_comparison(array $policy, array $comparison)
    {
        (new self())->verify((string) $policy['source_environment_id'], (string) $policy['target_environment_id'], $comparison);
    }

    /**
     * @return ChangeReportStore
     */
    public function store()
    {
        return $this->store;
    }

    // ---- build ------------------------------------------------------------

    /**
     * Build the report row and refresh the live items for a completed cycle.
     *
     * @param array<string, mixed> $policy      Sync policy row (mode=report).
     * @param array<string, mixed> $release     Report-purpose release row.
     * @param array<string, mixed> $preparation Preparation row (received: receipt + hub_notes; cancelled: expired).
     * @param string               $note        report_ready | report_expired | preparation_cancelled
     * @return array<string, mixed>|\WP_Error The report row.
     */
    public function ingest(array $policy, array $release, array $preparation, $note)
    {
        $source = (string) $policy['source_environment_id'];
        $target = (string) $policy['target_environment_id'];
        $comparison = (new ComparisonService())->compare($source, $target);
        if (is_wp_error($comparison)) {
            return $comparison;
        }
        $rows_by_source = [];
        $rows_by_target = [];
        foreach ((array) ($comparison['rows'] ?? []) as $row) {
            $domain = (string) $row['domain'];
            if (! empty($row['source_instance_uid'])) {
                $rows_by_source[$domain . '|' . $row['source_instance_uid']] = $row;
            }
            if (! empty($row['target_instance_uid'])) {
                $rows_by_target[$domain . '|' . $row['target_instance_uid']] = $row;
            }
        }
        $receipt = is_array($preparation['receipt'] ?? null) ? $preparation['receipt'] : null;
        $receipt_items = [];
        foreach ((array) ($receipt['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['domain'], $item['instance_uid'])) {
                $receipt_items[(string) $item['domain'] . '|' . (string) $item['instance_uid']] = $item;
            }
        }
        $notes_items = [];
        foreach ((array) ($preparation['hub_notes']['items'] ?? []) as $item) {
            if (is_array($item) && isset($item['domain'], $item['instance_uid'])) {
                $notes_items[(string) $item['domain'] . '|' . (string) $item['instance_uid']] = $item;
            }
        }
        $values_included = ! empty($receipt['values']['included']);
        $report_uid = 'rpt-' . bin2hex(random_bytes(8));
        $now = current_time('mysql', true);
        $locked = (new OverrideStore())->locked_for_environment($target);
        $decisions = $this->store->decisions($source, $target);

        $counts = array_fill_keys(ChangeReportStore::LABELS, 0);
        $touched = [];
        $truncated = 0;

        // 1) Transported objects: one item per release item, enriched by the receipt.
        foreach ((new ReleaseStore())->items((int) $release['release_id'], true) as $item) {
            $key = (string) $item['domain'] . '|' . (string) $item['instance_uid'];
            $row = $rows_by_source[$key] ?? null;
            $operation = (string) $item['operation'];
            $state = $row !== null ? (string) $row['state'] : '';
            if ($operation === ReleaseStore::OPERATION_CREATE) {
                $label = self::LABEL_CREATED;
            } elseif ($operation === ReleaseStore::OPERATION_DELETE) {
                $label = self::LABEL_REMOVED;
            } elseif ($state === 'conflict') {
                $label = self::LABEL_CONFLICT;
            } else {
                $label = self::LABEL_UPDATED;
            }
            $receipt_item = $receipt_items[$key] ?? null;
            $paths = is_array($receipt_item['patch']['changed_paths'] ?? null) ? $receipt_item['patch']['changed_paths'] : [];
            $is_truncated = ! empty($receipt_item['patch']['truncated']);
            $truncated += $is_truncated ? 1 : 0;
            $body = json_decode((string) ($item['payload'] ?? ''), true);
            $target_uid = (string) ($receipt_item['target_instance_uid'] ?? ($row['target_instance_uid'] ?? $item['instance_uid']));
            $display_name = self::display_name((string) $item['domain'], is_array($body) ? $body : [], '');
            if ($display_name === '') {
                // A removal carries the canonical null: name it from the item we already
                // hold for the object, else from the target's own projection (match_key).
                $existing = $this->store->item($source, $target, (string) $item['domain'], (string) $item['instance_uid']);
                $display_name = $existing !== null && $existing['display_name'] !== '' ? (string) $existing['display_name'] : $this->projection_name($target, (string) $item['domain'], $target_uid, (string) $item['profile']);
            }
            $entry = [
                'source_environment_id' => $source,
                'target_environment_id' => $target,
                'domain' => (string) $item['domain'],
                'instance_uid' => (string) $item['instance_uid'],
                'target_instance_uid' => $target_uid,
                'profile' => (string) $item['profile'],
                'display_name' => $display_name !== '' ? $display_name : (string) $item['instance_uid'],
                'label' => $label,
                'comparison_state' => $state,
                'operation' => $operation,
                'source_hash' => (string) $item['after_hash'],
                'target_hash' => isset($receipt_item['before_hash']) && is_string($receipt_item['before_hash']) ? $receipt_item['before_hash'] : ($row['target_hash'] ?? null),
                'baseline_hash' => $notes_items[$key]['baseline_hash'] ?? ($row['baseline_hash'] ?? null),
                'paths' => $paths,
                'values_included' => $values_included && $paths !== [],
                'truncated' => $is_truncated,
                'receipt_outcome' => (string) ($receipt_item['outcome'] ?? ($receipt === null ? 'no_receipt' : '')),
                'blockers' => array_values((array) ($receipt_item['blockers'] ?? [])),
                'report_uid' => $report_uid,
                'changed_at' => $now,
            ];
            $this->place($entry, $decisions, $comparison, $row);
            $counts[$label]++;
            $touched[$key] = true;
        }

        // 2) Listed-only objects: changed on LocalWP only, protected, not reporting, or unbaselined.
        //    Only what the policy watches (scope / include / exclude, the same key rule the
        //    report selection uses): an operator who scoped a pair to five objects must not
        //    get every unbaselined object of the fleet in the inbox (CR-4c live finding).
        foreach ((array) ($comparison['rows'] ?? []) as $row) {
            $domain = (string) $row['domain'];
            $uid = (string) ($row['source_instance_uid'] ?? '');
            $target_uid = (string) ($row['target_instance_uid'] ?? '');
            $state = (string) $row['state'];
            if ($uid === ComparisonService::ORDER_INSTANCE_UID || $target_uid === ComparisonService::ORDER_INSTANCE_UID) {
                continue;
            }
            if (! self::in_policy_scope($policy, $domain, $uid !== '' ? $uid : $target_uid)) {
                continue;
            }
            $key = $domain . '|' . ($uid !== '' ? $uid : $target_uid);
            if (isset($touched[$key])) {
                continue;
            }
            $label = null;
            if ($target_uid !== '' && isset($locked[$domain . '|' . $target_uid]) && in_array($state, ['outgoing', 'conflict'], true)) {
                $label = self::LABEL_LOCKED;
            } elseif ($state === 'incoming') {
                $label = self::LABEL_LOCAL_ONLY;
            } elseif ($state === 'unknown') {
                $label = self::LABEL_UNKNOWN;
            } elseif ($state === 'baseline_required' && ! ($row['source_exists'] === true && $row['target_exists'] === false)) {
                $label = self::LABEL_BASELINE_REQUIRED;
            }
            if ($label === null) {
                continue;
            }
            $entry = [
                'source_environment_id' => $source,
                'target_environment_id' => $target,
                'domain' => $domain,
                'instance_uid' => $uid !== '' ? $uid : $target_uid,
                'target_instance_uid' => $target_uid,
                'profile' => (string) ($row['profile'] ?? ''),
                'display_name' => '',
                'label' => $label,
                'comparison_state' => $state,
                'operation' => '',
                'source_hash' => $row['source_hash'] ?? null,
                'target_hash' => $row['target_hash'] ?? null,
                'baseline_hash' => $row['baseline_hash'] ?? null,
                'paths' => [],
                'values_included' => false,
                'truncated' => false,
                'receipt_outcome' => '',
                'blockers' => [],
                'report_uid' => $report_uid,
                'changed_at' => $now,
            ];
            $existing = $this->store->item($source, $target, $domain, $entry['instance_uid']);
            if ($existing !== null && $existing['display_name'] !== '') {
                $entry['display_name'] = $existing['display_name'];
            } else {
                // Hash-level rows carry no body: name them from the side that has the object.
                $entry['display_name'] = $this->projection_name($source, $domain, $uid, $entry['profile']);
                if ($entry['display_name'] === '') {
                    $entry['display_name'] = $this->projection_name($target, $domain, $target_uid, $entry['profile']);
                }
            }
            $this->place($entry, $decisions, $comparison, $row);
            $counts[$label]++;
            $touched[$key] = true;
        }

        // 3) Everything else the inbox still shows has converged (or left the comparison): settle it.
        $counts[self::LABEL_CONVERGED] += $this->settle_converged($source, $target, $comparison, $touched, $report_uid);

        $report = $this->store->create_report([
            'report_uid' => $report_uid,
            'agency_id' => (string) ($release['agency_id'] ?? $policy['agency_id'] ?? ''),
            'client_id' => (string) ($release['client_id'] ?? $policy['client_id'] ?? ''),
            'source_environment_id' => $source,
            'target_environment_id' => $target,
            'release_uid' => (string) $release['release_uid'],
            'operation_id' => (string) ($preparation['operation_id'] ?? ''),
            'state' => $note === 'report_ready' ? 'ready' : 'expired',
            'receipt_outcome' => (string) ($receipt['outcome'] ?? ''),
            'comparison_digest' => \Dbvc\AgencyControl\Parity\SyncDriver::comparison_digest($comparison),
            'values_included' => $values_included,
            'item_count' => count($touched),
            'counts' => $counts + ['truncated_items' => $truncated],
            'generated_at' => $now,
        ]);
        if ($report === null) {
            return new \WP_Error('dbvc_agency_report_error', 'The change report could not be recorded.', ['status' => 500]);
        }
        $this->store->prune_reports($source, $target, self::REPORT_KEEP_PER_PAIR);

        /**
         * A change report was generated (CR-3). CR-4 notifies on it.
         *
         * @param array<string, mixed> $report
         * @param array<string, mixed> $policy
         */
        do_action('dbvc_agency_change_report_generated', $report, $policy);

        return $report;
    }

    /**
     * Store one item, applying any decision carried for the object.
     *
     * @param array<string, mixed>                $entry
     * @param array<string, array<string, mixed>> $decisions keyed "domain|uid"
     * @param array<string, mixed>                $comparison
     * @param array<string, mixed>|null           $row
     * @return void
     */
    private function place(array $entry, array $decisions, array $comparison, $row)
    {
        $key = $entry['domain'] . '|' . $entry['instance_uid'];
        $entry['resolved'] = false;
        $decision = $decisions[$key] ?? null;
        if ($decision !== null) {
            $entry['resolved'] = $this->apply_decision($decision, $entry['source_hash'] ?? null, $entry['target_hash'] ?? null, $row !== null ? (string) $row['state'] : $entry['comparison_state']);
        }
        $item = $this->store->upsert_item($entry);
        if ($item !== null && (string) ($entry['report_uid'] ?? '') !== '') {
            // CR-4e: freeze how this run saw the object; later runs re-stamp the live row.
            $this->store->snapshot_item((string) $entry['report_uid'], $item);
        }
    }

    /**
     * Settle a carried decision against the current hashes/state; returns
     * whether the item stays hidden (resolved) from the inbox.
     *
     * @param array<string, mixed> $decision
     * @param string|null          $source_hash
     * @param string|null          $target_hash
     * @param string               $state
     * @return bool
     */
    private function apply_decision(array $decision, $source_hash, $target_hash, $state)
    {
        $converged = in_array($state, ['converged', 'synchronized'], true) || ($source_hash !== null && $source_hash === $target_hash);
        switch ($decision['decision']) {
            case ChangeReportStore::DECISION_RECONCILED:
            case ChangeReportStore::DECISION_PROMOTED:
                if ($converged) {
                    // "Sorted — they match now" on a conflict (CR-M b/i): once both sides agree, make
                    // that the object's agreed starting point so it reads synchronized, not converged.
                    if (! empty($decision['rebaseline_object']) && $state === 'converged') {
                        (new BaselineService())->confirm((string) $decision['source_environment_id'], (string) $decision['target_environment_id'], (string) $decision['domain'], (string) $decision['instance_uid'], 'report:sorted');
                    }
                    $this->store->set_verify_state((int) $decision['decision_id'], ChangeReportStore::VERIFY_VERIFIED);
                    return true;
                }
                $this->store->set_verify_state((int) $decision['decision_id'], ChangeReportStore::VERIFY_STILL_DIFFERS);
                return false;
            case ChangeReportStore::DECISION_IGNORED:
                if ($source_hash !== null && (string) $decision['decided_source_hash'] !== (string) $source_hash) {
                    $this->store->set_verify_state((int) $decision['decision_id'], ChangeReportStore::VERIFY_CHANGED_AGAIN);
                    return false;
                }
                $this->store->set_verify_state((int) $decision['decision_id'], ChangeReportStore::VERIFY_MUTED);
                return true;
            case ChangeReportStore::DECISION_SNOOZED:
                $until = $decision['snoozed_until'] !== null ? strtotime((string) $decision['snoozed_until'] . ' GMT') : false;
                if ($until === false || time() >= $until) {
                    $this->store->set_verify_state((int) $decision['decision_id'], ChangeReportStore::VERIFY_DUE);
                    return false;
                }
                $this->store->set_verify_state((int) $decision['decision_id'], ChangeReportStore::VERIFY_SNOOZED);
                return true;
        }

        return false;
    }

    /**
     * Open items the comparison no longer reports as differing are settled as
     * converged; resolved ones are left alone.
     *
     * @param string               $source
     * @param string               $target
     * @param array<string, mixed> $comparison
     * @param array<string, bool>  $touched    keys "domain|uid" refreshed by this pass
     * @param string|null          $report_uid the run settling them (ingest), null on an idle verify
     * @return int Items settled.
     */
    private function settle_converged($source, $target, array $comparison, array $touched, $report_uid = null)
    {
        $differing = [];
        foreach ((array) ($comparison['rows'] ?? []) as $row) {
            if (in_array((string) $row['state'], ['converged', 'synchronized'], true)) {
                continue;
            }
            $differing[(string) $row['domain'] . '|' . (string) ($row['source_instance_uid'] ?? $row['target_instance_uid'] ?? '')] = true;
        }
        $settled = 0;
        foreach ($this->store->items($source, $target, ['resolved' => false]) as $item) {
            $key = $item['domain'] . '|' . $item['instance_uid'];
            if (isset($touched[$key]) || isset($differing[$key]) || $item['label'] === self::LABEL_CONVERGED) {
                continue;
            }
            $this->store->set_item_label((int) $item['item_id'], self::LABEL_CONVERGED, true, 'converged');
            if ($report_uid !== null && $report_uid !== '') {
                $settled_item = $this->store->item($source, $target, $item['domain'], $item['instance_uid']);
                if ($settled_item !== null) {
                    $this->store->snapshot_item((string) $report_uid, $settled_item);
                }
            }
            $decision = $this->store->decision($source, $target, $item['domain'], $item['instance_uid']);
            if ($decision !== null && in_array($decision['decision'], [ChangeReportStore::DECISION_RECONCILED, ChangeReportStore::DECISION_PROMOTED], true)) {
                $this->store->set_verify_state((int) $decision['decision_id'], ChangeReportStore::VERIFY_VERIFIED);
            }
            $settled++;
        }

        return $settled;
    }

    /**
     * Verify carried decisions and settle converged items against a fresh
     * comparison (every idle report tick; no release needed).
     *
     * @param string               $source
     * @param string               $target
     * @param array<string, mixed> $comparison
     * @return array{verified: int, reopened: int, settled: int}
     */
    public function verify($source, $target, array $comparison)
    {
        $rows = [];
        foreach ((array) ($comparison['rows'] ?? []) as $row) {
            $rows[(string) $row['domain'] . '|' . (string) ($row['source_instance_uid'] ?: ($row['target_instance_uid'] ?? ''))] = $row;
        }
        $verified = 0;
        $reopened = 0;
        foreach ($this->store->decisions($source, $target) as $key => $decision) {
            $item = $this->store->item($source, $target, $decision['domain'], $decision['instance_uid']);
            if ($item === null) {
                continue;
            }
            $row = $rows[$key] ?? null;
            $state = $row !== null ? (string) $row['state'] : 'converged';
            $source_hash = $row !== null ? ($row['source_hash'] ?? null) : $item['source_hash'];
            $target_hash = $row !== null ? ($row['target_hash'] ?? null) : $item['target_hash'];
            $was_resolved = $item['resolved'];
            $resolved = $this->apply_decision($decision, $source_hash, $target_hash, $state);
            if ($resolved !== $was_resolved) {
                $this->store->set_item_resolved((int) $item['item_id'], $resolved);
            }
            $fresh = $this->store->decision($source, $target, $decision['domain'], $decision['instance_uid']);
            if ($fresh !== null && $fresh['verify_state'] === ChangeReportStore::VERIFY_VERIFIED && $decision['verify_state'] !== ChangeReportStore::VERIFY_VERIFIED) {
                $verified++;
            }
            if (! $resolved && $was_resolved) {
                $reopened++;
            }
        }
        $settled = $this->settle_converged($source, $target, $comparison, []);

        return ['verified' => $verified, 'reopened' => $reopened, 'settled' => $settled];
    }

    // ---- read -------------------------------------------------------------

    /**
     * The inbox for a pair: open items with their decisions, handled items,
     * a one-line summary and the pair's honest status (simple page data).
     *
     * @param string               $source
     * @param string               $target
     * @param array<string, mixed> $options handled_limit (default 50), report_uid (the run's snapshot instead; `run.snapshot` says whether one existed)
     * @return array<string, mixed>|\WP_Error
     */
    public function inbox($source, $target, array $options = [])
    {
        $policy = (new SyncPolicyService())->get((string) $source, (string) $target);
        if ($policy === null) {
            return new \WP_Error('dbvc_agency_sync_policy_missing', 'No sync policy for that pair; create one first.', ['status' => 404]);
        }
        $decisions = $this->store->decisions($source, $target);
        $with_decision = function (array $item) use ($decisions) {
            $item['label_text'] = self::LABEL_TEXT[$item['label']] ?? $item['label'];
            $item['decision'] = $decisions[$item['domain'] . '|' . $item['instance_uid']] ?? null;
            $item['needs_look'] = ! $item['resolved'] && in_array($item['label'], [self::LABEL_CREATED, self::LABEL_UPDATED, self::LABEL_REMOVED, self::LABEL_CONFLICT], true);

            return $item;
        };
        $snapshot = null;
        if (! empty($options['report_uid'])) {
            // CR-4e: a run is read from its snapshot (how it saw each object); the decision
            // beside each row is the live one. Runs recorded before the snapshot table fall
            // back to the live rows still stamped with that run.
            $items = $this->store->run_items($source, $target, (string) $options['report_uid'], (int) ($options['handled_limit'] ?? 500) + 500);
            $snapshot = $items !== [];
            if (! $snapshot) {
                $items = $this->store->items($source, $target, ['report_uid' => (string) $options['report_uid']]);
            }
            $items = array_map($with_decision, $items);
            $open = array_values(array_filter($items, static function ($item) {
                return ! $item['resolved'];
            }));
            $handled = array_values(array_filter($items, static function ($item) {
                return $item['resolved'];
            }));
        } else {
            $open = array_map($with_decision, $this->store->items($source, $target, ['resolved' => false]));
            $handled = array_map($with_decision, $this->store->items($source, $target, ['resolved' => true, 'limit' => (int) ($options['handled_limit'] ?? 50)]));
        }
        $needs_look = count(array_filter($open, static function ($item) {
            return $item['needs_look'];
        }));
        $registry = new EnvironmentRegistry();
        $source_env = $registry->find((string) $source);
        $target_env = $registry->find((string) $target);
        $latest = $this->store->latest_report($source, $target);

        return [
            'source' => (string) $source,
            'target' => (string) $target,
            'summary' => [
                'open' => count($open),
                'needs_look' => $needs_look,
                'info' => count($open) - $needs_look,
                'handled' => count($handled),
                'since' => $latest !== null ? (string) $latest['generated_at'] : '',
                'text' => $this->summary_text(count($open), $needs_look),
            ],
            'status' => [
                'mode' => (string) $policy['mode'],
                'enabled' => (bool) $policy['enabled'],
                'cadence' => (string) $policy['cadence'],
                'last_run_at' => (string) $policy['last_run_at'],
                'last_run_note' => (string) $policy['last_run_note'],
                'in_flight' => $policy['active_release_uid'] !== '' || $policy['active_operation_id'] !== '',
                'source_last_contact_at' => $source_env !== null ? (string) ($source_env['last_contact_at'] ?? '') : '',
                'target_last_contact_at' => $target_env !== null ? (string) ($target_env['last_contact_at'] ?? '') : '',
                'source_last_seen_at' => $source_env !== null ? (string) ($source_env['last_seen_at'] ?? '') : '',
                'target_last_seen_at' => $target_env !== null ? (string) ($target_env['last_seen_at'] ?? '') : '',
                'latest_report' => $latest,
            ],
            'items' => $open,
            'handled' => $handled,
            'labels' => self::LABEL_TEXT,
            'run' => ! empty($options['report_uid']) ? ['report_uid' => (string) $options['report_uid'], 'snapshot' => (bool) $snapshot] : null,
        ];
    }

    /**
     * @param int $open
     * @param int $needs_look
     * @return string
     */
    private function summary_text($open, $needs_look)
    {
        if ($open === 0) {
            return 'Nothing new.';
        }
        $text = sprintf('%d change%s on staging.', $open, $open === 1 ? '' : 's');
        if ($needs_look > 0) {
            $text .= sprintf(' %d need%s a look.', $needs_look, $needs_look === 1 ? 's' : '');
        }
        if ($open - $needs_look > 0) {
            $text .= sprintf(' %d just so you know.', $open - $needs_look);
        }

        return $text;
    }

    /**
     * Markdown rendering of an inbox (the "Copy as Markdown" / export shape).
     *
     * @param array<string, mixed> $inbox
     * @return string
     */
    public static function render_markdown(array $inbox)
    {
        $lines = ['# Change report · ' . $inbox['source'] . ' → ' . $inbox['target'], ''];
        $lines[] = $inbox['summary']['text'] . ($inbox['summary']['since'] !== '' ? ' Last check ' . $inbox['summary']['since'] . '.' : '');
        $groups = [];
        foreach ($inbox['items'] as $item) {
            $groups[$item['label']][] = $item;
        }
        foreach (self::LABEL_TEXT as $label => $text) {
            if (empty($groups[$label])) {
                continue;
            }
            $lines[] = '';
            $lines[] = '## ' . $text . ' (' . count($groups[$label]) . ')';
            foreach ($groups[$label] as $item) {
                $name = $item['display_name'] !== '' ? $item['display_name'] : $item['instance_uid'];
                $review = ! empty($item['design_review']) ? ' · Design Review: ' . DesignReviewAnnotation::describe($item['design_review']) : '';
                $lines[] = '- **' . $name . '** (' . $item['domain'] . ') · ' . $item['path_count'] . ' path' . ($item['path_count'] === 1 ? '' : 's') . ' · ' . $item['changed_at'] . $review;
                foreach (array_slice($item['paths'], 0, 10) as $path) {
                    $before = isset($path['before_value']) ? (string) $path['before_value'] : null;
                    $after = isset($path['after_value']) ? (string) $path['after_value'] : null;
                    $values = $before !== null || $after !== null ? ': ' . ($before ?? '?') . ' → ' . ($after ?? '?') : '';
                    $lines[] = '  - ' . (string) $path['path'] . ' (' . (string) $path['operation'] . ')' . $values;
                }
                if (count($item['paths']) > 10) {
                    $lines[] = '  - … ' . (count($item['paths']) - 10) . ' more';
                }
            }
        }
        if (! empty($inbox['handled'])) {
            $lines[] = '';
            $lines[] = '## Handled (' . count($inbox['handled']) . ')';
            foreach ($inbox['handled'] as $item) {
                $name = $item['display_name'] !== '' ? $item['display_name'] : $item['instance_uid'];
                $decision = $item['decision'] !== null ? $item['decision']['decision'] . ' · ' . $item['decision']['verify_state'] : $item['label'];
                $lines[] = '- ' . $name . ' (' . $item['domain'] . ') · ' . $decision;
            }
        }
        if (! empty($inbox['design_review'])) {
            $lines = array_merge($lines, DesignReviewAnnotation::markdown_lines($inbox['design_review']));
        }

        return implode("\n", $lines) . "\n";
    }

    // ---- decide -----------------------------------------------------------

    /**
     * Record a decision for one or more open items (approver write).
     *
     * @param array<string, mixed> $args source, target, items (list of "domain:instance_uid" or {domain, instance_uid}),
     *                                   decision reconciled|ignored|snoozed|clear, note, rebaseline (bool, conflicts only), snooze_seconds
     * @return array<string, mixed>|\WP_Error
     */
    public function decide(array $args)
    {
        $source = (string) ($args['source'] ?? '');
        $target = (string) ($args['target'] ?? '');
        $decision = (string) ($args['decision'] ?? '');
        if (! in_array($decision, [ChangeReportStore::DECISION_RECONCILED, ChangeReportStore::DECISION_IGNORED, ChangeReportStore::DECISION_SNOOZED, 'clear'], true)) {
            return new \WP_Error('dbvc_agency_invalid_decision', 'decision must be reconciled, ignored, snoozed or clear.', ['status' => 400]);
        }
        $items = self::parse_items($args['items'] ?? []);
        if ($items === []) {
            return new \WP_Error('dbvc_agency_invalid_selection', 'Name at least one item as domain:instance_uid.', ['status' => 400]);
        }
        $rebaseline = ! empty($args['rebaseline']);
        $note = (string) ($args['note'] ?? '');
        $snooze = max(60, (int) ($args['snooze_seconds'] ?? self::SNOOZE_SECONDS));
        $results = [];
        foreach ($items as $ref) {
            $item = $this->store->item($source, $target, $ref['domain'], $ref['instance_uid']);
            if ($item === null) {
                $results[] = ['domain' => $ref['domain'], 'instance_uid' => $ref['instance_uid'], 'result' => 'not_found'];
                continue;
            }
            if ($decision === 'clear') {
                $this->store->delete_decision($source, $target, $item['domain'], $item['instance_uid']);
                $this->store->set_item_resolved((int) $item['item_id'], false);
                $results[] = ['domain' => $item['domain'], 'instance_uid' => $item['instance_uid'], 'result' => 'cleared'];
                continue;
            }
            $row = [
                'source_environment_id' => $source,
                'target_environment_id' => $target,
                'domain' => $item['domain'],
                'instance_uid' => $item['instance_uid'],
                'decision' => $decision,
                'decided_source_hash' => $item['source_hash'],
                'decided_target_hash' => $item['target_hash'],
                'decided_by' => get_current_user_id(),
                'note' => $note,
                'verify_state' => $decision === ChangeReportStore::DECISION_IGNORED ? ChangeReportStore::VERIFY_MUTED : ($decision === ChangeReportStore::DECISION_SNOOZED ? ChangeReportStore::VERIFY_SNOOZED : ChangeReportStore::VERIFY_PENDING),
                'snoozed_until' => $decision === ChangeReportStore::DECISION_SNOOZED ? gmdate('Y-m-d H:i:s', time() + $snooze) : null,
                'rebaseline_object' => false,
            ];
            $rebaselined = null;
            // CR-M (b)/(i): a per-object baseline confirm only for a conflict ("Sorted — they match now").
            // The sides usually still differ when the button is pressed (LocalWP reports its edit
            // within the next poll), so the flag is kept and the confirm lands at verification.
            if ($rebaseline && $decision === ChangeReportStore::DECISION_RECONCILED && $item['label'] === self::LABEL_CONFLICT) {
                $confirmed = (new BaselineService())->confirm($source, $target, $item['domain'], $item['instance_uid'], 'report:sorted');
                $rebaselined = is_wp_error($confirmed) ? $confirmed->get_error_code() : (int) ($confirmed['confirmed'] ?? 0);
                $row['rebaseline_object'] = true;
            }
            $stored = $this->store->upsert_decision($row);
            $this->store->set_item_resolved((int) $item['item_id'], true);
            $results[] = ['domain' => $item['domain'], 'instance_uid' => $item['instance_uid'], 'result' => 'decided', 'decision' => $stored, 'rebaselined' => $rebaselined];
        }

        return ['source' => $source, 'target' => $target, 'decision' => $decision, 'items' => $results];
    }

    /**
     * Promote open items into a real release (publisher write): the existing
     * prepare → approve → guarded-apply pipeline takes it from there.
     *
     * @param array<string, mixed> $args source, target, items, note
     * @return array<string, mixed>|\WP_Error
     */
    public function promote(array $args)
    {
        $source = (string) ($args['source'] ?? '');
        $target = (string) ($args['target'] ?? '');
        $items = self::parse_items($args['items'] ?? []);
        if ($items === []) {
            return new \WP_Error('dbvc_agency_invalid_selection', 'Name at least one item as domain:instance_uid.', ['status' => 400]);
        }
        $selections = [];
        $found = [];
        foreach ($items as $ref) {
            $item = $this->store->item($source, $target, $ref['domain'], $ref['instance_uid']);
            if ($item === null || $item['operation'] === '') {
                return new \WP_Error('dbvc_agency_invalid_selection', sprintf('%s:%s is not a transported report item.', $ref['domain'], $ref['instance_uid']), ['status' => 400]);
            }
            $selections[] = ['domain' => $item['domain'], 'instance_uid' => $item['instance_uid'], 'operation' => $item['operation']];
            $found[] = $item;
        }
        $created = (new ReleaseService())->create($source, $selections, (string) ($args['note'] ?? ('report-promote:' . $source . '->' . $target)));
        if (is_wp_error($created)) {
            return $created;
        }
        foreach ($found as $item) {
            $this->store->upsert_decision([
                'source_environment_id' => $source,
                'target_environment_id' => $target,
                'domain' => $item['domain'],
                'instance_uid' => $item['instance_uid'],
                'decision' => ChangeReportStore::DECISION_PROMOTED,
                'decided_source_hash' => $item['source_hash'],
                'decided_target_hash' => $item['target_hash'],
                'decided_by' => get_current_user_id(),
                'note' => (string) ($args['note'] ?? ''),
                'verify_state' => ChangeReportStore::VERIFY_PENDING,
                'release_uid' => (string) $created['release']['release_uid'],
            ]);
            $this->store->set_item_resolved((int) $item['item_id'], true);
        }

        return ['source' => $source, 'target' => $target, 'release' => $created['release'], 'promoted' => count($found), 'note' => 'The release follows the existing prepare → approve → apply pipeline; approve it from Releases.'];
    }

    /**
     * @param mixed $raw "a:b,c:d" | ["a:b", ...] | [{domain, instance_uid}, ...]
     * @return array<int, array{domain: string, instance_uid: string}>
     */
    public static function parse_items($raw)
    {
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)), 'strlen');
        }
        $out = [];
        foreach ((array) $raw as $entry) {
            if (is_array($entry) && isset($entry['domain'], $entry['instance_uid'])) {
                $out[] = ['domain' => (string) $entry['domain'], 'instance_uid' => (string) $entry['instance_uid']];
                continue;
            }
            if (! is_string($entry)) {
                continue;
            }
            // Domains carry colons (`wp.bricks:components`); the instance uid is the LAST segment.
            $pos = strrpos($entry, ':');
            if ($pos === false || $pos === 0 || $pos === strlen($entry) - 1) {
                continue;
            }
            $out[] = ['domain' => substr($entry, 0, $pos), 'instance_uid' => substr($entry, $pos + 1)];
        }

        return $out;
    }

    // ---- retention --------------------------------------------------------

    /**
     * Purge report-purpose releases (release, items, preparations) older than
     * the retention window whose report row exists, then reclaim orphan media.
     *
     * @param array<string, mixed> $args days (default REPORT_RELEASE_RETENTION_DAYS), dry_run
     * @return array<string, mixed>
     */
    public function purge(array $args = [])
    {
        $days = max(0, (int) ($args['days'] ?? self::REPORT_RELEASE_RETENTION_DAYS));
        $dry_run = ! empty($args['dry_run']);
        $before = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $releases = new ReleaseStore();
        $preparations = new PreparationStore();
        $purgeable = [];
        $kept = 0;
        foreach ($releases->report_releases_before($before, 500) as $release) {
            if ($release['state'] !== ReleaseStore::STATE_WITHDRAWN && ! $this->store->report_exists_for_release((string) $release['release_uid'])) {
                // The cycle never completed into a report: keep the evidence until it does or is withdrawn.
                $kept++;
                continue;
            }
            $purgeable[] = $release;
        }
        $purged = 0;
        $preparations_deleted = 0;
        if (! $dry_run) {
            foreach ($purgeable as $release) {
                $preparations_deleted += $preparations->delete_for_release((string) $release['release_uid']);
                if ($releases->delete((int) $release['release_id'])) {
                    $purged++;
                }
            }
        }
        $gc = ! $dry_run && $purged > 0 ? MediaBlobStore::gc() : null;

        return [
            'dry_run' => $dry_run,
            'older_than_days' => $days,
            'before' => $before,
            'purgeable' => count($purgeable),
            'purged' => $purged,
            'preparations_deleted' => $preparations_deleted,
            'kept_without_report' => $kept,
            'media_gc' => $gc,
            'release_uids' => array_map(static function ($release) {
                return (string) $release['release_uid'];
            }, $purgeable),
        ];
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * A human name for an object from its canonical body: post title, Bricks
     * name/label, then id, then the uid.
     *
     * @param string               $domain
     * @param array<string, mixed> $body
     * @param string               $instance_uid
     * @return string
     */
    /**
     * Whether a comparison row is one the policy watches: inside its domain scope,
     * inside its include list (when set) and outside its exclude list. Same key
     * rule as SyncDriver::select_objects_report (source uid, else target uid).
     *
     * @param array<string, mixed> $policy
     * @param string               $domain
     * @param string               $uid
     * @return bool
     */
    public static function in_policy_scope(array $policy, $domain, $uid)
    {
        $scope = array_values(array_map('strval', (array) ($policy['scope_domains'] ?? [])));
        if ($scope !== [] && ! in_array((string) $domain, $scope, true)) {
            return false;
        }
        $include = array_values(array_map('strval', (array) ($policy['include_uids'] ?? [])));
        if ($include !== [] && ! in_array((string) $uid, $include, true)) {
            return false;
        }

        return ! in_array((string) $uid, array_map('strval', (array) ($policy['exclude_uids'] ?? [])), true);
    }

    /**
     * The name an environment's own projection carries for an object (the Slice 0
     * `match_key`: name / token / slug), or '' when it has none.
     *
     * @param string $environment_id
     * @param string $domain
     * @param string $instance_uid
     * @param string $profile
     * @return string
     */
    private function projection_name($environment_id, $domain, $instance_uid, $profile)
    {
        if ($instance_uid === '') {
            return '';
        }
        $env = (new EnvironmentRegistry())->find((string) $environment_id);
        if (! is_array($env)) {
            return '';
        }
        $projection = (new \Dbvc\AgencyControl\Storage\ProjectionStore())->get((string) $environment_id, (string) $env['current_epoch'], (string) $domain, (string) $instance_uid, (string) $profile);

        return is_array($projection) ? trim((string) ($projection['match_key'] ?? '')) : '';
    }

    public static function display_name($domain, array $body, $instance_uid)
    {
        foreach (['post_title', 'label', 'name', 'title', 'id'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key]) && trim((string) $body[$key]) !== '') {
                return trim((string) $body[$key]);
            }
        }
        unset($domain);

        return (string) $instance_uid;
    }
}
