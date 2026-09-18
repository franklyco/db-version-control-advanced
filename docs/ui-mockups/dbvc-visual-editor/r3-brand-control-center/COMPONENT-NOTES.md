# Component notes &mdash; R3 Global Brand Control Center mockup

Naming: outer scope `.dbvc-ve-control-center-mockup`, component prefix `dbvc-ve-control-center`, BEM-like elements/modifiers, `is-*` state classes. **No JS in this mockup** &mdash; rows are pre-rendered inline; `data-*` hooks that appear on rows (`data-public-id`, `data-category`, `data-status`, `data-priority`, `data-field-family`) mirror the production DOM shape and are safe.

Format follows the R1 Media Manager `COMPONENT-NOTES.md` &mdash; per-selector mapping, bindings, data-attribute vocabulary, invented names table, "not wired in R3" list.

---

## 1. Component inventory

| Component | Element | Bound to (fixture path) | Notes |
|---|---|---|---|
| `.dbvc-ve-control-center` | `<aside role="complementary">` | root of one drawer instance | Left-anchored, ~480px, backdrop-less. Pre-rendered inline per state; no JS binding. |
| `.dbvc-ve-control-center__header` | `<header>` | &mdash; | icon + title + summary chip + close |
| `.dbvc-ve-control-center__header-icon` | `<span>` | &mdash; | inline SVG (sliders glyph matching the toolbar entry) |
| `.dbvc-ve-control-center__title` | `<h2>` | fixture title string | text "Global Brand Controls" (maintainer-picked). |
| `.dbvc-ve-control-center__summary-chip` | `<span>` | `registered_count` + `available_count` | display-only summary of registry size + how many are `available` |
| `.dbvc-ve-control-center__close` | `<button>` | &mdash; | `aria-label="Close Brand Control Center"`. Escape-equivalent |
| `.dbvc-ve-control-center__tabs` | `<div role="tablist">` | `categories[]` from list response | 8 tabs at 480px: All + 7 categories. Horizontally scrollable |
| `.dbvc-ve-control-center__tab` | `<button role="tab">` | one `categories[]` entry | `aria-selected` reflects active tab. `data-category` carries the slug. Optional trailing count `<span>` for the category size |
| `.dbvc-ve-control-center__tab-count` | `<span>` | `categories[].count` | Rendered outside a strict server contract &mdash; may be omitted per the schematic §3.1 note that `categories` are optional in R3 |
| `.dbvc-ve-control-center__filters` | `<div>` | current query filters | search input + chip rows |
| `.dbvc-ve-control-center__search` | `<input type="search" maxlength="100">` | `query.search` | 100-char cap matches the R1/R2 convention |
| `.dbvc-ve-control-center__chip-row` | `<div role="group">` | one filter axis | grouped labelled set: `Status`, `Priority`, `Field` |
| `.dbvc-ve-control-center__chip` | `<button aria-pressed>` | one filter value | `data-value` is the value on the wire. In the mockup the chip is visual-only; production wires a click handler that toggles the filter. |
| `.dbvc-ve-control-center__clear-filters` | `<button>` | derived `has_active_filters` | Hidden when no filter is active |
| `.dbvc-ve-control-center__table-wrap` | `<div>` | scrollable region | drawer body is the scroll container; header stays pinned |
| `.dbvc-ve-control-center__table` | `<table>` | `items[]` | semantic table; the row anatomy from schematic §5 |
| `.dbvc-ve-control-center__thead` | `<thead>` | column labels | `position: sticky; top: 0` |
| `.dbvc-ve-control-center__tbody` | `<tbody>` | `items[]` | **Pre-rendered inline** in `index.html` and each `states.html` cell. Production translation is the same DOM shape, populated server-side by the R3-C list route. |
| `.dbvc-ve-control-center__row` + `is-<status>` | `<tr>` | one `items[]` record | modifier reflects `record.status`. `data-public-id` is the only client-authoritative token |
| `.dbvc-ve-control-center__row.is-focused-source` | modifier | client-only | Row that opened the current panel; restored on rerender for focus continuity |
| `.dbvc-ve-control-center__status-dot` + `--<status>` | `<span>` | `record.status` | 8px circle. Colored via `--dbvc-ve-color-success/info/warning-strong/--empty`. Always accompanied by a text label |
| `.dbvc-ve-control-center__label` | `<span>` | `record.label` | primary label text |
| `.dbvc-ve-control-center__meta` | `<div>` | derived | `category · group · [Shared Global badge]` |
| `.dbvc-ve-control-center__meta-part` | `<span>` | one meta chunk | `::before` renders a middle-dot separator except on first-child |
| `.dbvc-ve-control-center__badge` | `<span>` | `record.meta.badge` | Uses `--dbvc-ve-color-shared*`. Verbatim label "Shared Global" |
| `.dbvc-ve-control-center__owner` | `<div>` | `record.ownerType/ownerSubtype · fieldFamily` | Tiny muted hint; disambiguates same-labelled controls from different providers |
| `.dbvc-ve-control-center__action` | `<button>` | `record.status === 'available'` | Primary orange. `data-dbvc-ve-control-center-action="open"` + `data-public-id="<publicId>"` |
| `.dbvc-ve-control-center__action--view` | modifier | `record.status === 'inspect_only'` | Secondary bordered. Same action + publicId contract |
| `.dbvc-ve-control-center__action--opening` | modifier | client state | Row's action swap during open request; `aria-busy="true"`, disabled |
| `.dbvc-ve-control-center__action-none` | `<span>` | `unsupported` or `unavailable` | Uppercase status word, no button. Row stays visible; **never rendered as disabled** (component map §12 rule 6) |
| `.dbvc-ve-control-center__spinner` | `<span>` | client state | 10px circle border, `dbvc-ve-cc-spin` keyframe; suppressed under reduced-motion |
| `.dbvc-ve-control-center__row-notice` | `<tr>` | open-error state | Inline error notice as a table row below the affected `.dbvc-ve-control-center__row` |
| `.dbvc-ve-control-center__notice` + `is-error/warning` | `<div role="alert">` | notice text | Uses existing `--dbvc-ve-color-error*/info*/warning*` tokens |
| `.dbvc-ve-control-center__notice-dismiss` | `<button>` | `data-dbvc-ve-control-center-action="dismiss-notice"` + `data-public-id` | Dismisses the inline notice only; the row stays |
| `.dbvc-ve-control-center__panel-state` | `<div>` | `state ∈ {loading-initial, empty, empty-filtered, error, empty-tab}` | Full-panel replacement of the table body |
| `.dbvc-ve-control-center__panel-state-title` | `<p>` | state title | plain-language explanation |
| `.dbvc-ve-control-center__panel-state-body` | `<p>` | state body | plain-language *why* |
| `.dbvc-ve-control-center__panel-state-actions` | `<div>` | optional | Retry / Clear filters |
| `.dbvc-ve-control-center__button` + `--secondary` | `<button>` | panel-state actions | Reuses primary/secondary conventions (component map §4.2) |
| `.dbvc-ve-control-center__loading-spinner` | `<div>` | loading states | Larger spinner (22px) for the full-panel loading state |
| `.dbvc-ve-control-center__refresh-overlay` | `<div>` | loading-refresh | Absolute-positioned dimmed overlay inside `__table-wrap` |
| `.dbvc-ve-control-center__footer` | `<footer>` | derived | `<visible> of <total> controls · <hidden> hidden by filters` |
| `.dbvc-ve-control-center__sr-only` | utility | announcer + hidden labels | New; overlay.css ships no visually-hidden helper |
| `[role="status"][aria-live="polite"]` | `<p>` | derived | Shared polite live region; reuses the single-announcer pattern (component map §6) &mdash; do NOT add a second in production. In this static mockup the region carries a fixed opening announcement; production populates it in response to real events. |

