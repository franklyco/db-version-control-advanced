# R3 Global Brand Control Center &mdash; design decisions

Rationale for the visual + interaction calls made in this mockup. Every decision references the schematic (`R3-BRAND-CONTROL-CENTER-WIRING-SCHEMATIC.md`), the component map (`CURRENT-VISUAL-EDITOR-COMPONENT-MAP.md`), or the governing directives.

---

## 1. Toolbar icon choice &mdash; **Sliders**

**Decision.** The single new toolbar entry uses a **sliders** glyph (three horizontal lines each with a filled circular "thumb" at a different position; see `index.html` toolbar and drawer header). Data attribute: `data-dbvc-ve-toolbar-action="open-control-center"`.

**Why sliders (over the alternatives listed in schematic §6.1: sliders / dashboard / layout).**

- **Semantic fit.** A global brand control center is where the site's global settings live. Sliders is the industry-standard "adjust the settings for this thing" glyph (macOS Control Center, Chrome DevTools filters, every design tool's Inspector). It reads as adjustment authority, not as a menu.
- **Contrast with the existing toolbar.** The existing seven icons are `pause/play`, `layers`, `search`, `image`, `globe`, `edit`, `power`. `dashboard` (grid of squares) is visually collision-prone with a future preview / workspace grid; `layout` reads as canvas geometry, adjacent to `edit`. Sliders is orthogonal to all seven current glyphs.
- **Screen-reader label.** `aria-label="Global Brand Controls"` reads cleanly. `dashboard` implies a dedicated page (which R3 is not), `layout` implies canvas manipulation (which R3 does not do).
- **Reserves the right visual space for R4.** When R4 evolves the drawer into a full workspace, the sliders glyph continues to read correctly &mdash; a workspace of controls is still a controls surface. `dashboard` might have been more accurate for R4, but the mockup is R3.

Rejected options:
- `dashboard` &mdash; too suggestive of a separate page/route; reads "leave the editor to see this".
- `layout` &mdash; too close to `edit` visually and semantically.
- Reusing `globe` (an existing reserved slot) &mdash; explicitly forbidden by D-061; the Shared Globals popover is untouched and the globe slot is reserved.
- Reusing the `layers` slot (the original D-060 direction) &mdash; explicitly reversed by D-061.

**Implementation status.** The `sliders` key has already been registered in `createToolbarButtonMarkup`'s icon table in `addons/visual-editor/assets/js/overlay-app.js` &mdash; the mockup's `iconName='sliders'` reference is now backed by real code. No further validation is needed on this front.

---

## 2. Tab structure &mdash; all 7 categories + "All", no super-tabs

**Decision.** Render **8 tabs** at ~480px drawer width: `All`, then one per curation category (`Brand`, `Contact`, `Content`, `Design`, `Layout Elements`, `Legal`, `SEO`). Tabs are horizontally scrollable (`overflow-x: auto`) inside the drawer, never wrap.

**Why per-category, not super-tabs.**

- **The curation categories are the maintainer's mental model.** Sections 3, 4, and 6 of the schematic all treat these seven categories as the authoritative shape. Consolidating to 3–4 super-tabs would force the mockup to invent a new mental model that then needs curation to translate back through.
- **The 480px width comfortably fits ~4 tabs at a time.** With a scrollable strip, direct access to every category costs no vertical space, does not hide anything, and does not add a second layer of navigation (a super-tab that then reveals sub-tabs would).
- **Predictability of the URL hash.** `#brand-controls/design` reads clearly for anyone bookmarking or sharing a link into a specific category. A super-tab scheme (`#brand-controls/media/design`) obscures which is the leaf.
- **Zero controls in a category is legible.** The empty-tab state (state 14 in the gallery) tells the user "this category will populate when a provider registers controls for it" &mdash; per-category tabs surface this honestly. A super-tab that silently omits the category hides that it exists.

Rejected options:
- **3 super-tabs** (`Identity / Content / Meta` covering Brand+Design, Content+Layout, Contact+Legal+SEO). Compact but forced &mdash; SEO does not belong under Contact, Contact does not belong with Legal, Layout is not Content. Would need three arguments per grouping to defend.
- **Dropdown category picker** rather than a tab strip. Hides the shape of the registry; costs a click for what should be a glance.
- **Single `All`-only tab** with an inline category chip filter. Blurs "which categories exist" and would force reviewers to read filter state to answer that.

