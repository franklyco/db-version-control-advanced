<?php

namespace Dbvc\AgencyControl\Framework;

use Dbvc\AgencyControl\Enrollment\SegmentService;
use Dbvc\AgencyControl\Storage\DefinitionStore;
use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\OverrideStore;
use Dbvc\ConnectedProtocol\ObservationEvent;

/**
 * Fleet framework publishing (M9 slice 4b), the "what changed" + "who gets it"
 * + "impact preview" of a framework rollout, with no execution.
 *
 * A "framework version" is not one row: it is many per-object definition
 * versions ({@see FrameworkService::publish}) that share a version label.
 * {@see publish()} stamps that label across the golden source's objects and
 * marks each desired. {@see plan()} then composes, read-only, what a rollout
 * of those desired versions WOULD do to each subscribing environment — one
 * planned action per object per target — so the studio can preview impact
 * before pushing.
 *
 * The plan honours client work exactly as parity enforcement does: an object
 * under an approved (or rebase-flagged) override is `skip_override`, never
 * overwritten. It is the same locked set {@see OverrideStore::locked_for_environment}
 * that {@see \Dbvc\AgencyControl\Parity\SyncDriver} skips at release-build time.
 * Every `create`/`replace` item names the operation and target a per-target
 * release would carry; execution (release fan-out + rollout) lands in a later
 * slice. Nothing here writes to any environment.
 */
final class FrameworkPublishService
{
    /**
     * @var FrameworkService
     */
    private $framework;

    /**
     * @var FrameworkStatusService
     */
    private $status;

    /**
     * @var DefinitionStore
     */
    private $definitions;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    public function __construct()
    {
        $this->framework = new FrameworkService();
        $this->status = new FrameworkStatusService();
        $this->definitions = new DefinitionStore();
        $this->environments = new EnvironmentRegistry();
    }

    /**
     * Publish one version label across a golden source's objects. Each object
     * becomes an immutable per-object definition version read from the source's
     * current complete projection; re-publishing the same (definition, version)
     * is an idempotent no-op that still re-points desired when asked.
     *
     * @param array<string, mixed> $args agency_id, source_environment_id, version, channel, note, desire,
     *                                   objects: array<int, array{definition_uid, domain, instance_uid, profile}>
     * @return array<string, mixed>|\WP_Error
     */
    public function publish(array $args)
    {
        $agency_id = (string) ($args['agency_id'] ?? 'studio');
        $source_environment_id = (string) ($args['source_environment_id'] ?? '');
        $version = (string) ($args['version'] ?? '');
        $channel = (string) ($args['channel'] ?? '');
        $channel = $channel !== '' ? $channel : 'stable';
        $note = (string) ($args['note'] ?? '');
        $desire = ! array_key_exists('desire', $args) || ! empty($args['desire']);

        if (! ObservationEvent::isIdentifier($version)) {
            return new \WP_Error('dbvc_agency_invalid_identifier', 'version must be a bounded ASCII identifier.', ['status' => 400]);
        }
        $objects = isset($args['objects']) && is_array($args['objects']) ? array_values($args['objects']) : [];
        if ($objects === []) {
            return new \WP_Error('dbvc_agency_no_objects', 'Name at least one object (definition_uid + domain + instance_uid + profile) to publish.', ['status' => 400]);
        }

        // Fail fast on the source: publishing a framework version from a
        // stale or foreign source is never right, and reports a clearer error
        // than each object failing its own hash read.
        $environment = $this->environments->find($source_environment_id);
        if ($environment === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Source environment not found.', ['status' => 404]);
        }
        if ($environment['agency_id'] !== $agency_id) {
            return new \WP_Error('dbvc_agency_scope_mismatch', 'The source environment belongs to another agency.', ['status' => 403]);
        }
        if ($environment['status'] !== EnvironmentRegistry::STATUS_ENABLED) {
            return new \WP_Error('dbvc_agency_environment_not_enabled', 'The source environment is not enabled.', ['status' => 409]);
        }
        if (! FrameworkStatusService::is_fresh($environment)) {
            return new \WP_Error('dbvc_agency_environment_stale', 'The source environment\'s last contact is older than the freshness window.', ['status' => 409]);
        }

        $results = [];
        $summary = ['published' => 0, 'exists' => 0, 'error' => 0, 'desired' => 0];
        foreach ($objects as $object) {
            $result = $this->publish_object($agency_id, $source_environment_id, $version, $channel, $note, $desire, is_array($object) ? $object : []);
            $summary[$result['status']]++;
            if (! empty($result['desired'])) {
                $summary['desired']++;
            }
            $results[] = $result;
        }

        return [
            'agency_id' => $agency_id,
            'source_environment_id' => $source_environment_id,
            'version' => $version,
            'channel' => $channel,
            'summary' => $summary,
            'objects' => $results,
            'note' => 'Each object is an immutable per-object definition version sharing this version label; re-publishing the same version is a no-op.',
        ];
    }

