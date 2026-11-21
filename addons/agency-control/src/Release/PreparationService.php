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
     * @param string $scope 'client' (default, same-client parity) or 'agency' (a framework
     *                       rollout may reach another client's site under the same agency).
     * @return array<string, mixed>|\WP_Error
     */
    public function request($release_uid, $target_environment_id, $scope = 'client')
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
        if ($target['agency_id'] !== $release['agency_id'] || ($scope !== 'agency' && $target['client_id'] !== $release['client_id'])) {
            return new \WP_Error('dbvc_agency_scope_mismatch', $scope === 'agency' ? 'Releases stay within one agency.' : 'Releases stay within one agency and client.', ['status' => 403]);
        }
        if ($target['status'] !== EnvironmentRegistry::STATUS_ENABLED) {
            return new \WP_Error('dbvc_agency_environment_not_enabled', 'The target environment is not enabled.', ['status' => 409]);
        }
        if (EnvironmentRegistry::is_frozen($target)) {
            return new \WP_Error('dbvc_agency_environment_frozen', 'The target environment is in a maintenance-freeze window.', ['status' => 409]);
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
     * Every pending request is built; {@see page_requests} cuts the list the
     * route returns so one response stays within what the connector reads.
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
                        $media = \Dbvc\AgencyControl\Storage\MediaBlobStore::hydrate($decoded['media']);
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
     * One response page of prepare requests (CR-1): whole requests only (a
     * receipt must cover every item of its release), added in order until the
     * item count reaches `$max_items`; the first request always fits. A single
     * request larger than the page is returned alone. `has_more` tells the
     * connector to poll again in the same run.
     *
     * @param array<int, array<string, mixed>> $requests Built requests (release.items present).
     * @param int                              $max_items
     * @return array{requests: array<int, array<string, mixed>>, has_more: bool}
     */
    public static function page_requests(array $requests, $max_items = Protocol::MAX_PAYLOAD_ITEMS)
    {
        $max_items = max(1, (int) $max_items);
        $page = [];
        $items = 0;
        foreach ($requests as $request) {
            $count = is_array($request['release']['items'] ?? null) ? count($request['release']['items']) : 0;
            if ($page !== [] && $items + $count > $max_items) {
                break;
            }
            $page[] = $request;
            $items += $count;
            if ($items >= $max_items) {
                break;
            }
        }

        return ['requests' => $page, 'has_more' => count($page) < count($requests)];
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
        $encoded = wp_json_encode($receipt);
        if (! is_string($encoded) || strlen($encoded) > Protocol::MAX_RECEIPT_BYTES) {
            return new \WP_Error('dbvc_agency_receipt_too_large', 'Receipt exceeds ' . Protocol::MAX_RECEIPT_BYTES . ' bytes.', ['status' => 413]);
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
                    continue;
                }
                if (isset($item['patch']) && $item['patch'] !== null && ! self::valid_patch($item['patch'])) {
                    $errors[] = 'items.' . $index . '.patch';
                }
            }
        }
        if (! is_array($receipt['counts'] ?? null)) {
            $errors[] = 'counts';
        }

        return $errors;
    }

    /**
     * A patch is a strategy plus a list of changed-path rows (CR-1 nested shape):
     * each row names a dot-path, an add/remove/replace operation, a 64-hex or
     * null hash per side and, optionally, rendered string values per side.
     *
     * @param mixed $patch
     * @return bool
     */
    public static function valid_patch($patch)
    {
        if (! is_array($patch) || ! in_array($patch['strategy'] ?? null, ['replace_object', 'add_object', 'remove_object'], true)) {
            return false;
        }
        $rows = $patch['changed_paths'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return false;
        }
        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['path']) || ! is_string($row['path']) || ! in_array($row['operation'] ?? null, ['add', 'remove', 'replace'], true)) {
                return false;
            }
            foreach (['before_hash', 'after_hash'] as $key) {
                if (array_key_exists($key, $row) && $row[$key] !== null && ! (is_string($row[$key]) && preg_match('/^[a-f0-9]{64}$/', $row[$key]))) {
                    return false;
                }
            }
            foreach (['before_value', 'after_value'] as $key) {
                if (array_key_exists($key, $row) && $row[$key] !== null && ! is_string($row[$key])) {
                    return false;
                }
            }
        }

        return true;
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
