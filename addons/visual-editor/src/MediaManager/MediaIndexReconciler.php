<?php

namespace Dbvc\VisualEditor\MediaManager;

/**
 * R2-H Phase 1 (Slice 4) — background reconcile of the Media Index.
 *
 * The scheduled job that re-indexes entities flagged dirty by the deferred
 * invalidations (attachment deletion in this slice; ACF topology / exclusion
 * changes in Slice 4b) in bounded chunks. Re-indexing runs the invalidator's
 * single-entity rescan, which upserts a fresh row (clearing the dirty flag) or
 * removes the row if the entity is no longer indexable.
 *
 * Slice 4b (documented): the automatic first-run chunked FULL build (driving a
 * background coordinator scan) and the ACF field-group / exclusion-option rebuild
 * triggers, which need cursor-state-across-runs machinery.
 */
final class MediaIndexReconciler
{
    public const RECONCILE_HOOK = 'dbvc_visual_editor_media_index_reconcile';

    private const DEFAULT_CHUNK = 20;

    /**
     * @var MediaIndexStore
     */
    private $store;

    /**
     * @var MediaIndexInvalidator
     */
    private $invalidator;

    public function __construct(MediaIndexStore $store, MediaIndexInvalidator $invalidator)
    {
        $this->store = $store;
        $this->invalidator = $invalidator;
    }

    /**
     * Scheduled callback: reconcile one bounded chunk of dirty rows.
     *
     * @return void
     */
    public function run()
    {
        $this->reconcileDirty(self::DEFAULT_CHUNK);
    }

    /**
     * Re-index up to $limit dirty entities. Each is rescanned and upserted afresh
     * (which clears its dirty flag) or removed if no longer indexable.
     *
     * @param int $limit
     * @return int Rows processed.
     */
    public function reconcileDirty($limit = self::DEFAULT_CHUNK)
    {
        $rows = $this->store->listEntities([
            'onlyDirty' => true,
            'limit' => max(1, min(100, absint($limit))),
        ]);

        $processed = 0;
        foreach ($rows as $row) {
            $this->invalidator->reindexEntity(
                (string) ($row['entity_type'] ?? ''),
                (string) ($row['entity_subtype'] ?? ''),
                absint($row['entity_id'] ?? 0)
            );
            $processed++;
        }

        return $processed;
    }
}
