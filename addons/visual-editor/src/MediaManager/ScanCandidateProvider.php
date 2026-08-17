<?php

namespace Dbvc\VisualEditor\MediaManager;

use WP_Query;

final class ScanCandidateProvider
{
    private const DEFAULT_SOURCE_QUERY_BUDGET = 4;
    private const MAX_CHUNK_SIZE = 50;

    /**
     * @var EligibilityPolicy
     */
    private $eligibility;

    /**
     * @var int
     */
    private $source_query_budget;

    public function __construct(EligibilityPolicy $eligibility, $source_query_budget = self::DEFAULT_SOURCE_QUERY_BUDGET)
    {
        $this->eligibility = $eligibility;
        $this->source_query_budget = max(1, min(20, absint($source_query_budget)));
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function getSources()
    {
        $sources = [];

        foreach ($this->eligibility->getEligiblePostTypes() as $post_type) {
            $sources[] = [
                'family' => 'post',
                'subtype' => sanitize_key((string) $post_type),
            ];
        }

        foreach ($this->eligibility->getEligibleTaxonomies() as $taxonomy) {
            $sources[] = [
                'family' => 'term',
                'subtype' => sanitize_key((string) $taxonomy),
            ];
        }

        return $sources;
    }

    /**
     * @return array<string, int>
     */
    public function initialCursor()
    {
        return [
            'source_index' => 0,
            'offset' => 0,
        ];
    }

    /**
     * Fetch at most one bounded candidate chunk. Empty sources also consume the
     * query budget so a site with many registered but unused object families
     * cannot turn one client request into an unbounded sequence of queries.
     *
     * @param array<int, array<string, string>> $sources
     * @param array<string, mixed>              $cursor
     * @param int                               $limit
     * @return array<string, mixed>
     */
    public function next(array $sources, array $cursor, $limit)
    {
        $limit = max(1, min(self::MAX_CHUNK_SIZE, absint($limit)));
        $source_index = max(0, absint($cursor['source_index'] ?? 0));
        $offset = max(0, absint($cursor['offset'] ?? 0));
        $candidates = [];
        $queries = 0;

        while ($source_index < count($sources)
            && count($candidates) < $limit
            && $queries < $this->source_query_budget) {
            $source = $this->normalizeSource($sources[$source_index] ?? []);
            if (empty($source)) {
                $source_index++;
                $offset = 0;
                continue;
            }

            $remaining = $limit - count($candidates);
            $ids = $source['family'] === 'post'
                ? $this->queryPostIds($source['subtype'], $offset, $remaining)
                : $this->queryTermIds($source['subtype'], $offset, $remaining);
            $queries++;

            foreach ($ids as $id) {
                $candidates[] = [
                    'family' => $source['family'],
                    'subtype' => $source['subtype'],
                    'id' => absint($id),
                ];
            }

            $fetched = count($ids);
            if ($fetched < $remaining) {
                $source_index++;
                $offset = 0;
            } else {
                $offset += $fetched;
            }
        }

        return [
            'candidates' => $candidates,
            'cursor' => [
                'source_index' => $source_index,
                'offset' => $offset,
            ],
            'complete' => $source_index >= count($sources),
            'source_queries' => $queries,
        ];
    }

    /**
     * This is an estimate of registered live candidates before per-object
     * capability checks. It is used only for progress context.
     *
     * @param array<int, array<string, string>> $sources
     * @return int
     */
    public function estimateTotal(array $sources)
    {
        $total = 0;

        foreach ($sources as $raw_source) {
            $source = $this->normalizeSource($raw_source);
            if (empty($source)) {
                continue;
            }

            if ($source['family'] === 'post') {
                $counts = wp_count_posts($source['subtype']);
                $total += isset($counts->publish) ? absint($counts->publish) : 0;
                continue;
            }

            $count = wp_count_terms([
                'taxonomy' => $source['subtype'],
                'hide_empty' => false,
            ]);
            if (! is_wp_error($count)) {
                $total += absint($count);
            }
        }

        return $total;
    }

    /**
     * @param string $post_type
     * @param int    $offset
     * @param int    $limit
     * @return array<int, int>
     */
    private function queryPostIds($post_type, $offset, $limit)
    {
        $query = new WP_Query([
            'post_type' => sanitize_key((string) $post_type),
            'post_status' => 'publish',
            'posts_per_page' => max(1, absint($limit)),
            'offset' => max(0, absint($offset)),
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ]);

        return array_values(array_filter(array_map('absint', is_array($query->posts) ? $query->posts : [])));
    }

    /**
     * @param string $taxonomy
     * @param int    $offset
     * @param int    $limit
     * @return array<int, int>
     */
    private function queryTermIds($taxonomy, $offset, $limit)
    {
        $ids = get_terms([
            'taxonomy' => sanitize_key((string) $taxonomy),
            'hide_empty' => false,
            'number' => max(1, absint($limit)),
            'offset' => max(0, absint($offset)),
            'orderby' => 'term_id',
            'order' => 'ASC',
            'fields' => 'ids',
            'update_term_meta_cache' => true,
        ]);

        return is_wp_error($ids) || ! is_array($ids)
            ? []
            : array_values(array_filter(array_map('absint', $ids)));
    }

    /**
     * @param mixed $source
     * @return array<string, string>
     */
    private function normalizeSource($source)
    {
        if (! is_array($source)) {
            return [];
        }

        $family = sanitize_key((string) ($source['family'] ?? ''));
        $subtype = sanitize_key((string) ($source['subtype'] ?? ''));

        if (! in_array($family, ['post', 'term'], true) || $subtype === '') {
            return [];
        }

        return [
            'family' => $family,
            'subtype' => $subtype,
        ];
    }
}