---

## 2. Data attributes (action delegation vocabulary)

Matches the pattern in the component map §8 (`data-dbvc-ve-<component>-action`).

| Attribute | Values used | Handler |
|---|---|---|
| `data-dbvc-ve-toolbar-action` | `open-control-center` (mockup adds this) | toolbar click delegate; toggles drawer visibility. Existing values (`open-shared-globals`, `open-object-search`, `open-media-manager`, `go-to-object`, `toggle-mode`) are unchanged |
| `data-dbvc-ve-control-center-action` | `open`, `dismiss-notice`, `clear-filters`, `clear-filters-inline`, `close`, `retry` | drawer click delegate |
| `data-public-id` | one `items[].publicId` | Sole client-authoritative token. Namespaced `providerId:localId` (§6 invariant 1) |
| `data-category` | one `categories[].id` value or `all` | tab identity |
| `data-status` / `data-priority` / `data-field-family` | one `items[]` field | row identity for filter matching |
| (no `data-mockup-*` attributes in this drop) | &mdash; | The previous draft used them to drive the JS renderer. Rows are now pre-rendered inline; the runtime attributes above are the only per-row data attributes present. |

**Explicitly absent** (schematic §6 invariant 2): no `data-owner-id`, `data-field-key`, `data-selector`, `data-path`, `data-descriptor`, `data-token`.

