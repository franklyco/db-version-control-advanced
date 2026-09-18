# R6 Site Manager Workspace — Real-Chrome QA Checklist (maintainer)

**Purpose:** the one gate the agent could not run — the workspace exercised in real, foregrounded Chrome on the LocalWP site with the feature flag **on**. Everything below has an automated twin (jsdom / PHPUnit / headless Chromium, E-149/E-150); this pass confirms the same behaviour with the real toolbar, real BCC, real Media Manager, real `wp.media` and a real OS colour scheme. Tick, note the viewport, and paste the result table into a `qa/R6-…-QA-REPORT.md` in the R4-D-2 report format.

**Setup**
1. Settings → Visual Editor → tick **Site Manager Workspace** (leave Brand Control Center and Media Manager on so the Tools tab has live targets).
2. Open any page in Visual Editor mode at **1440×900**, then repeat the starred rows at **1280×720**.
3. Reload once with DevTools console open — expect **0 errors**.

| # | Item | Expected | 1440×900 | 1280×720 |
|---|---|---|---|---|
| 1 | Toolbar | `grid` Workspace button is **first in the dock**; gear (Preferences) sits between Edit and Exit; no stray orange dots on buttons without a count | | |
| 2 | Open ★ | Click Workspace → drawer slides in on the left (480px, below the admin bar, above the toolbar strip); button turns orange; focus lands on the drawer's × | | |
| 3 | Current object | Card shows the page's real title, "Current page · You are here", **Edit ↗** only (no Open for the page you're on) | | |
| 4 | Types | Tab strip = All + the site's public post types / taxonomies minus Settings exclusions; non-queryable types carry a lock affix; strip scrolls horizontally with an edge fade | | |
| 5 | Results ★ | Page 1 renders (≤ 20 rows); "Showing N · more available" when applicable; Load more appends and focuses the first new row; end state says "No more results" | | |
| 6 | Honest routes | A **draft** shows Edit only (no Open); a published post on a non-viewable type shows "No public page"; a term in a non-queryable taxonomy shows "No public archive" | | |
| 7 | Navigation | Open → same tab, Visual Editor mode still active on the new page (cookie); Edit → new tab, wp-admin editor. No `dbvc_visual_editor` / nonce / field / owner params in any URL | | |
| 8 | Search | Typing debounces (~180 ms); no-match state names the query + type and offers Clear search; clearing refocuses the input | | |
| 9 | Persistence ★ | Navigate via Open to another page → drawer is **already open** on arrival, same type selected, **focus not moved** into the drawer | | |
| 10 | Tools ▸ Brand & Globals ★ | BCC opens **over** the workspace; workspace is greyed/inert (no clicks); both toolbar buttons orange; Esc closes the BCC only and focus lands on the Brand & Globals tool | | |
| 11 | Tools ▸ Media Manager | Media Manager opens over the workspace; closing it restores the workspace and focus to the Media Manager tool | | |
| 12 | Tools ▸ Review fields | Review Fields popover opens above the drawer; Esc closes the popover only; focus returns to the tool | | |
| 13 | Panel coexistence ★ | Review fields → open a field → editor panel is placed **right of the drawer**; dragging it left stops at the drawer edge; close the drawer → panel bounds relax | | |
| 14 | `wp.media` | From an image field in the panel, open the media modal; Esc closes the modal only (workspace untouched); after closing, Esc again closes the panel context, then the workspace | | |
| 15 | Escape order | With nothing above it, Esc closes the workspace and returns focus to the toolbar button | | |
| 16 | Keyboard | From ×: Tab → current Edit → section tab → search → type tab → row Open → row Edit → … ; Arrow keys move between type tabs and between rows; Home/End jump | | |
| 17 | Dark mode | Preferences → Dark: drawer, toolbar, popovers, BCC all dark; **the site page itself unchanged**; native inputs/scrollbars inside the drawer dark. Light on a dark OS → light. System → follows OS. Reload keeps the choice | | |
| 18 | Flag off ★ | Untick the flag → reload → toolbar identical to pre-R6 (no grid button); Go To Object popover still works and shows Edit-only for drafts | | |
| 19 | Builder | Open the same page in Bricks Builder → no Visual Editor toolbar / drawer | | |
| 20 | Console | 0 errors across the whole pass | | |
| 21 | R6.1 kind filter | Kind row shows **All · Content · Taxonomies** as a radiogroup; Taxonomies → strip narrows to the taxonomies (+ its own All), rows are terms only; Content → post types only; ←/→ moves the checked kind and keeps focus on it after results load | | |
| 22 | R6.1 type implies kind | With All checked, pick a taxonomy tab → Taxonomies becomes checked and the strip narrows | | |
| 23 | R6.1 sort | Title A → Z: page 1 alphabetical (untitled objects last — see Known), Load more appends the next alphabetical page with no repeats; status reads "Showing n · more available · Title A → Z"; sort survives kind/type changes | | |
| 24 | R6.1 Best match | Empty search + Recently updated: typing switches the select to **Best match** (offered only while a term is present); clearing returns to Recently updated; an explicit order (e.g. Newest first) is kept while typing and after clearing | | |
| 25 | R6.1 persistence | Set Content + Oldest first, Open a row (same tab) → on arrival the drawer is open with **Content checked** and **Oldest first** selected, results in oldest order; reload keeps a picked type tab too | | |
| 26 | R6.1 keyboard order | Tab from the search field: kind (one stop) → sort select → selected type tab → first row | | |

**Known/expected:** under Title A → Z / Z → A, objects with an empty title (displayed as "Post #ID") sort **last** in both orders (E-158); the `wp.media` modal stays light regardless of the appearance override (core-styled); `landmark-unique` only matters on the mockup gallery page; the BCC's own toolbar button now lights when opened from the workspace (R6-E polish).

**Rollback if anything fails:** untick the flag (soft rollback, seconds) — see `releases/SITE-MANAGER-WORKSPACE-RELEASE-NOTES-AND-ROLLBACK.md`.

**VE-prefs-2 (2026-09-16):** Preferences → "Site Manager on page load": Always open → reload with the drawer closed → it opens (no focus steal); Always closed → reload with it open → it stays closed, and switching back to Remember + reload restores the open drawer. The section is absent when the workspace flag is off.
