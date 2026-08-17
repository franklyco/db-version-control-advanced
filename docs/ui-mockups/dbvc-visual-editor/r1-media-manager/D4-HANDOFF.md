# R1-D4 handoff

Session scaffolding for resuming the R1-D Media Manager mockup at sub-phase **D4** in a fresh
Claude Code thread opened in the correct repository.

> **Note on this file.** It is not one of the outputs listed in the R1-D capsule's output
> contract. It was created on explicit user request to carry context across sessions. D4 may
> delete it once absorbed, or keep it as a session aid. It is not a specification and holds no
> authority over the capsule.

---

## 1. Environment

| Item | Value |
|---|---|
| Repository root | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main` |
| Branch | `codex/visual-editor-linked-posts-plan` |
| Base commit | `5db4b40` - "Expand DBVC automation, portability, and editing workflows" |
| Upstream | `origin/codex/visual-editor-linked-posts-plan`, synchronized 0/0 |
| Output root | `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/` |

**Open the new session with this repository as the working directory.** D1-D3 were executed from
a different repo's working directory (the `frameworkflo-live` theme). The work is correct and
complete, but that mis-start cost the browser preview tooling, which refuses to drive `file://`
pages outside the session's project folder. Starting here restores it.

## 2. Working-tree safety

Expected state before any D4 edit:

- **54 modified** tracked files - all pre-existing R0/R1-A/R1-B/R1-C work. None are D1-D3's.
- **25 untracked** files - 13 pre-existing (see below) plus the 12 mockup files.

Pre-existing untracked work that must be preserved and is **not** in D4 scope:

```
addons/visual-editor/src/MediaManager/AcfMediaFieldCatalog.php
addons/visual-editor/src/MediaManager/EligibilityPolicy.php
addons/visual-editor/src/MediaManager/MediaAssignmentValueClassifier.php
addons/visual-editor/src/MediaManager/MediaScanCoordinator.php
addons/visual-editor/src/MediaManager/MediaScanReadModel.php
addons/visual-editor/src/MediaManager/MediaScanService.php
addons/visual-editor/src/MediaManager/ScanCandidateProvider.php
addons/visual-editor/src/MediaManager/ScanSnapshotStore.php
addons/visual-editor/src/Rest/Controllers/MediaManagerController.php
docs/dropins/.../ui-ux/fixtures/media-manager-r1c-view-model.json
tests/phpunit/VisualEditorMediaManagerR1ATest.php
tests/phpunit/VisualEditorMediaManagerR1BTest.php
tests/phpunit/VisualEditorMediaManagerR1CTest.php
```

Rules carried from the capsule: recheck branch, HEAD, upstream divergence and status first. Do
not reset, restore, stash, clean, stage, commit, or push. Do not edit production PHP/JS/CSS,
tests, generated agent docs, package tracking, manifests, or checksums. Write only under the
output root.

## 3. Authoritative reading

1. `AGENTS.md` (repo root)
2. `addons/visual-editor/AGENTS.md`
3. `docs/dropins/dbvc-visual-editor-brand-controls-guide/ui-ux/MEDIA-MANAGER-CLAUDE-MOCKUP-HANDOFF.md`
   - **the capsule; authoritative for scope, fixture, naming, output contract, and gating**
4. This file
5. The existing `COMPONENT-NOTES.md` and `WIRING-SCHEMATIC.md` in this directory

Follow the capsule's exception-only read policy. Do not preload the wider brand-controls
package. The fixture (`ui-ux/fixtures/media-manager-r1c-view-model.json`) and targeted slices of
`addons/visual-editor/assets/css/overlay.css` are the only other reads that were needed for
D1-D3.

## 4. Delivered so far

| Sub-phase | Scope | State |
|---|---|---|
| D1 | Evidence and component plan | complete (chat only, no files) |
| D2 | Default desktop mockup | complete |
| D3 | States, responsive, screenshots | complete |
| D4 | Handback package | **authorized, not started** |

Twelve files in the output root:

```
index.html          40.9 KB   default view, Home Page row expanded, responsive
states.html         22.2 KB   11-case state gallery
styles.css          42.5 KB   scoped; mirrored base tokens + component tokens
mockup.js            9.6 KB   optional local-only toggles
README.md            4.1 KB   entry doc, status, constraints, screenshot repro
COMPONENT-NOTES.md  12.8 KB   inventory, fixture reconciliation, decisions, gaps
WIRING-SCHEMATIC.md 14.2 KB   selector->route/symbol map, state diagrams, not-wired list
D4-HANDOFF.md                 this file
screenshots/01-desktop-default.png   1440x1000
screenshots/02-tablet.png             900x1200
screenshots/03-mobile-500w.png        500x1600
screenshots/04-states-gallery.png    1280x3100
```