---

## 3. Fixture reconciliation

**No curation JSON existed at authorship time.** The fixture is hand-authored per schematic §9.2 (33 controls across all 7 categories, all statuses, all priorities, all field families).

Fixture shape mirrors §3.1 exactly for the fields the mockup renders. **Fields that would need maintainer confirmation before landing on the list route:**

- `priority` &mdash; used by the priority filter chip (schematic §5.1.2). Not in the §3.1 sample response. Would need to be either (a) a first-class field on `items[]`, or (b) nested under `meta`, or (c) not exposed &mdash; in which case the priority filter is removed.
- `inputHint` &mdash; mockup-only display key. Production can drop it in favor of `meta.icon` or `fieldFamily`.

When the R3-BX curation JSON does land, the mapping table in schematic §9.1 covers the translation. The mockup does not need to be regenerated to switch data sources &mdash; only the source of the `items[]` array changes.

---

## 4. State machine (client-side)

```
                   ┌────────────────┐
                   │  loading-init  │  (first open)
                   └───────┬────────┘
                           │ 200 / items
                           ▼
                   ┌────────────────┐          ┌──────────────┐
                   │      list      │◀────────▶│    error     │
                   └───────┬────────┘          └──────┬───────┘
                           │ filter / tab              │ retry
                           ▼                           │
                   ┌────────────────┐                  │
                   │ loading-refresh│──────────────────┘
                   └───────┬────────┘
                           │ 200 / items
              ┌────────────┼────────────┐
              ▼            ▼            ▼
      ┌────────────┐ ┌────────────┐ ┌────────────┐
      │ empty      │ │ empty      │ │ list       │
      │  registry  │ │ filtered   │ │ (filtered) │
      └────────────┘ └────────────┘ └─────┬──────┘
                                          │ row Open click
                                          ▼
                                   ┌────────────┐
                                   │  opening   │  (row-scoped, aria-busy)
                                   └─────┬──────┘
                            ┌────────────┴────────────┐
                    open ok │                         │ open-error (403/404/409)
                            ▼                         ▼
                   ┌────────────────┐          ┌─────────────────┐
                   │    opened      │          │ inline row      │
                   │ (panel + drawer│          │ notice + Dismiss│
                   │  coexist)      │          └─────────────────┘
                   └────────────────┘
```

Notes:
- **Opening** is a per-row state, not a full-drawer state. Other rows remain interactive.
- **Opened** is a coexistence state &mdash; the drawer stays visible; the panel is the authority.
- **Open-error** does NOT block the rest of the drawer. Only the affected row's action reverts and the inline notice appears.
- **Filter / tab changes** always route through `loading-refresh` in production (the list is server-owned). The mockup skips the network hop.

