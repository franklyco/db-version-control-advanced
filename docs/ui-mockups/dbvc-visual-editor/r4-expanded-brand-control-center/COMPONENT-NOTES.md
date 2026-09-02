# R4 mockup — component notes

Naming: outer scope `.dbvc-ve-control-center-mockup` (mockup-only), component prefix `.dbvc-ve-control-center` (extends R3 verbatim), BEM-like elements/modifiers with `is-*` state classes. **No JS in this mockup** — rows and states are pre-rendered inline. `data-*` hooks that appear on rows mirror the production DOM shape and are safe.

Format matches R3's `../r3-brand-control-center/COMPONENT-NOTES.md` — per-selector mapping, `data-*` action vocabulary, invented-names table, "not wired in R4" list.

## 1. Component inventory (new + extended for R4)

R4 inherits every R3 selector. This table only lists what's NEW or MODIFIED in R4. See R3 COMPONENT-NOTES for the base selectors.

| Component | Element | Bound to | R4 role |
|---|---|---|---|
| `.dbvc-ve-control-center__view-toggle` | `<div role="tablist">` | derived from `viewMode` state | Header segmented control between the `title-block` and the `close` button. Two options: `By category` / `By provider`. `aria-label="Category view"`. |
| `.dbvc-ve-control-center__view-toggle-option` | `<button role="tab" aria-selected="…">` | one option | New. `data-view-mode="category" \| "provider"`. |
| `.dbvc-ve-control-center__view-toggle-icon` | `<span>` | inline SVG | New. `category` = grid glyph; `provider` = stack glyph. |
| `.dbvc-ve-control-center__search-wrap` | `<div>` | search state | New wrapper in the filter strip so the search bar can carry a leading icon + clear button. |
| `.dbvc-ve-control-center__search` | `<input type="search" maxlength="100">` | `query.search` | Extended from R3. `placeholder` now covers labels+descriptions+owner+categories. |
| `.dbvc-ve-control-center__search-icon` | `<span>` | — | Leading search glyph, inline SVG. |
| `.dbvc-ve-control-center__search-clear` | `<button>` | derived | Visible only when search has a value. Clears + refocuses input. |
| `.dbvc-ve-control-center__group` | `<tbody>` (in a `<table>`) OR `<section>` (in a virtualized list layout) | one `record.group` key | New. Contains a group-header row + collapsible member rows. R4 mockup uses `<tbody>` per group so the sticky `<thead>` can stay one instance. |
| `.dbvc-ve-control-center__group-header` | `<tr class="dbvc-ve-control-center__group-header">` | one group | New. Colspan header row spanning both `Control` and `Action` columns. Contains a disclosure button + group title + row count. `aria-expanded` on the disclosure button reflects state. |
| `.dbvc-ve-control-center__group-toggle` | `<button aria-expanded="…">` | disclosure state | New. `data-dbvc-ve-control-center-action="toggle-group"` + `data-group-key="{providerId}::{group}"`. |
| `.dbvc-ve-control-center__group-title` | `<span>` | `record.group` | New. |
| `.dbvc-ve-control-center__group-count` | `<span>` | derived | New. `N controls` badge inside the header. |
| `.dbvc-ve-control-center__row` (extended) | `<tr>` | one `items[]` record | R3 base. R4 adds new `is-focused-source`, `is-open-error`, `is-descriptor-loading`, `is-permission-filtered` modifiers — mockup uses them in states.html. `is-permission-filtered` renders the row muted with a lock glyph in place of the value summary. |
| `.dbvc-ve-control-center__label-block` | `<div>` | — | New wrapper around `__label` + optional `__description` so they stack cleanly under `__status-dot`. |
| `.dbvc-ve-control-center__description` | `<p>` | `record.meta.description` | New. Muted second line under the label. `line-clamp: 2`. Only rendered when the record carries a description; the DOM node is omitted otherwise (not an empty `<p>`). |
| `.dbvc-ve-control-center__value-summary` | `<div>` | `record.meta.currentValueSummary` | New. Right-side cell content in the `--action` column, positioned above the Open button. Contains one of the family-specific sub-components below. Not clickable in R4 (edit still routes through the panel). |
| `.dbvc-ve-control-center__value-text` | `<span>` | text-family summary | Truncated text + char-count. |
| `.dbvc-ve-control-center__value-image` | `<span>` (contains `<img>` + `<span>`) | image summary | 24×24 rounded thumb + filename. `img.loading="lazy"`. |
| `.dbvc-ve-control-center__value-gallery` | `<span>` | gallery summary | Up to 3 24×24 thumbs + `+N` badge for overflow. |
| `.dbvc-ve-control-center__value-relationship` | `<span>` | relationship / post_object summary | `"N connected"` text + `title=""` tooltip with first-3 titles as plain-text (comma-separated, no HTML). |
| `.dbvc-ve-control-center__value-color` | `<span>` (contains `<span class="__value-color-swatch">` + `<span>`) | color_picker summary | 12×12 swatch + hex code. |
| `.dbvc-ve-control-center__value-wysiwyg` | `<span>` | wysiwyg summary | Stripped-text preview + word count. |
| `.dbvc-ve-control-center__value-empty` | `<span>` | empty value | Muted em-dash + `sr-only` "no value set". |
| `.dbvc-ve-control-center__value-locked` | `<span>` | `is-permission-filtered` | Lock glyph + `sr-only` "you don't have permission to view this control's value". |
| `.dbvc-ve-control-center__notice--provider-error` | `<div role="status">` | one entry per errored provider | New. Compact notice at the top of the table body, above the first group header. Text: "Some controls could not be loaded — {providerId} reported an error." Provider id is opaque. Dismiss button. |
| `.dbvc-ve-control-center__row-notice--descriptor-loading` | `<tr>` | `is-descriptor-loading` row | New. Row-scoped inline notice while `POST /control-center/open` is in flight. Shows a spinner + "Loading control…". |
| `.dbvc-ve-control-center__save-status-strip` | `<div>` | derived from Save event on the main panel | New. Thin strip at the top of the drawer body when the main panel just fired a `save-and-reload`. Shows "Saved {label}" briefly, then fades. Reuses the existing polite live region (no second `role="status"` created). |

