# DBVC Visual Editor and Frontend Media Manager Current Handoff

**Updated:** August 16, 2026

**Repository:** `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`

**Branch:** `codex/visual-editor-linked-posts-plan`

**Base HEAD:** `5db4b4094c0d834b3cf482adb095273387b59dc8`

**Current boundary:** R1-A through R1-D implemented; R1-E automated hardening implemented with runtime/manual release evidence still open; R2 not started

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
- combined R1-A through R1-E PHPUnit: 22 tests/685 assertions passing;
- Playwright Media Manager table suite: 6/6 across Chromium, Firefox, and WebKit engines at 1440x900 and 1280x720;
- full PHP comparison: 706 tests, 7,820 assertions, and the same six inherited failures;
- agent documentation: 54 curated records, 415 discovered surfaces, zero unmapped;
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

The latest aggregate `npm run lint` rerun did not complete and was stopped. Targeted Media Manager lint is current; aggregate lint is not a current pass.

## Remaining R1-E release evidence

The following are not yet proven:

- authenticated active-site REST and table behavior against real WordPress responses;
- representative complete candidate traversal and raw ACF read scale, beyond synthetic snapshot/list/payload scale;
- VoiceOver or equivalent real assistive-technology operation;
- real Safari behavior; WebKit automation is not Safari proof;
- a completing aggregate repository JavaScript lint run or an explicit risk acceptance.

Runtime provenance was previously identified as `dbvc-codexchanges.local` using this plugin checkout and the `vertical` child theme over Bricks. The Visual Editor persistent option was off, the Media Manager option was on, and the available browser session was logged out. This state may have changed and must be rechecked read-only.

Do not toggle persistent options, log in, inspect credentials, modify content, or change LocalWP state merely to satisfy a test. Use an already-authorized authenticated session if one exists; otherwise record the exact blocked gate.

## Immediate next slice

The next bounded task is R1-E closeout:

1. refresh Git and active-runtime provenance without changing state;
2. rerun focused current checks before interpreting older evidence;
3. obtain authenticated real-response proof only if an authorized session is already available;
4. measure representative bounded candidate traversal/raw reads with deterministic, non-mutating fixtures where possible;
5. perform or coordinate real VoiceOver and real Safari checks without presenting engine automation as equivalent;
6. give aggregate lint one bounded current attempt and record completion or the residual caveat;
7. reconcile R1 release gates, evidence, risks, tracker, module docs, and roadmap;
8. stop for review before R2-A unless the user has explicitly authorized that crossing line.

Do not add more responsive/mobile work during this slice.

## Following implementation sequence

After R1 sign-off or explicit acceptance of its remaining evidence risks:

### R2-A: descriptor bridge

- exchange one opaque current finding for one fresh standard descriptor;
- recheck snapshot identity, owner status/capability, field applicability, field family, and empty state;
- report writable, inspect-only, changed, or unavailable without exposing raw targets;
- test tampered, expired, stale, changed-definition, changed-value, and permission-loss cases;
- stop before `wp.media` or content mutation.

### R2-B: native Media Library selection

- reuse the existing image/gallery `wp.media` lifecycle;
- use single selection for featured/ACF image and ordered multiple selection for gallery;
- retain WordPress upload capability behavior and use no custom uploader;
- stage a visible unsaved selection inside the loaded Media Manager table;
- preserve shell/modal focus, Escape, and layering;
- stop before persistence.

This is the first slice where users can choose an existing image or upload a new one without following the entity `Open` link.

### R2-C: field-level save and no-reload reconciliation

- save through existing resolver/mutation/journal/audit/cache systems;
- enforce the expected-old-empty precondition immediately before mutation;
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
