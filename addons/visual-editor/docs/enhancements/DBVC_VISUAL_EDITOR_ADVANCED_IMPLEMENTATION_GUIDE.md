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
- exact single-tag text-like bindings
- exact single-tag link URL bindings
- direct safe loop-owned field editing for concrete queried post, term, and user owners

If a repeater, flexible layout, relationship collection, or complex query-loop node is not getting `data-dbvc-ve`, that is expected under the current implementation.

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
