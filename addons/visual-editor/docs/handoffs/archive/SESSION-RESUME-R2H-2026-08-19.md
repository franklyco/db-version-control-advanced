# Session resume — R2-H Persistent Media Index (Phase 1)

_Last updated: 2026-08-19. Read this first, then skim the linked docs below before touching code._

## Exact point we're at

R2-H Persistent Media Index, **Phase 1 COMPLETE**. Done and validated: **Slices 1, 2, 3, 4, 2b,
2c, 4b-1, 4b-2, 5**. The Media Manager now opens instantly from a durable, cross-user,
per-user-eligibility-filtered index that self-builds on first run, self-maintains, atomically
self-rebuilds on any topology/exclusion change, and is **backup-portable** via a derived JSON
mirror in the DBVC sync folder that auto-hydrates a restored empty table.

- **Slice 5 (done)** — derived JSON mirror. `MediaIndexJsonExporter::exportAll()` writes an
  envelope `{schema:1, exported_at, source, generation, count, entities}` to
  `{sync}/visual-editor/media-index.json` at every completion boundary (build completion,
  rebuild swap, reconcile sweeps that touched rows), honoring `dbvc_is_safe_file_path` and
  `wp_mkdir_p`; only the SERVING generation is exported. `importIfEmpty()` on bootstrap
  hydrates an empty table from the mirror — adopts the mirror's generation (so `entity_ref`
  HMACs round-trip) and marks the builder state complete for that generation so the drain does
  not re-fire post-restore. Never overwrites a populated table. New observable actions:
  `dbvc_visual_editor_media_index_exported($file_path, $count)` and `_imported($file_path, $imported)`.
  Verified by `VisualEditorMediaIndexJsonExportTest` (5 tests/41 assertions).
- **Slice 4b-2 (done)** — atomic topology/exclusion rebuilds. `MediaIndexStore` schema v2 splits
  serving/building generations (unique key extended by `index_generation`) so a rebuild writes
  into a fresh building generation while reads keep serving the OLD generation; on the builder's
  final chunk `completeRebuild()` atomically swaps the serving pointer and prunes every other
  generation; `MediaIndexInvalidator` dual-writes into both generations so mid-rebuild saves
  survive the swap; `MediaIndexRebuildController` wires ACF field-group + exclusion-option +
  `wp_loaded` topology-fingerprint triggers. Non-clobbering. Verified by
  `VisualEditorMediaIndexRebuildTest` (4 tests/40 assertions).

## HARD process constraints (do not violate)

- **Preserve the dirty working tree.** The repo has a large pre-existing set of uncommitted changes.
- **NO git operations of any kind.** Never run reset / restore / stash / clean / checkout / switch /
  branch / broad `git add` / commit / push. Don't change branches. (Current branch:
  `codex/visual-editor-linked-posts-plan`; main is `master`. Do not act on this — informational only.)
- **Verify content mutations ONLY via disposable PHPUnit fixtures.** Never mutate live-site content
  (no writing real posts/terms/meta to validate). Tests create + tear down their own fixtures.
- Work in bounded, explicitly-authorized slices. Reconcile docs after each slice (see below).
- Scratchpad for temp files, not `/tmp`.

## Validation commands + current green baselines

Run from the plugin root
(`/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`):

- Full PHP suite: `vendor/bin/phpunit` → **799 tests, 6 inherited failures**. A **7th** sometimes appears
  (`ContentMigrationPhase4ImportExecutorTest` or `ContentCollectorV2Phase8Test::test_phase_eight_preflight_and_execute_routes_bridge_package_import`) —
  both are **order-dependent flaky**, pass in isolation, and are NOT regressions. The 6 inherited are all outside the Media Manager/Index namespace:
  BricksAddonPhase11, BricksAddonPhase7, CapabilityLandscape, ContentCollectorV2Phase29,
  ContentCollectorV2Phase32, ContentMigrationPhase37W0Settings (CapabilityCli also flaps in/out).
  Confirm a failure isn't yours by running it with `--filter` in isolation.
- Media Manager/Index subset: `vendor/bin/phpunit --filter "VisualEditorMedia"` → **115 tests OK**.
- jsdom: `node --test tests/visual-editor-media-manager-state.test.cjs` → **38 pass**.
- JS lint: `npm run lint:visual-editor-media-manager` (clean; ignore stale-browserslist warnings).
  NOTE: `api-client.js` is NOT in the lint set and has pre-existing `no-undef` on `DBVCVisualEditorBootstrap`
  — leave those alone.
