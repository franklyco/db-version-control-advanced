# DBVC Visual Editor and Frontend Media Manager Current Handoff

**Updated:** August 16, 2026

**Repository:** `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`

**Branch:** `codex/visual-editor-linked-posts-plan`

**Base HEAD:** `5db4b4094c0d834b3cf482adb095273387b59dc8`

**Current boundary:** R1 signed off. **R2 Media Manager core complete and live-confirmed:** R2-A..R2-D; **R2-E (E1 journal/cache, E2 security/permission hardening, E3 frame lifecycle + surgical DOM patch, E4 feature isolation + release-notes/rollback)**; **R2-F (Entity Media Inventory & Replace, Slices 1–4)** — populated-field inventory + lazy thumbnails, gated replace via the `/replacement` endpoint (expected-current-value fingerprint; never deletes attachments), and a just-assigned field immediately replaceable (D-052, Slice 4); the group-nested ACF write bug is corrected (RK-013: write/read THROUGH the root group) and **live-confirmed by the user**; **R2-G** UX polish (live saved thumbnail + compact header status panel). **In progress: R2-H Persistent Media Index (Phase 1), Slices 1–4 + 2b + 2c + 4b-1 done** (D-053/D-054/D-055: `dbvc_ve_media_field_index` table + `MediaIndexStore`; `MediaIndexReadModel` lists with read-time per-user eligibility filtering + full search/entity/field/sort parity; `MediaIndexInvalidator`/`MediaIndexReconciler`/`MediaIndexScheduler` keep it fresh; **Slice 2b** adds opaque `vemx_` refs + a detached per-entity snapshot + `GET .../index` + `POST .../index/expand`; **Slice 2c** flips the frontend — flag-gated (`mediaManager.indexList`) open-from-index with `vemx_`-keyed rows and detached-snapshot expansion, with automatic fallback to the ephemeral scan; **Slice 4b-1** makes the index cross-user-complete — `EligibilityPolicy` `$structural` mode + `MediaIndexBuilder` chunked structural first-run build drained by the scheduler, and the completion hook rewired to `onScanRefreshed`/`refreshFromSnapshot` (refresh-in-place, no rotate/clobber)). The Manager opens instantly from the durable, per-user-filtered index, which now self-builds on first run for all users. Deferred: real-browser/AT QA (D-049), and R2-H Slice 4b-2 (topology/exclusion/registration rebuild triggers + atomic generation swap) + Slice 5 (JSON export/import). Plans: `releases/R2H-PERSISTENT-MEDIA-INDEX-PHASE-1.md`

## Purpose

This is the shortest authoritative resume path for the current Frontend Media Manager work. It replaces the original Visual Editor scaffold handoff, which no longer represented the implemented architecture.

Use this document with the current code and the implementation package under:

`docs/dropins/dbvc-visual-editor-brand-controls-guide/`

The code and current Git state remain authoritative when any planning language drifts.

## Safety and working-tree authority

The current implementation exists in an intentionally dirty working tree based on commit `5db4b40`. The base commit does not contain the accumulated R0/R1 implementation.

At the latest handoff refresh:

- the checked-out branch was `codex/visual-editor-linked-posts-plan`;
- HEAD was `5db4b4094c0d834b3cf482adb095273387b59dc8`;
- the branch tracked `origin/codex/visual-editor-linked-posts-plan` with no ahead/behind marker;
- tracked Visual Editor, guide, roadmap, package, and agent-document files were modified;
- Media Manager PHP, JavaScript, CSS, tests, fixtures, and mockup artifacts were untracked;
- no files were staged, committed, pushed, reset, restored, stashed, or cleaned during the latest slice.

Treat every current tracked and untracked change as user-owned work unless a fresh diff proves otherwise. Do not reset, restore, stash, clean, broadly stage, or overwrite it.

Start every resumed task with:

```bash
git status --short --branch
git rev-parse --abbrev-ref HEAD
git rev-parse HEAD
git diff --stat
```

