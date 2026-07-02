# DBVC Visual Editor Advanced Implementation Guide

## Goal

Expand the Visual Editor beyond the current safe MVP without regressing source accuracy, ownership clarity, or rollback safety.

This phase is specifically about:
- Bricks query-loop content sourced from ACF relationship and related objects
- repeater and flexible-content subfield editing
- richer structured ACF fields and nested field paths
- clear non-current-post UI treatment
- durable backup and rollback for multi-step mutations

## Current Reality

Today the addon only marks narrow, render-verifiable cases:
- exact single-tag text-like bindings and one supported embedded dynamic tag inside a text-like setting
- exact single-tag link URL bindings
- direct safe loop-owned field editing for concrete queried post, term, and user owners

If a repeater, flexible layout, relationship collection, multi-token text setting, or complex query-loop node is not getting `data-dbvc-ve`, that is expected under the current implementation.

For the recent native ACF loop hardening patches and the code-level consolidation map that should turn them into true universal handling, see [../knowledge/NATIVE_ACF_LOOP_HARDENING_MAP.md](../knowledge/NATIVE_ACF_LOOP_HARDENING_MAP.md).

For the dedicated current-owner connected-items roadmap, see [DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md](./DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md).

For the runtime badge, hydration, and bounded viewport-prefetch roadmap, see [DBVC_VISUAL_EDITOR_BADGE_AND_HYDRATION_PLAN.md](./DBVC_VISUAL_EDITOR_BADGE_AND_HYDRATION_PLAN.md).

For the dedicated CPT archive and taxonomy archive context roadmap, see [DBVC_VISUAL_EDITOR_ARCHIVE_CONTEXT_PLAN.md](./DBVC_VISUAL_EDITOR_ARCHIVE_CONTEXT_PLAN.md).

## Design Decisions

### 1. Do not jump straight from unsupported to writable

Advanced sources should enter in two steps:
1. inspectable marker with honest non-editable status
2. editable only after a dedicated resolver + rollback-safe mutation path exists

This avoids repeating the original failure mode of “marker exists, but the owner/path is ambiguous”.

### 2. Keep ownership first-class

Every advanced descriptor must distinguish:
- page entity
- source owner entity
- loop owner entity
- field root
- field path
- render projection

For advanced cases, “what post am I editing” matters more than “what element was clicked”.

### 3. Keep render-time instrumentation as the primary source of truth

Do not add a selector-only or text-guessing fallback for repeaters/flexible/query loops.

Advanced support should still be driven by:
- Bricks element settings
- Bricks query runtime context
- server-side resolver classification

### 4. Introduce durable mutation journaling before multi-step writes

Transient-backed descriptor sessions are still correct for request-time markers and modal hydration.

They are not enough for:
- repeater row reindexing
- flexible layout path writes
- relationship collection reorder/replace actions
- multi-field structured writes that need rollback

### 5. Separate runtime UX optimization from durable write storage

Do not conflate frontend responsiveness work with persistence strategy.

Near-term runtime optimization should focus on:
- a shared hover/focus badge instead of one detached badge per marker
- lighter session bootstrap payloads
- on-demand descriptor hydration

Those changes do not require new DB tables.

### 6. Add bounded viewport-aware descriptor prefetch before any heavier caching

If the next runtime optimization step is “make other visible fields feel faster while the editor is open,” do that by extending the current lightweight runtime model, not by reintroducing eager hydration.

Current status:
- implemented at a bounded baseline level in the frontend runtime
- still subject to profiling/tuning rather than broader cache expansion

Recommended approach:
- keep the current public-map-only session bootstrap
- keep on-demand descriptor lookup as the only source of full field payloads
- reuse the existing in-memory descriptor cache and in-flight request reuse
- add a low-priority viewport-aware prefetch queue for visible uncached markers

Guardrails:
- no `hydrate=1` warmup for the whole page
- no persistent runtime token/descriptor cache table
- no broad hidden-container prefetch
- no competing request storm while a save or collection mutation is in progress

This should remain a runtime convenience layer, not a new source-of-truth path.

### 7. Keep editing interactions higher priority than background prefetch

Viewport prefetch is only worthwhile if it stays subordinate to explicit user actions.

That means:
- active-marker hover/focus/touch prefetch still has highest priority
- opening a field should always be able to reuse or supersede the same in-flight descriptor request
- background viewport work should pause or defer during save, reload-after-save states, and other expensive modal flows such as Media Library selection
- the browser should only prefetch a small bounded set of nearby markers at a time

## Recommended Storage Shape

### Keep as-is

- transient descriptor sessions for per-request marker lookup
- `DBVC_Database::log_activity()` for audit/events
- existing DBVC snapshot/history vocabulary where useful

### Add when advanced writes begin

Add dedicated Visual Editor journal tables instead of overloading the activity log:

#### `wp_dbvc_ve_change_sets`

One row per committed Visual Editor save batch.

Suggested fields:
- `id`
- `status`
- `scope_type`
- `page_entity_type`
- `page_entity_id`
- `owner_entity_type`
- `owner_entity_id`
- `descriptor_token`
- `snapshot_id` nullable
- `initiated_by`
- `created_at`
- `completed_at`

#### `wp_dbvc_ve_change_items`

One row per field/path mutation inside a change set.

Suggested fields:
- `id`
- `change_set_id`
- `resolver_name`
- `field_type`
- `field_name`
- `field_key`
- `field_path_json`
- `old_value_json`
- `new_value_json`
- `rollback_value_json`
- `result_status`
- `error_message`

### Table decision

Recommendation:
- do **not** add a session table now
- do **not** add a token-cache table now
- do add the change-set + change-item journal tables before enabling flexible-content writes, repeater row reordering, relationship collection mutation, or rollback-aware multi-step saves

Reason:
- marker sessions are ephemeral request state
- advanced field edits are operational write history and need durable rollback state

### Optional later optimization

If profiling later proves request-time instrumentation or descriptor classification is a true bottleneck, consider a separate optional materialized inventory cache.

That cache should be:
- rebuildable on demand from settings/admin tools
- keyed by page/template/signature hashes
- treated as an optimization hint, not source of truth
- invalidated aggressively when Bricks, ACF, or content inputs change

This is not part of the next runtime slice.

## Descriptor V2 Requirements

Advanced descriptors should add or formalize:
- `owner`
  - the actual entity being mutated
- `page`
  - the page where the marker is being shown
- `loop`
  - query element id, query object type, loop object type/id, stable loop signature
- `path`
  - nested subfield path such as repeater row, flexible layout key, nested group path