## 2. Data attributes (action delegation vocabulary)

Matches the R3 pattern (`data-dbvc-ve-<component>-action`).

| Attribute | Values used | Handler |
|---|---|---|
| `data-dbvc-ve-toolbar-action` | `open-control-center` (unchanged from R3) | overlay-app.js toolbar click delegate |
| `data-dbvc-ve-control-center-action` | R3 values (`open`, `dismiss-notice`, `clear-filters`, `close`, `retry`) + R4 additions (`toggle-group`, `set-view-mode`, `clear-search`, `dismiss-provider-error`) | brand-control-center-app.js click delegate |
| `data-view-mode` | `category` \| `provider` | Toggle option identity |
| `data-group-key` | `{providerId}::{group}` | Group-header disclosure identity + `localStorage` persistence key |
| `data-public-id` | one `items[].publicId` | Sole client-authoritative token. Unchanged from R3. |
| `data-category` | one category slug or `all` | Tab identity + row filter identity |
| `data-status` / `data-priority` / `data-field-family` / `data-provider-id` | one `items[]` field | Row filter identity (R3 base + new `data-provider-id` for the provider-partitioned view) |

**Explicitly absent** (schematic §6 invariant 2, carried from R3): no `data-owner-id`, `data-field-key`, `data-selector`, `data-path`, `data-descriptor`, `data-token`. R4 adds no new forbidden attributes — value summaries are pre-computed, escaped strings only, not raw target references.

## 3. Per-family value-summary rendering contract

The mockup's `__value-summary` slot renders one of the following per family. Production R4-C's renderer must:

1. Read `record.meta.currentValueSummary` (an opaque server-computed object, per-family shape).
2. Dispatch on `record.fieldFamily` to select the appropriate sub-renderer.
3. Escape every text field (labels, filenames, hex codes, tooltip content) with `textContent` — never `innerHTML`.
4. For image / gallery thumbs, only trust `attachmentId` and derive URL via `wp.media.attachment(id)` or a server-signed URL — never accept a URL directly from the response.
5. If the current viewer lacks capability for the record's owner, replace the value summary with `.dbvc-ve-control-center__value-locked` and drop any thumbnail hydration.

### Per-family shape reference

