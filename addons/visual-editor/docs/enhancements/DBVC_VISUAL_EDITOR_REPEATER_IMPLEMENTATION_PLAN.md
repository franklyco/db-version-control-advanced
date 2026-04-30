# DBVC Visual Editor Repeater Slice Plan

## Goal

Add the first safe ACF repeater editing slice to the Visual Editor without breaking the existing direct-field contracts.

Target outcome:
- current-post Bricks repeater rows can be marked and edited
- related-post Bricks repeater rows can be marked and edited when the row owner resolves to the related post rendered by the parent loop
- unsupported repeater subfields still surface honestly as inspect-only when ownership and row identity are stable

## Runtime Facts Confirmed In This Repo

- Bricks already exposes repeater subfield metadata through the ACF provider tag registry.
- Repeater subfield tags include both the subfield definition and the parent repeater definition.
- Bricks query loop indexes are zero-based for non-post loops, including ACF repeater loops.
- Nested Bricks loops expose a parent-loop API, so related-post owner context can be recovered for repeater rows nested inside relationship or post-object loops.
- The current Visual Editor blocks active loops unless they resolve to a concrete post owner, so repeater rows are filtered out today before descriptor registration.

## Writable Scope For This Slice

Enable save support only for repeater subfields that satisfy all of the following:
- rendered through Bricks as an exact single-tag dynamic binding
- inside an active Bricks ACF repeater loop with a stable row index
- current-post owner or related-post owner only
- scalar or already-supported structured field types only

Initial writable field types:
- `text`
- `textarea`
- `url`
- `email`
- `number`
- `range`
- `wysiwyg`
- `checkbox`
- `select`
- `radio`
- `button_group`
- `link`
- `image`

Initial inspect-only repeater field types:
- `gallery`
- `post_object`
- `relationship`
- `taxonomy`
- nested object-style descendants that do not yet have a dedicated mutation contract

## Explicit Non-Goals For This Slice

- flexible content writes
- multi-row repeater insert/remove/reorder
- nested repeater-in-repeater writes
- relationship collection editing inside repeater rows
- gallery collection writes inside repeater rows
- generic non-ACF Bricks builder-owned loop state mutation

## Repo Touch Points

### Loop context

Extend the existing loop resolver instead of adding a parallel loop layer:
- include parent-loop metadata
- preserve nested-loop identity in the exported signature
- recover an effective related-post owner from the parent loop when the current loop is an ownerless repeater row

Files:
- `src/Bricks/LoopContextResolver.php`

### ACF context resolution

Extend the current ACF field context resolver to recognize Bricks repeater tags:
- derive the parent repeater from Bricks tag metadata
- verify that the active query object type matches the repeater parent name or suffix
- capture the stable row index
- return repeater path metadata alongside the existing entity/scope/field data

Files:
- `src/Bricks/AcfFieldContextResolver.php`

### Resolver classification

Keep the existing resolver registry and reuse the current field-type resolvers:
- classify repeater descendants as `acf_repeater_subfield`
- attach parent repeater key/name and row index to the source metadata
- keep unsupported repeater descendants inspect-only instead of silently unsupported

Files:
- `src/Resolvers/ResolverRegistry.php`

### Value access and mutation

Do not add repeater-specific REST routes.

Instead, extend the existing ACF resolver base so supported resolvers can read and write a repeater row subfield by:
- loading the raw parent repeater rows
- resolving the correct row by loop index
- mutating only the targeted row subfield
- writing the full parent repeater value back through `update_field()`

Files:
- `src/Resolvers/AbstractAcfResolver.php`
- existing field resolvers that should accept repeater-backed sources

### Marker identity and live sync

Row-backed sources must not share sync groups across different rows of the same repeater.

Add repeater row identity to:
- source group
- sync group
- nested loop signature handling

Files:
- `src/Bricks/ElementInstrumentationService.php`

### Overlay UI

Surface repeater context in the existing modal metadata:
- parent repeater label/name
- row number
- owner context remains current/shared/related through the existing badge/border system

Files:
- `assets/js/overlay-app.js`
- `src/Assets/AssetLoader.php`

## Table Decision

No new Visual Editor table is required for this narrow repeater-row slice.

Reason:
- each save remains a single resolver-owned mutation against one row subfield
- the current audit log already records old/new values and source metadata
- this slice does not introduce row insertion, deletion, reordering, or multi-item collection mutation

Still required next:
- add dedicated `dbvc_ve_change_sets` and `dbvc_ve_change_items` tables before flexible content writes, repeater row reordering, relationship collection mutation, or rollback-aware multi-step saves

## Validation Targets

- current-page repeater text field
- current-page repeater WYSIWYG field
- current-page repeater link field
- related-post repeater row inside a Bricks relationship or post-object loop
- inspect-only surfacing for unsupported repeater descendants
- no cross-row live-sync bleed after save
