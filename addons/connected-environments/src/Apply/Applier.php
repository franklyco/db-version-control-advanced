<?php

namespace Dbvc\Connected\Apply;

use Dbvc\Connected\Adapters\BricksOptionCollectionObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Adapters\PostTypeObserver;
use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Storage\JobStore;
use Dbvc\Connected\Storage\OperationStore;
use Dbvc\Connected\Storage\PreparationStore;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Approved execution on the target, two domain families in this
 * step: Bricks option collections (global classes, global variables) and
 * wp.service posts (per-post row writes).
 *
 * The approval binds the prepare receipt this environment produced; the
 * applier re-reads every container immediately before writing and writes
 * only when the container's raw storage fingerprint still equals the one
 * the receipt recorded, with a single conditional SQL statement (`WHERE
 * SHA2(option_value) = <fingerprint>`), so an unrelated editor save in the
 * meantime makes the operation `stale` instead of being overwritten. A
 * verified before image is journalled before the write; the after-state is
 * verified through the read-only observer, and a failed verification is
 * compensated by the same conditional write back to the before image. Bricks items of one container succeed or fail together; wp.service items write one post row each, guarded by the post fingerprint (post_modified + meta), verified by re-snapshot and compensated on a miss. Media (attachments) and service deletions are not written here.
 */
final class Applier
{
    /**
     * @var OperationStore
     */
    private $operations;

    /**
     * @var PreparationStore
     */
    private $preparations;

    public function __construct(?OperationStore $operations = null, ?PreparationStore $preparations = null)
    {
        $this->operations = $operations ?: new OperationStore();
        $this->preparations = $preparations ?: new PreparationStore();
    }

