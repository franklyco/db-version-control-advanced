<?php

namespace Dbvc\AgencyControl\Release;

use Dbvc\AgencyControl\Framework\FrameworkStatusService;
use Dbvc\AgencyControl\Storage\BaselineStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\InstanceLinkStore;
use Dbvc\AgencyControl\Storage\PreparationStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;
use Dbvc\AgencyControl\Storage\ReleaseStore;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Target preparation: a studio requests a dry-run of one sealed release on
 * one target; the target connector retrieves the request with the manifest
 * and payloads, computes its receipt locally and posts it back. The hub
 * stores the receipt with its digest and annotates it with what it knows
 * (its own projection of each target object and the pair baseline), so a
 * receipt taken against state the hub has not seen is visible as such.
 * No step here writes content anywhere; a receipt is not permission to apply.
 */
final class PreparationService
{
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

    /**
     * @var ProjectionStore
     */
    private $projections;

    /**
     * @var BaselineStore
     */
    private $baselines;

    public function __construct()
    {
        $this->preparations = new PreparationStore();
        $this->releases = new ReleaseStore();
        $this->environments = new EnvironmentRegistry();
        $this->projections = new ProjectionStore();
        $this->baselines = new BaselineStore();
    }

    /**
     * @param string $release_uid
     * @param string $target_environment_id
     * @return array<string, mixed>|\WP_Error
     */
    public function request($release_uid, $target_environment_id)
    {
        $release = $this->releases->find((string) $release_uid);
        if ($release === null) {
            return new \WP_Error('dbvc_agency_release_not_found', 'Release not found.', ['status' => 404]);
        }
        if ($release['state'] !== ReleaseStore::STATE_SEALED) {
            return new \WP_Error('dbvc_agency_release_not_sealed', 'Only a sealed release (every payload verified) can be prepared.', ['status' => 409]);
        }
        $target = $this->environments->find((string) $target_environment_id);
        if ($target === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Target environment not found.', ['status' => 404]);
        }
        if ($target['environment_id'] === $release['source_environment_id']) {
            return new \WP_Error('dbvc_agency_self_release', 'A release is not prepared on its own source.', ['status' => 400]);
        }
        if ($target['agency_id'] !== $release['agency_id'] || $target['client_id'] !== $release['client_id']) {
            return new \WP_Error('dbvc_agency_scope_mismatch', 'Releases stay within one agency and client.', ['status' => 403]);
        }
        if ($target['status'] !== EnvironmentRegistry::STATUS_ENABLED) {
            return new \WP_Error('dbvc_agency_environment_not_enabled', 'The target environment is not enabled.', ['status' => 409]);
        }
        foreach ($this->preparations->all($release['release_uid'], $target['environment_id'], 50) as $existing) {
            if ($existing['state'] === PreparationStore::STATE_REQUESTED) {
                return new \WP_Error('dbvc_agency_preparation_pending', 'A prepare request for this release and target is still outstanding: ' . $existing['operation_id'], ['status' => 409]);
            }
        }

        $preparation = $this->preparations->request([
            'operation_id' => 'prep-' . bin2hex(random_bytes(8)),
            'release_id' => $release['release_id'],
            'release_uid' => $release['release_uid'],
            'target_environment_id' => $target['environment_id'],
            'target_epoch' => $target['current_epoch'],
            'requested_by' => get_current_user_id(),
        ]);
        if ($preparation === null) {
            return new \WP_Error('dbvc_agency_preparation_error', 'The prepare request could not be recorded.', ['status' => 500]);
        }

        return $preparation;
    }

