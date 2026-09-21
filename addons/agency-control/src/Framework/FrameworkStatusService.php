<?php

namespace Dbvc\AgencyControl\Framework;

use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Storage\DefinitionStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\OverrideStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;
use Dbvc\AgencyControl\Storage\SubscriptionStore;
use Dbvc\ConnectedProtocol\Comparison;

/**
 * Read-only framework status for every enabled framework subscription:
 * the environment's current projection hash against the adopted definition
 * version, the channel's desired version and any approved override, through
 * `Comparison::framework()`. Version distance uses the studio-supplied
 * `version_order` only; version strings are never compared. Stale contact,
 * incomplete projections and missing definitions report `unknown`, never
 * clean, and nothing here changes content, adoption or overrides.
 */
final class FrameworkStatusService
{
    /**
     * @var SubscriptionStore
     */
    private $subscriptions;

    /**
     * @var DefinitionStore
     */
    private $definitions;

    /**
     * @var OverrideStore
     */
    private $overrides;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    /**
     * @var ProjectionStore
     */
    private $projections;

    public function __construct()
    {
        $this->subscriptions = new SubscriptionStore();
        $this->definitions = new DefinitionStore();
        $this->overrides = new OverrideStore();
        $this->environments = new EnvironmentRegistry();
        $this->projections = new ProjectionStore();
    }

    /**
     * @param string|null $environment_id
     * @param string|null $definition_uid
     * @return array<string, mixed>
     */
    public function status($environment_id = null, $definition_uid = null)
    {
        $rows = [];
        $environments = [];
        foreach ($this->subscriptions->framework_subscriptions($environment_id, $definition_uid) as $subscription) {
            if ((int) $subscription['enabled'] !== 1) {
                continue;
            }
            $env_id = $subscription['source_environment_id'];
            if (! array_key_exists($env_id, $environments)) {
                $environments[$env_id] = $this->environments->find($env_id);
            }
            $rows[] = $this->classify($subscription, $environments[$env_id]);
        }

        usort($rows, static function ($a, $b) {
            return strcmp($a['environment_id'] . '|' . $a['definition_uid'] . '|' . $a['domain'] . '|' . $a['instance_uid'], $b['environment_id'] . '|' . $b['definition_uid'] . '|' . $b['domain'] . '|' . $b['instance_uid']);
        });

        $counts = ['drift' => ['clean' => 0, 'local_drift' => 0, 'approved_override' => 0, 'override_changed' => 0, 'unknown' => 0], 'version' => ['current' => 0, 'behind_version' => 0, 'ahead_version' => 0, 'channel_mismatch' => 0, 'version_differs' => 0, 'unknown' => 0], 'rebase_review' => 0];
        foreach ($rows as $row) {
            $counts['drift'][$row['drift']]++;
            $counts['version'][$row['version']]++;
            if ($row['override_state'] === OverrideStore::STATE_NEEDS_REBASE_REVIEW) {
                $counts['rebase_review']++;
            }
        }

        return [
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'freshness_seconds' => ComparisonService::freshness_seconds(),
            'counts' => $counts,
            'rows' => $rows,
            'note' => 'Hash-level report of each environment\'s own projections against studio definitions; it applies nothing and promotes no client edit.',
        ];
    }

    /**
     * @param array<string, mixed>      $subscription
     * @param array<string, mixed>|null $environment
     * @return array<string, mixed>
     */
    private function classify(array $subscription, $environment)
    {
        $reasons = [];
        $adopted = $subscription['adopted_version'] !== '' ? $this->definitions->get($subscription['agency_id'], $subscription['definition_uid'], $subscription['adopted_version']) : null;
        $desired = $this->definitions->desired($subscription['agency_id'], $subscription['definition_uid'], $subscription['channel']);
        if ($subscription['adopted_version'] === '') {
            $reasons[] = 'no_adopted_version';
        } elseif ($adopted === null) {
            $reasons[] = 'adopted_version_unknown';
        }
        if ($desired === null) {
            $reasons[] = 'no_desired_version';
        }
        $profile = $adopted !== null ? $adopted['profile'] : ($desired !== null ? $desired['profile'] : null);

        $actual = null;
        $exists = null;
        $complete = false;
        $fresh = false;
        $observed_sequence = 0;
        $projection = null;
        if ($environment === null) {
            $reasons[] = 'environment_missing';
        } else {
            $fresh = self::is_fresh($environment);
            if (! $fresh) {
                $reasons[] = $environment['status'] !== EnvironmentRegistry::STATUS_ENABLED ? 'environment_' . $environment['status'] : 'stale';
            }
            if ($profile !== null) {
                $projection = $this->projections->get($environment['environment_id'], $environment['current_epoch'], $subscription['domain'], $subscription['instance_uid'], $profile);
                if ($projection === null) {
                    $reasons[] = 'not_observed';
                } else {
                    $complete = (int) $projection['snapshot_complete'] === 1;
                    $exists = (int) $projection['object_exists'] === 1;
                    $observed_sequence = (int) $projection['observed_sequence'];
                    if (! $complete) {
                        $reasons[] = 'incomplete';
                    } elseif ($fresh) {
                        $actual = (string) $projection['semantic_hash'];
                    }
                }
            }
        }

        $override = $this->overrides->find($subscription['source_environment_id'], $subscription['domain'], $subscription['instance_uid'], $subscription['definition_uid']);
        $override_hash = $override !== null && $override['state'] !== OverrideStore::STATE_DETACHED ? (string) $override['approved_hash'] : null;

        $result = Comparison::framework(
            $actual,
            $adopted !== null ? (string) $adopted['definition_hash'] : null,
            $adopted !== null ? (string) $adopted['version'] : null,
            $desired !== null ? (string) $desired['version'] : null,
            $override_hash
        );
        $version = $result['version'];
        if ($version === 'version_differs' && $adopted !== null && $desired !== null) {
            if ($adopted['channel'] !== $desired['channel']) {
                $version = 'channel_mismatch';
            } elseif ($adopted['version_order'] < $desired['version_order']) {
                $version = 'behind_version';
            } elseif ($adopted['version_order'] > $desired['version_order']) {
                $version = 'ahead_version';
            }
        }

        return [
            'subscription_id' => (int) $subscription['subscription_id'],
            'environment_id' => $subscription['source_environment_id'],
            'client_id' => $subscription['client_id'],
            'domain' => $subscription['domain'],
            'instance_uid' => $subscription['instance_uid'],
            'definition_uid' => $subscription['definition_uid'],
            'profile' => $profile,
            'channel' => $subscription['channel'],
            'adopted_version' => $subscription['adopted_version'],
            'desired_version' => $desired !== null ? $desired['version'] : null,
            'drift' => $result['drift'],
            'version' => $version,
            'actual_hash' => $actual,
            'adopted_hash' => $adopted !== null ? $adopted['definition_hash'] : null,
            'desired_hash' => $desired !== null ? $desired['definition_hash'] : null,
            'override_hash' => $override_hash,
            'override_state' => $override !== null ? $override['state'] : null,
            'override_policy_revision' => $override !== null ? $override['policy_revision'] : null,
            'exists' => $exists,
            'complete' => $complete,
            'fresh' => $fresh,
            'observed_sequence' => $observed_sequence,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param array<string, mixed> $environment
     * @return bool
     */
    public static function is_fresh(array $environment)
    {
        if ($environment['status'] !== EnvironmentRegistry::STATUS_ENABLED || empty($environment['last_contact_at'])) {
            return false;
        }

        return (time() - (int) strtotime((string) $environment['last_contact_at'] . ' UTC')) <= ComparisonService::freshness_seconds();
    }
}
