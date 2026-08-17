# Media Manager Table and Row Interaction Specification

## Design intent

The Media Manager should optimize repeated remediation work while retaining clear source, status, and safety boundaries. It should feel like a focused frontend operations panel, not a WordPress admin list table copied over the website.

See `../ui-ux/reference-images/04-media-manager-initial-concept.png` for initial visual direction only.

## Panel behavior

Preferred desktop behavior:

- large centered panel or wide drawer over the live site;
- maximum width/height clamped to viewport;
- internal scroll region;
- sticky panel header, filters, and table column header where feasible;
- site remains visible in the background;
- current Visual Editor toolbar remains usable only where layering/focus rules permit.

Small screens — **tabled by D-036 for the current laptop/desktop-priority implementation:**

- preserve the working slice-1 narrow-width regression floor;
- do not implement a new full-height slide-over, responsive-card conversion, touch refinement, or mobile-specific interaction in the current R1 slices;
- revisit the equivalent-information requirement only after explicit responsive/mobile reauthorization.

The implementation choice must follow current Visual Editor panel/dialog conventions.

## Header

Include:

- `Media Manager` title;
- short purpose text;
- scan summary counts;
- scan state/progress;
- close action;
- optional help/source detail entry if current patterns support it.

Do not show a `Publish` action unless such a concept actually exists in the Visual Editor. The generated concept image is not authoritative.

## Filter toolbar

Recommended controls:

- entity search;
- entity family tabs/filter;
- field family filter;
- published/live scope label or non-editable indicator;
- refresh scan;
- clear filters when active.

Filters should not cause the entire page to reload.

## Bulk toolbar

Allowed in R1:

- select all visible only if selection has a read-only or expansion purpose;
- expand/collapse visible rows;
- refresh selected findings, if directly useful and safe.

Allowed in R2:

- no same-entity `Save Row` in initial R2; each supported field saves independently.

Deferred:

- cross-entity `Save selected`;
- apply one attachment to many entities;
- automatic fill;
- bulk clear/delete.

If a disabled `Save selected` appears in the concept image, omit it from production unless a release explicitly implements it. Do not ship misleading disabled controls merely to match a mockup.

## Table columns

Recommended desktop columns:

| Column | Purpose |
|---|---|
| Expand/select | Explicit row interaction |
| Entity | Safe title/name and icon |
| Type | Page/post/CPT/taxonomy label |
| Location | Permitted frontend route or context |
| Missing fields | Count and optional family summary |
| Updated/scanned | Safe freshness context |
| Actions | Expand, open, refresh finding |

Columns may be adapted to actual data and viewport constraints. Do not expose raw owner IDs or field keys.

## Row states

- collapsed;
- expanded and loading;
- expanded with writable findings;
- expanded with mixed writable/inspect-only findings;
- no longer has findings;
- stale/changed;
- permission unavailable;
- entity deleted/unpublished;
- row-level save in progress;
- row-level partial failure.

## Expansion behavior

- Use a real button with `aria-expanded` and `aria-controls` or equivalent current pattern.
- Only one expanded row at a time is acceptable if it materially improves performance; multiple expanded rows are acceptable if current state management handles them safely.
- Preserve the user’s scroll position when a row loads or resolves.
- Expansion triggers fresh server hydration.
- Do not render every field editor for every collapsed row.

## Nested field rows

Each field row should display:

- field label;
- family/source label;
- optional parent group/layout context;
- current empty status;
- preview/placeholder;
- choose/manage action;
- upload availability through the native modal;
- unsaved/saved/error state;
- save action according to current interaction design.

### Featured image

- Single image selection.
- Label as native featured image.
- Use current featured-image descriptor and mutation contract.

### ACF image

- Single image selection.
- Show friendly ACF label.
- A technical field name may appear only in privileged source details if current Visual Editor already does so; it is never an action target.

### ACF gallery

- Multiple ordered image selection.
- `Manage Gallery` opens the current gallery media-frame workflow.
- Preserve order and validate all attachment IDs.
- Do not silently add unrelated previously hidden values; current value must be freshly reloaded before save.

## Draft selection state

After media is selected but before save:

- show a thumbnail or count preview;
- label the state as unsaved;
- allow replace/change;
- allow discard for that field;
- do not update scan counters yet;
- do not imply the frontend has been published.

## Save controls

Preferred safest sequence:

1. Field-level save is always available for a writable dirty field.
2. Each dirty field is saved independently in initial R2.
3. Cross-entity save remains absent.

If current main panel is the only safe editor, R2 may open each field in that panel rather than embedding full controls in the table. The product goal is direct remediation without page navigation; exact editor placement should adapt to current architecture.

## Resolved behavior

After targeted verification:

- remove the resolved field from the expanded list or mark it resolved briefly before removal;
- decrement counts;
- remove the entity row when no findings remain;
- preserve current table scroll/filter state;
- announce the update for assistive technology;
- update the currently loaded Media Manager table without a browser-page or table reload.

When the edited entity is also the current frontend page, rendered content outside the Media Manager may still use the existing Visual Editor reload fallback if no proven DOM projection can be patched safely. That fallback must not discard or reload the Media Manager table state.

## Media Library layering

- Opening the core media modal must not close the Media Manager.
- Outside-click handlers are suspended while the modal is active.
- Focus returns to the initiating field action.
- The Media Manager retains draft selections and expanded state.
- Escape should close the topmost media modal before the underlying Media Manager.

## Keyboard and accessibility

- Semantic table for the current laptop/desktop workflow; responsive-card semantics are tabled with D-036.
- Visible focus for every control.
- Expansion button is keyboard-operable.
- Status is expressed in text/icon, not color alone.
- Sticky regions do not obscure focused controls.
- Loading changes use appropriate live-region announcements without excessive noise.
- Error messages are associated with the field or row.
- Touch targets follow current Visual Editor minimums.

## Empty and error states

Required states:

- no scan yet;
- scan in progress;
- no missing assignments found;
- no results for filters/search;
- recoverable scan failure;
- provider/ACF unavailable;
- snapshot expired;
- row changed since scan;
- no upload capability;
- unsupported nested field;
- field save failed;
- row save partially failed.

## Table acceptance criteria

- The panel remains responsive at representative large result counts.
- Complete results are navigable without rendering all rows simultaneously.
- Headers and controls remain understandable while scrolling.
- Expanded rows hydrate lazily and revalidate state.
- Field selections are clearly unsaved until confirmed.
- Native Media Library layering and focus behavior work.
- No cross-entity bulk mutation is implied.
- The table remains usable by keyboard and assistive technology at supported laptop/desktop viewports.
- Touch-specific optimization and mobile table/card behavior remain tabled by D-036.