    /**
     * Read-only rollout plan: for each subscribing environment, the action a
     * rollout of the given (or all desired) definitions WOULD take per object.
     * Composes {@see FrameworkStatusService::status()} — it applies nothing and
     * promotes no client edit.
     *
     * @param array<string, mixed> $args agency_id, definitions (array|csv of definition_uid),
     *                                   cohorts (array|csv of environment_id), client_id
     * @return array<string, mixed>|\WP_Error
     */
    public function plan(array $args)
    {
        $agency_id = (string) ($args['agency_id'] ?? 'studio');
        $client_id = isset($args['client_id']) && (string) $args['client_id'] !== '' ? (string) $args['client_id'] : null;
        $definition_uids = $this->id_list($args['definitions'] ?? []);
        if ($definition_uids === []) {
            $definition_uids = $this->desired_definition_uids($agency_id);
        }
        $cohorts = $this->id_list($args['cohorts'] ?? []);
        $cohort_filter = $cohorts !== [] ? array_fill_keys($cohorts, true) : null;

        // Optional segment (env tag): restrict targets to same-agency sites carrying the tag.
        $segment = (string) ($args['segment'] ?? '');
        $segment_filter = $segment !== ''
            ? array_fill_keys((new SegmentService())->environments_for_segment($segment, $agency_id), true)
            : null;

        $environments = [];
        $targets = [];
        foreach ($definition_uids as $definition_uid) {
            foreach ($this->status->status(null, $definition_uid)['rows'] as $row) {
                $env_id = (string) $row['environment_id'];
                if ($cohort_filter !== null && ! isset($cohort_filter[$env_id])) {
                    continue;
                }
                if ($segment_filter !== null && ! isset($segment_filter[$env_id])) {
                    continue;
                }
                if (! array_key_exists($env_id, $environments)) {
                    $environments[$env_id] = $this->environments->find($env_id);
                }
                $env = $environments[$env_id];
                // Scope to the requested agency; a never-found environment cannot be planned against.
                if ($env === null || $env['agency_id'] !== $agency_id) {
                    continue;
                }
                if ($env['status'] === EnvironmentRegistry::STATUS_REVOKED) {
                    continue;
                }
                if ($client_id !== null && $env['client_id'] !== $client_id) {
                    continue;
                }
                if (! isset($targets[$env_id])) {
                    $targets[$env_id] = $this->new_target($env);
                }
                $targets[$env_id]['items'][] = $this->plan_item($row);
            }
        }

        $target_rows = [];
        $summary = ['targets' => 0, 'will_update' => 0, 'protected' => 0, 'up_to_date' => 0, 'not_reporting' => 0, 'attention' => 0, 'create' => 0, 'replace' => 0, 'skip_override' => 0];
        foreach ($targets as $target) {
            $counts = ['create' => 0, 'replace' => 0, 'skip_override' => 0, 'up_to_date' => 0, 'blocked' => 0, 'attention' => 0];
            foreach ($target['items'] as $item) {
                $counts[$item['action']]++;
            }
            $target['counts'] = $counts;
            $target['state'] = $this->target_state($target['fresh'], $counts);
            usort($target['items'], static function ($a, $b) {
                return strcmp($a['definition_uid'] . '|' . $a['domain'] . '|' . $a['instance_uid'], $b['definition_uid'] . '|' . $b['domain'] . '|' . $b['instance_uid']);
            });
            $summary['targets']++;
            $summary[$target['state']]++;
            $summary['create'] += $counts['create'];
            $summary['replace'] += $counts['replace'];
            $summary['skip_override'] += $counts['skip_override'];
            $target_rows[] = $target;
        }

        usort($target_rows, static function ($a, $b) {
            return strcmp($a['client_id'] . '|' . $a['environment_id'], $b['client_id'] . '|' . $b['environment_id']);
        });

        return [
            'generated_at' => gmdate('Y-m-d H:i:s'),
            'agency_id' => $agency_id,
            'definitions' => $definition_uids,
            'cohorts' => $cohorts,
            'segment' => $segment,
            'summary' => $summary,
            'targets' => $target_rows,
            'note' => 'Read-only preview composed from each environment\'s own framework status; an approved override is skip_override, never overwritten, and nothing is applied here.',
        ];
    }

