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
- repeater/flexible row-owned relationship collections
- relationship collections nested inside flexible/repeater owners
- live in-place DOM patching after save

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

This phase is now implemented in code for direct current-owner row roots where:
- the active native loop path is stable
- repeater chains do not cross a deferred flexible ancestor boundary
- flexible rows do not cross a deferred repeater ancestor boundary

Mixed repeater/flexible nesting and broader grouped row-owned collection roots remain deferred.

### Phase 4. Shared and loop-owned collection roots

- shared post owners
- shared term/user/option owners where meaningful
- loop-owned related owners

These require explicit acknowledgement and stronger owner messaging.

### Phase 5. True collection mutation expansion

- relationship append/remove/reorder from more complex source contexts
- taxonomy multi collections
- custom query-editor collection roots if they can be tied to one canonical field

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
