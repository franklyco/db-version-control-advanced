<?php

namespace Dbvc\AgencyControl\Release;

use Dbvc\AgencyControl\Storage\ApprovalStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\PreparationStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\RolloutStore;

/**
 * Fleet rollout, hub side. A rollout stages one sealed release across an
 * ordered sequence of cohorts of target environments — cohort 0 is the canary.
 * It is emphatically NOT one distributed transaction: each target is the
 * ordinary single-environment prepare -> approve -> the-target-executes
 * operation, and {@see advance()} moves each target of the current cohort one
 * step per call (request a prepare, approve a received receipt, then read the
 * execution outcome). A cohort opens the next only once every one of its
 * targets verified; a single failure pauses the rollout so later cohorts never
 * start. The studio reviews the paused outcome and either retries the failed
 * target, resumes, or withdraws. Nothing here writes to a target directly.
 */
final class RolloutService
{
    /** @var array<int, string> Execution outcomes that count a target verified. */
    private const SUCCESS_OUTCOMES = ['applied', 'noop'];

    /** @var array<int, string> Terminal states a rollout must be in to be pruned. */
    private const RETENTION_STATES = [
        RolloutStore::STATE_COMPLETED,
        RolloutStore::STATE_WITHDRAWN,
        RolloutStore::STATE_FAILED,
    ];

    /** @var int Default retention window: finished rollouts older than this are prunable. */
    public const RETENTION_DAYS_DEFAULT = 30;

    /**
     * @var RolloutStore
     */
    private $rollouts;

    /**
     * @var ReleaseStore
     */
    private $releases;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    /**
     * @var PreparationStore
     */
    private $preparations;

    /**
     * @var ApprovalStore
     */
    private $approvals;

    /**
     * @var PreparationService
     */
    private $preparation_service;

    /**
     * @var ApprovalService
     */
    private $approval_service;

    public function __construct()
    {
        $this->rollouts = new RolloutStore();
        $this->releases = new ReleaseStore();
        $this->environments = new EnvironmentRegistry();
        $this->preparations = new PreparationStore();
        $this->approvals = new ApprovalStore();
        $this->preparation_service = new PreparationService();
        $this->approval_service = new ApprovalService();
    }

    /**
     * Plan a rollout: an ordered list of cohorts, each a list of target
     * environment ids. The first cohort is the canary.
     *
     * @param string                    $release_uid
     * @param array<int, array<int, string>> $cohorts
     * @param string                    $note
     * @return array<string, mixed>|\WP_Error
     */
    public function create($release_uid, array $cohorts, $note = '')
    {
        $release = $this->releases->find((string) $release_uid);
        if ($release === null) {
            return new \WP_Error('dbvc_agency_release_not_found', 'Release not found.', ['status' => 404]);
        }
        if ($release['state'] !== ReleaseStore::STATE_SEALED) {
            return new \WP_Error('dbvc_agency_release_not_sealed', 'Only a sealed release (every payload verified) can be rolled out.', ['status' => 409]);
        }

        $normalized = [];
        foreach ($cohorts as $cohort) {
            $ids = array_values(array_filter(array_map('trim', (array) $cohort), static function ($id) {
                return $id !== '';
            }));
            if ($ids !== []) {
                $normalized[] = $ids;
            }
        }
        if ($normalized === []) {
            return new \WP_Error('dbvc_agency_rollout_no_cohorts', 'A rollout needs at least one cohort with a target.', ['status' => 400]);
        }

        $seen = [];
        $targets = [];
        foreach ($normalized as $cohort_index => $ids) {
            foreach ($ids as $position => $environment_id) {
                if (isset($seen[$environment_id])) {
                    return new \WP_Error('dbvc_agency_rollout_duplicate_target', 'A target may appear in only one cohort: ' . $environment_id, ['status' => 400]);
                }
                $seen[$environment_id] = true;
                $target = $this->environments->find((string) $environment_id);
                if ($target === null) {
                    return new \WP_Error('dbvc_agency_environment_not_found', 'Target environment not found: ' . $environment_id, ['status' => 404]);
                }
                if ($target['environment_id'] === $release['source_environment_id']) {
                    return new \WP_Error('dbvc_agency_self_release', 'A release is not rolled out onto its own source.', ['status' => 400]);
                }
                if ($target['agency_id'] !== $release['agency_id'] || $target['client_id'] !== $release['client_id']) {
                    return new \WP_Error('dbvc_agency_scope_mismatch', 'A rollout stays within one agency and client.', ['status' => 403]);
                }
                if ($target['status'] !== EnvironmentRegistry::STATUS_ENABLED) {
                    return new \WP_Error('dbvc_agency_environment_not_enabled', 'Target environment is not enabled: ' . $environment_id, ['status' => 409]);
                }
                $targets[] = [
                    'cohort' => $cohort_index,
                    'position' => $position,
                    'target_environment_id' => $target['environment_id'],
                ];
            }
        }

        $rollout = $this->rollouts->create([
            'rollout_uid' => 'rol-' . bin2hex(random_bytes(8)),
            'agency_id' => $release['agency_id'],
            'client_id' => $release['client_id'],
            'release_id' => $release['release_id'],
            'release_uid' => $release['release_uid'],
            'source_environment_id' => $release['source_environment_id'],
            'cohort_count' => count($normalized),
            'note' => (string) $note,
            'created_by' => get_current_user_id(),
        ], $targets);
        if ($rollout === null) {
            return new \WP_Error('dbvc_agency_rollout_error', 'The rollout could not be recorded.', ['status' => 500]);
        }

        return $this->status_of($rollout);
    }

