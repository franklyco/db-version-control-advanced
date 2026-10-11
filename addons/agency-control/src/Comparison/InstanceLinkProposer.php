<?php

namespace Dbvc\AgencyControl\Comparison;

use Dbvc\AgencyControl\Storage\EnvironmentRegistry;
use Dbvc\AgencyControl\Storage\InstanceLinkStore;
use Dbvc\AgencyControl\Storage\ProjectionStore;

/**
 * Cross-site object identity auto-linker (M9 slice 0).
 *
 * Bricks option-collection members (global classes, variables, …) identify by
 * their site-local `id`, so the same framework object has a different
 * `instance_uid` on every environment and {@see ComparisonService} — which
 * pairs only by an equal `instance_uid` or an explicit `InstanceLinkStore`
 * link — reads them as unrelated. This service proposes those links by
 * matching the portable identity token each side now reports (the object's
 * name/token/slug, carried on the observation as `match_key` and stored on the
 * projection), reusing the same match-by-name model as the Bricks portability
 * registry's `match_order` (classes `[name,id]`, variables `[token,name,id]`, …;
 * the connector emits the first non-id field as the match key).
 *
 * Proposals are advisory: {@see propose()} never writes, and {@see accept()}
 * only creates a link that is still a current proposal, so nothing is ever
 * linked silently and a stale or forged pair cannot slip through. Ambiguous
 * names (a token that matches more than one object on either side) are reported
 * but never proposed. Once accepted, comparison, overrides and the dashboard
 * resolve the object through the link rather than the raw site-local id.
 */
final class InstanceLinkProposer
{
    /**
     * @var ComparisonService
     */
    private $comparison;

    /**
     * @var EnvironmentRegistry
     */
    private $environments;

    /**
     * @var ProjectionStore
     */
    private $projections;

    /**
     * @var InstanceLinkStore
     */
    private $links;

    /**
     * @var BaselineService
     */
    private $baselines;

    public function __construct()
    {
        $this->comparison = new ComparisonService();
        $this->environments = new EnvironmentRegistry();
        $this->projections = new ProjectionStore();
        $this->links = new InstanceLinkStore();
        $this->baselines = new BaselineService();
    }

    /**
     * Propose name/token links from source to target. Read-only.
     *
     * @param string      $source_environment_id
     * @param string      $target_environment_id
     * @param string|null $domain Restrict to one domain, or all when null.
     * @return array<string, mixed>|\WP_Error
     */
    public function propose($source_environment_id, $target_environment_id, $domain = null)
    {
        // Reuse the comparison scope gate: both enrolled, same agency + client,
        // not self, valid domain. Staleness never errors here (proposing over
        // stale projections is fine — the operator reviews before accepting).
        $scope = $this->comparison->compare((string) $source_environment_id, (string) $target_environment_id, $domain);
        if (is_wp_error($scope)) {
            return $scope;
        }

        $source = $this->environments->find((string) $source_environment_id);
        $target = $this->environments->find((string) $target_environment_id);
        $domain = $domain !== null && $domain !== '' ? (string) $domain : null;

        $source_rows = $this->linkable_projections($source, $domain);
        $target_rows = $this->linkable_projections($target, $domain);
        $links = $this->links->map_for_pair($source['environment_id'], $target['environment_id'], $domain);

        $target_by_key = [];
        foreach ($target_rows as $row) {
            $target_by_key[$this->group_key($row)][] = $row['instance_uid'];
        }
        $source_key_counts = [];
        $target_uids = [];
        foreach ($source_rows as $row) {
            $source_key_counts[$this->group_key($row)] = ($source_key_counts[$this->group_key($row)] ?? 0) + 1;
        }
        foreach ($target_rows as $row) {
            $target_uids[$row['domain'] . '|' . $row['profile'] . '|' . $row['instance_uid']] = true;
        }

        $proposals = [];
        $ambiguous = [];
        $already_linked = 0;
        $already_aligned = 0;
        $unmatched_source = 0;

        foreach ($source_rows as $row) {
            $link_key = $row['domain'] . '|' . $row['instance_uid'];
            if (isset($links[$link_key])) {
                $already_linked++;
                continue;
            }
            // A shared local id already aligns the pair; no link is needed.
            if (isset($target_uids[$row['domain'] . '|' . $row['profile'] . '|' . $row['instance_uid']])) {
                $already_aligned++;
                continue;
            }

            $group = $this->group_key($row);
            $candidates = array_values(array_filter(
                $target_by_key[$group] ?? [],
                static function ($uid) use ($row) {
                    return $uid !== $row['instance_uid'];
                }
            ));

            if ($candidates === []) {
                $unmatched_source++;
                continue;
            }
            if (count($candidates) > 1 || ($source_key_counts[$group] ?? 0) > 1) {
                $ambiguous[] = [
                    'domain' => $row['domain'],
                    'profile' => $row['profile'],
                    'match_key' => $row['match_key'],
                    'source_instance_uid' => $row['instance_uid'],
                    'target_candidates' => $candidates,
                    'reason' => count($candidates) > 1 ? 'multiple_target_matches' : 'duplicate_source_match_key',
                ];
                continue;
            }
            $proposals[] = [
                'domain' => $row['domain'],
                'profile' => $row['profile'],
                'match_key' => $row['match_key'],
                'source_instance_uid' => $row['instance_uid'],
                'target_instance_uid' => $candidates[0],
                'reason' => 'name_match',
            ];
        }

        return [
            'source' => $source['environment_id'],
            'target' => $target['environment_id'],
            'domain' => $domain,
            'considered' => count($source_rows),
            'proposals' => $proposals,
            'ambiguous' => $ambiguous,
            'already_linked' => $already_linked,
            'already_aligned' => $already_aligned,
            'unmatched_source' => $unmatched_source,
            'note' => 'Proposed by name/token match; nothing links until accepted. Ambiguous names are reported, never proposed.',
        ];
    }

