# DBVC Visual Editor Native Loop Expansion Plan

## Goal

Extend the Visual Editor from the now-hardened native ACF repeater slice into the next real nested-loop families without collapsing back into ad hoc field fixes.

Target outcome:
- native Bricks `relationship`, `post_object`, and `taxonomy` loops can surface and edit supported nested descendants with the same canonical owner/path contract now used for native repeater loops
- nested group/repeater/flexible descendants under those native loops can reuse the existing resolver stack instead of introducing per-loop special cases
- later collection mutation work has a clean prerequisite path instead of being mixed into scalar descendant support

## Current Runtime Baseline

Already true in this repo:
- native Bricks ACF query roots are classified from `query.objectType`
- native repeater loops are materially hardened across shortened aliases, wrong child keys, fake related owners, repeated-loop seed collapse, and repeater-in-repeater ancestry
- native loop provenance now travels through descriptor `source`, `path`, and `mutation`
- parent native-loop ancestry now survives nested paths like `relationship -> repeater`
- stable flexible row/layout identity is canonicalized from the actual row payload when Bricks emits the wrong layout alias
- group ancestry and `group_key_path` now survive descriptor classification, row traversal, and live sync grouping
- durable journal tables and explicit mutation contracts already exist

This means the next branch should build on the existing nested-path contract, not invent another loop-specific abstraction.

## Priority Order

### Immediate branch

1. native `relationship` loops with nested descendants
2. native `post_object` loops with nested descendants
3. native `taxonomy` loops with nested descendants
4. `flexible -> repeater` and `repeater -> flexible` mixed nesting under the above owners
5. grouped-descendant live save hardening on those same paths

### Later structured branch

6. structured descendants beyond gallery:
   - file
   - oEmbed / embed-like fields
   - media-object style descendants that are not plain image/gallery

### Later collection-mutation branch

7. relationship collection editing
8. repeater row insert/remove/reorder
9. flexible row insert/remove/reorder

The narrow direct current-owner connected-items slice now has its own implementation track in [DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md](./DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md). This native-loop expansion plan remains the source of truth for descendant editing and the later broader collection-mutation branches.

## Scenario Matrix

### A. Native owner loop descendants to support next

| Scenario | Owner identity | Nested path shape | Initial target state | Notes |
| --- | --- | --- | --- | --- |
| `relationship -> direct field` | concrete related post | leaf | already largely supported | keep as baseline smoke |
| `relationship -> repeater` | concrete related post | row descendant | next writable slice | same scalar/structured field set as current repeater support |
| `relationship -> flexible` | concrete related post | row + layout descendant | next writable slice | reuse canonical flexible row contract |
| `relationship -> group -> repeater/flexible` | concrete related post | grouped nested descendant | inspect-first, then writable | requires live grouped-save smoke before broadening |
| `post_object -> direct field` | concrete related post | leaf | already largely supported | keep as baseline smoke |
| `post_object -> repeater` | concrete related post | row descendant | next writable slice | same contract family as relationship case |
| `post_object -> flexible` | concrete related post | row + layout descendant | next writable slice | same contract family as relationship case |
| `taxonomy -> direct field` | concrete related term | leaf | already partially supported | include media and group cases in smoke |
| `taxonomy -> repeater` | concrete related term | row descendant | inspect-first, then narrow writable | do not assume term nested collections behave like post nested collections until verified |
| `taxonomy -> flexible` | concrete related term | row + layout descendant | inspect-first, then narrow writable | same caution as above |
| `taxonomy -> group -> repeater/flexible` | concrete related term | grouped nested descendant | inspect-only first | only widen after runtime proof |

### B. Mixed collection nesting to support after owner-loop hardening

| Scenario | Why it matters | Initial target state |
| --- | --- | --- |
| `repeater -> flexible` | common “card row with flexible sub-sections” shape | inspect-first, then writable scalar descendants |
| `flexible -> repeater` | common “section layout with repeated items” shape | next likely writable branch after owner loops |
| `repeater -> group -> flexible` | stresses group-key ancestry + layout ancestry together | inspect-first |
| `flexible -> group -> repeater` | stresses layout identity + nested row identity together | inspect-first |