    /**
     * Drive the rollout one step: move each target of the current cohort along
     * (prepare -> approve -> read outcome), then apply the cohort gate. Safe to
     * call repeatedly; each call advances only what is ready.
     *
     * @param string $rollout_uid
     * @return array<string, mixed>|\WP_Error
     */
    public function advance($rollout_uid)
    {
        $rollout = $this->rollouts->find((string) $rollout_uid);
        if ($rollout === null) {
            return new \WP_Error('dbvc_agency_rollout_not_found', 'Rollout not found.', ['status' => 404]);
        }
        if ($rollout['state'] !== RolloutStore::STATE_RUNNING) {
            return $this->status_of($rollout);
        }

        // A guard so an opened-and-kicked next cohort cannot loop indefinitely.
        for ($guard = 0; $guard <= $rollout['cohort_count']; $guard++) {
            $this->step_cohort($rollout);
            $targets = $this->rollouts->cohort_targets($rollout['rollout_id'], $rollout['current_cohort']);
            $failed = false;
            $all_succeeded = $targets !== [];
            foreach ($targets as $target) {
                if ($target['state'] === RolloutStore::TARGET_FAILED) {
                    $failed = true;
                }
                if ($target['state'] !== RolloutStore::TARGET_SUCCEEDED) {
                    $all_succeeded = false;
                }
            }
            if ($failed) {
                $this->rollouts->update_rollout($rollout['rollout_id'], [
                    'state' => RolloutStore::STATE_PAUSED,
                    'paused_reason' => 'cohort_' . $rollout['current_cohort'] . '_failed',
                ]);
                break;
            }
            if (! $all_succeeded) {
                break; // Still in flight; wait for the targets to poll and report.
            }
            if ($rollout['current_cohort'] + 1 >= $rollout['cohort_count']) {
                $this->rollouts->update_rollout($rollout['rollout_id'], ['state' => RolloutStore::STATE_COMPLETED]);
                break;
            }
            // Canary/cohort verified: open the next cohort and kick its prepares in this pass.
            $rollout['current_cohort']++;
            $this->rollouts->update_rollout($rollout['rollout_id'], ['current_cohort' => $rollout['current_cohort']]);
        }

        $fresh = $this->rollouts->get($rollout['rollout_id']);

        return $this->status_of($fresh ?? $rollout);
    }

    /**
     * Move every target of the current cohort one macro-step.
     *
     * @param array<string, mixed> $rollout
     * @return void
     */
    private function step_cohort(array $rollout)
    {
        foreach ($this->rollouts->cohort_targets($rollout['rollout_id'], $rollout['current_cohort']) as $target) {
            if ($target['state'] === RolloutStore::TARGET_PENDING) {
                $this->request_prepare($rollout, $target);
            } elseif ($target['state'] === RolloutStore::TARGET_PREPARING) {
                $this->approve_when_ready($target);
            } elseif ($target['state'] === RolloutStore::TARGET_APPROVED) {
                $this->read_outcome($target);
            }
        }
    }

    /**
     * @param array<string, mixed> $rollout
     * @param array<string, mixed> $target
     * @return void
     */
    private function request_prepare(array $rollout, array $target)
    {
        $result = $this->preparation_service->request($rollout['release_uid'], $target['target_environment_id']);
        if (is_wp_error($result)) {
            $this->rollouts->update_target($target['rollout_target_id'], [
                'state' => RolloutStore::TARGET_FAILED,
                'detail' => 'prepare_request:' . $result->get_error_code(),
            ]);

            return;
        }
        $this->rollouts->update_target($target['rollout_target_id'], [
            'state' => RolloutStore::TARGET_PREPARING,
            'operation_id' => (string) $result['operation_id'],
            'detail' => '',
        ]);
    }