- `mutation`
  - `scalar`, `structured`, `collection`, `row`, `layout`
- `ui.badge`
  - `current`
  - `shared`
  - `related`
  - `derived`
  - `locked`

## UI Contract

### Shared active badge model

Recommended next-step UI model:
- keep marker outlines on all eligible nodes
- render one shared badge controller for the active marker only
- activate it on hover, focus, or explicit touch selection
- preserve current differentiated labels such as `Edit`, `Shared`, `Related Post`, `Related Term`, and `Inspect`

This gives most of the inline feel without forcing a real button into every marked subtree.

### Viewport-aware descriptor warmup

Recommended next-step runtime behavior:
- while the user is editing or inspecting one field, the browser may opportunistically prefetch full descriptors for other visible markers nearby
- that warmup should be driven by viewport visibility, not by hydrating every token at bootstrap
- the warmup queue should only touch markers that are already in the authenticated public descriptor map and are not already cached or in flight

Recommended priority order:
1. active marker selected by hover/focus/touch
2. currently visible editable markers
3. currently visible inspect-only markers
4. near-viewport markers inside a small root margin

Recommended mechanics:
- `IntersectionObserver` over rendered VE markers
- small root margin such as `200px` to `400px`
- bounded concurrency such as `1` to `2` descriptor requests
- idle-time pumping via `requestIdleCallback` with a timer fallback
- per-cycle queue cap so long pages do not backfill everything at once

Recommended pause conditions:
- save request in progress
- page reload pending after gallery or collection mutation
- session expired
- panel or Media Library flow is already under heavy interaction

This should make nearby markers open faster without materially changing the current save/session architecture.

### Non-current-post badge

Any item whose owner entity differs from the current page post should surface a visible badge in the overlay.

Initial labels:
- `Related Post`
- `Related Term`
- `Shared Option`
- `Derived`
- `Locked`

### Border and color rule

Do not replace the existing source/scope border differentiator.

The badge should reinforce the same visual system:
- current entity: keep existing teal outline/border treatment
- shared/global entity: keep existing amber outline/border treatment
- related non-current entity: add a distinct coral/red outline/border treatment
- derived/locked: use a muted/slate treatment

The same scope color should be reused across:
- marker outline
- badge border/background accent
- modal scope chip / notice accent

### Inspect-first advanced states

Before advanced saves are enabled, the modal should still show:
- owner entity
- field root
- nested path summary
- why it is currently locked
- what capability / resolver / path support is missing

## Resolver Expansion Order

### Phase A: inspect-only advanced sources

Add classifiers and modal inspection for:
- repeater subfields
- flexible content subfields
- nested group paths
- relationship collections
- non-current query-loop owners beyond the current narrow slice

No save yet. Marker + honest inspector only.

### Phase B: writable scalar descendants

Add writable paths for:
- repeater text-like subfields
- flexible text-like subfields
- repeater/flexible WYSIWYG subfields
- scalar descendants inside current-post and related-post loop owners

Current status:
- a narrow repeater-row descendant slice is now implemented for stable Bricks ACF repeater loops on current-post and related-post owners
- native Bricks ACF repeater loops have now been hardened against several real-world provider/runtime failure classes, including shortened parent aliases, duplicate child keys, nested group descendants, repeated-loop seed collapse, and fake related-owner classification from bare numeric row indices
- native Bricks ACF repeater-in-repeater descendants under a repeater root now carry canonical nested repeater row ancestry back to the outer stored repeater field, instead of attempting to treat the innermost repeater loop like a top-level editable root
- direct safe ACF fields on concrete queried post, term, and user loop owners are now writable through the explicit loop-owned contract layer
- direct flexible descendants with stable row + layout identity now surface with stable path metadata
- flexible text-like, WYSIWYG, choice, link, and image descendants are now writable through the flexible contract layer for current owners, loop-owned related owners, and shared term/user/option owners
- nested ACF group ancestry is now preserved through descriptor metadata, row traversal, and live sync identity so grouped descendants can be resumed from a stable contract baseline
- ordered gallery replacement is now enabled for direct Bricks gallery collections, including stable repeater and flexible row descendants, and the same safe flexible field set is now widened across shared post/term/user/option owners through the explicit `shared_flexible_layout` contract

### Phase B2: composite mixed dynamic text fields

Problem:
- Bricks text-like elements often combine multiple dynamic fields with static labels, separators, line breaks, and inline HTML.
- Example:

```json
{
  "id": "ullocp",
  "label": "Location Text",
  "name": "text-basic",
  "settings": {
    "text": "{acf_listing_location_label}<br>{acf_listing_address}<br>{acf_listing_city}, {acf_listing_region} {acf_listing_postal_code}"
  }
}
```

- The rendered element is one visible text block, but the editable sources are five separate ACF fields.
- Treating the whole rendered string as one editable value would require brittle string parsing on save and could write to the wrong source when values, separators, or formatting change.

Goal:
- surface one Visual Editor marker on the rendered Bricks element.
- open one panel that edits multiple proven child fields at once.
- keep each child field tied to its own resolver, owner, field path, mutation contract, acknowledgement state, and save result.
- reconstruct the visible text from the original Bricks template only after the child saves succeed.

Source shapes for the first slice:
- Bricks text-like settings whose saved value contains two or more dynamic tags plus optional static text/HTML.
- Supported first-pass element/settings:
  - `text-basic.settings.text`
  - `heading.settings.text`
  - button/link label text where the label is a text projection, not the URL contract
  - other text-like Bricks controls only after they already use the same direct text instrumentation path
- Supported dynamic tags:
  - ACF scalar/text-like fields that the existing resolver can already classify and save as a normal single-field descriptor
  - current-owner, related-owner, shared-owner, repeater-row, flexible-layout, and grouped descendants only when each child already has a proven existing save contract
  - inspect-only composites may include unsupported Bricks/provider tags as locked child rows when at least one child source resolves safely
- Supported static template pieces:
  - plain text
  - whitespace
  - punctuation
  - `<br>`
  - simple inline wrappers such as `<strong>`, `<em>`, `<span>`, and similar markup that can be preserved as static template text

Deferred source shapes:
- arbitrary user editing of the whole rendered string with automatic reverse-splitting into fields
- mixed dynamic text in attributes, CSS, custom classes, data attributes, query settings, or URL controls
- relationship/post_object/taxonomy/gallery/image fields embedded as text unless a dedicated scalar display/save projection is proven
- unknown providers such as `{echo:...}`, shortcodes, third-party dynamic tags, or Bricks computed tags inside a writable composite save
- composite text whose rendered output cannot be verified against the template in field order
- composite fields that require creating/removing repeater rows, flexible layouts, or relationship items
- multi-owner `Save All` without explicit grouped acknowledgement and rollback behavior

