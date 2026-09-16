# R6-C Frontend Site Manager Workspace — Claude Code mockup capsule

**Status:** Brief frozen by R6-B on 2026-09-15. R6-C (the static mockup) is **not yet authorized** — the maintainer authorizes D1 explicitly, then each sub-phase in turn, exactly as R1-D and R3/R4 were run.
**Precedents to match, in this order:** `docs/ui-mockups/dbvc-visual-editor/r4-expanded-brand-control-center/` (most recent, same drawer family), `r3-brand-control-center/` (D-061 shell), `r1-media-manager/` (state-gallery + wiring-schematic format).
**Runtime authority:** `releases/R6-WORKSPACE-STATE-CONTRACT.md` (R6-B). The mockup renders that contract; it does not invent one.

This is the smallest authoritative startup context for the static R6 mockup. Read linked files only when this capsule names them or a discrepancy requires source verification. Do not preload the wider package.

---

## 0. Design inventory — what already exists (surface this first)

| Asset | Where | Status for R6 |
|---|---|---|
| Concept PNG — persistent "Website Manager" left rail with search, Current, Content types, Brand & Globals, Shared Content, Workspaces, Changes | `ui-ux/reference-images/01-frontend-website-manager-shell.png` | **Visual direction only.** "Workspaces", "Changes", "Shared Content" sub-lists, pins, `⌘K`, `Publish` are **not** R6 scope. |
| Concept PNG — object navigation workspace (left rail + centre "Site Manager Workspace" modal with Recently Opened, Pinned, Quick Actions, Users, Media rows, counts) | `ui-ux/reference-images/03-frontend-site-manager-workspace.png` | **Visual direction only.** The centre modal, Recently Opened, Pinned, Users, Media rows, per-type counts and `Open Fields` are **out** (D-066/D-067/D-069, release §Out of scope). Keep: rail density, row anatomy (title / type / three actions), type tabs, current-object card. |
| Concept PNG — Global Control Editor grid | `docs/dropins/visual-editor-brand-controls--mockups/ChatGPT Image Aug 14, 2026, 09_41_43 PM (3).png` (not in `reference-images/`) | Not R6. Shows the same left rail; useful only for rail continuity. |
| Concept PNGs 02 / 04 | `reference-images/` | BCC / Media Manager — already realised by R3/R4 and R1 mockups. |
| R3 BCC static mockup (drawer shell, D-061) | `docs/ui-mockups/dbvc-visual-editor/r3-brand-control-center/` | **Inherit the shell**: 480px left drawer, admin-bar/toolbar insets, header/close, tab strip, internal scroll, state gallery layout, `screenshots/*.svg` wireframe convention. |
| R4 expanded BCC static mockup | `docs/ui-mockups/dbvc-visual-editor/r4-expanded-brand-control-center/` | **Inherit the doc set shape** (README / COMPONENT-NOTES / DESIGN-DECISIONS / states.html) and the two viewports. |
| R1 Media Manager static mockup | `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/` | Inherit `WIRING-SCHEMATIC.md` format and the state-gallery toggles pattern (`mockup.js` local-only). |
| Live production surfaces | `addons/visual-editor/assets/css/overlay.css` (tokens `:root` lines ~1–130, toolbar `.dbvc-ve-toolbar*`, popover `.dbvc-ve-toolbar-popover*`, object rows `.dbvc-ve-toolbar-object*`, dark-mode block), `control-center.css` (drawer geometry lines 1–60) | The workspace must read as a sibling of these. Real toolbar is now a dark pill (R5.later-toolbar-skin.a/b/c) with system dark mode (R5.later-darkmode.a/b/c) — the R3/R4 mockup toolbars predate that reskin; **match the live CSS, not the older mockups**, for the toolbar strip. |

**There is no R6 static mockup yet.** R6-C produces it under `docs/ui-mockups/dbvc-visual-editor/r6-site-manager-workspace/`.

---

## 1. Authority and working-tree safety

