# DBVC Visual Editor — Resume Prompt (post-R6, boundary 2026-09-15)

**Copy everything below the `---` line into a fresh Claude Code session.**

---

You are picking up the DBVC Visual Editor project after the R6 Frontend Site Manager Workspace arc reached code-complete and passed live QA. Nothing is mid-flight. The only open R6 items are three maintainer-only checklist rows and the sign-off signature.

## Repository + branch + env

- **Working dir:** `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`
- **Branch:** `codex/visual-editor-r6-site-manager-workspace` — created (maintainer-authorized) from `codex/visual-editor-r5-later-arc` tip `9778885`. **The arc is committed (2026-09-16, maintainer-authorized):** `a924639` PHPUnit suites · `64ac3b2` darkmode.d + palette hotfix · `a21a289` VE-prefs-1 · `279ab86` R6 + R6.1. Not pushed. The pre-existing dirty tree (`tmp/wordpress/*`, `docs/agents/*`, `_source/*`, untracked `docs/ui-mockups/dbvc-visual-editor/{r1,r3,r4}-*` mockups) is intentional; preserve it.
- **Do NOT run `git add`/`commit`/`stash`/`reset`/`checkout`/`clean`/`push`** unless the maintainer explicitly asks.
- **Local site:** LocalWP at `https://dbvc-codexchanges.local/`. **Never toggle Visual Editor options from the agent** (`dbvc_addon_visual_editor_enabled`, `dbvc_visual_editor_control_center_enabled`, `dbvc_visual_editor_workspace_enabled`) — the maintainer flips flags in Settings → Visual Editor.
- **Cross-repo Vertical mirror:** any edit to `wp-content/themes/vertical/functions/features/dbvc-visual-editor/includes/class-vf-vertical-control-provider.php` must be `cp`'d to `/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/themes/vertical/functions/features/dbvc-visual-editor/includes/class-vf-vertical-control-provider.php` and `diff -q`'d byte-identical (untouched during R6).

## Current boundary (as of 2026-09-15)

- **R6 code complete + live-QA'd** (R6-A read model → R6-B contract → R6-C mockup → R6-D-1 shell → R6-D-2 navigation → R6-D-3 integration → R6-E hardening → live QA E-152; E-142–E-152; D-065–D-080). Flag `dbvc_visual_editor_workspace_enabled`, default off — **currently ON on the LocalWP site** (maintainer enabled it 2026-09-15). Three maintainer-only checklist rows remain (`wp.media` from an image field, flag-off fallback, builder).
- **R5.later-darkmode.d landed** (E-151, D-080): control-surface + emphasis tokens; the darkmode arc is closed.
- **VE-prefs-1** landed mid-arc (toolbar Preferences popover, System/Light/Dark appearance override; D-079, E-147). **Rule: every dark-mode CSS rule ships in both the media-guarded form (`:root:not([data-dbvc-ve-scheme="light"])`) and the explicit form (`:root[data-dbvc-ve-scheme="dark"]`).**
- Palette bulk popover hotfix (`--dbvc-ve-z-modal: 120030`, E-148).
- Still PAUSED / PLANNED from before R6: **R5.later-perf** (LocalWP 3.7s TTFB investigation), **R5.later-cache** (blocked on perf).

## Baselines to preserve

```bash
vendor/bin/phpunit --filter 'VisualEditor|Curation'                    # expect 470 tests / 3295 assertions OK
node --test tests/visual-editor-brand-control-center-state.test.cjs   # expect 67 pass
node --test tests/visual-editor-media-manager-state.test.cjs          # expect 42 pass
node --test tests/visual-editor-overlay-app-state.test.cjs            # expect 25 pass
node --test tests/visual-editor-workspace-state.test.cjs              # expect 28 pass
```

`php -l` every touched PHP file; `node --check` every touched JS file. `composer agent-docs:check` fails on **pre-existing** drift (7 unmapped R3-era discovery IDs) — not caused by R6; do not "fix" it as a side effect.

## Canonical docs (read these first)