| `record.fieldFamily` | `record.meta.currentValueSummary` shape | Rendered as |
|---|---|---|
| `text` | `{ preview: string, charCount: number, truncated: bool }` | `<span class="__value-text">{{preview}}{{truncated ? " ("+charCount+")" : ""}}</span>` |
| `image` | `{ attachmentId: number, filename: string, thumbUrl: string }` | `<img loading="lazy" src="{{thumbUrl}}" alt="" width="24" height="24"> {{filename}}` |
| `gallery` | `{ items: [{attachmentId, thumbUrl}], overflow: number }` | up to 3 thumbs + `+{{overflow}}` badge |
| `relationship` / `post_object` | `{ count: number, firstTitles: [string, string, string] }` | `{{count}} connected` with `title=""` = `firstTitles.join(', ')` |
| `color_picker` | `{ hex: "#rrggbb", label: string \| null }` | swatch + hex |
| `wysiwyg` | `{ preview: string, wordCount: number }` | `{{preview}} · {{wordCount}} words` |
| `other` | *none* | *no chip* |
| *empty value on a supported family* | `{ empty: true }` | `<span class="__value-empty">—<span class="__sr-only">no value set</span></span>` |

## 4. State machine (client-side)

```
                     ┌─────────────────┐
                     │  loading-init   │
                     └────────┬────────┘
                              │ 200 / items
                              ▼
                     ┌─────────────────┐     ┌──────────────┐
                     │      list       │────▶│    error     │
                     └────────┬────────┘     └──────┬───────┘
                              │                     │ retry
      ┌───────────────────────┼─────────────────────┘
      │ filter/tab/search      │ set-view-mode
      ▼                        ▼
┌─────────────┐         ┌───────────────┐
│loading-     │         │  list         │
│  refresh    │────────▶│  (folded)     │
└──────┬──────┘         └───────┬───────┘
       │ 200 / items            │ set-view-mode
       ▼                        ▼
┌─────────────┐         ┌───────────────┐
│ empty-      │         │  list         │
│  filtered   │         │  (provider)   │
└─────────────┘         └───────┬───────┘
                                │ row Open click
                                ▼
                        ┌───────────────┐
                        │descriptor-    │  (row-scoped, aria-busy, is-opening)
                        │  loading      │
                        └───────┬───────┘
                       ┌────────┴────────┐
              open ok  │                 │  open-error (404/403/409)
                       ▼                 ▼
              ┌───────────────┐   ┌──────────────────┐
              │    opened     │   │ row inline notice│
              │(panel+drawer  │   │  + Dismiss        │
              │   coexist)    │   └──────────────────┘
              └───────┬───────┘
                      │ save (main panel)
                      ▼
              ┌───────────────┐
              │save-and-reload│  (drawer strip briefly shows "Saved {label}")
              └───────────────┘
```

**New states R4 adds vs R3**:
- `loading-refresh` may be triggered by `set-view-mode` (folded ↔ provider), not just by filter/tab/search.
- `descriptor-loading` renamed from R3's `opening` for release-doc alignment (§states); behaviorally identical.
- `save-and-reload` is new. Fires when the main editor panel dispatches its own `dbvc:visual-editor:panel:saved` event (or equivalent) — the drawer listens and briefly shows a confirmation strip. Reuses R3's single polite live region for the announcement.

**Provider-error state** (per-provider, not full-drawer):
- Fires when the R3-A registry rejects a provider's records or a provider throws.
- Notice renders at the top of the row list with the provider id.
- Other providers' rows continue to render normally.
- Dismissible via `data-dbvc-ve-control-center-action="dismiss-provider-error"`. Reappears on next drawer open.

