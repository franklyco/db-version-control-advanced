<?php

namespace Dbvc\AgencyControl\Enrollment;

use Dbvc\AgencyControl\Storage\EnvironmentRegistry;

/**
 * Environment tags / segments (M9 slice 5). Operators group enrolled sites by a
 * free slug — a vertical (`dental`), a tier (`production`, `staging`), a cohort
 * — and then target a framework rollout or preview, or filter the fleet, BY
 * SEGMENT across clients, instead of listing environment ids one agency+client
 * pair at a time. Tags are stored as a JSON list on each environment row; this
 * service validates them and resolves a segment to its environments.
 *
 * It only reads/writes the environment registry's own `tags`; it never touches
 * content, subscriptions or overrides.
 */
final class SegmentService
{
    /**
     * Max tags per environment and max bytes per tag slug.
     */
    public const MAX_TAGS = 20;
    public const MAX_TAG_BYTES = 32;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    public function __construct()
    {
        $this->environments = new EnvironmentRegistry();
    }

    /**
     * Replace an environment's tag set.
     *
     * @param string            $environment_id
     * @param array<int,string> $tags
     * @return array<string, mixed>|\WP_Error
     */
    public function set_tags($environment_id, array $tags)
    {
        $environment = $this->environments->find((string) $environment_id);
        if ($environment === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Environment not found.', ['status' => 404]);
        }
        $clean = self::sanitize_tags($tags);
        if (! $this->environments->update((string) $environment_id, ['tags' => wp_json_encode($clean)])) {
            return new \WP_Error('dbvc_agency_tags_error', 'Tags could not be recorded.', ['status' => 500]);
        }

        return ['environment_id' => (string) $environment_id, 'tags' => $clean];
    }

    /**
     * Add tags to an environment (union with its current tags).
     *
     * @param string            $environment_id
     * @param array<int,string> $tags
     * @return array<string, mixed>|\WP_Error
     */
    public function add_tags($environment_id, array $tags)
    {
        $environment = $this->environments->find((string) $environment_id);
        if ($environment === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Environment not found.', ['status' => 404]);
        }

        return $this->set_tags($environment_id, array_merge((array) $environment['tags'], $tags));
    }

    /**
     * Remove tags from an environment.
     *
     * @param string            $environment_id
     * @param array<int,string> $tags
     * @return array<string, mixed>|\WP_Error
     */
    public function remove_tags($environment_id, array $tags)
    {
        $environment = $this->environments->find((string) $environment_id);
        if ($environment === null) {
            return new \WP_Error('dbvc_agency_environment_not_found', 'Environment not found.', ['status' => 404]);
        }
        $drop = array_fill_keys(self::sanitize_tags($tags), true);
        $kept = array_values(array_filter((array) $environment['tags'], static function ($tag) use ($drop) {
            return ! isset($drop[$tag]);
        }));

        return $this->set_tags($environment_id, $kept);
    }

    /**
     * Every segment (distinct tag) with a count of the environments carrying it,
     * sorted by tag. Revoked environments are excluded.
     *
     * @param string|null $agency_id
     * @param string|null $client_id
     * @return array<string, mixed>
     */
    public function segments($agency_id = null, $client_id = null)
    {
        $counts = [];
        foreach ($this->fleet($agency_id, $client_id) as $environment) {
            foreach ((array) $environment['tags'] as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        ksort($counts);
        $segments = [];
        foreach ($counts as $tag => $count) {
            $segments[] = ['tag' => (string) $tag, 'environments' => (int) $count];
        }

        return ['returned' => count($segments), 'segments' => $segments];
    }

    /**
     * The enrolled, non-revoked environment ids that carry a tag.
     *
     * @param string      $tag
     * @param string|null $agency_id
     * @param string|null $client_id
     * @return array<int, string>
     */
    public function environments_for_segment($tag, $agency_id = null, $client_id = null)
    {
        $clean = self::sanitize_tags([$tag]);
        if ($clean === []) {
            return [];
        }
        $wanted = $clean[0];
        $ids = [];
        foreach ($this->fleet($agency_id, $client_id) as $environment) {
            if (in_array($wanted, (array) $environment['tags'], true)) {
                $ids[] = (string) $environment['environment_id'];
            }
        }

        return $ids;
    }

    /**
     * Normalise a list of tag slugs: lowercase ASCII [a-z0-9_-], bounded length,
     * de-duplicated, capped. Empty/invalid entries are dropped.
     *
     * @param array<int,string> $tags
     * @return array<int, string>
     */
    public static function sanitize_tags(array $tags)
    {
        $clean = [];
        foreach ($tags as $tag) {
            $slug = strtolower(trim((string) $tag));
            $slug = preg_replace('/[^a-z0-9_-]/', '', $slug);
            if ($slug === '' || $slug === null) {
                continue;
            }
            $slug = substr($slug, 0, self::MAX_TAG_BYTES);
            $clean[$slug] = true;
            if (count($clean) >= self::MAX_TAGS) {
                break;
            }
        }

        return array_keys($clean);
    }

    /**
     * Enrolled, non-revoked environments in scope.
     *
     * @param string|null $agency_id
     * @param string|null $client_id
     * @return array<int, array<string, mixed>>
     */
    private function fleet($agency_id, $client_id)
    {
        $out = [];
        foreach ($this->environments->all($client_id, 500) as $environment) {
            if ($environment['status'] === EnvironmentRegistry::STATUS_REVOKED) {
                continue;
            }
            if ($agency_id !== null && $agency_id !== '' && $environment['agency_id'] !== $agency_id) {
                continue;
            }
            $out[] = $environment;
        }

        return $out;
    }
}
