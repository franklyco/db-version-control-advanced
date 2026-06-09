# DBVC Visual Editor Handoff - 2026-05-24

## Purpose

Use this document to resume the DBVC Visual Editor implementation in a fresh Codex session without re-discovering the current state.

Primary scope remains the Visual Editor add-on:

```text
addons/visual-editor/
```

Do not switch to Bricks Portability, Content Collector, AI packages, configuration portability, or other DBVC modules unless the user explicitly asks for that module.

## Repository Snapshot

- Branch at handoff: `codex/visual-editor-linked-posts-plan`
- Last pushed commit before this handoff doc: `540be41 Commit current DBVC implementation state`
- Remote: `origin` -> `https://github.com/franklyco/db-version-control-main.git`
- Commit status at the time of the prior push: clean
- Important note after the push: unrelated proposal docs under root `docs/` were modified/created outside Visual Editor. Leave them alone unless the user explicitly asks for proposal-diff work.

This handoff document itself is new work after `540be41`.

## Start Here In A Fresh Session

1. Read repo root `AGENTS.md`.
2. Read this handoff.
3. Check `git status -sb`.
4. Stay inside `addons/visual-editor/` unless the user explicitly broadens scope.
5. Before changing behavior, inspect the relevant code path and the matching implementation guide section below.

## Core Docs Map

- [Docs index](../README.md)
- [Current phased implementation state](../enhancements/DBVC_VISUAL_EDITOR_PHASES.md)
- [Advanced implementation guide](../enhancements/DBVC_VISUAL_EDITOR_ADVANCED_IMPLEMENTATION_GUIDE.md)
- [Collection editor plan](../enhancements/DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md)
- [Native loop expansion plan](../enhancements/DBVC_VISUAL_EDITOR_NATIVE_LOOP_EXPANSION_PLAN.md)
- [Archive context plan](../enhancements/DBVC_VISUAL_EDITOR_ARCHIVE_CONTEXT_PLAN.md)
- [Badge and hydration plan](../enhancements/DBVC_VISUAL_EDITOR_BADGE_AND_HYDRATION_PLAN.md)
- [Toolbar 2.0 implementation guide](../enhancements/DBVC_VISUAL_EDITOR_TOOLBAR_2_0_IMPLEMENTATION_GUIDE.md)
- [Native ACF loop hardening map](../knowledge/NATIVE_ACF_LOOP_HARDENING_MAP.md)
- [Data contracts](../knowledge/DATA_CONTRACTS.md)
- [Resolver registry notes](../knowledge/RESOLVER_REGISTRY.md)
- [Bricks render instrumentation model](../knowledge/BRICKS_RENDER_INSTRUMENTATION_MODEL.md)
- [Builder mode guard](../knowledge/frontend-plugin-builder-mode-guard.md)
- [Guardrails](../standards/VISUAL_EDITOR_GUARDRAILS.md)
- [UI states and copy](../standards/UI_STATES_AND_COPY.md)
- [QA checklist](../qa/QA_CHECKLIST.md)
- [Test log](../qa/TEST_LOG.md)

## Current Feature State

The Visual Editor currently supports or partially supports:

- Render-time Bricks instrumentation with lightweight DOM markers and transient descriptor sessions.
- Source-owner classification for current objects, related posts, related terms, related users, ACF options/shared fields, archive terms, loop-owned sources, and inspect-only derived sources.
- Shared hover/focus badge model with differentiated labels and dashed border colors by source.
- Draggable, closable, viewport-fitting `dbvc-ve-panel`.
- Media Library-safe outside-click behavior.
- Longer descriptor session TTL plus focus/visibility keepalive.
- Lazy descriptor hydration plus bounded viewport-aware prefetch.
- Text, rich text, choice, link, image, background image, gallery, relationship/post_object collection, and linked-term panel inputs where contracts are implemented.
- Native ACF repeater, flexible, relationship, post_object, and taxonomy loop provenance for many current and loop-owned scenarios.
- Current-owner connected-items container markers for ACF relationship/post_object query roots.
- Derived Bricks Query Editor linked-post collection editors where final query IDs prove one current-owner or exact shared-option source.
- Shared Globals toolbar inventory for configured option-owned relationship/post_object fields.
- Visual Editor settings/exclusions page.
- Review Fields index grouping by public source metadata.
- Toolbar 2.0 shell and status/review popover.