Recommended descriptor model:
1. Register a parent composite descriptor for the rendered element.
   - `render.context = composite_text`
   - `input = composite_text`
   - parent descriptor is a UI/container descriptor, not a direct mutation target
   - parent marker owns the one DOM badge and panel entry point
2. Parse the Bricks setting into ordered template segments.
   - literal segments preserve static text and static inline HTML
   - dynamic segments preserve the raw expression, occurrence index, setting key, and normalized render order
   - repeated references to the same field should share one child editor while all occurrences update in preview
3. Create or reference one child descriptor per unique editable source.
   - each child descriptor keeps the existing resolver classification, owner entity, field key/name, group/repeater/flexible path, mutation contract, and current display value
   - children should carry `composite_parent_token`, `composite_occurrences`, and `text_projection = composite_child`
   - unsupported child tags stay visible in the preview/source summary as locked segments and must not receive editable controls
4. Keep bootstrap light.
   - the public marker map should expose only the parent composite marker summary
   - full child descriptors should hydrate when the panel opens, reusing existing descriptor cache and in-flight request reuse
   - do not add one DOM marker per child field inside the same text node

Resolver and instrumentation changes:
1. Extend `DynamicDataInspector` with a template parser separate from `extractSingleEmbeddedExpression()`.
   - keep the existing single embedded path unchanged for the simple one-field case
   - return a readonly composite inspection when two or more dynamic expressions are present and at least one expression resolves to a safe field source
   - lock unsupported expressions instead of pretending they are editable
2. Extend `ElementInstrumentationService` to register a composite parent descriptor.
   - run each dynamic segment through the same classification path used by single-field text descriptors
   - preserve loop context, native ACF loop ancestry, grouped selectors, field keys, and source/sync groups per child
   - parent source group should be a composite hash of element UID, setting key, template hash, loop signature, and child source groups
3. Add render verification for composites.
   - project current child display values back through the saved template
   - compare normalized rendered text/HTML to the actual Bricks fragment in the same order
   - allow empty child values when the template and owner/path are proven, but do not make the composite writable if ordering or static separators cannot be verified
   - if verification fails, surface inspect-only with clear source evidence rather than editable controls

Panel and UI behavior:
1. Open one panel for the composite parent.
   - title from the Bricks element label, for example `Location Text`
   - show a compact preview of the reconstructed text
   - render one control per editable child field
   - group controls by owner when multiple owners are present
   - show locked/inspect-only rows for unsupported tags so the user understands why part of the text cannot be edited
2. Field controls should use existing input modes.
   - text, textarea, number, select, WYSIWYG, link, image, and collection controls should only appear when that child descriptor already supports the same input as a standalone marker
   - first implementation should favor scalar text-like fields and defer heavier structured controls unless the panel layout can handle them cleanly
3. Save affordances:
   - `Save All` is the primary action when all editable child fields pass preflight
   - optional per-field save can be added later, but should not be the first path if it creates confusing partial page states
   - shared/related/loop-owned children must reuse the same acknowledgement copy and warning icons already used by standalone fields
   - if children span different owners or scopes, the panel should require explicit acknowledgement per non-current owner group before saving

Save and rollback design:
1. Do not reverse-parse the rendered string.
   - save payload should send child token/value pairs, never the full rendered composite text as the source of truth
2. Add a batch preflight before any write.
   - validate session token, capabilities, nonce, child descriptor tokens, mutation contracts, owner/path freshness, and stale source state for every child
   - reject the batch before writing if any editable child fails validation
   - reuse the descriptor payload's `compositeText.saveReadiness` shape as the read-side preflight model: `readyChildCount`, `blockedChildCount`, `unsupportedChildCount`, `ownerGroups`, `requiresAcknowledgementTypes`, `blockers`, and `childContractsReady`
3. Use a single Visual Editor change set for a successful `Save All`.
   - one change set for the composite save
   - one change item per child field mutation
   - store the parent composite token and child token list in journal context
4. Rollback requirements:
   - preferred implementation: use the existing journal recorder and rollback values so a later child failure can restore earlier child writes
   - if rollback is not ready for a child contract, that child should be excluded from `Save All` and remain standalone/inspect-only
   - never leave the UI claiming the composite saved when only some child writes succeeded
5. Post-save DOM patching:
   - after all child saves succeed, reconstruct the element's inner content from the saved template and returned child display values
   - patch only the marked element for no-reload saves
   - fall back to `Save and Reload` when the composite contains WYSIWYG/block HTML, unsupported dynamic providers, conditionally rendered segments, or any child whose display projection cannot be safely rebuilt client-side

Similar scenarios to include in QA:
- address blocks with line breaks and punctuation
- price/rent strings such as `$ {acf_price} / month` or `{acf_price} {acf_price_period}`
- contact rows such as `{acf_phone} | {acf_email}`
- icon/list rows where a static label wraps one field and a second field follows
- repeated same-field references such as `{acf_city}, {acf_region} - serving {acf_city}`
- mixed empty/non-empty child values that leave static punctuation or blank lines
- composite text inside related post cards where all child owners are the loop-owned post
- composite text inside repeater/flexible rows where every child shares the same row/layout path
- composites that mix current-owner and related/shared fields; these should group by owner and require acknowledgement before any batch save
- one unsupported dynamic tag plus one or more supported ACF tags; first slice should inspect and lock the unsupported segment rather than silently ignoring it
- nested group selectors with mixed casing, preserving raw selectors just like `benefits_section_benefitsContent_related_items`

Validation targets:
- `frameworkflo-live` listing template example:
  - `{acf_listing_location_label}<br>{acf_listing_address}<br>{acf_listing_city}, {acf_listing_region} {acf_listing_postal_code}`
  - all five child ACF fields should resolve as current-owner children on listing post `107582`
- same-site single embedded regression fixtures:
  - `Bathrooms: {acf_listing_property_details_bathrooms}` should remain on the existing single embedded path, not be forced into composite mode
  - `<strong>Property type</strong><br>{acf_listing_property_details_property_type}` should remain supported as one embedded child
- `dbvc-codexchanges.local` text-heavy pages:
  - current-post scalar ACF text composites
  - related-post query-loop card composites
  - repeater/flexible row composites where stable row/layout metadata already exists