The tab strip is horizontally scrollable per schematic §5.1.2 &mdash; users on the 1280 viewport at higher OS chrome / DPI still see the first 3–4 tabs and scroll to the rest.

---

## 3. Drawer geometry &mdash; 480px fixed, both viewports

Per schematic §5.1.1:
- **480px width** (mid-point of the 420–560 range). At 480 the row anatomy reads cleanly (label + meta + action), the tab strip fits ~4 tabs, and 960px of the site remains visible at 1440-wide (66% of the viewport), 800px at 1280-wide (63%).
- **Top:** below the 32px WordPress admin bar.
- **Bottom:** above the `.dbvc-ve-toolbar` strip (~76px reserved so the strip is not obscured).
- **No backdrop** &mdash; the site remains visible on the right per governing directives §7. The drawer carries its own shadow (`--dbvc-ve-control-center-shadow` &mdash; component-local, does not touch the shared palette).

Fixed width across viewports keeps the row anatomy stable and avoids re-flowing the row-vs-action layout across two supported viewports.

---

## 4. Z-index &mdash; ONE new token proposed

`--dbvc-ve-z-drawer: 120015`, sitting between `--dbvc-ve-z-panel` (120010) and `--dbvc-ve-z-toolbar` (120020).

- **Above the panel** so the drawer does not slide *behind* the main editor panel when the panel opens (the drawer is a persistent workspace).
- **Below the toolbar** so the toolbar strip stays reachable at the bottom center for close/open toggling.
- **Below `wp.media`** by definition &mdash; the WordPress Media Library modal remains the topmost layer whenever it opens (component map §1).

This is the ONE new token the schematic authorizes; declared in `styles.css` under the mockup scope. Per the mockup contract (component map §7), Codex adds it to `overlay.css :root` in a separate PR before wiring the drawer to production.

---

## 5. Row anatomy &mdash; verbatim from §5

- Status dot color driven by `--dbvc-ve-color-success/info/warning-strong/--empty` for `available/inspect_only/unsupported/unavailable`. Every dot has a text label (either the Open/View button, or the uppercase status word). **Never color-alone** (directives §9).
- The `Open` action is orange primary (`--dbvc-ve-color-primary`). The `View` action for `inspect_only` is a bordered secondary (`--dbvc-ve-color-surface` on `--dbvc-ve-color-border`) so it visually separates a read-only path from an editable one, and so that "View" is not misread as an equally-loud Open.
- **Muted rows (`unsupported`, `unavailable`) carry no action button.** Text-only status label. Never rendered as a disabled button &mdash; component map §12 rule 6.
- The metadata line renders `category · group · [Shared Global badge]`. The badge label is `Shared Global` verbatim &mdash; matches the existing badge convention in the Shared Globals popover (component map §7 token: `--dbvc-ve-color-shared*`).
- The owner hint (small muted text) renders `ownerType/ownerSubtype · fieldFamily`. This is per §5, and helps disambiguate two controls that share a label from different providers (which is possible once a second provider registers).

---

## 6. State-by-state rationale

