<?php

namespace Dbvc\AgencyControl\Framework;

use Dbvc\AgencyControl\Enrollment\SegmentService;
use Dbvc\AgencyControl\Release\ReleaseService;
use Dbvc\AgencyControl\Release\RolloutService;
use Dbvc\AgencyControl\Storage\DefinitionStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\FrameworkRolloutStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\RolloutStore;

/**
 * Framework rollout EXECUTION (M9 slice 4c) — the engine behind "publish once,
 * lands everywhere". It turns a published framework version + a target set into
 * actually-applied changes on the fleet, honouring each target's approved
 * overrides, through the existing M5 release + M6 rollout pipeline. No new write
 * path.
 *
 * Because whether an object is a create or a replace, and whether it is locked,
 * is decided PER TARGET, each target gets its OWN override-aware release +
 * single-target rollout — mirroring {@see \Dbvc\AgencyControl\Parity\SyncDriver}'s
 * proven per-pair cycle. {@see FrameworkPublishService::plan()} supplies the
 * per-target create/replace/skip decisions; a locked object is never in the
 * release, so it is never overwritten.
 *
 * The golden source seals its own release (the hub never seals), so this is an
 * async driver: {@see start()} enrols the targets, then {@see advance()} is a
 * re-entrant tick that carries each target through build → seal → rollout →
 * verify. Advance is idempotent and safe to call repeatedly (by an operator or,
 * later, a scheduler).
 *
 * Scope (4c): a campaign stays within ONE agency AND client — the M6
 * release/prepare/rollout path enforces same-client, and a framework object
 * only rolls out to a target sharing the golden source's client. Cross-client
 * ("agency scope") fleet distribution is a separate, opt-in follow-up.
 */
final class FrameworkRolloutService
{
    /**
     * @var FrameworkRolloutStore
     */
    private $store;

    /**
     * @var FrameworkPublishService
     */
    private $publish;

    /**
     * @var DefinitionStore
     */
    private $definitions;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    /**
     * @var ProjectionStore
     */
    private $projections;

    public function __construct()
    {
        $this->store = new FrameworkRolloutStore();
        $this->publish = new FrameworkPublishService();
        $this->definitions = new DefinitionStore();
        $this->environments = new EnvironmentRegistry();
        $this->projections = new ProjectionStore();
    }

