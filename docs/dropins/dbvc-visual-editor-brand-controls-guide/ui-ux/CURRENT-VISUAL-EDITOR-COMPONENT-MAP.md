# Current Visual Editor component map

**Audience:** the UI/UX agent producing the R3 mockup (or any future overlay/panel mockup). This is the **inventory of what already exists on the frontend** so your new work reads as a cohesive addition — not as an obstructive, over-engineered, non-standardized layer.

**Companion doc:** [R3-BRAND-CONTROL-CENTER-WIRING-SCHEMATIC.md](./R3-BRAND-CONTROL-CENTER-WIRING-SCHEMATIC.md) — the R3-specific wiring.

**Source of truth is the code**, not this doc — when they disagree, the code wins:
- `addons/visual-editor/assets/js/overlay-app.js` (~8000 lines; the shell + all panels + popovers)
- `addons/visual-editor/assets/js/media-manager-app.js` (~4500 lines; the Media Manager modal + rows)
- `addons/visual-editor/assets/js/media-frame-factory.js` (shared wp.media construction)
- `addons/visual-editor/assets/js/api-client.js` (REST client)
- `addons/visual-editor/assets/css/overlay.css` (root tokens + shell + panel + popover styles)
- `addons/visual-editor/assets/css/media-manager.css` (Media Manager only; loaded after overlay.css)

---

## 0. Scope + non-goals (read first)

Per **D-058 (2026-08-23)**: the Visual Editor and DBVC plugin are **desktop-only, permanent non-goal for mobile / tablet / touch**. Real assistive-technology (VoiceOver / JAWS / NVDA) sit-with-a-screen-reader QA is **not a required gate**. Automated axe / keyboard / reduced-motion checks stay in the coverage matrix. See `docs/dropins/dbvc-visual-editor-brand-controls-guide/00-GOVERNING-DIRECTIVES.md` §0.

Existing narrow-width protections in `overlay.css` and `media-manager.css` stay as a regression floor for unusual desktop DPI/zoom only — **do not treat them as an invitation to build mobile UX on top of them.**

---

## 1. Layered mental model

The Visual Editor overlays onto a rendered Bricks page. From the viewer's perspective, the layers from bottom to top are:

```
[Bricks-rendered page content]                            ← z-index: page-native
[Badge layer]                        ← z: --dbvc-ve-z-badge-layer     (119990)
[Section badges]                     ← z: --dbvc-ve-z-section-badge   (119980)
[Status bar]                         ← z: --dbvc-ve-z-statusbar       (119970)
[Editor panel]                       ← z: --dbvc-ve-z-panel           (120010)
[Toolbar]                            ← z: --dbvc-ve-z-toolbar         (120020)
[wp.media modal (WordPress-owned)]   ← z: > 120020 (WordPress default)
[Media Manager modal]                ← z: overlay-panel-adjacent; layered above the toolbar
```

**All z-index values come from CSS tokens.** Never hardcode a z-index — reuse `--dbvc-ve-z-*` or add a new token in a separate PR against `overlay.css` root.

The Bricks Builder editor context is **explicitly excluded** — the Visual Editor runtime, assets, and instrumentation are blocked inside Bricks Builder edit/main/iframe requests. Any new UI must inherit this exclusion (loading gates already enforce it).

---

## 2. Toolbar (`.dbvc-ve-toolbar`)

**Position:** bottom-center strip. Fixed. Rendered by `overlay-app.js`'s toolbar builder (around line 2835).

**Composition:** a row of icon buttons ("bar-satellite" pattern), plus a status/messages sibling `<div>`, plus an optional right-side "power" toggle-mode entry.

**Icon slots present today** (left to right, at the time of writing):