## 5. D4 scope, per the capsule

> Finalize the component notes, wiring schematic, design decisions, static validation, exact
> inventory, limitations, and accepted/adapted/deferred candidates. Do not claim production
> integration.

That implies:

- **`DESIGN-DECISIONS.md`** - the one output-contract file not yet written. Section 7 below lists
  the decisions that must land in it.
- **Finalize** `COMPONENT-NOTES.md` and `WIRING-SCHEMATIC.md` - they are current through D3 and
  need a closing pass, not a rewrite.
- **Exact inventory** - file list with byte sizes and purpose.
- **Static validation record** - what was run, what passed, what was not run.
- **Limitations** - section 6 below.
- **Accepted / adapted / deferred candidates** - the raw material is already written: adapted and
  rejected concept-image elements are in `WIRING-SCHEMATIC.md` section 4, and the reconciliation
  narrative is in `COMPONENT-NOTES.md` section 2. D4 consolidates these into one explicit list.

Stop for review at the end. Do not claim production integration; Codex performs translation.

## 6. Carry-forward issues - RESOLVED at D4A-1, retained for provenance

> **All carry-forward issues below were adjudicated by Codex on 2026-08-15/16** (decisions D-030
> to D-032, evidence E-040) and corrected in the fixture and static states at D4A-1. The fixture is
> no longer frozen: Codex explicitly authorized its correction. Read
> `DESIGN-DECISIONS.md` section 3 for the rulings and what changed. The text below records the
> questions as they were originally raised.

**Three fixture discrepancies.** Recorded in `COMPONENT-NOTES.md` section 2. The fixture is
unmodified and must stay that way. All three need Codex or a maintainer to adjudicate:

1. `query.sort` is `entity_asc` but `items[]` is ordered by `scannedAt` descending. The mockup
   renders the server's order verbatim and sets the control to the declared sort.
2. `pagination.hasMore` is `true` although all 6 entities-with-findings and all 13 findings are
   present under `limit: 20`. The mockup honours `hasMore` rather than inferring completeness,
   because that inference is the exact-total leak the contract forbids.
3. Home Page's `modifiedGmt` (2026-08-15 18:20 UTC) is about four hours later than its
   `scannedAt` (14:22:11 UTC), yet `expandedRows.current.status` is `current`. The mockup renders
   the server-declared status and never derives freshness client-side.

**Unverified sort keys.** Only `entity_asc` is fixture-confirmed. The option values
`missing_desc` and `scanned_desc` are placeholders and must be checked against
`MediaScanReadModel`'s allowlist before production translation.

**Fixture gap.** `expandedRows.changed`, `.resolved`, and `.unavailable` carry only `groupRef`,
`status`, `counts`, and `newMissingFindingCount` - no `fields[]`. States 7, 8, and 9 in
`states.html` therefore render counts plus a status banner and invent no field rows. Keep it that
way.

**Illustrative placeholders.** `states.html` cases 1-4 (no scan, running, error, cancelled) have
no fixture counterpart. Their progress numbers, timestamps, and error text are layout
placeholders and are labelled as such on the page. Do not promote them to contract values.

**Screenshot viewport limit.** Headless Chrome on this host clamps its viewport to a 500px
minimum, so the mobile capture is 500px rather than phone-width. 500px is inside the 640px
breakpoint, so the captured layout is the correct card layout - it is simply wider than a real
handset. Verified by measuring rendered geometry (`drawer width=488, viewport=500`), not by
trusting the cropped image. **With the session opened in this repo, the browser preview tooling
should work and can likely produce a true narrow-viewport capture.** Worth one attempt in D4.

**Dangling BEM anchor.** `__tbody` is declared in markup but carries no CSS rule. Intentional
translation anchor; note it, do not delete it.

**Not updated by design.** `docs/ROADMAP.md`, `PACKAGE-MANIFEST.json`, and
`PACKAGE-CONTENTS.sha256` have no entry for this mockup. The capsule scopes writes to the output
root, so those are a separate, explicitly authorized slice. Note that repo `AGENTS.md` asks for
`docs/roadmap.md` updates when implementation docs change - flag this to the user in D4 and let
them authorize it rather than doing it unasked.

**Not simulated.** Capability checks, the Media Manager feature flag, signed mode, WordPress REST
authentication, and snapshot-ownership gating. The mockup assumes an already-authorized operator.

## 7. Decisions that must be recorded in `DESIGN-DECISIONS.md`

1. **Mirrored base tokens.** `overlay.css` declares `--dbvc-ve-*` on `:root`, but a standalone
   page may not add a global reset or unscoped selectors. Base token values are mirrored inside
   `.dbvc-ve-media-manager-mockup` and annotated as a non-authoritative copy. Dropped at
   translation, where the real `:root` tokens are inherited. The one accepted duplication.
