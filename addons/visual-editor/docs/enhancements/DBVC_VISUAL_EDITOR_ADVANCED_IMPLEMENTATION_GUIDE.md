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
- a narrow related-post loop slice where Bricks resolves a concrete related post owner

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
- do add the change-set + change-item journal tables before enabling flexible-content writes, repeater row reordering, relationship collection mutation, or rollback-aware multi-step saves

Reason:
- marker sessions are ephemeral request state
- advanced field edits are operational write history and need durable rollback state

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
- flexible descendants are still pending

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

1. Add inspect-only advanced markers for unsupported repeater/flexible/query-loop items.
2. Add visible non-current-owner badges while preserving the existing border color differentiator.
3. Formalize descriptor V2 shape for owner/page/path/loop metadata.
4. Add journal tables and DBVC schema migration hooks for advanced write history.
5. Add journal tables and DBVC schema migration hooks before flexible rows, repeater row reordering, relationship collection mutation, or rollback-aware multi-step saves.
6. Expand repeater/flexible structured descendants.
7. Add relationship collection mutation UI last.
