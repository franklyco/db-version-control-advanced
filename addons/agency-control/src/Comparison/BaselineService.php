<?php

namespace Dbvc\AgencyControl\Comparison;

use Dbvc\AgencyControl\Storage\BaselineStore;
use Dbvc\AgencyControl\Storage\InstanceLinkStore;
use Dbvc\ConnectedProtocol\ObservationEvent;

/**
 * Explicit baseline confirmation. Only an operator action records a baseline,
 * and only for objects whose current source and target projections agree
 * (state `converged`, or `baseline_required` with equal hashes) while both
 * sides are complete and fresh. The hub cannot re-read the sites, so it
 * records the sequences it agreed on; a later event from either side is
 * classified against that agreement.
 */
final class BaselineService
{
    /**
     * @var ComparisonService
     */
    private $comparison;

    /**
     * @var BaselineStore
     */
    private $baselines;

    /**
     * @var InstanceLinkStore
     */
    private $links;

    public function __construct()
    {
        $this->comparison = new ComparisonService();
        $this->baselines = new BaselineStore();
        $this->links = new InstanceLinkStore();
    }

    /**
     * @param string      $source_environment_id
     * @param string      $target_environment_id
     * @param string|null $domain
     * @param string|null $instance_uid  Source instance UID to confirm alone.
     * @param string      $note
     * @param bool        $accept_absent Also record an absent baseline for objects verified absent on exactly one side
     *                                   (declares "the other side never had this"), so they classify as outgoing/incoming.
     * @return array<string, mixed>|\WP_Error
     */
    public function confirm($source_environment_id, $target_environment_id, $domain = null, $instance_uid = null, $note = '', $accept_absent = false)
    {
        $comparison = $this->comparison->compare($source_environment_id, $target_environment_id, $domain);
        if (is_wp_error($comparison)) {
            return $comparison;
        }

        $confirmed = [];
        $skipped = [];
        foreach ($comparison['rows'] as $row) {
            if ($instance_uid !== null && $instance_uid !== '' && $row['source_instance_uid'] !== $instance_uid) {
                continue;
            }
            $agree = $row['state'] === 'converged' || ($row['state'] === 'baseline_required' && $row['source_hash'] !== null && $row['source_hash'] === $row['target_hash']);
            $baseline_hash = $row['source_hash'];
            if (! $agree && $accept_absent && $row['state'] === 'baseline_required' && ($row['source_exists'] === false xor $row['target_exists'] === false)) {
                // One side is verified absent: record that absence as the agreed starting point.
                $agree = true;
                $baseline_hash = ComparisonService::absent_hash();
            }
            if (! $agree || ! $row['complete'] || ! $row['fresh']) {
                $reason = $row['state'] === 'unknown' ? 'unknown:' . implode(',', (array) $row['reasons']) : ($agree ? 'not_complete_or_fresh' : 'no_agreement');
                $skipped[] = ['source_instance_uid' => $row['source_instance_uid'], 'domain' => $row['domain'], 'profile' => $row['profile'], 'state' => $row['state'], 'reason' => $reason];
                continue;
            }
            $this->baselines->upsert([
                'source_environment_id' => $comparison['source']['environment_id'],
                'target_environment_id' => $comparison['target']['environment_id'],
                'domain' => $row['domain'],
                'source_instance_uid' => $row['source_instance_uid'],
                'target_instance_uid' => $row['target_instance_uid'],
                'profile' => $row['profile'],
                'baseline_hash' => $baseline_hash,
                'source_sequence' => (int) $row['source_sequence'],
                'target_sequence' => (int) $row['target_sequence'],
                'source_epoch' => $comparison['source']['epoch'],
                'target_epoch' => $comparison['target']['epoch'],
                'confirmed_by' => get_current_user_id(),
                'note' => $note,
            ]);
            $confirmed[] = ['source_instance_uid' => $row['source_instance_uid'], 'domain' => $row['domain'], 'profile' => $row['profile'], 'baseline_hash' => $baseline_hash];
        }

        return [
            'source' => $comparison['source']['environment_id'],
            'target' => $comparison['target']['environment_id'],
            'confirmed' => count($confirmed),
            'skipped' => count($skipped),
            'confirmed_rows' => $confirmed,
            'skipped_rows' => $skipped,
        ];
    }

    /**
     * @param array<string, mixed> $args domain, source_environment_id, source_instance_uid, target_environment_id, target_instance_uid, note
     * @return array<string, mixed>|\WP_Error
     */
    public function link(array $args)
    {
        foreach (['source_instance_uid', 'target_instance_uid', 'source_environment_id', 'target_environment_id'] as $key) {
            if (! ObservationEvent::isIdentifier((string) ($args[$key] ?? ''))) {
                return new \WP_Error('dbvc_agency_invalid_identifier', $key . ' must be a bounded ASCII identifier.', ['status' => 400]);
            }
        }
        if (! in_array((string) ($args['domain'] ?? ''), ObservationEvent::DOMAINS, true)) {
            return new \WP_Error('dbvc_agency_invalid_domain', 'Unknown domain.', ['status' => 400]);
        }
        // Reuse the comparison's scope checks (same agency/client, both enrolled, not self).
        $scope = $this->comparison->compare((string) $args['source_environment_id'], (string) $args['target_environment_id'], (string) $args['domain']);
        if (is_wp_error($scope)) {
            return $scope;
        }
        $result = $this->links->create(array_merge($args, ['created_by' => get_current_user_id()]));

        return ['result' => $result, 'domain' => (string) $args['domain'], 'source_instance_uid' => (string) $args['source_instance_uid'], 'target_instance_uid' => (string) $args['target_instance_uid']];
    }
}