    /**
     * Outstanding requests for the authenticated target, with manifest and payloads.
     *
     * @param array<string, mixed> $target Trusted environment row.
     * @return array<int, array<string, mixed>>
     */
    public function pending_for_target(array $target)
    {
        $out = [];
        foreach ($this->preparations->requested_for_target($target['environment_id'], 10) as $preparation) {
            $release = $this->releases->get($preparation['release_id']);
            if ($release === null || $release['state'] !== ReleaseStore::STATE_SEALED) {
                $this->preparations->cancel($preparation['preparation_id'], 'release_' . ($release === null ? 'missing' : $release['state']));
                continue;
            }
            if ($preparation['target_epoch'] !== $target['current_epoch']) {
                $this->preparations->cancel($preparation['preparation_id'], 'target_epoch_changed');
                continue;
            }
            // Operator-declared lineage links (M3) let independently enrolled targets resolve the source's objects.
            $links = (new InstanceLinkStore())->map_for_pair($release['source_environment_id'], $target['environment_id'], null);
            $items = [];
            foreach ($this->releases->items($release['release_id'], true) as $item) {
                $media = [];
                if (isset($item['media']) && is_string($item['media']) && $item['media'] !== '') {
                    $decoded = json_decode($item['media'], true);
                    if (is_array($decoded) && isset($decoded['media']) && is_array($decoded['media'])) {
                        $media = $decoded['media'];
                    }
                }
                $items[] = [
                    'domain' => $item['domain'],
                    'instance_uid' => $item['instance_uid'],
                    'target_instance_uid' => $links[$item['domain'] . '|' . $item['instance_uid']] ?? $item['instance_uid'],
                    'profile' => $item['profile'],
                    'operation' => $item['operation'],
                    'after_hash' => $item['after_hash'],
                    'source_sequence' => $item['source_sequence'],
                    'body' => (string) $item['payload'],
                    'media' => $media,
                ];
            }
            $out[] = [
                'operation_id' => $preparation['operation_id'],
                'requested_at' => $preparation['requested_at'],
                'release' => [
                    'release_uid' => $release['release_uid'],
                    'digest' => $release['digest'],
                    'source_environment_id' => $release['source_environment_id'],
                    'source_epoch' => $release['source_epoch'],
                    'note' => $release['note'],
                    'items' => $items,
                ],
                'receipt_ttl_seconds' => Protocol::PREPARE_RECEIPT_TTL_SECONDS,
            ];
        }

        return $out;
    }

