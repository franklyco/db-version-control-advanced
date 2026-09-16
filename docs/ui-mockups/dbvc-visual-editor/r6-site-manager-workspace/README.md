# R6 Frontend Site Manager Workspace — static mockup

Non-production UI mockup for the DBVC Visual Editor **R6-C** slice (mockup + design decision record, per `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R6-FRONTEND-SITE-MANAGER-WORKSPACE.md` and the frozen runtime contract `releases/R6-WORKSPACE-STATE-CONTRACT.md`). **Purely static HTML/CSS**; `index.html` loads no JavaScript, `states.html` loads a 1 KB gallery helper with two presentation toggles.

The workspace is the drawer family's third member (after the R3/R4 Brand Control Center): a persistent left-anchored rail for navigating the site's pages, posts, approved CPTs and terms without leaving Visual Editor mode, plus one-tap routes to the existing tools (Review Fields, Brand & Globals, Media Manager, Edit active object, Exit). It edits nothing — the main panel stays the only field editor.

Delivered 2026-09-15 through the gated D1 → D4 sub-phases in `ui-ux/R6-WORKSPACE-CLAUDE-MOCKUP-HANDOFF.md`. Title "Site Manager", tab-strip type navigation, always-visible two row actions, the `__row-note` honesty note and the current-object "You are here" rule were confirmed by the maintainer during D1/D2.

## Companion docs (authoritative when they and this mockup disagree)

- `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R6-FRONTEND-SITE-MANAGER-WORKSPACE.md` — release scope, personas, acceptance criteria.
- `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R6-WORKSPACE-STATE-CONTRACT.md` — state shape, action/event vocabulary, layering, focus/Escape, persistence, S-01…S-20 matrix. **The mockup renders this; it does not define it.**
- `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/R6-WORKSPACE-CLAUDE-MOCKUP-HANDOFF.md` — the brief + design inventory.
- `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/fixtures/workspace-r6b-view-model.json` — the only display data used.
- `../../../docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/CURRENT-VISUAL-EDITOR-COMPONENT-MAP.md` (+ R6 addendum) — tokens, live toolbar, single-live-region rule.
- `../r3-brand-control-center/`, `../r4-expanded-brand-control-center/` — the drawer shell (D-061) this extends.

## Files

| File | Bytes | Purpose |
|---|---:|---|
| `index.html` | 56 945 | S-02 happy path at 1440×900: open drawer, Navigate section, `queries.populated` (20 rows — every honest-route variant), current-object card, live-style toolbar with the new Workspace button expanded. No JS. |
| `states.html` | 381 314 | State gallery: S-01, S-03…S-10, S-14…S-20 (S-09/S-18 split a/b; S-20 added by R6.1-b) as 480×640 inline drawers, plus S-11/S-12/S-13 as 1440×900 composite frames (scaled 0.74 in the gallery). |
| `styles.css` | 48 243 | Scoped mockup stylesheet: token mirror (verbatim from `overlay.css` 2026-09-15), drawer, D3 additions, dark-mode block, gallery/composite scaffold, sibling-surface stand-ins. |
| `mockup.js` | 966 | Gallery-only: "preview dark tokens" and "simulate reduced motion" toggles. No state, no network, no persistence. |
| `COMPONENT-NOTES.md` | 10 836 | Per-selector purpose / display data / actions (production names, not wired) / accessibility / states; invented-names table. |
| `WIRING-SCHEMATIC.md` | 7 897 | Selector → contract action → `object-search` request → server symbol → states → focus; event contract; state diagrams; "not wired in R6-C". |
| `DESIGN-DECISIONS.md` | — | 14 decisions (14 = R6.1-b kind + sort) with rationale + rejected alternative; accepted / adapted / rejected candidate list. |
| `screenshots/` | 15 PNG + README | Headless Chromium captures (repo Playwright, no install): both viewports, scrolled drawer, gallery overview, composites at 1:1, key cells. |

SHA-256 (first 12) at handback: index `21a2f7c70664`, states `a62b0ea0e891`, styles `cb763f3966c8`, mockup.js `7a2a2737afa0`, COMPONENT-NOTES `958814f7fe47`, WIRING-SCHEMATIC `10808ceeaa4e`, DESIGN-DECISIONS `70ef62bbe649`.

## How to view

Open `index.html` or `states.html` directly in a desktop browser. No server, no build, no dependencies, no remote assets. Widen the window to at least 1280px.

Supported viewports (D-058: desktop-only, permanent):
- **1440 × 900** (primary)
- **1280 × 720** (secondary — the drawer keeps its 480px width; the site backdrop shrinks)

Nothing in `index.html` reacts to clicks. In `states.html` only the two header toggles do. The row/tab/search hooks are `data-mockup-*` and exist to show the DOM shape production will emit.

## Fixture strategy