| # | State | Rendered how | Why |
|---|---|---|---|
| 01 | list | Full drawer, 33 rows, sticky column header | Baseline reference. Everything else is measured against this. |
| 02 | loading-initial | Full-panel spinner, `aria-busy="true"`, plain "Loading Brand Controls…" | Matches component-map §9. `role="status"` polite; no color-alone. |
| 03 | loading-refresh | List stays visible under a `0.6` white overlay + small spinner | Preserves scroll position and focus; avoids the "everything flashes" pattern the Media Manager also rejects. `aria-busy` on the table region, not the whole drawer. |
| 04 | empty registry | Full-panel copy: "No global controls are registered yet." | Directives §9 &mdash; explain *why* it is empty, never "No results" alone. |
| 05 | empty-filtered | Same panel-state pattern, names the filter, offers "Clear filters" | Media Manager empty-filtered parallel &mdash; same expectation for R3. |
| 06 | error | Assertive alert region + Retry | R2-D `kind: 'error'` convention; matches the four-state shape in component map §9. |
| 07 | opening | Row swaps Open → "Opening…" + spinner, row stays put | Row-focus continuity (component map §10). The row must not disappear or move &mdash; the user knows where they came from. `aria-busy="true"` on the row's action button; polite announcement, not assertive. |
| 08 | opened (drawer + panel coexist) | Drawer stays open, main editor panel opens over/beside, row keeps `is-focused-source` highlight | Schematic §5.1.3 "Coexists with the main editor panel". The highlight is the "focus continuity" cue for when the panel closes. |
| 09 | open-error (409 stale) | Inline notice row below the affected row, `role="alert"` (assertive per §3.2), Dismiss button | 409 is stale visibility &mdash; the user did the right thing; the world moved. The message is polite + retryable; the row is preserved. |
| 10 | all statuses | One row of each status side-by-side | Verifies every status renders correctly &mdash; particularly that unsupported/unavailable are muted with text labels, never as disabled buttons. |
| 11 | Category tab (Design) | Only Design rows visible, `aria-selected="true"` on Design tab | Baseline for per-tab behaviour. |
| 12 | Contact tab + priority=must chip | Contact rows filtered to `priority=must`, Clear filters visible | Verifies the filter strip persists across tabs (per §5.1.2) and Clear only appears when filters are active. |
| 13 | Sticky header mid-scroll | `scrollTop=240` on the table wrap; header pins | Confirms `position: sticky` on `<thead>` works &mdash; and that the drawer body is the scroll container, not the whole drawer. |
| 14 | Empty tab | Empty-state panel scoped to the tab, not the whole drawer | Preserves the tab strip so the user can navigate to a populated tab without reopening the drawer. |
| 15 | Opening frame | `is-opening` modifier: `translateX(-45%)` snapshot | Illustrates the slide-in animation is real, not implied. |
| 16 | Reduced-motion open | Same as list, `Motion off` badge, `transition: none` inline | Confirms `@media (prefers-reduced-motion: reduce)` suppresses the slide-in. |
| 17 | Empty filtered within category | Legal tab + `field=image` chip → no matches | Compound state: an empty tab that is empty *because of the filters*, not because the tab is unpopulated. Names both. |

---

## 7. Interactions that intentionally differ from popover convention

Component map §3 says popovers close on outside-click; §5.1.3 explicitly says the drawer does **not**. Called out:

- **Outside-click:** ignored. The drawer is a workspace; the site is behind it by design.
- **Escape:** closes the drawer; focus returns to the new toolbar icon (matches popover convention).
- **Second-click on the toolbar icon:** closes the drawer (toggle behavior; matches popover convention).
- **Row Open click:** opens the main editor panel; drawer stays open.
- **Filter change / tab change:** rerender preserves focus on the last-focused row's `publicId` when possible (component map §10 `activeElement` snapshot pattern).

---

## 8. Fixtures &mdash; pre-render inline, no runtime renderer

**Decision.** Every row in `index.html` and `states.html` is authored directly as `<tr>` markup in the file. There is no `mockup.js`, no fixture object built into the DOM at load, no JavaScript of any kind. The tabs, filter chips, and Open buttons remain in the DOM (they are part of the reference shape the production translator must match) but do not react to clicks.

**Why.**

- **A reference mockup should be readable.** The point is to show Codex the exact DOM shape production will emit. If a reviewer has to follow JS to know what would render, the mockup is not doing its job. Pre-rendering removes an entire layer between "read the file" and "understand the intent".
- **File size is not a constraint.** The full `states.html` with 17 cells and all row markup baked in is ~186 KB &mdash; large by mockup standards but well below anything a reference document needs to worry about. Duplicating a row across two state cells is fine here (both `index.html` state 01 and `states.html` state 13 hold all 33 rows; that is on purpose).
- **The R1 Media Manager mockup made the JS optional; here we go one step further.** R1 shipped its JS with the caveat that deleting it left the page as a valid static default. R3 deletes the JS entirely. Same intent, cleaner ship.

**Fixture data preserved.** The fixture summary (33 records with category / group / priority / status / field-family) sits as an HTML comment at the top of `index.html` so a production translator can still see the shape without reading through the row markup.

**Priority stays first-class.** Per the maintainer's confirmed working assumption, `items[].priority: "must" | "should" | "nice" | ""` is treated as a top-level field on the list response. The priority filter chip in the mockup depends on this. If maintainer later decides to nest it under `meta` or drop it entirely, the mockup adjusts in a follow-up &mdash; but the current form is what production should aim for.

---

## 9. Colors + tokens used

Every color in the mockup routes through the mirrored `--dbvc-ve-color-*` tokens. No hardcoded palette values. The one net-new token is `--dbvc-ve-z-drawer: 120015`.