    /**
     * @param array<string, mixed> $approval approval_uid, operation_id, expires_at, release{release_uid,digest,items}, receipt, receipt_digest
     * @param array<string, mixed> $state    Connector state row.
     * @param string               $hub_url
     * @return array<string, mixed> The execution receipt.
     */
    public function apply(array $approval, array $state, $hub_url)
    {
        $started = time();
        $release = is_array($approval['release'] ?? null) ? $approval['release'] : [];
        $receipt = is_array($approval['receipt'] ?? null) ? $approval['receipt'] : [];
        $base = [
            'approval_uid' => (string) ($approval['approval_uid'] ?? ''),
            'operation_id' => (string) ($approval['operation_id'] ?? ''),
            'release_uid' => (string) ($release['release_uid'] ?? ''),
            'release_digest' => (string) ($release['digest'] ?? ''),
            'receipt_digest' => (string) ($approval['receipt_digest'] ?? ''),
            'target' => ['environment_id' => (string) $state['environment_id'], 'epoch' => (string) $state['installation_epoch']],
            'started_at' => gmdate('c', $started),
        ];

        // The approval must bind the receipt this environment produced, unexpired.
        $local = $this->preparations->find($base['operation_id']);
        if ($local === null || $local['receipt_digest'] !== $base['receipt_digest'] || Canonicalizer::hash(Canonicalizer::encode($receipt)) !== $base['receipt_digest']) {
            return $this->finish_without_writes($base, 'failed', 'receipt_mismatch', $hub_url);
        }
        if (strtotime((string) ($approval['expires_at'] ?? '') . ' UTC') <= time()) {
            return $this->finish_without_writes($base, 'stale', 'approval_expired', $hub_url);
        }
        if (($receipt['release_digest'] ?? null) !== $base['release_digest'] || ($receipt['target']['epoch'] ?? null) !== $base['target']['epoch']) {
            return $this->finish_without_writes($base, 'failed', 'receipt_binding_mismatch', $hub_url);
        }

        // Plan: group receipt items (with their identity and fingerprints) by container; every item must have been ready or noop.
        $receipt_items = [];
        foreach ((array) ($receipt['items'] ?? []) as $item) {
            if (is_array($item)) {
                $receipt_items[$item['domain'] . '|' . $item['instance_uid'] . '|' . $item['profile']] = $item;
            }
        }
        $plan = [];
        $service_plan = [];
        $results = [];
        foreach ((array) ($release['items'] ?? []) as $manifest) {
            if (! is_array($manifest)) {
                continue;
            }
            $key = $manifest['domain'] . '|' . $manifest['instance_uid'] . '|' . $manifest['profile'];
            $prepared = $receipt_items[$key] ?? null;
            $result = [
                'domain' => (string) $manifest['domain'],
                'instance_uid' => (string) $manifest['instance_uid'],
                'target_instance_uid' => (string) ($prepared['target_instance_uid'] ?? $manifest['instance_uid']),
                'profile' => (string) $manifest['profile'],
                'operation' => (string) ($manifest['operation'] ?? 'replace'),
                'after_hash' => (string) $manifest['after_hash'],
                'before_hash' => $prepared['before_hash'] ?? null,
                'outcome' => 'unsupported',
                'verified' => false,
                'error' => '',
                'container' => $prepared['container'] ?? null,
            ];
            if ($prepared === null) {
                $result['outcome'] = 'failed';
                $result['error'] = 'not_in_receipt';
                $results[$key] = $result;
                continue;
            }
            if ($prepared['outcome'] === 'noop') {
                $result['outcome'] = 'noop';
                $results[$key] = $result;
                continue;
            }
            if ($prepared['outcome'] !== 'ready') {
                $result['outcome'] = 'failed';
                $result['error'] = 'receipt_item_not_ready';
                $results[$key] = $result;
                continue;
            }
            $observer = DomainRegistry::observer_for($result['domain']);
            if ($observer instanceof BricksOptionCollectionObserver) {
                $plan[$observer->option_name()][] = ['key' => $key, 'observer' => $observer, 'manifest' => $manifest, 'prepared' => $prepared];
                $results[$key] = $result;
                continue;
            }
            if ($observer instanceof PostTypeObserver) {
                // Per-CPT apply allow-list: even with the global apply gate on, a post type
                // this connector has not opted into applying is refused here (the write path
                // is authoritative — advertisement in capabilities()['apply'] mirrors it).
                if (empty($observer->capabilities()['apply'])) {
                    $result['outcome'] = 'unsupported';
                    $result['error'] = 'apply_not_enabled_for_domain';
                    $results[$key] = $result;
                    continue;
                }
                $service_plan[$key] = ['key' => $key, 'observer' => $observer, 'manifest' => $manifest, 'prepared' => $prepared];
                $results[$key] = $result;
                continue;
            }
            $result['outcome'] = 'unsupported';
            $result['error'] = 'domain_apply_not_supported_in_this_step';
            $results[$key] = $result;
        }

        // Journal the verified before image of every container before the first write.
        $before_images = [];
        $stale_containers = [];
        // Per-post before images for wp.service: the current canonical snapshot, guarded by the post fingerprint.
        foreach ($service_plan as $key => $entry) {
            $post_id = (string) ($entry['prepared']['identity']['storage_key'] ?? '');
            $snapshot = $post_id !== '' ? $entry['observer']->snapshot(['storage_key' => $post_id]) : ['status' => 'missing'];
            $expected = (string) ($entry['prepared']['storage_fingerprint'] ?? '');
            if ($post_id === '' || ($snapshot['status'] ?? '') !== 'available' || (string) ($snapshot['storage_fingerprint'] ?? '') !== $expected) {
                $stale_containers['service:' . $key] = (string) ($snapshot['storage_fingerprint'] ?? '');
                continue;
            }
            $before_images['service:' . $key] = [
                'kind' => 'service',
                'domain' => (string) $entry['manifest']['domain'],
                'container' => (string) $entry['prepared']['container'],
                'post_id' => (int) $post_id,
                'fingerprint' => (string) $snapshot['storage_fingerprint'],
                'before_hash' => (string) $snapshot['semantic_hash'],
                'canonical' => (string) ($snapshot['canonical'] ?? ''),
            ];
        }
        foreach ($plan as $option => $entries) {
            $container = $entries[0]['observer']->read_container();
            $expected = (string) ($entries[0]['prepared']['storage_fingerprint'] ?? '');
            foreach ($entries as $entry) {
                if ((string) ($entry['prepared']['storage_fingerprint'] ?? '') !== $expected) {
                    $expected = null;
                    break;
                }
            }
            if ($expected === null || ! $container['present'] || $container['fingerprint'] !== $expected) {
                $stale_containers[$option] = $container['fingerprint'];
                continue;
            }
            $before_images[$option] = ['fingerprint' => $container['fingerprint'], 'raw' => $container['raw']];
        }
        $journal = [];
        $started_row = $this->operations->start([
            'operation_id' => $base['operation_id'],
            'approval_uid' => $base['approval_uid'],
            'release_uid' => $base['release_uid'],
            'release_digest' => $base['release_digest'],
            'receipt_digest' => $base['receipt_digest'],
            'installation_epoch' => $base['target']['epoch'],
            'before_image' => $before_images,
        ], $hub_url);
        if ($started_row === 'exists') {
            $existing = $this->operations->find($base['operation_id']);
            if ($existing !== null && is_array($existing['execution_receipt'])) {
                return $existing['execution_receipt'];
            }
            // Started but never finished: a crash mid-operation. Do not execute again; report for recovery.
            return $this->finish_without_writes($base, 'failed', 'operation_in_progress_recovery_required', $hub_url, false);
        }
        if ($started_row !== 'created') {
            return $this->finish_without_writes($base, 'failed', 'journal_unavailable', $hub_url, false);
        }

        $capture = new DirtyCapture(new JobStore(), DomainRegistry::domains());
        foreach ($plan as $option => $entries) {
            if (array_key_exists($option, $stale_containers)) {
                foreach ($entries as $entry) {
                    $results[$entry['key']]['outcome'] = 'stale';
                    $results[$entry['key']]['error'] = 'container_changed_since_receipt';
                }
                $journal[] = ['container' => 'option:' . $option, 'step' => 'precheck', 'result' => 'stale', 'fingerprint_now' => $stale_containers[$option]];
                continue;
            }
            $outcome = $this->apply_container($option, $entries, $before_images[$option], $journal);
            foreach ($entries as $entry) {
                $results[$entry['key']]['outcome'] = $outcome['items'][$entry['key']]['outcome'];
                $results[$entry['key']]['verified'] = $outcome['items'][$entry['key']]['verified'];
                $results[$entry['key']]['error'] = $outcome['items'][$entry['key']]['error'];
                $results[$entry['key']]['storage_fingerprint_before'] = $before_images[$option]['fingerprint'];
                $results[$entry['key']]['storage_fingerprint_after'] = $outcome['fingerprint_after'];
            }
            if ($outcome['written']) {
                // Report the change through the ordinary observation pipeline with origin=apply.
                $capture->signal($entries[0]['observer']->domain(), DomainRegistry::COLLECTION_KEY, ['origin' => 'apply', 'causation_id' => $base['operation_id'], 'source_hook' => 'apply']);
            }
        }

        // wp.service: one conditional per-post write per item, guarded by the post fingerprint.
        foreach ($service_plan as $key => $entry) {
            if (array_key_exists('service:' . $key, $stale_containers)) {
                $results[$key]['outcome'] = 'stale';
                $results[$key]['error'] = 'post_changed_since_receipt';
                $journal[] = ['container' => (string) $entry['prepared']['container'], 'step' => 'precheck', 'result' => 'stale', 'fingerprint_now' => $stale_containers['service:' . $key]];
                continue;
            }
            $before = $before_images['service:' . $key];
            $outcome = $this->apply_service_item($entry, $before, $journal);
            $results[$key]['outcome'] = $outcome['outcome'];
            $results[$key]['verified'] = $outcome['verified'];
            $results[$key]['error'] = $outcome['error'];
            $results[$key]['storage_fingerprint_before'] = $before['fingerprint'];
            $results[$key]['storage_fingerprint_after'] = $outcome['fingerprint_after'];
        }

        $receipt_out = $this->build_receipt($base, array_values($results), $journal, $started);
        $this->operations->finish($base['operation_id'], $receipt_out, $journal);

        return $receipt_out;
    }

