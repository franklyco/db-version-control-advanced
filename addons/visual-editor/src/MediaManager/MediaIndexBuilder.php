<?php

namespace Dbvc\VisualEditor\MediaManager;

/**
 * R2-H Phase 1 (Slice 4b) — automatic first-run chunked build of the Media Index.
 *
 * The completion hook (Slice 2) only ever populated the index from a single user's
 * on-demand scan, so the shared, cross-user index was limited to whatever that user
 * could edit. This builder enumerates the STRUCTURALLY eligible entity set
 * (published/public/show-UI/not-excluded — no per-object capability, since the index
 * is cross-user and capability is re-checked at read time) in bounded chunks and
 * upserts a per-entity row for every entity with missing media, so the index is
 * complete for all users regardless of who is browsing.
 *
 * The build advances one bounded chunk at a time, persisting a cursor across runs
 * (WP-Cron single-event chaining or an Action Scheduler async chain — see
 * {@see MediaIndexScheduler}), and marks itself complete when the candidate cursor is
 * exhausted. The first build fills the current (empty) generation directly; an atomic
 * build-into-a-fresh-generation-then-swap for topology/exclusion rebuilds is Slice 4b-2.
 */
final class MediaIndexBuilder
{
    private const OPTION_STATE = 'dbvc_visual_editor_media_index_build';

    private const DEFAULT_CHUNK = 50;

    /**
     * @var MediaIndexStore
     */
    private $store;

    /**
     * @var ScanCandidateProvider
     */
    private $provider;

    /**
     * @var MediaScanService
     */
    private $scanner;

    /**
     * @var MediaIndexProjector
     */
    private $projector;

    public function __construct(
        MediaIndexStore $store,
        ScanCandidateProvider $provider,
        MediaScanService $scanner,
        MediaIndexProjector $projector
    ) {
        $this->store = $store;
        $this->provider = $provider;
        $this->projector = $projector;
        $this->scanner = $scanner;
    }

    /**
     * True when the index has not been fully built for the current target generation.
     *
     * The target is {@see MediaIndexStore::activeBuildGeneration()} — the building
     * generation during a rebuild (Slice 4b-2), or the serving generation for the
     * first-run build. A rebuild's rotation therefore causes needsBuild() to flip
     * back to true even if the prior serving generation was fully built.
     *
     * @return bool
     */
    public function needsBuild()
    {
        $state = $this->loadState();
        $generation = $this->store->activeBuildGeneration();

        return ! (($state['status'] ?? '') === 'complete' && ($state['generation'] ?? '') === $generation);
    }

    /**
     * Advance the build by one bounded chunk. Safe to call repeatedly and idempotent
     * once complete.
     *
     * During a rebuild (Slice 4b-2) the target is the building generation, so writes
     * do not clobber the still-serving rows. When the final chunk completes AND a
     * rebuild is in progress, the store atomically swaps the serving pointer to the
     * newly-built generation and prunes the old one — reads never observe a
     * half-built index.
     *
     * @param int $limit
     * @return array{processed: int, indexed: int, complete: bool, generation: string}
     */
    public function runChunk($limit = self::DEFAULT_CHUNK)
    {
        $this->store->maybeUpgrade();
        $limit = max(1, min(50, absint($limit)));
        $generation = $this->store->activeBuildGeneration();
        $rebuild_active = $this->store->hasActiveRebuild();
        $state = $this->loadState();

        // Start (or restart for a new generation) a fresh build.
        if (($state['status'] ?? '') === 'complete' && ($state['generation'] ?? '') === $generation) {
            return ['processed' => 0, 'indexed' => 0, 'complete' => true, 'generation' => $generation];
        }
        if (($state['generation'] ?? '') !== $generation) {
            $state = [
                'generation' => $generation,
                'cursor' => $this->provider->initialCursor(),
                'status' => 'building',
                'processed' => 0,
                'indexed' => 0,
                'started_at' => current_time('mysql', true),
            ];
        }

        $sources = $this->provider->getSources();
        $next = $this->provider->next($sources, is_array($state['cursor'] ?? null) ? $state['cursor'] : $this->provider->initialCursor(), $limit);
        $candidates = isset($next['candidates']) && is_array($next['candidates']) ? $next['candidates'] : [];

        $indexed = 0;
        foreach ($candidates as $candidate) {
            $indexed += $this->indexCandidate($candidate, $generation);
        }

        $complete = ! empty($next['complete']);
        $state['cursor'] = isset($next['cursor']) && is_array($next['cursor']) ? $next['cursor'] : $this->provider->initialCursor();
        $state['processed'] = absint($state['processed'] ?? 0) + count($candidates);
        $state['indexed'] = absint($state['indexed'] ?? 0) + $indexed;
        $state['status'] = $complete ? 'complete' : 'building';
        $this->saveState($state);

        // Slice 4b-2 atomic swap: only when a rebuild was active for this build do we
        // promote the building generation to serving and prune the old one. The
        // first-run build already writes into the serving generation, so no swap.
        if ($complete && $rebuild_active) {
            $this->store->completeRebuild();
        }

        if ($complete) {
            /**
             * Fires once when a build (initial or rebuild) reaches completion. The
             * derived JSON exporter (Slice 5) subscribes so the sync-folder mirror is
             * refreshed at each completion boundary rather than per-invalidator.
             *
             * @param string $generation     The generation the build wrote into (post-swap
             *                                this is the serving generation).
             * @param bool   $rebuild_active True when this completion was the swap end of a
             *                                topology/exclusion rebuild.
             */
            do_action('dbvc_visual_editor_media_index_build_completed', $generation, $rebuild_active);
        }

        return [
            'processed' => count($candidates),
            'indexed' => $indexed,
            'complete' => $complete,
            'generation' => $generation,
        ];
    }

    /**
     * Reset the build state so the next run rebuilds from the start (used when the
     * generation rotates for a topology/exclusion rebuild — Slice 4b-2).
     *
     * @return void
     */
    public function reset()
    {
        delete_option(self::OPTION_STATE);
    }

    /**
     * @return array<string, mixed>
     */
    public function state()
    {
        return $this->loadState();
    }

    /**
     * Scan one structural candidate and upsert its row if it has missing media. The
     * scanner is driven with an ad-hoc `vmsg_` scan generation (its internal
     * finding-ref namespace); the row is stored under the `vmig_` INDEX generation,
     * mirroring the incremental invalidator.
     *
     * @param mixed  $candidate
     * @param string $index_generation
     * @return int Rows written (0 or 1).
     */
    private function indexCandidate($candidate, $index_generation)
    {
        if (! is_array($candidate)) {
            return 0;
        }

        $scan_generation = 'vmsg_' . substr(hash('sha256', uniqid('dbvc_media_build', true) . wp_rand()), 0, 20);
        $scanned = $this->scanner->scan([$candidate], $scan_generation);
        if (is_wp_error($scanned)) {
            return 0;
        }

        $groups = isset($scanned['groups']) && is_array($scanned['groups']) ? $scanned['groups'] : [];
        $written = 0;
        foreach ($groups as $group_ref => $group) {
            if (is_array($group) && $this->projector->indexGroup((string) $group_ref, $group, $index_generation)) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState()
    {
        $state = get_option(self::OPTION_STATE, []);

        return is_array($state) ? $state : [];
    }

    /**
     * @param array<string, mixed> $state
     * @return void
     */
    private function saveState(array $state)
    {
        update_option(self::OPTION_STATE, $state, false);
    }
}
