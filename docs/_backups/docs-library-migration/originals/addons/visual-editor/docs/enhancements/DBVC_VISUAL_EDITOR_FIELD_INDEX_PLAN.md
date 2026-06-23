# DBVC Visual Editor Field Index Plan

## Goal

Add a collapsible field index inside `dbvc-ve-statusbar > dbvc-ve-statusbar__meta` so users can review all marked Visual Editor fields on the current page without hovering every element.

This is a UI navigation and review feature. It should not change save behavior, resolver classification, mutation contracts, or descriptor source-of-truth rules.

## Product Shape

The statusbar should stay compact by default:
- show the current supported-marker count
- show a small toggle such as `Review fields`
- expand into a nested, scrollable field index only when requested

The expanded index should help answer:
- what fields are marked on this page
- which owner/source group each field belongs to
- whether each field is editable, shared, related, or inspect-only
- where the marker is on the page
- whether a user can jump to or open that marker

## Current Runtime Constraints

The frontend already loads a lightweight session public map on startup.

Current public-map shape includes:
- token
- status
- scope
- label
- input type
- public entity summary: type, id, subtype

That is enough for a first owner-type grouped index, but not enough for rich parent group/source grouping.

Do not solve this by using `hydrate=1` on page load. Full descriptor hydration for every marker would reverse the recent performance improvements.

Current issue audit:
- `renderStatusBarMeta()` snapshots open section/item state before replacing `dbvc-ve-statusbar__meta` with new markup, and now also snapshots the scroll position of `.dbvc-ve-field-index`.
- `cacheDescriptorPayload()` and `mergeSessionDescriptors()` call `scheduleFieldIndexRefresh()` while the index is open, so lazy descriptor enrichment, shared-global descriptor merges, panel opens, saves, and session/status updates can rebuild the index while the user is scrolling it.
- Because the scrollable `.dbvc-ve-field-index` node is replaced, the browser resets its `scrollTop` to `0`, which matches the observed jump back to the top.
- The fix should preserve scroll state for refreshes that do not intentionally change the filter or close/reopen the index, and should clamp the restored value when the filtered/grouped content becomes shorter.

## Recommended Data Contract

Extend `EditableRegistry::exportPublicMap()` with a small, safe `index` payload per token.

Allowed public index fields:
- `sourceType`
- `sourceContext`
- `fieldName`
- `fieldType`
- `parentFieldName`
- `containerType`
- `layoutName`
- `groupPath`
- `nativeQueryKind`
- `nativeQuerySelector`
- `status`
- `scope`
- `entityType`
- `entityId`
- `entitySubtype`
- `label`
- `input`

Optional public index fields:
- `ownerLabel`
- `sourceLabel`
- `groupLabel`

Only include labels that are already safe to show to the logged-in Visual Editor user. Do not include raw field values, rendered values, warnings, save payloads, nonces, or full descriptor objects.

## Grouping Model

The field index should use deterministic client-side grouping from the public map.

Top-level groups:
- Current entity
- Related posts
- Related terms
- Shared options
- Shared terms/users
- Archive fields
- Inspect-only / derived

Second-level groups:
- entity subtype and ID, such as `post:service`, `term:feature_tax`, `option`
- source context, such as `archive_option`, `archive_loop_post`, `archive_loop_term`, `loop_term`, `shared_option`
- container root, such as `repeater:process_steps`, `flexible:core_sections`
- native query root, such as `relationship:related_services`, `post_object:office_manager`, `taxonomy:feature_tax`

Leaf rows:
- field label
- source field name
- status chip: `Edit`, `Shared`, `Related`, or `Inspect`
- input type hint where useful: `text`, `image`, `gallery`, `connected`
- marker action buttons: `Locate`, `Open`

## UI Behavior

Collapsed statusbar:
- `Visual Editor active`
- `42 supported fields`
- `Review fields`
- current entity edit link stays available

Expanded statusbar:
- nested list inside `dbvc-ve-statusbar__meta`
- group headers are collapsible
- counts per group are visible
- editable and inspect-only counts are visible where helpful
- rows are keyboard navigable
- row hover/focus highlights the matching marker
- `Locate` scrolls the marker into view and briefly pulses it
- `Open` loads the descriptor and opens the existing `dbvc-ve-panel`

The field index should not compete with the draggable editor panel. It is a launcher and review surface, not a second editor.

## Implementation Phases

### Phase 0: Contract Audit