    /**
     * @param array<string, mixed> $target
     * @return void
     */
    private function approve_when_ready(array $target)
    {
        $preparation = $target['operation_id'] !== '' ? $this->preparations->find($target['operation_id']) : null;
        if ($preparation === null) {
            $this->rollouts->update_target($target['rollout_target_id'], [
                'state' => RolloutStore::TARGET_FAILED,
                'detail' => 'preparation_lost',
            ]);

            return;
        }
        if ($preparation['state'] === PreparationStore::STATE_CANCELLED) {
            $this->rollouts->update_target($target['rollout_target_id'], [
                'state' => RolloutStore::TARGET_FAILED,
                'detail' => 'prepare_cancelled:' . $preparation['outcome'],
            ]);

            return;
        }
        if ($preparation['state'] !== PreparationStore::STATE_RECEIVED) {
            return; // Still waiting for the target to post its receipt.
        }
        if (! in_array($preparation['outcome'], ['ready', 'noop'], true)) {
            $this->rollouts->update_target($target['rollout_target_id'], [
                'state' => RolloutStore::TARGET_FAILED,
                'detail' => 'prepare_blocked:' . $preparation['outcome'],
            ]);

            return;
        }
        $approval = $this->approval_service->approve($target['operation_id'], 'rollout');
        if (is_wp_error($approval)) {
            $this->rollouts->update_target($target['rollout_target_id'], [
                'state' => RolloutStore::TARGET_FAILED,
                'detail' => 'approve:' . $approval->get_error_code(),
            ]);

            return;
        }
        $this->rollouts->update_target($target['rollout_target_id'], [
            'state' => RolloutStore::TARGET_APPROVED,
            'approval_uid' => (string) $approval['approval_uid'],
            'detail' => '',
        ]);
    }

    /**
     * @param array<string, mixed> $target
     * @return void
     */
    private function read_outcome(array $target)
    {
        $approval = $target['approval_uid'] !== '' ? $this->approvals->find($target['approval_uid']) : null;
        if ($approval === null) {
            $this->rollouts->update_target($target['rollout_target_id'], [
                'state' => RolloutStore::TARGET_FAILED,
                'detail' => 'approval_lost',
            ]);

            return;
        }
        if ($approval['state'] === ApprovalStore::STATE_REVOKED) {
            $this->rollouts->update_target($target['rollout_target_id'], [
                'state' => RolloutStore::TARGET_FAILED,
                'detail' => 'approval_revoked',
            ]);

            return;
        }
        if ($approval['state'] !== ApprovalStore::STATE_CONSUMED) {
            return; // The target has not executed yet.
        }
        $outcome = (string) $approval['execution_outcome'];
        if (in_array($outcome, self::SUCCESS_OUTCOMES, true)) {
            $this->rollouts->update_target($target['rollout_target_id'], [
                'state' => RolloutStore::TARGET_SUCCEEDED,
                'outcome' => $outcome,
                'detail' => '',
            ]);

            return;
        }
        $this->rollouts->update_target($target['rollout_target_id'], [
            'state' => RolloutStore::TARGET_FAILED,
            'outcome' => $outcome,
            'detail' => 'execution:' . $outcome,
        ]);
    }

    /**
     * Operator pause. Later cohorts will not start until resumed.
     *
     * @param string $rollout_uid
     * @param string $reason
     * @return array<string, mixed>|\WP_Error
     */
    public function pause($rollout_uid, $reason = 'operator')
    {
        $rollout = $this->rollouts->find((string) $rollout_uid);
        if ($rollout === null) {
            return new \WP_Error('dbvc_agency_rollout_not_found', 'Rollout not found.', ['status' => 404]);
        }
        if ($rollout['state'] !== RolloutStore::STATE_RUNNING) {
            return new \WP_Error('dbvc_agency_rollout_not_running', 'Only a running rollout can be paused; this one is ' . $rollout['state'] . '.', ['status' => 409]);
        }
        $this->rollouts->update_rollout($rollout['rollout_id'], [
            'state' => RolloutStore::STATE_PAUSED,
            'paused_reason' => (string) $reason,
        ]);

        return $this->status_of($this->rollouts->get($rollout['rollout_id']) ?? $rollout);
    }

    /**
     * Resume a paused rollout. It does not blow past a failure: if the current
     * cohort still holds a failed target, the next advance re-pauses until the
     * target is retried or the rollout withdrawn.
     *
     * @param string $rollout_uid
     * @return array<string, mixed>|\WP_Error
     */
    public function resume($rollout_uid)
    {
        $rollout = $this->rollouts->find((string) $rollout_uid);
        if ($rollout === null) {
            return new \WP_Error('dbvc_agency_rollout_not_found', 'Rollout not found.', ['status' => 404]);
        }
        if ($rollout['state'] !== RolloutStore::STATE_PAUSED) {
            return new \WP_Error('dbvc_agency_rollout_not_paused', 'Only a paused rollout can be resumed; this one is ' . $rollout['state'] . '.', ['status' => 409]);
        }
        $this->rollouts->update_rollout($rollout['rollout_id'], [
            'state' => RolloutStore::STATE_RUNNING,
            'paused_reason' => '',
        ]);

        return $this->advance($rollout['rollout_uid']);
    }

