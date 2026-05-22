# DBVC Visual Editor Collection Editor Plan

## Goal

Add a governed collection editor to the Visual Editor for ACF connected-object fields without collapsing scalar descendant editing, loop-owner editing, and collection mutation into one unsafe branch.

Initial user-facing target:
- Bricks native ACF query containers driven by a current-owner ACF `relationship` field
- Bricks native ACF query containers driven by a current-owner ACF `post_object` field

The first UI mode is a container/root editor, not a descendant field editor:
- badge label: `Edit Connected`
- panel mode: ordered connected-items picker
- save behavior: reload after save so Bricks can rebuild the loop DOM from the updated collection

## Why This Is Separate

Relationship and object collections are not just another scalar field projection.

They require:
- ordered ID list mutation
- target-type validation
- whole-field snapshotting before write
- reload-based reconciliation after save

This makes them a different mutation family from:
- text-like direct fields
- repeater/flexible scalar descendants
- image/gallery media projections

## Narrow First Slice

### Supported in the first writable slice

- current-page/current-post owner only
- direct ACF `relationship` query roots
- direct ACF `post_object` query roots
- single-select and multi-select post-object fields
- ordered add/remove/reorder of connected posts

### Explicitly deferred from the first slice

- loop-owned related owners
- shared owners
- taxonomy collections
- custom Bricks `queryEditor` sources
- live in-place DOM patching after save