Status: completed during planning.

Steps:
1. Confirm all current markers have stable tokens in the startup public map.
2. Confirm public map entries can be safely expanded without exposing field values.
3. Decide the exact public `index` schema.
4. Add unit-level notes for what must stay out of the public map.

Acceptance:
- the plan can group fields without full descriptor hydration
- no sensitive values are added to the page bootstrap payload

### Phase 1: Public Map Index Metadata

Status: implemented in first slice.

Files likely involved:
- `src/Registry/EditableRegistry.php`
- `src/Presentation/DescriptorSummaryBuilder.php` only if label reuse is worth extracting
- `docs/knowledge/DATA_CONTRACTS.md`

Steps:
1. Add an `index` object to each public-map entry.
2. Populate source and owner metadata from the descriptor only.
3. Keep payload shallow and scalar-heavy.
4. Add filters only if needed after the base shape is stable.

Acceptance:
- `session.descriptors[token].index` exists for all markers
- startup payload remains materially smaller than full descriptor hydration
- no full current values or rendered values appear in the index payload

### Phase 2: Client-Side Index Builder

Status: implemented in first slice.

Files likely involved:
- `assets/js/overlay-app.js`

Steps:
1. Add `buildFieldIndexModel(session.descriptors, markers)`.
2. Normalize marker DOM state into the model:
   - token
   - visible/in DOM
   - active/selected state
3. Group by owner category first.
4. Group by source/container context second.
5. Sort groups deterministically:
   - current entity
   - related entities
   - shared/options
   - archive/derived
   - inspect-only
6. Sort rows by DOM order within each group.

Acceptance:
- model can be rebuilt after session refresh, save, or marker remount
- repeated fields with the same source group stay listed as separate DOM markers but can be collapsed under the same source group

### Phase 3: Statusbar UI

Status: implemented in first slice.

Files likely involved:
- `assets/js/overlay-app.js`
- `assets/css/overlay.css`
- `src/Assets/AssetLoader.php` for new strings

Steps:
1. Add a toggle inside `dbvc-ve-statusbar__meta`.
2. Render a compact summary line when collapsed.
3. Render nested groups when expanded.
4. Add `aria-expanded`, `aria-controls`, button semantics, and keyboard focus support.
5. Cap expanded statusbar height and make the list scroll independently.
6. Preserve the existing statusbar edit link.

Acceptance:
- statusbar remains unobtrusive when collapsed
- expanded list is readable on desktop and usable on mobile
- no layout shift breaks the draggable editor panel

### Phase 4: Marker Actions

Status: implemented in first slice.

Files likely involved:
- `assets/js/overlay-app.js`
- `assets/css/overlay.css`

Steps:
1. Add `Locate` action:
   - scroll marker into view
   - set preview/selected marker state
   - pulse the dashed outline briefly
2. Add `Open` action:
   - reuse existing `openEditor(marker, state.session)`
   - reuse descriptor cache/in-flight request map
3. Add active row styling when the marker is active or the panel is open.
4. If a marker is no longer connected, show stale state and offer no action.

Acceptance:
- selecting a row does not trigger navigation
- existing hover/focus badge behavior still works
- opening from the index uses the same panel and save flow as clicking a badge

### Phase 5: Lazy Enrichment

Status: planned after base UI.

Steps:
1. Keep initial index built from the public map only.
2. When a group expands, optionally hydrate only the first visible tokens in that group.
3. Reuse the existing viewport/active prefetch queue.
4. Backfill richer summaries as descriptor payloads arrive:
   - entity title
   - source summary
   - backend/frontend editor links where already available
5. Never block rendering the base index on enrichment.

Acceptance:
- index appears immediately from public metadata
- enriched details improve progressively without request bursts
- no `hydrate=1` startup regression

### Phase 6: Counts and Filters

Status: planned after base UI.

Steps:
1. Add quick filters:
   - All
   - Editable
   - Shared
   - Related
   - Inspect-only
2. Show group counts:
   - total
   - editable
   - inspect-only
3. Add text filter only if the list is large enough to justify it.

Acceptance:
- users can quickly find problematic or inspect-only markers
- filters do not mutate marker state or descriptor state

### Phase 7: QA Matrix

Status: planned.