    /**
     * One wp.service post: guard already checked, write the received body, verify
     * by re-snapshot, and compensate by restoring the journalled before body on a
     * verification miss. Media (attachments) are never created here.
     *
     * @param array<string, mixed>             $entry
     * @param array<string, mixed>             $before
     * @param array<int, array<string, mixed>> $journal
     * @return array{outcome: string, verified: bool, error: string, fingerprint_after: string}
     */
    private function apply_service_item(array $entry, array $before, array &$journal)
    {
        $observer = $entry['observer'];
        $post_id = (int) $before['post_id'];
        $container = (string) $entry['prepared']['container'];
        $manifest = $entry['manifest'];
        $operation = (string) ($manifest['operation'] ?? 'replace');

        if ($operation === 'delete') {
            // Reversible removal: trash the post (the fingerprint guard already passed).
            // The journalled before image lets a reviewed rollback restore it.
            $trashed = $observer->trash($post_id);
            if ($trashed !== true) {
                $journal[] = ['container' => $container, 'step' => 'delete', 'result' => 'refused', 'error' => (string) $trashed];
                return ['outcome' => 'failed', 'verified' => false, 'error' => (string) $trashed, 'fingerprint_after' => (string) ($observer->snapshot(['storage_key' => (string) $post_id])['storage_fingerprint'] ?? '')];
            }
            $after = $observer->snapshot(['storage_key' => (string) $post_id]);
            $gone = ($after['status'] ?? '') === 'missing';
            $journal[] = ['container' => $container, 'step' => 'delete', 'result' => 'trashed', 'fingerprint_before' => $before['fingerprint'], 'fingerprint_after' => (string) ($after['storage_fingerprint'] ?? '')];
            $journal[] = ['container' => $container, 'step' => 'verify', 'result' => $gone ? 'verified' : 'failed'];
            if ($gone) {
                return ['outcome' => 'applied', 'verified' => true, 'error' => '', 'fingerprint_after' => (string) ($after['storage_fingerprint'] ?? '')];
            }
            // Compensate: untrash so a failed verify never leaves the post removed.
            $observer->untrash($post_id);
            $now = $observer->snapshot(['storage_key' => (string) $post_id]);
            return ['outcome' => 'failed', 'verified' => false, 'error' => 'delete_unverified', 'fingerprint_after' => (string) ($now['storage_fingerprint'] ?? '')];
        }
        $body = json_decode((string) ($manifest['body'] ?? ''), true);
        if (! is_array($body)) {
            $journal[] = ['container' => $container, 'step' => 'build', 'result' => 'payload_invalid'];
            return ['outcome' => 'failed', 'verified' => false, 'error' => 'payload_invalid', 'fingerprint_after' => $before['fingerprint']];
        }

        // Materialize the carried media (M7 step 3b) so apply_body's detokenize can
        // resolve every referenced attachment to a local id/URL: content the target
        // lacks is sideloaded from the carried bytes, content it already holds is
        // reused. Ids created here are journalled (for a reviewed rollback) and
        // discarded if the guarded write does not verify.
        $created_media = [];
        $carried_media = is_array($manifest['media'] ?? null) ? $manifest['media'] : [];
        if ($carried_media !== []) {
            $materialized = MediaMaterializer::materialize($carried_media);
            $created_media = array_values($materialized['created']);
            $journal[] = ['container' => $container, 'step' => 'media', 'result' => $materialized['errors'] === [] ? 'materialized' : 'failed', 'created' => count($created_media), 'reused' => count($materialized['reused']), 'created_ids' => $created_media, 'errors' => $materialized['errors']];
            if ($materialized['errors'] !== []) {
                MediaMaterializer::discard($created_media);
                return ['outcome' => 'failed', 'verified' => false, 'error' => 'media_materialize_failed', 'fingerprint_after' => $before['fingerprint']];
            }
        }

        $written = $observer->apply_body($post_id, $body);
        if ($written !== true) {
            MediaMaterializer::discard($created_media);
            $journal[] = ['container' => $container, 'step' => 'write', 'result' => 'refused', 'error' => (string) $written];
            return ['outcome' => 'failed', 'verified' => false, 'error' => (string) $written, 'fingerprint_after' => (string) ($observer->snapshot(['storage_key' => (string) $post_id])['storage_fingerprint'] ?? '')];
        }
        $after = $observer->snapshot(['storage_key' => (string) $post_id]);
        $ok = ($after['status'] ?? '') === 'available' && (string) ($after['semantic_hash'] ?? '') === (string) $manifest['after_hash'];
        $journal[] = ['container' => $container, 'step' => 'write', 'result' => 'written', 'fingerprint_before' => $before['fingerprint'], 'fingerprint_after' => (string) ($after['storage_fingerprint'] ?? '')];
        $journal[] = ['container' => $container, 'step' => 'verify', 'result' => $ok ? 'verified' : 'failed'];
        if ($ok) {
            return ['outcome' => 'applied', 'verified' => true, 'error' => '', 'fingerprint_after' => (string) $after['storage_fingerprint']];
        }

        // Compensate: restore the before body so a failed verify never leaves partial
        // state, and discard any attachments this run created (now orphaned).
        MediaMaterializer::discard($created_media);
        $before_body = json_decode((string) $before['canonical'], true);
        $restored = is_array($before_body) ? $observer->apply_body($post_id, $before_body) : 'before_body_unreadable';
        $now = $observer->snapshot(['storage_key' => (string) $post_id]);
        $restored_ok = $restored === true && (string) ($now['semantic_hash'] ?? '') === (string) $before['before_hash'];
        $journal[] = ['container' => $container, 'step' => 'compensate', 'result' => $restored_ok ? 'restored_verified' : 'restore_unverified'];

        return [
            'outcome' => $restored_ok ? 'compensated' : 'failed',
            'verified' => false,
            'error' => $restored_ok ? 'verification_failed_restored' : 'verification_failed_restore_unverified',
            'fingerprint_after' => (string) ($now['storage_fingerprint'] ?? ''),
        ];
    }