2. **Scope class on `<body>`.** Makes every rule scoped with zero unscoped declarations. In
   production the class moves to a container element and the `margin: 0` page reset is dropped.
3. **Card transform over duplicate markup - changed from the D1 plan.** D1 proposed parallel
   `__table` and `__cards` surfaces swapped by CSS. Rejected during D3: it doubles the markup a
   production template must emit and invites drift. Instead the single table transforms with
   `display: block`, cells print their column name from `data-label`, and explicit ARIA roles
   (`table`, `rowgroup`, `row`, `columnheader`, `rowheader`, `cell`) sit in the markup at every
   breakpoint so semantics survive the transform.
4. **Absent, not disabled.** State-gated controls (Continue, Cancel, Retry) are omitted from the
   DOM rather than rendered disabled - a greyed-out control still advertises authority the
   operator does not have.
5. **One sort control.** The concept image's sortable "Updated" header is gone because
   `modifiedGmt` is not an allowlisted sort key. The `<select>` is the only sort control;
   `aria-sort` on the active header conveys state without advertising rejected sorts.
6. **Scan-wide summary, frozen against filters.** Header counts bind to `scan.summary` and are
   labelled "in last scan" so they cannot be misread as an exact filtered total.
7. **Non-modal `<section aria-labelledby>` rather than `role="dialog"`.** The live site stays
   visible and usable behind the drawer. If production makes it modal, promote to `dialog` and
   add a focus trap.
8. **Layout-pinned chrome, single scroll region.** The drawer is a flex column; only `__results`
   scrolls. The table `<thead>` is the sole `position: sticky` element. Rows carry
   `scroll-margin-block` so keyboard focus never lands beneath it.
9. **Expansion as a sibling `<tr>` with `aria-expanded`/`aria-controls`.** `<details>`/`<summary>`
   cannot span two table rows. Every `aria-controls` target exists; collapsed rows point at a
   hidden pre-fetch expansion rather than at nothing.
10. **New scoped a11y helpers.** `overlay.css` ships no visually-hidden utility and
    `overlay-app.js` has no `aria-live` region, so `__sr-only` and the polite live region are new,
    scoped additions worth carrying into production.
11. **Two D3 layout bugs found by screenshot review.** Desktop flex-basis values
    (`flex: 1 1 220px`, `flex: 0 0 190px`) sized the block axis once the controls became a mobile
    column, producing 410px of dead vertical space; both reset to `flex: 0 0 auto`. Mobile chips
    wrap rather than forming a nowrap scroll rail, which would hide filter options behind a swipe.
12. **A false diagnosis, corrected.** A mobile "horizontal overflow" was diagnosed twice from a
    cropped screenshot before direct measurement showed the viewport was clamped to 500px and the
    PNG was merely cropped. The guard rules added at the time are harmless and independently
    defensible, but the code comments asserting the false cause were rewritten. Record this so
    nobody re-derives the phantom bug.

## 8. Validation that works in this environment

No project test runner covers a static mockup. What was used:

- **Structural validation** - a throwaway Python script using stdlib `html.parser`: tag balance,
  duplicate IDs, `aria-controls` and `label[for]` target resolution, ARIA role counts,
  `data-label` completeness, colgroup/header/colspan agreement, CSS selector scoping, absence of
  inline handlers and external references, and a forbidden-R2-vocabulary grep. Rewrite it ad hoc;
  it was not kept as a deliverable.
- **`node --check mockup.js`** - passes.
- **Banned-API grep on comment-stripped JS** - confirms no `fetch`, `XMLHttpRequest`,
  `localStorage`, `sessionStorage`, `document.cookie`, `indexedDB`, `sendBeacon`, `eval`, or
  `innerHTML` in executable code. Strip comments first: the file's own header lists those names
  as prohibitions and trips a naive grep.
- **`git diff --check`** and `git status --short --untracked-files=all`.
- **Screenshots** via the host's installed Chrome, no install required:

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --hide-scrollbars --virtual-time-budget=3000 --window-size=1440,1000 --screenshot=screenshots/01-desktop-default.png index.html
```

Chrome's parent process may be reaped before the detached child writes the PNG; poll for the file
rather than trusting the exit status.

## 9. Product boundary - unchanged, re-assert in D4

R1 is read-only scan and report. Permitted: load latest, start/refresh, continue, cancel, retry,
search, filter, sort, cursor-page, expand/collapse, open a permitted frontend URL, close.

Absent by design and to stay absent: media selection, upload, descriptor hydration, image/gallery
assignment, field or row save, Save Selected, arbitrary meta, raw owner/field targets, exact
filtered totals, rollback, and any mutation. Unsupported nested, conditional, option, and
user-scoped field paths appear only inside the aggregate skipped-observations notice, never as
rows.
