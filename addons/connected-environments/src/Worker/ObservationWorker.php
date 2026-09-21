<?php

namespace Dbvc\Connected\Worker;

use Dbvc\Connected\Adapters\BricksOptionCollectionObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Adapters\ServicePostObserver;
use Dbvc\Connected\Capture\DirtyCapture;
use Dbvc\Connected\Identity\InstanceIdentity;
use Dbvc\Connected\Storage\JobStore;
use Dbvc\Connected\Storage\ObjectStore;
use Dbvc\Connected\Storage\OutboxStore;
use Dbvc\Connected\Storage\Schema;
use Dbvc\Connected\Storage\StateStore;
use Dbvc\Connected\Storage\Transaction;
use Dbvc\ConnectedProtocol\ObservationEvent;

/**
 * Background observation batch.
 *
 * Algorithm per claimed dirty marker:
 *
 * 1. Claim with a lease token and the generation captured at claim time.
 * 2. Re-read the persisted collection through the read-only observer.
 * 3. Assign missing instance identity (explicit writer step), compare each
 *    member's semantic hash with the stored projection and emit an immutable
 *    outbox event only for changed, new or verifiably removed members plus a
 *    separate collection-order projection.
 * 4. Acknowledge the marker only under the same lease and generation. A newer
 *    generation keeps the marker pending; a lost lease rolls everything back.
 *
 * Snapshot rows, outbox events, sequence allocation and the acknowledgement
 * are written inside one transaction. The worker never modifies Bricks
 * options or any other source content and performs no network requests.
 */
final class ObservationWorker
{
    public const DEFAULT_LIMIT = 50;
    public const DEFAULT_LEASE_SECONDS = 120;
    public const DEFAULT_BUDGET_SECONDS = 10;
    public const RETRY_BASE_SECONDS = 60;
    public const RETRY_MAX_SECONDS = 3600;

    /**
     * @var JobStore
     */
    private $jobs;

    /**
     * @var ObjectStore
     */
    private $objects;

    /**
     * @var OutboxStore
     */
    private $outbox;

    /**
     * @var StateStore
     */
    private $state;

    /**
     * @var InstanceIdentity
     */
    private $identity;

    public function __construct(JobStore $jobs, ObjectStore $objects, OutboxStore $outbox, StateStore $state, InstanceIdentity $identity)
    {
        $this->jobs = $jobs;
        $this->objects = $objects;
        $this->outbox = $outbox;
        $this->state = $state;
        $this->identity = $identity;
    }

    /**
     * WP-Cron entrypoint.
     *
     * @return void
     */
    public function run_from_cron()
    {
        $this->run(['context' => 'cron']);
    }