If branch, HEAD, or the dirty boundary has changed, reconcile the delta before editing. Do not force the checkout back to this recorded state.

## Required read order

Read narrowly in this order:

1. `docs/README.md`
2. `docs/agent-entrypoints.md`
3. `addons/visual-editor/AGENTS.md`
4. `addons/visual-editor/README.md`
5. `addons/visual-editor/ARCHITECTURE.md`
6. this handoff
7. `addons/visual-editor/docs/knowledge/DATA_CONTRACTS.md`
8. `docs/dropins/dbvc-visual-editor-brand-controls-guide/README.md`
9. `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R1-MEDIA-MANAGER-SCAN-AND-REPORT.md`
10. `docs/dropins/dbvc-visual-editor-brand-controls-guide/tracking/IMPLEMENTATION-TRACKER.md`
11. `docs/dropins/dbvc-visual-editor-brand-controls-guide/quality/TEST-QA-RELEASE-GATES.md`
12. `docs/dropins/dbvc-visual-editor-brand-controls-guide/quality/MEDIA-MANAGER-TEST-MATRIX.md`

Read the R2 release and mutation contract only when the R1-E closeout is being reviewed or R2-A is explicitly authorized:

- `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R2-MEDIA-MANAGER-DIRECT-REMEDIATION.md`
- `docs/dropins/dbvc-visual-editor-brand-controls-guide/media-manager/MUTATION-STALE-DATA-AND-REVALIDATION.md`

Do not read `docs/archives/` as current guidance.

## Product boundary

The Frontend Media Manager is a missing image-assignment workflow for:

- published pages, posts, and eligible public CPTs;
- public/show-UI taxonomy terms;
- native featured-image assignments;
- supported unconditional top-level and deterministic group-only ACF image fields;
- supported unconditional top-level and deterministic group-only ACF gallery fields.

R1 is read-only reporting. R2 adds one-field-at-a-time remediation. The following remain outside the current phases:

- files, videos, oEmbed, broken-file health, attachment deletion, and duplicate detection;
- conditional, repeater, flexible-content, clone, option-owned, or user-owned scan paths;
- static Bricks image settings;
- same-row or cross-entity bulk save;
- automatic placeholder assignment;
- additional mobile/responsive design or QA work, tabled by decision D-036.

## Current implementation map

### R1-A: eligibility, ACF catalog, and value classification

- `src/MediaManager/EligibilityPolicy.php` discovers eligible public/show-UI owner families, enforces published posts, exclusions, and object-specific edit capability.
- `src/MediaManager/AcfMediaFieldCatalog.php` evaluates active ACF group visibility for the exact owner and exposes only supported media definitions.
- `src/MediaManager/MediaAssignmentValueClassifier.php` understands current image/gallery storage shapes and distinguishes empty from malformed non-empty values.

### R1-B: bounded scan and ephemeral snapshots

- `src/MediaManager/ScanCandidateProvider.php` provides deterministic bounded post/term candidate pages.
- `src/MediaManager/MediaScanService.php` rechecks authority, reads supported values only, and creates opaque HMAC-derived group/finding references plus empty fingerprints.
- `src/MediaManager/ScanSnapshotStore.php` stores compressed, user/blog-bound transient snapshots with generation, revision, TTL, payload ceiling, and short update locking.
- `src/MediaManager/MediaScanCoordinator.php` owns start, next, cancel, retry, invalidation, summary, cursor, and metrics state.

### R1-C: protected read model and REST

- `src/MediaManager/MediaScanReadModel.php` owns bounded server-side list search/filter/sort/cursor projection and `expandGroup()` current-state revalidation.
- `src/Rest/Controllers/MediaManagerController.php` exposes protected start/latest/list/next/retry/cancel/group routes.
- explicit scan/group calls require opaque scan identity, generation, and expected revision;
- browser payloads contain safe labels/counts/status only, never owner IDs, field keys/names/paths, fingerprints, raw values, descriptors, or mutation targets.

### R1-D: frontend shell, table, and lazy field expansion