## Recent Stable Slices To Preserve

### Media And Gallery

Relevant code:

- `assets/js/overlay-app.js`
  - `createMediaReferenceController()`
  - `createMediaGalleryReferenceController()`
  - `renderGalleryPreviewItems()`
  - `reorderGalleryItems()`
  - `patchGalleryCollectionNode()`
  - `setNodeDisplayValue()`
  - `updateSaveButtonState()`
  - `handleSave()`
- `assets/css/overlay.css`
  - `.dbvc-ve-panel__gallery-preview`
  - `.dbvc-ve-panel__gallery-item`
  - `.dbvc-ve-panel__gallery-action`
  - `.dbvc-ve-live-gallery-item`
- `src/Assets/AssetLoader.php`
  - panel strings for media/gallery add, replace, clear, drag sorting, no-reload save, and reload save
- `src/Resolvers/AcfGalleryResolver.php`
- `src/Resolvers/AcfImageResolver.php`
- `src/Resolvers/PostFeaturedImageResolver.php`
- `src/Save/MutationContractService.php`

Current behavior:

- Gallery panel is additive by default.
- `Add images` appends unique selected Media Library images.
- `Replace gallery` intentionally overwrites the collection.
- Each thumbnail supports remove, move earlier/later, and desktop HTML5 drag/drop sorting.
- Rendered image, background-image, and gallery markers expose `Save` plus `Save and Reload`.
- No-reload saves patch visible DOM for rendered images/backgrounds/galleries.
- Missing/conditional media markers remain reload-only because there may be no safe rendered target to patch.

Known follow-up:

- Live browser QA should confirm no-reload save on direct image markers, background-image markers, and the `xxrpfg` gallery.
- Non-empty condition-skipped gallery/image values remain deferred because proximity-based writable markers would be unsafe without a concrete rendered target.

### Connected Items And Query Collections

Relevant code:

- `src/Resolvers/AcfReferenceCollectionResolver.php`
- `src/Resolvers/ResolverRegistry.php`
- `src/Save/MutationContractService.php`
- `src/Rest/Controllers/ReferenceSearchController.php`
- `src/Rest/Controllers/SharedGlobalFieldsController.php`
- `src/Bricks/ElementInstrumentationService.php`
- `assets/js/overlay-app.js`
  - reference collection controllers
  - query collection badge layout
  - seed/undo controls
  - no-reload save handling

Current behavior:

- Current-owner native ACF relationship/post_object query roots can surface `Edit Connected`.
- Repeater/flexible/grouped current-owner relationship/post_object query roots can use the same collection editor when row ancestry is proven.
- Loop-owned related-post relationship/post_object query roots can use the collection editor with related-owner acknowledgement.
- Derived Query Editor loops can become writable when final query IDs exactly prove one current-owner source subset or exact full source.
- Exact shared-option fallback collections can become writable with shared acknowledgement.
- Empty current-owner derived query loops can surface a parent/container badge and allow adding the first connected item through the same proven contract.
- No-reload `Save` and `Save and Reload` are supported for query collections.
- Seed actions can add fallback items to the current page field, expose undo/reload controls, and re-enable primary save buttons after undo.

Known follow-up:

- Shared connected-item collections remain deferred outside configured Shared Globals and exact fallback branches.
- Loop-owned non-post connected-item collections remain deferred.
- Recent/default fallback branches remain inspect-only unless exact source evidence is proven.

### Post Terms Collection

Relevant code:

- `src/Resolvers/PostTermsCollectionResolver.php`
- `src/Resolvers/ResolverRegistry.php`
  - post terms classification