    /**
     * Enrol a fleet of targets under a new campaign for a published framework
     * version. Builds nothing yet — {@see advance()} drives each target.
     *
     * @param array<string, mixed> $args agency_id, definitions (array|csv), channel, targets (array|csv), note
     * @return array<string, mixed>|\WP_Error
     */
    public function start(array $args)
    {
        $agency_id = (string) ($args['agency_id'] ?? 'studio');
        $channel = (string) ($args['channel'] ?? '');
        $channel = $channel !== '' ? $channel : 'stable';
        $note = (string) ($args['note'] ?? '');
        $definition_uids = $this->id_list($args['definitions'] ?? []);
        if ($definition_uids === []) {
            $definition_uids = $this->desired_definition_uids($agency_id, $channel);
        }
        if ($definition_uids === []) {
            return new \WP_Error('dbvc_agency_no_definitions', 'Name at least one definition with a desired version to roll out.', ['status' => 400]);
        }

        // Every definition must resolve to a desired version sharing ONE golden
        // source; a hash-only definition (no source) cannot be delivered.
        $source_environment_id = '';
        foreach ($definition_uids as $definition_uid) {
            $desired = $this->definitions->desired($agency_id, $definition_uid, $channel);
            if ($desired === null) {
                return new \WP_Error('dbvc_agency_no_desired_version', 'No desired version for definition ' . $definition_uid . ' on channel ' . $channel . '.', ['status' => 409]);
            }
            $source = (string) $desired['source_environment_id'];
            if ($source === '' || (string) $desired['source_instance_uid'] === '') {
                return new \WP_Error('dbvc_agency_definition_has_no_source', 'Definition ' . $definition_uid . ' was published from a hash, not a source environment, so it cannot be rolled out.', ['status' => 409]);
            }
            if ($source_environment_id === '') {
                $source_environment_id = $source;
            } elseif ($source_environment_id !== $source) {
                return new \WP_Error('dbvc_agency_multiple_sources', 'A campaign rolls out one golden source; these definitions name different source environments.', ['status' => 409]);
            }
        }

        $source_env = $this->environments->find($source_environment_id);
        if ($source_env === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Golden source environment not found.', ['status' => 404]);
        }
        if ($source_env['agency_id'] !== $agency_id) {
            return new \WP_Error('dbvc_agency_scope_mismatch', 'The golden source belongs to another agency.', ['status' => 403]);
        }
        if ($source_env['status'] !== EnvironmentRegistry::STATUS_ENABLED) {
            return new \WP_Error('dbvc_agency_environment_not_enabled', 'The golden source is not enabled.', ['status' => 409]);
        }
        $client_id = (string) $source_env['client_id'];

        // Target set: ordered cohorts (a staged rollout — canary first, then the
        // rest, pausing the fleet if a cohort fails), an explicit flat list, a
        // segment (every same-agency site carrying a tag, across clients), or
        // every subscriber the plan resolves (one cohort). Precedence:
        // cohorts > targets > segment > all subscribers.
        $ordered = $this->ordered_cohorts($args['cohorts'] ?? null);
        if ($ordered === null) {
            return new \WP_Error('dbvc_agency_rollout_duplicate_target', 'A target may appear in only one cohort.', ['status' => 400]);
        }
        if ($ordered === []) {
            $requested = $this->id_list($args['targets'] ?? []);
            $segment = (string) ($args['segment'] ?? '');
            if ($requested === [] && $segment !== '') {
                $requested = (new SegmentService())->environments_for_segment($segment, $agency_id);
                if ($requested === []) {
                    return new \WP_Error('dbvc_agency_empty_segment', 'No enrolled site carries the segment tag: ' . $segment, ['status' => 409]);
                }
            }
            if ($requested === []) {
                $plan = $this->publish->plan(['agency_id' => $agency_id, 'definitions' => $definition_uids]);
                if (is_wp_error($plan)) {
                    return $plan;
                }
                foreach ($plan['targets'] as $target) {
                    $requested[] = (string) $target['environment_id'];
                }
                $requested = array_values(array_unique($requested));
            }
            $ordered = [$requested];
        }

        // Agency scope (4d): a framework rollout reaches every enrolled site
        // under this agency, across its clients. It never crosses agencies (a
        // different-agency target is refused). The rollout carries scope=agency
        // so the release/prepare path allows a target in another client of the
        // same agency; parity (SyncDriver) keeps the default client scope.
        $targets = [];
        $seen = [];
        $skipped_other_agency = [];
        $skipped_unavailable = [];
        foreach ($ordered as $cohort_index => $ids) {
            $position = 0;
            foreach ($ids as $env_id) {
                if ($env_id === $source_environment_id || isset($seen[$env_id])) {
                    continue;
                }
                $seen[$env_id] = true;
                $env = $this->environments->find($env_id);
                if ($env === null || $env['status'] === EnvironmentRegistry::STATUS_REVOKED) {
                    $skipped_unavailable[] = $env_id;
                    continue;
                }
                if ($env['agency_id'] !== $agency_id) {
                    $skipped_other_agency[] = $env_id;
                    continue;
                }
                $targets[] = ['target_environment_id' => $env_id, 'cohort' => (int) $cohort_index, 'position' => $position];
                $position++;
            }
        }
        if ($targets === []) {
            return new \WP_Error('dbvc_agency_no_eligible_targets', 'No enrolled target under this agency to roll out to.', ['status' => 409, 'skipped_other_agency' => $skipped_other_agency]);
        }

        $campaign_uid = 'fwr-' . bin2hex(random_bytes(8));
        $created = $this->store->create([
            'campaign_uid' => $campaign_uid,
            'agency_id' => $agency_id,
            'client_id' => $client_id,
            'source_environment_id' => $source_environment_id,
            'version' => $this->campaign_version($agency_id, $definition_uids, $channel),
            'channel' => $channel,
            'definition_uids' => $definition_uids,
            'note' => $note,
            'created_by' => get_current_user_id(),
        ], $targets);
        if ($created === null) {
            return new \WP_Error('dbvc_agency_campaign_error', 'The campaign could not be recorded.', ['status' => 500]);
        }

        $status = $this->status($campaign_uid);
        if (is_array($status)) {
            $status['skipped_other_agency'] = $skipped_other_agency;
            $status['skipped_unavailable'] = $skipped_unavailable;
        }

        return $status;
    }