**Permission-filtered state** (row-scoped):
- A row whose `visibleTo()` closure returns false is NOT rendered at all (matches R3's visibility contract — omitted, not rendered disabled).
- The `is-permission-filtered` modifier + `__value-locked` cell shown here in the mockup is only for the case where the row IS visible but the current-viewer LOST capability between list load and hover (rare edge case; production may not need this state if list is always freshly authorized).

## 5. Interactions preserved from R3

| Pattern | Source | R4 reuse |
|---|---|---|
| Drawer form (D-061) | R3-BRAND-CONTROL-CENTER-WIRING-SCHEMATIC §5.1 | Unchanged. R4 does not resize, reposition, or add a backdrop. |
| Single polite live region | Component-map §6 | Reused for all R4 announcements including `save-and-reload`. Do NOT add a second. |
| Row-focus continuity across rerenders | Media Manager `activeElement` snapshot | Extended to survive `set-view-mode` transitions — when a viewer flips folded ↔ provider, focus lands on the same `publicId` row's Open button in the new layout. |
| Reduced-motion suppresses transitions | Component map §7 | Reused for R4's group-header disclosure transitions + view-mode-toggle segmented-control transitions. |
| Client-side filtering (R3-C-2 pinned decision #3) | R3 drawer | R4 keeps client-side by default (up to ~1,000 rows measured OK); server-side search flip is R4-A's call. |
| Absorb-descriptor event bridge (R3-C-2 pinned decision #1) | overlay-app.js `bindControlCenterBridge` | Reused verbatim; R4 does not change the event contract. |

## 6. Not wired in R4 (mockup honesty)

- **No real REST calls.** Every value summary, description, and category-count in the mockup is fabricated inline markup.
- **No JavaScript at all.** The view-mode toggle, group-header disclosures, filter chips, and search input are all static DOM — they do not react to clicks. Production R4-C wires them.
- **No main editor panel.** The wireframe stand-in in states.html state "coexisting-panel" is orientation only; production reuses the existing `.dbvc-ve-panel`.
- **No Shared Globals popover modifications.** Rendered untouched by this mockup.
- **No cross-page state.** No localStorage actually written, no URL hash actually persisted.
- **No IntersectionObserver hydration.** All visible-viewport rows in the mockup are pre-populated with their value summaries; production lazy-hydrates below the fold.
- **No mobile / tablet / touch layout.** D-058.
- **No PNG screenshot capture.** SVG placeholders in the mockup are the visual reference; PNG capture is out of scope.
- **No R5 features.** color_picker rows show a value summary but the Open button is disabled with `Unsupported` tag — matches VerticalControlProvider's current MVP state.

## 7. Invented names table (Codex validation targets)

Also captured in `DESIGN-DECISIONS.md` §7 (view-mode toggle). Consolidated here for reviewers who read component notes first.

| Name | Kind | Where introduced | Status |
|---|---|---|---|
| `view-mode-toggle` | Header segmented control | Drawer header, between summary chip and close button | **Proposed.** Confirmed by maintainer (2026-08-29). |
| `by-category` / `by-provider` | View mode identifiers | `data-view-mode` on toggle options + `localStorage` key | Proposed. |
| `dbvc.ve.control-center.view-mode` | localStorage key | Persists the view-mode preference per viewer/origin | Proposed. |
| `dbvc.ve.control-center.groups.<groupKey>` | localStorage key | Persists group-header collapsed state per viewer | Proposed. R4-C should decide the exact key format (per-provider-group vs per-category-group). |
| `record.meta.description` | New optional ControlRecord field | Emitted by providers per record; renders as `__description` line | R4-A. |
| `record.meta.currentValueSummary` | New ControlRecord field per family | Emitted by the R4-A value-summary factory; renders as `__value-summary` chip | R4-A. |
| `record.meta.sortKey` | New optional ControlRecord field | Provider-defined stable sort key; falls back to alpha `label` | R4-A. |
| `.dbvc-ve-control-center__group` / `__group-header` / `__group-toggle` / `__group-title` / `__group-count` | BEM selectors | Collapsible group headers | R4-C. |
| `.dbvc-ve-control-center__value-*` | BEM selector family | Per-family value summaries | R4-C. |
| `.dbvc-ve-control-center__view-toggle*` | BEM selector family | Header segmented control | R4-C. |
| `.dbvc-ve-control-center__notice--provider-error` | BEM selector | Provider-scoped error notice | R4-C. |
| `.dbvc-ve-control-center__save-status-strip` | BEM selector | Save confirmation strip in drawer body | R4-C. |
| `toggle-group` / `set-view-mode` / `clear-search` / `dismiss-provider-error` | R4 action vocabulary | `data-dbvc-ve-control-center-action` values | R4-C. |
| `dbvc:visual-editor:panel:saved` | Optional new document event | Fired by overlay-app.js main panel on successful save so the drawer can render the `save-and-reload` strip | R4-C or R4-D (depending on whether overlay-app.js already dispatches an equivalent event). |