    /**
     * @param array<string, mixed> $options limit, lease_seconds, budget_seconds, context.
     * @return array<string, mixed> Run summary.
     */
    public function run(array $options = [])
    {
        $limit = isset($options['limit']) ? max(1, min(500, (int) $options['limit'])) : self::DEFAULT_LIMIT;
        $lease_seconds = isset($options['lease_seconds']) ? max(5, (int) $options['lease_seconds']) : self::DEFAULT_LEASE_SECONDS;
        $budget_seconds = isset($options['budget_seconds']) ? max(1, (int) $options['budget_seconds']) : self::DEFAULT_BUDGET_SECONDS;

        $summary = [
            'context' => isset($options['context']) ? substr((string) $options['context'], 0, 32) : 'manual',
            'started_at' => gmdate('c'),
            'finished_at' => null,
            'blocked' => null,
            'claimed' => 0,
            'acknowledged' => 0,
            'requeued' => 0,
            'retried' => 0,
            'lease_lost' => 0,
            'events' => 0,
            'tombstones' => 0,
            'objects_written' => 0,
            'identities_assigned' => 0,
            'jobs' => [],
            'pending_after' => null,
        ];

        Schema::reset_memo();
        if (! Schema::is_ready()) {
            $summary['blocked'] = 'schema_not_ready';
            $summary['finished_at'] = gmdate('c');
            return $summary;
        }

        $state = $this->state->get();
        if (! $this->state->is_initialized() || ! is_array($state)) {
            $summary['blocked'] = 'identity_uninitialized';
            $summary['finished_at'] = gmdate('c');
            $this->state_record($summary);
            return $summary;
        }

        $deadline = microtime(true) + $budget_seconds;
        $claims = $this->jobs->claim_due($limit, $lease_seconds);
        $summary['claimed'] = count($claims);

        foreach ($claims as $claim) {
            if (microtime(true) > $deadline) {
                $this->jobs->retry($claim, 'budget_exhausted', time());
                $summary['retried']++;
                $summary['jobs'][] = ['job_id' => $claim['job_id'], 'domain' => $claim['domain'], 'outcome' => 'budget_exhausted'];
                continue;
            }

            $result = $this->process_claim($claim, $state);
            $summary['jobs'][] = $result;
            $summary['events'] += (int) $result['events'];
            $summary['tombstones'] += (int) $result['tombstones'];
            $summary['objects_written'] += (int) $result['objects_written'];
            $summary['identities_assigned'] += (int) $result['identities_assigned'];
            switch ($result['outcome']) {
                case JobStore::OUTCOME_ACKNOWLEDGED:
                    $summary['acknowledged']++;
                    break;
                case JobStore::OUTCOME_GENERATION_ADVANCED:
                    $summary['requeued']++;
                    break;
                case JobStore::OUTCOME_LEASE_LOST:
                    $summary['lease_lost']++;
                    break;
                default:
                    $summary['retried']++;
            }
        }

        $summary['pending_after'] = $this->jobs->counts();
        if ($summary['pending_after']['total'] > 0) {
            DirtyCapture::schedule_processing(DirtyCapture::processing_delay());
        }
        if ($summary['events'] > 0 && $state['connection_state'] === StateStore::CONNECTION_ENROLLED) {
            DirtyCapture::schedule_delivery(DirtyCapture::processing_delay());
        }
        $summary['finished_at'] = gmdate('c');
        $this->state_record($summary);

        return $summary;
    }

