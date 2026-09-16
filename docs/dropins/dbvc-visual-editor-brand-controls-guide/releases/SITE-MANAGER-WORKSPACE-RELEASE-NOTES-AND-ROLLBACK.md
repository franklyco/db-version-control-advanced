# Site Manager Workspace — Release Notes & Rollback (R6-A → R6-E)

Ship-readiness summary for the Frontend Site Manager Workspace (R6-A read model → R6-B contract → R6-C mockup → R6-D-1 shell → R6-D-2 navigation → R6-D-3 integration → R6-E hardening, all landed 2026-09-15 on branch `codex/visual-editor-r6-site-manager-workspace`, from `9778885`). Same shape as `BRAND-CONTROL-CENTER-RELEASE-NOTES-AND-ROLLBACK.md`. Evidence E-142–E-150; decisions D-065–D-078.

## What shipped

**R6-A — navigation read model (backend).** `Navigation\ObjectNavigationReadModel` extracts Go To Object's query, object-type policy, permission filtering and item shape so the toolbar popover and the workspace share exactly one read model. The existing `GET /dbvc/v1/visual-editor/object-search` route is widened backward-compatibly: `page` / `perPage` (≤ 30, `limit` alias) / `includeTypes` → `types[]`; items gain `statusKey` + `hasFrontendRoute`; `frontendUrl` is emitted only when the object is genuinely viewable by the current user (public status on a viewable type, or private + `read_post`; term taxonomies must be `publicly_queryable`). Per-type `edit_others_*` policy is pushed into SQL via a marker-gated `posts_where` filter so offset pagination stays dense; `canEditPostId` / `canEditTermId` remain as a bounded fail-closed backstop. Users are never enumerated; attachments are never listed.

**R6-B — runtime contract (docs).** `R6-WORKSPACE-STATE-CONTRACT.md`: z-stack, state shape, action/event/API vocabulary, drawer anatomy (D-061 shell), coexistence, Escape precedence, persistence, mode preservation, flag/bootstrap/i18n, S-01…S-19 state matrix.

**R6-C — static mockup + decision record.** `docs/ui-mockups/dbvc-visual-editor/r6-site-manager-workspace/` (D1→D4 gated; axe 0 violations; D-074–D-077).

**R6-D-1 — production shell.** Flag `dbvc_visual_editor_workspace_enabled` (default **off**; `is_workspace_enabled()` = master AND feature; SETTINGS_VERSION 7; Settings → Visual Editor → "Site Manager Workspace"). `AssetLoader` enqueues `assets/css/workspace.css` + `assets/js/workspace-app.js` only when enabled, emits the `workspace` bootstrap block and the `workspace*` i18n family. `overlay-app.js` renders the `grid` Workspace button first in the dock only when enabled and bridges clicks via `dbvc:visual-editor:workspace:toggle`. `workspace-app.js`: `role="complementary"` drawer, current-object card, Navigate/Tools tablist, Tools routes, Escape precedence, `localStorage` persistence (`dbvc-ve-workspace:v1`), inert-under-overlay. New tokens `--dbvc-ve-z-workspace: 120005`; dark `--dbvc-ve-color-text-subtle` 0.52 → 0.58.

**R6-D-2 — object navigation.** `searchObjects(search, objectType, options)`; sticky search + type tablist (backend-only affix), paginated results with honest Open/Edit + notes, skeleton/empty/no-match/error/403 states, stale-response guard, focus continuity, row/tab keyboard nav, `navigate()` API; `PageContextResolver` emits a safe `title`.

**R6-D-3 — tool integration + coexistence.** overlay-app `bindWorkspaceBridge()` (Review Fields routing; panel clamp inset via `--dbvc-ve-workspace-inset`); workspace Escape yields on event flags; focus hand-back after a sibling overlay closes; empty toolbar count badges hidden. Verified against the real BCC script in headless Chromium (E-149).

**R6-E — hardening (this doc).** `VisualEditorWorkspaceHardeningTest`: route closed to logged-out / subscriber / author (base capability) and to capable users without active mode; subscribers get no Visual Editor assets at all; a cold 30-row page costs the same number of queries as a 5-row page (no N+1) and stays ≤ 8; type discovery ≤ 2 (cache warm-up only); `perPage` hard-capped at 30 / default 20 — never the whole inventory. Overlay suite: Go To Object popover fallback renders honest routes on the widened payload with the flag off (D-073). BCC / Media Manager toolbar buttons now reflect `aria-expanded` when opened from the workspace. Automated axe pass on the **production** drawer (Navigate + Tools), toolbar and Preferences popover: **0 violations**; keyboard traversal matches the contract order.