Component-local tokens (do NOT hoist to `:root`, per component map §12 rule 4):
- `--dbvc-ve-control-center-width: 480px`
- `--dbvc-ve-control-center-admin-bar: 32px`
- `--dbvc-ve-control-center-toolbar-strip: 76px`
- `--dbvc-ve-control-center-header-padding-y`, `--dbvc-ve-control-center-section-padding-x`
- `--dbvc-ve-control-center-focus-ring: 2px solid var(--dbvc-ve-color-focus)`
- `--dbvc-ve-control-center-shadow` (drawer edge shadow)
- `--dbvc-ve-control-center-chip-padding`

Palette use:
- `--dbvc-ve-color-primary` (orange): Open button, focus rings, active row highlight tint.
- `--dbvc-ve-color-secondary` (deep navy): titles, active tab background, label text.
- `--dbvc-ve-color-shared*` (existing amber palette): "Shared Global" badge on every row and in the panel stand-in.
- `--dbvc-ve-color-success/info/warning-strong/--empty`: status dots. All accompanied by text labels.

---

## 10. Invented names / attributes / classes &mdash; Codex validation targets

Called out so Codex validates against the code before production translation. The `sliders` icon key has landed; the remaining items still need confirmation.

| Name | Kind | Where | Notes |
|---|---|---|---|
| `open-control-center` | Toolbar action | `data-dbvc-ve-toolbar-action` | New action, needs a handler in the toolbar click delegate. |
| `open` / `dismiss-notice` / `clear-filters` / `clear-filters-inline` / `retry` | Row / notice / panel actions | `data-dbvc-ve-control-center-action` | New action vocabulary for the R3 root; matches the pattern `.dbvc-ve-media-manager` uses. |
| `.dbvc-ve-control-center` | BEM root | Drawer | Proposed root in the schematic. Confirmed matches convention (component map §8). |
| `.dbvc-ve-control-center__row.is-focused-source` | Modifier | Row that opened the current panel | Not in the schematic. Kept by design &mdash; extends the Media Manager focus-continuity pattern one step further to signal "this is the row the currently-open panel came from". See COMPONENT-NOTES.md §7 for rationale. |
| `--dbvc-ve-z-drawer` | Token | `:root` | Proposed at 120015 &mdash; the ONE new token R3 needs. |
| Fixture key `priority` | Row-level first-class field on `items[]` | pre-rendered rows | Maintainer's confirmed working assumption is that `priority: "must" \| "should" \| "nice" \| ""` sits on `items[]` alongside label/category/etc. If maintainer later moves it under `meta` or drops it, the mockup adjusts in a follow-up. |

---

## 11. Resolved decisions from the previous drop

Recorded for continuity so a future reviewer can see how the mockup arrived at its current shape:

- **Drawer title:** "Global Brand Controls" (maintainer-picked, supersedes "Brand Controls" from the previous draft).
- **Summary chip:** total count only (e.g. "33 controls"). The earlier draft had a "33 registered &middot; 19 ready to open" cue; the "ready to open" affordance is deferred as a potential later enhancement (likely R4 territory) and not shipped in R3.
- **`is-focused-source` row modifier:** kept, as recommended. See §10 above and COMPONENT-NOTES.md §7.
- **Toolbar icon `sliders`:** landed in real code (`overlay-app.js` `createToolbarButtonMarkup` icon table). No further validation needed.
- **PNG screenshot capture:** not planned. The 4 SVG wireframes in `screenshots/` are the visual reference. If PNGs are ever needed, they are a manual browser-pass job following the R1 D4A-3 precedent (CDP `Emulation.setDeviceMetricsOverride` at 1440&times;900 and 1280&times;720). No Puppeteer rig, no queued capture task.
- **Static-only implementation:** the previous draft's `mockup.js` renderer was replaced with pre-rendered inline `<tr>` markup. See §8 above.

---

## 12. What the mockup will NOT show

Per schematic §7 reuse boundary and §8 scope:

- **The main editor panel itself.** The stand-in in state 08 is a wireframe placeholder for orientation only; the real panel is the existing one, opened via the existing panel factory.
- **Real Bricks or ACF data.** All values are hand-authored.
- **Any REST call.** `data-mockup-*` posts nothing.
- **A modified Shared Globals popover.** D-061 keeps it untouched; the mockup renders the Layers slot in the toolbar as it exists today.
- **A modified Media Manager modal.** Nothing in R3 touches the Media Manager surface.
- **Mobile / tablet / touch layouts.** D-058.
