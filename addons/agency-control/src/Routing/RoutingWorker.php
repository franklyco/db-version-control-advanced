<?php

namespace Dbvc\AgencyControl\Routing;

use Dbvc\AgencyControl\Storage\DeliveryStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\EventStore;
use Dbvc\AgencyControl\Storage\ReviewStore;
use Dbvc\AgencyControl\Storage\SubscriptionStore;
use Dbvc\AgencyControl\Storage\Transaction;

/**
 * Turns durably received events (`routing_state=pending`) into deliveries
 * and framework review items using the trusted registry, freezing the
 * policy revision on each row. Runs right after receipt and from the CLI;
 * a failure leaves the event pending for the next run.
 */
final class RoutingWorker
{
    public const DEFAULT_LIMIT = 200;

    /**
     * @var EventStore
     */
    private $events;

    /**
     * @var DeliveryStore
     */
    private $deliveries;

    /**
     * @var ReviewStore
     */
    private $reviews;

    /**
     * @var SubscriptionStore
     */
    private $subscriptions;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    public function __construct()
    {
        $this->events = new EventStore();
        $this->deliveries = new DeliveryStore();
        $this->reviews = new ReviewStore();
        $this->subscriptions = new SubscriptionStore();
        $this->environments = new EnvironmentRegistry();
    }

    /**
     * @param int $limit
     * @return array<string, mixed>
     */
    public function run($limit = self::DEFAULT_LIMIT)
    {
        $summary = ['claimed' => 0, 'routed' => 0, 'unroutable' => 0, 'deliveries' => 0, 'review_items' => 0, 'errors' => 0, 'jobs' => []];
        $snapshots = [];

        foreach ($this->events->pending_routing($limit) as $event) {
            $summary['claimed']++;
            $source_id = (string) $event['environment_id'];
            if (! isset($snapshots[$source_id])) {
                $source = $this->environments->find($source_id);
                $snapshots[$source_id] = $source === null ? null : array_merge($this->subscriptions->registry_snapshot($source), ['source' => $source]);
            }
            $snapshot = $snapshots[$source_id];
            $body = is_array($event['body']) ? $event['body'] : null;

            if ($snapshot === null || $body === null) {
                $this->events->mark_routed($event['event_row_id'], EventStore::ROUTING_UNROUTABLE, '');
                $summary['unroutable']++;
                $summary['jobs'][] = ['event_id' => $event['event_id'], 'outcome' => 'unroutable', 'reason' => $snapshot === null ? 'environment_missing' : 'body_unreadable'];
                continue;
            }

            try {
                $decision = RoutingPolicy::decide($body, $source_id, $snapshot['registry'], false);
            } catch (\InvalidArgumentException $exception) {
                // An event received under an epoch that has since rotated, or a revoked source: leave a visible unroutable mark.
                $this->events->mark_routed($event['event_row_id'], EventStore::ROUTING_UNROUTABLE, $snapshot['revision']);
                $summary['unroutable']++;
                $summary['jobs'][] = ['event_id' => $event['event_id'], 'outcome' => 'unroutable', 'reason' => $exception->getMessage()];
                continue;
            }

            $transaction = new Transaction();
            $transaction->begin();
            try {
                $created_deliveries = 0;
                foreach ($decision['client_targets'] as $target) {
                    $result = $this->deliveries->create([
                        'event_row_id' => $event['event_row_id'],
                        'source_environment_id' => $source_id,
                        'target_environment_id' => $target,
                        'domain' => (string) $event['domain'],
                        'policy_revision' => $snapshot['revision'],
                    ]);
                    if ($result === 'error') {
                        throw new \RuntimeException('delivery_insert_failed');
                    }
                    $created_deliveries += $result === 'created' ? 1 : 0;
                }
                $created_reviews = 0;
                foreach ($decision['framework_review'] as $definition_uid) {
                    $result = $this->reviews->create([
                        'event_row_id' => $event['event_row_id'],
                        'agency_id' => $snapshot['source']['agency_id'],
                        'client_id' => $snapshot['source']['client_id'],
                        'environment_id' => $source_id,
                        'domain' => (string) $event['domain'],
                        'instance_uid' => (string) $event['instance_uid'],
                        'definition_uid' => $definition_uid,
                        'installation_epoch' => (string) $event['installation_epoch'],
                        'event_sequence' => (int) $event['source_sequence'],
                        'policy_revision' => $snapshot['revision'],
                    ]);
                    if ($result === 'error') {
                        throw new \RuntimeException('review_insert_failed');
                    }
                    $created_reviews += $result === 'created' ? 1 : 0;
                }
                if (! $this->events->mark_routed($event['event_row_id'], EventStore::ROUTING_ROUTED, $snapshot['revision'])) {
                    throw new \RuntimeException('event_already_routed');
                }
                $transaction->commit();
                $summary['routed']++;
                $summary['deliveries'] += $created_deliveries;
                $summary['review_items'] += $created_reviews;
                $summary['jobs'][] = ['event_id' => $event['event_id'], 'outcome' => 'routed', 'targets' => $decision['client_targets'], 'review' => $decision['framework_review']];
            } catch (\Throwable $exception) {
                $transaction->rollback();
                $summary['errors']++;
                $summary['jobs'][] = ['event_id' => $event['event_id'], 'outcome' => 'error', 'reason' => $exception->getMessage()];
            }
        }

        return $summary;
    }
}