    /**
     * Re-entrant driver tick. Advances the active cohort one step at a time
     * (build → seal → rollout → verify), opening the next cohort only once the
     * current one has fully succeeded (canary-first staging) and PAUSING the
     * whole campaign if any target in the active cohort fails — later cohorts
     * are never touched while an earlier one has a failure. Idempotent.
     *
     * @param string $campaign_uid
     * @return array<string, mixed>|\WP_Error
     */
    public function advance($campaign_uid)
    {
        $campaign = $this->store->find((string) $campaign_uid);
        if ($campaign === null) {
            return new \WP_Error('dbvc_agency_campaign_not_found', 'Campaign not found.', ['status' => 404]);
        }
        if ($campaign['state'] !== FrameworkRolloutStore::STATE_RUNNING) {
            return $this->status($campaign_uid);
        }

        $agency_id = (string) $campaign['agency_id'];
        $channel = (string) $campaign['channel'];
        $source_environment_id = (string) $campaign['source_environment_id'];
        $definition_uids = (array) $campaign['definition_uids'];

        // Deliverable object set: each definition's desired version whose golden
        // source STILL reports the published hash (never roll out a source that
        // drifted from what was published). Keyed by definition_uid.
        $source_env = $this->environments->find($source_environment_id);
        $deliverable = $source_env !== null ? $this->deliverable_objects($source_env, $agency_id, $definition_uids, $channel) : [];

        // One plan pass over all this campaign's targets.
        $env_ids = [];
        foreach ($this->store->targets($campaign_uid) as $row) {
            $env_ids[] = (string) $row['target_environment_id'];
        }
        $plan = $this->publish->plan(['agency_id' => $agency_id, 'definitions' => $definition_uids, 'cohorts' => $env_ids]);
        $plan_by_env = [];
        if (! is_wp_error($plan)) {
            foreach ($plan['targets'] as $target) {
                $plan_by_env[(string) $target['environment_id']] = $target;
            }
        }

        // Walk cohorts in order: skip finished ones, step the first unfinished
        // one, and stop as soon as one is still in flight or has failed.
        $new_state = FrameworkRolloutStore::STATE_COMPLETED;
        foreach ($this->cohort_indices($campaign_uid) as $cohort) {
            $status = $this->cohort_status($campaign_uid, $cohort);
            if ($status['all_success']) {
                continue; // cohort done — open the next.
            }
            if ($status['any_failed']) {
                $new_state = FrameworkRolloutStore::STATE_PAUSED;
                break;
            }
            foreach ($this->cohort_rows($campaign_uid, $cohort) as $row) {
                if (! $this->is_terminal($row['state'])) {
                    $this->advance_target($row, $plan_by_env, $deliverable, $campaign);
                }
            }
            $after = $this->cohort_status($campaign_uid, $cohort);
            if ($after['any_failed']) {
                $new_state = FrameworkRolloutStore::STATE_PAUSED;
                break;
            }
            if ($after['all_success']) {
                continue; // resolved this pass (e.g. all skipped) — open the next.
            }
            $new_state = FrameworkRolloutStore::STATE_RUNNING; // still in flight — wait.
            break;
        }
        if ($new_state !== $campaign['state']) {
            $this->store->update_campaign($campaign_uid, ['state' => $new_state]);
            // M9 slice 7d: notify on a terminal/paused transition (best-effort; a
            // notification failure never affects the rollout). RUNNING is in-flight.
            if ($new_state !== FrameworkRolloutStore::STATE_RUNNING) {
                try {
                    (new \Dbvc\AgencyControl\Notifications\NotificationService())->enqueue(
                        (string) ($campaign['agency_id'] ?? ''),
                        'framework_rollout.' . $new_state,
                        $campaign_uid . ':' . $new_state,
                        ['campaign_uid' => $campaign_uid, 'state' => $new_state, 'definition_uid' => (string) ($campaign['definition_uid'] ?? ''), 'version' => (string) ($campaign['version'] ?? '')]
                    );
                } catch (\Throwable $e) {
                    unset($e);
                }
            }
        }

        return $this->status($campaign_uid);
    }

