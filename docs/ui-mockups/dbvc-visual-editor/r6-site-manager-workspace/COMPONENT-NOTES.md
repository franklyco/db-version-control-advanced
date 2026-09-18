# R6 Site Manager workspace — component notes (final, D4 2026-09-15)

Per-selector mapping for the drawer in `index.html`. **Production action names** in the "Actions" column are the contract names from `releases/R6-WORKSPACE-STATE-CONTRACT.md` §3.1 — they are **not wired** here; the static files carry `data-mockup-*` only. Display data columns name the keys of `ui-ux/fixtures/workspace-r6b-view-model.json`.

Format follows `../r4-expanded-brand-control-center/COMPONENT-NOTES.md`.

## Root — `.dbvc-ve-workspace`

| | |
|---|---|
| Purpose | Persistent left-anchored navigation + tool-routing rail; a `role="complementary"` landmark (not a dialog). |
| Display data | none directly |
| Actions | — (container). Escape handled per contract §6 precedence. |
| Layout | fixed, `top: 32px` (admin bar), `bottom: 76px` (toolbar strip), width 480px, internal scroll only in `__body`. z `--dbvc-ve-z-workspace` (120005). |
| Accessibility | `aria-labelledby` → `__title`; no focus trap; outside click never closes. |
| States | default (open) · `.is-closed` (translateX −100%, pointer-events none) · `.is-inert` (S-11/S-12, paired with `inert` + `aria-hidden` in production) · reduced motion: `transition: none`. |

## Header — `__header`, `__header-icon`, `__eyebrow`, `__title`, `__close`

| | |
|---|---|
| Purpose | Identify the surface ("Visual Editor / Site Manager") and close it. |
| Display data | static copy (i18n keys `workspaceEyebrow`, `workspaceTitle`, `workspaceClose`) |
| Actions | `__close` → `close` |
| Accessibility | `__close` has `aria-label="Close Site Manager"`; receives focus on open (BCC precedent). |
| States | none |

## Current object — `__current`, `__current-row`, `__here`

| | |
|---|---|
| Purpose | Answer "where am I?" and offer the backend edit of the page being viewed. |
| Display data | `currentObject.{title,typeLabel,status,statusKey,backendUrl}` (from `bootstrap.pageContext` + `currentEditLink`; no request) |
| Actions | `current-backend` (Edit, new tab). `current-frontend` is intentionally **not rendered** when `currentObject.frontendUrl` equals the page URL — a reload button is noise; production should apply the same rule (D3 decision candidate). |
| Accessibility | `<section aria-labelledby>` the "Current object" overline; "You are here" is text, not colour. |
| States | hidden when `currentObject` is `null` (unsupported page context). |

## Section tablist — `__sections`, `__section-tab`

| | |
|---|---|
| Purpose | Switch between Navigate (object search) and Tools (route to existing surfaces). |
| Display data | static labels (`workspaceSectionNavigate`, `workspaceSectionTools`) |
| Actions | `section` (+ `data-…-section`) |
| Accessibility | `role="tablist"` / `role="tab"` / `aria-selected` / `aria-controls`; roving tabindex; ArrowLeft/Right move selection (contract §6). |
| States | selected = secondary text + 2px primary underline. |

## Navigate body — `__body.is-navigate`

| | |
|---|---|
| Purpose | The scroll container for search, type strip, status, results, footer. |
| Accessibility | `role="tabpanel"` `aria-labelledby` its tab. |
| States | `hidden` when Tools is active. |

### Sticky strip — `__sticky` → `__search-wrap` (`__search`, `__search-clear`) + `__types` (`__type`, `__type-affix`)