Required test contexts:
- singular page with direct current-owner ACF fields
- singular CPT with repeater/flexible descendants
- page with related post fields
- page with shared options fields
- CPT archive with option-backed fields
- taxonomy archive with current term fields
- archive query-loop post descendants
- archive query-loop term descendants
- derived readonly tags such as `{post_url}`, `{term_url}`, `{term_id}`, `{archive_title}`

Validation checklist:
- collapsed statusbar count matches rendered marker count
- grouped counts match row totals
- rows are ordered by DOM position within groups
- `Locate` works for visible and below-fold markers
- `Open` works for cached and uncached descriptors
- save results update active row state when applicable
- session refresh preserves index state where possible
- session expiration degrades cleanly
- mobile statusbar remains usable
- keyboard navigation works

## Risks and Guardrails

Risks:
- public map grows too large on pages with hundreds of markers
- nested grouping becomes visually noisy
- users confuse the index with a bulk editor
- hydrated summaries create request bursts

Guardrails:
- keep the first index payload shallow
- keep the collapsed UI as the default
- do not add bulk edit controls in this phase
- hydrate details only on demand
- use DOM order as the fallback truth for row ordering
- leave durable inventory caching out of scope until profiling proves it is needed

## Recommended First Slice

Implement only:
1. public-map `index` metadata
2. collapsed `Review fields` toggle
3. owner-type grouping
4. basic rows with `Locate` and `Open`

First-slice implementation notes:
- `EditableRegistry::exportPublicMap()` now emits a shallow `index` object per public descriptor.
- `overlay-app.js` builds the grouped field index from the session public map and live DOM markers.
- `dbvc-ve-statusbar__meta` now renders a compact count/toggle by default and an expanded nested review list on demand.
- `Locate` scrolls to and previews the marker; `Open` reuses the existing descriptor hydration and editor panel path.

Second-slice condensation notes:
- Public entity summaries now include a lightweight owner label for post, term, user, and option owners.
- Public index summaries now include field-group title and option-page hints where available.
- The frontend index now aggregates marker rows into item-level accordions under each category/source group.
- Related post/term fields, shared options fields, native loop fields, and repeater/flexible row fields are condensed behind one item toggle before exposing individual marker rows.

Third-slice owner/source organization notes:
- `dbvc-ve-field-index__subgroup` is now a structural wrapper only; its redundant summary toggle was removed after parent sections were introduced.
- Each category group includes `Expand all` and `Collapse all` controls for its parent sections.
- Field item accordions are the primary nested toggles under each section, and their summary row keeps the field label and marked-field count on one line.
- Current singular post/term/user metadata groups are prioritized before native-loop/archive/related-style groups within the current entity area.

Fourth-slice state preservation notes:
- Open section keys are snapshotted before the statusbar index re-renders.
- Open item-level accordions are also snapshotted so the exact nested editing context survives panel refreshes.
- Save/status updates restore the same open section and item state instead of reverting to the default state.
- Collapsing and reopening the whole field index preserves section/item state for the current page session.

Fifth-slice lazy enrichment notes:
- Field-index rows now merge in cached descriptor payload summaries when a field has already been opened or prefetched.
- Enrichment can improve owner labels, field labels, input hints, and source detail lines without enabling full startup hydration.
- Descriptor cache updates schedule a lightweight statusbar refresh only when the field index is open.
- Structural subgroup and item keys are stable and separate from display labels so hydrated titles do not collapse the current field context.

Sixth-slice filter notes:
- The expanded field index includes client-side filters for `All`, `Editable`, `Shared`, `Related`, and `Inspect-only`.
- Filter counts are calculated from the full live marker/session entry set, not only the currently filtered view.
- Filtering runs before the grouping model is built, so condensed owner/source/item grouping remains unchanged per filtered subset.
- Empty filtered states show a lightweight message and do not mutate marker, descriptor, or save state.