    /**
     * Reviewed rollback: restore the before image journalled for the reversed
     * operation, one conditional write per container guarded by that operation's
     * after fingerprint. A container that changed since the apply yields a
     * restore conflict (never an overwrite). Bricks option collections restore by conditional option write; wp.service posts restore by re-applying the journalled before body, guarded the same way.
     *
     * @param array<string, mixed> $approval hub apply-request with kind=rollback + rolls_back_operation_id
     * @param array<string, mixed> $state    connector state (environment_id, installation_epoch)
     * @param string               $hub_url
     * @return array<string, mixed> execution receipt
     */
    public function rollback(array $approval, array $state, $hub_url)
    {
        global $wpdb;

        $started = time();
        $release = is_array($approval['release'] ?? null) ? $approval['release'] : [];
        $base = [
            'approval_uid' => (string) ($approval['approval_uid'] ?? ''),
            'operation_id' => (string) ($approval['operation_id'] ?? ''),
            'rolls_back_operation_id' => (string) ($approval['rolls_back_operation_id'] ?? ''),
            'release_uid' => (string) ($release['release_uid'] ?? ''),
            'release_digest' => (string) ($release['digest'] ?? ''),
            'receipt_digest' => (string) ($approval['receipt_digest'] ?? ''),
            'target' => ['environment_id' => (string) $state['environment_id'], 'epoch' => (string) $state['installation_epoch']],
            'started_at' => gmdate('c', $started),
        ];
        if (strtotime((string) ($approval['expires_at'] ?? '') . ' UTC') <= time()) {
            return $this->finish_rollback($base, 'stale', 'approval_expired', [], [], $hub_url);
        }

        $original = $this->operations->find($base['rolls_back_operation_id']);
        if ($original === null || $original['state'] !== OperationStore::STATE_FINISHED || ! is_array($original['before_image']) || $original['before_image'] === []) {
            return $this->finish_rollback($base, 'failed', 'original_operation_not_restorable', [], [], $hub_url);
        }
        if ((string) $original['installation_epoch'] !== $base['target']['epoch']) {
            return $this->finish_rollback($base, 'failed', 'epoch_changed_since_operation', [], [], $hub_url);
        }
        $original_receipt = is_array($original['execution_receipt']) ? $original['execution_receipt'] : [];

        // Which containers the reversed apply wrote, and the after fingerprint it produced for each (keyed by the full container).
        $after_fp = [];
        foreach ((array) ($original['journal'] ?? []) as $entry) {
            // The operation's after fingerprint per container: a member write ('write'/'written')
            // or a reversible delete ('delete'/'trashed'); both record fingerprint_after.
            $records_after = ($entry['step'] ?? '') === 'write' && ($entry['result'] ?? '') === 'written';
            $records_after = $records_after || (($entry['step'] ?? '') === 'delete' && ($entry['result'] ?? '') === 'trashed');
            if (is_array($entry) && $records_after && isset($entry['container'], $entry['fingerprint_after'])) {
                $after_fp[(string) $entry['container']] = (string) $entry['fingerprint_after'];
            }
        }
        // Attachments the reversed apply sideloaded, per container. A reviewed rollback
        // deletes only these once the before image is restored (the restored post no
        // longer references them); a pre-existing or reused attachment is never touched.
        $created_media = [];
        foreach ((array) ($original['journal'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['step'] ?? '') === 'media' && isset($entry['container']) && is_array($entry['created_ids'] ?? null)) {
                foreach ($entry['created_ids'] as $mid) {
                    $created_media[(string) $entry['container']][] = (int) $mid;
                }
            }
        }
        // Group the reversed operation's receipt items by their storage container (option:<name> or post_type:<pt>#<id>).
        $items_by_container = [];
        foreach ((array) ($original_receipt['items'] ?? []) as $item) {
            if (! is_array($item) || empty($item['container'])) {
                continue;
            }
            $items_by_container[(string) $item['container']][] = $item;
        }

        // Capture the current container state as this restore's before image (so a rollback can itself be rolled back).
        $restore_before = [];
        foreach ((array) $original['before_image'] as $key => $target_image) {
            if (($target_image['kind'] ?? '') === 'service') {
                $observer = DomainRegistry::observer_for((string) ($target_image['domain'] ?? 'wp.service'));
                if (! $observer instanceof PostTypeObserver) {
                    continue;
                }
                $snapshot = $observer->snapshot(['storage_key' => (string) ($target_image['post_id'] ?? '')]);
                $restore_before[$key] = ['kind' => 'service', 'domain' => (string) ($target_image['domain'] ?? 'wp.service'), 'container' => (string) ($target_image['container'] ?? ''), 'post_id' => (int) ($target_image['post_id'] ?? 0), 'fingerprint' => (string) ($snapshot['storage_fingerprint'] ?? ''), 'before_hash' => (string) ($snapshot['semantic_hash'] ?? ''), 'canonical' => (string) ($snapshot['canonical'] ?? '')];
                continue;
            }
            $container = 'option:' . $key;
            $items = $items_by_container[$container] ?? [];
            $observer = $items === [] ? null : DomainRegistry::observer_for((string) $items[0]['domain']);
            if (! $observer instanceof BricksOptionCollectionObserver) {
                continue; // Only Bricks option collections restore this way.
            }
            $now = $observer->read_container();
            $restore_before[$key] = ['fingerprint' => $now['fingerprint'], 'raw' => $now['raw'], 'present' => $now['present']];
        }

        $started_row = $this->operations->start([
            'operation_id' => $base['operation_id'],
            'approval_uid' => $base['approval_uid'],
            'release_uid' => $base['release_uid'],
            'release_digest' => $base['release_digest'],
            'receipt_digest' => $base['receipt_digest'],
            'installation_epoch' => $base['target']['epoch'],
            'before_image' => $restore_before,
        ], $hub_url);
        if ($started_row === 'exists') {
            $existing = $this->operations->find($base['operation_id']);
            if ($existing !== null && is_array($existing['execution_receipt'])) {
                return $existing['execution_receipt'];
            }
            return $this->finish_rollback($base, 'failed', 'operation_in_progress_recovery_required', [], [], $hub_url, false);
        }
        if ($started_row !== 'created') {
            return $this->finish_rollback($base, 'failed', 'journal_unavailable', [], [], $hub_url, false);
        }

        $journal = [];
        $results = [];
        $capture = new DirtyCapture(new JobStore(), DomainRegistry::domains());
        foreach ((array) $original['before_image'] as $key => $target_image) {
            $is_service = ($target_image['kind'] ?? '') === 'service';
            $container = $is_service ? (string) ($target_image['container'] ?? '') : 'option:' . $key;
            $items = $items_by_container[$container] ?? [];
            $target_fp = (string) ($target_image['fingerprint'] ?? '');
            $item_result = function ($outcome, $verified, $error) use ($items) {
                $rows = [];
                foreach ($items as $item) {
                    $rows[] = [
                        'domain' => (string) $item['domain'],
                        'instance_uid' => (string) $item['instance_uid'],
                        'target_instance_uid' => (string) ($item['target_instance_uid'] ?? $item['instance_uid']),
                        'profile' => (string) $item['profile'],
                        'operation' => 'rollback',
                        'outcome' => $outcome,
                        'verified' => $verified,
                        'error' => $error,
                        'restored_hash' => (string) ($item['before_hash'] ?? ''),
                        'before_hash' => (string) ($item['before_hash'] ?? ''),
                        'after_hash' => (string) ($item['after_hash'] ?? ''),
                    ];
                }
                return $rows;
            };
            $observer = $items === [] ? null : DomainRegistry::observer_for((string) $items[0]['domain']);
            if ($observer === null || ($is_service && ! $observer instanceof PostTypeObserver) || (! $is_service && ! $observer instanceof BricksOptionCollectionObserver)) {
                $journal[] = ['container' => $container, 'step' => 'precheck', 'result' => 'unsupported'];
                foreach ($item_result('unsupported', false, 'domain_rollback_not_supported_in_this_step') as $r) {
                    $results[] = $r;
                }
                continue;
            }
            $now_fp = $is_service
                ? (string) ($observer->snapshot(['storage_key' => (string) ($target_image['post_id'] ?? '')])['storage_fingerprint'] ?? '')
                : (string) $observer->read_container()['fingerprint'];
            if ($now_fp === $target_fp) {
                $journal[] = ['container' => $container, 'step' => 'precheck', 'result' => 'already_before'];
                foreach ($item_result('noop', true, '') as $r) {
                    $results[] = $r;
                }
                continue;
            }
            $afp = $after_fp[$container] ?? null;
            if ($afp === null || $now_fp !== $afp) {
                $journal[] = ['container' => $container, 'step' => 'precheck', 'result' => 'restore_conflict', 'fingerprint_now' => $now_fp, 'fingerprint_after' => (string) $afp];
                foreach ($item_result('restore_conflict', false, 'container_changed_since_operation') as $r) {
                    $results[] = $r;
                }
                continue;
            }

            if ($is_service) {
                $before_body = json_decode((string) ($target_image['canonical'] ?? ''), true);
                $written = is_array($before_body) ? $observer->apply_body((int) $target_image['post_id'], $before_body) : 'before_body_unreadable';
                if ($written !== true) {
                    $journal[] = ['container' => $container, 'step' => 'write', 'result' => 'refused', 'error' => (string) $written];
                    foreach ($item_result('failed', false, (string) $written) as $r) {
                        $results[] = $r;
                    }
                    continue;
                }
                $verify = $observer->snapshot(['storage_key' => (string) $target_image['post_id']]);
                $ok = (string) ($verify['semantic_hash'] ?? '') === (string) ($target_image['before_hash'] ?? '');
                $journal[] = ['container' => $container, 'step' => 'write', 'result' => 'restored', 'fingerprint_before' => $afp, 'fingerprint_after' => (string) ($verify['storage_fingerprint'] ?? '')];
                $journal[] = ['container' => $container, 'step' => 'verify', 'result' => $ok ? 'verified' : 'failed'];
                // The restored before image no longer references the attachments the reversed
                // apply sideloaded, so delete exactly those (never a reused/pre-existing one).
                if ($ok && ($created_media[$container] ?? []) !== []) {
                    MediaMaterializer::discard($created_media[$container]);
                    $journal[] = ['container' => $container, 'step' => 'media', 'result' => 'discarded', 'discarded_ids' => $created_media[$container]];
                }
                foreach ($item_result($ok ? 'restored' : 'failed', $ok, $ok ? '' : 'restore_verification_failed') as $r) {
                    $results[] = $r;
                }
                continue;
            }

            $option = $key;
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND SHA2(option_value, 256) = %s",
                (string) $target_image['raw'],
                $option,
                $afp
            ));
            self::flush_option_cache($option);
            if ($affected !== 1) {
                $journal[] = ['container' => $container, 'step' => 'write', 'result' => 'rejected', 'affected' => (int) $affected];
                foreach ($item_result('stale', false, 'conditional_write_rejected') as $r) {
                    $results[] = $r;
                }
                continue;
            }
            $verify = $observer->read_container();
            $ok = $verify['fingerprint'] === $target_fp;
            $journal[] = ['container' => $container, 'step' => 'write', 'result' => 'restored', 'fingerprint_before' => $afp, 'fingerprint_after' => $verify['fingerprint']];
            $journal[] = ['container' => $container, 'step' => 'verify', 'result' => $ok ? 'verified' : 'failed'];
            foreach ($item_result($ok ? 'restored' : 'failed', $ok, $ok ? '' : 'restore_verification_failed') as $r) {
                $results[] = $r;
            }
            $capture->signal($observer->domain(), DomainRegistry::COLLECTION_KEY, ['origin' => 'rollback', 'causation_id' => $base['operation_id'], 'source_hook' => 'rollback']);
        }

        return $this->finish_rollback($base, null, '', $results, $journal, $hub_url);
    }

    /**
     * Build, journal and return a rollback execution receipt.
     *
     * @param array<string, mixed>             $base
     * @param string|null                      $forced_outcome When set, a pre-write refusal outcome.
     * @param string                           $error
     * @param array<int, array<string, mixed>> $items
     * @param array<int, array<string, mixed>> $journal
     * @param string                           $hub_url
     * @param bool                             $journal_row
     * @return array<string, mixed>
     */
    private function finish_rollback(array $base, $forced_outcome, $error, array $items, array $journal, $hub_url, $journal_row = true)
    {
        $counts = ['restored' => 0, 'noop' => 0, 'stale' => 0, 'restore_conflict' => 0, 'failed' => 0, 'unsupported' => 0];
        foreach ($items as $item) {
            $counts[$item['outcome']] = ($counts[$item['outcome']] ?? 0) + 1;
        }
        if ($forced_outcome !== null) {
            $outcome = $forced_outcome;
        } elseif ($counts['failed'] > 0) {
            $outcome = 'failed';
        } elseif ($counts['restore_conflict'] > 0 || $counts['stale'] > 0) {
            $outcome = $counts['restored'] > 0 ? 'partial' : 'restore_conflict';
        } elseif ($counts['restored'] > 0) {
            $outcome = ($counts['unsupported'] > 0) ? 'partial' : 'restored';
        } elseif ($counts['unsupported'] > 0) {
            $outcome = 'unsupported';
        } else {
            $outcome = 'noop';
        }
        $receipt = array_merge($base, [
            'kind' => 'rollback',
            'outcome' => $outcome,
            'counts' => $counts,
            'items' => $items,
            'journal' => $journal !== [] ? $journal : [['step' => 'precheck', 'result' => $error]],
            'warnings' => array_values(array_filter([$this->bricks_css_warning($items, ['restored'], 'rollback')])),
            'error' => (string) $error,
            'finished_at' => gmdate('c'),
            'note' => 'Reviewed rollback: conditional restore of the journalled before image, guarded by the operation\'s after fingerprint; a moved container is a restore conflict, never an overwrite.',
        ]);
        if ($journal_row) {
            $started = $this->operations->find($base['operation_id']);
            if ($started === null) {
                $this->operations->start(['operation_id' => $base['operation_id'], 'approval_uid' => $base['approval_uid'], 'release_uid' => $base['release_uid'], 'release_digest' => $base['release_digest'], 'receipt_digest' => $base['receipt_digest'], 'installation_epoch' => $base['target']['epoch'], 'before_image' => []], $hub_url);
            }
            $this->operations->finish($base['operation_id'], $receipt, $receipt['journal']);
        }

        return $receipt;
    }

    /**
     * One container: build the new value from the receipt's member identities, write conditionally, verify, compensate.
     *
     * @param string                              $option
     * @param array<int, array<string, mixed>>    $entries
     * @param array{fingerprint: string, raw: string} $before
     * @param array<int, array<string, mixed>>    $journal
     * @return array{written: bool, fingerprint_after: string|null, items: array<string, array{outcome: string, verified: bool, error: string}>}
     */
    private function apply_container($option, array $entries, array $before, array &$journal)
    {
        global $wpdb;

        $observer = $entries[0]['observer'];
        $items = [];
        $value = maybe_unserialize($before['raw']);
        if (! is_array($value)) {
            foreach ($entries as $entry) {
                $items[$entry['key']] = ['outcome' => 'failed', 'verified' => false, 'error' => 'container_not_array'];
            }
            $journal[] = ['container' => 'option:' . $option, 'step' => 'build', 'result' => 'container_not_array'];
            return ['written' => false, 'fingerprint_after' => $before['fingerprint'], 'items' => $items];
        }
        $key_field = $observer->key_field();
        $volatile = $observer->volatile_top_level_keys();
        $index_by_key = [];
        foreach ($value as $index => $member) {
            if (is_array($member) && isset($member[$key_field]) && is_scalar($member[$key_field])) {
                $index_by_key[trim((string) $member[$key_field])] = $index;
            }
        }

        $new_value = $value;
        $removals = [];
        foreach ($entries as $entry) {
            $storage_key = (string) ($entry['prepared']['identity']['storage_key'] ?? '');
            $index = $index_by_key[$storage_key] ?? null;
            $operation = (string) ($entry['manifest']['operation'] ?? 'replace');
            // Re-verify the member the receipt described is still the member being replaced.
            $current_hash = $index !== null ? Canonicalizer::project($value[$index], $volatile)['hash'] ?? null : BricksOptionCollectionObserver::absent_hash();
            if ($storage_key === '' || $current_hash !== (string) $entry['prepared']['before_hash']) {
                foreach ($entries as $other) {
                    $items[$other['key']] = ['outcome' => 'stale', 'verified' => false, 'error' => 'member_changed_since_receipt'];
                }
                $journal[] = ['container' => 'option:' . $option, 'step' => 'precheck', 'result' => 'member_stale', 'storage_key' => $storage_key];
                return ['written' => false, 'fingerprint_after' => $before['fingerprint'], 'items' => $items];
            }
            if ($operation === 'delete') {
                if ($index !== null) {
                    $removals[] = $index;
                }
                continue;
            }
            $after = json_decode((string) $entry['manifest']['body'], true);
            if (! is_array($after) || Canonicalizer::hash((string) $entry['manifest']['body']) !== (string) $entry['manifest']['after_hash']) {
                foreach ($entries as $other) {
                    $items[$other['key']] = ['outcome' => 'failed', 'verified' => false, 'error' => 'payload_invalid'];
                }
                $journal[] = ['container' => 'option:' . $option, 'step' => 'build', 'result' => 'payload_invalid', 'storage_key' => $storage_key];
                return ['written' => false, 'fingerprint_after' => $before['fingerprint'], 'items' => $items];
            }
            // Whole-object replacement in place: position and unrelated members are preserved.
            $new_value[$index] = $after;
        }
        if ($removals !== []) {
            rsort($removals);
            foreach ($removals as $index) {
                array_splice($new_value, $index, 1);
            }
        }
        $new_raw = maybe_serialize($new_value);
        if ($new_raw === $before['raw']) {
            foreach ($entries as $entry) {
                $items[$entry['key']] = ['outcome' => 'noop', 'verified' => true, 'error' => ''];
            }
            $journal[] = ['container' => 'option:' . $option, 'step' => 'build', 'result' => 'identical'];
            return ['written' => false, 'fingerprint_after' => $before['fingerprint'], 'items' => $items];
        }

        // Conditional write: only if the stored value is still exactly the one the receipt was taken against.
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND SHA2(option_value, 256) = %s",
            $new_raw,
            $option,
            $before['fingerprint']
        ));
        self::flush_option_cache($option);
        if ($affected !== 1) {
            foreach ($entries as $entry) {
                $items[$entry['key']] = ['outcome' => 'stale', 'verified' => false, 'error' => 'conditional_write_rejected'];
            }
            $journal[] = ['container' => 'option:' . $option, 'step' => 'write', 'result' => 'rejected', 'affected' => (int) $affected];
            return ['written' => false, 'fingerprint_after' => $observer->read_container()['fingerprint'], 'items' => $items];
        }
        $fingerprint_after = hash('sha256', (string) $new_raw);
        $journal[] = ['container' => 'option:' . $option, 'step' => 'write', 'result' => 'written', 'fingerprint_before' => $before['fingerprint'], 'fingerprint_after' => $fingerprint_after];

        // Verify through the read-only observer: each replaced member hashes to the manifest hash, each removed member is gone.
        $verified_all = true;
        foreach ($entries as $entry) {
            $storage_key = (string) $entry['prepared']['identity']['storage_key'];
            $snapshot = $observer->snapshot(['storage_key' => $storage_key]);
            $ok = (string) ($entry['manifest']['operation'] ?? 'replace') === 'delete'
                ? $snapshot['status'] === 'missing'
                : ($snapshot['status'] === 'available' && (string) $snapshot['semantic_hash'] === (string) $entry['manifest']['after_hash']);
            $items[$entry['key']] = ['outcome' => $ok ? 'applied' : 'failed', 'verified' => $ok, 'error' => $ok ? '' : 'verification_failed'];
            $verified_all = $verified_all && $ok;
        }
        $journal[] = ['container' => 'option:' . $option, 'step' => 'verify', 'result' => $verified_all ? 'verified' : 'failed'];
        if ($verified_all) {
            return ['written' => true, 'fingerprint_after' => $fingerprint_after, 'items' => $items];
        }

        // Compensate with the same conditional write back to the journalled before image; verify the restore.
        $restored = $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND SHA2(option_value, 256) = %s",
            $before['raw'],
            $option,
            $fingerprint_after
        ));
        self::flush_option_cache($option);
        $now = $observer->read_container();
        $restored_ok = $restored === 1 && $now['fingerprint'] === $before['fingerprint'];
        foreach ($entries as $entry) {
            $items[$entry['key']] = ['outcome' => $restored_ok ? 'compensated' : 'failed', 'verified' => false, 'error' => $restored_ok ? 'verification_failed_restored' : 'verification_failed_restore_unverified'];
        }
        $journal[] = ['container' => 'option:' . $option, 'step' => 'compensate', 'result' => $restored_ok ? 'restored_verified' : 'restore_unverified', 'affected' => (int) $restored, 'fingerprint_now' => $now['fingerprint']];

        return ['written' => true, 'fingerprint_after' => $now['fingerprint'], 'items' => $items];
    }

    /**
     * @param string $option
     * @return void
     */
    private static function flush_option_cache($option)
    {
        wp_cache_delete($option, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /**
     * @param array<string, mixed>              $base
     * @param array<int, array<string, mixed>>  $items
     * @param array<int, array<string, mixed>>  $journal
     * @param int                               $started
     * @return array<string, mixed>
     */
    /**
     * Regenerate Bricks' cached CSS files after a write that touched Bricks
     * option collections, when Bricks is present and using external CSS files.
     * A no-op (and no warning) in inline mode, which needs no rebuild. Returns a
     * receipt-warning string, or '' when nothing needs saying.
     *
     * @param array<int, array<string, mixed>> $items    Receipt items.
     * @param array<int, string>               $outcomes Outcomes that count as a write.
     * @param string                           $context  'apply' or 'rollback', for the warning copy.
     * @return string
     */
    private function bricks_css_warning(array $items, array $outcomes, $context)
    {
        $wrote_bricks = false;
        foreach ($items as $item) {
            if (strpos((string) ($item['domain'] ?? ''), 'bricks.') === 0 && in_array((string) ($item['outcome'] ?? ''), $outcomes, true)) {
                $wrote_bricks = true;
                break;
            }
        }
        if (! $wrote_bricks) {
            return '';
        }
        // Bricks in inline CSS mode regenerates per request: no files to rebuild.
        $file_mode = class_exists('\\Bricks\\Database') && \Bricks\Database::get_setting('cssLoading') === 'file';
        if (! $file_mode) {
            return '';
        }
        if (! class_exists('\\Bricks\\Assets_Files') || ! method_exists('\\Bricks\\Assets_Files', 'regenerate_css_files')) {
            return 'generated_css_not_rebuilt: Bricks CSS is in external-file mode but no supported regeneration call is available; a builder save or cache rebuild is required for rendered output.';
        }
        $files = \Bricks\Assets_Files::regenerate_css_files();
        $count = is_array($files) ? count($files) : 0;

        return sprintf('generated_css_rebuilt: %d Bricks CSS file(s) regenerated after %s via Bricks\\Assets_Files::regenerate_css_files().', $count, $context);
    }

    private function build_receipt(array $base, array $items, array $journal, $started)
    {
        $counts = ['applied' => 0, 'noop' => 0, 'stale' => 0, 'failed' => 0, 'compensated' => 0, 'unsupported' => 0];
        foreach ($items as $item) {
            $counts[$item['outcome']] = ($counts[$item['outcome']] ?? 0) + 1;
        }
        $writes = $counts['applied'] + $counts['compensated'] + $counts['failed'];
        if ($counts['compensated'] > 0) {
            $outcome = 'compensated';
        } elseif ($counts['failed'] > 0) {
            $outcome = 'failed';
        } elseif ($counts['applied'] > 0) {
            $outcome = ($counts['stale'] + $counts['unsupported']) > 0 ? 'partial' : 'applied';
        } elseif ($counts['stale'] > 0) {
            $outcome = 'stale';
        } elseif ($counts['unsupported'] > 0) {
            $outcome = 'unsupported';
        } else {
            $outcome = 'noop';
        }
        unset($writes);

        return array_merge($base, [
            'outcome' => $outcome,
            'counts' => $counts,
            'items' => $items,
            'journal' => $journal,
            'warnings' => array_values(array_filter([$this->bricks_css_warning($items, ['applied', 'compensated'], 'apply')])),
            'finished_at' => gmdate('c'),
            'note' => 'Conditional writes against the receipt\'s storage fingerprints; verified through the read-only observer; a lost response must be resolved by asking for this operation, never by executing again.',
        ]);
    }

    /**
     * @param array<string, mixed> $base
     * @param string               $outcome
     * @param string               $error
     * @param string               $hub_url
     * @param bool                 $journal_row Whether to create a journal row for this refused execution.
     * @return array<string, mixed>
     */
    private function finish_without_writes(array $base, $outcome, $error, $hub_url, $journal_row = true)
    {
        $receipt = array_merge($base, [
            'outcome' => $outcome,
            'counts' => ['applied' => 0, 'noop' => 0, 'stale' => 0, 'failed' => 0, 'compensated' => 0, 'unsupported' => 0],
            'items' => [],
            'journal' => [['step' => 'precheck', 'result' => $error]],
            'warnings' => [],
            'error' => $error,
            'finished_at' => gmdate('c'),
            'note' => 'Refused before any write.',
        ]);
        if ($journal_row) {
            $created = $this->operations->start(['operation_id' => $base['operation_id'], 'approval_uid' => $base['approval_uid'], 'release_uid' => $base['release_uid'], 'release_digest' => $base['release_digest'], 'receipt_digest' => $base['receipt_digest'], 'installation_epoch' => $base['target']['epoch'], 'before_image' => []], $hub_url);
            if ($created === 'created') {
                $this->operations->finish($base['operation_id'], $receipt, $receipt['journal']);
            }
        }

        return $receipt;
    }
}
