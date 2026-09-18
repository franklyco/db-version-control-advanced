# R1-D Media Manager Claude Context Capsule

> **Status after review:** R1-D1 through R1-D4 are complete. This capsule remains historical evidence for the original static-mockup boundary. For D4A corrections, use `releases/R1-MEDIA-MANAGER-SCAN-AND-REPORT.md`, `ui-ux/MOCKUP-TO-PRODUCTION-INTEGRATION.md`, tracking decisions D-030 through D-032, evidence E-040, risks RK-033 through RK-035, and the current copy/paste prompt. Current source and the D4A review record supersede any fixture/state/responsive assumption below.

This is the smallest authoritative startup context for the static R1-D Media Manager mockup. Read linked implementation files only when this capsule identifies them or a discrepancy requires source verification. Do not preload the wider package.

## Authority and working-tree safety

- Repository root: `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`
- Recheck branch, HEAD, upstream divergence, and status. The last verified base was synchronized branch `codex/visual-editor-linked-posts-plan` at `5db4b40`, with intentional uncommitted R0/R1-A/R1-B/R1-C work above it.
- Preserve all existing work. Do not reset, restore, stash, clean, stage, commit, or push.
- R1-D static mockup work is approved. Work one sub-phase at a time and stop after each.
- Do not edit production PHP/JS/CSS, tests, generated agent docs, package tracking, manifests, or checksums. Codex performs production translation later.

## Minimal read set

1. Repository `AGENTS.md` and `addons/visual-editor/AGENTS.md`.
2. This file.
3. `ui-ux/fixtures/media-manager-r1c-view-model.json`.
4. Targeted Visual Editor styling evidence in `addons/visual-editor/assets/css/overlay.css`: variables at the top plus `.dbvc-ve-toolbar*`, `.dbvc-ve-toolbar-object*`, `.dbvc-ve-field-index*`, notices, panel, focus, and responsive rules.
5. Primary image: `ui-ux/reference-images/04-media-manager-initial-concept.png`.

Optional visual continuity references: `01-frontend-website-manager-shell.png` and `03-frontend-site-manager-workspace.png`. Inspect `overlay-app.js` only for a specific interaction convention; do not load it wholesale. The broader product/table/integration docs are exception-only references.

## Product boundary

R1 is read-only scan/report. Allowed UI actions are: load latest, start/refresh, continue progress, cancel when allowed, retry when allowed, search, filter, sort, cursor-page, expand/collapse, open a permitted frontend URL, and close.

Do not show or imply media selection/upload, descriptor hydration, image/gallery assignment, field/row save, Save Selected, arbitrary meta, raw owner/field targets, exact filtered totals, rollback, or mutation. Unsupported nested/conditional/option/user paths are excluded from rows; they may appear only as an aggregate skipped-observations notice.

The older fixture and early handoff included future R2 writable examples. They are not current R1 authority. Use only `media-manager-r1c-view-model.json` for the default mockup. If future R2 concepts are later requested, isolate them in a clearly labeled non-production state page; initial R2 still has no Save Row or cross-entity bulk save.

## Safe R1-C view model

- Scan: state/request status, processed/estimated progress, safe summary counts, safe error, timestamps, `canRetry`, and `canCancel`.
- Query: search up to 100 characters; entity `all|post|term`; field `all|featured_image|acf_image|acf_gallery`; allowlisted entity/missing/scanned sorting; limit 1-50; opaque cursor.
- Row: opaque group reference, safe entity label/family/type/frontend URL, missing count, family counts, freshness, expand/open availability.
- Expansion: `current|changed|resolved_or_changed|unavailable`, counts, new-missing count, and safe field records.
- Field: opaque finding reference, label, family, context label, `missing|changed|resolved_or_changed|unavailable`, descriptor status, safe message, and false descriptor/assignment actions.
- Pagination exposes only `hasMore` and `nextCursor`. Never show page X of Y or an exact filtered total.

## Minimal backend wiring map

