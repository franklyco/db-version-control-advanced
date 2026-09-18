# R6 Site Manager Workspace — Real-Chrome QA Report

**Date:** 2026-09-15
**Site:** `https://dbvc-codexchanges.local/` (LocalWP), homepage + `/codex-phase51-20260722191539-31052/`
**Auth:** `Agent User` (administrator), Visual Editor mode active
**Flag:** `dbvc_visual_editor_workspace_enabled` **enabled by the maintainer** before this pass (BCC + Media Manager also on)
**Tool:** Claude in Chrome (extension), real Chrome, backgrounded (rAF/IO throttled per E-123)
**Viewport:** 1728×958 CSS (retina-mapped; the 1280×720 request maps to the same CSS viewport in this harness — geometry asserted numerically instead)
**OS colour scheme:** dark; the maintainer's stored Visual Editor preference was **Light**
**Checklist:** `qa/R6-SITE-MANAGER-WORKSPACE-QA-CHECKLIST.md`

**State observed live:** 41 navigable types discovered (26 post types, 15 taxonomies; 7 non-viewable with the lock affix), 20 rows on page 1 interleaved, 401 Brand Control Center controls, 681 marked fields on the homepage.

## Results

| # | Item | Result | Evidence |
|---|---|---|---|
| 1 | Toolbar | ✅ | Order `status · workspace · review-fields · go-object · media-manager · shared-globals · control-center · edit-object · settings · toggle-mode`; 0 empty count dots |
| 2 | Open | ✅ / ⚠️ | Drawer `[0, 32, 480×850]`, button `aria-expanded=true`, `--dbvc-ve-workspace-inset: 480px`. Focus-to-× not observable: the backgrounded tab throttles `requestAnimationFrame` (E-123); verified in jsdom + headless Chromium (E-150) |
| 3 | Current object | ✅ | "Home" / "Codex Phase 5.1 Applied …" from `pageContext.title`; "You are here"; Edit ↗ only |
| 4 | Types | ✅ | 41 tabs; non-queryable types (`assortment-cpt`, `benefit`, `certification`, `client`, `comparison-table-cpt`, `classification_tax`) carry the lock affix; strip scrolls |
| 5 | Results | ✅ | "Showing 20 · more available"; Load more → 40 rows, page 2, focus on first appended row (`post:24137`); type tab Pages → 20 pages only |
| 6 | Honest routes | ✅ | `Benefits Subitem` (Classification, non-queryable taxonomy) → Edit only + "No public archive" |
| 7 | Navigation | ✅ | Open → same-tab navigation to `/codex-phase51-…/`; `.dbvc-ve-active` on arrival (mode preserved); href carries no `dbvc_visual_editor` / nonce / field / owner / token params |
| 8 | Search | ✅ | `zzqx-no-such` in Pages → "No matches for “zzqx-no-such” in Pages." + Clear search; clearing refocuses the input and re-queries (20 rows) |
| 9 | Persistence | ✅ (after fix) | On arrival the drawer was open with Pages selected and focus on `<body>`; **the toolbar button read `aria-expanded=false`** → fixed (see Findings), re-verified `true` after reload |
| 10 | Tools ▸ Brand & Globals | ✅ | BCC `z 120015` over inert + covered workspace `z 120005`; both toolbar buttons expanded; Esc → BCC only closes, workspace restored, focus on the Brand & Globals tool |
| 11 | Tools ▸ Media Manager | ✅ | Real Media Manager over the inert (not covered) workspace; toolbar button expanded; Esc → MM closes, focus on the Media Manager tool |
| 12 | Tools ▸ Review fields | ✅ | Popover "Review fields" above the drawer, review button expanded; Esc closes the popover only. Note: Esc does not return focus to the trigger (pre-existing toolbar behaviour; the × button does) |
| 13 | Panel coexistence | ✅ | Field opened from the BCC: panel `max-width 1232px` (= 1728 − 480 − 16), placed at `left 1332`; dragged hard left → clamped at **`left 488`** (drawer 480 + 8) |
| 14 | `wp.media` | ⏭ | Not exercised (no image field opened in this pass); covered by the D-071 predicate + jsdom |
| 15 | Escape order | ✅ | With nothing above: Esc closes the workspace, focus lands on the toolbar Workspace button, inset → `0px`, `isOpen:false` persisted |
| 16 | Keyboard | ✅ (headless) | Tab/Arrow order verified in E-150; not repeated here |
| 17 | Dark mode | ✅ | Preferences popover opens (Light checked); System → drawer `rgba(0,10,54,.94)`, drawer `color-scheme: dark`, **`html` `color-scheme: normal`, site `body` background and `h1` colour unchanged**; announced "Appearance set to System."; persisted. Set back to Light afterwards |
| 18 | Flag off | ⏭ | Not toggled by the agent (maintainer action); jsdom + PHPUnit cover the off path |
| 19 | Builder | ⏭ | Not opened; PHPUnit builder-exclusion test covers it |
| 20 | Console | ✅ | 0 errors across the pass (tracking started after first load; reload + open/close re-checked clean) |