- Agent docs (required when REST/hook/table/settings surfaces change):
  `composer agent-docs:refresh` then `composer agent-docs:check` → **54 curated / 431 discovered / 0 unmapped**.
  If it says the README summary is stale: `composer agent-docs:build`, then re-check. New `do_action`/
  `apply_filters` extension points, REST routes, and custom tables must be mapped in
  `docs/agents/manifest.json` (kept alphabetically sorted within each list).

## Architecture you need for Slice 4b-2

Storage/refs (all under `addons/visual-editor/src/MediaManager/`):
- Custom table `{prefix}dbvc_ve_media_field_index` via `MediaIndexStore` (one row per entity with missing
  media). Named to avoid confusion with core DBVC's unrelated `dbvc_media_index` attachment-file table.
- **Three distinct generation namespaces — do not mix them:**
  - `vmig_…` = the **index generation** (`MediaIndexStore::currentGeneration()`/`rotateGeneration()`),
    stamped on rows. Rows are pruned by generation.
  - `vmsg_…` = a **scan generation**, required by `MediaScanService::scan()` (its finding-ref HMAC
    namespace). The builder/invalidator mint an ad-hoc `vmsg_` per scan and then index the resulting
    groups under the **index** generation. `MediaScanService` REJECTS a non-`vmsg_` generation.
  - `vemx_…` = the stable, opaque, **per-entity index ref** (`MediaIndexStore` derives it via HMAC of
    identity+index-generation; `getByEntityRef` resolves it; forged/stale → null). This is what the
    frontend list rows are keyed by in index mode.
- Security contract (D-053): the shared row is **never authority**. Every list/expand re-resolves the
  entity and re-runs `EligibilityPolicy` for the **current user** at read time (`MediaIndexReadModel`,
  `MediaIndexController`). Rows expose no owner id / field key / selector / path / fingerprint.

Population + maintenance:
- `MediaIndexBuilder` (Slice 4b-1) = the **authoritative cross-user population**: enumerates the
  **structural** eligible set via `ScanCandidateProvider` in chunks (cursor persisted in option
  `dbvc_visual_editor_media_index_build`) and upserts rows. It uses a **structural**
  `EligibilityPolicy(new, $structural=true)` that skips ONLY the per-object capability check (status/
  public/show-UI/exclusions preserved) — capability stays a read-time check.
- `MediaIndexScheduler`: recurring `RECONCILE_HOOK` (dirty rows, hourly) + a self-continuing build drain
  on `BUILD_HOOK` (Action Scheduler async chain if available, else WP-Cron single-event re-armed each
  incomplete chunk). Both prefer Action Scheduler, fall back to WP-Cron.
- `MediaIndexInvalidator` (Slice 3): per-entity re-index/remove on save_post/trashed/deleted/edited_term/
  delete_term/delete_attachment.
- `MediaIndexProjector`: `indexGroup()` (build one row from a scanned group — upsertEntity ignores any
  passed entity_ref and derives its own `vemx_`); `rebuildFromSnapshot()` (rotate+prune — legacy full
  rebuild); `refreshFromSnapshot()`/`onScanRefreshed()` (Slice 4b-1: **upsert into current generation,
  no rotate/prune**). The manual-scan completion hook (`dbvc_visual_editor_media_scan_completed`) is now
  wired to `onScanRefreshed` in `Bootstrap/Addon.php` so a user scan never clobbers the cross-user index.

Frontend (Slice 2c): `assets/js/media-manager-app.js` + `api-client.js`. Gated by the filterable
bootstrap flag `mediaManager.indexList` (`dbvc_visual_editor_media_index_list_enabled`, default =
MM-enabled) set in `Assets/AssetLoader.php`. Opens from `GET .../media-manager/index`, expands via
`POST .../media-manager/index/expand` (adopts the detached snapshot as the per-expansion working
identity: `state.expansion.scan` + working `vemg_` group; list row stays keyed by `vemx_` =
`state.expansion.itemKey`). Automatic fallback to the ephemeral scan (`/scans/latest`) on index error
or empty index. `state.source` is `'scan'|'index'`.

## Slice 4b-2 design notes (implemented — kept for reference)

Slice 4b-2 shipped the serving-vs-building generation split as designed:

- `MediaIndexStore` schema v2 extends the per-entity unique key to
  `(entity_type, entity_id, entity_subtype, index_generation)` so a per-entity building row can
  coexist with its serving counterpart mid-rebuild.
- `currentGeneration()` is the SERVING generation (unchanged for reads); the new
  `OPTION_BUILDING_GENERATION` holds the BUILDING generation while a rebuild is in flight, and
  `activeBuildGeneration()` is the writer-facing helper (building during a rebuild, serving
  otherwise).
- `beginRebuild()` mints a fresh building generation (dropping any orphaned rows from a prior
  in-flight rebuild first); `completeRebuild()` atomically swaps the serving pointer and prunes
  every other generation.
- `MediaIndexBuilder` targets `activeBuildGeneration()`; on its final chunk, if a rebuild was in
  flight, it calls `completeRebuild()` — the swap and prune happen in one step.
- `MediaIndexInvalidator::reindexEntity` dual-writes into serving AND building during a rebuild
  so a mid-rebuild save survives the swap; `onAttachmentDeleted` marks both generations dirty.
- `MediaIndexRebuildController` hooks `acf/update_field_group`, `acf/delete_field_group`,
  `acf/trash_field_group`, `acf/untrash_field_group`; the two exclusion options
  (`update_option`/`add_option`/`delete_option` variants); and `wp_loaded` (priority 20) for the
  topology fingerprint check. Triggers are non-clobbering (no-op while a rebuild is running);
  `dbvc_visual_editor_media_index_rebuild_started` and `_skipped` `do_action`s fire for
  observability.

Coverage: `VisualEditorMediaIndexRebuildTest` (4 tests/40 assertions) — mid-drain reads still
serve the OLD generation; each trigger surface begins a rebuild while a concurrent trigger is a
no-op; the topology fingerprint primes silently, is stable, drifts on post-type registration →
rebuild, and returns to idle after the drain; and the invalidator dual-writes into both
generations so a mid-rebuild save survives the swap.

## Doc reconciliation checklist (do this after the slice, every time)

Under `docs/dropins/dbvc-visual-editor-brand-controls-guide/`:
- `releases/R2H-PERSISTENT-MEDIA-INDEX-PHASE-1.md` — the plan; add a dated "Slice … checkpoint".
- `tracking/IMPLEMENTATION-TRACKER.md` — the single R2-H row.
- `tracking/EVIDENCE-LOG.md` — next id is **E-083** (E-082 = Slice 5, E-081 = Slice 4b-2, E-080 = Slice 4b-1).
- `tracking/DECISION-LOG.md` — next id is **D-058** (D-057 = Slice 5, D-056 = Slice 4b-2, D-055 = Slice 4b-1) if a decision is worth recording.
Plus: `addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md` (canonical; boundary + next-tasks),
`addons/visual-editor/CHANGELOG.md` (Unreleased, plain-language user-facing entry),
`addons/visual-editor/docs/knowledge/DATA_CONTRACTS.md` (only if a client/REST contract changes), and
re-run agent-docs. Update THIS file's "Exact point" when the slice lands.

## Starting-point files

- Plan: `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R2H-PERSISTENT-MEDIA-INDEX-PHASE-1.md`
- Canonical handoff: `addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md`
- Evidence/decisions/tracker: `docs/dropins/dbvc-visual-editor-brand-controls-guide/tracking/{EVIDENCE-LOG,DECISION-LOG,IMPLEMENTATION-TRACKER}.md`
- Code (all `addons/visual-editor/src/MediaManager/` unless noted): `MediaIndexStore.php`,
  `MediaIndexBuilder.php`, `MediaIndexScheduler.php`, `MediaIndexReconciler.php`, `MediaIndexInvalidator.php`,
  `MediaIndexProjector.php`, `MediaIndexReadModel.php`, `EligibilityPolicy.php`, `ScanCandidateProvider.php`,
  `MediaScanService.php`, `Rest/Controllers/MediaIndexController.php`, `Bootstrap/Addon.php`,
  `Assets/AssetLoader.php`; frontend `assets/js/media-manager-app.js`, `assets/js/api-client.js`.
- Tests: `tests/phpunit/VisualEditorMediaIndex*Test.php` (Store, Phase2, Invalidator, Reconcile, Controller,
  Query, Builder), `tests/visual-editor-media-manager-state.test.cjs`.