    /**
     * @param string               $agency_id
     * @param string               $source_environment_id
     * @param string               $version
     * @param string               $channel
     * @param string               $note
     * @param bool                 $desire
     * @param array<string, mixed> $object
     * @return array<string, mixed>
     */
    private function publish_object($agency_id, $source_environment_id, $version, $channel, $note, $desire, array $object)
    {
        $definition_uid = (string) ($object['definition_uid'] ?? '');
        $domain = (string) ($object['domain'] ?? '');
        $instance_uid = (string) ($object['instance_uid'] ?? '');
        $profile = (string) ($object['profile'] ?? '');
        $base = [
            'definition_uid' => $definition_uid,
            'domain' => $domain,
            'instance_uid' => $instance_uid,
            'profile' => $profile,
        ];

        $result = $this->framework->publish([
            'agency_id' => $agency_id,
            'definition_uid' => $definition_uid,
            'version' => $version,
            'channel' => $channel,
            'domain' => $domain,
            'profile' => $profile,
            'from_environment_id' => $source_environment_id,
            'from_instance_uid' => $instance_uid,
            'desired' => $desire,
            'note' => $note,
        ]);

        if (! is_wp_error($result)) {
            return array_merge($base, [
                'status' => 'published',
                'hash' => (string) $result['definition_hash'],
                'version_order' => (int) $result['version_order'],
                'desired' => (bool) $result['desired'],
            ]);
        }

        // Versions are immutable: an already-published version is an idempotent
        // no-op. Still (re-)point desired when asked, so a re-run can repair it.
        if ($result->get_error_code() === 'dbvc_agency_definition_exists') {
            $desired = false;
            if ($desire) {
                $marked = $this->framework->set_desired($agency_id, $definition_uid, $version);
                $desired = ! is_wp_error($marked);
            }

            return array_merge($base, ['status' => 'exists', 'desired' => $desired]);
        }

        return array_merge($base, [
            'status' => 'error',
            'desired' => false,
            'error' => $result->get_error_code(),
            'message' => $result->get_error_message(),
        ]);
    }

    /**
     * The rollout action for one framework-status row. Mirrors the primitives a
     * per-target release honours: locked objects are skipped, absent objects are
     * created (guarded creation), present drifting/behind objects are replaced.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function plan_item(array $row)
    {
        $action = $this->classify_action($row);
        $item = [
            'definition_uid' => (string) $row['definition_uid'],
            'domain' => (string) $row['domain'],
            'instance_uid' => (string) $row['instance_uid'],
            'profile' => $row['profile'],
            'desired_version' => $row['desired_version'],
            'adopted_version' => (string) $row['adopted_version'],
            'action' => $action,
            'version' => (string) $row['version'],
            'drift' => (string) $row['drift'],
            'override_state' => $row['override_state'],
            'reasons' => $row['reasons'],
        ];
        if ($action === 'create' || $action === 'replace') {
            $item['operation'] = $action;
        }

        return $item;
    }

    /**
     * @param array<string, mixed> $row
     * @return string create|replace|skip_override|up_to_date|blocked|attention
     */
    private function classify_action(array $row)
    {
        // A site that is not reporting cannot be planned against safely.
        if (empty($row['fresh'])) {
            return 'blocked';
        }
        // Approved (or rebase-flagged) client work is protected, exactly as the
        // locked set a per-target release build skips.
        if ($row['override_state'] === OverrideStore::STATE_APPROVED || $row['override_state'] === OverrideStore::STATE_NEEDS_REBASE_REVIEW) {
            return 'skip_override';
        }
        // Nothing to push without a desired version, or when the site already
        // matches it, or when the site is ahead of it (never a downgrade).
        if ($row['desired_version'] === null) {
            return 'attention';
        }
        if ($row['version'] === 'ahead_version' || $row['version'] === 'channel_mismatch') {
            return 'attention';
        }
        if ($row['version'] === 'current' && $row['drift'] === 'clean' && $row['exists'] === true) {
            return 'up_to_date';
        }
        // A reported-absent object is created; a present object is replaced.
        // A guarded creation (slice 4a) refuses an id/name collision, so an
        // inferred create on a never-observed instance is safe at apply time.
        if ($row['exists'] === false) {
            return 'create';
        }
        if ($row['exists'] === true) {
            return 'replace';
        }

        return 'create';
    }

    /**
     * @param bool                       $fresh
     * @param array<string, int>         $counts
     * @return string
     */
    private function target_state($fresh, array $counts)
    {
        if (! $fresh) {
            return 'not_reporting';
        }
        if ($counts['attention'] > 0) {
            return 'attention';
        }
        if ($counts['create'] > 0 || $counts['replace'] > 0) {
            return 'will_update';
        }
        if ($counts['skip_override'] > 0) {
            return 'protected';
        }

        return 'up_to_date';
    }

    /**
     * @param array<string, mixed> $env
     * @return array<string, mixed>
     */
    private function new_target(array $env)
    {
        return [
            'environment_id' => (string) $env['environment_id'],
            'client_id' => (string) $env['client_id'],
            'agency_id' => (string) $env['agency_id'],
            'label' => (string) $env['label'],
            'enrollment_status' => (string) $env['status'],
            'tags' => array_values((array) ($env['tags'] ?? [])),
            'fresh' => FrameworkStatusService::is_fresh($env),
            'items' => [],
        ];
    }

    /**
     * @param string $agency_id
     * @return array<int, string>
     */
    private function desired_definition_uids($agency_id)
    {
        $uids = [];
        foreach ($this->definitions->all(null, 500) as $row) {
            if ((int) $row['desired'] === 1 && (string) $row['agency_id'] === $agency_id) {
                $uids[(string) $row['definition_uid']] = true;
            }
        }

        return array_keys($uids);
    }

    /**
     * Accept a comma-separated string or an array of identifiers.
     *
     * @param mixed $value
     * @return array<int, string>
     */
    private function id_list($value)
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $out[$item] = true;
            }
        }

        return array_keys($out);
    }
}