- `assets/js/api-client.js` owns nonce-authenticated Media Manager route calls.
- `assets/js/overlay-app.js` owns the conditional toolbar entry and narrow open/close event seam.
- `assets/js/media-manager-app.js` owns lifecycle state, server-driven query state, cursor append, safe normalization, stale-response suppression, and one-row lazy expansion.
- `assets/css/media-manager.css` owns the separately scoped laptop/desktop presentation.
- `src/Assets/AssetLoader.php` localizes configuration and enqueues the module only when Visual Editor mode and the default-off Media Manager setting pass.

The first open calls only the latest-scan route. It does not automatically start or advance a scan. Row expansion calls only the server-owned group route. R1 does not hydrate a descriptor, call `wp.media`, mutate content, write a journal record, or invalidate a content cache.

### R1-E: implemented automated hardening

The current working tree includes:

- row-focus continuity across loading, success, and collapse rerenders;
- native Enter/Space disclosure operation;
- reduced-motion transition suppression;
- stable named dialog, results, scroll, and expanded-row regions;
- explicit busy/loading semantics;
- one stable polite live-region path for list and field-check lifecycle announcements;
- default-off, Builder isolation, and no-auto-scan regression coverage;
- synthetic 100/500/2,000-group snapshot/read/payload measurements;
- isolated Chromium, Firefox, and WebKit-engine automation at supported laptop/desktop viewports.

### R2-A: implemented descriptor bridge

- `src/MediaManager/MediaFindingDescriptorBridge.php` exchanges one opaque finding for one fresh standard `EditableDescriptor`. It loads the user/site-bound snapshot, validates generation/revision, resolves the owner/field only from the snapshot group/finding, rechecks owner eligibility/status/capability, rescans the single owner to reconfirm applicability and the current empty value by fingerprint, and mints a descriptor for `post_featured_image`, `acf_image`, or `acf_gallery` routing to exactly one existing resolver.
- The descriptor is re-resolved server-side by the R2-C save, so it is not persisted and no opaque token/session is returned (CR-1, 2026-08-16).
- `src/Rest/Controllers/MediaManagerController.php` adds a protected `POST .../scans/{scan_ref}/groups/{group_ref}/findings/{finding_ref}/descriptor` route; `Routes.php` and `Bootstrap/Addon.php` wire the bridge.
- The response returns only `{input, family, expectedState}` plus safe labels and writable/changed/resolved/unavailable status. R2-A opens no Media Library frame, hydrates no value, writes nothing, and journals nothing.

### R2-B: implemented native Media Library selection

- `assets/js/media-manager-app.js` adds a capability-gated `assign-media` control per still-`missing` field, `beginAssignMedia`/`handleDescriptorPayload`/`openAssignFrame`/`stageSelection`/`clearStagedSelection`, staged-selection state on `state.expansion.selections`, and a targeted `refreshExpansionPanel` that re-renders only the detail row and restores field focus.
- `assets/js/api-client.js` adds `mediaManager.descriptor(scan, groupRef, findingRef)` (POST to the R2-A route).
- `assets/css/media-manager.css` adds the assign controls, unsaved badge, and thumbnail preview (no new breakpoints; responsive floor preserved). `AssetLoader.php` localizes the R2-B strings.
- The `wp.media` frame reuses the same standard config as `overlay-app.js`'s image/gallery builders (single vs multiple, `library:{type:'image'}`); the overlay is untouched (D-046). The upload tab follows WordPress's `upload_files` capability. Escape/layering reuse the existing `mediaModalIsOpen` guard.
- R2-B stages the selection unsaved and writes nothing: no save, mutation, expected-empty check, journal, cache, or reconciliation, and the staged selection carries no descriptor token/session (CR-1) and no raw targets ever enter the DOM.

### R2-C: implemented field-level save