- Every value comes from `workspace-r6b-view-model.json` (`fixtureOnly: true`). Sample site "Example Landscaping"; generic CPTs `project` / `testimonial` and taxonomies `project_type` / `service_area`. No real client content, no VerticalFramework names.
- The item and type shapes are byte-for-byte the R6-A `object-search` payload (E-142). When R6-D-2 renders real data the DOM shape holds; only values change.
- S-14 fabricates 30 rows (three long titles) from the populated set to exercise the `perPage` cap and the 2-line clamp.

## Product boundary held by this mockup

**Present:** persistent 480px left drawer (`role="complementary"`, no backdrop, no focus trap); current-object card; Navigate (search, type tab strip from `types[]`, single polite status line, paginated results with honest Open/Edit, Load more) and Tools (five routes with availability gating); every contract state; BCC-over-workspace, Media-Manager-over-workspace, and panel-beside-drawer composites; light + dark; reduced motion; keyboard focus order.

**Deliberately absent (matches R6 scope):** favourites/pins, recent objects, named workspaces, ⌘K, Publish, per-type counts, users, media/attachment rows, "Open Fields", object create/delete/reorder, bulk actions, inline editing, drag/drop, a second field editor, a centre modal, mobile/tablet/touch layouts, any write.

## Interaction notes (see contract §5–§6 for the full rules)

- Open → focus to ×; close → focus back to the toolbar button. Outside click never closes. Escape closes only when no media modal / Media Manager / BCC / toolbar popover / focused panel is above.
- Both tablists use roving tabindex with ArrowLeft/Right; rows use ArrowUp/Down + Home/End between primary actions.
- Open navigates the same tab (mode preserved by cookie, no URL params); Edit opens the backend in a new tab (sr-only "(opens in a new tab)").
- Under the BCC or Media Manager the drawer is `inert` + `aria-hidden` and visually unchanged; the toolbar shows both buttons expanded (S-11/S-12).

## Validation performed (D4)

- **Scoping**: no unscoped element selectors (`grep -E '^(html|body|button|input|a|ul|li|svg|\*|h[1-6]|p|div|section|aside|header|footer|label|small|strong|code)\b'` → none); every rule under `.dbvc-ve-workspace-mockup` or `.dbvc-ve-workspace`.
- **Self-containment**: no `http(s)://`, `@import`, `url()`, fonts, icon kits, frameworks, base64 blobs; SVG glyphs inline.
- **Production names**: `data-dbvc-ve-workspace-action` and `dbvc:visual-editor:workspace:*` appear in **no** mockup file (grep count 0).
- **State coverage**: every S-id from contract §9 present once in `states.html` (script-checked).
- **axe-core 4.11** (repo dependency, WCAG 2.x A/AA + best-practice), scope `.dbvc-ve-workspace` in `index.html`: **0 violations**, 30 rule passes, 3 "incomplete" contrast checks (gradient/alpha backgrounds axe cannot resolve). Gallery scope: 0 violations attributable to the drawer after D4 fixes (skeleton strip no longer claims `role="tablist"`; dark `--text-subtle` raised — see decision 12); remaining two findings are explained under Known limitations.

## Known limitations

- **Toolbar count badge contrast** (`--dbvc-ve-color-text-light` on `--dbvc-ve-color-primary`, 2.95:1) — the mockup mirrors the *live* toolbar, so this is a **production** defect in `overlay.css` `.dbvc-ve-toolbar__count`, outside R6 scope; recorded for the toolbar owner.
- **`landmark-unique` in `states.html`** — 17 `complementary` landmarks named "Site Manager" (and their "Current object" regions) coexist on the gallery page. Gallery artefact; production has one.
- **Dark `--dbvc-ve-color-text-subtle`**: the mockup ships 0.58 alpha as a *proposed* correction to the live 0.52 (4.45:1). Until `overlay.css` is corrected, production dark meta text is marginally under AA (decision 12).
- Sibling-surface stand-ins (BCC, Media Manager, panel) are shape approximations for stacking review, not copies of the live CSS.
- Static focus emulation (`.is-focus-visible`) and the "motion: none" badge exist only for capture; they are not production classes.
- No real-AT (VoiceOver/JAWS/NVDA) pass — per D-058 automated coverage is the gate; a real-Chrome keyboard pass happens in R6-E.

## Mockup-only JavaScript

`mockup.js` (states.html only): toggles `dbvc-ve-workspace-mockup--dark` / `--motion-off` on `<body>` and mirrors `aria-pressed`. Nothing else.

## External assets / licences

None. All glyphs are hand-drawn inline SVG (stroke paths), including the proposed `grid` toolbar icon.

## Not production-approved by rendering

Per `MOCKUP-DELIVERABLE-CONTRACT.md`: this mockup is visual direction + DOM-shape reference. Production translation (R6-D-1/2/3) reuses existing DBVC components, scoped styles, and the contract's event/action names; it must not copy `styles.css` or `mockup.js` wholesale.
