<?php

namespace Dbvc\AgencyControl\Comparison;

use Dbvc\AgencyControl\Storage\BaselineStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\InstanceLinkStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;
use Dbvc\ConnectedProtocol\Canonicalizer;
use Dbvc\ConnectedProtocol\Comparison;
use Dbvc\ConnectedProtocol\ObservationEvent;

/**
 * Read-only three-way environment comparison over the hub's stored
 * projections (each environment's own reports) and the pair's confirmed
 * baselines. Hash-level only: the hub holds identity, profile, hash,
 * existence and completeness — never content — so it classifies state but
 * cannot show path diffs (that needs separately authorized payload upload).
 *
 * Objects pair by equal instance UID (shared lineage: same-client clones,
 * portable vf_object_uid) or by an explicit operator link. Anything stale,
 * incomplete, unpaired or outside verified coverage is `unknown`, never clean.
 */
final class ComparisonService
{
    public const DEFAULT_FRESHNESS_SECONDS = 86400;
    public const ORDER_INSTANCE_UID = 'collection.order';

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

    /**
     * @var InstanceLinkStore
     */
    private $links;

    public function __construct()
    {
        $this->environments = new EnvironmentRegistry();
        $this->projections = new ProjectionStore();
        $this->baselines = new BaselineStore();
        $this->links = new InstanceLinkStore();
    }

    /**
     * @return int
     */
    public static function freshness_seconds()
    {
        /**
         * Seconds since an environment's last authenticated contact before its projections count as stale.
         *
         * @param int $seconds
         */
        return max(60, (int) apply_filters('dbvc_agency_control_freshness_seconds', self::DEFAULT_FRESHNESS_SECONDS));
    }