Implementation order:
1. Add parser-only tests for composite template segmentation.
2. Register inspect-only composite parent descriptors with child source summaries, no save.
3. Add panel rendering for composite preview and locked/editable child rows.
4. Enable editable children only when every child can reuse an existing scalar save contract and the batch preflight passes.
5. Add the batch save endpoint or service wrapper, backed by one journal change set and per-child change items.
6. Add guarded `Save All` controls only after disposable same-value and changed-value live-save QA proves the endpoint, journal, acknowledgement copy, and stale descriptor handling.
7. Add no-reload DOM patching for safe scalar composites; keep complex HTML projections locked until a dedicated projection contract exists.
8. Browser QA marker surfacing, panel hydration, `Save All`, partial-failure handling, no-reload patch, and session-refresh behavior.

Current implementation state:
- The first inspect-only slice is implemented for Bricks text-like settings with two or more dynamic expressions and at least one safely resolved child source.
- `DynamicDataInspector` now preserves ordered literal/dynamic template segments and child ACF candidates while leaving existing pure and single-embedded tag paths unchanged.
- Composite parent descriptors classify as readonly `native_readonly` / `composite_text`; the parent marker owns the one DOM badge and panel entry point.
- Child candidates are classified through the existing resolver path and exposed in the descriptor payload as source/value evidence. Unsupported Bricks/provider tags are preserved as locked child rows with their original expression.
- The descriptor payload now includes `compositeText.saveReadiness`, a preflight summary that reports child contract readiness, blocked/unsupported children, owner groups, acknowledgement types, and `canBatchSave`. `canBatchSave` is true only when every dynamic child maps to an embedded writable scalar descriptor using `text`, `textarea`, `number`, `url`, `email`, or single-select input; otherwise it reports `ready_pending_ui` or `blocked`.
- A guarded backend composite-save route is registered at `/visual-editor/session/{session_id}/composite-save/{token}`. It accepts child index/value pairs only, requires every dynamic child to map to an embedded writable scalar descriptor, rejects unsupported/readonly/structured child controls before mutation, and requires related/shared acknowledgement before any writes.
- `MutationService::mutateBatch()` and `ChangeJournalRecorder` now provide the server-side batch foundation: one parent change set, one child change item per successful field mutation, audit/cache invalidation per child, and reverse-order rollback attempts if a later child write fails.
- The panel renders reconstructed preview text, the original Bricks template, a partial-lock note when needed, a batch-save preflight block, and one source row per child descriptor/tag. When `canBatchSave` is true, save-ready scalar children render editable controls and the primary action becomes `Save All`.
- Frontend `saveComposite()` now sends child index/value pairs and child base values with the existing related/shared acknowledgement model, updates the cached composite payload and base values from the save response, and patches the active marker's inner HTML from the original template plus returned child display values without a reload.
- Composite save preflight now rejects stale child sources before any writes when a submitted base value no longer matches the backend value. The route returns `409 Conflict` with `stage = stale`, and the frontend preserves attempted values, clears the active descriptor cache, shows stale-state copy, and keeps `Save All` blocked until the field is reopened/refreshed.
- Direct ACF repeater-row child reads now use the expanded post-meta fallback when the resolved parent selector/name maps to stored row meta such as `_price_item_repeater`, including normalized Bricks selector variants. This keeps composite base values, stale checks, and writes aligned on the same stored row source instead of trusting an ACF-rendered/cache value.
- Live disposable save QA on `/our-process/` page `24732` confirmed same-value save, changed-value save, immediate restore, stale-conflict rejection/restore, and in-memory rollback attempts for a later child write failure.
- Live browser QA on `/our-process/` now confirms Review Fields token-based `Open`, related-owner acknowledgement gating, `Save All` no-reload DOM patching and restore, and tall composite panel viewport fitting. The field index also explicitly hides closed `<details>` item rows so closed-row actions do not overlap active summaries or intercept `Open` hit tests.
- Remaining follow-up work: browser-rendered stale-state UI still needs a harness that mutates the exact hydrated child source path behind an open panel; backend/runtime stale probes already cover the no-write behavior. A live journal failure-row readback is optional unless durable rollback evidence beyond the current controlled rollback probe is required.

Remaining live-browser hardening slice for composite `Save All`:
- Objective: finish browser-only stale UI confirmation without widening source support, mutation contracts, or parser behavior in the same slice.
- Primary fixtures:
  - `/our-process/` page `24732`, because the current probes already validate related-owner scalar composite descriptors, acknowledgement requirements, stale baseline protection, no-reload patching, and journal rows there.
  - A visible non-header composite marker should be preferred for click testing. If the only readily visible composite marker is clipped by the admin bar or header layering, use the Review Fields token-based `Open` path first, or add a temporary disposable visible fixture only for QA and remove it before commit.
  - `frameworkflo-live` listing post `107582` remains a secondary validation target for pure/single-embedded and future multi-tag listing composites; do not make it the first browser hardening fixture unless the patched plugin files are deployed there and the site is authenticated.
- Step 1, panel entry hardening:
  - Status: Review Fields token-based `Open` is verified for the `/our-process/` zero-size composite marker path.
  - Direct badge click remains useful to re-check when a visible non-header composite marker exists, but it is no longer required to unblock the token fallback path.
  - Ensure the `Open` action continues to avoid Bricks Builder mode and does not depend on a live node hit-test when a hydrated descriptor exists.
- Step 2, preflight UI verification:
  - Status: reconstructed preview text, original Bricks template, child source rows, owner context, scalar controls, and `Save All` visibility are verified on `/our-process/`.
  - Confirm blocked-child copy only when a live `canBatchSave = false` fixture is available.
  - Required related/shared acknowledgement gating is verified before save.
- Step 3, no-reload save behavior:
  - Status: same-value save, changed-value save, restore, URL stability, stable marker count, and active-marker patching without reload are verified on `/our-process/`.
  - Confirm the server response refreshes child base values so a second save from the still-open or reopened panel does not stale-conflict.
  - Confirm sibling markers or unrelated source-group matches are not cross-synced unless a later explicit composite sync contract is added.
- Step 4, stale/conflict behavior:
  - Reuse the current stale probes for backend coverage, including direct expanded-post-meta repeater rows whose Bricks selectors normalize away a leading underscore.
  - Confirm the browser panel renders a clear stale-state message when the route returns `409 / stage = stale`.
  - The panel should preserve the user's attempted values, explain that the backend source changed, and require refresh/reopen before another write.
- Step 5, journal and rollback evidence:
  - Keep the existing same-value/change/restore, stale, rollback, preflight, and journal probes as the baseline.
  - Only add live durable failure-row browser QA if a real browser path can safely force a controlled second-child failure without leaving content mutated; otherwise the current controlled rollback probe remains sufficient for this slice.