- Repository root: `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`
- Branch `codex/visual-editor-r6-site-manager-workspace` (from `9778885`). Recheck branch/HEAD/status first. Preserve all existing work: do not reset, restore, stash, clean, stage, commit, or push.
- R6-C writes **only** under `docs/ui-mockups/dbvc-visual-editor/r6-site-manager-workspace/` (plus the package manifest/checksum entries if the maintainer asks). Do not edit production PHP/JS/CSS, tests, generated agent docs, or tracking files — Codex/Claude does production translation in R6-D.
- Desktop only, permanent (D-058): **1440×900 primary, 1280×720 secondary**. No tablet/mobile/touch/slide-over states, none planned.

## 2. Minimal read set

1. `AGENTS.md` and `addons/visual-editor/AGENTS.md`.
2. This file.
3. `releases/R6-WORKSPACE-STATE-CONTRACT.md` — §1 layering, §4 anatomy, §5 coexistence, §6 focus/Escape, §9 state matrix (S-01…S-19).
4. `ui-ux/fixtures/workspace-r6b-view-model.json` — the only display data allowed.
5. `ui-ux/CURRENT-VISUAL-EDITOR-COMPONENT-MAP.md` §1, §2, §6, §7, §8, §10 (tokens, single live region, class naming, focus patterns).
6. Targeted CSS evidence: `overlay.css` `:root` tokens + `@media (prefers-color-scheme: dark)` block, `.dbvc-ve-toolbar*`, `.dbvc-ve-toolbar-object*`; `control-center.css` lines 1–60.
7. Reference images 01 and 03 for hierarchy/density only.

Inspect `overlay-app.js` / `brand-control-center-app.js` only to confirm a specific interaction convention; never load them wholesale.

## 3. Product boundary (what the mockup may show)

**Allowed actions** (contract §3.1): close; switch section (Navigate / Tools); pick object type; search; clear search; retry; load more; open frontend (same tab); open backend (new tab); current-object open/edit; Review Fields; Brand & Globals; Media Manager; Edit active object; Exit Visual Editor.

**Must not appear or be implied:** favorites/pins, recent objects, named workspaces, `⌘K`, `Publish`, `Open Fields`, per-type counts, users, media/attachment rows, object create/delete/reorder, bulk actions, inline editing, drag/drop, a second field editor, a centre modal, mobile layouts, any URL with field/owner/nonce params, any write.

**Honesty rules the visuals must encode:** a row without `hasFrontendRoute` shows **Edit** (new-tab affix in the accessible name) as its only action and its status text (Draft / Pending / Scheduled / Private / "backend only" term) in `__meta`; status is never colour-only; results never claim "page X of Y" or totals beyond the visible count + Load more.

## 4. Shell contract (inherit D-061, extend for R6)

- Left-anchored, **480px** fixed, `top: 32px` (admin bar), `bottom: 76px` (toolbar strip), no backdrop, site visible on the right, drawer shadow/border, slide-in with `prefers-reduced-motion` fallback.
- `role="complementary"` landmark (not `dialog`): no focus trap; outside-click does **not** close; Escape closes only when no higher surface is open (contract §6).
- Anatomy top→bottom exactly as contract §4: header (title "Site Manager" — designer proposes final copy, maintainer approves) + close; current-object card; section tablist (Navigate | Tools); Navigate body = search → type tablist (from `types[]`, horizontally scrollable, never wraps; non-viewable types carry a "backend only" affix) → single polite live-region status line → results list → footer (Load more / end); Tools body = 5 entries with availability gating.
- Row anatomy: type glyph · title (2-line clamp, `title=`) · meta `typeLabel · status` · actions right-aligned (primary Open or Edit; secondary Edit when both).
- Stacked states to compose: **S-11** BCC drawer painted over the inert workspace (both visible), **S-12** Media Manager modal over the inert workspace, **S-13** main editor panel clamped to the right of the drawer.
- Toolbar strip: reproduce the **live** dark pill toolbar with a new first dock button "Workspace" (`aria-expanded`, orange `[aria-expanded="true"]` fill per R5.later-toolbar-skin.c). Existing buttons unchanged.
- Dark mode: provide the `prefers-color-scheme: dark` treatment via the same token names the production `overlay.css` dark block redefines; no new colours.

## 5. Visual and code conventions