- `MediaFindingDescriptorBridge::resolveFinding()` was extracted so the R2-A bridge and the R2-C save share one revalidation authority; `bridgeFinding()` now delegates to it.
- `src/MediaManager/MediaAssignmentService.php` (`assign()`) re-runs `resolveFinding` as the expected-empty precondition, fails closed with `409 media_assignment_stale` when the field changed/was populated, validates the selection cardinality, mutates through the shared `MutationService`, and rereads via `expandGroup` to reconcile.
- `src/Rest/Controllers/MediaManagerController.php` adds `POST .../findings/{finding_ref}/assignment`; `Routes.php` and `Bootstrap/Addon.php` wire the service.
- `assets/js/media-manager-app.js` adds a Save control per staged field, `saveAssignment`/`reconcileAfterSave`/`reconcileGroupItem`, a per-finding `saving` state, and a resolved-row marker. It reconciles the field, row counts, and scan summary from the returned reread and issues no list/scan request. `api-client.js` adds `mediaManager.assign`.
- The write target is always the freshly server-resolved descriptor; the client token/selection is never the write authority. `overlay-app.js` remains untouched.

### R2-F: implemented entity media inventory, thumbnails, and replace

- **Slice 1 (inventory):** `MediaScanService::scan()` gains an `$include_assigned` mode (`buildPreview` → sanitized thumbnail URL/alt/count); `MediaScanReadModel::expandGroup` rescans in inventory mode and projects populated fields as `status: assigned` via `projectAssignedField`, merges the preview onto an empty-at-scan/now-populated resolved finding, dedups, and adds a `populated` count. Top-level list/counts stay empty-focused.
- **Slice 2 (thumbnails):** `media-manager-app.js` `createFieldProjection`/`createFieldThumbnail` render `[thumbnail | content]` (staged → `preview.url` → accent placeholder), lazy attributes, gallery `+{count}` badge; `media-manager.css` adds the flex layout and square/rounded/full-height thumb with a `color-mix` placeholder.
- **Slice 3 (replace):** `MediaScanService` emits an opaque `value_fingerprint` (`vemv_…`); `projectAssignedField` exposes it as `valueRef` and flips `availableActions.replace`. `MediaFindingDescriptorBridge::resolveReplaceable` re-resolves the owner from the snapshot, rescans, confirms still-populated, and `hash_equals`-enforces the expected-current-value precondition — non-writable outcomes are hard `WP_Error`s (`media_replace_stale`/`media_replace_not_populated`/`media_replace_value_ref_invalid`). `MediaAssignmentService::replace` shares one `applyMutation` pipeline with `assign` and returns `status: replaced`; the resolver overwrites the reference only and never deletes attachments. `MediaManagerController` adds `POST .../findings/{finding_ref}/replacement` (`handleReplaceFinding`), `Routes.php`/`Bootstrap/Addon.php` need no new dependency. `media-manager-app.js` adds `createFieldReplaceControls`/`beginReplaceMedia`/`saveReplacement` (no descriptor pre-call; the endpoint revalidates at save), `api-client.js` adds `mediaManager.replace`. `overlay-app.js` remains untouched.

## Existing mutation systems reserved for R2 reuse

R2 must extend these authoritative systems rather than creating a Media Manager-specific arbitrary writer:

- `src/Registry/EditableDescriptor.php` and `EditableRegistry.php` for opaque server-side descriptor identity and sessions;
- `src/Rest/DescriptorPayloadBuilder.php` and `Controllers/DescriptorController.php` for resolver-aware hydration;
- `src/Resolvers/PostFeaturedImageResolver.php` for attachment-backed featured images;
- `src/Resolvers/AcfImageResolver.php` for ACF images;
- `src/Resolvers/AcfGalleryResolver.php` for ordered ACF galleries;
- `src/Save/MutationContractService.php` and `MutationService.php` for validation, sanitization, mutation, audit, journal, and cache sequencing;
- `src/Journal/ChangeJournalRecorder.php` and journal store for durable change history;
- `src/Cache/CacheInvalidator.php` for post-save cache handoff;
- the existing `overlay-app.js` `wp.media` image/gallery setup as reusable lifecycle evidence.