- Step 6, closeout docs and checklist:
  - Update `docs/qa/TEST_LOG.md` with the exact fixture, panel entry path, fields changed/restored, browser result, and any console errors.
  - Update `docs/qa/QA_CHECKLIST.md` for composite panel preflight, scalar child controls, acknowledgement gating, no-reload patching, and Review Fields token fallback once each item is confirmed.
  - Update this current-state block only after the browser path is validated or a concrete blocker is identified.

Guardrails for this slice:
- Do not add new writable composite child input types beyond scalar `text`, `textarea`, `number`, `url`, `email`, and single-select.
- Do not enable composite saves for WYSIWYG/block HTML, image/media/gallery, relationship/post_object/taxonomy, unknown providers, or unsupported dynamic tags.
- Do not reverse-parse the rendered string; every save must continue to submit child index/value pairs tied to hydrated child descriptors.
- Do not weaken stale baseline checks, related/shared acknowledgement gates, capability checks, nonce checks, owner/path freshness checks, or rollback attempts.
- Do not make hidden/offcanvas marker hit-testing broader as a shortcut for opening composites; use descriptor-token panel opening for non-clickable markers.

Acceptance for this slice:
- A composite marker can be opened from a real badge and from Review Fields `Open`.
- A `canBatchSave = true` composite shows scalar child controls and `Save All` only after required acknowledgement is satisfied.
- Same-value, changed-value, restore, and repeat-save flows work from the browser without a reload and without stale false positives.
- A stale backend source returns clear browser UI and performs no writes.
- No Visual Editor console errors are introduced, and Builder-mode guard still leaves composite marker count at zero under `?bricks=run`.

Docs to update during implementation:
- `CHANGELOG.md`
- `docs/qa/TEST_LOG.md`
- `docs/qa/QA_CHECKLIST.md`
- this guide's current-state notes once each sub-slice moves from planned to implemented

### Planned tranche: missing or conditional Bricks image/media/gallery markers

Problem:
- Bricks image/background elements only receive Visual Editor media badges today when the element renders and exposes a direct image source/background attribute.
- Bricks image-gallery elements only receive Visual Editor gallery badges when the gallery element renders and exposes the direct gallery marker.
- Some templates intentionally conditionally hide or skip the image element when the source field is empty, especially inside Bricks query-loop cards, repeater rows, and flexible-content rows.
- Gallery sections can follow the same pattern when an ACF gallery field is empty and the Bricks image-gallery element is conditionally hidden.
- In those cases the editable source can be valid and safely writable, but there is no rendered `<figure>`, `<img>`, gallery wrapper, or background wrapper to carry `data-dbvc-ve`.

Goal:
- surface an actionable media/gallery badge on the nearest safe visible parent/container when a proven Bricks image or image-gallery element is missing because the underlying source is empty or the element condition prevented render output.
- reuse the existing `media_reference` panel and ACF image / featured-image save contracts for single images.
- reuse the existing `media_gallery_reference` panel and ACF gallery save contract for Bricks image-gallery elements backed by direct ACF gallery fields.
- keep source ownership/path strict for current owners, loop-owned posts, repeater rows, and flexible layouts.

Source shapes for the first slice:
- Bricks image element with `settings.image.useDynamicData` mapped to a supported direct media source:
  - ACF `image` field
  - post `featured_image`
- render context expected to be `image_src`
- Bricks image-gallery element with `settings.items.useDynamicData` mapped to a direct ACF `gallery` field.
- render context expected to be `gallery_collection`
- owner/path already resolvable through the existing resolver stack:
  - current post/page/CPT
  - concrete loop-owned post
  - stable repeater row
  - stable flexible layout
  - nested group descendants where source/path metadata is already proven

Deferred source shapes:
- gallery sources that are not direct ACF gallery fields
- non-empty galleries hidden by unrelated Bricks conditions
- ambiguous image projections that are not direct image source bindings
- relationship/post_object/taxonomy selector fields rendered as image projections
- shared option fallback media unless the owner contract is explicit and acknowledged
- arbitrary CSS/background conditions without a proven Bricks image/background dynamic-data setting
- creating repeater/flexible rows or changing row/layout lifecycle to make space for the image

Recommended implementation design:
1. Capture missing-media candidates during normal element instrumentation:
   - when `DynamicDataInspector::inspectImageSettings()` classifies a Bricks image/background source, keep a lightweight candidate record keyed by element ID, setting key, source expression, expected render context, owner/path seed, and loop signature.
   - do not register synthetic descriptors for unsupported or ambiguous image projections.
2. Register a synthetic descriptor only after resolver classification succeeds:
   - status can be editable only if the existing resolver/save contract would already be writable for the same image source if rendered normally.
   - render metadata should include `context = image_src`, `background_image`, or `gallery_collection`, plus a marker hint such as `missing_media_anchor = true`.
   - skip normal rendered-value verification because the element is absent, but require resolver value to be empty or missing before surfacing the missing-media marker.
3. Inject a hidden marker if final HTML lacks the descriptor token:
   - first try the exact Bricks image element occurrence by `brxe-{element_id}` when a wrapper exists but the inner image does not.
   - if the element is fully absent because of a Bricks condition, anchor after a safe nearby structural marker:
     - nearest rendered ancestor/container recorded from element metadata, if present in final HTML
     - loop item root or row wrapper when loop context is active
     - as a last safe fallback, a hidden marker appended inside the nearest parent Bricks container, never `body`
   - mark the synthetic node with a dedicated data flag such as `data-dbvc-ve-missing-media="1"` so CSS/JS can position a container badge without making a fake visible image.
4. Frontend badge behavior:
   - mount a container-style badge labelled from the media source, for example `Add Image`, `Add Featured Image`, or the ACF field label.
   - gallery markers should label as `Add Gallery` or `Add {ACF gallery label}`.
   - keep the dashed outline on the parent/container using a distinct empty-media style, not the text empty-field pulse.
   - opening the panel should use the existing single-media or ordered-gallery Media Library flow based on descriptor input type.
5. Save behavior:
   - saving without reload can update descriptor state, but cannot reliably render an absent Bricks element that was removed by a server-side condition.
   - default primary action should be `Save and Reload` for missing-media markers unless the marker was attached to an existing image wrapper that can be patched in place.
   - after reload, Bricks should render the image or gallery element normally and the marker should become a regular `image_src` / `gallery_collection` marker.

