# DBVC Visual Editor and Frontend Media Manager Current Handoff

**Updated:** August 16, 2026

**Repository:** `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`

**Branch:** `codex/visual-editor-linked-posts-plan`

**Base HEAD:** `5db4b4094c0d834b3cf482adb095273387b59dc8`

**Current boundary:** R1 signed off (read-only scan/report) with residual gates accepted; R2-A descriptor bridge, R2-B native Media Library selection, and R2-C field-level save implemented. R2-C is the first content-mutating slice: it enforces the expected-empty precondition immediately before writing (a field populated after scan is blocked and never overwritten), writes through the shared audited `MutationService`, and reconciles the finding/row/summary from a targeted reread with no table reload. R2-D verified UX states and R2-E production hardening remain and are not started

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
- `src/Registry/EditableRegistry.php` gains a narrow `persistDetachedDescriptor()` that stores one off-render descriptor in a fresh user-bound session without a supported render page context.
- `src/Rest/Controllers/MediaManagerController.php` adds a protected `POST .../scans/{scan_ref}/groups/{group_ref}/findings/{finding_ref}/descriptor` route; `Routes.php` and `Bootstrap/Addon.php` wire the bridge.
- The response returns only opaque token/session ids plus safe labels and writable/changed/resolved/unavailable status. R2-A opens no Media Library frame, hydrates no value, writes nothing, and journals nothing.

### R2-B: implemented native Media Library selection

- `assets/js/media-manager-app.js` adds a capability-gated `assign-media` control per still-`missing` field, `beginAssignMedia`/`handleDescriptorPayload`/`openAssignFrame`/`stageSelection`/`clearStagedSelection`, staged-selection state on `state.expansion.selections`, and a targeted `refreshExpansionPanel` that re-renders only the detail row and restores field focus.
- `assets/js/api-client.js` adds `mediaManager.descriptor(scan, groupRef, findingRef)` (POST to the R2-A route).
- `assets/css/media-manager.css` adds the assign controls, unsaved badge, and thumbnail preview (no new breakpoints; responsive floor preserved). `AssetLoader.php` localizes the R2-B strings.
- The `wp.media` frame reuses the same standard config as `overlay-app.js`'s image/gallery builders (single vs multiple, `library:{type:'image'}`); the overlay is untouched (D-046). The upload tab follows WordPress's `upload_files` capability. Escape/layering reuse the existing `mediaModalIsOpen` guard.
- R2-B stages the selection unsaved and writes nothing: no save, mutation, expected-empty check, journal, cache, or reconciliation, and the descriptor token/session and raw targets never enter the DOM.

### R2-C: implemented field-level save

- `MediaFindingDescriptorBridge::resolveFinding()` was extracted so the R2-A bridge and the R2-C save share one revalidation authority; `bridgeFinding()` now delegates to it.
- `src/MediaManager/MediaAssignmentService.php` (`assign()`) re-runs `resolveFinding` as the expected-empty precondition, fails closed with `409 media_assignment_stale` when the field changed/was populated, validates the selection cardinality, mutates through the shared `MutationService`, and rereads via `expandGroup` to reconcile.
- `src/Rest/Controllers/MediaManagerController.php` adds `POST .../findings/{finding_ref}/assignment`; `Routes.php` and `Bootstrap/Addon.php` wire the service.
- `assets/js/media-manager-app.js` adds a Save control per staged field, `saveAssignment`/`reconcileAfterSave`/`reconcileGroupItem`, a per-finding `saving` state, and a resolved-row marker. It reconciles the field, row counts, and scan summary from the returned reread and issues no list/scan request. `api-client.js` adds `mediaManager.assign`.
- The write target is always the freshly server-resolved descriptor; the client token/selection is never the write authority. `overlay-app.js` remains untouched.

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
- Media Manager jsdom state suite: 19 tests passing (5 R2-B staging + 3 R2-C save/reconcile/conflict/saving-state); targeted `lint:visual-editor-media-manager` clean;
- combined Media Manager PHPUnit (R1-A through R1-E plus R2-A and R2-C): 41 tests/1,413 assertions passing;
- Playwright Media Manager table suite: 6/6 across Chromium, Firefox, and WebKit engines at 1440x900 and 1280x720;
- live active-site REST auth enforcement (unauthenticated): all Media Manager routes registered; `scans/latest`, tampered scan/group refs, and POST `scans` each return HTTP 401 `rest_forbidden` before resolution and create no snapshot;
- complete candidate traversal/raw reads across 100/300 live owners: constant 2 raw ACF reads per owner, one applicability evaluation per candidate, <=50 candidates and <=1 source query per chunk, per-candidate DB cost falling ~1.25 -> ~0.83 as owners triple (no field-definition/capability/permalink N+1);
- full PHP comparison: 725 tests, 8,550 assertions, and the same six inherited failures;
- agent documentation: 54 curated records, 417 discovered surfaces, zero unmapped (the descriptor and assignment routes are registered and remapped);
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

R1 was signed off on 2026-08-16, and R2-A (descriptor bridge), R2-B (native Media Library selection), and R2-C (field-level save) are implemented and reviewed. R2-C is the first content-mutating slice; the expected-empty gate is enforced and proven. Focused: jsdom 19 tests (3 R2-C), `VisualEditorMediaManagerR2CTest` 7/81, combined Media Manager PHP 41/1,413; the full suite runs 725/8,550 with the same six inherited failures; agent docs pass 54/417/0. Residual gate: real-browser save/upload/reconciliation QA (authenticated runtime unavailable).

The next bounded task is **R2-D — verified UX states** (do not begin without explicit authorization):

- take the R1 mockup as a base and add verified states for: media modal open; image selected but unsaved; gallery selected but unsaved; upload unavailable; save in progress; saved/verified; changed since scan; validation error; entity resolved and removed;
- these are presentation/state refinements over the implemented R2-A/R2-B/R2-C behavior — no new REST surface, no new mutation authority;
- keep the responsive floor and add no mobile-specific work (tabled by D-036).

R2-E (production hardening: security/stale/attachment/permission tests, journal/cache verification, laptop/desktop browser+keyboard QA, repeated-expansion performance, current-page DOM/reload QA, feature isolation, release notes, rollback) follows R2-D.

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