    /**
     * Create links for accepted proposals. Each pair is re-checked against the
     * freshly recomputed proposal set, so only a pair that is still a current,
     * unambiguous name match is written — a stale or hand-supplied pair that is
     * not a current proposal is rejected, never linked.
     *
     * @param string                                   $source_environment_id
     * @param string                                   $target_environment_id
     * @param array<int, array<string, mixed>>|string  $pairs Explicit pairs, or the string 'all' for every current proposal.
     * @param string|null                              $domain
     * @return array<string, mixed>|\WP_Error
     */
    public function accept($source_environment_id, $target_environment_id, $pairs, $domain = null)
    {
        $current = $this->propose($source_environment_id, $target_environment_id, $domain);
        if (is_wp_error($current)) {
            return $current;
        }

        $allowed = [];
        foreach ($current['proposals'] as $proposal) {
            $allowed[$this->pair_key($proposal['domain'], $proposal['source_instance_uid'], $proposal['target_instance_uid'])] = $proposal;
        }

        $requested = [];
        if ($pairs === 'all' || $pairs === true || $pairs === '1' || $pairs === 1) {
            $requested = array_values($allowed);
        } elseif (is_array($pairs)) {
            foreach ($pairs as $pair) {
                if (! is_array($pair)) {
                    continue;
                }
                $requested[] = [
                    'domain' => (string) ($pair['domain'] ?? ''),
                    'source_instance_uid' => (string) ($pair['source_instance_uid'] ?? ''),
                    'target_instance_uid' => (string) ($pair['target_instance_uid'] ?? ''),
                ];
            }
        }

        $results = [];
        $rejected = [];
        $created = 0;
        foreach ($requested as $pair) {
            $key = $this->pair_key($pair['domain'], $pair['source_instance_uid'], $pair['target_instance_uid']);
            if (! isset($allowed[$key])) {
                $rejected[] = array_merge($pair, ['reason' => 'not_a_current_proposal']);
                continue;
            }
            $link = $this->baselines->link([
                'domain' => $pair['domain'],
                'source_environment_id' => $current['source'],
                'source_instance_uid' => $pair['source_instance_uid'],
                'target_environment_id' => $current['target'],
                'target_instance_uid' => $pair['target_instance_uid'],
                'note' => 'auto-linker name/token match: ' . $allowed[$key]['match_key'],
            ]);
            if (is_wp_error($link)) {
                $rejected[] = array_merge($pair, ['reason' => $link->get_error_code()]);
                continue;
            }
            if (($link['result'] ?? '') === 'created') {
                $created++;
            }
            $results[] = [
                'domain' => $pair['domain'],
                'source_instance_uid' => $pair['source_instance_uid'],
                'target_instance_uid' => $pair['target_instance_uid'],
                'result' => (string) ($link['result'] ?? 'error'),
            ];
        }

        return [
            'source' => $current['source'],
            'target' => $current['target'],
            'domain' => $current['domain'],
            'available_proposals' => count($allowed),
            'created' => $created,
            'results' => $results,
            'rejected' => $rejected,
        ];
    }

    /**
     * Current-epoch, existing, named collection members that can be linked:
     * anything carrying a `match_key` (option-collection members do; posts,
     * the `collection.order` marker, tombstones and pre-upgrade rows do not).
     *
     * @param array<string, mixed> $environment
     * @param string|null          $domain
     * @return array<int, array<string, mixed>>
     */
    private function linkable_projections(array $environment, $domain)
    {
        $rows = [];
        foreach ($this->projections->all($environment['environment_id'], 5000) as $row) {
            if ($row['installation_epoch'] !== $environment['current_epoch']) {
                continue;
            }
            if ($domain !== null && $row['domain'] !== $domain) {
                continue;
            }
            if ($row['instance_uid'] === ComparisonService::ORDER_INSTANCE_UID) {
                continue;
            }
            if ((int) $row['object_exists'] !== 1) {
                continue;
            }
            if ((string) $row['match_key'] === '') {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @return string
     */
    private function group_key(array $row)
    {
        return $row['domain'] . '|' . $row['profile'] . '|' . $row['match_key'];
    }

    /**
     * @param string $domain
     * @param string $source_instance_uid
     * @param string $target_instance_uid
     * @return string
     */
    private function pair_key($domain, $source_instance_uid, $target_instance_uid)
    {
        return $domain . '|' . $source_instance_uid . '|' . $target_instance_uid;
    }
}