| UI event | REST route | Server symbols |
|---|---|---|
| Open/resume | `GET /dbvc/v1/visual-editor/media-manager/scans/latest` | `MediaManagerController::handleLatest()` -> `MediaScanReadModel::getLatestList()` |
| Start/refresh | `POST /dbvc/v1/visual-editor/media-manager/scans` | `handleStart()` -> `MediaScanCoordinator::start()` |
| Continue | `POST /scans/{scanRef}/next` | `handleNext()` -> `runNextChunk()` |
| List/query/page | `GET /scans/{scanRef}` | `handleList()` -> `MediaScanReadModel::getList()` |
| Expand/revalidate | `GET /scans/{scanRef}/groups/{groupRef}` | `handleGroup()` -> `MediaScanReadModel::expandGroup()` |
| Retry | `POST /scans/{scanRef}/retry` | `handleRetry()` -> `MediaScanCoordinator::retry()` |
| Cancel | `POST /scans/{scanRef}/cancel` | `handleCancel()` -> `MediaScanCoordinator::cancel()` |

Explicit scan actions use current generation and expected revision. All routes require the Media Manager flag, Visual Editor capability, active signed mode, WordPress REST authentication, and current user/site snapshot ownership. Static mockup code must not call them.

Source references when exact behavior is disputed:

- `addons/visual-editor/src/Rest/Controllers/MediaManagerController.php`
- `addons/visual-editor/src/MediaManager/MediaScanReadModel.php`
- `addons/visual-editor/src/MediaManager/MediaScanCoordinator.php`

## Visual and code conventions

- Live site remains visible behind a large viewport-clamped panel/drawer; preserve Visual Editor toolbar continuity.
- Include header/close, scan status/progress, summary, fixed Published/live scope, search/entity/field/sort controls, refresh/cancel/retry as state permits, sticky controls/header, internally scrollable results, compact rows, one expanded row, frontend link, cursor Load more, and a polite live region.
- Desktop may use a semantic table. Mobile may use equivalent cards/list groups without losing information.
- Use semantic controls, `aria-expanded`/`aria-controls`, associated labels, visible `:focus-visible`, text plus color for status, reduced-motion handling, and focus-safe sticky regions.
- Outer scope: `.dbvc-ve-media-manager-mockup`.
- Component prefix: `dbvc-ve-media-manager`; BEM-like elements/modifiers; `is-*` state classes. Static JavaScript hooks use `data-mockup-*` only.
- Reuse current `--dbvc-ve-*` colors, typography, radii, shadows, focus, and z-index tokens. Add only scoped `--dbvc-ve-media-manager-*` variables.
- No global reset, unscoped element selectors, framework/CDN/font/icon kit, build step, minification, inline handlers, network request, persistence, or production state store.

## Output contract

After D2 is authorized, write only under `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/`:

- `index.html`, `styles.css`, `states.html`, `README.md`
- `COMPONENT-NOTES.md`, `WIRING-SCHEMATIC.md`, `DESIGN-DECISIONS.md`
- optional local-only `mockup.js`
- screenshots when existing tooling can produce them without installing anything

`WIRING-SCHEMATIC.md` maps each major selector/component to purpose, safe data fields, local behavior, future route/method, server symbol, states, accessibility/focus, and scope (`R1`, `R2 deferred`, or `mockup-only`). Include scan and row state diagrams plus a “not wired in R1” section.

## Gated sub-phases

### D1 - Evidence and component plan (authorized first)

Read only. Confirm provenance, inspect the minimal read set, reconcile the fixture, and return the proposed component tree, tokens, responsive strategy, and schematic map in chat. Create no files. Stop with: `R1-D1 complete. Waiting for explicit R1-D2 authorization.`

### D2 - Default desktop mockup

After explicit approval, create the output root plus default/expanded desktop `index.html`, scoped `styles.css`, and initial notes/schematic. Render if existing tools permit. Stop for review.

### D3 - States and responsive mockup

After explicit approval, add the state gallery, optional local toggles, tablet/mobile/focus/reduced-motion treatments, screenshots, and updated notes. Stop for review.

### D4 - Handback package

After explicit approval, finalize the component notes, wiring schematic, design decisions, static validation, exact inventory, limitations, and accepted/adapted/deferred candidates. Do not claim production integration.

Stop rather than guess if a design needs absent data, mutation authority, raw targets, exact totals, a dependency/download, production-file edits, or conflicts with current code.