**R6.1 (post-QA follow-up, 2026-09-16).** R6.1-a (E-155): `sort` vocabulary in the read model / route / client (`recent` default · `title_asc` · `title_desc` · `newest` · `oldest` · search-scoped `relevance`, each with an `ID` tiebreak; popover ordering unchanged). R6.1-b (E-156): drawer `__controls` row — kind radiogroup **All · Content · Taxonomies** (the kind is `activeType.objectType`; the type strip is filtered by kind) + sort `<select>` with Best match only while searching; `sort` persisted in `dbvc-ve-workspace:v1` (never `relevance`); status suffix; i18n `workspaceKind*` / `workspaceSort*`; mockup S-20 + decision #14; axe 0. R6.1-c (E-157): live-QA'd in real Chrome (report §R6.1-c, checklist rows 21–26); one defect fixed (persisted kind-only selection reset to All after type discovery → `kindIsOffered()`). Rollback = the R6.1-marked hunks in `ObjectNavigationReadModel.php`, `ObjectSearchController.php`, `api-client.js`, `workspace-app.js`, `workspace.css`, `AssetLoader.php`; the route ignores unknown params, so a client without `sort` keeps today's order.

Adjacent slices landed in the same arc: **VE-prefs-1** (toolbar Preferences popover, System/Light/Dark override — D-079, E-147) and the palette bulk popover layering/radius hotfix (`--dbvc-ve-z-modal`, E-148).

## Feature gates & isolation (verified)

- **Two flags, default-off.** The workspace is active only when the Visual Editor master flag **and** `dbvc_visual_editor_workspace_enabled` are on. With the feature flag off: no enqueue, `bootstrap.workspace.enabled === false`, no toolbar button — the toolbar is byte-identical to pre-R6 (jsdom-asserted).
- **Capability + authentication.** `object-search` `permission_callback` requires a logged-in user with the base capability (`edit_others_posts` by default); the handler additionally requires the active-mode cookie (403 otherwise). Object types are filtered by `edit_posts` / `edit_terms`; per-item `edit_post` / `edit_term` rechecked.
- **Bricks Builder exclusion.** Inherited from `EditModeState::shouldLoadFrontendAssets()` → `FrontendRuntimeGuard`; asserted in `VisualEditorWorkspaceShellTest`.
- **No new write authority, no new routes.** The workspace only reads `object-search` and navigates; every edit still goes through the main panel's existing save pipeline. Exit uses the existing nonce'd toggle URL; no URL params are appended to object links (jsdom invariant).
- **Layering.** `--dbvc-ve-z-workspace` 120005 sits below panel/Media Manager (120010), BCC (120015), toolbar (120020), modal (120030), `wp.media` (560000) — nothing existing was re-ordered.

## Side effects & boundaries

- **Writes:** none.
- **Storage:** `localStorage` `dbvc-ve-workspace:v1` = `{isOpen, section, activeType, sort}` (R6.1-b added `sort`) per viewer; nothing server-side beyond the option.
- **Option added:** `dbvc_visual_editor_workspace_enabled` (add_option `'0'`).
- **Frontend footprint (flag on):** `workspace.css` (~750 lines), `workspace-app.js` (~1 900 lines), one toolbar button, one `posts_where` filter that is added and removed around each read-model query.
- **Payload change visible to the fallback popover:** drafts / non-viewable objects now show `Edit` instead of an invented `Open` (intentional, D-068/D-075).

## Residual / deferred

- **Real-Chrome QA with the flag ON** — done 2026-09-15 after the maintainer enabled the flag: `qa/R6-SITE-MANAGER-WORKSPACE-QA-REPORT.md` (E-152), 16/20 rows ✅ with real data; two integration defects found and fixed (persisted restore vs toolbar mount order; host `h2` leak into drawer titles). Maintainer-only rows left: `wp.media` from an image field, flag-off fallback, builder.
- **Media Manager coexistence** verified in jsdom and by the same event contract as the BCC, but not with the real `media-manager-app.js` in the headless run (flag was off in that page). Covered by the checklist.
- **Real AT** (VoiceOver/JAWS/NVDA) is not a required gate per D-058; automated axe + keyboard coverage is.
- **Go To Object popover retirement** — deliberately deferred (D-073) until the workspace has one release cycle of real use.
- **`composer agent-docs:check`** fails on pre-existing drift unrelated to R6 (7 unmapped R3-era discovery IDs); constant-declared VE options are invisible to the discovery scanner (same as BCC / curation flags).
- **`wp.media` modal** is core-styled and does not follow the VE-prefs-1 appearance override.