- `src/Save/MutationContractService.php`
- `src/Rest/Controllers/ReferenceSearchController.php`
- `src/Bricks/ElementInstrumentationService.php`
- `assets/js/overlay-app.js`
  - term-specific collection panel behavior via existing reference collection UI
  - badge grouping for repeated owner-scoped term collection markers

Current behavior:

- Bricks term query loops using `objectType: term` + `current_post_term` can map to a post-owned native term collection when one taxonomy and one concrete owner post are proven.
- Native Bricks taxonomy display elements such as `post-taxonomy` can reuse the same `post_terms_collection` contract when one taxonomy and one current or loop-owned post owner are proven.
- Repeated card instances now group by owner/taxonomy so the first card does not swallow badges for later cards.
- Hover/focus badge fallback is allowed for `post_terms_collection` markers when container-level badge placement is insufficient.

Known follow-up:

- Empty term-loop handling is WIP and is the most logical next implementation slice.
- Live browser QA remains open for marker placement, panel load, term search, no-reload save, optional reload, and rendered chip updates.

### Archive Context

Relevant code:

- `src/Context/PageContextResolver.php`
- `src/Bricks/ElementInstrumentationService.php`
- `src/Resolvers/ResolverRegistry.php`
- `src/Save/MutationContractService.php`

Current behavior:

- Public CPT archives, posts archive, and taxonomy archives can get archive-aware context.
- Direct ACF fields owned by queried taxonomy terms can save when the descriptor proves the current archive term.
- Option-backed ACF fields on archive pages can save through shared-field acknowledgement where owner/source is proven.
- Archive query-loop descendants can reuse existing concrete loop-owner contracts where Bricks exposes stable per-row post or term ownership.

Known follow-up:

- Collection fields, galleries, and non-concrete archive loop owners remain inspect-only.
- Do not treat archive pages as fake singular posts.

### Toolbar 2.0, Shared Globals, Settings, Field Index

Relevant code:

- `src/Admin/SettingsPage.php`
- `src/Rest/Controllers/ObjectSearchController.php`
- `src/Rest/Controllers/SharedGlobalFieldsController.php`
- `src/Rest/Routes.php`
- `assets/js/api-client.js`
- `assets/js/overlay-app.js`
- `assets/css/overlay.css`
- `src/Assets/AssetLoader.php`

Current behavior:

- Toolbar 2.0 shell exists with upward status/review popover.
- Go To Object search is navigation-only.
- Shared Globals popover is scoped to configured option-owned ACF relationship/post_object fields.
- Visual Editor settings page exists, including excluded post types/taxonomies.
- Review Fields index preserves scroll during passive refresh and groups fields by safe public metadata.

Known follow-up:

- Live browser QA still needs to confirm Shared Globals save, toolbar popovers, tooltip/focus behavior, large connected-items scrolling, settings page behavior, and frontend search exclusions.

## Important Fixtures And Examples

Use only `dbvc-codexchanges.local` unless the user explicitly asks to work in another LocalWP site.

Existing Bricks templates can be inspected through WordPress/Bricks database state or the DBVC synced child-theme exports:

```text
/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/themes/vertical/sync/db-version-control-main/bricks_template/
```

Prefer the synced JSON files for quick static inspection and line references; verify against the database/runtime when behavior depends on current Bricks render state.

### Media/Gallery

- URL: `https://dbvc-codexchanges.local/vertical/websites-for-contractors/`
- Post: vertical CPT, post ID `28148`
- Template: `FLO-Verticals-Single`, template ID `26763`
- Synced template export: `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/themes/vertical/sync/db-version-control-main/bricks_template/bricks_template-flo-verticals-single-26763.json`
- Gallery element: `xxrpfg`
- Root-level synced export references:
  - element ID/name at line `38317`: `"id": "xxrpfg"`, `"name": "image-gallery"`
  - dynamic source at line `38323`: `"useDynamicData": "{acf_gallery_section_gallery}"`
  - parent container at line `38305`: `"id": "hmupao"`, `"name": "container"`