Minor Enhancement Phase 8: Scroll-stable parent ACF group sections:
- Status: implemented, including follow-up cleanup that removed the redundant source subgroup summary toggle.
- Goal: improve the expanded field index so it remains scroll-stable during lazy refreshes and clusters field item accordions under their nearest ACF parent group, such as `Hero`, `Intro`, or `Benefits`, without changing descriptor authority or save behavior.
- Exact source shape: all descriptors already eligible for the public field index; ACF-backed descriptors with `index.groupPath`, `index.parentFieldName`, `index.containerType`, `index.fieldGroupTitle`, or native-query metadata get section grouping, while non-ACF and ungrouped descriptors stay in an `Ungrouped` or existing owner/source bucket.
- Current code paths: `scheduleFieldIndexRefresh()`, `captureFieldIndexOpenState()`, `renderStatusBarMeta()`, `buildFieldIndexModel()`, `resolveFieldIndexSection()`, `resolveFieldIndexSubGroup()`, `resolveFieldIndexSubGroupKey()`, `renderFieldIndexSectionMarkup()`, `renderFieldIndexSubgroupMarkup()`, and `renderFieldIndexMarkup()` in `assets/js/overlay-app.js`; `.dbvc-ve-field-index`, `.dbvc-ve-field-index__group`, `.dbvc-ve-field-index__section`, `.dbvc-ve-field-index__subgroup`, and `.dbvc-ve-field-index__item-title` in `assets/css/overlay.css`; `EditableRegistry::exportPublicIndexSummary()` only if a later slice needs safe group-label metadata.
- Descriptor metadata: first implementation should use the existing shallow public fields only. Section keys should be deterministic from owner top group, entity identity, container root, immediate group path segment, and native-query ancestry where present. Section labels should prefer a safe group label when available, then a humanized immediate group field name, then a contextual fallback such as `Current Fields`, `Option Fields`, or `Ungrouped`.
- Optional metadata: add a shallow `groupLabelPath` or `sectionLabel` only if ACF field labels can be derived server-side without exposing field values, warnings, save payloads, nonces, or full descriptor objects.
- Resolver classification changes: none. Grouping must not influence editability, readonly status, owner scope, descriptor lookup, or mutation eligibility.
- Mutation contract requirements: none. `Locate` and `Open` continue to use the existing token-based action path, with no bulk actions, section-level saves, or inferred ACF writes.
- UI/badge/panel impact: preserve top-level owner/source groups and filters; add an intermediate visual section between each top-level group and the field item accordions; render section headings as compact unframed bands with counts; remove the redundant source subgroup summary/toggle layer; keep item summaries as single-row label/count rows; preserve existing section and item open-state behavior; capture and restore `.dbvc-ve-field-index.scrollTop` across passive refreshes.
- Intentional scroll resets: reset scroll when the user changes the filter, closes/reopens the review popover, or explicitly locates a marker.
- Save and rollback risk: low because this is client-side review UI only. Primary regression risk is hiding markers under unstable section keys or losing section/item open state. Rollback should be one JS/CSS slice; keep scroll-state preservation independently if validated.
- Live template/page examples: singular page with grouped current-owner ACF fields such as `Hero`, `Intro`, and `Benefits`; singular CPT with repeater/flexible descendants inside grouped fields; page with shared option fields; page with related post fields rendered through native relationship/post-object loops; taxonomy archive with current-term grouped fields; archive template with option-backed fields and archive loop descendants.
- Docs to update: this plan, `DBVC_VISUAL_EDITOR_PHASES.md`, `README.md` and `CHANGELOG.md` only after implementation ships, and `docs/qa/TEST_LOG.md` after browser/manual validation.
- Validation to run: scroll below the first viewport and trigger passive refreshes through descriptor open/prefetch; confirm scroll position is preserved; change filters and confirm scroll resets intentionally; expand/collapse sections and field items; confirm grouped counts equal row totals; confirm the redundant subgroup summary is absent; confirm item summary label/count stay on one row; confirm `Locate` and `Open` still work for cached and uncached descriptors; confirm marker surfacing, badge labels, panel load, descriptor payload, and save request behavior are unchanged.
- First-slice implementation notes: `renderStatusBarMeta()` now captures/restores `.dbvc-ve-field-index.scrollTop` across passive refreshes and intentionally resets scroll when the review surface opens or filters change. `buildFieldIndexModel()` now inserts a client-side `dbvc-ve-field-index__section` layer between top-level owner groups and field item accordions, using current public metadata first: immediate `groupPath` segment, then row container root, then field-group title/option page, then native loop label, then a general fallback. The follow-up cleanup keeps `dbvc-ve-field-index__subgroup` as a non-toggle structural wrapper, removes `.dbvc-ve-field-index__subgroup-summary`, and renders each `dbvc-ve-field-index__item-title` as a single-row label/count summary. No resolver, descriptor authority, save contract, or startup hydration behavior changed.
- Follow-up: add safe server-side group label metadata only if live template testing shows humanized ACF names are not clear enough.

Defer:
- text search
- virtualized lists
- persisted expanded/collapsed group state
- any bulk actions
