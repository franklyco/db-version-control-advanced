<?php

namespace Dbvc\Connected\Preparation;

use Dbvc\Connected\Adapters\BricksOptionCollectionObserver;
use Dbvc\Connected\Adapters\DomainRegistry;
use Dbvc\Connected\Adapters\ServicePostObserver;
use Dbvc\Connected\Identity\InstanceIdentity;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\ObservationEvent;
use Dbvc\ConnectedProtocol\Protocol;

/**
 * Target-side dry run of a sealed release. For every manifest item it
 * resolves the local object by portable identity only (sidecar UID for
 * Bricks members, `vf_object_uid` for service posts; never name, slug or
 * position), reads the current persisted state through the read-only
 * observer, and reports either an exact whole-object patch (before/after
 * hashes, container, storage fingerprint, changed top-level paths) or an
 * explicit blocker. It never writes, never assigns identity, and the
 * receipt it returns expires; it is not permission to apply.
 */
final class Preparer
{
    public const OUTCOME_READY = 'ready';
    public const OUTCOME_NOOP = 'noop';
    public const OUTCOME_BLOCKED = 'blocked';

    /**
     * @var InstanceIdentity
     */
    private $identity;

    public function __construct(?InstanceIdentity $identity = null)
    {
        $this->identity = $identity ?: new InstanceIdentity();
    }

    /**
     * @param array<string, mixed> $request  operation_id, release {release_uid, digest, items[]}, receipt_ttl_seconds
     * @param array<string, mixed> $state    Connector state row (environment_id, installation_epoch).
     * @return array<string, mixed> The receipt.
     */
    public function prepare(array $request, array $state)
    {
        $release = is_array($request['release'] ?? null) ? $request['release'] : [];
        $ttl = max(60, (int) ($request['receipt_ttl_seconds'] ?? Protocol::PREPARE_RECEIPT_TTL_SECONDS));
        $now = time();
        $items = [];
        $counts = ['ready' => 0, 'noop' => 0, 'blocked' => 0];
        $manifest_items = array_values(array_filter((array) ($release['items'] ?? []), 'is_array'));
        foreach ($manifest_items as $item) {
            $result = $this->prepare_item($item, $manifest_items);
            $counts[$result['outcome']]++;
            $items[] = $result;
        }
        if ($counts['blocked'] > 0) {
            $outcome = ($counts['ready'] + $counts['noop']) > 0 ? 'partial' : 'blocked';
        } else {
            $outcome = $counts['ready'] > 0 ? 'ready' : 'noop';
        }

        return [
            'operation_id' => (string) ($request['operation_id'] ?? ''),
            'release_uid' => (string) ($release['release_uid'] ?? ''),
            'release_digest' => (string) ($release['digest'] ?? ''),
            'target' => ['environment_id' => (string) $state['environment_id'], 'epoch' => (string) $state['installation_epoch']],
            'compatibility' => [
                'protocol' => Protocol::PROTOCOL_VERSION,
                'canonicalizer' => Canonicalizer::VERSION,
                'plugin_version' => defined('DBVC_PLUGIN_VERSION') ? (string) DBVC_PLUGIN_VERSION : '',
                'domains' => $this->domain_availability(),
            ],
            'outcome' => $outcome,
            'counts' => $counts,
            'items' => $items,
            'prepared_at' => gmdate('c', $now),
            'expires_at' => gmdate('c', $now + $ttl),
            'note' => 'Dry run over current persisted state; nothing was written. A receipt expires and is not permission to apply.',
        ];
    }