    /**
     * Store the target's receipt once, annotated with the hub's own view.
     *
     * @param array<string, mixed> $target  Trusted environment row of the caller.
     * @param string               $release_uid
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>|\WP_Error
     */
    public function accept_receipt(array $target, $release_uid, array $receipt)
    {
        $operation_id = (string) ($receipt['operation_id'] ?? '');
        $preparation = $operation_id !== '' ? $this->preparations->find($operation_id) : null;
        if ($preparation === null || $preparation['target_environment_id'] !== $target['environment_id'] || $preparation['release_uid'] !== (string) $release_uid) {
            return new \WP_Error('dbvc_agency_preparation_not_found', 'No outstanding prepare request matches this receipt.', ['status' => 404]);
        }
        if ($preparation['state'] !== PreparationStore::STATE_REQUESTED) {
            return new \WP_Error('dbvc_agency_preparation_closed', 'This prepare request already has a receipt or was cancelled.', ['status' => 409]);
        }
        $release = $this->releases->get($preparation['release_id']);
        if ($release === null) {
            return new \WP_Error('dbvc_agency_release_not_found', 'Release not found.', ['status' => 404]);
        }
        $errors = self::validate_receipt($receipt, $release, $target);
        if ($errors !== []) {
            return new \WP_Error('dbvc_agency_invalid_receipt', 'Receipt rejected: ' . implode(', ', $errors), ['status' => 400, 'errors' => $errors]);
        }

        $notes = $this->annotate($release, $target, $receipt);
        $digest = Canonicalizer::hash(Canonicalizer::encode($receipt));
        if (! $this->preparations->record_receipt($preparation['preparation_id'], $receipt, $digest, $notes)) {
            return new \WP_Error('dbvc_agency_preparation_error', 'The receipt could not be stored.', ['status' => 500]);
        }

        return [
            'operation_id' => $operation_id,
            'release_uid' => $release['release_uid'],
            'receipt_digest' => $digest,
            'outcome' => (string) $receipt['outcome'],
            'hub_notes' => $notes,
            'stored_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $receipt
     * @param array<string, mixed> $release
     * @param array<string, mixed> $target
     * @return array<int, string>
     */
    public static function validate_receipt(array $receipt, array $release, array $target)
    {
        $errors = [];
        if (($receipt['release_uid'] ?? null) !== $release['release_uid']) {
            $errors[] = 'release_uid';
        }
        if (($receipt['release_digest'] ?? null) !== $release['digest']) {
            $errors[] = 'release_digest';
        }
        $env = is_array($receipt['target'] ?? null) ? $receipt['target'] : [];
        if (($env['environment_id'] ?? null) !== $target['environment_id'] || ($env['epoch'] ?? null) !== $target['current_epoch']) {
            $errors[] = 'target';
        }
        if (! in_array($receipt['outcome'] ?? null, ['ready', 'noop', 'blocked', 'partial'], true)) {
            $errors[] = 'outcome';
        }
        foreach (['prepared_at', 'expires_at'] as $key) {
            if (! is_string($receipt[$key] ?? null) || strtotime((string) $receipt[$key]) === false) {
                $errors[] = $key;
            }
        }
        $items = $receipt['items'] ?? null;
        if (! is_array($items) || $items === []) {
            $errors[] = 'items';
        } else {
            $expected = (int) $release['item_count'];
            if (count($items) !== $expected) {
                $errors[] = 'item_count';
            }
            foreach ($items as $index => $item) {
                if (! is_array($item) || ! in_array($item['outcome'] ?? null, ['ready', 'noop', 'blocked'], true) || ! isset($item['domain'], $item['instance_uid'], $item['profile'], $item['after_hash'])) {
                    $errors[] = 'items.' . $index;
                }
            }
        }
        if (! is_array($receipt['counts'] ?? null)) {
            $errors[] = 'counts';
        }

        return $errors;
    }

    /**
     * The hub's view beside the target's claims: the projection hash it last
     * received for each target object and whether the receipt's before-hash
     * agrees with it, plus the pair baseline when one exists.
     *
     * @param array<string, mixed> $release
     * @param array<string, mixed> $target
     * @param array<string, mixed> $receipt
     * @return array<string, mixed>
     */
    private function annotate(array $release, array $target, array $receipt)
    {
        $baselines = $this->baselines->for_pair($release['source_environment_id'], $target['environment_id'], null);
        $items = [];
        $stale = 0;
        foreach ((array) $receipt['items'] as $item) {
            $target_uid = isset($item['target_instance_uid']) && is_string($item['target_instance_uid']) && $item['target_instance_uid'] !== '' ? $item['target_instance_uid'] : (string) $item['instance_uid'];
            $projection = $this->projections->get($target['environment_id'], $target['current_epoch'], (string) $item['domain'], $target_uid, (string) $item['profile']);
            $hub_hash = $projection !== null ? (string) $projection['semantic_hash'] : null;
            $before = isset($item['before_hash']) && is_string($item['before_hash']) ? $item['before_hash'] : null;
            $agrees = $hub_hash !== null && $before !== null ? $hub_hash === $before : null;
            if ($agrees === false) {
                $stale++;
            }
            $baseline_key = (string) $item['domain'] . '|' . (string) $item['instance_uid'] . '|' . (string) $item['profile'];
            $baseline = isset($baselines[$baseline_key]) ? (string) $baselines[$baseline_key]['baseline_hash'] : null;
            $items[] = [
                'domain' => (string) $item['domain'],
                'instance_uid' => (string) $item['instance_uid'],
                'target_instance_uid' => $target_uid,
                'profile' => (string) $item['profile'],
                'hub_target_hash' => $hub_hash,
                'target_projection_agrees' => $agrees,
                'baseline_hash' => $baseline,
                'target_edited_since_baseline' => $baseline !== null && $before !== null ? $baseline !== $before : null,
            ];
        }

        return [
            'target_fresh' => FrameworkStatusService::is_fresh($target),
            'items_disagreeing_with_hub_projection' => $stale,
            'items' => $items,
            'note' => 'Hub annotations compare the receipt with the hub\'s last received projections and baselines; they add no authority and change nothing.',
        ];
    }
}