    /**
     * @param array<string, mixed> $claim
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private function process_claim(array $claim, array $state)
    {
        $result = [
            'job_id' => $claim['job_id'],
            'domain' => $claim['domain'],
            'object_key' => $claim['object_key'],
            'generation' => $claim['generation'],
            'outcome' => 'retry',
            'reason' => '',
            'events' => 0,
            'tombstones' => 0,
            'objects_written' => 0,
            'identities_assigned' => 0,
            'members' => 0,
            'problems' => [],
        ];

        $observer = DomainRegistry::observer_for($claim['domain']);
        if ($observer === null) {
            $this->jobs->retry($claim, 'unsupported_domain', time() + self::RETRY_MAX_SECONDS);
            $result['reason'] = 'unsupported_domain';
            return $result;
        }

        if (strpos((string) $claim['object_key'], 'post:') === 0) {
            return $this->process_object_claim($claim, $state, $observer, $result);
        }

        $inventory = $observer->inventory(['limit' => 0]);
        if ($inventory['status'] !== 'available') {
            $reason = 'source_' . $inventory['status'] . ($inventory['reason'] !== '' ? ':' . $inventory['reason'] : '');
            $this->jobs->retry($claim, $reason, time() + $this->backoff((int) $claim['attempts']));
            $result['reason'] = $reason;
            $result['problems'] = $inventory['problems'];
            return $result;
        }

        $result['members'] = count($inventory['items']);
        $result['problems'] = $inventory['problems'];

        $transaction = new Transaction();
        if (! $transaction->begin()) {
            $this->jobs->retry($claim, 'transaction_begin_failed', time() + $this->backoff((int) $claim['attempts']));
            $result['reason'] = 'transaction_begin_failed';
            return $result;
        }

        try {
            $signal = isset($claim['context']) && is_array($claim['context']) ? $claim['context'] : [];
            $origin = isset($signal['origin']) && in_array($signal['origin'], ObservationEvent::ORIGINS, true) ? $signal['origin'] : 'human';
            $causation_id = isset($signal['causation_id']) && ObservationEvent::isIdentifier($signal['causation_id']) ? $signal['causation_id'] : null;
            $context = [
                'domain' => $observer->domain(),
                'profile' => $observer->profile(),
                'environment_id' => (string) $state['environment_id'],
                'epoch' => (string) $state['installation_epoch'],
                'storage_fingerprint' => $inventory['storage_fingerprint'],
                'origin' => $origin,
                'causation_id' => $causation_id,
            ];

            $existing = $this->objects->get_for_domain($context['domain'], $context['profile']);
            $owns_identity = DomainRegistry::is_post_domain($context['domain']);
            $storage_keys = array_map(static function ($item) {
                return $item['storage_key'];
            }, $inventory['items']);
            $identity_map = $owns_identity ? [] : $this->identity->lookup_many($context['domain'], $storage_keys);
            $current_uids = [];

            foreach ($inventory['items'] as $item) {
                $storage_key = $item['storage_key'];
                if ($owns_identity) {
                    // Portable identity comes from the domain (DBVC vf_object_uid); it is never assigned here.
                    $instance_uid = (string) ($item['instance_uid'] ?? '');
                    if ($instance_uid === '') {
                        $result['problems'][] = 'identity_missing:' . $storage_key;
                        continue;
                    }
                } elseif (isset($identity_map[$storage_key])) {
                    $instance_uid = $identity_map[$storage_key]['instance_uid'];
                } else {
                    $instance_uid = $this->identity->assign($context['domain'], $storage_key, $item['display_name']);
                    if ($instance_uid === null) {
                        $result['problems'][] = 'identity_assignment_failed:' . $storage_key;
                        continue;
                    }
                    $result['identities_assigned']++;
                }
                $current_uids[$instance_uid] = true;

                $row = $existing[$instance_uid] ?? null;
                // A different epoch (re-enrollment) re-emits every member so the new identity has a complete history.
                $unchanged = is_array($row)
                    && $row['observed_epoch'] === $context['epoch']
                    && $row['object_exists'] === 1
                    && $row['semantic_hash'] === $item['hash']
                    && $row['snapshot_complete'] === ($item['complete'] ? 1 : 0);
                if ($unchanged) {
                    continue;
                }

                $this->emit($context, [
                    'instance_uid' => $instance_uid,
                    'storage_key' => $storage_key,
                    'display_name' => $item['display_name'],
                    'exists' => true,
                    'complete' => (bool) $item['complete'],
                    'hash' => $item['hash'],
                    'canonical' => $item['canonical'],
                    'position' => $item['position'],
                    'problems' => $item['problems'],
                ]);
                $result['events']++;
                $result['objects_written']++;
            }

            // Verified removals: only after a complete read of the authoritative collection.
            if (! empty($inventory['complete'])) {
                foreach ($existing as $instance_uid => $row) {
                    if ($row['object_exists'] !== 1 || isset($current_uids[$instance_uid])) {
                        continue;
                    }
                    $this->emit($context, [
                        'instance_uid' => $instance_uid,
                        'storage_key' => $row['storage_key'],
                        'display_name' => $row['display_name'],
                        'exists' => false,
                        'complete' => true,
                        'hash' => BricksOptionCollectionObserver::absent_hash(),
                        'canonical' => null,
                        'position' => null,
                        'problems' => [],
                    ]);
                    $result['events']++;
                    $result['tombstones']++;
                    $result['objects_written']++;
                }
            }

            // Collection order (Bricks) / inventory (posts) is a separately identified projection so neighbour
            // reorders and coverage are visible.
            if ($inventory['order_hash'] !== null) {
                $emitted = $this->emit_order_projection($observer, $context, $inventory['order_hash'], $inventory['order_canonical'], ! empty($inventory['complete']));
                $result['events'] += $emitted;
                $result['objects_written'] += $emitted;
            }

            $outcome = $this->jobs->complete($claim, DirtyCapture::processing_delay());
            if ($outcome === JobStore::OUTCOME_LEASE_LOST) {
                $transaction->rollback();
                $result['outcome'] = $outcome;
                $result['reason'] = 'lease_lost_before_commit';
                $result['events'] = 0;
                $result['tombstones'] = 0;
                $result['objects_written'] = 0;
                $result['identities_assigned'] = 0;
                return $result;
            }

            $transaction->commit();
            $result['outcome'] = $outcome;
        } catch (\Throwable $exception) {
            $transaction->rollback();
            $reason = 'exception:' . substr(preg_replace('/[^A-Za-z0-9_]+/', '_', $exception->getMessage()), 0, 100);
            $this->jobs->retry($claim, $reason, time() + $this->backoff((int) $claim['attempts']));
            $result['outcome'] = 'retry';
            $result['reason'] = $reason;
            $result['events'] = 0;
            $result['tombstones'] = 0;
            $result['objects_written'] = 0;
            $result['identities_assigned'] = 0;
        }

        return $result;
    }

    /**
     * Per-object path for domains that own portable identity (`post:<id>` markers).
     * A verified single-object read decides existence; a missing post whose
     * projection row is known becomes a tombstone.
     *
     * @param array<string, mixed>                       $claim
     * @param array<string, mixed>                       $state
     * @param \Dbvc\ConnectedProtocol\DomainObserver     $observer
     * @param array<string, mixed>                       $result
     * @return array<string, mixed>
     */
    private function process_object_claim(array $claim, array $state, $observer, array $result)
    {
        $storage_key = substr((string) $claim['object_key'], 5);
        $snapshot = $observer->snapshot(['storage_key' => $storage_key]);
        $result['members'] = 1;
        $result['problems'] = (array) ($snapshot['problems'] ?? []);

        if (in_array($snapshot['status'], ['unavailable', 'unsupported'], true)) {
            $reason = 'source_' . $snapshot['status'] . (! empty($snapshot['reason']) ? ':' . $snapshot['reason'] : '');
            $this->jobs->retry($claim, $reason, time() + $this->backoff((int) $claim['attempts']));
            $result['reason'] = $reason;
            return $result;
        }
        if ($snapshot['status'] === 'identity_missing') {
            // Explicit condition: DBVC assigns vf_object_uid on the next ordinary save; never backfill here.
            $this->jobs->retry($claim, 'identity_missing', time() + $this->backoff((int) $claim['attempts']));
            $result['reason'] = 'identity_missing';
            return $result;
        }

        $signal = isset($claim['context']) && is_array($claim['context']) ? $claim['context'] : [];
        $origin = isset($signal['origin']) && in_array($signal['origin'], ObservationEvent::ORIGINS, true) ? $signal['origin'] : 'human';
        $context = [
            'domain' => $observer->domain(),
            'profile' => $observer->profile(),
            'environment_id' => (string) $state['environment_id'],
            'epoch' => (string) $state['installation_epoch'],
            'storage_fingerprint' => $snapshot['storage_fingerprint'] ?? null,
            'origin' => $origin,
            'causation_id' => isset($signal['causation_id']) && ObservationEvent::isIdentifier($signal['causation_id']) ? $signal['causation_id'] : null,
        ];

        $transaction = new Transaction();
        if (! $transaction->begin()) {
            $this->jobs->retry($claim, 'transaction_begin_failed', time() + $this->backoff((int) $claim['attempts']));
            $result['reason'] = 'transaction_begin_failed';
            return $result;
        }
        try {
            if ($snapshot['status'] === 'missing') {
                $row = $this->objects->find_by_storage_key($context['domain'], $storage_key, $context['profile']);
                if (is_array($row) && $row['object_exists'] === 1) {
                    $this->emit($context, [
                        'instance_uid' => $row['instance_uid'],
                        'storage_key' => $storage_key,
                        'display_name' => $row['display_name'],
                        'exists' => false,
                        'complete' => true,
                        'hash' => BricksOptionCollectionObserver::absent_hash(),
                        'canonical' => null,
                        'position' => null,
                        'problems' => [],
                    ]);
                    $result['events']++;
                    $result['tombstones']++;
                    $result['objects_written']++;
                }
            } else {
                $row = $this->objects->get($context['domain'], $snapshot['instance_uid'], $context['profile']);
                $unchanged = is_array($row)
                    && $row['observed_epoch'] === $context['epoch']
                    && $row['object_exists'] === 1
                    && $row['semantic_hash'] === $snapshot['hash']
                    && $row['snapshot_complete'] === ($snapshot['complete'] ? 1 : 0);
                if (! $unchanged) {
                    $this->emit($context, [
                        'instance_uid' => $snapshot['instance_uid'],
                        'storage_key' => $storage_key,
                        'display_name' => $snapshot['display_name'],
                        'exists' => true,
                        'complete' => (bool) $snapshot['complete'],
                        'hash' => $snapshot['hash'],
                        'canonical' => $snapshot['canonical'],
                        'position' => null,
                        'problems' => $snapshot['problems'],
                    ]);
                    $result['events']++;
                    $result['objects_written']++;
                }
            }

            // A single-object change can add or remove a member, so the domain's coverage projection is
            // re-read (IDs and UIDs only) and re-emitted when it changed.
            if ($observer instanceof ServicePostObserver) {
                $coverage = $observer->inventory_projection();
                if ($coverage['status'] === 'available') {
                    $emitted = $this->emit_order_projection($observer, $context, $coverage['hash'], $coverage['canonical'], (bool) $coverage['complete']);
                    $result['events'] += $emitted;
                    $result['objects_written'] += $emitted;
                    $result['problems'] = array_values(array_unique(array_merge($result['problems'], $coverage['problems'])));
                }
            }

            $outcome = $this->jobs->complete($claim, DirtyCapture::processing_delay());
            if ($outcome === JobStore::OUTCOME_LEASE_LOST) {
                $transaction->rollback();
                $result['outcome'] = $outcome;
                $result['reason'] = 'lease_lost_before_commit';
                $result['events'] = 0;
                $result['tombstones'] = 0;
                $result['objects_written'] = 0;
                return $result;
            }
            $transaction->commit();
            $result['outcome'] = $outcome;
        } catch (\Throwable $exception) {
            $transaction->rollback();
            $reason = 'exception:' . substr(preg_replace('/[^A-Za-z0-9_]+/', '_', $exception->getMessage()), 0, 100);
            $this->jobs->retry($claim, $reason, time() + $this->backoff((int) $claim['attempts']));
            $result['outcome'] = 'retry';
            $result['reason'] = $reason;
            $result['events'] = 0;
            $result['tombstones'] = 0;
            $result['objects_written'] = 0;
        }

        return $result;
    }