    /**
     * @param array<string, mixed>             $item           domain, instance_uid, profile, operation, after_hash, body
     * @param array<int, array<string, mixed>> $manifest_items Every item of the release (dependency discovery).
     * @return array<string, mixed>
     */
    private function prepare_item(array $item, array $manifest_items)
    {
        $domain = (string) ($item['domain'] ?? '');
        $operation = (string) ($item['operation'] ?? 'replace');
        $instance_uid = (string) ($item['instance_uid'] ?? '');
        // The hub may name the local instance this source object is linked to (operator-declared lineage); default: same UID.
        $target_uid = isset($item['target_instance_uid']) && is_string($item['target_instance_uid']) && $item['target_instance_uid'] !== '' ? $item['target_instance_uid'] : $instance_uid;
        $profile = (string) ($item['profile'] ?? '');
        $after_hash = (string) ($item['after_hash'] ?? '');
        $body = $item['body'] ?? null;
        $result = [
            'domain' => $domain,
            'instance_uid' => $instance_uid,
            'target_instance_uid' => $target_uid,
            'profile' => $profile,
            'operation' => $operation,
            'after_hash' => $after_hash,
            'before_hash' => null,
            'outcome' => self::OUTCOME_BLOCKED,
            'blockers' => [],
            'identity' => ['storage_key' => null, 'source' => null],
            'container' => null,
            'storage_fingerprint' => null,
            'exists' => null,
            'complete' => null,
            'patch' => null,
            'dependencies' => null,
        ];

        if (! in_array($operation, ['replace', 'delete'], true) || ! ObservationEvent::isIdentifier($instance_uid) || ! ObservationEvent::isIdentifier($target_uid) || ! is_string($body) || $body === '' || ! preg_match('/^[a-f0-9]{64}$/', $after_hash)) {
            $result['blockers'][] = 'invalid_manifest_item';
            return $result;
        }
        if ($operation === 'delete' && $after_hash !== BricksOptionCollectionObserver::absent_hash()) {
            $result['blockers'][] = 'invalid_manifest_item:deletion_without_absence';
            return $result;
        }
        if (Canonicalizer::hash($body) !== $after_hash) {
            $result['blockers'][] = 'payload_hash_mismatch';
            return $result;
        }
        $observer = DomainRegistry::observer_for($domain);
        if ($observer === null) {
            $result['blockers'][] = 'unsupported_domain';
            return $result;
        }
        if ($observer->profile() !== $profile) {
            $result['blockers'][] = 'profile_mismatch:' . $observer->profile();
            return $result;
        }
        $capabilities = $observer->capabilities();
        if (empty($capabilities['report'])) {
            $result['blockers'][] = 'domain_unavailable:' . (string) ($capabilities['reason'] ?? 'unknown');
            return $result;
        }

        // Identity: portable UID → local storage key, or an explicit creation decision.
        $resolved = $this->resolve_identity($domain, $target_uid, $observer);
        $result['identity'] = ['storage_key' => $resolved['storage_key'], 'source' => $resolved['source'] . ($target_uid !== $instance_uid ? '+link' : '')];
        if ($resolved['blocker'] !== null) {
            $result['before_hash'] = BricksOptionCollectionObserver::absent_hash();
            $result['exists'] = false;
            $result['container'] = $this->container($domain, null, $observer);
            if ($operation === 'delete' && $resolved['blocker'] === 'identity_unmatched:creation_requires_decision') {
                // Deleting something this environment never had: nothing to do, nothing to decide.
                $result['complete'] = true;
                $result['outcome'] = self::OUTCOME_NOOP;
                return $result;
            }
            $result['blockers'][] = $resolved['blocker'];
            return $result;
        }

        $snapshot = $observer->snapshot(['storage_key' => $resolved['storage_key']]);
        $result['container'] = $this->container($domain, $resolved['storage_key'], $observer);
        $result['storage_fingerprint'] = isset($snapshot['storage_fingerprint']) ? $snapshot['storage_fingerprint'] : null;
        if (in_array($snapshot['status'], ['unavailable', 'unsupported'], true)) {
            $result['blockers'][] = 'source_' . $snapshot['status'] . (! empty($snapshot['reason']) ? ':' . $snapshot['reason'] : '');
            return $result;
        }
        if ($snapshot['status'] === 'identity_missing') {
            $result['blockers'][] = 'identity_missing';
            return $result;
        }
        if ($snapshot['status'] === 'missing') {
            // The identity mapping exists but the object is gone (deleted or trashed after observation).
            $result['before_hash'] = BricksOptionCollectionObserver::absent_hash();
            $result['exists'] = false;
            $result['complete'] = true;
            if ($operation === 'delete') {
                $result['outcome'] = self::OUTCOME_NOOP;
                return $result;
            }
            $result['blockers'][] = 'target_object_absent';
            return $result;
        }
        $result['exists'] = true;
        $result['complete'] = ! empty($snapshot['complete']);
        $result['before_hash'] = (string) $snapshot['semantic_hash'];
        if ($operation === 'delete') {
            // Removal of a present object: exact before-hash, no dependency discovery (nothing new is referenced).
            $result['patch'] = ['strategy' => 'remove_object', 'changed_paths' => [], 'preserves' => 'unrelated collection entries and environment-specific fields'];
            $result['outcome'] = self::OUTCOME_READY;
            return $result;
        }
        if (! $result['complete']) {
            $problems = array_values((array) ($snapshot['problems'] ?? []));
            $blocking = array_values(array_filter($problems, static function ($problem) {
                return strpos((string) $problem, 'masked:') !== 0;
            }));
            if ($blocking !== []) {
                $result['blockers'][] = 'target_projection_incomplete:' . implode(',', $problems);
                return $result;
            }
            // Masking-only incompleteness is a deliberate, deterministic exclusion (the
            // operator masks these fields for privacy), not missing data: the projection
            // hash over the managed (unmasked) set is authoritative on both sides, and
            // apply never writes or deletes a masked field. So the object stays appliable
            // over its unmasked fields; record which fields are excluded for transparency.
            $result['masked_fields'] = array_values(array_map(static function ($problem) {
                return substr((string) $problem, strlen('masked:'));
            }, $problems));
            $result['complete'] = true;
        }
        if ($result['before_hash'] === $after_hash) {
            $result['outcome'] = self::OUTCOME_NOOP;
            return $result;
        }
        $before = json_decode((string) ($snapshot['canonical'] ?? ''), true);
        $after = json_decode($body, true);
        if (! is_array($before) || ! is_array($after)) {
            $result['blockers'][] = 'canonical_unreadable';
            return $result;
        }
        $result['patch'] = self::patch($before, $after);
        // Content hashes the release carries bytes for (M7): a referenced attachment the
        // target lacks is still resolvable at apply because these bytes will be sideloaded.
        $carried_media_hashes = [];
        foreach (is_array($item['media'] ?? null) ? $item['media'] : [] as $media_entry) {
            if (is_array($media_entry) && isset($media_entry['hash']) && is_string($media_entry['hash'])) {
                $carried_media_hashes[] = $media_entry['hash'];
            }
        }
        $ledger = DependencyLedger::build($domain, $after, $manifest_items, $observer, $carried_media_hashes);
        $result['dependencies'] = $ledger;
        if ($ledger['blocking'] !== [] || ! $ledger['complete']) {
            $result['blockers'] = array_merge($result['blockers'], $ledger['blocking'] !== [] ? $ledger['blocking'] : ['dependency_discovery_incomplete']);
            return $result;
        }
        $result['outcome'] = self::OUTCOME_READY;

        return $result;
    }

