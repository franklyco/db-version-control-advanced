# R3 Global Brand Control Center &mdash; static mockup

Non-production UI mockup for the DBVC Visual Editor R3-C release (registry-backed Global Brand Control Center). **Purely static HTML/CSS reference** &mdash; no JavaScript, no runtime renderer, no fixture object at load. Every row is pre-rendered inline. Codex translates the accepted direction through separately reviewed production slices.

Companion docs (authoritative when this mockup and they disagree):
- `docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/R3-BRAND-CONTROL-CENTER-WIRING-SCHEMATIC.md`
- `docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/CURRENT-VISUAL-EDITOR-COMPONENT-MAP.md`
- `docs/dropins/dbvc-visual-editor-brand-controls-guide/00-GOVERNING-DIRECTIVES.md`

## Files

| File | Purpose |
|---|---|
| `index.html` | Happy-path drawer at 1440&times;900, All tab active, site content visible on the right. **All 33 fixture rows pre-rendered inline as `<tr>` markup**; no JavaScript. |
| `states.html` | 17-cell state gallery. Every state from schematic §4 + the drawer-specific states in §8. **Each cell's row set is pre-rendered inline**; no JavaScript. |
| `styles.css` | Scoped stylesheet, mirrored base tokens plus component-local drawer tokens. Declares the one new proposed token `--dbvc-ve-z-drawer: 120015`. |
| `screenshots/` | Wireframe SVG placeholders for the primary screens. PNG capture is not planned (see `screenshots/README.md`). |
| `DESIGN-DECISIONS.md` | Why each state renders the way it does; toolbar-icon choice + rationale; tab structure + rationale; alternatives considered; open questions. |
| `COMPONENT-NOTES.md` | Per-selector mapping table matching the R1 Media Manager `WIRING-SCHEMATIC.md` format. |

**No `mockup.js`.** The purely-static approach was a deliberate maintainer call &mdash; a reference mockup should be readable without following JS logic. If you need the fixture data as structured data, it lives as a top-of-file HTML comment inside `index.html`.

Read `DESIGN-DECISIONS.md` first if you are Codex &mdash; sections 1 and 2 record the two calls the schematic asked the designer to make (toolbar icon, tab structure), and the fixture section (§8) records the "pre-render inline" call.

## How to view

Open `index.html` in a desktop browser. No server, no build step, no dependency install. `states.html` is the state gallery.

Supported viewports (D-058 &mdash; desktop only):
- **1440 &times; 900** (primary)
- **1280 &times; 720** (secondary)

Do NOT resize the browser below the 1280 minimum; there is no mobile layout and none is planned. The drawer keeps its fixed 480px width at both viewports; the site backdrop is what shrinks.

The tabs, filter chips, and Open buttons in the DOM are static &mdash; they do not react to clicks. This is intentional. The mockup is a visual + DOM-shape reference, not a runtime prototype.

## Fixtures &mdash; hand-authored per §9.2, pre-rendered inline

The schematic (§9.1) prefers the curated JSON at `addons/visual-editor/curation/vertical-approved-controls.json` when it exists. **It does not exist yet** &mdash; the R3-BX BCC Curation admin tool has not been run against the reference Vertical site. So per §9.2 the mockup ships a hand-authored fixture set.

Where the previous draft carried the fixture as a JS object built into the DOM at load, this drop pre-renders every row directly as `<tr>` markup inline in `index.html` and `states.html`. The fixture summary (33 records with their category / group / priority / status / field-family) is preserved as an HTML comment at the top of `index.html` for the production translator.

The fixture:
- Covers **33 controls across all 7 curation categories** (Brand, Contact, Content, Design, Layout Elements, Legal, SEO).
- Includes at least one row of **each status** (`available`, `inspect_only`, `unsupported`, `unavailable`) so the states matrix is honest.
- Includes at least one row of **each priority** (`must`, `should`, `nice`).
- Includes at least one row of **each fieldFamily** in the schematic whitelist (`text`, `image`, `gallery`, `relationship`, `post_object`, `other`).
- Uses only the safe list-projection keys (`publicId`, `label`, `category`, `group`, `ownerType`, `ownerSubtype`, `fieldFamily`, `status`, `meta`, plus a first-class `priority` field per maintainer's confirmed working assumption). **No `source`, `fieldKey`, `selector`, `ownerId`, `path`, `descriptor`, or `token` on any row.**

When the curation JSON does land, the production renderer maps its records to this same shape (see schematic §9.1 mapping table). The mockup does not need to be regenerated to switch data sources &mdash; the same DOM shape holds.

## Product boundary held by this mockup

Present:
- Left-anchored tabbed drawer with tab strip, filter chips, sticky-header table body, footer count.
- List + Open action per row (per §5 anatomy).
- Every state from schematic §4 (loading-initial, loading-refresh, empty, empty-filtered, error, list, opening, opened, open-error, inspect-only, unsupported, unavailable).
- Drawer-specific states from §8 (partial slide-in, per-category tab, filter chips active, sticky-header mid-scroll, drawer + panel coexisting, empty-tab, reduced-motion open, empty-filtered-in-category).

Deliberately absent (matches R3 scope, not R4):
- Search-heavy expanded UI (R4).
- Pinned controls / named workspaces / usage indexing / preview mode (R4).
- Bulk save / stage / undo (R4).
- Design-token editing / direct Bricks setting writes (R4).
- The main editor panel itself &mdash; the mockup ends at the moment the row's Open control is clicked; the panel is reused as-is (schematic §7).
- Real REST calls.
- Any real Bricks or ACF data.
- **Any JavaScript.** The mockup ships zero scripts.

## Constraints observed

No global reset, no unscoped element selector, no framework, no CDN, no font or icon kit, no build step, no minification, no inline handler, no network request, no persistence, no production state store, **no JavaScript at all**. Icons are inline SVG.

## Not modified

Production PHP/JS/CSS, tests, generated agent docs, REST routes, descriptors, the Shared Globals toolbar entry, the Shared Globals popover, the Media Manager modal, the Media Library, mutation systems &mdash; all untouched. Governing-directives §7 (site visible behind workspace) and the schematic §5.1 drawer contract are the only new commitments.

The `sliders` icon key referenced by the mockup's toolbar entry has already been registered in `overlay-app.js` &mdash; the mockup is now backed by real code on that front.