Safety requirements:
- never infer an image source from DOM proximity alone.
- never surface a writable missing-media marker unless the Bricks element settings and resolver prove the exact source field/owner/path.
- do not allow stale row/layout descriptors to save if the repeater/flexible row no longer exists.
- do not place multiple synthetic missing-media badges for the same owner/source/row when Bricks repeats the same element ID across cards; include owner/path/loop signature in the synthetic seed.
- conditionally hidden elements that are not empty-source cases should stay inspect-only or hidden until the condition source itself is editable through a separate contract.

Current implementation state:
- First guarded runtime slice is implemented for empty direct `image_src` / `background_image` descriptors backed by an existing ACF image or post featured-image save contract.
- The same guarded fallback now includes empty direct `gallery_collection` descriptors backed by an existing ACF gallery save contract.
- Populated direct Bricks `image-gallery` render verification now compares rendered attachment IDs from Bricks gallery markup (`data-id` first, `wp-image-*` fallback) against resolved ACF gallery attachment IDs instead of comparing text content such as `5 images`.
- Bricks can apply the gallery root attributes to each gallery item; Visual Editor now keeps the first gallery collection marker on the gallery wrapper and strips duplicate item-level marker attributes for the same token.
- The gallery panel now manages the full ordered gallery collection: `Add images` appends new Media Library selections while preserving existing IDs, `Replace gallery` intentionally overwrites the collection, individual thumbnails can be removed, thumbnail controls can move images earlier/later, and desktop drag-and-drop can reorder thumbnails before the ordered ID list is saved.
- Rendered media markers now expose both `Save` and `Save and Reload`: no-reload saves patch `img` `src/srcset/sizes/alt`, background-image style values, or a lightweight live gallery thumbnail list in the existing wrapper; reload saves remain available when Bricks needs to rebuild full gallery/lightbox markup.
- When Bricks emits no image markup, the descriptor is retained only if the backend media value resolves empty; non-empty hidden/conditional image values remain unsurfaced rather than becoming writable from proximity.
- When Bricks emits no gallery markup, the descriptor is retained only if the backend gallery value resolves to an empty list; non-empty hidden/conditional gallery values remain unsurfaced rather than becoming writable from proximity.
- The marker first anchors to the configured Bricks parent element by matching the final DOM class `brxe-{parent_element_id}` while descriptors continue to store unprefixed Bricks IDs.
- If the immediate wrapper is absent or already owns another Visual Editor marker, the marker walks known Bricks ancestor metadata and chooses the nearest rendered unclaimed ancestor. This supports conditional wrapper omission without overwriting a different editable descriptor.
- The frontend uses `data-dbvc-ve-missing-media="1"` plus `data-dbvc-ve-missing-media-kind` to show the proper media/gallery badge and forces reload after save so Bricks can render the missing image or gallery element normally.
- Writable gallery descriptors now use `media_gallery_reference`; readonly gallery descriptors use `media_gallery_preview`.
- Still deferred: conditional image/gallery elements whose source already has a value, ancestor recovery when Bricks exposes no parent-chain metadata at all, and synthetic row/layout creation for missing containers.

### Planned tranche: no-reload media clear/removal DOM patching

Goal:
- when a user clears a rendered media field and clicks no-reload `Save`, make the visible page reflect that empty saved value immediately instead of leaving a broken or stale image/video shell until reload.
- preserve the existing working Bricks `image-gallery` behavior: selected images, removed gallery items, and drag/drop ordering must continue to live-update through `patchGalleryCollectionNode()` and must not be converted to the single-media removal path.

Source shapes in scope:
- rendered `image_src` descriptors backed by the existing ACF image or featured-image contracts.
- rendered `background_image` descriptors backed by the existing ACF image or featured-image contracts.
- future rendered video/media descriptors only after a dedicated resolver/render context proves the owner, media field, and mutation contract.

Out of scope:
- Bricks `image-gallery` collection replacement/reordering. Existing `gallery_collection` live patching stays separate and must remain the only code path for gallery clear/remove/reorder.
- missing-media markers with `data-dbvc-ve-missing-media="1"`; these still require reload because there is no stable rendered media target to remove or rebuild.
- condition-skipped media whose backend source is non-empty.
- deleting the marked Visual Editor target node itself when doing so would remove the descriptor token and break the open panel/session.
- guessing parent containers from visual proximity.

Implementation rules:
1. Add a narrow frontend helper, separate from `patchGalleryCollectionNode()`, for cleared single-media render contexts.
   - It should run only when `context` is `image_src` or `background_image`.
   - It should trigger only when the saved value resolves to an empty attachment ID and empty render URL.
   - It must not run for `gallery_collection`.
2. For `image_src`:
   - find the actual rendered media child, preferring `img`, then `picture`, then a future explicit video selector only when a video descriptor exists.
   - remove or hide the media child, not the marked wrapper, when the wrapper carries `data-dbvc-ve`.
   - if the marked node itself is the `<img>`, preserve it with a Visual Editor clear-state class and remove `src/srcset/sizes/alt` rather than removing the node from the DOM.
   - keep `data-dbvc-ve`, source group, sync group, and display-value metadata on a connected node so badges and the active descriptor remain stable after save.
3. For `background_image`:
   - clear `style.backgroundImage`.
   - add a clear-state class on the marked node so the visible badge/outline can still anchor to an element with dimensions.
   - do not remove the background wrapper because it often carries layout, overlay, or content children.
4. For non-empty single-media saves:
   - keep the current no-reload patch behavior: update `src/srcset/sizes/alt` for images and `background-image` for background markers.
   - if a previous clear-state class exists, remove it once a non-empty media value is saved.
5. For gallery saves:
   - keep the current `gallery_collection` branch untouched.
   - `Clear gallery` should continue to render an empty gallery wrapper through `patchGalleryCollectionNode()` by emptying/rebuilding the wrapper children from the saved ordered list.
   - `Add images`, `Replace gallery`, thumbnail remove, move earlier/later, and desktop drag/drop sorting must keep using the existing gallery item normalization and ordered attachment-ID save payload.
6. Save UX:
   - no-reload `Save` can patch rendered single-media clear states immediately and close the panel.
   - `Save and Reload` remains available for exact Bricks rebuild behavior, conditional wrappers, lightboxes, picture/source markup, and any theme/Bricks script that needs server-rendered markup.
   - if a cleared rendered media field is inside a query/repeater/flexible context, the DOM patch must only touch nodes in the same descriptor sync/source group.