    /**
     * Resume a paused campaign (after the operator retries or accepts the failed
     * targets). A campaign that still has a failed target in its active cohort
     * simply pauses again on the next step.
     *
     * @param string $campaign_uid
     * @return array<string, mixed>|\WP_Error
     */
    public function resume($campaign_uid)
    {
        $campaign = $this->store->find((string) $campaign_uid);
        if ($campaign === null) {
            return new \WP_Error('dbvc_agency_campaign_not_found', 'Campaign not found.', ['status' => 404]);
        }
        if ($campaign['state'] !== FrameworkRolloutStore::STATE_PAUSED) {
            return $this->status($campaign_uid);
        }
        $this->store->update_campaign($campaign_uid, ['state' => FrameworkRolloutStore::STATE_RUNNING]);

        return $this->advance($campaign_uid);
    }

    /**
     * Reset a failed target to pending so the driver re-attempts it. The rollout
     * a previous attempt created (if any) is withdrawn first.
     *
     * @param string $campaign_uid
     * @param string $target_environment_id
     * @return array<string, mixed>|\WP_Error
     */
    public function retry($campaign_uid, $target_environment_id)
    {
        $campaign = $this->store->find((string) $campaign_uid);
        if ($campaign === null) {
            return new \WP_Error('dbvc_agency_campaign_not_found', 'Campaign not found.', ['status' => 404]);
        }
        $found = null;
        foreach ($this->store->targets($campaign_uid) as $row) {
            if ($row['target_environment_id'] === (string) $target_environment_id) {
                $found = $row;
                break;
            }
        }
        if ($found === null) {
            return new \WP_Error('dbvc_agency_target_not_found', 'Target not found in this campaign.', ['status' => 404]);
        }
        if ($found['state'] !== FrameworkRolloutStore::TARGET_FAILED) {
            return ['campaign_uid' => (string) $campaign_uid, 'target_environment_id' => (string) $target_environment_id, 'state' => (string) $found['state'], 'changed' => false];
        }
        if ((string) $found['rollout_uid'] !== '') {
            (new RolloutService())->withdraw((string) $found['rollout_uid']);
        }
        $this->store->update_target((int) $found['framework_rollout_target_id'], ['state' => FrameworkRolloutStore::TARGET_PENDING, 'release_uid' => '', 'rollout_uid' => '', 'detail' => 'retry']);

        return ['campaign_uid' => (string) $campaign_uid, 'target_environment_id' => (string) $target_environment_id, 'state' => FrameworkRolloutStore::TARGET_PENDING, 'changed' => true];
    }