    /**
     * Emit the domain's order/inventory projection (`collection.order` under the
     * observer's order profile) when it differs from the stored row.
     *
     * @param \Dbvc\ConnectedProtocol\DomainObserver $observer
     * @param array<string, mixed>                   $context
     * @param string                                 $hash
     * @param string|null                            $canonical
     * @param bool                                   $complete
     * @return int Events emitted (0 or 1).
     */
    private function emit_order_projection($observer, array $context, $hash, $canonical, $complete)
    {
        $profile = (string) ($observer->capabilities()['order_profile'] ?? '');
        if ($profile === '') {
            return 0;
        }
        $row = $this->objects->get($context['domain'], BricksOptionCollectionObserver::ORDER_INSTANCE_UID, $profile);
        $unchanged = is_array($row)
            && $row['observed_epoch'] === $context['epoch']
            && $row['semantic_hash'] === $hash
            && $row['snapshot_complete'] === ($complete ? 1 : 0);
        if ($unchanged) {
            return 0;
        }
        $this->emit(array_merge($context, ['profile' => $profile, 'storage_fingerprint' => null]), [
            'instance_uid' => BricksOptionCollectionObserver::ORDER_INSTANCE_UID,
            'storage_key' => '',
            'display_name' => $observer instanceof ServicePostObserver ? 'Inventory' : 'Collection order',
            'exists' => true,
            'complete' => $complete,
            'hash' => $hash,
            'canonical' => $canonical,
            'position' => null,
            'problems' => [],
        ]);

        return 1;
    }