1. `addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md` — head ~40 lines only (~90KB file).
2. `addons/visual-editor/CHANGELOG.md` — Unreleased top: R6-E … R6-A, VE-prefs-1, hotfix.
3. `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/SITE-MANAGER-WORKSPACE-RELEASE-NOTES-AND-ROLLBACK.md` — what shipped, gates, residuals, rollback, sign-off record.
4. `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R6-WORKSPACE-STATE-CONTRACT.md` — the runtime contract the code implements (amended E-149).
5. `docs/dropins/dbvc-visual-editor-brand-controls-guide/tracking/IMPLEMENTATION-TRACKER.md` (R6 + VE-prefs-1 rows), `EVIDENCE-LOG.md` (E-142–E-157), `DECISION-LOG.md` (D-065–D-080).
6. `docs/dropins/dbvc-visual-editor-brand-controls-guide/qa/R6-SITE-MANAGER-WORKSPACE-QA-REPORT.md` (live results, E-152) + `…-QA-CHECKLIST.md` (rows 14/18/19 still maintainer-only).
7. Real-browser QA is possible via **Claude in Chrome** (the maintainer's logged-in Chrome, `Agent User`); the tab is backgrounded so rAF/IO-dependent checks are throttled (E-123) — assert state via `javascript_tool` rather than screenshots for focus.

## Key file locations

- Workspace drawer: `addons/visual-editor/assets/js/workspace-app.js`, `assets/css/workspace.css`; jsdom `tests/visual-editor-workspace-state.test.cjs`
- Read model: `addons/visual-editor/src/Navigation/ObjectNavigationReadModel.php`; route `src/Rest/Controllers/ObjectSearchController.php`; PHPUnit `tests/phpunit/VisualEditorObjectNavigationTest.php`, `VisualEditorWorkspaceShellTest.php`, `VisualEditorWorkspaceHardeningTest.php`
- Toolbar / popovers / preferences / panel clamp / workspace bridge: `assets/js/overlay-app.js` (search `R6-D-1`, `R6-D-3`, `VE-prefs-1`); jsdom `tests/visual-editor-overlay-app-state.test.cjs`
- Flag + settings: `addons/visual-editor/bootstrap.php` (`OPTION_WORKSPACE_ENABLED`, `is_workspace_enabled()`); enqueue + bootstrap + i18n: `src/Assets/AssetLoader.php`
- Mockup (accepted, D-074): `docs/ui-mockups/dbvc-visual-editor/r6-site-manager-workspace/`
- Headless QA harnesses used during R6 (scratch only, not in repo): a page that loads the real `overlay.css`/`control-center.css`/`workspace.css` + `overlay-app.js`/`brand-control-center-app.js`/`workspace-app.js` with a stubbed `DBVCVisualEditorApi`, driven by the repo's Playwright (`NODE_PATH=$PWD/node_modules`). Rebuild it from E-149 if you need browser-level checks — the in-app browser pane cannot serve local files with CSS, and no dev server binds in this environment.

## Safety constraints (durable)

- No git operations; no live-site option toggles; never touch `~/.config/dbvc-local-agent.env`; desktop-only (D-058); no new REST routes or write authority unless a phase spec calls for one; Vertical parity invariant; commit attribution `Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>` when the maintainer commits.
- Do not modify `overlay-app.js` / `workspace-app.js` without extending the matching jsdom suite.
- Do not retire the Go To Object popover (D-073) without maintainer direction.

## Choose the next move

| # | Slice | Sizing | Notes |
|---|---|---|---|
| 1 | **R6 + R6.1 sign-off support** | Small | Maintainer finishes rows 14/18/19 (R6) and 21–26 (R6.1) of `qa/R6-SITE-MANAGER-WORKSPACE-QA-CHECKLIST.md` (report already at `qa/R6-SITE-MANAGER-WORKSPACE-QA-REPORT.md`); fix anything surfaced; fill the sign-off record. (Polish from the report landed 2026-09-16: drawer alpha 0.98, Escape focus restore, admin-bar clamp — E-153/E-154.) |
| 2 | ~~R5.later-darkmode.d~~ | — | Landed 2026-09-15 (E-151). |
| 2b | ~~R6.1~~ | — | R6.1-a/b/c landed 2026-09-16 (E-155–E-157): kind radiogroup + sort select, live-QA'd (report §R6.1-c, checklist rows 21–26). Only the maintainer's own pass over rows 21–26 remains — fold into Option 1. |
| 3 | **VE-prefs-2** | Small | Next viewer preference in the Preferences popover (candidates: reduced-motion override, workspace-open-by-default, badge visibility). |
| 4 | **R5.later-perf resumption** | Medium | The paused TTFB investigation; real-Chrome measurement per E-123. |
| 5 | **Go To Object retirement** | Small | Only after one release cycle of workspace use (D-073); consolidate the popover into the workspace. |

Default recommendation: **Option 1** until the maintainer signs off R6, then **Option 3** (VE-prefs-2) or **Option 4** (perf).

## First actions

1. Read the head of the HANDOFF and the top of the CHANGELOG.
2. Run the five baselines above.
3. Ask the maintainer which option to pursue. Do not write code until confirmed.