Current implementation state:
- Implemented for rendered `image_src` and `background_image` descriptors only.
- Clearing a rendered image removes the child `img`/`picture` when the Visual Editor marker is on a wrapper; if the media node itself carries the marker, the node stays connected and its media attributes are cleared so the descriptor target remains stable.
- Clearing a rendered background image clears `style.backgroundImage` and applies the same clear-state marker class to keep the badge anchor visible.
- Re-adding a single image without reload can recreate a lightweight live `<img>` inside the existing wrapper; `Save and Reload` remains the path for exact Bricks picture/lightbox markup.
- The `gallery_collection` branch is intentionally unchanged and still uses `patchGalleryCollectionNode()` for add/replace/remove/reorder/clear gallery behavior.

Validation before implementation is complete:
- direct rendered ACF image: select replacement, no-reload save; then clear image, no-reload save; then add image again without reload.
- rendered featured image: same replace/clear/add loop.
- background image: replace, clear, and add back without reload while preserving wrapper dimensions.
- populated Bricks `image-gallery` element `xxrpfg` on `/vertical/websites-for-contractors/`: user QA on 2026-06-13 confirmed add, replace, remove item, move/reorder, and no-reload `Save` still work as before after the single-media clear patch. Re-check `Save and Reload` only if gallery-specific code changes again.
- missing-media marker: confirm clear/removal helper does not run and reload remains required.
- repeated/query-loop cards: confirm clearing one card image does not hide another card image that shares the same Bricks element ID but has a different owner/source group.

Validation targets:
- current post ACF image field that renders no image when empty
- query-loop card post with missing featured image
- native repeater row image subfield where one row has no image
- flexible-content image subfield inside a row/layout that conditionally hides the Bricks image element
- background image dynamic-data source with an empty ACF image field
- Bricks image-gallery dynamic-data source with an empty direct ACF gallery field
- populated Bricks image-gallery source to verify ordered Media Library replacement and reload-after-save on live markup

Concrete gallery fixtures:
- Populated gallery: `https://dbvc-codexchanges.local/vertical/websites-for-contractors/` uses `FLO-Verticals-Single` template `26763`, Bricks image-gallery element `xxrpfg`, and ACF gallery field `gallery_section_gallery` with five stored attachment IDs.
- Empty/condition-skipped gallery gap: `https://dbvc-codexchanges.local/vertical/dentists/` uses the same template and field with an empty value; current render probes show Bricks emits no `xxrpfg` markup and no Visual Editor marker because the element is skipped before the current render hooks can register a descriptor.

Current validation status:
- `xxrpfg` gallery regression check is closed by user QA on 2026-06-13 for add/replace/remove/reorder/no-reload save.
- Read-only render probes on 2026-06-13 confirmed server-side marker coverage for rendered `image_src` paths on `/vertical/websites-for-contractors/` and `/our-process/`, and one concrete `background_image` marker on `/service-areas/ohio/akron/` from template `120111`, element `117a72`, term `437`, field `vf_service_area_hero_image`.
- Direct rendered ACF image, featured image, and background-image replace/clear/add-back browser checks remain open because the in-app browser session was not authenticated and could not load Visual Editor assets or markers.
- Missing-media markers remain reload-only by design; confirm the single-media clear helper stays limited to rendered `image_src` and `background_image` markers.

### Immediate next implementation order

Do not start with broad flexible-content query-loop writes.

Recommended next sequence:
1. formalize Descriptor V2 owner/page/loop/path/mutation metadata
2. add durable Visual Editor change-set journaling
3. introduce dedicated save-contract metadata for loop-owned sources
4. keep native Bricks ACF loop provenance explicit and smoke the remaining relationship/post-object/taxonomy cases on real pages
   - include parent native loop ancestry for nested paths like `relationship -> repeater` so descriptors, signatures, and save-contract summaries do not collapse everything down to the inner loop alone
5. expand inspect-only flexible/query-loop coverage where ownership is stable
6. enable writable flexible scalar descendants only after the above are in place

### Immediate runtime optimization order

Treat viewport-aware prefetch as a separate bounded UX slice, not part of resolver expansion.

Recommended sequence:
1. keep current public-map bootstrap and active-marker dwell prefetch unchanged
2. add `IntersectionObserver`-driven visible-marker collection
3. add a bounded low-priority descriptor prefetch queue that reuses current cache and in-flight request logic
4. pause that queue during save, reload, and heavy modal flows
5. profile marker counts, descriptor request counts, and modal-open latency before considering any larger cache design

For the concrete scenario matrix and later mutation roadmap, use:
- [DBVC_VISUAL_EDITOR_NATIVE_LOOP_EXPANSION_PLAN.md](./DBVC_VISUAL_EDITOR_NATIVE_LOOP_EXPANSION_PLAN.md)
- [DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md](./DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md)
- [DBVC_VISUAL_EDITOR_ARCHIVE_CONTEXT_PLAN.md](./DBVC_VISUAL_EDITOR_ARCHIVE_CONTEXT_PLAN.md)

Reason:
- repeater row writes already proved the narrow nested-path pattern
- the native repeater slice has now also proved the failure-class hardening path for real Bricks provider/runtime drift
- flexible-content rows and broader loop-owned saves are the next nested-path consumers
- they should inherit a real journal + contract layer instead of forcing more special cases through the current direct-field mutation path

### Phase C: structured descendants

Add writable paths for:
- repeater link fields
- repeater checkbox/select/radio/button group fields
- flexible structured descendants

### Phase D: relationship collection controls

