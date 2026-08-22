<?php

namespace Dbvc\VisualEditor\MediaManager;

use WP_Post;
use WP_Term;

/**
 * R2-H Phase 1 (Slice 2) — read the durable, cross-user Media Index safely.
 *
 * The index is shared site-wide, so a stored row is NEVER treated as authority: for
 * every row this model re-resolves the entity and re-runs the eligibility policy for
 * the CURRENT user (status/public/show-UI/exclusions AND per-object capability) and
 * drops any row the requesting user may not see. Rows expose only a per-entity summary
 * (label, url, counts) — no owner id beyond the entity, no field key/selector/path.
 */
final class MediaIndexReadModel
{
    public const VIEW_MODEL_VERSION = 1;

    /**
     * @var MediaIndexStore
     */
    private $store;

    /**
     * @var EligibilityPolicy
     */
    private $policy;

    public function __construct(MediaIndexStore $store, EligibilityPolicy $policy)
    {
        $this->store = $store;
        $this->policy = $policy;
    }

    /**
     * List index rows the current user is eligible to see, missing-media first.
     *
     * @param array<string, mixed> $query Keys: limit, offset, search, entityFamily,
     *                                     fieldFamily, sort. The search/entity/sort
     *                                     filters run in SQL; fieldFamily is applied
     *                                     after hydration (it reads the family counts).
     * @return array<string, mixed>
     */
    public function getList(array $query = [])
    {
        $generation = $this->store->currentGeneration();
        $limit = isset($query['limit']) ? max(1, min(100, absint($query['limit']))) : 25;
        $offset = isset($query['offset']) ? max(0, absint($query['offset'])) : 0;
        $normalized = $this->normalizeQuery($query);

        $rows = $this->store->listEntities([
            'generation' => $generation,
            'onlyMissing' => true,
            'limit' => $limit,
            'offset' => $offset,
            'search' => $normalized['search'],
            'entityFamily' => $normalized['entityFamily'],
            'sort' => $normalized['sort'],
        ]);

        $items = [];
        foreach ($rows as $row) {
            if (! $this->matchesFieldFamily($row, $normalized['fieldFamily'])) {
                continue;
            }
            if (! $this->isVisibleToCurrentUser($row)) {
                continue;
            }
            $items[] = $this->projectItem($row);
        }

        return [
            'viewModelVersion' => self::VIEW_MODEL_VERSION,
            'source' => 'index',
            'generation' => $generation,
            'query' => $normalized,
            'items' => $items,
            // The offset/limit is over stored rows; the returned item count may be
            // smaller after read-time (eligibility + field-family) filtering — precise
            // per-user paging is a later refinement. `hasMore` reflects whether a full
            // stored page was read.
            'pagination' => [
                'offset' => $offset,
                'limit' => $limit,
                'hasMore' => count($rows) === $limit,
            ],
        ];
    }

    /**
     * Normalize the list query to the same whitelist the ephemeral scan list uses.
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    private function normalizeQuery(array $query)
    {
        $entity_family = sanitize_key((string) ($query['entityFamily'] ?? 'all'));
        if (! in_array($entity_family, ['all', 'post', 'term'], true)) {
            $entity_family = 'all';
        }

        $field_family = sanitize_key((string) ($query['fieldFamily'] ?? 'all'));
        if (! in_array($field_family, ['all', 'featured_image', 'acf_image', 'acf_gallery'], true)) {
            $field_family = 'all';
        }

        $sort = sanitize_key((string) ($query['sort'] ?? 'entity_asc'));
        if (! in_array($sort, ['entity_asc', 'entity_desc', 'missing_asc', 'missing_desc', 'scanned_asc', 'scanned_desc'], true)) {
            $sort = 'entity_asc';
        }

        return [
            'search' => trim(sanitize_text_field((string) ($query['search'] ?? ''))),
            'entityFamily' => $entity_family,
            'fieldFamily' => $field_family,
            'sort' => $sort,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param string               $field_family
     * @return bool
     */
    private function matchesFieldFamily(array $row, $field_family)
    {
        if ($field_family === 'all') {
            return true;
        }

        $counts = isset($row['family_counts']) && is_array($row['family_counts']) ? $row['family_counts'] : [];

        return absint($counts[$field_family] ?? 0) > 0;
    }

    /**
     * Read-time authority check: re-resolve and re-assess the entity for the current user.
     *
     * @param array<string, mixed> $row
     * @return bool
     */
    private function isVisibleToCurrentUser(array $row)
    {
        $type = sanitize_key((string) ($row['entity_type'] ?? ''));
        $id = absint($row['entity_id'] ?? 0);
        $subtype = sanitize_key((string) ($row['entity_subtype'] ?? ''));
        if ($id <= 0) {
            return false;
        }

        if ($type === 'post') {
            $post = get_post($id);

            return $post instanceof WP_Post && ! empty($this->policy->assessPost($post)['eligible']);
        }

        if ($type === 'term') {
            $term = get_term($id, $subtype);

            return ! is_wp_error($term)
                && $term instanceof WP_Term
                && ! empty($this->policy->assessTerm($term)['eligible']);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function projectItem(array $row)
    {
        $family_counts = isset($row['family_counts']) && is_array($row['family_counts']) ? $row['family_counts'] : [];
        $label = (string) ($row['label'] ?? '');
        $frontend_url = esc_url_raw((string) ($row['frontend_url'] ?? ''));

        return [
            'groupRef' => sanitize_key((string) ($row['entity_ref'] ?? '')),
            'entity' => [
                'label' => $label !== '' ? $label : __('Untitled content', 'dbvc'),
                'typeLabel' => sanitize_text_field((string) ($row['entity_subtype'] ?? '')),
                'frontendUrl' => $frontend_url,
            ],
            'status' => 'index_observation',
            'missingCount' => absint($row['missing_count'] ?? 0),
            'findingCounts' => [
                'featuredImage' => absint($family_counts['featured_image'] ?? 0),
                'acfImage' => absint($family_counts['acf_image'] ?? 0),
                'acfGallery' => absint($family_counts['acf_gallery'] ?? 0),
            ],
            'indexedAt' => sanitize_text_field((string) ($row['indexed_at'] ?? '')),
            'availableActions' => [
                'expand' => true,
                'openFrontend' => $frontend_url !== '',
                'assignMedia' => false,
            ],
        ];
    }
}