| Slot | Icon | Purpose | Data action |
|---|---|---|---|
| Pause / Play | `pause`/`play` | Reserved future mode toggle | (mockup-only in Media Manager) |
| Layers | `layers` | Open the Shared Globals popover | `data-dbvc-ve-toolbar-action="open-shared-globals"` |
| Search | `search` | Open the Object Search popover | `data-dbvc-ve-toolbar-action="open-object-search"` |
| Image | `image` | Open the Media Manager (when enabled) | `data-dbvc-ve-toolbar-action="open-media-manager"` |
| Globe | `globe` | Reserved (currently Shared Globals adjacent) | — |
| Pencil | `edit` | Go To Object shortcut (current-object edit link) | `data-dbvc-ve-toolbar-action="go-to-object"` |
| Power | `power` | Exit Visual Editor mode | `data-dbvc-ve-toolbar-action="toggle-mode"` (or href-based) |

**Button markup helper:** `createToolbarButtonMarkup(action, iconName, label, extraClass, isSatellite)` — the R3 center MUST reuse this helper if it needs a toolbar entry. **Do not** invent a second toolbar-button builder.

**Existing per-action orange status dot** — some slots (image, globe) render a small orange dot to signal outstanding items. Reuse the same visual language for R3 if it needs a "controls awaiting attention" signal — do not invent a new badge shape.

