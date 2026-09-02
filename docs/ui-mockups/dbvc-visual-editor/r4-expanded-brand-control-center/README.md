# R4 Expanded Global & Brand Control Center — static mockup

Non-production UI mockup for the DBVC Visual Editor **R4-B** slice (UI contract + mockup, per `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R4-EXPANDED-GLOBAL-BRAND-CONTROL-CENTER.md`). **Purely static HTML/CSS**, zero JavaScript. Every row and state is pre-rendered inline so a reviewer can read the intended production markup without following JS logic — matches the R3 mockup's static-first stance.

R4 is the drawer's transition from *proof-of-registry* (R3-C-2 with 400 real records but flat, minimally decorated) to *client-facing workspace* (rich value summaries, descriptions, collapsible group headers, search across labels+descriptions+owner labels, category ↔ provider view-mode toggle). R4 adds **no new mutation authority** — every row still opens into the existing main editor panel via the R3-C-1 open route; save behavior is unchanged.

## Companion docs (authoritative when they and this mockup disagree)

- `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R4-EXPANDED-GLOBAL-BRAND-CONTROL-CENTER.md` — canonical release spec (personas, in-scope, out-of-scope, acceptance criteria).
- `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/R3-BRAND-CONTROL-CENTER-WIRING-SCHEMATIC.md` — R3 drawer shell contract (D-061). R4 inherits and extends; it does not redesign.
- `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/CURRENT-VISUAL-EDITOR-COMPONENT-MAP.md` — tokens, focus behavior, single-live-region rule.
- `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/00-GOVERNING-DIRECTIVES.md` — desktop-only D-058, preserve-source-authority §5, no over-engineering §4.
- `../r3-brand-control-center/` — R3 mockup this one extends. Same `.dbvc-ve-control-center*` BEM prefix.

## Files

| File | Purpose |
|---|---|
| `index.html` | Happy-path drawer at 1440×900. All R4 decorations on: category-folded view active, search field visible, filter chips row, one collapsible group header expanded, ~20 pre-rendered rows spanning every value-summary family (text, image, gallery, relationship, post_object, color_picker, wysiwyg) with fabricated descriptions on ~half. |
| `states.html` | State gallery. Every R4 state from the release doc plus the view-mode toggle demo (category-folded ↔ provider-partitioned side-by-side). |
| `styles.css` | Component-scoped stylesheet. Extends R3's `.dbvc-ve-control-center*` selectors with R4-only additions (`__description`, `__value-summary` family variants, `__group-header`, `__view-toggle`, `__search`). Reuses every existing `--dbvc-ve-*` token in `overlay.css`. |
| `DESIGN-DECISIONS.md` | The 7 pinned decisions (6 baseline + the category view-mode toggle), rationale + rejected alternatives per choice, mapped to release-doc line refs. Read this first if you're Codex — it explains why the DOM looks the way it does. |
| `COMPONENT-NOTES.md` | Per-selector mapping, `data-*` action vocabulary, per-family value-summary rendering contract, state machine, fixture reconciliation, invented-names table. Format matches R3's COMPONENT-NOTES. |

## How to view

Open `index.html` in a desktop browser. No server, no build, no dependencies. `states.html` is the gallery.

Supported viewports (D-058: desktop-only):
- **1440 × 900** (primary)
- **1280 × 720** (secondary)

Do NOT resize below 1280 — no mobile layout, none planned. The drawer keeps its 480px fixed width at both viewports; the site backdrop shrinks.

Nothing in `index.html` or `states.html` reacts to clicks. The mockup is a visual + DOM-shape reference, not a runtime prototype.

## Fixture strategy

- **Real category + label + priority data** — the ~20 rows in `index.html` are drawn verbatim from `addons/visual-editor/curation/vertical-approved-controls.json` (the R3-BX curation export, 400 approved records at time of writing). Categories, labels, group titles, and priority tiers are all real.
- **Fabricated overlays** — `description` per row (~15 sample descriptions, non-sensitive, brand-neutral), per-family value summaries (fake truncated text, fake filenames, fake color hex, fake connected-item titles), and the state-gallery's failure / loading / empty branches.
- **No real client content** in the markup. Sample descriptions are austere placeholders per the maintainer's fabrication approval (2026-08-29).
- **The Shared Globals row is included** (`shared_globals:settings_globals_default_posts`) so the "Shared Global" badge shows in-mix with Vertical rows under the folded-category view.

When R4-A produces the real backend fields (`description`, `sortKey`, `currentValueSummary`, server-side `q=` / `category=` / `status=` / `family=` params), the mockup does not need to be regenerated — the DOM shape holds and the production renderer swaps in the real data.

## Product boundary held by this mockup

**Present:**
- Left-anchored drawer preserved from R3 (D-061), 480px fixed, ~615px tall at 1440×900.
- Category ↔ provider **view-mode toggle** in the drawer header, remembered per-viewer via `localStorage`.
- Search input in the filter strip that searches across `label`, `description`, `group`, `ownerSubtype`, and category slugs (matches R4's "approved metadata, not raw arbitrary option values" data rule — §data rules).
- Filter chips carried over from R3-C-2: status, priority, field family.
- Collapsible group headers within each category (ACF field-group titles from `record.group`), collapsed by default, per-viewer preference.
- Per-row **description** as a muted second line under the label (only rendered when the record emits `meta.description`).
- Per-row **value summary** on the right side before the Open button, type-safe per field family (text truncation, image thumb, gallery strip, connected-item count, color swatch, WYSIWYG stripped-text preview).
- Every state from R4 release doc §states: loading-initial, loading-refresh, no-controls-registered, no-search-matches, provider-error, unavailable-source, unsupported-family, inspect-only-source, permission-filtered, descriptor-loading, save-and-reload; plus R3-inherited list / opening / opened / open-error / empty-filtered.

**Deliberately absent (matches R4 scope):**
- Inline editing inside list rows (release doc explicitly forbids: "Do not create inline editing inside list rows in R4"). Every row still opens into the existing main editor panel.
- Enabling R5.x field families (color_picker, text-family editing, etc.) — those are R5 slice work.
- Pinned controls, named workspaces, completion tracking, usage indexing, temporary preview, batch editing, Site Manager drawer — all explicitly out of scope in the release doc.
- New custom database tables — no.
- Any real REST calls — the mockup ships zero scripts.
- Any real client content — fabricated descriptions only.
- Bricks-Builder targeting — mockup runs standalone.

## Constraints observed

No global reset, no unscoped element selector, no framework, no CDN, no font or icon kit, no build step, no minification, no inline handler, no network request, no persistence, no production state store, **no JavaScript at all**. Icons are inline SVG.

## Not modified

Production PHP/JS/CSS, tests, generated agent docs, REST routes, descriptors, the Shared Globals toolbar entry, the Shared Globals popover, the Media Manager modal, the Media Library, mutation systems — all untouched by this mockup. Governing-directives §7 (site visible behind workspace), the R3 §5.1 drawer contract (D-061), and the R4 release doc's in-scope / out-of-scope boundaries are the commitments this mockup makes.