---

## 5. Interactions preserved from existing components

| Pattern | Source | Reuse in R3 |
|---|---|---|
| Toolbar popover Escape + return-focus | `openStatusBarToolbarPopover` | Drawer close matches |
| Row-focus continuity across rerenders | Media Manager `activeElement` snapshot | Row `is-focused-source` modifier + tab focus restore |
| Single polite `aria-live` region per shell | Component map §6 | Reused; do NOT add another |
| Assertive `role="alert"` for open errors | R2-D notice `kind: 'error'` | Inline notice in row-notice row |
| `--dbvc-ve-color-*` tokens for all colors | overlay.css | Every color routes through a token |
| Reduced-motion suppresses transitions | Component map §7 | `@media (prefers-reduced-motion: reduce)` covers drawer slide + spinner rotate |

---

## 6. Not wired in R3 (mockup honesty)

- **No real REST calls.** Nothing in this mockup fires a request. Production wires the two routes in schematic §1.
- **No JavaScript at all.** The previous draft's `mockup.js` was replaced with pre-rendered inline `<tr>` markup. The tabs, filter chips, and Open buttons are static &mdash; they exist as the DOM shape production will emit but do not react to clicks.
- **No main editor panel.** The wireframe stand-in in state 08 is orientation only. Production reuses the existing `.dbvc-ve-panel`.
- **No Shared Globals popover modifications.** Rendered untouched in `index.html`'s toolbar.
- **No cross-page state.** No localStorage, no URL hash actually written (schematic §5.1.3 mentions `#brand-controls/design` but the mockup does not persist it).
- **No mobile / tablet / touch layout.** D-058.
- **No PNG capture rig, no PNG queue.** Wireframe SVG placeholders in `screenshots/` are the visual reference; PNG capture is not planned. See `screenshots/README.md`.

---

## 7. Invented names table (Codex validation targets)

Also listed in `DESIGN-DECISIONS.md` §10. Consolidated here for the reviewer who reads component notes first.

| Name | Kind | Where introduced | Status |
|---|---|---|---|
| `sliders` | Icon-registry key | Toolbar and drawer header icon | **Landed.** Registered in `overlay-app.js` `createToolbarButtonMarkup` icon table. No further validation needed. |
| `open-control-center` | Toolbar action | `data-dbvc-ve-toolbar-action` | Needs handler in the toolbar click delegate. |
| `open` / `dismiss-notice` / `clear-filters` / `clear-filters-inline` / `retry` | R3 row/notice/panel actions | `data-dbvc-ve-control-center-action` | New action vocabulary; matches the `.dbvc-ve-media-manager` pattern. |
| `.dbvc-ve-control-center` root + all `__*` element classes | BEM | schematic-proposed, matches convention | Proposed. |
| `.dbvc-ve-control-center__row.is-focused-source` | Modifier | Row that opened the currently-open panel | **Kept.** Mirrors the Media Manager focus-continuity pattern (component map §10 &mdash; `body.ownerDocument.activeElement` snapshot). The Media Manager restores focus to the row a user was on across list rerenders; `is-focused-source` extends the same idea one step further by keeping the "opened from here" visual cue on that row while the main editor panel is up. Does not collide with any existing selector (`grep -r 'is-focused-source' addons/visual-editor` returns zero). Codex can rename it (`is-open-source`, `is-source`, etc.) if a different name reads better in the production codebase &mdash; the model is unchanged. |
| `--dbvc-ve-z-drawer` (120015) | Token | The one net-new token; added to `overlay.css :root` by a separate PR | Proposed. |
| `priority` on rows | First-class field on `items[]` | Pre-rendered rows + priority filter chip | Maintainer's confirmed working assumption is first-class. Kept in the mockup as-is. If maintainer later moves it under `meta` or drops it, the mockup adjusts in a follow-up. |
