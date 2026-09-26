<?php

namespace Dbvc\AgencyControl\Parity;

use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\SyncPolicyStore;
use Dbvc\ConnectedProtocol\ObservationEvent;

/**
 * CRUD + validation for a per-pair parity sync policy (M8). A policy is scoped
 * to one source→target pair within a single agency + client and is the operator
 * knob the {@see SyncDriver} reads. Config only — this class never writes content
 * or creates releases.
 */
final class SyncPolicyService
{
    /**
     * @var SyncPolicyStore
     */
    private $store;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    public function __construct()
    {
        $this->store = new SyncPolicyStore();
        $this->environments = new EnvironmentRegistry();
    }

    /**
     * Create or update the policy for a pair.
     *
     * @param array<string, mixed> $args source, target, mode, conflict_policy, scope_domains,
     *                                    include_uids, exclude_uids, create_new, propagate_deletions,
     *                                    max_objects, cadence, enabled, note.
     * @return array<string, mixed>|\WP_Error
     */
    public function set(array $args)
    {
        $source = $this->environments->find((string) ($args['source'] ?? ''));
        $target = $this->environments->find((string) ($args['target'] ?? ''));
        if ($source === null || $target === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Both environments must be enrolled.', ['status' => 404]);
        }
        if ($source['environment_id'] === $target['environment_id']) {
            return new \WP_Error('dbvc_agency_self_subscription', 'A pair cannot sync to itself.', ['status' => 400]);
        }
        if ($source['agency_id'] !== $target['agency_id'] || $source['client_id'] !== $target['client_id']) {
            return new \WP_Error('dbvc_agency_scope_mismatch', 'A sync policy stays within one agency and client.', ['status' => 403]);
        }

        $mode = (string) ($args['mode'] ?? 'manual');
        if (! in_array($mode, SyncPolicyStore::MODES, true)) {
            return new \WP_Error('dbvc_agency_invalid_mode', 'mode must be manual, assisted or auto.', ['status' => 400]);
        }
        $conflict = (string) ($args['conflict_policy'] ?? 'hold');
        if (! in_array($conflict, SyncPolicyStore::CONFLICT_POLICIES, true)) {
            return new \WP_Error('dbvc_agency_invalid_conflict_policy', 'conflict_policy must be hold, source_wins or skip.', ['status' => 400]);
        }
        $direction = (string) ($args['direction'] ?? 'push');
        if (! in_array($direction, SyncPolicyStore::DIRECTIONS, true)) {
            return new \WP_Error('dbvc_agency_invalid_direction', 'Only push direction is supported.', ['status' => 400]);
        }

        $scope = $this->clean_domains($args['scope_domains'] ?? []);
        if (is_wp_error($scope)) {
            return $scope;
        }

        $policy = [
            'agency_id' => $source['agency_id'],
            'client_id' => $source['client_id'],
            'source_environment_id' => $source['environment_id'],
            'target_environment_id' => $target['environment_id'],
            'direction' => $direction,
            'mode' => $mode,
            'scope_domains' => $scope,
            'include_uids' => array_values(array_filter(array_map('strval', (array) ($args['include_uids'] ?? [])), 'strlen')),
            'exclude_uids' => array_values(array_filter(array_map('strval', (array) ($args['exclude_uids'] ?? [])), 'strlen')),
            'conflict_policy' => $conflict,
            'create_new' => ! empty($args['create_new']),
            'propagate_deletions' => ! empty($args['propagate_deletions']),
            'max_objects' => max(1, (int) ($args['max_objects'] ?? 25)),
            'cadence' => (string) ($args['cadence'] ?? 'manual'),
            'note' => (string) ($args['note'] ?? ''),
        ];
        if (array_key_exists('enabled', $args)) {
            $policy['enabled'] = ! empty($args['enabled']);
        }

        return $this->store->upsert($policy);
    }

    /**
     * @param string $source
     * @param string $target
     * @return array<string, mixed>|null
     */
    public function get($source, $target)
    {
        return $this->store->find((string) $source, (string) $target);
    }

    /**
     * @param array<string, mixed> $args agency, client, limit.
     * @return array<int, array<string, mixed>>
     */
    public function all(array $args = [])
    {
        return $this->store->all(
            isset($args['agency']) ? (string) $args['agency'] : null,
            isset($args['client']) ? (string) $args['client'] : null,
            (int) ($args['limit'] ?? 50)
        );
    }

    /**
     * @param string $source
     * @param string $target
     * @return array<string, mixed>|\WP_Error
     */
    public function delete($source, $target)
    {
        if ($this->store->find((string) $source, (string) $target) === null) {
            return new \WP_Error('dbvc_agency_sync_policy_not_found', 'No sync policy for that pair.', ['status' => 404]);
        }
        $this->store->delete((string) $source, (string) $target);

        return ['deleted' => true, 'source' => (string) $source, 'target' => (string) $target];
    }

    /**
     * @param mixed $domains
     * @return array<int, string>|\WP_Error Empty array means "all subscribed domains".
     */
    private function clean_domains($domains)
    {
        $out = [];
        foreach ((array) $domains as $domain) {
            $domain = trim((string) $domain);
            if ($domain === '') {
                continue;
            }
            if (! ObservationEvent::isDomain($domain)) {
                return new \WP_Error('dbvc_agency_invalid_domain', 'Unknown domain in scope: ' . $domain, ['status' => 400]);
            }
            $out[$domain] = true;
        }

        return array_keys($out);
    }
}
