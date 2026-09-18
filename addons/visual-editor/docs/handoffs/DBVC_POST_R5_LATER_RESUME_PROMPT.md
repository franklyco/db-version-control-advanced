# DBVC Visual Editor — Resume Prompt (post-R5.later, boundary 2026-09-11)

> **Superseded 2026-09-15.** The maintainer chose Option 1 (R6 kickoff). R6-A landed on branch `codex/visual-editor-r6-site-manager-workspace` (from `9778885`). PHPUnit baseline is now **458/3180**. Resume from `DBVC_VISUAL_EDITOR_HANDOFF.md` head + `IMPLEMENTATION-TRACKER.md` R6 row + E-142; next slice is **R6-B** (state/action contract, docs-only). The text below is kept for history.

**Copy everything below the `---` line into a fresh Claude Code session.**

---

You are picking up the DBVC Visual Editor project after the R5.later pre-R6
arc closed. Nothing is mid-flight; the tree is clean at the boundary, and
the next move is a choice among several unblocked options.

## Repository + branch + env

- **Working dir:** `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`
- **Branch (pushed):** `codex/visual-editor-r5-later-arc` — tip `f870cf9`, based on `5db4b40`. This branch bundles the entire R5.later arc + the load-bearing R3/R4/R5.x untracked work that preceded it. **The maintainer has not merged this yet; do not force-push over it without asking.**
- **Working branch (still active for new slices):** `codex/visual-editor-linked-posts-plan` per the original arc — new work continues on top of `5db4b40`'s intentionally-dirty tree unless the maintainer explicitly moves you to a branch based on `f870cf9`.
- **Base HEAD to preserve:** `5db4b40` — every prior R5.x slice landed as a working-tree edit; the maintainer commits and pushes manually. **Do NOT run any `git add`/`commit`/`stash`/`reset`/`checkout`/`clean`/`push`** unless the maintainer explicitly asks. The `codex/visual-editor-r5-later-arc` push was a maintainer-authorized exception.
- **Local site:** LocalWP at `https://dbvc-codexchanges.local/`
- **Cross-repo Vertical mirror:** every edit to `themes/vertical/functions/features/dbvc-visual-editor/includes/class-vf-vertical-control-provider.php` must be `cp`'d to the sibling checkout at `/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/themes/vertical/functions/features/dbvc-visual-editor/includes/class-vf-vertical-control-provider.php` and verified byte-identical via `diff -q`.

## Current boundary (as of 2026-09-11)

R1 signed off. R4 arc COMPLETE + defensively hardened by R4-D-3. Every R5.x + R5.later arc is CLOSED except:

- **R5.later-perf** — PAUSED (uniform 3.7s LocalWP dev-env TTFB uncorrelated with Xdebug / `WP_DEBUG_LOG` / autoload / object cache; investigation deferred).
- **R5.later-cache** — PLANNED (blocked on perf).
- **R5.later-darkmode.d** — optional residual-hardcoded-color tokenization; not planned unless maintainer identifies rough edges.

**LANDED between 2026-09-05 and 2026-09-11** (see EVIDENCE-LOG E-134 through E-141 for full detail):

- R5.later-tests — overlay-app.js jsdom scaffolding, 15-test suite (E-134)
- R5.later-perf Task 2/3 — gated `dbvc.ve.*` User Timing instrumentation + measurement recipe; sweep paused
- R5.later-toolbar-skin arc (.a/.b/.c) COMPLETE — structural pill reskin + dark color scheme + aria-expanded orange split; full mockup match (E-135/-136/-141)
- R5.later-darkmode arc (.a/.b/.c) COMPLETE — system `prefers-color-scheme: dark` via component-scoped tokens + token-level global override (E-137/-138/-139)
- R5.later-c — drawer inline current-value display; R4-C-1b chip moved from action cell to label cell; every R5.x family renders inline; palette/repeater aggregate chips (E-140)

**R6 (Frontend Site Manager Workspace) is the next major planned phase — not started.**

## Baselines to preserve

Run these before starting AND after each substantive edit; they must stay green:

```bash
vendor/bin/phpunit --filter 'VisualEditor|Curation'                    # expect 441/3036 OK
node --test tests/visual-editor-brand-control-center-state.test.cjs   # expect 66 pass
node --test tests/visual-editor-media-manager-state.test.cjs          # expect 42 pass
node --test tests/visual-editor-overlay-app-state.test.cjs            # expect 15 pass
```

PHP lint every touched PHP file with `php -l <path>` before considering the edit done.

## Safety constraints (durable — do not deviate)

- **No git operations** in either repo without explicit maintainer authorization. The `codex/visual-editor-r5-later-arc` push was one-time authorized. Preserve the intentional dirty tree at base `5db4b40`.
- **No live-site mutations** outside test setUp/tearDown. Never toggle `dbvc_visual_editor_control_center_enabled` or the master Visual Editor option from production paths.
- **Never touch** `~/.config/dbvc-local-agent.env`.
- **Desktop-only** per D-058 (mobile is a permanent non-goal).
- **No new write authority.** Every new save round-trip must route through an existing `save_shared_field` mutation contract; do not create new REST routes or resolvers unless the phase spec explicitly calls for one.
- **Cross-repo Vertical parity is invariant.** Both checkouts of `class-vf-vertical-control-provider.php` must stay byte-identical.
- **Commit-message attribution** (when the maintainer later commits): end with `Co-Authored-By: Claude Opus 4.7 <noreply@anthropic.com>`. PR descriptions end with `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.

## Canonical docs (read these first, before writing code)

1. **`addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md`** — current-state handoff; first ~40 lines carry the boundary + last-slice summaries. Do NOT read the whole file (~85KB).
2. **`addons/visual-editor/CHANGELOG.md`** — Unreleased section top has every landed slice with evidence-log refs (E-134 through E-141). Skim to understand recent code shape.
3. **`docs/dropins/dbvc-visual-editor-brand-controls-guide/tracking/IMPLEMENTATION-TRACKER.md`** — one row per phase; grep for the target slice name.
4. **`docs/dropins/dbvc-visual-editor-brand-controls-guide/tracking/EVIDENCE-LOG.md`** — E-134 through E-141 for the most recent code-touch patterns.
5. **`docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R5-REMAINING-UNLOCK-FRONTIERS.md`** — canonical spec for remaining R5.later slices (perf resumption, cache, darkmode.d).
6. **`docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R6-FRONTEND-SITE-MANAGER-WORKSPACE.md`** — canonical spec for R6 (the next major phase).
7. **`addons/visual-editor/docs/enhancements/DBVC_VISUAL_EDITOR_PHASES.md`** — release-status table current as of 2026-09-11.

## Task tracking convention

Use `TaskCreate` at the start of each phase to break work into 4-8 tasks. Update as you go with `TaskUpdate`. Task naming pattern: `{Phase}: {short subject}`. Don't batch — mark each task completed as it lands.

## Choose the next move

Five plausible next slices, sorted by strategic weight:

| # | Slice | Sizing | Blocker |
|---|---|---|---|
| 1 | **R6 major arc kickoff** | Large (multi-slice) | None — canonical spec at `R6-FRONTEND-SITE-MANAGER-WORKSPACE.md` |
| 2 | **R5.later-perf resumption** | Medium | LocalWP dev-env TTFB investigation must resolve first (Bricks-ecosystem plugin cost suspected); real-Chrome measurement per E-123 |
| 3 | **R5.later-cache** | Medium-large | Sequenced after perf resumes; 7 correctness invariants demand deterministic tests |
| 4 | **R5.later-darkmode.d** | Small | Only if maintainer identifies specific dark-mode rough edges (residual hardcoded rgba/hex) |
| 5 | **New maintainer-driven slice** | Varies | The maintainer may request UX polish, refactoring, or something unlisted — ask them |

**Recommend Option 1 (R6 kickoff)** unless the maintainer specifies otherwise. R6 has been the "natural next major phase" flagged in the HANDOFF for months and every pre-R6 blocker is now closed except perf/cache which are independent of R6.

## Starting Option 1 — R6 Frontend Site Manager Workspace

R6 introduces a persistent, laptop/desktop workspace that integrates the existing tools (Media Manager, Brand Control Center drawer, Object Search, Layers/Field Index, Shared Globals popover) into one navigable surface. **Non-goals**: it must not duplicate Media Manager or Go To Object logic; no mobile paths.

**First action**:
1. Read `docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R6-FRONTEND-SITE-MANAGER-WORKSPACE.md` in full — this is the canonical scope.
2. Read the R6-CODEX-PROMPT.md kickoff prompt at `docs/dropins/dbvc-visual-editor-brand-controls-guide/prompts/R6-CODEX-PROMPT.md`.
3. Draft a task breakdown (5-8 slices) and propose it to the maintainer BEFORE writing code — R6 is large enough that scope alignment upfront prevents rework.
4. Run all four baselines and confirm they're green.
5. Grep `addons/visual-editor/` for any existing `R6` references or scaffolding.

## Key file locations reference

- Drawer surface: `addons/visual-editor/assets/js/brand-control-center-app.js`
- Toolbar + panel + palette controllers: `addons/visual-editor/assets/js/overlay-app.js` (~13K lines — see `RENDER_DISPATCH_CASES` in `tests/visual-editor-overlay-app-state.test.cjs` for the family dispatch map)
- Vertical provider (both checkouts, byte-identical): `themes/vertical/functions/features/dbvc-visual-editor/includes/class-vf-vertical-control-provider.php`
- List/Open/Save/ValueSummaries controllers: `addons/visual-editor/src/Rest/Controllers/ControlCenter*.php`
- Descriptor factory: `addons/visual-editor/src/Registry/Providers/SharedGlobalsDescriptorFactory.php`
- Registry: `addons/visual-editor/src/Registry/ControlRegistry.php` (has `buildValueSummaryForRecord`)
- Provider interface: `addons/visual-editor/src/Registry/ControlProvider.php`
- Panel CSS: `addons/visual-editor/assets/css/overlay.css`; drawer CSS: `addons/visual-editor/assets/css/control-center.css`
- i18n: `addons/visual-editor/src/Assets/AssetLoader.php`
- Gated perf instrumentation (LANDED): `overlay-app.js` + `api-client.js` + `brand-control-center-app.js` `createPerfSpan` helpers, gated on `?dbvc_ve_perf=1`

## Environmental gotchas (learned the hard way)

- **Claude-in-Chrome runs backgrounded** and throttles IntersectionObserver callbacks (E-123). Any IO-dependent verification must run in real foregrounded Chrome.
- **Claude-in-Chrome viewport is retina-mapped** — a 1440×900 request maps to 1728×958 CSS pixels. Can't genuinely test 1280×720 in an automation session.
- **jsdom stubs layout poorly** — no `scrollIntoView`, `getBoundingClientRect` returns zeros, focus behaviour is limited. Cover state + network flow; leave real-layout to browser QA.
- **LocalWP mysql is faster than production** — perf audit findings need a "translation to production" caveat.
- **LocalWP dev-env has a uniform 3.7s TTFB** that's not attributable to any standard suspect (Xdebug off, `WP_DEBUG_LOG` off didn't fix, no persistent object cache, autoload healthy at 0.86 MB). Suspected Bricks-ecosystem or plugin-init cost. Any perf work needs to acknowledge this baseline noise.

## What NOT to do

- Do not start R5.later-cache until R5.later-perf's investigation resolves.
- Do not modify `addons/visual-editor/assets/js/overlay-app.js` for new features without adding jsdom coverage via the R5.later-tests scaffolding — the R5.later-y-2b/c/d hotfix pattern is what that scaffolding exists to prevent.
- Do not reopen pinned design decisions in the R5-REMAINING doc without explicit maintainer direction.
- Do not add features/refactor/introduce abstractions beyond what the phase spec calls for.
- Do not push to any branch without explicit maintainer authorization.

## First action to take

1. Read the head (~40 lines) of `addons/visual-editor/docs/handoffs/DBVC_VISUAL_EDITOR_HANDOFF.md`.
2. Read the top of `addons/visual-editor/CHANGELOG.md` (Unreleased section — captures every landed slice through 2026-09-11).
3. Run the four baselines and confirm they're green.
4. Ask the maintainer which option to pursue (default recommendation: Option 1 — R6 kickoff). Do not start writing code until the maintainer confirms.

Good luck — the arc is in a genuinely clean state.