### C. Collection mutation branches to delay

| Branch | Why it is later |
| --- | --- |
| relationship collection editing | this is not one field projection update; it is ordered collection mutation with validation and rollback implications |
| repeater row insert/remove/reorder | changes row cardinality and indexes, not just a field inside one row |
| flexible row insert/remove/reorder | changes layout sequence and row identity, not just a field inside one layout row |

## Writable Scope For The Next Runtime Slice

Enable write support first only where all of the following are true:
- the owner is concrete and verified from the native loop context
- the nested path is canonical and complete in descriptor `path`
- the field type already has a safe resolver-backed mutation contract
- the rendered projection is exact and verifier-backed

Initial writable field types for the next native owner-loop descendant slice:
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
- `gallery`

Initial inspect-only-first field types for that slice:
- `file`
- `oembed`
- `relationship` collections
- multi-target `taxonomy`
- nested collection/object-style descendants without a dedicated collection mutation contract

## Canonical Contract Rules

Do not add a second mutation model for native relationship/post-object/taxonomy loops.

Use the same canonical descriptor requirements:
- owner entity
- root selector
- leaf selector
- row index when row-backed
- layout key/name when flexible
- `group_path`
- `group_key_path`
- parent native loop ancestry

Save-side contract rules:
- row-backed writes may populate an empty leaf value inside a proven row/container
- row-backed writes must not create missing nested repeater rows, missing nested repeater containers, or missing grouped containers from descriptor metadata alone
- if a descriptor's nested path no longer resolves against the current stored ACF payload, save should fail with an explicit safety message instead of creating a new path

The runtime should keep solving by failure class:
- root selector alias drift
- wrong child key drift
- grouped key-path drift
- row/layout identity drift
- media projection drift

Not by individual field name.

## Implementation Phases

### Phase A. Native owner-loop descendant expansion

#### A1. Relationship loops

Target:
- `relationship -> repeater`
- `relationship -> flexible`
- grouped descendants inside those paths

Work:
- verify the parent native loop ancestry remains intact through classification
- ensure the effective related-post owner survives nested row/layout descent
- reuse existing repeater/flexible read/write helpers once the owner/path is canonical

Current status:
- `relationship -> repeater` is runtime-smoked on page `88` with template `923` (`Single Page`): parent loop `bdxtme` / `acf_related_faq_groups`, inner loop `hudkbu` / `acf_faq_items_repeater`, descendants `yimqpq`, `zvywab`, `lisgki`, and `bwgvtd`.
- The probe confirmed `scope=related_entity`, owner post `863` (`faq`), row-backed source type `acf_repeater_subfield`, and descriptor ancestry with `parent_native_query_kind=relationship`.
- Structured synced-template scanning found 25 native `relationship -> repeater` occurrences, including FAQ and gallery-group descendants; use these for wider manual smoke, but do not infer save safety for gallery/media projections without the existing media/galleries final checks.
- The same structured scan did not find a current native `relationship -> flexible` fixture. Keep that branch WIP until a real template is added or identified.
- A custom Bricks Query Editor post loop that reads an ACF relationship field is not the same source shape. It can still resolve as a related-owner repeater path when Bricks exposes a concrete `WP_Post`, but it should not be counted as native `relationship -> repeater` coverage.

#### A2. Post-object loops

Target:
- `post_object -> repeater`
- `post_object -> flexible`
- grouped descendants inside those paths

Work:
- same as relationship loops
- confirm single-owner post-object loops do not regress direct-field support while nested descendants widen

Current status:
- Direct native post-object owner loops such as `acf_office_manager` remain covered as loop-owned related post fields.
- Structured synced-template scanning did not find a live native `post_object -> repeater` or `post_object -> flexible` descendant fixture. Keep this branch WIP until a real template is added or a disposable fixture is created.
- Do not broaden post-object nested save claims from the relationship fixture alone; the expected code path is shared, but the field return shape and Bricks loop normalization still need their own smoke.

