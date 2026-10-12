<?php

namespace Dbvc\AgencyControl\Fleet;

use Dbvc\AgencyControl\Comparison\ComparisonService;
use Dbvc\AgencyControl\Framework\FrameworkStatusService;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\OverrideStore;

/**
 * Read-only fleet rollup for the hub dashboard (M9 slice 3): one row per
 * enrolled client environment with a composed site-level status, framework
 * version distance, locked (approved-override) count and freshness — the
 * "desired-vs-observed" view that makes hands-off fleet pushing feel safe.
 *
 * It composes existing reports, never a new comparison: {@see FrameworkStatusService}
 * for per-object drift + version distance, {@see OverrideStore} for locked client
 * work, and the {@see EnvironmentRegistry} for enrollment + contact freshness.
 * It writes nothing; a stale, revoked or never-reporting site is never rolled up
 * as in sync. Segments (env tags) and framework publishing land in later slices;
 * a site with no framework subscription simply reports no version distance.
 */
final class FleetService
{
    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    /**
     * @var FrameworkStatusService
     */
    private $framework;

    /**
     * @var OverrideStore
     */
    private $overrides;

    public function __construct()
    {
        $this->environments = new EnvironmentRegistry();
        $this->framework = new FrameworkStatusService();
        $this->overrides = new OverrideStore();
    }

    /**
     * @param string|null $agency_id Restrict to one agency (the hub's own), or all when null.
     * @param string|null $client_id Restrict to one client, or all when null.
     * @param string|null $segment   Restrict to sites carrying this tag (M9 slice 5), or all when null.
     * @return array<string, mixed>
     */
    public function overview($agency_id = null, $client_id = null, $segment = null)
    {
        $agency_id = $agency_id !== null && $agency_id !== '' ? (string) $agency_id : null;
        $client_id = $client_id !== null && $client_id !== '' ? (string) $client_id : null;
        $segment = $segment !== null && $segment !== '' ? (string) $segment : null;

        // One framework-status pass, grouped per environment.
        $framework_rows = [];
        foreach ($this->framework->status()['rows'] as $row) {
            $framework_rows[$row['environment_id']][] = $row;
        }

        $sites = [];
        $summary = ['sites' => 0, 'in_sync' => 0, 'update_available' => 0, 'protected' => 0, 'needs_review' => 0, 'not_reporting' => 0];
        foreach ($this->environments->all($client_id, 500) as $env) {
            if ($agency_id !== null && $env['agency_id'] !== $agency_id) {
                continue;
            }
            // A revoked environment has left the fleet; it is not rolled up.
            if ($env['status'] === EnvironmentRegistry::STATUS_REVOKED) {
                continue;
            }
            if ($segment !== null && ! in_array($segment, (array) $env['tags'], true)) {
                continue;
            }
            $site = $this->roll_up($env, $framework_rows[$env['environment_id']] ?? []);
            $sites[] = $site;
            $summary['sites']++;
            $summary[$site['state']]++;
        }

        usort($sites, static function ($a, $b) {
            return strcmp($a['client_id'] . '|' . $a['environment_id'], $b['client_id'] . '|' . $b['environment_id']);
        });

        return [
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'freshness_seconds' => ComparisonService::freshness_seconds(),
            'summary' => $summary,
            'sites' => $sites,
            'note' => 'Composed from each environment\'s own framework-status report, approved overrides and last contact; a stale or never-reporting site is never rolled up as in sync.',
        ];
    }

    /**
     * @param array<string, mixed>             $env
     * @param array<int, array<string, mixed>> $framework_rows Framework-status rows for this environment.
     * @return array<string, mixed>
     */
    private function roll_up(array $env, array $framework_rows)
    {
        $fresh = FrameworkStatusService::is_fresh($env);

        // Locked (approved-override) client work on this environment.
        $locked = $this->overrides->locked_for_environment($env['environment_id']);
        $needs_rebase = 0;
        $locked_instances = [];
        foreach ($locked as $override) {
            if ($override['state'] === OverrideStore::STATE_NEEDS_REBASE_REVIEW) {
                $needs_rebase++;
            }
            if (count($locked_instances) < 10) {
                $locked_instances[] = [
                    'domain' => (string) $override['domain'],
                    'instance_uid' => (string) $override['instance_uid'],
                    'state' => (string) $override['state'],
                ];
            }
        }

        // Framework version distance, worst across this environment's subscriptions.
        $behind = false;
        $adopted_version = null;
        $desired_version = null;
        foreach ($framework_rows as $row) {
            if ($row['version'] === 'behind_version') {
                $behind = true;
            }
            if ($adopted_version === null && $row['adopted_version'] !== '') {
                $adopted_version = (string) $row['adopted_version'];
            }
            if ($desired_version === null && $row['desired_version'] !== null) {
                $desired_version = (string) $row['desired_version'];
            }
        }

        // Composed site state, worst-wins.
        if (! $fresh) {
            $state = 'not_reporting';
        } elseif ($needs_rebase > 0) {
            $state = 'needs_review';
        } elseif ($behind) {
            $state = 'update_available';
        } elseif (count($locked) > 0) {
            $state = 'protected';
        } else {
            $state = 'in_sync';
        }

        return [
            'environment_id' => (string) $env['environment_id'],
            'client_id' => (string) $env['client_id'],
            'agency_id' => (string) $env['agency_id'],
            'label' => (string) $env['label'],
            'enrollment_status' => (string) $env['status'],
            'tags' => array_values((array) ($env['tags'] ?? [])),
            'state' => $state,
            'fresh' => $fresh,
            'last_contact_at' => (string) ($env['last_contact_at'] ?? ''),
            'framework' => $framework_rows !== []
                ? ['adopted_version' => $adopted_version, 'desired_version' => $desired_version, 'behind' => $behind]
                : null,
            'locked' => count($locked),
            'needs_rebase_review' => $needs_rebase,
            'locked_instances' => $locked_instances,
        ];
    }
}