    /**
     * @param string                                   $domain
     * @param string                                   $instance_uid
     * @param \Dbvc\ConnectedProtocol\DomainObserver   $observer
     * @return array{storage_key: string|null, source: string, blocker: string|null}
     */
    private function resolve_identity($domain, $instance_uid, $observer)
    {
        if ($observer instanceof ServicePostObserver) {
            $ids = get_posts([
                'post_type' => $observer->post_type(),
                'post_status' => 'any',
                'fields' => 'ids',
                'meta_key' => 'vf_object_uid', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_value' => $instance_uid, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'posts_per_page' => 2,
                'suppress_filters' => true,
                'no_found_rows' => true,
            ]);
            $ids = array_values(array_map('intval', (array) $ids));
            if ($ids === []) {
                return ['storage_key' => null, 'source' => 'vf_object_uid', 'blocker' => 'identity_unmatched:creation_requires_decision'];
            }
            if (count($ids) > 1) {
                return ['storage_key' => null, 'source' => 'vf_object_uid', 'blocker' => 'identity_ambiguous'];
            }

            return ['storage_key' => (string) $ids[0], 'source' => 'vf_object_uid', 'blocker' => null];
        }

        $mapping = $this->identity->find_by_instance_uid($domain, $instance_uid);
        if ($mapping === null) {
            return ['storage_key' => null, 'source' => 'sidecar', 'blocker' => 'identity_unmatched:creation_requires_decision'];
        }

        return ['storage_key' => $mapping['storage_key'], 'source' => 'sidecar', 'blocker' => null];
    }

    /**
     * @param string                                   $domain
     * @param string|null                              $storage_key
     * @param \Dbvc\ConnectedProtocol\DomainObserver   $observer
     * @return string
     */
    private function container($domain, $storage_key, $observer)
    {
        if ($observer instanceof ServicePostObserver) {
            return 'post_type:' . $observer->post_type() . ($storage_key !== null ? '#' . $storage_key : '');
        }
        $definitions = DomainRegistry::definitions();

        return 'option:' . (string) ($definitions[$domain]['option'] ?? $domain);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function domain_availability()
    {
        $out = [];
        foreach (DomainRegistry::observers() as $domain => $observer) {
            $capabilities = $observer->capabilities();
            $out[$domain] = ['available' => ! empty($capabilities['report']), 'reason' => (string) ($capabilities['reason'] ?? ''), 'profile' => $observer->profile()];
        }

        return $out;
    }

    /**
     * Whole-object replacement described at the top level: which keys would
     * be added, removed or changed, with per-key before/after hashes.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, mixed>
     */
    public static function patch(array $before, array $after)
    {
        $paths = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $in_before = array_key_exists($key, $before);
            $in_after = array_key_exists($key, $after);
            if ($in_before && $in_after && $before[$key] === $after[$key]) {
                continue;
            }
            $paths[] = [
                'path' => (string) $key,
                'operation' => $in_before ? ($in_after ? 'replace' : 'remove') : 'add',
                'before_hash' => $in_before ? Canonicalizer::hash(Canonicalizer::encode($before[$key])) : null,
                'after_hash' => $in_after ? Canonicalizer::hash(Canonicalizer::encode($after[$key])) : null,
            ];
        }
        usort($paths, static function ($a, $b) {
            return strcmp($a['path'], $b['path']);
        });

        return ['strategy' => 'replace_object', 'changed_paths' => $paths, 'preserves' => 'unrelated collection entries and environment-specific fields'];
    }
}