| | |
|---|---|
| Purpose | Search + type filter stay visible while results scroll. |
| Display data | `types[]` → one `__type` per `{label, viewable}` after the static "All"; non-viewable types get `.is-backend-only` + a lock affix with visually-hidden "(backend only)". |
| Actions | `search` (input event, 180 ms debounce, ≤ 100 chars) · `clear-search` (hidden until `search` non-empty) · `type` (+ `data-…-type`, `data-…-subtype`) |
| Accessibility | `<label class="__sr-only" for>` on the input; `aria-describedby` → `__status`; type strip is a second `role="tablist"` with roving tabindex and horizontal scroll (never wraps). |
| States | search `disabled` while types load (S-03); selected type = secondary fill (BCC tab style — maintainer decision, R6-D1). |

### Status line — `__status`

| | |
|---|---|
| Purpose | The drawer's **single** polite live region (component map §6): result count, searching, error text. |
| Display data | derived: `results.length`, `hasMore`, `requestStatus`, `requestError.message` |
| Copy | "Showing 20 · more available" — never a total or "page X of Y". |
| Accessibility | `role="status" aria-live="polite" aria-atomic="true"`; `id` is the search input's `aria-describedby`. |
| States | default · `.is-error` (error colour, text still present). |

### Results — `__results` → `__row`

| | |
|---|---|
| Purpose | One object per row with honest routes. |
| Display data | `ObjectItem.{objectType,subtype,title,typeLabel,status,statusKey,hasFrontendRoute,frontendUrl,backendUrl}` |
| Row anatomy | `__row-glyph` (type glyph, `aria-hidden`) · `__row-text` { `__row-title` (2-line clamp, `title=` full text) · `__row-meta` { `__row-type` · `__status-chip--{statusKey}` · optional `__row-note` } } · `__row-actions` |
| Actions | `hasFrontendRoute:true` → `__action--primary` **Open** (`open-frontend`, same tab) + `__action--secondary` **Edit** (`open-backend`, new tab, "(opens in a new tab)" sr-only + external glyph). `hasFrontendRoute:false` → single `__action--primary` **Edit** only. Always-visible two buttons — maintainer decision, R6-D1. |
| Honesty | `__row-note` "No public page" / "No public archive" appears only when the status alone doesn't explain the missing Open (published post on a non-viewable type; term in a non-queryable taxonomy). Draft/Pending/Scheduled/Private rows rely on the chip text. |
| Accessibility | `<ul role="list">`; status is text (chip colour is reinforcement only); ArrowUp/Down between rows' primary actions, Home/End (contract §6). |
| States | `.is-{publish|draft|pending|future|private|term}` · `.is-no-route` · `.is-focused` (focus-continuity highlight) · `.is-skeleton` (S-04). |

### Footer — `__footer` → `__load-more` | `__end`

| | |
|---|---|
| Purpose | Fetch the next page; or state the end. |
| Display data | `hasMore` |
| Actions | `load-more` (appends `page+1`; focus → first appended row) |
| States | default · `.is-loading` (disabled, "Loading…", S-05) · replaced by `__end` "No more results" when `hasMore:false`. |

### Empty / error blocks — `__empty`, `__error` (+ `__empty-actions`, `__retry`) — S-06/S-07/S-08/S-09b

| | |
|---|---|
| Purpose | Explain zero results or a failed request without wiping previously rendered rows on error. |
| Actions | `retry` · `clear-search` (inside S-07 copy) |
| Copy | S-06 "No {label} yet." + one-line hint · S-07 "No matches for “{search}” in {label}." + Clear search · S-08 "Object search failed." (`role="alert"`, rows retained, Retry) · S-09b `__error--mode` (warning tint) "Visual Editor mode is no longer active." + Refresh page |

## Tools body — `__body.is-tools` → `__tools` → `__tool` (`__tool-text`, `__tool-label`, `__tool-detail`, `__tool-affordance`) — S-18a/b