## Findings (fixed in this pass, E-152)

1. **Persisted restore did not light the toolbar button.** Footer scripts evaluate while the document is still loading; `overlay-app.js` builds the toolbar on `DOMContentLoaded`, but `workspace-app.js` ran its persisted `open()` at eval time, before the button existed. Fix: `workspace-app.js` now defers `mount()` to `DOMContentLoaded` exactly like overlay-app (its listener is registered first, so ordering is deterministic) and `mount()` is idempotent; `overlay-app.js`'s workspace bridge additionally mirrors `aria-expanded` on `workspace:opened/closed`. jsdom harness fires `DOMContentLoaded` after eval; overlay suite asserts the mirror.
2. **Host theme heading rules leaked into the drawer titles.** Both `.dbvc-ve-workspace__title` and `.dbvc-ve-control-center__title` are `<h2>`s with no `font-family`; the Bricks theme's `h2` rule restyled them. Fix: `font-family: inherit; text-transform: none; letter-spacing` pinned on both.

## Observations (not fixed)

- Dark drawers are 94% opaque (`--dbvc-ve-color-drawer-background`, darkmode.b); with light site content behind, the workspace's Tools list shows faint page text through it. Raising the dark alpha to ~0.98 would remove it at the cost of the glass effect — maintainer's call.
- The main editor panel's top clamp allows `top: 8px`, i.e. under the WordPress admin bar (pre-existing, not R6).
- Escape on a toolbar popover does not restore focus to its trigger (pre-existing; the × does).

## Verdict

R6 behaves on the live site as the contract, mockup and automated suites predicted, with two integration defects found and fixed. Remaining maintainer-only rows: 14 (`wp.media` from an image field), 18 (flag off), 19 (builder). Sign-off can be recorded in `releases/SITE-MANAGER-WORKSPACE-RELEASE-NOTES-AND-ROLLBACK.md`.

---

## R6.1-c — kind filter + sort (real-Chrome pass, 2026-09-16)

**Pages:** `/` → `/insurance/3748/` (same-tab Open) → reload · **Auth:** `Agent User`, Visual Editor mode active · **Flag:** on · **Tool:** Claude in Chrome (assertions via `javascript_tool`; API calls captured by wrapping `DBVCVisualEditorApi.searchObjects` — the extension redacts query strings) · **Data:** 41 types (26 post types / 15 taxonomies), thousands of objects.