    /**
     * @param string      $source_environment_id
     * @param string      $target_environment_id
     * @param string|null $domain
     * @return array<string, mixed>|\WP_Error
     */
    public function compare($source_environment_id, $target_environment_id, $domain = null)
    {
        $source = $this->environments->find((string) $source_environment_id);
        $target = $this->environments->find((string) $target_environment_id);
        if ($source === null || $target === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Both environments must be enrolled.', ['status' => 404]);
        }
        if ($source['environment_id'] === $target['environment_id']) {
            return new \WP_Error('dbvc_agency_self_comparison', 'An environment cannot be compared with itself.', ['status' => 400]);
        }
        if ($source['agency_id'] !== $target['agency_id'] || $source['client_id'] !== $target['client_id']) {
            return new \WP_Error('dbvc_agency_scope_mismatch', 'Comparisons stay within one agency and client.', ['status' => 403]);
        }
        $domain = $domain !== null && $domain !== '' ? (string) $domain : null;
        if ($domain !== null && ! in_array($domain, ObservationEvent::DOMAINS, true)) {
            return new \WP_Error('dbvc_agency_invalid_domain', 'Unknown domain: ' . $domain, ['status' => 400]);
        }

        $freshness = self::freshness_seconds();
        $source_fresh = $this->is_fresh($source, $freshness);
        $target_fresh = $this->is_fresh($target, $freshness);

        $source_rows = $this->current_projections($source, $domain);
        $target_rows = $this->current_projections($target, $domain);
        $source_coverage = $this->coverage($source_rows);
        $target_coverage = $this->coverage($target_rows);
        $baselines = $this->baselines->for_pair($source['environment_id'], $target['environment_id'], $domain);
        $links = $this->links->map_for_pair($source['environment_id'], $target['environment_id'], $domain);
        $reverse_links = array_flip($links);

        $rows = [];
        $consumed_targets = [];
        foreach ($source_rows as $key => $projection) {
            $link_key = $projection['domain'] . '|' . $projection['instance_uid'];
            $target_uid = isset($links[$link_key]) ? $links[$link_key] : $projection['instance_uid'];
            $target_key = $projection['domain'] . '|' . $target_uid . '|' . $projection['profile'];
            $target_projection = $target_rows[$target_key] ?? null;
            $consumed_targets[$target_key] = true;
            $rows[] = $this->classify($projection, $target_projection, $target_uid, isset($links[$link_key]) ? 'link' : 'uid', $baselines, $source_coverage, $target_coverage, $source_fresh, $target_fresh, $source, $target);
        }
        foreach ($target_rows as $key => $projection) {
            if (isset($consumed_targets[$key])) {
                continue;
            }
            $link_key = $projection['domain'] . '|' . $projection['instance_uid'];
            $source_uid = isset($reverse_links[$link_key]) ? explode('|', $reverse_links[$link_key], 2)[1] : $projection['instance_uid'];
            $rows[] = $this->classify(null, $projection, $projection['instance_uid'], isset($reverse_links[$link_key]) ? 'link' : 'uid', $baselines, $source_coverage, $target_coverage, $source_fresh, $target_fresh, $source, $target, $source_uid);
        }

        usort($rows, static function ($a, $b) {
            return strcmp($a['domain'] . '|' . $a['profile'] . '|' . $a['source_instance_uid'], $b['domain'] . '|' . $b['profile'] . '|' . $b['source_instance_uid']);
        });

        $counts = ['synchronized' => 0, 'outgoing' => 0, 'incoming' => 0, 'converged' => 0, 'conflict' => 0, 'baseline_required' => 0, 'unknown' => 0];
        foreach ($rows as $row) {
            $counts[$row['state']]++;
        }

        return [
            'source' => ['environment_id' => $source['environment_id'], 'epoch' => $source['current_epoch'], 'status' => $source['status'], 'last_contact_at' => $source['last_contact_at'], 'fresh' => $source_fresh, 'coverage' => $source_coverage],
            'target' => ['environment_id' => $target['environment_id'], 'epoch' => $target['current_epoch'], 'status' => $target['status'], 'last_contact_at' => $target['last_contact_at'], 'fresh' => $target_fresh, 'coverage' => $target_coverage],
            'domain' => $domain,
            'freshness_seconds' => $freshness,
            'baselines' => count($baselines),
            'links' => count($links),
            'counts' => $counts,
            'rows' => $rows,
            'compared_at' => gmdate('c'),
            'note' => 'Hash-level classification of each environment\'s own reports; a zero count is not proof of clean state when coverage is incomplete or contact is stale.',
        ];
    }

    /**
     * @param array<string, mixed>|null $source_projection
     * @param array<string, mixed>|null $target_projection
     * @return array<string, mixed>
     */
    private function classify($source_projection, $target_projection, $target_uid, $pairing, array $baselines, array $source_coverage, array $target_coverage, $source_fresh, $target_fresh, array $source, array $target, $source_uid = null)
    {
        $reference = $source_projection ?: $target_projection;
        $domain = (string) $reference['domain'];
        $profile = (string) $reference['profile'];
        $source_uid = $source_uid !== null ? (string) $source_uid : (string) $reference['instance_uid'];
        $reasons = [];

        $source_side = $this->side($source_projection, $source_coverage[$domain] ?? false, 'source', $reasons);
        $target_side = $this->side($target_projection, $target_coverage[$domain] ?? false, 'target', $reasons);
        if (! $source_fresh) {
            $reasons[] = 'stale_source';
        }
        if (! $target_fresh) {
            $reasons[] = 'stale_target';
        }

        $baseline = $baselines[$domain . '|' . $source_uid . '|' . $profile] ?? null;
        $complete = $source_side['complete'] && $target_side['complete'];
        $fresh = $source_fresh && $target_fresh;

        if ($source_side['hash'] !== null && $target_side['hash'] !== null && ! $source_side['exists'] && ! $target_side['exists']) {
            $state = 'synchronized';
            $reasons[] = 'both_absent';
        } else {
            $state = Comparison::environment(
                $baseline !== null ? (string) $baseline['baseline_hash'] : null,
                $source_side['hash'],
                $target_side['hash'],
                [$profile, $profile, $profile],
                $complete,
                $fresh
            );
        }

        return [
            'domain' => $domain,
            'profile' => $profile,
            'source_instance_uid' => $source_uid,
            'target_instance_uid' => (string) $target_uid,
            'pairing' => $pairing,
            'state' => $state,
            'reasons' => array_values(array_unique($reasons)),
            'baseline_hash' => $baseline !== null ? (string) $baseline['baseline_hash'] : null,
            'source_hash' => $source_side['hash'],
            'target_hash' => $target_side['hash'],
            'source_exists' => $source_side['exists'],
            'target_exists' => $target_side['exists'],
            'source_observed' => $source_projection !== null,
            'target_observed' => $target_projection !== null,
            'source_sequence' => $source_projection !== null ? (int) $source_projection['observed_sequence'] : null,
            'target_sequence' => $target_projection !== null ? (int) $target_projection['observed_sequence'] : null,
            'complete' => $complete,
            'fresh' => $fresh,
        ];
    }

