<?php

namespace Dbvc\AgencyControl\Receipt;

use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\EventStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;
use Dbvc\AgencyControl\Storage\Transaction;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Scoped, durable observation receipt for an already authenticated and
 * trusted enrollment. Each event gets its own transaction and outcome:
 * accepted, duplicate (same scoped id and identical digest), conflict (same
 * id with a different digest, or sequence reuse) or rejected (invalid,
 * wrong environment or epoch). ACK means durable receipt, never delivery.
 */
final class ObservationReceipt
{
    /**
     * @var EventStore
     */
    private $events;

    /**
     * @var ProjectionStore
     */
    private $projections;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    public function __construct(?EventStore $events = null, ?ProjectionStore $projections = null, ?EnvironmentRegistry $environments = null)
    {
        $this->events = $events ?: new EventStore();
        $this->projections = $projections ?: new ProjectionStore();
        $this->environments = $environments ?: new EnvironmentRegistry();
    }

    /**
     * @param array<string, mixed>      $environment Trusted registry row.
     * @param array<int, mixed>         $events      Decoded candidate bodies.
     * @param string                    $batch_id
     * @param string                    $site_url    Sender-reported URL (recorded; hold decided by caller).
     * @return array<string, mixed>
     */
    public function accept_batch(array $environment, array $events, $batch_id = '', $site_url = '')
    {
        $outcomes = [];
        $counts = [
            Protocol::OUTCOME_ACCEPTED => 0,
            Protocol::OUTCOME_DUPLICATE => 0,
            Protocol::OUTCOME_CONFLICT => 0,
            Protocol::OUTCOME_REJECTED => 0,
        ];
        $max_sequence = 0;

        foreach ($events as $index => $candidate) {
            $outcome = $this->accept_one($environment, $candidate, $batch_id);
            $outcome['index'] = (int) $index;
            $outcomes[] = $outcome;
            $counts[$outcome['outcome']]++;
            if ($outcome['outcome'] === Protocol::OUTCOME_ACCEPTED) {
                $max_sequence = max($max_sequence, (int) $candidate['sequence']);
            }
        }

        $this->environments->record_contact($environment['environment_id'], $counts[Protocol::OUTCOME_ACCEPTED], $max_sequence, $batch_id, $site_url);

        return [
            'batch_id' => (string) $batch_id,
            'received_at' => gmdate('c'),
            'outcomes' => $outcomes,
            'counts' => $counts,
        ];
    }

    /**
     * @param array<string, mixed> $environment
     * @param mixed                $candidate
     * @param string               $batch_id
     * @return array<string, mixed>
     */
    private function accept_one(array $environment, $candidate, $batch_id)
    {
        $event_id = is_array($candidate) && isset($candidate['event_id']) && is_string($candidate['event_id']) ? $candidate['event_id'] : '';
        $errors = ObservationEvent::validate($candidate);
        if ($errors !== []) {
            return ['event_id' => $event_id, 'outcome' => Protocol::OUTCOME_REJECTED, 'reason' => 'invalid_event:' . $errors[0]];
        }
        if ($candidate['environment_id'] !== $environment['environment_id']) {
            return ['event_id' => $event_id, 'outcome' => Protocol::OUTCOME_REJECTED, 'reason' => 'environment_mismatch'];
        }
        if ($candidate['installation_epoch'] !== $environment['current_epoch']) {
            return ['event_id' => $event_id, 'outcome' => Protocol::OUTCOME_REJECTED, 'reason' => 'epoch_mismatch'];
        }

        try {
            $digest = ObservationEvent::digest($candidate);
            $body = ObservationEvent::encode($candidate);
        } catch (\Throwable $exception) {
            return ['event_id' => $event_id, 'outcome' => Protocol::OUTCOME_REJECTED, 'reason' => 'invalid_event:encoding'];
        }

        $transaction = new Transaction();
        $transaction->begin();
        $result = $this->events->insert([
            'environment_id' => $environment['environment_id'],
            'installation_epoch' => $environment['current_epoch'],
            'event_id' => $event_id,
            'source_sequence' => (int) $candidate['sequence'],
            'domain' => $candidate['object']['domain'],
            'instance_uid' => $candidate['object']['instance_uid'],
            'profile' => $candidate['projection']['profile'],
            'body_digest' => $digest,
            'immutable_body' => $body,
            'batch_id' => $batch_id,
        ]);

        if ($result === 'duplicate_event') {
            $transaction->rollback();
            $existing = $this->events->find($environment['environment_id'], $environment['current_epoch'], $event_id);
            if ($existing !== null && hash_equals((string) $existing['body_digest'], $digest)) {
                return ['event_id' => $event_id, 'outcome' => Protocol::OUTCOME_DUPLICATE, 'reason' => 'already_received'];
            }
            return ['event_id' => $event_id, 'outcome' => Protocol::OUTCOME_CONFLICT, 'reason' => 'event_digest_mismatch'];
        }
        if ($result === 'duplicate_sequence') {
            $transaction->rollback();
            return ['event_id' => $event_id, 'outcome' => Protocol::OUTCOME_CONFLICT, 'reason' => 'sequence_reuse'];
        }
        if ($result !== 'inserted') {
            $transaction->rollback();
            return ['event_id' => $event_id, 'outcome' => Protocol::OUTCOME_REJECTED, 'reason' => 'storage_error'];
        }

        $projection = $this->projections->upsert([
            'environment_id' => $environment['environment_id'],
            'installation_epoch' => $environment['current_epoch'],
            'domain' => $candidate['object']['domain'],
            'instance_uid' => $candidate['object']['instance_uid'],
            'profile' => $candidate['projection']['profile'],
            'semantic_hash' => $candidate['projection']['hash'],
            'object_exists' => $candidate['projection']['exists'],
            'snapshot_complete' => $candidate['projection']['complete'],
            'observed_sequence' => (int) $candidate['sequence'],
            'observed_at' => gmdate('Y-m-d H:i:s', (int) strtotime($candidate['observed_at'])),
            'event_id' => $event_id,
        ]);
        $transaction->commit();

        return ['event_id' => $event_id, 'outcome' => Protocol::OUTCOME_ACCEPTED, 'reason' => 'projection_' . $projection];
    }
}