    /**
     * Allocate a sequence, persist the immutable event and the projection row.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $object
     * @return void
     * @throws \RuntimeException
     */
    private function emit(array $context, array $object)
    {
        $sequence = $this->state->allocate_sequence();
        if ($sequence === null) {
            throw new \RuntimeException('sequence_allocation_failed');
        }

        $event_id = 'obs-' . str_pad((string) $sequence, 12, '0', STR_PAD_LEFT);
        $body = ObservationEvent::build([
            'event_id' => $event_id,
            'environment_id' => $context['environment_id'],
            'installation_epoch' => $context['epoch'],
            'sequence' => $sequence,
            'observed_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'object' => [
                'domain' => $context['domain'],
                'instance_uid' => $object['instance_uid'],
            ],
            'projection' => [
                'profile' => $context['profile'],
                'hash' => $object['hash'],
                'exists' => (bool) $object['exists'],
                'complete' => (bool) $object['complete'],
            ],
            'origin' => $context['origin'],
            'causation_id' => $context['causation_id'],
        ]);
        $errors = ObservationEvent::validate($body);
        if ($errors !== []) {
            throw new \RuntimeException('invalid_event_body:' . implode(',', $errors));
        }

        $inserted = $this->outbox->insert([
            'installation_epoch' => $context['epoch'],
            'event_id' => $event_id,
            'source_sequence' => $sequence,
            'domain' => $context['domain'],
            'instance_uid' => $object['instance_uid'],
            'body_digest' => ObservationEvent::digest($body),
            'immutable_body' => ObservationEvent::encode($body),
        ]);
        if (! $inserted) {
            throw new \RuntimeException('outbox_insert_failed');
        }