    /**
     * @param array<string, mixed>|null $projection
     * @param bool                      $coverage_complete
     * @param string                    $label
     * @param array<int, string>        $reasons
     * @return array{hash: string|null, exists: bool|null, complete: bool}
     */
    private function side($projection, $coverage_complete, $label, array &$reasons)
    {
        if ($projection === null) {
            if ($coverage_complete) {
                // Never observed inside a complete inventory: verified absent.
                return ['hash' => self::absent_hash(), 'exists' => false, 'complete' => true];
            }
            $reasons[] = $label . '_coverage_unknown';
            return ['hash' => null, 'exists' => null, 'complete' => false];
        }
        $complete = (int) $projection['snapshot_complete'] === 1;
        if (! $complete) {
            $reasons[] = 'incomplete_' . $label;
        }

        return ['hash' => (string) $projection['semantic_hash'], 'exists' => (int) $projection['object_exists'] === 1, 'complete' => $complete];
    }

    /**
     * Current-epoch projections keyed by "domain|instance_uid|profile".
     *
     * @param array<string, mixed> $environment
     * @param string|null          $domain
     * @return array<string, array<string, mixed>>
     */
    private function current_projections(array $environment, $domain)
    {
        $keyed = [];
        foreach ($this->projections->all($environment['environment_id'], 5000) as $row) {
            if ($row['installation_epoch'] !== $environment['current_epoch']) {
                continue;
            }
            if ($domain !== null && $row['domain'] !== $domain) {
                continue;
            }
            $keyed[$row['domain'] . '|' . $row['instance_uid'] . '|' . $row['profile']] = $row;
        }

        return $keyed;
    }

    /**
     * Domain coverage: a domain is fully known once its `collection.order`
     * projection (Bricks collection order, or the `wp.service` inventory of
     * portable UIDs) was reported complete; without one, never-observed
     * objects stay unknown.
     *
     * @param array<string, array<string, mixed>> $rows
     * @return array<string, bool>
     */
    private function coverage(array $rows)
    {
        $coverage = [];
        foreach ($rows as $row) {
            if ($row['instance_uid'] === self::ORDER_INSTANCE_UID) {
                $coverage[$row['domain']] = (int) $row['snapshot_complete'] === 1;
            }
        }

        return $coverage;
    }

    /**
     * @param array<string, mixed> $environment
     * @param int                  $freshness
     * @return bool
     */
    private function is_fresh(array $environment, $freshness)
    {
        if ($environment['status'] !== EnvironmentRegistry::STATUS_ENABLED || empty($environment['last_contact_at'])) {
            return false;
        }

        return (time() - (int) strtotime((string) $environment['last_contact_at'] . ' UTC')) <= $freshness;
    }

    /**
     * @return string
     */
    public static function absent_hash()
    {
        return Canonicalizer::hash(Canonicalizer::encode(null));
    }
}