- The same element ID appears earlier in repeated/template-expanded blocks in the export. For the root media/gallery fixture, use the final root-level block around lines `38317-38323`.
- Gallery field: `gallery_section_gallery`
- Expected marker: rendered gallery wrapper with `data-dbvc-ve-context="gallery_collection"` and `data-dbvc-ve-input="media_gallery_reference"`
- Benefits missing image case:
  - Empty related-owner image wrapper: `.brxe-ktiaxo`
  - Missing media marker should use `data-dbvc-ve-missing-media="1"`
  - Save remains reload-only if the original image DOM is missing

### Empty Gallery Control

- URL: `https://dbvc-codexchanges.local/vertical/dentists/`
- Post ID: `23690`
- Same template/field as above
- Current behavior: Bricks emits no `xxrpfg` markup and no marker when the gallery element is fully condition-skipped. This is a deferred gap, not a regression in empty-output fallback.

### Native ACF Repeater

- Page ID: `24732`
- Template: `flo-explainer-single`
- Query loop type: ACF Repeater, `acf_process_section_process_steps`
- Previously validated fields:
  - `acf_process_steps_step_label`
  - `acf_process_steps_step_name`
  - `acf_process_steps_step_description`
  - grouped duration fields such as `acf_step_duration_group_step_duration_value_1`
- Important prior bug: repeater rows without images must still expose text/grouped descendants.

### Nested Repeater

- Product CPT post: `Grow Plan`, post ID `20526`
- Template stack: `FCO-Landing-Single` with nested Bricks template `f3-price-single-norepeater`
- Parent Bricks query loop: `brxe-yriblb`
- Parent ACF repeater loop: `brxe-dujolg`
- Nested repeater loop: `brxe-wevhbe`
- Top-level repeater: `_price_item_repeater`
- Nested repeater: `_price_item_repeater_0_quantities`

### Connected Items And Query Collections

- Template: `FLO-Verticals-Single`
- Related posts/cards container: `brxe-swnffk`
- Query loop class previously discussed: `wbkdhv`
- Nested group relationship field example:
  - ACF flattened selector: `benefits_section_benefitsContent_related_items`
  - Stored value can be nested as an array of IDs
- Empty query loop parent case:
  - Parent container: `brxe-yjxtqp`
  - Bricks loop-start comment example: `<!--brx-loop-start-zsfmel-->`
  - This was tested on a different LocalWP site. Do not touch that site unless explicitly asked.

### Post Terms Collection

- `bricks_template-f3-pricing-cards-tall-23814.json` includes `objectType: term`, `current_post_term: true`, taxonomy `filter-tag`.
- `bricks_template-flo-about-single-26168.json` includes plain term loops without `current_post_term`; those should remain outside the first writable slice unless owner semantics are proven.
- Native `post-taxonomy` elements with one explicit taxonomy are implemented in code but still need live browser QA.

## Recommended Next Implementation Item

### Primary recommendation: native post-term collection empty-loop support

Reason:

- It follows directly from the current `post_terms_collection` branch.
- It uses an existing dedicated resolver and mutation contract.
- It fills the same UX gap already solved for empty related-post query loops.
- It is narrower and safer than starting row insert/remove/reorder or broad archive collection writes.

Plan before coding:

1. Confirm non-empty post-term collection markers still surface and save.
2. Inspect how empty Bricks term loops render in the target templates.
3. Capture enough Bricks element/query metadata to prove:
   - source is native post terms, not an ACF taxonomy field
   - one concrete owner post exists
   - one taxonomy exists
   - the current assigned term set is empty or has no rendered loop output
4. Register a synthetic marker on the nearest safe visible parent/container, similar to empty linked-post query loops.
5. Reuse `PostTermsCollectionResolver` and `post_terms_collection` save contracts.
6. Keep ambiguous or multi-taxonomy empty loops inspect-only or unmarked.
7. Validate no-reload save and optional reload.
8. Update docs and QA notes.

