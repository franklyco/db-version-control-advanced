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
- direct safe ACF fields on concrete queried post, term, and user loop owners are now writable through the explicit loop-owned contract layer
- direct flexible descendants with stable row + layout identity now surface with stable path metadata
- current/related post flexible text-like, WYSIWYG, choice, link, and image descendants are now writable through the flexible contract layer
- flexible gallery plus non-post/shared flexible descendants are still pending

### Immediate next implementation order

Do not start with broad flexible-content query-loop writes.

Recommended next sequence:
1. formalize Descriptor V2 owner/page/loop/path/mutation metadata
2. add durable Visual Editor change-set journaling
3. introduce dedicated save-contract metadata for loop-owned sources
4. expand inspect-only flexible/query-loop coverage where ownership is stable
5. enable writable flexible scalar descendants only after the above are in place

Reason:
- repeater row writes already proved the narrow nested-path pattern
- flexible-content rows and broader loop-owned saves are the next nested-path consumers
- they should inherit a real journal + contract layer instead of forcing more special cases through the current direct-field mutation path

### Phase C: structured descendants

Add writable paths for:
- repeater link fields
- repeater checkbox/select/radio/button group fields
- flexible structured descendants

### Phase D: relationship collection controls

Only after journaling exists:
- single-item replace
- append/remove
- reorder
- relation target validation

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
8. Add relationship collection mutation UI last.
