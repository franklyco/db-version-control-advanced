<?php

namespace Dbvc\AgencyControl\Release;

use Dbvc\AgencyControl\Storage\ApprovalStore;
use Dbvc\AgencyControl\Storage\BaselineStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\PreparationStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\AgencyControl\Storage\SubscriptionStore;
use Dbvc\ConnectedProtocol\Canonicalizer;

/**
 * Reviewed execution, hub side. An approval is an explicit studio action on
 * one received prepare receipt whose every item is ready or a no-op; it
 * binds the release digest, the receipt digest, the target environment and
 * epoch and the current routing policy revision, and expires with the
 * receipt. The target connector retrieves open approvals on its own poll
 * (only when its apply gate is on), executes conditionally against the
 * container fingerprints the receipt recorded, and posts one execution
 * receipt, which consumes the approval. Verified applied objects advance
 * the pair baseline; nothing else does.
 */
final class ApprovalService
{
    /**
     * @var ApprovalStore
     */
    private $approvals;

    /**
     * @var PreparationStore
     */
    private $preparations;

    /**
     * @var ReleaseStore
     */
    private $releases;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    public function __construct()
    {
        $this->approvals = new ApprovalStore();
        $this->preparations = new PreparationStore();
        $this->releases = new ReleaseStore();
        $this->environments = new EnvironmentRegistry();
    }

    /**
     * @param string $operation_id Prepare operation id.
     * @param string $note
     * @return array<string, mixed>|\WP_Error
     */
    public function approve($operation_id, $note = '')
    {
        $preparation = $this->preparations->find((string) $operation_id);
        if ($preparation === null) {
            return new \WP_Error('dbvc_agency_preparation_not_found', 'Preparation not found.', ['status' => 404]);
        }
        if ($preparation['state'] !== PreparationStore::STATE_RECEIVED || ! is_array($preparation['receipt'])) {
            return new \WP_Error('dbvc_agency_receipt_missing', 'Only a received prepare receipt can be approved.', ['status' => 409]);
        }
        if (! in_array($preparation['outcome'], ['ready', 'noop'], true)) {
            return new \WP_Error('dbvc_agency_receipt_not_ready', 'The receipt reports blocked items (' . $preparation['outcome'] . '); resolve them and prepare again.', ['status' => 409]);
        }
        if (empty($preparation['expires_at']) || strtotime((string) $preparation['expires_at'] . ' UTC') <= time()) {
            return new \WP_Error('dbvc_agency_receipt_expired', 'The prepare receipt has expired; prepare again.', ['status' => 409]);
        }
        $release = $this->releases->get($preparation['release_id']);
        if ($release === null || $release['state'] !== ReleaseStore::STATE_SEALED) {
            return new \WP_Error('dbvc_agency_release_not_sealed', 'The release is no longer sealed.', ['status' => 409]);
        }
        $target = $this->environments->find($preparation['target_environment_id']);
        if ($target === null || $target['status'] !== EnvironmentRegistry::STATUS_ENABLED) {
            return new \WP_Error('dbvc_agency_environment_not_enabled', 'The target environment is not enabled.', ['status' => 409]);
        }
        if ($target['current_epoch'] !== $preparation['target_epoch']) {
            return new \WP_Error('dbvc_agency_target_epoch_changed', 'The target re-enrolled since the receipt was taken; prepare again.', ['status' => 409]);
        }
        if ($this->approvals->find_by_operation($preparation['operation_id']) !== null) {
            return new \WP_Error('dbvc_agency_already_approved', 'This receipt already has an approval.', ['status' => 409]);
        }
        $notes = is_array($preparation['hub_notes']) ? $preparation['hub_notes'] : [];
        if ((int) ($notes['items_disagreeing_with_hub_projection'] ?? 0) > 0) {
            return new \WP_Error('dbvc_agency_receipt_disagrees', 'The receipt\'s before-state disagrees with the hub\'s last projection of the target; let the target report, then prepare again.', ['status' => 409]);
        }

        $snapshot = (new SubscriptionStore())->registry_snapshot($target);
        $approval = $this->approvals->create([
            'approval_uid' => 'apr-' . bin2hex(random_bytes(8)),
            'operation_id' => $preparation['operation_id'],
            'release_id' => $release['release_id'],
            'release_uid' => $release['release_uid'],
            'release_digest' => $release['digest'],
            'receipt_digest' => $preparation['receipt_digest'],
            'target_environment_id' => $target['environment_id'],
            'target_epoch' => $target['current_epoch'],
            'policy_revision' => $snapshot['revision'],
            'note' => (string) $note,
            'approved_by' => get_current_user_id(),
            'expires_at' => (string) $preparation['expires_at'],
        ]);
        if ($approval === null) {
            return new \WP_Error('dbvc_agency_approval_error', 'The approval could not be recorded.', ['status' => 500]);
        }

        return $approval;
    }

    /**
     * @param string $approval_uid
     * @param string $reason
     * @return array<string, mixed>|\WP_Error
     */
    public function revoke($approval_uid, $reason = 'revoked')
    {
        $approval = $this->approvals->find((string) $approval_uid);
        if ($approval === null) {
            return new \WP_Error('dbvc_agency_approval_not_found', 'Approval not found.', ['status' => 404]);
        }
        if ($approval['state'] !== ApprovalStore::STATE_APPROVED) {
            return ['approval_uid' => $approval['approval_uid'], 'state' => $approval['state'], 'changed' => false];
        }
        $this->approvals->revoke($approval['approval_id'], (string) $reason);

        return ['approval_uid' => $approval['approval_uid'], 'previous_state' => $approval['state'], 'state' => ApprovalStore::STATE_REVOKED, 'changed' => true];
    }