| | |
|---|---|
| Purpose | Route to existing surfaces; never a second editor. |
| Display data | `tools[].{action,label,available,detail}` — availability gated by `bootstrap.controlCenter.enabled`, `bootstrap.mediaManager.enabled`, `bootstrap.currentEditLink`. |
| Actions | `tool-review-fields` (event → existing popover) · `tool-control-center` (BCC toggle; workspace becomes inert underneath — S-11) · `tool-media-manager` (MM toggle — S-12) · `tool-edit-object` (new tab) · `tool-exit` (`bootstrap.toggleUrl`). |
| Accessibility | `<ul role="list">` of `<button>`/`<a>` (Edit active object is an `<a target=_blank>` with the new-tab sr-only affix; Exit is an `<a href=toggleUrl>`); unavailable entries are rendered `disabled aria-disabled` with the reason in `__tool-detail` (never removed silently); trailing chevron/external glyph is `aria-hidden`. |

## D3 additions

| Element / class | Purpose | Production? |
|---|---|---|
| `__row.is-skeleton`, `__skeleton-bar(--tab/--glyph/--action)`, `__types.is-skeleton[aria-busy]` | S-03/S-04 placeholders; shimmer stops under reduced motion | yes (shape); shimmer optional |
| `__spinner` inside `__load-more.is-loading[disabled]` | S-05 | yes |
| `__types` scroll-edge mask | Overflowing type strip reads as scrollable (D2 review note) | yes |
| `.is-focus-visible` | Static emulation of `:focus-visible` for S-15 captures | **mockup only** |
| `.is-motion-off` + `::after` "motion: none" badge | S-16 annotation; production relies on `@media (prefers-reduced-motion: reduce)` | **mockup only** |
| `@media (prefers-color-scheme: dark)` token block + four rule-level control overrides | Same token names as `overlay.css`; the four rules (search, secondary action, status chip, load-more/retry) cover controls whose light value is the un-flipped `--color-surface` — mirrors what R5.later-darkmode.b did for BCC chrome | yes (media block); `__dark-scope` / `--dark` class variants are gallery only |
| `__header` background → `--dbvc-ve-color-chrome-header-background`; `__sticky`/`__sections` → `--dbvc-ve-color-drawer-background` | Chrome surfaces flip in dark mode without rule edits | yes |
| `is-inert` (+ `inert` + `aria-hidden` attributes on the root) | S-11/S-12 under BCC / Media Manager; `filter: saturate(0.85)` is a gallery hint only | yes (attributes); filter mockup only |

## Mockup-only scaffold (not production)

`__admin-bar`, `__stage` (+ `__site-bar`, `__hero*`, `__marker`, `__cards`), `__toolbar` (+ `__toolbar-dock`, `__toolbar-button`, `.is-expanded`, `__toolbar-count`, `--inline`), gallery scaffold (`--gallery`, `__gallery-*`, `__toolbar-stage`, `__stage-hint`, `__dark-scope`, `--dark`, `--motion-off`), composite frames (`__frame-wrap`, `__frame`) and the sibling-surface stand-ins (`__bcc*`, `__mm*`, `__panel*`) used only by S-11/S-12/S-13. All `aria-hidden="true"`. The toolbar reproduces the **live** `overlay.css` pill so the new first-dock Workspace button can be judged in context; its `grid` glyph is the SVG proposed for `renderToolbarIcon('grid')` in R6-D-1.

## Invented names table

| Name | Where | Status |
|---|---|---|
| `--dbvc-ve-z-workspace` | styles.css token mirror | production proposal, pinned (D-072) |
| `--dbvc-ve-workspace-*` sizing vars | drawer root | production, component-local |
| `.dbvc-ve-workspace__*` BEM classes | drawer | production names (contract §4) |
| `.is-backend-only`, `.is-no-route`, `.is-inert`, `.is-focused`, `.is-skeleton` | drawer | production state classes proposed |
| `__row-note` | rows | proposed production element (honesty note) |
| `data-mockup-*` | everything interactive | mockup only |
| `grid` icon | toolbar + header | production proposal for `renderToolbarIcon` |