The current descriptor registry is normally populated from render-time Visual Editor instrumentation. R2-A therefore needs a new narrow bridge for a non-rendered Media Manager finding. That bridge must resolve the target only from the current user/site-bound snapshot and opaque finding reference, then recheck owner status, object capability, current ACF applicability, field family, and current empty value before creating or returning any standard descriptor.

No client-supplied owner ID, field key/name, ACF object ID, selector, or path may become authority.

## Current validation evidence

The latest completed evidence is:

- `npm run test:visual-editor-media-manager-state`: 11/11 passing;
- `npm run lint:visual-editor-media-manager`: passing, with stale dependency-data warnings only;
- focused R1-D PHPUnit: 4 tests/47 assertions passing;
- focused R2-A PHPUnit: 11 tests/200 assertions passing (descriptor bridge revalidation, resolver routing, no-raw-target projection, user isolation, and fail-closed cases);
- `VisualEditorMediaManagerR2CTest`: 7 tests/81 assertions passing (three-family save + reconcile, expected-empty block, non-image/empty rejection, stale-generation block);
- Media Manager jsdom state suite: 34 tests passing (5 R2-B + 3 R2-C + 4 R2-D + 1 R2-F inventory + 1 R2-F Slice 2 + 3 R2-F Slice 3 + 2 R2-G polish + 4 R2-E3 frame-lifecycle/DOM-patch); targeted `lint:visual-editor-media-manager` clean;
- `VisualEditorMediaManagerR2ETest` (journal/cache verification): 3 tests/39 assertions passing;
- `VisualEditorMediaManagerR2FTest` (media inventory + preview): 3 tests/29 assertions passing (populated fields listed with sanitized preview; top-level list unchanged; no raw-target leak);
- `VisualEditorMediaManagerR2FReplaceTest` (replace mutation): 6 tests/62 assertions passing (success + fresh fingerprint + prior attachment retained + cache event; stale-ref block; emptied-after-read block; malformed-ref reject; non-image reject; gallery overwrite);
- `VisualEditorMediaManagerGroupedFieldTest` (group-nested field write): 4 tests passing — grouped gallery/image descriptors target the ROOT group selector and carry `group_write_path` (write/read THROUGH the group), and a grouped write never lands in a bare leaf meta; live nested-ACF save confirmed by the user (RK-013);
- `VisualEditorMediaManagerR2E2Test` (security/permission hardening): 9 tests/67 assertions passing — assign + replace fail closed with no write for a foreign user's snapshot, revision mismatch, owner unpublished after scan/read, revoked edit capability, and a non-existent attachment;
- `VisualEditorMediaManagerR2E4Test` (feature isolation): 4 tests/10 assertions passing — the kill switch closes `canAccess` for every route when the MM flag, VE master flag, base capability, or auth is absent;
- combined Media Manager PHPUnit (R1-A..R1-E plus R2-A, R2-C, R2-E, R2-E2, R2-E4, R2-F, R2-F replace, grouped): 71 tests passing;
- Playwright Media Manager table suite: 6/6 across Chromium, Firefox, and WebKit engines at 1440x900 and 1280x720;
- live active-site REST auth enforcement (unauthenticated): all Media Manager routes registered; `scans/latest`, tampered scan/group refs, and POST `scans` each return HTTP 401 `rest_forbidden` before resolution and create no snapshot;
- complete candidate traversal/raw reads across 100/300 live owners: constant 2 raw ACF reads per owner, one applicability evaluation per candidate, <=50 candidates and <=1 source query per chunk, per-candidate DB cost falling ~1.25 -> ~0.83 as owners triple (no field-definition/capability/permalink N+1);
- full PHP comparison: 755 tests, 8,832 assertions, and the same six inherited failures;
- agent documentation: 54 curated records, 418 discovered surfaces, zero unmapped (the descriptor, assignment, and replacement routes are registered and remapped);
- package checksum: 46/46 passing;
- `git diff --check`: passing.

The six inherited PHP failures are:

1. `BricksAddonPhase11Test::test_i18n_strings_are_rendered_for_phase11_additions`
2. `BricksAddonPhase7Test::test_disabled_mode_regression_suppresses_submenu_routes_and_jobs`
3. `CapabilityLandscapeTest::test_prepared_records_identify_cli_and_parity_opportunities`
4. `ContentCollectorV2Phase29Test::test_phase_twenty_nine_resolved_conflicts_no_longer_block_package_preflight`
5. `ContentCollectorV2Phase32Test::test_phase_thirty_two_url_qa_reports_field_context_provider_risk_and_reviewed_ambiguity`
6. `ContentMigrationPhase37W0SettingsTest::test_phase37_feature_flags_are_registered_with_safe_defaults`

Do not claim the repository suite is green. Distinguish these identities from any new regression.

On 2026-08-16 the aggregate `npm run lint` was given one bounded attempt that ran ~11 minutes without completing and was stopped. Targeted Media Manager lint is current; aggregate lint is not a current pass.

## Remaining R1-E release evidence

Proven during the 2026-08-16 closeout:

- live active-site REST route registration and unauthenticated auth enforcement (401 before resolution; no snapshot created);
- representative complete candidate traversal and raw ACF read scale at 100/300 live owners with no field-definition/capability/permalink N+1.

The following are the accepted residual gates, not yet proven:

- authenticated active-site REST and table **data** behavior against real WordPress responses (blocked: no already-authorized session is available);
- VoiceOver or equivalent real assistive-technology operation;
- real Safari behavior; the WebKit engine automation is not Safari proof;
- large-list browser responsiveness at authenticated runtime;
- a completing aggregate repository JavaScript lint run or an explicit risk acceptance.

Runtime provenance was rechecked read-only on 2026-08-16: active site `dbvc-codexchanges.local` using this plugin checkout and the `vertical` child theme over Bricks, DBVC plugin active, `siteurl=https://dbvc-codexchanges.local`, MySQL running. The Media Manager option remains on; the persistent Visual Editor option is now **on** (a drift from the previously recorded off state — recorded, not reverted). No in-app browser session exists (logged out). No option, login, content, or LocalWP state was changed.

Do not toggle persistent options, log in, inspect credentials, modify content, or change LocalWP state merely to satisfy a test. Use an already-authorized authenticated session if one exists; otherwise record the exact blocked gate.

## Immediate next slice

R1 was signed off on 2026-08-16; R2-A..R2-D and R2-E1 are complete; and **R2-F Slices 1–3 are complete** (2026-08-17): inventory + preview (`VisualEditorMediaManagerR2FTest` 3 tests/29 assertions), thumbnail presentation, and gated replace (`VisualEditorMediaManagerR2FReplaceTest` 6 tests/62 assertions; jsdom 28 total). The full suite runs 738/8,705 (six inherited failures); agent docs pass 54/418/0.

**R2-E is complete (E1–E4).** E2: assign + replace fail closed with no write for foreign-user snapshots, revision mismatch, owner unpublished after scan/read, revoked edit capability, and a non-existent attachment. E3: a single active `wp.media` frame torn down on re-open/collapse/group-switch/list-reload/close (RK-011), and a save patches only the affected row (siblings preserved, no list/scan reload). E4: the kill switch closes `canAccess` for every route when the MM flag, VE master flag, base capability, or auth is absent, plus a consolidated release-notes/rollback runbook (`releases/MEDIA-MANAGER-RELEASE-NOTES-AND-ROLLBACK.md`).