Current status:
- the narrow first slice is now moving into direct current-owner Bricks native ACF query roots for `relationship` and `post_object` fields
- that slice uses a dedicated query-root descriptor family, `Edit Connected` badge treatment, and reload-after-save reconciliation instead of pretending collection mutation is just another scalar descendant write
- that implementation is now widened in code to direct current-owner repeater-row and flexible-row roots when the active row path is stable, to mixed current-owner `repeater -> flexible` / `flexible -> repeater` collection roots when the nested row chain can be reduced to canonical container ancestry, to grouped current-owner row-owned collection roots when the intermediate group ancestry can be proven from the native query path, and to loop-owned related-post collection roots with dedicated collection contracts and acknowledgement flow; broader shared owners and loop-owned non-post collection roots remain deferred
- the derived Bricks query tranche now has its first writable current-owner filtered-subset slice: final `bricks/posts/query_vars` are captured, `post__in` plus a single target `post_type` are matched against exactly one current-owner ACF relationship/post_object field, and the `relationship_collection_filtered_subset` / `post_object_collection_filtered_subset` contract replaces only that target CPT subset while preserving non-target IDs.
- Bricks native dynamic include/post__in controls are included in that tranche only when the saved control exposes ACF dynamic-tag evidence such as `{acf_page_related_items}`; that evidence is preserved from the saved setting even when the final resolved IDs come from `bricks/posts/query_vars`, while static/manual include lists and opaque native final-ID lists remain unsupported because there is no editable source field to mutate safely.
- simple Query Editor loops that return `post__in => get_field('page_related_items')` now contribute current-owner ACF source hints, but only direct `get_field('field')` calls are accepted; option/user/explicit-object reads remain excluded from writable hints.
- mixed/`any` derived post queries can now use the full `relationship_collection` / `post_object_collection` contract only when source evidence exists and the final ordered query IDs exactly equal one current-owner field's full stored value.
- custom Query Editor fallback branches now have a governed evidence path: exact options-field fallback matches are labelled as shared-option query collections, exact target-CPT and exact full-field option matches can use shared collection save contracts with acknowledgement, exact branches with one empty hinted current-owner field can expose an explicit seed-current-field action, and unmatched Query Editor `post__in` branches remain locked query evidence.
- locked fallback branches use a read-only connected-items preview in the panel, grouping queried items by object type and naming the active branch without mounting search or mutation controls.
- current-owner derived Query Editor collection matching now walks nested ACF group sub-fields for relationship/post_object leaves and preserves a case-sensitive `field_selector_raw` for flattened grouped selectors such as `benefits_section_benefitsContent_related_items`; this keeps the save contract on the proven current-owner ACF field rather than falling back to selector/text guessing.
- exact shared-option fallback collection matching and the explicit seed-current-field action now use the same nested-group candidate model, so option-backed and seed-target grouped collections require the same source-owner, raw selector, group path, and stored-ID proof before a mutation contract is exposed.
- Visual Editor source summaries now surface `selector:{field_selector_raw}` when that trusted grouped selector differs from the normalized field name, making grouped collection contracts auditable from the panel/status details without adding new frontend state.
- empty current-owner derived post loops are now treated as a first-class collection source only when Bricks exposes explicit ACF source evidence and a concrete target post type; this includes non-empty raw `post__in` lists whose IDs are all outside the proven target post type. The query-vars hook can register a synthetic descriptor when no loop element is rendered, and the server injects a hidden marker after the `brx-loop-start-*` comment or Bricks query-trail placeholder so the existing container badge and filtered-subset save contract can add the first connected item without guessing from missing DOM children.

Near-term order:
1. current-owner native `relationship` query roots
2. current-owner native `post_object` query roots
3. current-owner repeater/flexible row-owned relationship/post-object collections
4. browser smoke and hardening for derived Bricks query filtered-subset saves, including native dynamic include/post__in controls
5. browser smoke and hardening for inspect-only branch evidence on custom Query Editor fallbacks
6. shared and loop-owned collection roots

Still later:
- append/remove/reorder from broader owner contexts
- taxonomy collection mutation
- custom query-editor collection source writes without active-branch proof, source-owner proof, and explicit shared/current save contracts

## Bricks Query Loop Strategy

### Safe first loops

Support first:
- Bricks ACF `relationship` / `post_object` post loops
- explicit current row owner from Bricks runtime
- text/link/render projections that are still exact and verifiable

### Delayed loop types

Delay until later:
- generic non-ACF post queries
- taxonomy/user loops with nested flexible/repeater mutation
- builder-generated derived values without stable field ownership

### Archive entry points

Post type archives and taxonomy archives are a separate context tranche, not just another query-loop case.

Use the archive plan before enabling runtime support:
- expand page context first: implemented for supported CPT and taxonomy archive entry points
- surface archive markers inspect-only first: implemented for render-verified ACF/post-field candidates
- enable taxonomy archive direct ACF term fields: initial queried-term slice implemented
- enable archive direct option-backed ACF fields with shared-option acknowledgement and options-page field-group discovery: initial slice implemented for CPT and taxonomy archives
- enable native taxonomy `{term_name}` and `{term_description}` fields through a dedicated term resolver: queried archive terms and concrete Bricks term-loop owners now supported
- enable archive query-loop term/post descendants only through explicit loop-owner contracts: initial concrete-owner slice implemented
- leave native archive tags such as `{archive_title}`, `{post_url}`, `{term_url}`, `{term_id}`, and broad `{term_meta:*}` writes inspect-only until dedicated mutation contracts exist; the first four now surface through a readonly resolver where they can be resolved safely

## Validation Gates Before Advanced Writes

Require all of the following before enabling save:
- stable owner entity resolution
- stable nested field path
- resolver-specific validation
- pre-write snapshot or rollback journal entry
- exact render projection match
- explicit badge/acknowledgement for non-current owners

## Recommended Next Execution Order

1. Refactor the runtime UI to a shared hover/focus badge plus active-marker state manager.
2. Change initial session bootstrap to public-map-only by default and move full descriptor hydration to on-demand fetch.
3. Preserve and formalize non-current-owner badge labels and outline colors across current/shared/related/inspect states.
4. Add inspect-only advanced markers for unsupported repeater/flexible/query-loop items where they are still missing.
5. Formalize descriptor V2 shape for owner/page/path/loop metadata.
6. Add journal tables and DBVC schema migration hooks for advanced write history before flexible rows, repeater row reordering, relationship collection mutation, or rollback-aware multi-step saves.
7. Expand repeater/flexible structured descendants.
8. Add the archive context tranche in inspect-first order if archive page editing is prioritized over broader collection mutation.
9. Add relationship collection mutation UI last.

## Current Pause Note

Advanced grouped/flexible follow-up is intentionally paused after the recent contract work and live marker verification.

Resume from here:
0. current active slice before resuming the grouped-save smoke:
   - widen from the hardened native repeater slice into native `relationship -> repeater` and `relationship -> flexible` descendants first
   - then widen to native `post_object -> repeater` and `post_object -> flexible` descendants
   - keep native loop provenance and parent native ancestry first-class throughout descriptor/source/path/mutation summaries
   - native taxonomy nested descendants are limited to guarded current archive term or concrete loop-owned term writes where the row/layout path is already proven; shared term collections and row/layout lifecycle mutation remain deferred
   - leave relationship collection editing and repeater/flexible row insert-remove-reorder in the later collection-mutation branch
1. run live save smoke tests for nested grouped descendants inside supported repeater/flexible/related-owner paths
   - direct grouped ACF leaves now preserve parent group ancestry and prefer selector-based writes, so the next verification target is live save behavior rather than descriptor discovery
2. verify grouped descendants do not cross-sync after save on real pages
3. only then widen any remaining collection-safe structured paths