        $written = $this->objects->upsert([
            'domain' => $context['domain'],
            'instance_uid' => $object['instance_uid'],
            'profile' => $context['profile'],
            'storage_key' => $object['storage_key'],
            'display_name' => $object['display_name'],
            'exists' => $object['exists'],
            'complete' => $object['complete'],
            'semantic_hash' => $object['hash'],
            'storage_fingerprint' => $context['storage_fingerprint'],
            'position' => $object['position'],
            'observed_sequence' => $sequence,
            'observed_epoch' => $context['epoch'],
            'snapshot_body' => $object['canonical'],
            'problems' => $object['problems'],
        ]);
        if ($written === 'stale') {
            throw new \RuntimeException('projection_row_newer_than_observation');
        }
    }

    /**
     * @param int $attempts
     * @return int
     */
    private function backoff($attempts)
    {
        $exponent = max(0, min(6, $attempts - 1));
        $seconds = self::RETRY_BASE_SECONDS * (2 ** $exponent);
        $jitter = wp_rand(0, (int) max(1, $seconds / 5));

        return (int) min(self::RETRY_MAX_SECONDS, $seconds + $jitter);
    }

    /**
     * @param array<string, mixed> $summary
     * @return void
     */
    private function state_record(array $summary)
    {
        $compact = $summary;
        $compact['jobs'] = array_map(static function ($job) {
            return array_intersect_key($job, array_flip(['job_id', 'domain', 'outcome', 'reason', 'events', 'tombstones', 'members']));
        }, (array) $summary['jobs']);
        $this->state->record_worker_status($compact);
    }
}