**Persistent Media Index — Phase 1 (R2-H), Slices 1–4 + 2b + 2c complete** (D-053/D-054: custom table + JSON export; cross-user with read-time per-user eligibility filtering; Action Scheduler + WP-Cron fallback). **Slice 1**: `{prefix}dbvc_ve_media_field_index` table + `MediaIndexStore`. **Slice 2**: completion-action population + `MediaIndexReadModel::getList` with read-time per-user eligibility filtering. **Slice 3**: `MediaIndexInvalidator` re-indexes/removes a single entity on save/delete/term hooks. **Slice 4**: `MediaIndexReconciler` (scheduled) re-indexes dirty entities in chunks, `onAttachmentDeleted` flags the generation dirty, and `MediaIndexScheduler` runs the reconcile via Action Scheduler with a WP-Cron fallback. **Slice 2b (server plumbing)**: stable opaque `entity_ref` (`vemx_…`, store-only-resolvable) + `getByEntityRef`; `MediaScanCoordinator::snapshotEntity` builds a detached per-entity snapshot (`ScanSnapshotStore::createDetached`) that never clobbers the latest full scan; `MediaIndexController` exposes `GET .../media-manager/index` + `POST .../media-manager/index/expand`. **Slice 2c (frontend flip)**: `MediaIndexReadModel`/`MediaIndexStore` gain full search/entity/field/sort query parity, and the Manager — gated behind the filterable `mediaManager.indexList` flag — opens from `GET .../index` with `vemx_`-keyed rows and expands via `POST .../index/expand`, adopting the detached snapshot as the per-expansion working identity (`expansion.scan` + working `vemg_` group) so descriptor/assign/replace are unchanged, with **automatic fallback to the ephemeral scan** on index error or empty index. **Slice 4b-1 (structural first-run build)**: `EligibilityPolicy` gains a `$structural` mode (skips only the per-object capability check); `MediaIndexBuilder` enumerates the structural eligible set in bounded chunks (cursor persisted across runs) and is drained by `MediaIndexScheduler` (`BUILD_HOOK`, AS async chain / WP-Cron single-event chaining) until complete; and the manual-scan completion hook is rewired to `onScanRefreshed`/`refreshFromSnapshot` (upsert scanned entities into the current generation, no rotate/prune) so the index is cross-user-complete and a scan never clobbers it. The index builds itself on first run for all users, self-maintains on edits, self-heals in the background, and is the live read source with a safe scan fallback. Plan: `releases/R2H-PERSISTENT-MEDIA-INDEX-PHASE-1.md`.

The next bounded tasks (do not begin without explicit authorization):

- **R2-H Slice 4b-2** — topology/exclusion rebuild triggers: ACF field-group save/delete, post-type/taxonomy (de)registration, and Media-Manager exclusion-option changes rotate the generation and rebuild, with an atomic build-into-fresh-generation-then-swap so reads never see a half-built index mid-rebuild.
- **R2-H Slice 5** — JSON export/import for backup portability.
- **Real-browser / AT QA** — authenticated Media Library open/upload/focus, keyboard flows, repeated-open memory/listener profiling, current-page DOM/reload confirmation, real Safari (deferred, D-049).
- **R2-F browser QA** — laptop/desktop Media Library + keyboard verification of the assign/replace flows (deferred, D-049).
- **Shared `wp.media` factory consolidation** — collapse the overlay and Media Manager call sites onto one factory (RK-011).
- **Overlay descriptor-session toast** — investigate the "descriptor session was unavailable" message on page load (separate overlay subsystem, not Media Manager).

**Deferred** (D-049, resume later): laptop/desktop Media Library browser + keyboard QA; touch/mobile-specific QA remains tabled by D-036. Keep the responsive floor and add no mobile-specific work.

## Following implementation sequence

After R1 sign-off or explicit acceptance of its remaining evidence risks:

### R2-A: descriptor bridge — **implemented 2026-08-16**

- exchange one opaque current finding for one fresh standard descriptor; **done**
- recheck snapshot identity, owner status/capability, field applicability, field family, and empty state; **done**
- report writable, inspect-only, changed, or unavailable without exposing raw targets; **done** (writable/changed/resolved/unavailable);
- test tampered, expired, stale, changed-definition, changed-value, and permission-loss cases; **done** (11 tests/200 assertions);
- stop before `wp.media` or content mutation. **held**

### R2-B: native Media Library selection — **implemented 2026-08-16**