Likely code touch points:

- `src/Bricks/ElementInstrumentationService.php`
- `src/Resolvers/ResolverRegistry.php`
- `src/Resolvers/PostTermsCollectionResolver.php`
- `src/Save/MutationContractService.php`
- `assets/js/overlay-app.js`
- `assets/css/overlay.css`
- `src/Assets/AssetLoader.php`
- `docs/enhancements/DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md`
- `docs/qa/TEST_LOG.md`

Acceptance:

- Empty native post-term loops can surface one actionable badge on a safe parent/container.
- Panel shows term collection source metadata with the owner post and taxonomy.
- Search can add terms.
- `Save` updates assigned terms without reload where rendered DOM can be patched or safely acknowledged.
- `Save and Reload` rebuilds Bricks output.
- Other taxonomies on the post are preserved.
- Ambiguous term loops remain locked or absent.

## Secondary Recommended Work

### 1. Close browser QA for current media no-reload saves

Test:

- Direct rendered image marker.
- Background-image marker if available.
- `xxrpfg` gallery on `/vertical/websites-for-contractors/`, cross-checking `bricks_template-flo-verticals-single-26763.json` around lines `38317-38323` when template structure is needed.

Verify:

- `Save` updates DOM immediately.
- `Save and Reload` still rebuilds Bricks markup.
- Missing/conditional image/galleries stay reload-only.

### 2. Close browser QA for native post taxonomy cards

Test:

- Non-empty `post_terms_collection` loops.
- Native `post-taxonomy` elements inside repeated post cards.

Verify:

- Every repeated card can surface its own badge.
- Panel opens against the correct owner post.
- Search/add/remove terms works.
- No-reload save updates state and does not affect other cards.

### 3. Live-save smoke grouped/nested descendants

Focus:

- Nested grouped ACF leaves inside supported repeater/flexible/related-owner paths.
- Confirm save writes the correct owner and row.
- Confirm same-source fields do not cross-sync incorrectly after save.

### 4. Archive follow-up

Only after the above:

- Validate current taxonomy archive term descendant saves.
- Validate archive option-backed shared fields with explicit warnings.
- Keep collection/galleries/non-concrete archive loop owners inspect-only.

## Deferred Work

Keep these deferred unless the user specifically requests them:

- Shared connected-item collections beyond configured Shared Globals and exact fallback branches.
- Loop-owned non-post connected-item collections.
- Taxonomy collection mutation for ACF taxonomy fields.
- Shared term collection mutation.
- Relationship collection mutation expansion beyond proven current/shared/loop-owned cases.
- Repeater row insert/remove/reorder.
- Flexible row insert/remove/reorder.
- Synthetic row/layout creation for missing containers.
- Non-empty condition-skipped image/gallery discovery without a rendered target.
- Generic static ID list writes for Bricks query loops with no source evidence.
- Treating archive pages as singular posts.

## Validation Baseline

Run the narrowest useful checks for touched files:

```bash
node --check addons/visual-editor/assets/js/overlay-app.js
php -l addons/visual-editor/src/Assets/AssetLoader.php
git diff --check -- addons/visual-editor
```

Add PHP lint for any touched PHP files, for example:

```bash
php -l addons/visual-editor/src/Bricks/ElementInstrumentationService.php
php -l addons/visual-editor/src/Resolvers/ResolverRegistry.php
php -l addons/visual-editor/src/Resolvers/PostTermsCollectionResolver.php
php -l addons/visual-editor/src/Save/MutationContractService.php
```

Use existing probes only when relevant and safe. Do not claim browser QA unless actually run.

## Reporting Standard For The Next Session

When finishing a turn, report:

1. What changed.
2. Files touched.
3. Validation performed.
4. Tradeoffs, blockers, or assumptions.
5. Next steps.

Keep source-owner and save-contract safety explicit. If ownership or path stability is uncertain, surface inspect-only or do not mark.