**R3 direction (from the release plan):** "Avoid adding a new permanent toolbar button when an existing entry can evolve safely." Two viable paths:
- Evolve the Shared Globals (Layers) entry to open the R3 center popover (which subsumes the current popover's rows).
- Reuse an existing slot semantics-adjacent to controls (e.g. the Globe) with a fresh label.

---

## 3. Popovers (toolbar-owned)

The toolbar can open **at most one** popover at a time. Popover shell classes:

- Container: `.dbvc-ve-statusbar-popover` (yes, "statusbar" is the historical namespace — do not rename)
- Anchor pointer / triangle: `.dbvc-ve-statusbar-popover__anchor`
- Header: `.dbvc-ve-statusbar-popover__header` (contains title + close)
- Body: `.dbvc-ve-statusbar-popover__body`
- Footer (optional): `.dbvc-ve-statusbar-popover__footer`

**Openers today:**

| Function | Purpose |
|---|---|
| `openStatusBarToolbarPopover` | Generic popover shell used by the others |
| `openObjectSearchToolbarPopover` | Go To Object search |
| `openSharedGlobalsToolbarPopover` | Shared Globals row list |
| `closeToolbarPopover` | Single close path — always used |

**Standard interactions:**
- Escape closes the popover and returns focus to the toolbar button that opened it.
- Clicking outside closes it.
- Only one popover is open at a time — opening another closes the current one.

**R3 direction:** the center likely opens *as* a toolbar popover using this shell (not as a separate modal), so it inherits Escape/outside-click/return-focus for free.

---

## 4. Editor panel (`.dbvc-ve-panel`)

**Position:** floating panel, initially anchored to the interacted marker but user-draggable. Fixed to the viewport; auto-clamps to viewport bounds on open (`Math.max/min` clamping in overlay-app.js around line 7377).

**Composition (top to bottom):**

- Header row (`.dbvc-ve-panel__header`): title + close button
- Meta row (`.dbvc-ve-panel__meta`): source context + badge label
- Body (`.dbvc-ve-panel__body`): the actual field controller (varies by input type — see §4.1)
- Toolbar row (`.dbvc-ve-panel__toolbar` / `.dbvc-ve-panel__actions`): Save · Save & Reload · Cancel · optional Choose Media
- Status row (`.dbvc-ve-panel__status`): saving indicator, saved indicator, error text

**Standard interactions:**
- Drag to reposition (header is the drag handle)
- Close on Escape (unless a wp.media modal is up — the `mediaModalIsOpen` guard defers to WordPress)
- Close on outside-click **unless** the panel has unsaved changes
- Auto-clamp to viewport on open (never overflows the visible area at supported desktop viewports)
- Keyboard: Tab cycles inside the panel; Shift+Tab returns to the last focusable

**Tall-content behavior:** the panel body is internally scrollable — header and action row stay reachable. Do not invent a second scrolling container.

### 4.1 Field-controller flavors (all live inside `.dbvc-ve-panel__body`)

| Controller | Function | DOM signature | Use case |
|---|---|---|---|
| Text-like | `createTextLikeController` | `<textarea class="dbvc-ve-panel__input dbvc-ve-panel__input--textlike">` | ACF text/textarea |
| Input (typed) | `createInputController` | `<input class="dbvc-ve-panel__input">` | URL / number / email fields |
| Textarea | `createTextareaController` | `<textarea class="dbvc-ve-panel__input">` | Plain textarea |
| Rich text (WP editor) | `createWordPressRichTextController` | TinyMCE inside `.dbvc-ve-panel__wysiwyg-host` | WYSIWYG when `wp-editor` is available |
| Rich text (fallback) | `createFallbackRichTextController` | `<div contenteditable class="dbvc-ve-panel__richtext">` | WYSIWYG fallback |
| Checkbox group | `createCheckboxGroupController` | `<div class="dbvc-ve-panel__option-list">` | Multi-select |
| Select | `createSelectController` | `<select class="dbvc-ve-panel__input dbvc-ve-panel__select">` | Single-select |
| Link | `createLinkController` | Stacked URL + title + target inputs in `.dbvc-ve-panel__stack` | Link fields |
| Media (image) | `createMediaReferenceController` | Preview thumb + `Choose image` + `Clear` inside `.dbvc-ve-panel__stack` | ACF image / featured image |
| Media (gallery) | `createMediaGalleryReferenceController` | Grid preview + `Add images` + `Replace gallery` + `Clear gallery` | ACF gallery |
| Reference collection | (used by Shared Globals + query collections) | Draggable chip list of post references | ACF relationship / post_object |
| Read-only preview | `createReadonlyPreviewController` | Static preview only | Inspect-only descriptors |

**R3 reuse rule:** the R3 center opens ONE OF the above controllers via the existing panel — R3 introduces zero new controllers. If a control's `input` field says `reference_collection`, that IS the existing controller — do not fork it.

### 4.2 Panel action buttons (`.dbvc-ve-panel__toolbar-button`)

Every panel action button uses the same class. Variants via modifier:
- `.dbvc-ve-panel__toolbar-button--primary` — Save
- `.dbvc-ve-panel__toolbar-button--secondary` — Save & Reload, Cancel
- `.dbvc-ve-panel__toolbar-button--danger` — Retry after error (rare)

Buttons that are not applicable in the current state are **omitted from the DOM**, not rendered disabled. Match the R1 Media Manager convention exactly.

### 4.3 Panel life-cycle helper

- `renderIdlePanel()` — the "no active field" empty-state placeholder
- `closeEditorPanel()` — the single close path; always used
- `destroyActiveController()` — tears down the current controller (this is what disposes overlay `wp.media` frames per RK-011 Slice 2)
- `createNoopLifecycle(controller)` — default `mount()`/`destroy()` no-ops that controllers extend

---

## 5. Media Manager modal (`.dbvc-ve-media-manager`)

Loaded from a separate file (`media-manager-app.js`), gated by the Media Manager option. Included here so R3 doesn't accidentally look like a Media Manager clone.

- Root: `.dbvc-ve-media-manager` — full-page-adjacent modal with backdrop
- Header: `.dbvc-ve-media-manager__header` — title + scan status strip + close
- Site-media-index banner: `.dbvc-ve-media-manager__index-banner` — plain-language line explaining source
- Filters row: search, entity family chips, field family chips, sort select
- Results table: `.dbvc-ve-media-manager__results` — semantic `<table>` with internally scrollable `<tbody>`
- Row: `.dbvc-ve-media-manager__row` — one entity, expandable disclosure
- Expanded detail: `.dbvc-ve-media-manager__expanded-group` — a per-field card list with Choose image / Save / Clear controls, thumbnail preview, unsaved-selection badge
- Footer: `.dbvc-ve-media-manager__footer-note` — read-only-mode disclaimer

**Interaction contract that R3 must NOT duplicate as a different pattern:**
- One row expanded at a time
- Row toggle uses native `<button>` + `aria-expanded` + `aria-controls`
- Focus continuity across list rerenders (focus restores to the row button)
- Native Enter/Space toggles disclosure
- Polite live-region announcements for field-check outcomes
- `.dbvc-ve-media-manager-description` names the dialog
- `.dbvc-ve-media-manager-results-title` names the results region

**R3 direction:** the R3 center opens **as a toolbar popover, not as a modal**. If R4 later expands it into a full-page workspace, that's when the Media Manager modal shell becomes the pattern to follow — not R3.

---

## 6. Status bar + notice / live regions

- **Status bar** (`.dbvc-ve-statusbar`) — persistent thin strip at the bottom-left; shows current object context and quick links. Reuse for R3 status hints; do not add a second status strip.
- **Field index** (`.dbvc-ve-field-index-*` inside the status bar) — collapsible list of markers on the current page. Not relevant to R3 (R3 lists off-page controls).
- **Polite live region** — a single visually-hidden `<div>` with `aria-live="polite"` + `aria-atomic="true"` used for save/refresh/check-outcome announcements. Reuse for R3 open-outcome announcements; do not add a second one.
- **Assertive live region** — used only for validation errors that must interrupt (`role="alert"`); reuse the same convention.
- **Toast pattern** — inline transient status inside the current popover/panel; not a global overlay. R3 open failures should render inline in the center popover, not as a separate toast layer.

---

## 7. CSS token conventions (`overlay.css` root)

**All colors** come from `--dbvc-ve-color-*`:

| Token | Role | Notes |
|---|---|---|
| `--dbvc-ve-color-primary` | Accent (orange) | R3 buttons/status dots should reuse — no new primary |
| `--dbvc-ve-color-secondary` | Deep navy | Panel headers, badges |
| `--dbvc-ve-color-accent` | Muted lavender | Secondary badges, hover states |
| `--dbvc-ve-color-light` | Near-white backgrounds | |
| `--dbvc-ve-color-dark` | Body text | |
| `--dbvc-ve-color-text-muted` | 68% blend | Secondary text |
| `--dbvc-ve-color-text-subtle` | 60% blend | Tertiary text — **the R1-D shared-token correction landed here**; keep it |
| `--dbvc-ve-color-surface` | Panel backgrounds | White |
| `--dbvc-ve-color-surface-muted` / `--surface-glass` | Layer glass effects | |
| `--dbvc-ve-color--empty` | Missing-media orange-red | Reuse for R3 `status: unavailable` if a color signal is needed (always with a text label — never color-alone) |

**Typography:** `--dbvc-ve-font--l`/`--m`/`--s`/`--body`/`--caption`/`--meta`/`--overline` + `--dbvc-ve-font-weight--normal|bold|heavy`.

**Sizing:** `--dbvc-ve-size-toolbar-width|height`, `--dbvc-ve-size-icon-width|height`, `--dbvc-ve-font-icon--s|m|l`.

**Z-index:** `--dbvc-ve-z-statusbar|section-badge|badge-layer|panel|toolbar` (values in §1). **Never hardcode a z-index.**

**Motion:** reduced-motion (`@media (prefers-reduced-motion: reduce)`) already suppresses the disclosure transition — carry the same guard into any R3 transitions.

**No CSS-in-JS.** All styles live in a scoped CSS file. R3 gets its own file loaded after `overlay.css` (mirroring `media-manager.css`).

---

## 8. Class-naming conventions (BEM under `.dbvc-ve-*`)

Every UI namespace uses the `dbvc-ve-` prefix + BEM:

- Root: `.dbvc-ve-<component>` (e.g. `.dbvc-ve-panel`, `.dbvc-ve-media-manager`)
- Element: `.dbvc-ve-<component>__<element>` (e.g. `.dbvc-ve-panel__body`)
- Modifier: `.dbvc-ve-<component>__<element>--<modifier>` OR `.is-<state>` on the element

**R3 root:** `.dbvc-ve-control-center` (proposed; matches convention). Rows use `__row` with `is-<status>` modifiers.

**Data attributes** (action delegation pattern):
- `data-dbvc-ve-toolbar-action="..."` — toolbar buttons
- `data-dbvc-ve-panel-action="..."` — panel action buttons
- `data-dbvc-ve-media-manager-action="..."` — Media Manager row / field controls
- **R3 addition:** `data-dbvc-ve-control-center-action="..."` — R3 row / action controls; matches the pattern

**No inline event handlers.** All interaction is event-delegated from the module root.

---

## 9. Loading, empty, error, saving conventions

Every list/panel surface today uses the same four-state shape:

| State | Convention |
|---|---|
| `loading` | Spinner + descriptive text; sets `aria-busy="true"` on the region; polite live-region announcement |
| `empty` | Plain-language explanation of *why* the list is empty (never "No results" alone); offers a clear next action when one exists (e.g. Clear filter) |
| `error` | Assertive `role="alert"` region with the human message from the server; Retry button when the operation is retryable |
| `saving` | Button text changes to "Saving…"; button disabled; polite announcement after resolution ("Saved" or the error) |

R3 must follow this exact shape.

---

## 10. Interaction patterns to reuse (not reinvent)

| Pattern | Where it lives | Reuse in R3 |
|---|---|---|
| Toolbar popover open + Escape + outside-click + return-focus | `openStatusBarToolbarPopover` / `closeToolbarPopover` | R3 center is a popover — reuse |
| Draggable, viewport-clamped floating panel | `.dbvc-ve-panel` drag helper | The center is a popover, not a draggable panel — R3 does not need this |
| Single active `wp.media` frame with previous-frame disposal | `DBVCVisualEditorMediaFrame` (RK-011) | Only relevant if R3 opens `wp.media` (unlikely for R3-C; if it does, use the factory) |
| Row-focus continuity across list rerenders | Media Manager `body.ownerDocument.activeElement` snapshot | R3 list must preserve focus on the row whose Open button was clicked |
| Native Enter/Space disclosure | Media Manager row toggle | R3 does not have disclosures in this scope — but if it adds category collapse in R4, follow this |
| Polite `aria-live` announcements for outcomes | Single shared region in the shell | Use the SAME region — do not add a second |
| Assertive `role="alert"` for validation errors | R2-D notice `kind: 'error'` | R3 open-error at 409 stale → assertive |

---

## 11. Enqueue / dependency graph (what loads when)

From `AssetLoader::enqueue`:

```
dbvc-visual-editor-api-client            (no deps)
dbvc-visual-editor-media-frame-factory   (no deps — RK-011 Slice 1)
dbvc-visual-editor-overlay               (deps: api-client, media-frame-factory, wp-editor, media-editor)
dbvc-visual-editor-media-manager         (deps: overlay)          [only when MM option enabled]
overlay.css                              (no deps)
media-manager.css                        (deps: overlay.css)      [only when MM option enabled]
```

**R3 enqueue proposal** (for the implementation agent — informational for the mockup):
```
dbvc-visual-editor-control-center        (deps: overlay, api-client)
control-center.css                       (deps: overlay.css)
```
Both should be feature-gated by a Media-Manager-style option so R3 can ship default-off and roll back cleanly.

---

## 12. What NOT to do (guardrails)

Actual patterns other agents have proposed and been redirected away from — record them so R3 doesn't repeat:

1. **Do not add a full-page modal for R3.** The center is a toolbar popover. R4 may expand it; R3 stays inside the popover shell.
2. **Do not invent a new field controller.** Every R3 open ends in one of the existing controllers listed in §4.1.
3. **Do not add mobile / tablet / touch layouts.** D-058. Existing narrow-width protections are a regression floor, not a springboard.
4. **Do not hardcode colors, fonts, or z-indexes.** Use tokens.
5. **Do not add a second polite live region.** Reuse the existing one.
6. **Do not render disabled controls for absent states.** Omit from DOM.
7. **Do not surface any raw target attributes on rows** (`ownerId`, `fieldKey`, `selector`, `path`, `descriptor`, `token`). Only `publicId` + safe presentation attributes.
8. **Do not derive filtered totals client-side.** The server owns list identity + counts.
9. **Do not add a new toolbar button when an existing entry can evolve** (R3 plan direction).
10. **Do not build screen-reader / VoiceOver-specific interactions.** D-058 — automated axe stays, real-AT does not.