    /**
     * Open approvals for the authenticated target, with everything execution needs.
     *
     * @param array<string, mixed> $target Trusted environment row.
     * @return array<int, array<string, mixed>>
     */
    public function pending_for_target(array $target)
    {
        $out = [];
        foreach ($this->approvals->open_for_target($target['environment_id'], 5) as $approval) {
            if ($approval['target_epoch'] !== $target['current_epoch']) {
                $this->approvals->revoke($approval['approval_id'], 'target_epoch_changed');
                continue;
            }
            $release = $this->releases->get($approval['release_id']);
            $preparation = $this->preparations->find($approval['operation_id']);
            if ($release === null || $release['state'] !== ReleaseStore::STATE_SEALED || $release['digest'] !== $approval['release_digest'] || $preparation === null || ! is_array($preparation['receipt'])) {
                $this->approvals->revoke($approval['approval_id'], 'release_or_receipt_unavailable');
                continue;
            }
            $items = [];
            foreach ($this->releases->items($release['release_id'], true) as $item) {
                $items[] = [
                    'domain' => $item['domain'],
                    'instance_uid' => $item['instance_uid'],
                    'profile' => $item['profile'],
                    'operation' => $item['operation'],
                    'after_hash' => $item['after_hash'],
                    'body' => (string) $item['payload'],
                ];
            }
            $out[] = [
                'approval_uid' => $approval['approval_uid'],
                'operation_id' => $approval['operation_id'],
                'approved_at' => $approval['approved_at'],
                'expires_at' => $approval['expires_at'],
                'policy_revision' => $approval['policy_revision'],
                'release' => [
                    'release_uid' => $release['release_uid'],
                    'digest' => $release['digest'],
                    'source_environment_id' => $release['source_environment_id'],
                    'items' => $items,
                ],
                // The receipt the approval binds: the target re-verifies its own before-state and container fingerprints against it.
                'receipt' => $preparation['receipt'],
                'receipt_digest' => $approval['receipt_digest'],
            ];
        }

        return $out;
    }

    /**
     * Store the target's execution receipt once, consume the approval, and
     * advance the pair baseline for every verified applied object.
     *
     * @param array<string, mixed> $target
     * @param string               $release_uid
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>|\WP_Error
     */
    public function accept_execution(array $target, $release_uid, array $receipt)
    {
        $approval = isset($receipt['approval_uid']) ? $this->approvals->find((string) $receipt['approval_uid']) : null;
        if ($approval === null || $approval['target_environment_id'] !== $target['environment_id'] || $approval['release_uid'] !== (string) $release_uid) {
            return new \WP_Error('dbvc_agency_approval_not_found', 'No approval matches this execution receipt.', ['status' => 404]);
        }
        if ($approval['state'] !== ApprovalStore::STATE_APPROVED) {
            return new \WP_Error('dbvc_agency_approval_closed', 'This approval is ' . $approval['state'] . '.', ['status' => 409]);
        }
        $errors = [];
        if (($receipt['operation_id'] ?? null) !== $approval['operation_id']) {
            $errors[] = 'operation_id';
        }
        if (($receipt['release_digest'] ?? null) !== $approval['release_digest'] || ($receipt['receipt_digest'] ?? null) !== $approval['receipt_digest']) {
            $errors[] = 'digests';
        }
        $env = is_array($receipt['target'] ?? null) ? $receipt['target'] : [];
        if (($env['environment_id'] ?? null) !== $target['environment_id'] || ($env['epoch'] ?? null) !== $target['current_epoch']) {
            $errors[] = 'target';
        }
        if (! in_array($receipt['outcome'] ?? null, ['applied', 'noop', 'stale', 'failed', 'compensated', 'partial', 'unsupported'], true)) {
            $errors[] = 'outcome';
        }
        if (! is_array($receipt['items'] ?? null)) {
            $errors[] = 'items';
        }
        if ($errors !== []) {
            return new \WP_Error('dbvc_agency_invalid_receipt', 'Execution receipt rejected: ' . implode(', ', $errors), ['status' => 400, 'errors' => $errors]);
        }

        $digest = Canonicalizer::hash(Canonicalizer::encode($receipt));
        if (! $this->approvals->consume($approval['approval_id'], $receipt, $digest)) {
            return new \WP_Error('dbvc_agency_approval_error', 'The execution receipt could not be stored.', ['status' => 500]);
        }

        $release = $this->releases->get($approval['release_id']);
        $advanced = 0;
        if ($release !== null) {
            $baselines = new BaselineStore();
            foreach ((array) $receipt['items'] as $item) {
                if (! is_array($item) || ($item['outcome'] ?? '') !== 'applied' || empty($item['verified'])) {
                    continue;
                }
                $baselines->upsert([
                    'source_environment_id' => $release['source_environment_id'],
                    'target_environment_id' => $target['environment_id'],
                    'domain' => (string) $item['domain'],
                    'source_instance_uid' => (string) $item['instance_uid'],
                    'target_instance_uid' => (string) ($item['target_instance_uid'] ?? $item['instance_uid']),
                    'profile' => (string) $item['profile'],
                    'baseline_hash' => (string) $item['after_hash'],
                    'source_sequence' => 0,
                    'target_sequence' => (int) $target['max_received_sequence'],
                    'source_epoch' => $release['source_epoch'],
                    'target_epoch' => $target['current_epoch'],
                    'confirmed_by' => 0,
                    'note' => 'applied:' . $approval['operation_id'],
                ]);
                $advanced++;
            }
        }

        return [
            'approval_uid' => $approval['approval_uid'],
            'operation_id' => $approval['operation_id'],
            'execution_digest' => $digest,
            'outcome' => (string) $receipt['outcome'],
            'baselines_advanced' => $advanced,
            'stored_at' => gmdate('c'),
        ];
    }
}