- reuse the existing image/gallery `wp.media` lifecycle; **done** (same standard config as `overlay-app.js`, overlay untouched, D-046)
- use single selection for featured/ACF image and ordered multiple selection for gallery; **done**
- retain WordPress upload capability behavior and use no custom uploader; **done** (native `wp.media`, `upload_files`-gated)
- stage a visible unsaved selection inside the loaded Media Manager table; **done** (badge + preview + Replace/Clear)
- preserve shell/modal focus, Escape, and layering; **done** (existing `mediaModalIsOpen` guard)
- stop before persistence. **held**

This is the first slice where users can choose an existing image or upload a new one without following the entity `Open` link. Residual gate: real-browser `wp.media` open/upload/focus-layering QA (authenticated runtime unavailable).

### R2-C: field-level save and no-reload reconciliation — **implemented 2026-08-16**

- save through existing resolver/mutation/journal/audit/cache systems; **done** (`MediaAssignmentService` -> `MutationService`)
- enforce the expected-old-empty precondition immediately before mutation; **done** (re-runs `resolveFinding`; blocks with `409 media_assignment_stale`)
- validate local image attachment IDs, MIME, and gallery cardinality/order;
- reread the canonical field after save;
- update the expanded field, row counts, scan summary, and fully resolved row in place;
- preserve search, filters, sort, cursor-loaded rows, scroll, and focus;
- do not reload the Media Manager table or require entity-page navigation.

The entity `Open` link remains a fallback. Same-row and cross-entity bulk save remain absent.

## Likely files for the next phases

R1-E closeout should primarily touch tests and documentation. R2-A is likely to touch or add narrowly scoped code around:

- `addons/visual-editor/src/MediaManager/`
- `addons/visual-editor/src/Rest/Controllers/MediaManagerController.php`
- `addons/visual-editor/src/Rest/Routes.php`
- `addons/visual-editor/src/Registry/`
- `addons/visual-editor/src/Rest/DescriptorPayloadBuilder.php`
- `addons/visual-editor/src/Bootstrap/Addon.php`
- `tests/phpunit/VisualEditorMediaManagerR1ETest.php`
- a new focused R2-A PHPUnit test file
- the implementation guide's R1/R2 release, evidence, decision, risk, coverage, and tracker files.

R2-B/R2-C may then touch:

- `addons/visual-editor/assets/js/api-client.js`
- `addons/visual-editor/assets/js/media-manager-app.js`
- `addons/visual-editor/assets/css/media-manager.css`
- `addons/visual-editor/assets/js/overlay-app.js` only for reusable media-frame extraction or a narrow shared seam;
- `addons/visual-editor/src/Assets/AssetLoader.php`;
- the existing featured-image/image/gallery resolvers, mutation service, journal, audit, and cache services only where integration requires it;
- focused jsdom, PHPUnit, and Playwright Media Manager tests.

Do not assume every listed file must change. Prefer the smallest contract-preserving slice.

## Validation commands

Use risk-based validation and avoid broad reruns after every edit.

```bash
npm run test:visual-editor-media-manager-state
npm run lint:visual-editor-media-manager
npm run playwright:test:visual-editor-media-manager-table
vendor/bin/phpunit -c phpunit.xml.dist --do-not-cache-result --filter 'VisualEditorMediaManagerR1[ABCDE]Test' tests/phpunit
composer agent-docs:check
git diff --check
```

Run touched PHP syntax checks and focused new tests first. Run the full PHP suite at a coherent release checkpoint and compare exact failure identities. Run `composer agent-docs:refresh` followed by `composer agent-docs:check` only when public REST, settings, add-on, hook, or safety surfaces change.

Playwright engine automation may require browser-process permission outside the filesystem sandbox. Do not install or upgrade dependencies unless explicitly authorized.

## Stop and handback format

At the end of each bounded slice, report:

1. branch, HEAD, divergence, and dirty boundary;
2. exact scope implemented;
3. files changed or added;
4. contracts reused and any new contract introduced;
5. exact validation commands and results;
6. inherited versus new failures;
7. runtime, browser, accessibility, and performance evidence boundaries;
8. guide/tracker/agent-doc reconciliation;
9. residual risks and rollback;
10. the next explicit approval line.

Do not stage, commit, push, or publish unless the user asks.
