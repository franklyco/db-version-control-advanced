<?php

namespace Dbvc\VisualEditor\MediaManager;

use WP_Post;
use WP_Term;

/**
 * R2-H Phase 1 (Slice 2) — populate the durable Media Index from a completed scan.
 *
 * Subscribes to `dbvc_visual_editor_media_scan_completed` and writes one per-entity
 * summary row (identity, cached label/url, missing count, per-family breakdown, and a
 * content hash for change detection) into {@see MediaIndexStore} under a fresh index
 * generation, pruning the prior generation. Population is a write-only accelerator:
 * it stores no field keys/selectors/paths and confers no authority — eligibility is
 * re-checked at read time (see {@see MediaIndexReadModel}).
 */
final class MediaIndexProjector
{
    /**
     * @var MediaIndexStore
     */
    private $store;

    public function __construct(MediaIndexStore $store)
    {
        $this->store = $store;
    }

    /**
     * Hook callback for `dbvc_visual_editor_media_scan_completed`.
     *
     * @param mixed $snapshot
     * @return void
     */
    public function onScanCompleted($snapshot)
    {
        if (is_array($snapshot)) {
            $this->rebuildFromSnapshot($snapshot);
        }
    }

    /**
     * Hook callback for `dbvc_visual_editor_media_scan_completed` once the cross-user
     * builder owns the index (R2-H Slice 4b): refresh only the scanned entities into
     * the CURRENT generation instead of rotating a fresh one.
     *
     * @param mixed $snapshot
     * @return void
     */
    public function onScanRefreshed($snapshot)
    {
        if (is_array($snapshot)) {
            $this->refreshFromSnapshot($snapshot);
        }
    }

    /**
     * Upsert the scanned groups into the CURRENT generation without rotating or
     * pruning. Used by the manual-scan completion hook once the structural builder is
     * the authoritative population (Slice 4b): a user's on-demand scan refreshes/adds
     * the rows it found missing but never clobbers the cross-user index or its
     * generation. Rows for entities that became fully populated are cleaned up by the
     * incremental invalidator on their next edit (or a later full rebuild).
     *
     * @param array<string, mixed> $snapshot
     * @return array{generation: string, written: int}
     */
    public function refreshFromSnapshot(array $snapshot)
    {
        $generation = $this->store->currentGeneration();
        $groups = isset($snapshot['groups']) && is_array($snapshot['groups']) ? $snapshot['groups'] : [];
        $written = 0;

        foreach ($groups as $group_ref => $group) {
            if (is_array($group) && $this->indexGroup((string) $group_ref, $group, $generation)) {
                $written++;
            }
        }

        return ['generation' => $generation, 'written' => $written];
    }

    /**
     * Rebuild the whole index from a completed snapshot under a fresh generation.
     *
     * @param array<string, mixed> $snapshot
     * @return array{generation: string, written: int}
     */
    public function rebuildFromSnapshot(array $snapshot)
    {
        $generation = $this->store->rotateGeneration();
        $groups = isset($snapshot['groups']) && is_array($snapshot['groups']) ? $snapshot['groups'] : [];
        $written = 0;

        foreach ($groups as $group_ref => $group) {
            if (is_array($group) && $this->indexGroup((string) $group_ref, $group, $generation)) {
                $written++;
            }
        }

        $this->store->pruneOtherGenerations($generation);

        return ['generation' => $generation, 'written' => $written];
    }

    /**
     * Build and upsert one per-entity index row from a scanned group. Shared by the
     * full rebuild and the incremental single-entity re-index (Slice 3).
     *
     * @param string               $group_ref
     * @param array<string, mixed> $group
     * @param string               $generation
     * @return bool True if a row was written.
     */
    public function indexGroup($group_ref, array $group, $generation)
    {
        $owner = isset($group['owner']) && is_array($group['owner']) ? $group['owner'] : [];
        $type = sanitize_key((string) ($owner['family'] ?? ''));
        $id = absint($owner['id'] ?? 0);
        $subtype = sanitize_key((string) ($owner['subtype'] ?? ''));
        if (! in_array($type, ['post', 'term'], true) || $id <= 0) {
            return false;
        }

        $findings = isset($group['findings']) && is_array($group['findings']) ? $group['findings'] : [];

        return $this->store->upsertEntity([
            'entity_type' => $type,
            'entity_id' => $id,
            'entity_subtype' => $subtype,
            'entity_ref' => sanitize_key((string) $group_ref),
            'label' => $this->entityLabel($type, $id, $subtype),
            'frontend_url' => $this->entityUrl($type, $id, $subtype),
            'missing_count' => count($findings),
            'populated_count' => 0,
            'family_counts' => $this->familyCounts($findings),
            'content_hash' => $this->contentHash($id, $findings),
            'index_generation' => sanitize_key((string) $generation),
        ]) > 0;
    }

    /**
     * @param array<string, mixed> $findings
     * @return array<string, int>
     */
    private function familyCounts(array $findings)
    {
        $counts = ['featured_image' => 0, 'acf_image' => 0, 'acf_gallery' => 0];
        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $family = sanitize_key((string) ($finding['family'] ?? ''));
            if (isset($counts[$family])) {
                $counts[$family]++;
            }
        }

        return $counts;
    }

    /**
     * A stable hash of the entity's missing-media state, so an incremental re-index can
     * skip a no-op update. Uses the finding fingerprints, not any raw target.
     *
     * @param int                  $id
     * @param array<string, mixed> $findings
     * @return string
     */
    private function contentHash($id, array $findings)
    {
        $parts = [];
        foreach ($findings as $finding_ref => $finding) {
            $fingerprint = is_array($finding) ? (string) ($finding['empty_fingerprint'] ?? '') : '';
            $parts[] = sanitize_key((string) $finding_ref) . ':' . $fingerprint;
        }
        sort($parts);

        return substr(hash('sha256', absint($id) . '|' . implode(',', $parts)), 0, 40);
    }

    /**
     * @param string $type
     * @param int    $id
     * @param string $subtype
     * @return string
     */
    private function entityLabel($type, $id, $subtype)
    {
        if ($type === 'post') {
            $post = get_post($id);
            if ($post instanceof WP_Post) {
                $title = get_the_title($post);

                return sanitize_text_field(wp_strip_all_tags(is_string($title) ? $title : ''));
            }
        } elseif ($type === 'term') {
            $term = get_term($id, $subtype);
            if (! is_wp_error($term) && $term instanceof WP_Term) {
                return sanitize_text_field((string) $term->name);
            }
        }

        return '';
    }

    /**
     * @param string $type
     * @param int    $id
     * @param string $subtype
     * @return string
     */
    private function entityUrl($type, $id, $subtype)
    {
        if ($type === 'post') {
            $url = get_permalink($id);

            return is_string($url) ? esc_url_raw($url) : '';
        }

        if ($type === 'term') {
            $url = get_term_link($id, $subtype);

            return ! is_wp_error($url) && is_string($url) ? esc_url_raw($url) : '';
        }

        return '';
    }
}
