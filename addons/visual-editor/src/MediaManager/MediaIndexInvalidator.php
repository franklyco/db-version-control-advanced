<?php

namespace Dbvc\VisualEditor\MediaManager;

use WP_Post;
use WP_Term;

/**
 * R2-H Phase 1 (Slice 3) — keep the Media Index fresh incrementally.
 *
 * Subscribes to the common entity-change hooks and re-indexes (or removes) just the
 * one affected entity, so the durable index stays current between full scans without
 * a full rebuild. Re-indexing runs a bounded single-entity rescan and upserts into
 * the current index generation; an entity that is no longer indexable (unpublished,
 * excluded, deleted, or no longer carrying supported media fields) has its row
 * removed. Index membership uses the same eligibility as the scan that built it; the
 * per-user read-time filter still applies at read time.
 *
 * Deferred to later slices (documented): attachment-deletion reverse lookup, ACF
 * field-group topology changes, exclusion-option changes, and direct-metadata writes
 * that do not fire `save_post` — those pair with the background reconcile (Slice 4).
 */
final class MediaIndexInvalidator
{
    /**
     * @var MediaIndexStore
     */
    private $store;

    /**
     * @var MediaScanService
     */
    private $scanner;

    /**
     * @var EligibilityPolicy
     */
    private $policy;

    /**
     * @var MediaIndexProjector
     */
    private $projector;

    public function __construct(
        MediaIndexStore $store,
        MediaScanService $scanner,
        EligibilityPolicy $policy,
        MediaIndexProjector $projector
    ) {
        $this->store = $store;
        $this->scanner = $scanner;
        $this->policy = $policy;
        $this->projector = $projector;
    }

    /**
     * @return void
     */
    public function register()
    {
        add_action('save_post', [$this, 'onSavePost'], 20, 2);
        add_action('trashed_post', [$this, 'onPostRemoved'], 20);
        add_action('deleted_post', [$this, 'onPostRemoved'], 20);
        add_action('edited_term', [$this, 'onEditedTerm'], 20, 3);
        add_action('delete_term', [$this, 'onDeletedTerm'], 20, 3);
        add_action('delete_attachment', [$this, 'onAttachmentDeleted'], 20);
    }

    /**
     * @return void
     */
    public function unregister()
    {
        remove_action('save_post', [$this, 'onSavePost'], 20);
        remove_action('trashed_post', [$this, 'onPostRemoved'], 20);
        remove_action('deleted_post', [$this, 'onPostRemoved'], 20);
        remove_action('edited_term', [$this, 'onEditedTerm'], 20);
        remove_action('delete_term', [$this, 'onDeletedTerm'], 20);
        remove_action('delete_attachment', [$this, 'onAttachmentDeleted'], 20);
    }

    /**
     * An attachment deletion can empty any field that referenced it. Without an
     * expensive reverse lookup, flag the whole current generation dirty so the
     * background reconcile (Slice 4) recomputes affected rows over time.
     *
     * @param int $attachment_id
     * @return void
     */
    public function onAttachmentDeleted($attachment_id)
    {
        unset($attachment_id);
        $this->store->markGenerationDirty($this->store->currentGeneration());
        // During a Slice 4b-2 rebuild we must ALSO flag the building generation dirty
        // so the reconcile that runs post-swap re-checks entities against the current
        // attachment state — otherwise a deletion that happened before the builder
        // reached an entity in this rebuild would survive as a stale row post-swap.
        $building = $this->store->buildingGeneration();
        if ($building !== '') {
            $this->store->markGenerationDirty($building);
        }
    }

    /**
     * @param int          $post_id
     * @param WP_Post|mixed $post
     * @return void
     */
    public function onSavePost($post_id, $post = null)
    {
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($post_id)) {
            return;
        }

        $post = $post instanceof WP_Post ? $post : get_post($post_id);
        if (! $post instanceof WP_Post) {
            return;
        }

        $this->reindexEntity('post', (string) $post->post_type, (int) $post->ID);
    }

    /**
     * @param int $post_id
     * @return void
     */
    public function onPostRemoved($post_id)
    {
        // Delete by id (unique) — the subtype may be unavailable after a hard delete.
        $this->store->deleteEntityById('post', absint($post_id));
    }

    /**
     * @param int    $term_id
     * @param int    $tt_id
     * @param string $taxonomy
     * @return void
     */
    public function onEditedTerm($term_id, $tt_id, $taxonomy)
    {
        $this->reindexEntity('term', (string) $taxonomy, (int) $term_id);
    }

    /**
     * @param int    $term_id
     * @param int    $tt_id
     * @param string $taxonomy
     * @return void
     */
    public function onDeletedTerm($term_id, $tt_id, $taxonomy)
    {
        unset($tt_id, $taxonomy);
        $this->store->deleteEntityById('term', absint($term_id));
    }

    /**
     * Re-index one entity: rescan it and upsert into the current index generation, or
     * remove its row if it is no longer indexable / carries no supported media fields.
     *
     * @param string $family
     * @param string $subtype
     * @param int    $id
     * @return void
     */
    public function reindexEntity($family, $subtype, $id)
    {
        $family = sanitize_key((string) $family);
        $subtype = sanitize_key((string) $subtype);
        $id = absint($id);
        if ($id <= 0 || ! in_array($family, ['post', 'term'], true)) {
            return;
        }

        if (! $this->isIndexable($family, $subtype, $id)) {
            $this->store->deleteEntity($family, $id, $subtype);

            return;
        }

        $generation = 'vmsg_' . substr(hash('sha256', uniqid('dbvc_media_reindex', true) . wp_rand()), 0, 20);
        $scanned = $this->scanner->scan([
            ['family' => $family, 'subtype' => $subtype, 'id' => $id],
        ], $generation, true);

        if (is_wp_error($scanned) || empty($scanned['groups']) || ! is_array($scanned['groups'])) {
            // Eligible but no supported media fields (or unscannable): drop any stale row.
            $this->store->deleteEntity($family, $id, $subtype);

            return;
        }

        // Always write to the serving generation. During a Slice 4b-2 rebuild also
        // dual-write into the building generation so a mid-rebuild edit survives the
        // atomic serving-pointer swap — otherwise a save between "builder scanned this
        // entity" and "rebuild completes" would be lost when the new generation takes
        // over.
        $target_generations = [$this->store->currentGeneration()];
        $building = $this->store->buildingGeneration();
        if ($building !== '' && ! in_array($building, $target_generations, true)) {
            $target_generations[] = $building;
        }

        foreach ($scanned['groups'] as $group_ref => $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ($target_generations as $index_generation) {
                $this->projector->indexGroup((string) $group_ref, $group, $index_generation);
            }
        }
    }

    /**
     * @param string $family
     * @param string $subtype
     * @param int    $id
     * @return bool
     */
    private function isIndexable($family, $subtype, $id)
    {
        if ($family === 'post') {
            $post = get_post($id);

            return $post instanceof WP_Post && ! empty($this->policy->assessPost($post)['eligible']);
        }

        if ($family === 'term') {
            $term = get_term($id, $subtype);

            return ! is_wp_error($term)
                && $term instanceof WP_Term
                && ! empty($this->policy->assessTerm($term)['eligible']);
        }

        return false;
    }
}