    /**
     * Reset one failed target of a paused rollout back to pending so the next
     * advance re-prepares it. The rollout stays paused until resumed.
     *
     * @param string $rollout_uid
     * @param string $target_environment_id
     * @return array<string, mixed>|\WP_Error
     */
    public function retry($rollout_uid, $target_environment_id)
    {
        $rollout = $this->rollouts->find((string) $rollout_uid);
        if ($rollout === null) {
            return new \WP_Error('dbvc_agency_rollout_not_found', 'Rollout not found.', ['status' => 404]);
        }
        if (! in_array($rollout['state'], [RolloutStore::STATE_PAUSED, RolloutStore::STATE_RUNNING], true)) {
            return new \WP_Error('dbvc_agency_rollout_closed', 'A ' . $rollout['state'] . ' rollout has no target to retry.', ['status' => 409]);
        }
        $match = null;
        foreach ($this->rollouts->targets($rollout['rollout_id']) as $target) {
            if ($target['target_environment_id'] === (string) $target_environment_id) {
                $match = $target;
                break;
            }
        }
        if ($match === null) {
            return new \WP_Error('dbvc_agency_rollout_target_not_found', 'That environment is not in this rollout.', ['status' => 404]);
        }
        if ($match['state'] !== RolloutStore::TARGET_FAILED) {
            return new \WP_Error('dbvc_agency_rollout_target_not_failed', 'Only a failed target can be retried; this one is ' . $match['state'] . '.', ['status' => 409]);
        }
        $this->rollouts->update_target($match['rollout_target_id'], [
            'state' => RolloutStore::TARGET_PENDING,
            'operation_id' => '',
            'approval_uid' => '',
            'outcome' => '',
            'detail' => '',
        ]);

        return $this->status_of($this->rollouts->get($rollout['rollout_id']) ?? $rollout);
    }

    /**
     * @param string $rollout_uid
     * @return array<string, mixed>|\WP_Error
     */
    public function withdraw($rollout_uid)
    {
        $rollout = $this->rollouts->find((string) $rollout_uid);
        if ($rollout === null) {
            return new \WP_Error('dbvc_agency_rollout_not_found', 'Rollout not found.', ['status' => 404]);
        }
        if (in_array($rollout['state'], [RolloutStore::STATE_COMPLETED, RolloutStore::STATE_WITHDRAWN], true)) {
            return new \WP_Error('dbvc_agency_rollout_closed', 'A ' . $rollout['state'] . ' rollout cannot be withdrawn.', ['status' => 409]);
        }
        $this->rollouts->update_rollout($rollout['rollout_id'], [
            'state' => RolloutStore::STATE_WITHDRAWN,
            'paused_reason' => '',
        ]);

        return $this->status_of($this->rollouts->get($rollout['rollout_id']) ?? $rollout);
    }

    /**
     * @param string $rollout_uid
     * @return array<string, mixed>|\WP_Error
     */
    public function status($rollout_uid)
    {
        $rollout = $this->rollouts->find((string) $rollout_uid);
        if ($rollout === null) {
            return new \WP_Error('dbvc_agency_rollout_not_found', 'Rollout not found.', ['status' => 404]);
        }

        return $this->status_of($rollout);
    }

    /**
     * Retention: remove finished rollouts (completed, withdrawn or failed) whose
     * last change is older than the window, together with their target rows.
     * Running and paused rollouts are never touched. A dry run only counts.
     *
     * @param int  $days    Age threshold in days; 0 prunes every finished rollout.
     * @param bool $dry_run When true, report the count without deleting.
     * @return array<string, mixed>
     */
    public function prune($days = self::RETENTION_DAYS_DEFAULT, $dry_run = false)
    {
        $days = max(0, (int) $days);
        $before = gmdate('Y-m-d H:i:s', time() - ($days * DAY_IN_SECONDS));
        $count = $dry_run
            ? $this->rollouts->prunable_count(self::RETENTION_STATES, $before)
            : $this->rollouts->prune(self::RETENTION_STATES, $before);

        return [
            'pruned' => $dry_run ? 0 : $count,
            'prunable' => $count,
            'dry_run' => (bool) $dry_run,
            'older_than_days' => $days,
            'before' => $before,
            'states' => self::RETENTION_STATES,
        ];
    }

    /**
     * @param array<string, mixed> $rollout
     * @return array<string, mixed>
     */
    private function status_of(array $rollout)
    {
        return [
            'rollout' => $rollout,
            'targets' => $this->rollouts->targets($rollout['rollout_id']),
        ];
    }
}