- Outer scope: `.dbvc-ve-workspace-mockup`. Component prefix: `dbvc-ve-workspace`; BEM elements/modifiers; `is-*` state classes. JS hooks `data-mockup-*` only — **do not** use `data-dbvc-ve-workspace-action` or the production event names.
- Reuse `--dbvc-ve-*` colour/typography/radius/shadow/focus/z tokens; add only scoped `--dbvc-ve-workspace-*` sizing variables. The one production-token proposal (`--dbvc-ve-z-workspace` = 120005) is already pinned in the contract — do not propose others without a DESIGN-DECISIONS entry.
- No global reset, no unscoped element selectors, no framework/CDN/font/icon kit, no build step, no minification, no inline handlers, no network, no persistence, no production state store. Inline SVG glyphs only.
- Semantic controls; `aria-selected` on tabs with roving tabindex intent; `aria-expanded` on the toolbar trigger; associated labels; visible `:focus-visible`; text + colour for status; reduced-motion handling; sticky search/type strip inside the scroll container.

## 6. Output contract

After D2 is authorized, write only under `docs/ui-mockups/dbvc-visual-editor/r6-site-manager-workspace/`:

| File | Content |
|---|---|
| `index.html` | S-02 happy path at 1440×900: open drawer, Navigate section, `populated` fixture query (20 rows incl. every honest-route variant), current-object card, live toolbar with Workspace button active. |
| `states.html` | Gallery of S-01, S-03…S-19 (each labelled with its S-id), including the three composites S-11/S-12/S-13 and the Tools section in both `tools` and `toolsAllFlagsOff` variants. |
| `styles.css` | Scoped mockup stylesheet (light + dark blocks). |
| `mockup.js` (optional) | Local-only state toggles for the gallery. |
| `README.md` | Release goal, viewports, interaction notes, fixture disclaimer, omissions, JS note, no external assets. |
| `COMPONENT-NOTES.md` | Per-selector: purpose, display data (fixture keys), actions (contract §3.1 names, marked "production name — not wired"), states, accessibility intent. |
| `WIRING-SCHEMATIC.md` | Selector → contract action → `object-search` request → server symbol (`ObjectNavigationReadModel::search/describeTypes`) → states → focus; plus a "not wired in R6-C" section. |
| `DESIGN-DECISIONS.md` | Every visual decision with rationale + rejected alternative, mapped to release-spec / contract section refs; final copy proposals for the `workspace*` i18n keys. |
| `screenshots/` | SVG wireframes for 1440×900 and 1280×720 + composites (R3 convention) if no capture tooling is available. |

## 7. Gated sub-phases

**D1 — Evidence and component plan (authorize first).** Read only. Confirm provenance, read the minimal set, reconcile the fixture against contract §2, and return in chat: proposed component tree, token usage, the S-01…S-19 → gallery mapping, and the schematic map. Create no files. Stop with: `R6-D1 complete. Waiting for explicit R6-D2 authorization.`

**D2 — Default desktop mockup.** After approval: output root + `index.html`, `styles.css`, initial `COMPONENT-NOTES.md` / `WIRING-SCHEMATIC.md`. Render at both viewports if existing tooling permits. Stop for review.

**D3 — States, composites, dark mode, focus.** After approval: `states.html` with every S-id, S-11/S-12/S-13 composites, dark-mode block, reduced-motion, keyboard-focus screens, optional `mockup.js`, screenshots. Stop for review.

**D4 — Handback package.** After approval: finalize `README.md`, `DESIGN-DECISIONS.md`, static validation (scoped-selector grep, no external refs, axe if available), exact file inventory, limitations, and the accepted / adapted / rejected candidate list for Codex to record as decisions. Do not claim production integration.

Stop rather than guess if a design needs absent data, a new action, mutation authority, raw targets, totals, a dependency/download, production-file edits, or conflicts with the contract.

## 8. Copy/paste prompt (for the maintainer)

```text
Produce the R6-C static mockup for the DBVC Visual Editor Frontend Site Manager Workspace.
Read, in order: AGENTS.md, addons/visual-editor/AGENTS.md,
docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/R6-WORKSPACE-CLAUDE-MOCKUP-HANDOFF.md,
docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R6-WORKSPACE-STATE-CONTRACT.md,
docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/fixtures/workspace-r6b-view-model.json.
Run D1 only (read-only). Stop with "R6-D1 complete. Waiting for explicit R6-D2 authorization."
Do not run git operations. Do not edit production files.
```