#### A3. Taxonomy loops

Target:
- direct native term fields, including `{term_name}` and `{term_description}` when Bricks exposes a concrete loop term owner
- direct grouped/media term fields
- then term-owned repeater/flexible descendants

Work:
- keep native `term_name` and `term_description` writable through `TermFieldResolver` for concrete loop terms on archive and non-archive contexts
- keep derived `term_url` and `term_id` inspect-only
- inspect-first on nested term collection paths
- only widen to writable after real site confirmation that owner identity, nested selector resolution, and save path are stable

### Phase B. Mixed nesting expansion

Target:
- `flexible -> repeater`
- `repeater -> flexible`

Work:
- make sure the nested-path segment model can express both row and layout ancestry together
- keep any unsupported deeper collections inspect-only instead of silently unsupported

### Phase C. Grouped live-save hardening

Target:
- real-page save verification for grouped descendants inside supported repeater/flexible/related-owner/taxonomy-owner paths

Acceptance:
- saves hit the intended grouped leaf
- no sibling group cross-sync
- no fallback to ambiguous leaf-name writes

### Phase D. Structured descendants beyond gallery

Target:
- `file`
- `oembed`
- other structured non-scalar descendants that project to specific Bricks controls

Rule:
- each new structured field family gets its own explicit mutation contract and verifier rules

## Later Collection Mutation Roadmap

These are not “just another nested descendant” problem.

They require row/collection mutation UI plus stronger rollback semantics.

### 1. Relationship collection editing

Recommended order:
1. inspect-only list surfacing with stable target IDs
2. single-item replace
3. append/remove
4. reorder

Required safeguards:
- validate each selected relation target exists and matches expected object type
- snapshot full collection before write
- write final ordered collection in one apply step
- include per-item add/remove/reorder journal detail

### 2. Repeater row insert/remove/reorder

Recommended order:
1. inspect-only row inventory + stable row identity
2. duplicate/append row
3. remove row
4. reorder rows

Required safeguards:
- row-template generation rules must be explicit
- all row indexes after mutation must be recomputed in memory before write
- post-save sync cannot rely on old row signatures; expect page reload or full marker refresh
- journal entries must record before/after row order and full row payload deltas

### 3. Flexible row insert/remove/reorder

Recommended order:
1. inspect-only row inventory with layout keys
2. append new row by chosen layout
3. remove row
4. reorder rows

Required safeguards:
- layout selection must be validated against the field definition
- inserted row payload must be shaped from the chosen layout schema, not guessed
- row identity must use layout key + row index + ancestor path
- expect reload-based reconciliation after apply

## Validation Fixtures To Gather

Before broadening runtime writes further, keep real pages/templates available for:
- native `relationship -> repeater`: page `88`, template `923`, parent `bdxtme` / `acf_related_faq_groups`, inner `hudkbu` / `acf_faq_items_repeater`, descendant examples `yimqpq`, `zvywab`, `lisgki`, `bwgvtd`
- native `relationship -> flexible`: no current synced-template fixture found; create or identify one before enabling as closed
- native `post_object -> repeater`: no current synced-template fixture found; create or identify one before enabling as closed
- native `post_object -> flexible`: no current synced-template fixture found; create or identify one before enabling as closed
- native taxonomy loop with grouped/media term fields
- native taxonomy loop with nested repeater or flexible descendants
- mixed `flexible -> repeater`
- mixed `repeater -> flexible`
- grouped descendants with repeated visible values across sibling rows

## Recommended Immediate Next Runtime Slice

Start with:
1. native `relationship -> repeater`
2. native `relationship -> flexible`
3. native `post_object -> repeater`
4. native `post_object -> flexible`

Do not start with:
- taxonomy nested collection writes
- relationship collection editing
- repeater/flexible row insert/remove/reorder

Reason:
- the related-post owner contract is already the closest to the hardened native repeater slice
- these scenarios reuse the existing resolver stack with the smallest new mutation surface
- they are the most likely to uncover the next real failure classes in owner/path ancestry before the collection-mutation phase