Custom Bricks Query Editor sources are now planned as a separate derived-query tranche, not as part of the native ACF query-root slice. See [Derived Bricks Query Loop Linked-Posts Editor](#derived-bricks-query-loop-linked-posts-editor).

## Descriptor Contract

Introduce a dedicated descriptor family for collection-editable query roots:

- `source.type = acf_collection_field`
- `render.context = query_collection`
- resolver name: `acf_reference_collection`
- mutation kind: `collection`
- contract names:
  - `relationship_collection`
  - `post_object_collection`

Required source metadata:
- `field_name`
- `field_key`
- `field_selector`
- `field_type`
- `reference_post_types`
- `reference_multiple`
- `reference_min`
- `reference_max`
- native query selector/object type metadata

## UI Contract

### Badge

Current-owner collection roots should use:
- badge text: `Edit Connected`
- current-owner border color treatment
- frontend placement: one overlay badge anchored to the resolved query-loop parent/items container, not one hover badge per repeated query item

This badge is only valid when VE can prove:
- the loop root maps back to one direct current-owner ACF relationship/post-object field
- the root is not a nested row-owned collection
- the owner is the current page/post

### Panel mode

The collection editor panel should show:
- current connected items in stored order
- remove action
- move up/down actions
- search input
- search results list
- add/replace action depending on single vs multi select

Save should:
- validate selections
- write the full final ordered ID list in one mutation
- reload the page after success

## Runtime Phases

### Phase 1. Query-root descriptor groundwork

- detect current-owner native ACF relationship/post-object query roots
- instrument the query container/root with a dedicated collection descriptor
- add dedicated resolver + mutation contract
- add panel mode for current ordered items
- save via reload-based reconciliation

### Phase 2. Search and selection UX hardening

- debounce search
- clearer empty states
- search result filtering against allowed post types
- max/min enforcement in UI
- better success/result summaries

### Phase 3. Nested current-owner collection roots

- current-owner repeater-row relationship/post-object collections
- current-owner flexible-row relationship/post-object collections

This phase is now implemented in code for current-owner row roots where:
- the active native loop path is stable
- the nested container ancestry can be reduced to a canonical repeater/flexible row chain
- the collection root can be tied back to one direct ACF `relationship` or `post_object` field

That now includes:
- direct repeater-row roots
- direct flexible-row roots
- mixed `repeater -> flexible` collection roots
- mixed `flexible -> repeater` collection roots

Still deferred inside this phase:
- broader shared/loop-owned collection roots
- grouped row-owned collection roots whose group ancestry still cannot be proven canonically from the native query path

### Phase 4. Shared and loop-owned collection roots

- loop-owned related post owners for native ACF `relationship` / `post_object` query roots
- shared post owners
- shared term/user/option owners where meaningful

The loop-owned related-post branch is now implemented in code for concrete related post owners.

Still deferred inside this phase:
- shared post owners
- shared term/user/option owners
- loop-owned non-post owners such as term/user/option roots
- broader custom query-editor collection roots

### Phase 5. True collection mutation expansion

- relationship append/remove/reorder from more complex source contexts
- taxonomy multi collections
- custom query-editor collection roots if they can be tied to one canonical field

### Phase 6. Derived Bricks query linked-post collections

This phase covers frontend query loops that are not native Bricks ACF relationship/post-object query roots, but are derived from a current post's ACF relationship/post_object field.

Example:
- current page field: `page_related_items`
- stored value: mixed post IDs across multiple post types
- Bricks query loop: custom Query Editor PHP filters `page_related_items` to `service` posts and renders service cards
- desired editor: container-level `Modify Linked Posts` badge that edits only the `service` subset stored in `page_related_items`

This is not safe to infer from DOM cards alone. It requires either:
- exact runtime proof that the query result is an ordered subset of one current-owner ACF field, or
- an explicit loop-to-source mapping chosen by an authorized user/admin.

### Phase 6A. Native Bricks include/post__in controls

This phase covers Bricks' native post-query controls that populate `post__in` / include-style arguments without using a custom Query Editor PHP block.

Supported in the current slice:
- saved Bricks query control includes a dynamic ACF tag such as `{acf_page_related_items}`
- final Bricks `bricks/posts/query_vars` exposes the resolved ordered IDs, or the saved native control can be resolved through `bricks_render_dynamic_data()`
- the dynamic tag maps to one current-owner direct ACF `relationship` / `post_object` field
- the final target post type is concrete or can be inferred from a single post type in the ordered ID list
- the ordered IDs exactly match that field's filtered target-post-type subset
- mixed/`any` queries are writable only when source evidence exists and the ordered IDs exactly match the full current-owner field value; those use the full `relationship_collection` / `post_object_collection` contract instead of the filtered-subset contract
- mixed post-type Query Editor loops can use direct current-owner `get_field()` hints to reach that same full-collection proof path; unmatched fallback branches remain inspect-only
- native dynamic include/post__in controls preserve their saved `{acf_*}` tag evidence even if the saved setting cannot be rendered during summary capture, provided the final `bricks/posts/query_vars` exposes the resolved ordered IDs
- native controls with only final query IDs but no saved ACF dynamic-tag evidence remain unsupported, even if the IDs happen to match a current-owner field

Not writable in this slice:
- static/manual Bricks `post__in` IDs because they do not identify an editable source field
- opaque/native final-ID lists where the saved Bricks setting no longer exposes the ACF dynamic tag that produced the IDs
- native include controls that use non-ACF dynamic tags
- mixed-post-type result sets that do not exactly match the full current-owner field value
- mixed-post-type result sets with no ACF dynamic tag or Query Editor source hint
- paginated, limited, or sorted subsets that do not represent the full source subset

Descriptor/source metadata added by this phase:
- `query_id_source`
- `query_id_setting_source`
- `query_id_setting_key`
- `query_dynamic_tags`
- `query_dynamic_field_hints`
- `query_result_post_types`
- `query_collection_write_mode`
- `query_editor_active`
- `query_editor_field_hints`
- `query_editor_option_field_hints`
- `query_editor_explicit_field_hints`
- `query_branch_state`

Guardrail:
- if a native include/post__in control is static, the loop may render normally, but Visual Editor must not offer a collection editor because saving an ACF field would not change the static Bricks query.

### Phase 7. Custom Query Editor fallback-source handling

This phase covers Bricks Query Editor PHP blocks that select from multiple possible sources, such as:
- current post relationship/post_object field
- ACF options relationship/post_object field fallback
- recent posts fallback
- taxonomy-filtered or meta-filtered result sets

Example source shape:
- current field: `page_related_items`
- options fallback: `settings_globals_default_posts`
- fallback branch: latest posts when both fields are empty
- final query: `post__in` plus `orderby = post__in`

Required branch states:
- current-owner branch active: editable current-post subset if exact source field and target subset are proven
- options fallback branch active: shared/option-scope with inspect-only default, then writable only for exact target-CPT subsets or exact full-field matches with explicit shared-option acknowledgement and a save contract for the option field
- recent/default query branch active: inspect-only or no badge because there is no relationship field to mutate
- ambiguous branch: inspect-only with source evidence and no save action

Current-owner hint widening now active:
- simple direct `get_field('field_name')` calls in Query Editor PHP are extracted as current-owner source hints
- obvious current-object calls such as `get_field('field_name', get_the_ID())` and `get_field('field_name', get_queried_object_id())` are also treated as current-owner source hints
- local variables directly assigned from `get_the_ID()` or `get_queried_object_id()` and then passed to `get_field()` are also treated as current-owner source hints
- `get_field('field_name', 'option')`, user-owned reads, literal object-ID reads, and arbitrary variable object reads are intentionally excluded from writable current-owner hints in this slice
- hints are only used to narrow candidate matching; the final ordered query IDs still must exactly match the target-post-type subset of one current-owner ACF relationship/post_object field
- if Query Editor source evidence contains only explicit-object reads, the loop falls through to locked fallback evidence even when the final IDs coincidentally match a current-owner field
- this hint branch itself does not enable shared-option fallback editing; exact options fallback subsets are handled by the explicit shared-option branch below

Inspect-only fallback evidence now active:
- Query Editor `get_field()` calls are split into direct current-owner hints, option-field hints, and explicit-object hints.
- If final `post__in` IDs do not prove exactly one current-owner relationship/post_object field, the loop can still surface as locked evidence instead of disappearing.
- Exact ACF options relationship/post_object fallback matches are labelled with `query_branch_state = shared_option_fallback_exact_match` and `scope = shared_entity`.
- Exact options fallback matches with one concrete target post type can now use the shared filtered-subset editor with acknowledgement.
- Exact options fallback matches with no concrete target post type can use the full shared collection editor only when the rendered query IDs exactly equal the full stored options field order.
- Exact options fallback matching now walks nested ACF group fields and preserves `field_selector_raw`, leaf field identity, `group_path`, and `group_key_path` before enabling the shared-option collection contract.
- Recent/default or otherwise unmatched Query Editor `post__in` branches are labelled with `query_branch_state = query_editor_post_in_unmatched` and remain inspect-only.
- Shared-option mutation is deliberately limited to exact target-CPT subset replacement or exact full-field replacement; current-field seeding, recent/default editing, partial windows, sorted results, and ambiguous fallback editing remain deferred.

Panel requirements for custom fallback branches:
- show the active branch label, such as `Current page related items`, `Site default related items`, or `Recent posts fallback`
- show candidate source fields and owner scope where proven
- explain why save is enabled or locked
- when options fallback is active, use shared-option warnings and shared-option save labels
- when current field is empty but options fallback is active, provide an explicit action path such as `Add items to current page field` rather than silently editing the options fallback

Current UI status:
- locked fallback branches use `reference_collection_preview`, a read-only panel mode that groups queried items by object/CPT type and shows frontend/backend item links when available
- notice copy names the active branch as either shared options fallback or unmatched Query Editor `post__in`
- locked fallback previews include a compact source-evidence block with the active branch, queried count, target type, current/options field hints, dynamic tags, and saved Bricks include/post__in setting evidence when available
- search, add, remove, reorder, and save controls are intentionally absent from the locked preview mode
- exact target-CPT and exact full-field shared options fallback branches use the standard `reference_collection` editor, shared-scope acknowledgement, and reload-after-save behavior
- exact shared options fallback branches can also expose an explicit `Add to current page field` action when one current-owner relationship/post_object hint is proven, the current field has no existing target items for that branch, and the action can be routed through a dedicated current-owner seed contract; nested current-owner group fields are allowed only when the flattened selector and grouped metadata are proven
- collection saves now expose both `Save` and `Save and Reload` for query-collection editors: `Save` writes the collection, keeps the page in place, closes the panel, and updates the transient descriptor state so repeat saves do not stale-conflict; `Save and Reload` preserves the original reload-based reconciliation path
- seed actions no longer force an immediate reload; the panel exposes undo and reload controls after seeding so users can restore the previous current-page field value before refreshing the Bricks query loop

Current writable status:
- exact ACF options fallback branches are writable only when the final query IDs equal one target-post-type subset or the full stored value of one options-owned ACF `relationship` / `post_object` field
- descriptors remain `scope = shared_entity`, `entity.type = option`, and `source_context = shared_option_fallback`
- save contracts use shared filtered-subset names so the panel and REST route require shared-scope acknowledgement before saving
- target-subset saves reuse the filtered-subset stale check, target-post-type validation, full-field merge preservation, reload-after-save behavior, and journal payload
- exact full-field options saves use the standard shared collection contract and reload-after-save behavior
- current-field seeding from fallback is limited to the explicit current-owner seed action; nested group seed targets preserve their raw selector and group path metadata before mutation; recent/default fallback editing, partial windows, sorted results, and ambiguous fallback editing remain deferred

Next validation/UI slice:
- browser-smoke no-reload `Save`, `Save and Reload`, seed undo, and seed reload controls on a real fallback loop
- keep branch/source copy visible in the panel so users can distinguish editing the shared fallback from seeding the current page field
- keep recent/default and ambiguous fallback branches read-only in the locked preview mode until a provable writable source contract exists

Implementation slices for Phase 7:
1. Add inspect-only branch evidence for known final `post__in` queries that do not match a current-owner field. Implemented for unmatched Query Editor `post__in` branches and exact ACF options fallback matches.
2. Continue widening current-owner Query Editor source hints only for direct, unambiguous current-owner ACF relationship/post_object reads.
3. Detect exact option-owned ACF relationship/post_object fallback matches and label them as shared-option query collections. Implemented; exact target-CPT subsets use the shared filtered-subset editor, exact full-field matches use the full shared collection editor, and nested group option fields reuse the same recursive field matching as current-owner collections.
4. Add an explicit branch selector UI only after source ownership can be shown in the panel.
5. Enable shared-option saves only after the option field path, target post type or full-field identity, acknowledgement copy, stale-subset/full-field handling, and journal payload are proven. Current slice is limited to exact target-CPT options fallback subsets and exact full-field options fallback matches.
6. Add a current-owner "seed field from fallback" action only as a separate explicit mutation contract, not as a side effect of editing the fallback list. Implemented for exact shared-option fallback branches when one current-owner direct or nested-group field hint is proven and the current field has no existing target items to overwrite.

## Derived Bricks Query Loop Linked-Posts Editor

### Goal

Allow authorized frontend users to modify the current post's stored related-object list for a Bricks query loop whose rendered cards are derived from an ACF relationship/post_object field.

User-facing label:
- badge: `Modify Linked Posts`
- panel mode: linked-post subset editor
- save behavior: update the source ACF field, then reload so Bricks can rebuild the query loop

This should feel like a native Visual Editor panel mode, not a separate plugin UI. It should reuse the existing `dbvc-ve-panel` shell, source summary, source-details toggle, owner warnings, save acknowledgement patterns, drag/viewport fitting behavior, and reload-after-save messaging.

### First Supported Shape

The first safe slice should be narrow:
- current post/page/CPT owner only
- ACF `relationship` field only
- source field stores post IDs or post objects that can be normalized to IDs
- Bricks query returns post objects from a single target post type
- query order matches `post__in` / stored field order
- no pagination, random order, date sorting, or partial-limit query
- no fallback branch is active, such as option defaults or recent posts

For the motivating example:
- source field: `page_related_items`
- target subset: `service` posts currently stored in `page_related_items`
- save should preserve non-service IDs in `page_related_items`
- save should only replace the service subset

### Source Detection Rules

Automatic detection should be conservative.

Candidate detection:
- capture Bricks query-root metadata and final query args where available
- capture the rendered loop item IDs in order
- inspect current-owner ACF `relationship` / `post_object` fields whose allowed post types include the loop post type
- normalize the source field's full stored value to ordered post IDs
- compute the target subset by filtering source IDs to the loop's post type

Writable only when:
- exactly one candidate source field matches
- rendered/query post IDs match that source field's target-post-type subset in order
- the query includes all IDs for that target subset
- the current field value is not falling back to options/default/recent logic
- the source field is on the current entity and passes existing capability/nonce checks

Inspect-only or no marker when:
- multiple source fields match
- query IDs are only a partial page/limited window of the source field
- query order is sorted by anything other than stored field order
- query IDs match an option fallback rather than the current field
- the query source cannot be proven from runtime metadata

### Runtime Evidence To Capture

The detection probe should collect evidence without parsing arbitrary Query Editor PHP:
- Bricks query element ID and parent/root element ID
- query mode, final post type, `post__in`, `orderby`, `posts_per_page`, pagination state, and whether a custom query editor was used
- ordered runtime loop result IDs
- current page/entity owner
- candidate ACF relationship/post_object fields on that owner
- each candidate field's full stored ordered IDs
- each candidate field's filtered subset for the loop post type
- whether the runtime loop result exactly equals one candidate subset
- whether a fallback/default/recent-post branch appears active

The descriptor should be writable only when this evidence produces one exact source candidate. Otherwise the panel should explain the evidence and remain inspect-only.

### Descriptor Contract

Use the existing collection descriptor family with derived-query metadata:
- `source.type = acf_collection_field`
- `render.context = query_collection`
- resolver name: `acf_reference_collection`
- mutation kind: `collection`
- contract name: `relationship_collection_filtered_subset`

Additional source metadata:
- `query_source = derived_bricks_query`
- `source_field_name`
- `source_field_key`
- `source_field_selector`
- `source_owner_entity`
- `target_post_type`
- `full_value_ids`
- `target_subset_ids`
- `preserved_ids`
- `query_result_ids`
- `subset_write_mode = replace_target_post_type_subset`
- `source_confidence = exact_current_owner_subset`

Additional UI/source-summary metadata:
- `source_label`
- `owner_label`
- `target_post_type_label`
- `query_element_id`
- `query_container_element_id`
- `preserved_count`
- `rendered_count`
- `source_detail_string`

Example source detail string:

```text
Source: acf_relationship / page_related_items / target:service / owner:page:123 / query:brxe-abc123
```

### Save Semantics

For the first slice, save should use a replace-subset strategy:
- read the latest full source field value immediately before write
- remove IDs whose current post type matches `target_post_type`
- insert the submitted target-post-type IDs at the first previous target-subset position
- if the field had no previous target-subset IDs, append the submitted IDs after preserved IDs
- preserve all non-target-post-type IDs
- validate every submitted ID exists, is published or otherwise allowed, matches `target_post_type`, and is allowed by the ACF field settings
- write the full merged ID list back to the ACF relationship field
- journal before/after full values and before/after target subset
- reload after save

Do not attempt in-place card DOM reconciliation in the first slice.

Save conflict behavior:
- re-read the source field immediately before mutation
- recompute the latest target subset from the current stored field
- if the latest target subset differs from the descriptor's original target subset, require a reload/reopen instead of silently overwriting another user's change
- reject submitted IDs that are not in the allowed post type set
- reject duplicate submitted IDs unless the source field definition explicitly allows duplicates, which ACF relationship fields generally should not
- preserve the original relative position of non-target IDs exactly

### UI Contract

The panel should reuse the current reference-collection editor where practical, with these differences:
- title: `Modify Linked Posts`
- source summary: `Field: Page Related Items`, `Editing: Service posts`, `Saves: current page`
- selected list shows only the target post type subset
- search defaults to the target post type
- post type filter is locked to the detected target type in the first slice
- taxonomy filter can narrow search results but must not become part of the save contract unless query filtering is explicitly supported
- non-target preserved IDs should be summarized but not silently exposed as editable in the first slice

The panel should keep the existing Visual Editor source meta pattern:
- entity title/link area at the top when available
- concise source label and owner label
- small `Source details` toggle below the label
- full raw source string only inside the expanded details area
- save contract label such as `relationship subset`
- acknowledgement copy only when the owner is non-current or shared; the first slice should avoid those scopes

Recommended first-slice layout inside the existing panel:
- header: `Modify Linked Posts`
- source summary: current owner, field label, target post type, query element
- selected list: ordered selected target posts with remove and move controls
- selected list: grouped by object/CPT/term type in collapsed accordions by default, while preserving the stored ID payload order for saves
- search row: search input plus locked post-type indicator
- optional taxonomy filter: search-only, not part of the save contract
- search results: add buttons for allowed target posts
- preserved summary: `N other linked items in this field will be preserved`
- collapsed source details: full source string and query evidence
- footer: save/cancel/status using existing panel actions

The attached reference UI maps to this panel mode as:
- left/current list: selected target-post subset
- right/search list: candidate target posts
- post type dropdown: locked to the detected target post type in the first slice
- taxonomy dropdown: search filter only
- search field: existing reference-search endpoint with target post type filter

Future UI hardening can add:
- post type dropdown for editing other subsets of the same mixed relationship field
- taxonomy filters as search-only helpers
- grouped selected/current lists like the reference screenshot
- explicit "Show preserved linked posts" disclosure
- source-field chooser when multiple current-owner relationship fields could plausibly drive the loop

### Badge Placement

The badge should attach to the query root/container rather than every child card:
- badge label: `Modify Linked Posts`
- scope color: current-entity treatment for current-owner fields
- source summary must clarify that this edits the ACF relationship field, not the individual rendered cards
- existing visibility/hit-test rules still apply so hidden/offcanvas query roots do not show badges

Badge collision rules:
- if a query root already has a native `Edit Connected` marker, prefer the native marker and do not add the derived marker
- if descendant cards have normal `Related Post` field badges, keep those descendant badges; the new badge is only for modifying the source collection
- if multiple derived subset markers overlap visually, show only the closest query container marker for the active hover/focus target

### Risks / Guardrails

- Do not parse arbitrary Query Editor PHP as the source of truth.
- Do not write when a fallback branch is active, such as option defaults or recent posts.
- Do not assume a visible set of cards is the full field subset when the query is limited, paginated, randomly ordered, or filtered by taxonomy.
- Do not remove non-target post types from a mixed relationship field.
- Do not let taxonomy search filters imply taxonomy collection mutation.
- Do not support shared/option-backed relationship fields in the first slice.

Performance guardrails:
- do not hydrate all relationship fields on page load
- use shallow public marker metadata only at session bootstrap
- hydrate the derived collection descriptor only when the container badge is opened or prefetched by the existing bounded warmup path
- cache candidate source analysis for the active request/session only
- keep post search paginated and debounced

Security guardrails:
- require the same Visual Editor capabilities and nonces as other saves
- validate the source field still belongs to the current owner at save time
- validate ACF field type and allowed post types server-side
- treat all submitted IDs as untrusted
- journal the full before/after field value, not only the edited subset

### Implementation Slices

0. Live target inventory:
   - identify pages/templates that render mixed relationship-field subsets, starting with `page_related_items` filtered to `service`
   - record Bricks query element IDs, parent containers, source fields, target post types, and whether fallback branches exist
   - choose one exact current-owner fixture before writing UI code

1. Detection probe and docs:
   - status: implemented in code for post loops whose final Bricks `posts/query_vars` expose a concrete `post__in` list and a single target `post_type`, and widened to native Bricks dynamic include/post__in controls that expose ACF dynamic-tag source evidence
   - nested current-owner ACF group descendants are included when the relationship/post_object leaf can be proven through the flattened ACF selector, for example `benefits_section_benefitsContent_related_items` under `benefits_section -> benefitsContent`
   - identify live Bricks query-loop examples such as `page_related_items` filtered to `service`
   - inspect whether final query args expose `post__in`, `post_type`, `orderby`, and pagination state
   - capture saved native query-control source evidence: include/post__in setting key, dynamic tags, ACF field-name hints, and whether Query Editor PHP was active
   - confirm a current-owner ACF field can be matched exactly by comparing the queried target-post-type subset to one current-owner relationship/post_object field
- for grouped ACF fields, preserve the exact flattened selector as `field_selector_raw` when ACF can read the field by the original mixed-case name but not by the lowercased `sanitize_key()` output
- source summaries should display that raw selector as `selector:{field_selector_raw}` when it differs from the normalized `field_name`, so QA can confirm the visible panel metadata matches the resolver's trusted read/write selector
   - confirm a mixed/any query can match the full ordered current-owner field value before using the full collection contract
   - reject native static include/post__in controls as unsupported because they do not identify a writable ACF source
   - add fixture notes to the QA log before enabling writable behavior

2. Inspect-only marker:
   - status: implemented as a locked `Modify Linked Posts` marker backed by the existing `query_collection` descriptor family
   - surface a container/root marker only when one source field matches
   - Bricks strips `hasLoop` from the visible rendered loop root, so the detector must rely on captured final query vars plus the retained query settings on the visible root instead of requiring `hasLoop`
   - during the temporary UX bridge, the frontend also adds one visible linked-posts button for each resolved Bricks query-loop items container that contains an eligible linked-post query marker
   - the temporary button label should prefer the single target post type proven from the query loop, with copy shaped like `Review Posts` / `FAQ Posts` / `Service Posts`
   - if a later inspect-only marker supports a mixed-post-type query, fall back to `Manage {Bricks element/section label} Posts`
   - the temporary button remains mounted in the shared overlay layer but is positioned against the target container's viewport rect, so content-section overflow or transform styles do not clip the badge while it visually sits on containers such as `brxe-swnffk`
   - when multiple eligible query loops resolve to the same container, lay their bridge badges out in a horizontal row before falling back to viewport-safe wrapping
   - the underlying query-loop container marker keeps the existing hover/focus inspect-only badge treatment until the filtered-subset save contract is enabled
   - show source field, target post type, current subset, preserved non-target count, and why save is locked
   - use badge text locked `Modify Linked Posts` until write safety is proven
- render within the existing `dbvc-ve-panel` shell with the same source summary and details toggle

3. Writable current-owner subset:
   - status: implemented for current-owner derived Bricks post query loops with one exact ACF relationship/post_object source field and one proven target post type
   - enable `relationship_collection_filtered_subset`
   - enable `post_object_collection_filtered_subset`
   - grouped current-owner source fields use the same filtered-subset contract once the flattened selector and stored IDs are proven; they do not use row-backed ancestry unless the query root is actually inside a repeater/flexible row
   - reuse reference search and selected-list UI
   - scope search and validation to `query_target_post_type`
   - save by rereading the full source field immediately before write, rejecting stale target-subset conflicts, replacing only target-post-type IDs, and preserving all non-target IDs in their existing relative order
   - durable journal context now records full source IDs before/after, edited target subset IDs before/after, preserved IDs before/after, descriptor target IDs, query element ID, and stale-conflict state
   - reload after save so Bricks rebuilds the query loop DOM from the updated relationship/post_object field
   - save full merged field value with non-target IDs preserved
   - reload after save
   - reject stale descriptors when the source field subset changed since descriptor creation

4. UX refinement:
   - status: partially implemented for filtered-subset derived Bricks Query Editor editors
   - add selected/search grouping similar to the reference UI
   - target-CPT filtered subset editors now show selected/search labels scoped to the proven post type and disclose how many other linked items in the source field will be preserved
   - add search post-type/taxonomy controls as search filters
   - improve empty-state handling when the current target subset is empty but source mapping is explicit
   - add clear preserved-items disclosure for mixed relationship fields

4A. Empty current-owner query loops:
   - support Bricks loops that render only comments such as `<!--brx-loop-start-zsfmel-->` because the current-owner relationship/post_object field has no matching related items
   - support Bricks loops whose source field is not empty but contains no IDs matching the loop's proven target post type, for example a mixed relationship field containing only `benefit` IDs while the loop targets `post`
   - require explicit source evidence from a saved ACF dynamic include/post__in control or a Query Editor current-owner `get_field('field_name')` hint
   - require a concrete target post type from final query vars or saved query settings; empty mixed/`any` loops remain unsupported because there are no result IDs to prove the target subset
   - require the hinted current-owner ACF field's stored target-CPT subset to be empty at descriptor creation
   - register a synthetic `query_collection` descriptor from the captured Bricks `posts/query_vars` hook, because a fully empty loop may never call the render-attributes hook for the loop element
   - keep the normal filtered-subset save contract so adding the first item rereads the full ACF value, rejects stale target-subset conflicts, preserves non-target IDs, writes the merged ordered ID list, journals the mutation, and reloads the page
   - when Bricks renders no loop item element, inject a hidden Visual Editor marker immediately after the matching `brx-loop-start-{element_id}` comment or `.brx-query-trail[data-query-element-id="{element_id}"]` placeholder so the existing container-level badge can anchor to the nearest visible Bricks parent container
   - do not make empty shared-option fallback, recent/default, paginated, sorted, or ambiguous Query Editor branches writable in this slice

5. Optional explicit mappings:
   - add a safe admin/frontend mapping layer for query loops that cannot be automatically proven
   - mapping fields: Bricks element ID, source ACF field, owner scope, target post type, optional taxonomy filter
   - mapped loops still require runtime validation before save

6. Broaden carefully:
   - support native Bricks dynamic `include` / `post__in` controls only when the saved control exposes an ACF dynamic tag that maps to one current-owner source field
   - support ACF `post_object` only after relationship subset replacement is stable
   - support multiple editable post-type subsets only after the panel can switch subsets without corrupting preserved IDs
   - consider shared/option-backed sources only after the current-owner branch has explicit acknowledgement, active-branch UI, stale-subset handling, and rollback/journal coverage

### Acceptance Criteria

- A custom Bricks query loop derived from `page_related_items` and filtered to `service` can surface one container-level `Modify Linked Posts` badge.
- A custom Bricks query loop derived from a nested group relationship field such as `benefits_section_benefitsContent_related_items` and filtered to `benefit` can surface the same container-level collection badge.
- The same kind of custom or native dynamic query loop can surface a container-level badge when the proven current-owner target subset is empty and Bricks only outputs the loop-start comment.
- The modal lists only the currently linked service posts from `page_related_items`.
- Search returns only valid service posts allowed by the source ACF field settings.
- Saving replaces the service subset while preserving other post types in `page_related_items`.
- If the loop is rendering defaults or recent posts instead of current-field values, the badge is locked or absent.
- The page reloads after save and Bricks renders the updated service cards.
- The panel source meta clearly shows current owner, source field, target post type, query element, preserved-item count, and collapsed full source details.
- The save journal records full before/after values plus edited target subset before/after values.

## Later Mutation Branches

These remain separate from the collection-editor rollout:

1. relationship collection editing in loop-owned/shared contexts
2. repeater row insert/remove/reorder
3. flexible row insert/remove/reorder

Reason:
- they mutate row cardinality or ownership scope, not just the ordered values inside one direct field

## New Phase: Post-Owned Linked Term Collections

Goal:
- let a user manage the taxonomy terms assigned to the post rendered by a Bricks query-loop card when that card contains a nested Bricks `objectType: term` query using `current_post_term`
- surface a container/root badge such as `Edit Linked Terms` or `{Taxonomy Label} Terms`
- reuse the existing Visual Editor collection modal where possible, but make the source contract explicit as WordPress post-term relationships instead of an ACF relationship/post_object field

Source shape:
- parent loop owner: concrete post entity, either the current post/page or a loop-owned related post card
- child loop root: Bricks query with `objectType: term`, `current_post_term: true`, and exactly one taxonomy in the saved query settings
- storage target: `wp_term_relationships` for the owner post and taxonomy, mutated through `wp_set_object_terms()`
- read target: `wp_get_object_terms()` for the same owner post and taxonomy

Descriptor requirements:
- `source.type = post_terms_collection`
- `render.context = query_collection`
- `source.field_type = taxonomy`
- `source.reference_taxonomies = [taxonomy]`
- `source.query_collection_write_mode = replace_post_terms`
- `resolver.name = post_terms_collection`
- current-owner contract: `post_terms_collection`
- loop-owned post contract: `loop_owned_post_terms_collection`

UI requirements:
- badge appears on the nested term query loop container/root, not on every rendered term chip/link
- panel label should name the taxonomy, for example `Filter Tag Terms`
- panel source meta must identify owner post, taxonomy, Bricks query element, and save contract
- selected/search lists should use term labels and term edit/frontend links where possible
- loop-owned card edits require the existing related-owner acknowledgement so users understand they are changing the card post, not the current page

Save safety:
- require `edit_post` on the owner post
- require taxonomy `assign_terms` capability when WordPress exposes one
- reject descriptors without exactly one taxonomy
- reject non-hierarchical/hierarchical ambiguity by saving term IDs only
- preserve all other taxonomies assigned to the post; only replace the one descriptor taxonomy
- use no-reload save by default with optional reload, matching current query-collection UX

Implementation slices:
1. Detect `objectType: term` + `current_post_term` query roots from Bricks element settings and create a collection-root descriptor only when a concrete post owner and one taxonomy are proven. Implemented for non-empty rendered loop roots.
2. Add `PostTermsCollectionResolver` for read/search/validate/save using WordPress term APIs. Implemented.
3. Add explicit mutation contracts and acknowledgement labels for current-owner and loop-owned post term collection saves. Implemented.
4. Reuse the existing reference-collection panel with term-specific copy and grouped term rows. Implemented with minimal term-specific panel copy.
5. Add empty-loop handling only after non-empty term-loop root markers are confirmed.
6. Later, consider ACF `taxonomy` field-backed query roots as a separate branch because those mutate an ACF field, not native post term relationships.

Initial live examples to inspect:
- `bricks_template-f3-pricing-cards-tall-23814.json` includes `objectType: term`, `current_post_term: true`, taxonomy `filter-tag` inside pricing/card structures.
- `bricks_template-flo-about-single-26168.json` includes plain `objectType: term` taxonomy loops without `current_post_term`; those should remain outside this first save slice unless owner semantics are proven.

Validation note:
- `/private/tmp/dbvc_ve_post_terms_collection_probe.php` classified a real `filter-tag` current-post term loop candidate as editable, resolved `post_terms_collection`, returned badge `Filter Tag Terms`, confirmed the mutation contract is writable, and found searchable term results.

## Acceptance For The First Slice

- a current-owner native ACF relationship/post-object query root surfaces a VE marker on the container/root
- the badge reads `Edit Connected`
- the panel lists the current connected posts in stored order
- the user can search allowed posts and add/remove/reorder them
- save writes the final ordered collection to the direct ACF field
- success triggers a page reload and the loop re-renders from the updated collection
- the change journal records before/after collection values