## Rollback runbook

1. **Soft rollback (seconds):** Settings → Visual Editor → untick "Site Manager Workspace" (or `wp option update dbvc_visual_editor_workspace_enabled 0`). Toolbar returns to the pre-R6 layout; no data changes; viewers' `localStorage` entries become inert.
2. **Popover shape (if ever needed):** the widened `object-search` response is a superset; nothing consumes `page`/`perPage`/`hasMore`/`types` except the workspace. To restore invented frontend routes for drafts in the popover, revert the `resolvePostFrontendUrl` / `resolveTermFrontendUrl` honesty rules in `ObjectNavigationReadModel` (not recommended).
3. **Hard rollback (code):** delete `src/Navigation/`, `assets/js/workspace-app.js`, `assets/css/workspace.css`, the four `tests/phpunit/VisualEditor{ObjectNavigation,WorkspaceShell,WorkspaceHardening}Test.php` + `tests/visual-editor-workspace-state.test.cjs`; revert the `R6-*`-marked hunks in `bootstrap.php`, `AssetLoader.php`, `PageContextResolver.php`, `ObjectSearchController.php` (restore from `9778885`), `api-client.js`, `overlay-app.js`, `overlay.css`; drop the R6 cases from `tests/visual-editor-overlay-app-state.test.cjs`; set `VisualEditorMediaManagerR1ATest`'s SETTINGS_VERSION assertion back to `'6'`. The option row may be left in place or deleted.
4. **Verify:** `vendor/bin/phpunit --filter 'VisualEditor|Curation'` and the three pre-R6 jsdom suites green; toolbar shows no `grid` button.

## Verification snapshot at wrap-up (2026-09-15)

- `vendor/bin/phpunit --filter 'VisualEditor|Curation'` → **467 tests / 3 253 assertions OK**
- `node --test tests/visual-editor-brand-control-center-state.test.cjs` → **67 pass**
- `node --test tests/visual-editor-media-manager-state.test.cjs` → **42 pass**
- `node --test tests/visual-editor-overlay-app-state.test.cjs` → **23 pass**
- `node --test tests/visual-editor-workspace-state.test.cjs` → **24 pass**
- axe-core 4.11 on the production drawer / toolbar / preferences popover → 0 violations (E-150)
- Headless integration QA (real overlay + BCC + workspace) → all checks pass, 0 console errors (E-149)

**R6.1 snapshot (2026-09-16, E-157):** PHPUnit **470 / 3 295** · jsdom **67 / 42 / 25 / 28** · axe 0 on the drawer with the kind + sort controls (light + dark) · real-Chrome rows 21–26 ✅.

## Sign-off record

```text
Release: R6 Frontend Site Manager Workspace (R6-A…R6-E) + R6.1 (sort + kind filter) + VE-prefs-1 + palette bulk hotfix
Status: Code complete; live QA passed (E-152; R6.1 E-157); awaiting maintainer sign-off (R6 rows 14/18/19; R6.1 rows 21–26)
Branch/commit/change scope: codex/visual-editor-r6-site-manager-workspace — a924639 (PHPUnit suites) · 64ac3b2 (darkmode.d + hotfix) · a21a289 (VE-prefs-1) · 279ab86 (R6 + R6.1); not pushed
Feature flag: dbvc_visual_editor_workspace_enabled (default 0) AND dbvc_addon_visual_editor_enabled
Automated test commands/results: see "Verification snapshot" above
Browser/device QA: headless Chromium integration (E-149/E-150); real Chrome with the flag on (E-152, qa/R6-SITE-MANAGER-WORKSPACE-QA-REPORT.md)
Accessibility QA: axe 0 violations (production DOM); keyboard order verified; real AT not a gate (D-058)
Performance evidence: cold 30-row page ≤ 8 queries, page-size-independent; type discovery ≤ 2; perPage cap 30
Security review: no new routes, no writes; permission callback + mode cookie + per-type/per-item caps; no URL params on object links; builder excluded
Known limitations: see "Residual / deferred"
Rollback procedure: see "Rollback runbook" (soft rollback = untick the flag)
Approved by/date: ________________
```