    /**
     * @param string $campaign_uid
     * @return array<string, mixed>|\WP_Error
     */
    public function status($campaign_uid)
    {
        $campaign = $this->store->find((string) $campaign_uid);
        if ($campaign === null) {
            return new \WP_Error('dbvc_agency_campaign_not_found', 'Campaign not found.', ['status' => 404]);
        }
        $targets = [];
        $summary = ['targets' => 0, 'pending' => 0, 'release_open' => 0, 'awaiting_payloads' => 0, 'rollout_running' => 0, 'succeeded' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($this->store->targets($campaign_uid) as $row) {
            $summary['targets']++;
            if (array_key_exists($row['state'], $summary)) {
                $summary[$row['state']]++;
            }
            $targets[] = [
                'target_environment_id' => (string) $row['target_environment_id'],
                'cohort' => (int) $row['cohort'],
                'state' => (string) $row['state'],
                'release_uid' => (string) $row['release_uid'],
                'rollout_uid' => (string) $row['rollout_uid'],
                'item_count' => (int) $row['item_count'],
                'detail' => (string) $row['detail'],
            ];
        }

        return [
            'campaign_uid' => (string) $campaign['campaign_uid'],
            'agency_id' => (string) $campaign['agency_id'],
            'client_id' => (string) $campaign['client_id'],
            'source_environment_id' => (string) $campaign['source_environment_id'],
            'version' => (string) $campaign['version'],
            'channel' => (string) $campaign['channel'],
            'definitions' => (array) $campaign['definition_uids'],
            'state' => (string) $campaign['state'],
            'note' => (string) $campaign['note'],
            'created_at' => (string) $campaign['created_at'],
            'updated_at' => (string) $campaign['updated_at'],
            'summary' => $summary,
            'targets' => $targets,
        ];
    }

    /**
     * @param string|null $agency_id
     * @param int         $limit
     * @return array<string, mixed>
     */
    public function all($agency_id = null, $limit = 100)
    {
        $rows = [];
        foreach ($this->store->all($agency_id, $limit) as $campaign) {
            $counts = ['succeeded' => 0, 'failed' => 0, 'skipped' => 0, 'in_flight' => 0];
            foreach ($this->store->targets($campaign['campaign_uid']) as $target) {
                if ($target['state'] === FrameworkRolloutStore::TARGET_SUCCEEDED) {
                    $counts['succeeded']++;
                } elseif ($target['state'] === FrameworkRolloutStore::TARGET_FAILED) {
                    $counts['failed']++;
                } elseif ($target['state'] === FrameworkRolloutStore::TARGET_SKIPPED) {
                    $counts['skipped']++;
                } else {
                    $counts['in_flight']++;
                }
            }
            $rows[] = [
                'campaign_uid' => (string) $campaign['campaign_uid'],
                'version' => (string) $campaign['version'],
                'source_environment_id' => (string) $campaign['source_environment_id'],
                'state' => (string) $campaign['state'],
                'target_count' => (int) $campaign['target_count'],
                'progress' => $counts,
                'created_at' => (string) $campaign['created_at'],
                'updated_at' => (string) $campaign['updated_at'],
            ];
        }

        return ['returned' => count($rows), 'campaigns' => $rows];
    }

    /**
     * Withdraw a running campaign: withdraw each target's in-flight release or
     * rollout and mark the campaign withdrawn. Applied targets are left as they
     * are (a landed change is not rolled back here — use reviewed rollback).
     *
     * @param string $campaign_uid
     * @return array<string, mixed>|\WP_Error
     */
    public function withdraw($campaign_uid)
    {
        $campaign = $this->store->find((string) $campaign_uid);
        if ($campaign === null) {
            return new \WP_Error('dbvc_agency_campaign_not_found', 'Campaign not found.', ['status' => 404]);
        }
        if ($campaign['state'] !== FrameworkRolloutStore::STATE_RUNNING) {
            return ['campaign_uid' => (string) $campaign_uid, 'state' => (string) $campaign['state'], 'changed' => false];
        }
        foreach ($this->store->targets($campaign_uid) as $row) {
            if (in_array($row['state'], [FrameworkRolloutStore::TARGET_SUCCEEDED, FrameworkRolloutStore::TARGET_SKIPPED, FrameworkRolloutStore::TARGET_FAILED], true)) {
                continue;
            }
            if ((string) $row['rollout_uid'] !== '') {
                (new RolloutService())->withdraw((string) $row['rollout_uid']);
            } elseif ((string) $row['release_uid'] !== '') {
                (new ReleaseService())->withdraw((string) $row['release_uid']);
            }
            $this->store->update_target((int) $row['framework_rollout_target_id'], ['state' => FrameworkRolloutStore::TARGET_FAILED, 'detail' => 'withdrawn']);
        }
        $this->store->update_campaign($campaign_uid, ['state' => FrameworkRolloutStore::STATE_WITHDRAWN]);

        return ['campaign_uid' => (string) $campaign_uid, 'state' => FrameworkRolloutStore::STATE_WITHDRAWN, 'changed' => true];
    }

    /**
     * Move one target one step. Persists its new state.
     *
     * @param array<string, mixed>             $row
     * @param array<string, array<string, mixed>> $plan_by_env
     * @param array<string, array<string, mixed>> $deliverable Keyed by definition_uid.
     * @param array<string, mixed>             $campaign
     * @return void
     */
    private function advance_target(array $row, array $plan_by_env, array $deliverable, array $campaign)
    {
        $id = (int) $row['framework_rollout_target_id'];
        $env_id = (string) $row['target_environment_id'];
        $state = (string) $row['state'];

        if ($state === FrameworkRolloutStore::TARGET_PENDING) {
            $selections = $this->selections_for($plan_by_env[$env_id] ?? null, $deliverable);
            if ($selections === []) {
                $detail = isset($plan_by_env[$env_id]) ? (string) $plan_by_env[$env_id]['state'] : 'no_subscription';
                $this->store->update_target($id, ['state' => FrameworkRolloutStore::TARGET_SKIPPED, 'detail' => $detail]);

                return;
            }
            $release = (new ReleaseService())->create((string) $campaign['source_environment_id'], $selections, 'fw:' . $campaign['version'] . ':' . $env_id);
            if (is_wp_error($release)) {
                $this->store->update_target($id, ['state' => FrameworkRolloutStore::TARGET_FAILED, 'detail' => 'release:' . $release->get_error_code()]);

                return;
            }
            $this->store->update_target($id, ['state' => FrameworkRolloutStore::TARGET_RELEASE_OPEN, 'release_uid' => (string) $release['release']['release_uid'], 'item_count' => count($selections), 'detail' => '']);

            return;
        }

        if ($state === FrameworkRolloutStore::TARGET_RELEASE_OPEN || $state === FrameworkRolloutStore::TARGET_AWAITING_PAYLOADS) {
            $release = (new ReleaseStore())->find((string) $row['release_uid']);
            if ($release === null || $release['state'] === ReleaseStore::STATE_WITHDRAWN) {
                $this->store->update_target($id, ['state' => FrameworkRolloutStore::TARGET_FAILED, 'detail' => 'release_gone']);

                return;
            }
            if ($release['state'] !== ReleaseStore::STATE_SEALED) {
                $this->store->update_target($id, ['state' => FrameworkRolloutStore::TARGET_AWAITING_PAYLOADS, 'detail' => 'awaiting_payloads']);

                return;
            }
            $rollout = (new RolloutService())->create((string) $row['release_uid'], [[$env_id]], 'fw:' . $campaign['version'] . ':' . $env_id, RolloutService::SCOPE_AGENCY);
            if (is_wp_error($rollout)) {
                $this->store->update_target($id, ['state' => FrameworkRolloutStore::TARGET_FAILED, 'detail' => 'rollout:' . $rollout->get_error_code()]);

                return;
            }
            $rollout_uid = (string) $rollout['rollout']['rollout_uid'];
            (new RolloutService())->advance($rollout_uid);
            $this->store->update_target($id, ['state' => FrameworkRolloutStore::TARGET_ROLLOUT_RUNNING, 'rollout_uid' => $rollout_uid, 'detail' => '']);

            return;
        }

        if ($state === FrameworkRolloutStore::TARGET_ROLLOUT_RUNNING) {
            $status = (new RolloutService())->advance((string) $row['rollout_uid']);
            if (is_wp_error($status)) {
                $this->store->update_target($id, ['state' => FrameworkRolloutStore::TARGET_FAILED, 'detail' => 'rollout_missing']);

                return;
            }
            $rollout_state = (string) $status['rollout']['state'];
            if ($rollout_state === RolloutStore::STATE_COMPLETED) {
                $this->store->update_target($id, ['state' => FrameworkRolloutStore::TARGET_SUCCEEDED, 'detail' => '']);
            } elseif ($rollout_state === RolloutStore::STATE_FAILED || $rollout_state === RolloutStore::STATE_PAUSED || $rollout_state === RolloutStore::STATE_WITHDRAWN) {
                $this->store->update_target($id, ['state' => FrameworkRolloutStore::TARGET_FAILED, 'detail' => 'rollout_' . $rollout_state]);
            }
            // still running → leave as is.
        }
    }

    /**
     * Build release selections from a target's plan, using the SOURCE instance
     * uid of each deliverable object. A locked object never appears in the plan
     * with an operation, so it is never selected.
     *
     * @param array<string, mixed>|null           $plan_target
     * @param array<string, array<string, mixed>> $deliverable Keyed by definition_uid.
     * @return array<int, array<string, string>>
     */
    private function selections_for($plan_target, array $deliverable)
    {
        if ($plan_target === null) {
            return [];
        }
        $selections = [];
        $seen = [];
        foreach ((array) $plan_target['items'] as $item) {
            if (! isset($item['operation'])) {
                continue; // skip_override / up_to_date / blocked / attention.
            }
            $definition_uid = (string) $item['definition_uid'];
            if (! isset($deliverable[$definition_uid])) {
                continue; // source drifted from the published hash, or no source.
            }
            $object = $deliverable[$definition_uid];
            $key = $object['domain'] . '|' . $object['source_instance_uid'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $selections[] = [
                'domain' => (string) $object['domain'],
                'instance_uid' => (string) $object['source_instance_uid'],
                'operation' => (string) $item['operation'],
            ];
        }

        return $selections;
    }

    /**
     * The definitions whose golden source STILL reports the published hash.
     *
     * @param array<string, mixed> $source_env
     * @param string               $agency_id
     * @param array<int, string>   $definition_uids
     * @param string               $channel
     * @return array<string, array<string, mixed>> Keyed by definition_uid.
     */
    private function deliverable_objects(array $source_env, $agency_id, array $definition_uids, $channel)
    {
        $deliverable = [];
        foreach ($definition_uids as $definition_uid) {
            $desired = $this->definitions->desired($agency_id, (string) $definition_uid, $channel);
            if ($desired === null) {
                continue;
            }
            $source_instance_uid = (string) $desired['source_instance_uid'];
            if ($source_instance_uid === '') {
                continue;
            }
            $projection = $this->projections->get($source_env['environment_id'], $source_env['current_epoch'], (string) $desired['domain'], $source_instance_uid, (string) $desired['profile']);
            if ($projection === null || (int) $projection['snapshot_complete'] !== 1 || (int) $projection['object_exists'] !== 1) {
                continue;
            }
            if ((string) $projection['semantic_hash'] !== (string) $desired['definition_hash']) {
                continue; // the golden source drifted from what was published.
            }
            $deliverable[(string) $definition_uid] = [
                'domain' => (string) $desired['domain'],
                'source_instance_uid' => $source_instance_uid,
                'definition_hash' => (string) $desired['definition_hash'],
            ];
        }

        return $deliverable;
    }

    /**
     * Distinct cohort indices for a campaign, in ascending order.
     *
     * @param string $campaign_uid
     * @return array<int, int>
     */
    private function cohort_indices($campaign_uid)
    {
        $cohorts = [];
        foreach ($this->store->targets($campaign_uid) as $row) {
            $cohorts[(int) $row['cohort']] = true;
        }
        $indices = array_keys($cohorts);
        sort($indices);

        return $indices;
    }

    /**
     * @param string $campaign_uid
     * @param int    $cohort
     * @return array<int, array<string, mixed>>
     */
    private function cohort_rows($campaign_uid, $cohort)
    {
        $rows = [];
        foreach ($this->store->targets($campaign_uid) as $row) {
            if ((int) $row['cohort'] === (int) $cohort) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param string $campaign_uid
     * @param int    $cohort
     * @return array{all_success: bool, any_failed: bool, any_open: bool}
     */
    private function cohort_status($campaign_uid, $cohort)
    {
        $all_success = true;
        $any_failed = false;
        $any_open = false;
        foreach ($this->cohort_rows($campaign_uid, $cohort) as $row) {
            if ($row['state'] === FrameworkRolloutStore::TARGET_FAILED) {
                $any_failed = true;
            }
            if ($row['state'] !== FrameworkRolloutStore::TARGET_SUCCEEDED && $row['state'] !== FrameworkRolloutStore::TARGET_SKIPPED) {
                $all_success = false;
            }
            if (! $this->is_terminal($row['state'])) {
                $any_open = true;
            }
        }

        return ['all_success' => $all_success, 'any_failed' => $any_failed, 'any_open' => $any_open];
    }

    /**
     * @param string $state
     * @return bool
     */
    private function is_terminal($state)
    {
        return in_array($state, [FrameworkRolloutStore::TARGET_SUCCEEDED, FrameworkRolloutStore::TARGET_SKIPPED, FrameworkRolloutStore::TARGET_FAILED], true);
    }

    /**
     * A campaign version label: the shared version if every definition names the
     * same desired one, else "mixed".
     *
     * @param string             $agency_id
     * @param array<int, string> $definition_uids
     * @param string             $channel
     * @return string
     */
    private function campaign_version($agency_id, array $definition_uids, $channel)
    {
        $version = '';
        foreach ($definition_uids as $definition_uid) {
            $desired = $this->definitions->desired($agency_id, (string) $definition_uid, $channel);
            $current = $desired !== null ? (string) $desired['version'] : '';
            if ($version === '') {
                $version = $current;
            } elseif ($version !== $current) {
                return 'mixed';
            }
        }

        return $version;
    }

    /**
     * @param string $agency_id
     * @param string $channel
     * @return array<int, string>
     */
    private function desired_definition_uids($agency_id, $channel)
    {
        $uids = [];
        foreach ($this->definitions->all(null, 500) as $row) {
            if ((int) $row['desired'] === 1 && (string) $row['agency_id'] === $agency_id && (string) $row['channel'] === $channel) {
                $uids[(string) $row['definition_uid']] = true;
            }
        }

        return array_keys($uids);
    }

    /**
     * Normalise a cohorts argument (an ordered list of target-id lists) into a
     * clean array of arrays. Accepts a JSON string or an array of (arrays or CSV
     * strings). Returns [] when none was given, or null on a duplicate target
     * across cohorts.
     *
     * @param mixed $value
     * @return array<int, array<int, string>>|null
     */
    private function ordered_cohorts($value)
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($value)) {
            return [];
        }
        $ordered = [];
        $seen = [];
        foreach ($value as $cohort) {
            $clean = [];
            foreach ($this->id_list($cohort) as $id) {
                if (isset($seen[$id])) {
                    return null;
                }
                $seen[$id] = true;
                $clean[] = $id;
            }
            if ($clean !== []) {
                $ordered[] = $clean;
            }
        }

        return $ordered;
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function id_list($value)
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[$item] = true;
            }
        }

        return array_keys($out);
    }
}
