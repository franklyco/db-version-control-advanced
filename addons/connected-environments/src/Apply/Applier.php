<?php

namespace Dbvc\Connected\Apply;

use Dbvc\Connected\Adapters\BricksOptionCollectionObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Storage\JobStore;
use Dbvc\Connected\Storage\OperationStore;
use Dbvc\Connected\Storage\PreparationStore;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Approved execution on the target, one supported domain family in this
 * step: Bricks option collections (global classes, global variables).
 *
 * The approval binds the prepare receipt this environment produced; the
 * applier re-reads every container immediately before writing and writes
 * only when the container's raw storage fingerprint still equals the one
 * the receipt recorded, with a single conditional SQL statement (`WHERE
 * SHA2(option_value) = <fingerprint>`), so an unrelated editor save in the
 * meantime makes the operation `stale` instead of being overwritten. A
 * verified before image is journalled before the write; the after-state is
 * verified through the read-only observer, and a failed verification is
 * compensated by the same conditional write back to the before image. Every
 * item of one container succeeds or fails together; containers are written
 * in manifest order. Service posts are `unsupported` here.
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
            if (! $observer instanceof BricksOptionCollectionObserver) {
                $result['outcome'] = 'unsupported';
                $result['error'] = 'domain_apply_not_supported_in_this_step';
                $results[$key] = $result;
                continue;
            }
            $plan[$observer->option_name()][] = ['key' => $key, 'observer' => $observer, 'manifest' => $manifest, 'prepared' => $prepared];
            $results[$key] = $result;
        }

        // Journal the verified before image of every container before the first write.
        $before_images = [];
        $stale_containers = [];
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

        $receipt_out = $this->build_receipt($base, array_values($results), $journal, $started);
        $this->operations->finish($base['operation_id'], $receipt_out, $journal);

        return $receipt_out;
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
            'warnings' => $counts['applied'] > 0 ? ['generated_css_not_rebuilt: Bricks CSS/cache regeneration is not performed by apply; a builder save or cache rebuild is required for rendered output.'] : [],
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
