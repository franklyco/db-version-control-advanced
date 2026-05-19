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
   - identify live Bricks query-loop examples such as `page_related_items` filtered to `service`
   - inspect whether final query args expose `post__in`, `post_type`, `orderby`, and pagination state
   - confirm a current-owner ACF field can be matched exactly
   - add fixture notes to the QA log before enabling writable behavior

2. Inspect-only marker:
   - surface a container/root marker only when one source field matches
   - show source field, target post type, current subset, preserved non-target count, and why save is locked
   - use badge text `Inspect Linked Posts` or locked `Modify Linked Posts` until write safety is proven
   - render within the existing `dbvc-ve-panel` shell with the same source summary and details toggle

3. Writable current-owner subset:
   - enable `relationship_collection_filtered_subset`
   - reuse reference search and selected-list UI
   - save full merged field value with non-target IDs preserved
   - reload after save
   - reject stale descriptors when the source field subset changed since descriptor creation

4. UX refinement:
   - add selected/search grouping similar to the reference UI
   - add search post-type/taxonomy controls as search filters
   - improve empty-state handling when the current target subset is empty but source mapping is explicit
   - add clear preserved-items disclosure for mixed relationship fields

5. Optional explicit mappings:
   - add a safe admin/frontend mapping layer for query loops that cannot be automatically proven
   - mapping fields: Bricks element ID, source ACF field, owner scope, target post type, optional taxonomy filter
   - mapped loops still require runtime validation before save

6. Broaden carefully:
   - support ACF `post_object` only after relationship subset replacement is stable
   - support multiple editable post-type subsets only after the panel can switch subsets without corrupting preserved IDs
   - consider shared/option-backed sources only after the current-owner branch has explicit acknowledgement and rollback coverage

### Acceptance Criteria

- A custom Bricks query loop derived from `page_related_items` and filtered to `service` can surface one container-level `Modify Linked Posts` badge.
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

## Acceptance For The First Slice

- a current-owner native ACF relationship/post-object query root surfaces a VE marker on the container/root
- the badge reads `Edit Connected`
- the panel lists the current connected posts in stored order
- the user can search allowed posts and add/remove/reorder them
- save writes the final ordered collection to the direct ACF field
- success triggers a page reload and the loop re-renders from the updated collection
- the change journal records before/after collection values