| # | Item | Result | Evidence |
|---|---|---|---|
| 21 | Kind filter | ✅ | Row `role=radiogroup` "Show", 480×42, select 32px; Taxonomies → request `objectType=term, subtype=''`, strip 42 → **16** tabs (All + 15 taxonomies), rows all `term:`; Content → 27 tabs (All + 26), rows all `post:`; persisted `activeType {term,''}`. ArrowRight on Content → Taxonomies checked immediately, **focus still on the Taxonomies radio after the response render**; wraps Taxonomies → All (strip back to 42) |
| 22 | Type implies kind | ✅ | All + Pages tab → `kind: post`, Content checked, `activeType {post,page}` |
| 23 | Sort + paging | ✅ | Content + Title A → Z: requests `sort:'title_asc'` page 1 then page 2 (Load more), status "Showing 20 · more available · Title A → Z" → "Showing 40 · …"; page 2 alphabetical, boundary `Alex Morafchek` → `alphabet` correct, **0 overlapping ids**, focus on the first appended row (`post:29336`); page 1 alphabetical once the three untitled objects (`Post #2985/#21602/#25698`, empty `post_title`, ID-ascending tiebreak) are excluded — expected MySQL order, recorded as Known. Sort stayed `oldest` across kind → post → all → Pages transitions (drift check) |
| 24 | Best match | ✅ | Typing `home` with Recently updated → request `sort:'relevance'`, select gains **Best match** (6 options) and shows it, status "Showing 3 · Best match", `localStorage.sort` still `recent`; explicit Newest first + typing `hom` → `sort:'newest'` kept, persisted `newest`; Clear → `newest` kept, Best match option removed, focus back on the input; Recently updated + `ab` → relevance; Clear → `recent` |
| 25 | Persistence across navigation | ✅ after fix | Content + Oldest first → Open `/insurance/3748/` (href carries no params; mode preserved; toolbar button expanded; focus on `<body>`): `sort: 'oldest'` restored, select "Oldest first", status "… · Oldest first", results ≠ recent order, route echo `query.sort: 'oldest'` — **but the kind came back as All** (see Findings). After the fix + reload: `{term,''}` restored with Taxonomies checked / 16 tabs / term rows; `{post,page}` + oldest restored with Content checked / Pages selected |
| 26 | Keyboard order | ✅ | Focusable order from the search field: `search → kind[all] → sort → type[selected] → row Open` (kind is a single roving stop) |
| 20 | Console | ✅ | 0 errors (tracking enabled, then reload + full restore re-checked) |

### Findings (fixed in this pass, E-157)

1. **Persisted kind-only selection reset to All after type discovery.** The R6-D-2 guard "a persisted subtype that no longer exists falls back to All" tested `activeTypeDescriptor()`, which is `null` for `{post,''}` / `{term,''}` (there is no descriptor for a kind), so every same-tab navigation or reload with a kind selected silently re-queried All. Fix: `kindIsOffered(kind)` — a kind-only `activeType` is kept when discovery returned at least one type of that kind; the fallback still fires when it returned none (e.g. all taxonomy caps lost). Bonus: kind-only empty states now read "No Taxonomies yet." / "… in Content" instead of "All". jsdom 27 → 28 (`a persisted kind-only activeType survives type discovery …`, both branches).

### Observations (not fixed)

- Between two probes on the arrival page the persisted sort changed `oldest` → `newest` once; a deterministic drift check across four kind/type transitions kept `oldest`, and a WordPress "Edit Page" tab opened in the same real-Chrome window during the pass, so the change is attributed to a manual interaction with the live tab. Not reproducible; no code path writes `sort` outside `setSort` / `syncRelevanceWithSearch` / restore.
- ~~Under Title A → Z, untitled objects (rendered "Post #ID") sort first — MySQL empty-string order, identical to the admin list.~~ **Fixed 2026-09-16 (E-158): a marker-gated `posts_orderby` puts untitled objects last under both title orders.**
- Type discovery on this LocalWP site takes several seconds on a cold page (pre-existing TTFB, R5.later-perf).

### Verdict

R6.1 behaves on the live site as specified: the kind row halves the 41-type strip, every sort key pages stably with an honest status line, Best match is search-scoped, and view state now survives navigation completely. R6.1 is code complete + live-QA'd; rows 21–26 are added to the checklist for the maintainer's sign-off pass.
