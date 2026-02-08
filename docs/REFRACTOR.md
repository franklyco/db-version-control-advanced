# DBVC Admin App REFRACTOR Plan

> **TL;DR:** This file maps the monolithic admin bundle into 11 epics, each with sub-components, refactor tasks, QA notes, and now also process guidance (branching, feature flag, testing matrix). Treat it as the high-level table of contents. As the sprint progresses, migrate completed/active epics into dedicated wiki pages (one per epic or per related group) so contributors can work from smaller docs and Codex/OpenAI sessions can reference focused summaries instead of this entire file. Keep this top section updated with links to those wiki pages.

This document maps the current React bundle (`src/admin-app/index.js`) into logical domains so we can rebuild the admin UI from manageable modules. The plan emphasizes keeping a backup/staging copy of the generated bundle (`build/admin-app.js` + the current `src/admin-app/index.js`) while new source files are created, and encourages working branch-by-branch so the production plugin always has a working reference artifact.

## Companion Docs
- UI structure and component hierarchy blueprint: `docs/UI-ARCHITECTURE.md`
- Implementation ticket seed (component `props/events/state/dependencies` contracts): `docs/UI-ARCHITECTURE.md#component-contracts-implementation-ticket-seed`
- Epic parity audit table: `docs/UI-ARCHITECTURE.md#refractor-coverage-matrix`

## Goals
- Preserve a pristine copy of the compiled bundle before each major extraction (tag + archive `src/admin-app/index.js`/`build/admin-app.js`), mirror work in a staging branch, and document any temporary toggles needed for QA.
- Identify every feature area, the actions/hooks it uses, and how those pieces depend on each other so refactors can be sliced into safe, reviewable chunks.
- Define the future module boundaries (data layer, tables, drawers, drawers, tools, resolver panels, masking drawer, apply modal, etc.) with checklists so progress is easy to track.
- Maintain anonymized proposals inside `docs/fixtures/` (refresh via the new “Dev upload” control) so every epic can rely on the same baseline ZIP when running QA.

## Tracking Board
Status legend: ⬜ Not started · ⏳ In progress · ✅ Done

| Status | Area | Focus | Key references | Depends on |
| --- | --- | --- | --- | --- |
| ⬜ | Shared HTTP + formatting utilities | Fetch helpers, doc links, badge maps, diff render helpers | `src/admin-app/index.js:8-210` | — |
| ⬜ | Proposal intake & uploader | Proposal list, uploader dropzone, refresh, toasts | `src/admin-app/index.js:240-420`, `1349-1408`, `2052-2140`; `src/admin-app/style.css:48-138, 1100-1130` | Shared utilities |
| ⬜ | Duplicate summary & gating | `/duplicates` fetch, modal, canonical marking, bulk cleanup | `src/admin-app/index.js:1164-1442`, `2304-2356`, `2570-2650` | Proposal intake, HTTP helpers |
| ⬜ | Entities dataset, filters & All Entities table | Status filters, search, selections, column toggles, table render | `src/admin-app/index.js:420-520`, `2300-2560` | Proposal intake, duplicates |
| ⬜ | Entity drawer & diff engine | Drawer focus trap, field decisions, bulk Accept/Keep, snapshot capture | `src/admin-app/index.js:499-900`, `2650-2890` | Entities dataset |
| ⬜ | Media resolver attachments & conflict actions | Resolver summary, attachment list, bulk apply tool, remember-as-global | `src/admin-app/index.js:860-1110`, `2780-3050` | Entity drawer |
| ⬜ | Tools drawer & masking workflow | Mask fetch/apply/undo, session storage, tool toggle, hash helpers | `src/admin-app/index.js:1187-1348`, `1664-1755`, `2390-2520` | Entities dataset, duplicates |
| ⬜ | Bulk entity actions & new-entity gating | Accept new entities, selection batches, snapshot capture, hash sync | `src/admin-app/index.js:2320-2560`, `1390-1485` | Entities dataset |
| ⬜ | Apply flow & history | Apply modal, close/apply POST, success/failure toasts, backups | `src/admin-app/index.js:1720-2295` | Entities dataset, resolver |
| ⬜ | Global resolver rules manager | Rule table, search, selection, add/edit/delete forms | `src/admin-app/index.js:3000-3320` | Media resolver attachments, shared utilities |
| ⬜ | Notifications & toast stack | Toast state, dismissal, severity rendering, error boundary + client logging | `src/admin-app/index.js:2052-2105, 3340-3385` | Proposal intake |

## Detailed Areas & Refactor Steps

### 1. Shared HTTP + Formatting Utilities (`src/admin-app/index.js:8-210`)
**Scope:** GET/POST/DELETE wrappers (`n`, `i`, `l`), mask doc URL helpers, date/value formatting (`a`, `o`, `r`), diff highlighter (`c`), badge/column metadata (`p`, `m`, `y`).

**Primary state/actions:** None (pure helpers), but every component imports these via closure scope.

**Dependencies:** All other sections rely on these helpers for REST calls, display, and column logic.

**Sub-components:**
- `api/client` (nonce/header + fetch wrappers)
- `utils/format` (date/value rendering, diff pretty-print)
- `constants/badges` & `constants/columns` (badge labels, table column configs)
- `docs/links` helper (masking doc anchors)

**Refactor steps**
- [ ] Create `src/admin-app/api/client.ts` with shared headers/nonces + `getJson/postJson/deleteJson`.
- [ ] Move formatters/badge maps/column definitions into `src/admin-app/utils/format.ts` and `src/admin-app/constants/tables.ts` with unit tests.
- [ ] Define and centralize finalized diff/resolver display terminology in one constants module so all UI surfaces consume the same labels (target set: `Change Summary`, `Additions`, `Deletions`, `Modifications`, `Total Changes`).
- [ ] Document these helpers in `AGENT.md`/README so contributors know which files are generated vs. source.
- [ ] Update downstream modules to import helpers instead of relying on closure scope.
- [ ] Add lint rule or CI check to prevent direct `window.fetch` usage outside the client module.
- [ ] Preserve the error boundary + `/logs/client` reporting hook when extracting the root render path.
- [ ] Add unit tests verifying date/value formatting handles empty/null/boolean inputs as expected.
- [ ] QA: smoke test formatter usage in the All Entities table + badges to ensure nothing regresses.

### 2. Proposal Intake & Uploader (`src/admin-app/index.js:240-420`, `1349-1408`, `2052-2140`; `src/admin-app/style.css:48-138, 1100-1130`)
**Scope:** Proposal table component (`w`), uploader dropzone (`C`), GET `/proposals` loader (`zt`), refresh button, upload success/error toasts (`Zs`, `Qs`).

**Recent updates:** Dropzone now splits copy + actions across `dbvc-proposal-uploader__text`/`__actions` with a dedicated options row, inline “ZIP files only” hint, and muted panel styling. The proposal table renders inside `dbvc-proposal-table-wrapper`, which caps the viewport to ~3 rows, keeps headers sticky, and exposes overflow scrolling for older submissions—future refactors must preserve these classes/hooks so layout regressions don’t reappear. Proposal rows now include a Delete action (REST-backed) that allows removing any non-current proposal, including open ones, while locked rows stay protected.

**State/hooks:** `G` (proposal list), `Z` (selected id), `_e`/`Re` (loading/error), `nt` (toasts). Hooks also set `Q` for selection and `zt` to reload.

**Dependencies:** Shared HTTP helpers, toast stack. Selected proposal drives every other area.

**Sub-components:**
- `ProposalUploader` (dropzone + overwrite checkbox)
- `ProposalTable` (widefat table + metrics display)
- `ProposalHeader` (title, refresh/clear buttons)
- `ToastStack` (shared notifications area)

**Refactor steps**
- [ ] Split the uploader, proposal list, and toast stack into dedicated components (e.g., `components/proposals/ProposalTable.tsx`).
- [ ] Replace `useState` scatter with a reducer or `useReducer` to handle loading/error/selected ID transitions atomically.
- [ ] Encapsulate toast creation/dismissal so other modules can dispatch notifications without touching proposal state.
- [ ] Wire the reducer + components into the legacy bundle via a bridge to keep behavior identical during migration.
- [ ] Add storybook or visual regression fixtures for the proposal table + uploader to aid QA.
- [ ] Preserve the sticky-head + scroll-limited wrapper (3-row default via CSS vars) when extracting `ProposalTable` so consuming pages keep the shorter viewport.
- [ ] Preserve the non-current delete action (including open proposals) + server delete endpoint when extracting proposal tooling so cleanup remains accessible.
- [ ] Provide stubs/mocks for WP nonce + REST endpoints to facilitate automated tests (Jest/React Testing Library).
- [ ] QA: upload proposal ZIPs, refresh list, select proposal, verify toast copy matches current flow.

### 3. Duplicate Summary & Gating (`src/admin-app/index.js:1164-1442`, `2304-2356`, `2570-2650`)
**Scope:** `Vt` loads `/duplicates`, `Gt` marks canonical entries, `bulkDuplicateCleanup` POSTs batch cleanup, duplicate modal toggled via `Dt`, UI badges/resolvers that block entity review when duplicates exist.

**State/hooks:** `_t` (duplicate summary), `wt` (loading), `Nt` (errors), `duplicateMode/confirm/bulkBusy` (bulk cleanup), `Dt` modal visibility.

**Dependencies:** Selected proposal ID (`Z`), entity fetcher (`Ht`), tools drawer highlight.

**Sub-components:**
- `useDuplicates` hook (loading/error state)
- `DuplicateSummaryBadge` (counts in entity toolbar)
- `DuplicateModal` (detail table + remediation form)
- `CanonicalRow` (per-entity controls)

**Refactor steps**
- [ ] Introduce `useDuplicates(proposalId)` hook to own fetching, errors, and canonical update logic.
- [ ] Create a standalone `DuplicateModal` component fed by the hook to simplify `AdminApp`.
- [ ] Ensure entity refresh + duplicate refresh run via shared dispatcher rather than manual `Promise.all`.
- [ ] Add tests/fixtures covering slug+ID mode, confirm-token validation, and canonical marking.
- [ ] Document dependency on `_t.count` gating for bulk actions so UI disables buttons consistently.
- [ ] Add instrumentation logging (console dev flag or telemetry hook) when duplicate cleanup fails for easier debugging.
- [ ] QA: trigger duplicate modal, mark canonical entry, run bulk cleanup, confirm entity list unblocks afterwards.

### 4. Entities Dataset, Filters & All Entities Table (`src/admin-app/index.js:420-520`, `2300-2560`)
**Scope:** `Ht` loads `/entities`, filters (`ne`, `le`), status badges, column toggle state (`xt`), selection tracking (`Ot`), table renderer (`k`).

**Recent updates:** Added a UID mismatch filter badge (flags entities where the local `vf_object_uid` differs from the proposal), and exposed it in the filter dropdown. Inserted a totals summary between the Entities header and filters that breaks down proposal/current counts (posts/terms/media) and shows the filtered result count.

**State/actions:** Entities array `te`, filter `ne`, search `le`, column toggles `xt`, selection set `Ot`, refresh button `refreshEntities`.

**Dependencies:** Shared columns metadata, duplicates (to disable actions), new-entity subset (`ss`), mask attention.

**Sub-components:**
- `EntityFilters` (status dropdown + search)
- `EntityStatusBadges` (counts row)
- `EntityColumnToggle` (checkbox grid)
- `EntityTable` (table + selection checkboxes)
- `useEntitySelection` (Set management)

**Refactor steps**
- [x] Consolidate the entity toolbar so the Actions & Tools popover, Columns toggle, and conditional selection buttons share a single flex row (`dbvc-entity-tools-row`), reducing layout jitter before the pieces are extracted into components.
- [ ] Split data fetching into `useEntities(proposalId, filter)` returning list + summary + refresh.
- [ ] Extract table, filters, status badges, and column toggle UIs into separate components (e.g., `EntityFilters`, `EntityStatusBadges`, `EntityTable`).
- [ ] Replace raw `Set` manipulations with dedicated selection hook so the drawer/bulk actions can subscribe cleanly.
- [ ] Memoize column configs and selection state to avoid unnecessary rerenders.
- [ ] Add virtualization or pagination abstraction so large proposals don’t re-render entire tables during filter changes.
- [ ] Write snapshot/unit tests for selection reducer to ensure toggling/clear/select-all flows behave.
- [ ] QA: filter by each status, search by slug/type, multi-select entities, toggle columns, ensure selection persists while paging.

### 5. Entity Drawer & Diff Engine (`src/admin-app/index.js:499-900`, `2650-2890`)
**Scope:** Drawer focus trap, diff renderer with Accept/Keep radios, new-entity card/gating, bulk Accept/Keep, snapshot capture, hash sync, inline resolver summary.

**Recent updates:** Snapshot capture now falls back to manifest-based identity matching when a proposed `vf_object_uid` doesn’t exist locally, and emits a debug log entry when `WP_DEBUG` is enabled. Added a "View All" mode to list every meta field (including unchanged values) alongside the standard diff views.

**State/actions:** `oe` (active entity id), `de` (detail payload), `me` (decisions), `pt`/`mt` flags for clear/snapshot, `T`/`P` for hash sync, `S` component props `onDecisionChange`, `onBulkDecision`.

**Dependencies:** Entities dataset for summary updates, resolver attachments for inline display, snapshot endpoints.

**Sub-components:**
- `EntityDrawerShell` (modal + focus trap)
- `EntityToolbar` (meta/status badges)
- `ChangeSummaryRail` (summary counts + navigation + quick actions)
- `EntityDiffSections` (accordion)
- `DecisionControls` (Accept/Keep radios + bulk buttons)
- `RawDiffView` (line-oriented fallback diff for power review/debug)
- `NewEntityCard` (gating UI)
- `SnapshotControls` (hash/snapshot actions)

**Target review layout (side-by-side)**
- Use a 3-column drawer workspace: `Source (Current)` panel, center `Change Summary` rail, `Destination (Proposed)` panel.
- Keep both side panels structurally identical so each section renders in the same order and reviewers can compare like-for-like without scanning.
- Pin core metadata in each header (ID, author, created/updated timestamps, status, open-in-new action) and keep it visible while scrolling sections.
- Center rail responsibilities:
  - Summary counts using finalized labels: `Additions`, `Deletions`, `Modifications`, `Total Changes`.
  - Change navigation: previous/next + index (`1 of N`) based on changed fields only.
  - Quick actions: toggle metadata visibility, switch `Raw Diff View`, export review report.
- Section registry (must exist in both side panels):
  - `Content`
  - `Custom Fields`
  - `SEO Metadata`
  - `Categories & Tags`
  - `Media Attachments` (with resolver badges/conflict markers)
  - `Raw Diff View` (full payload diff fallback, including unchanged context when needed)
- Accordion behavior:
  - Section headers show per-section change counts (`X changes`).
  - Default to collapsed sections except the first changed section.
  - Support `View All` mode to include unchanged rows for auditing.
- Field-level review model:
  - Each row carries `changeType` (`addition|deletion|modification|unchanged`), source value, destination value, and decision state.
  - Decision controls (`Accept proposed` / `Keep current`) must be reachable inline without leaving the section context.
  - Token/line highlighting should emphasize changed spans, not entire field blocks.
- Media resolver integration:
  - `Media Attachments` rows should surface resolver state inline (unresolved/conflict/resolved).
  - Resolver actions opened from drawer must round-trip to the shared resolver store and immediately refresh section badges/counts.

**Refactor steps**
- [ ] Move drawer UI into `components/entity-drawer/EntityDrawer.tsx` that accepts typed props (entity, decisions, callbacks).
- [ ] Extract diff table + decision controls into smaller components (`DiffSection`, `FieldDecisionRow`) for readability.
- [ ] Implement the 3-column drawer shell (`Source` / `Change Summary` / `Destination`) and keep headers + section order symmetric across both side panels.
- [ ] Apply the naming pass for diff badges/labels in the drawer (`Change Summary`, `Additions`, `Deletions`, `Modifications`, `Total Changes`) and ensure legacy wording is removed from component copy/tests.
- [ ] Add `RawDiffView` mode as a first-class section/toggle (not a debug-only afterthought), with QA coverage for large payloads and unchanged-context rendering.
- [ ] Integrate `Media Attachments` section with resolver states/actions so drawer-level reviews can resolve conflicts without losing position in the entity diff.
- [ ] Centralize decision persistence (currently `Ss`, `Ws`, etc.) into a `useEntityDecisions` hook shared by drawer + bulk actions.
- [ ] Port accessibility behaviors (focus trap, Escape to close) and document them for QA.
- [ ] Snapshot test diff rendering to ensure before/after highlighting matches legacy markup.
- [ ] Provide instrumentation/logging when decision saves fail so errors surface in toasts and dev tools.
- [ ] QA: open drawer, make Accept/Keep decisions, bulk accept visible, capture snapshot, clear decisions, confirm state sync with list.

### 6. Media Resolver Attachments & Conflict Actions (`src/admin-app/index.js:860-1110`, `2780-3050`)
**Scope:** Resolver metrics panel (`N`), attachment cards, per-conflict actions (`E`), bulk tool (filters by reason/UID/path), remember-as-global toggle, helper functions `ws`, `Cs`, `Ns`, `ks`.

**State/actions:** Resolver payload `Y`, `resolverSaving` maps (`rt`), bulk filters `Te/Fe/Ve`, action `Le`, `He` target ID, `We` remember flag, `nt/it/lt` computed matches.

**Dependencies:** Entity drawer for inline resolver contexts, global resolver rule manager, toast stack for errors.

**Sub-components:**
- `ResolverSummaryPanel` (metrics list + warnings)
- `ResolverConflictList` (cards/table for attachments)
- `ResolverDecisionForm` (per-conflict actions)
- `ResolverBulkApply` (filter + bulk action form)
- `RememberGlobalToggle` (checkbox + helper text)

**Refactor steps**
- [ ] Build `useResolverConflicts(proposalId)` to encapsulate GET/POST/DELETE plus optimistic updates (`rs`).
- [ ] Break UI into `ResolverSummary`, `ResolverConflictList`, `ResolverBulkApplyForm`, `ResolverDecisionForm` components.
- [ ] Align resolver badges/summaries with the shared naming vocabulary (`Change Summary`, `Additions`, `Deletions`, `Modifications`, `Total Changes`) so resolver and entity drawer terminology stays consistent.
- [ ] Ensure applying decisions updates both entity drawer data and resolver panel via shared store, not duplicated `setState`.
- [ ] Add automated tests around bulk filter combinations (reason/asset UID/manifest path) and map/download/reuse flows.
- [ ] Record resolver action telemetry (counts, failure reasons) to help monitor post-refactor stability.
- [ ] Add docs for “remember as global” flows so QA knows to verify the global rules panel after each action.
- [ ] QA: revisit conflict list, save single decision, bulk apply with each filter type, toggle “remember as global,” confirm toast + state updates.

### 7. Tools Drawer & Masking Workflow (`src/admin-app/index.js:1187-1348`, `1664-1755`, `2390-2520`)
**Scope:** Tools toggle button, `loadMasking`, `applyMasking`, `undoMasking`, `revertMasking`, progress indicators, sessionStorage key `DBVC_MASK_UNDO`, mask fields bulk actions (`maskBulkAction`, `maskBulkOverride`, `maskBulkNote`), attention badge, docs tooltips.

**State/actions:** `toolsOpen`, `maskFields`, `maskLoading`, `maskApplying`, `maskError`, `pendingMaskUndo`, `maskBulkAction` etc., `maskProgress/maskApplyProgress`.

**Dependencies:** Entities summary (to update counts), duplicate gating (tools disabled when duplicates unresolved), HTTP helpers.

**Sub-components:**
- `ToolsDrawer` shell (masking + hash utilities)
- `MaskFieldList` (table of masked meta)
- `MaskBulkControls` (action dropdowns, override inputs)
- `MaskProgressMeter` (load/apply progress)
- `MaskUndoBanner` (undo/revert notice)

**Refactor steps**
- [ ] Implement `useMasking(proposalId)` hook responsible for fetching fields, batching apply requests, caching undo payloads, and exposing progress; mirror backend contract (`/masking`, `/masking/apply`, `/masking/revert`) so the UI surfaces REST validation errors (patterns, unknown entities, unsupported actions) surfaced in `DBVC_Admin_App::apply_proposal_masking`.
- [ ] Create `ToolsDrawer` container housing Masking panel + hash helpers so `AdminApp` only toggles visibility.
- [ ] Document UI-state machine (idle → loading → built list → apply/undo/revert) to prevent race conditions when closing the drawer mid-request.
- [ ] Persist undo payload schema in docs so future changes keep compatibility (`DBVC_MASK_UNDO` storage).
- [ ] Add debounce when switching proposals to avoid overlapping masking fetches.
- [ ] Implement retry/backoff logic for apply batches to handle transient REST errors gracefully.
- [ ] QA: open tools drawer, load masking fields, bulk apply ignore/auto-accept/override, undo, revert, confirm status badges + entity counts update.

### 8. Bulk Entity Actions & New-Entity Gating (`src/admin-app/index.js:2320-2560`, `1390-1485`)
**Scope:** Buttons for accepting selected entities (`ls`), unaccept (`as`), accept all new (`is`), select-all/clear, duplicate resolution trigger, snapshot capture (`Rs`), hash storage (`vs`), descriptive hints.

**State/actions:** `Ot` selection set, `ss` new entities array, busy flags `Mt/Bt/It/Ut`, hashed entity IDs `us`, snapshot counts `zs`.

**Dependencies:** Entities dataset, duplicates, mask attention, top-level apply flow (ensuring gating before apply).

**Sub-components:**
- `SelectionSummary` (counts + clear/select buttons)
- `NewEntityActions` (accept/review buttons)
- `BulkDecisionButtons` (accept/unaccept selected)
- `SnapshotHashActions` (capture snapshot, store hashes)
- `DuplicateWarningBanner`

**Refactor steps**
- [ ] Convert these operations into discrete hooks (e.g., `useEntitySelection`, `useNewEntityActions`) so button components simply call `actions.acceptSelection()`.
- [ ] Collocate descriptive copy with components to avoid mixing UI text with control logic.
- [ ] Provide a guard/warning component that reads duplicates/mask state to enable/disable actions centrally.
- [ ] Add unit tests for accept/unaccept payload shaping (`scope: "selected"`, etc.).
- [ ] Ensure selection hooks expose derived counts (new entities vs. existing) for UI copy reuse.
- [ ] Add analytics/logging for bulk accept/unaccept to confirm adoption and detect failures.
- [ ] QA: accept/unaccept selected entities, accept all new, clear selection, ensure warnings display when duplicates/mask pending.

### 9. Apply Flow & History (`src/admin-app/index.js:1720-2295`)
**Scope:** `gs` opens apply modal, `_s` sends POST `/apply`, `Oe/Pe` busy flags, error notices, post-apply summary card (`Ke`, `Xs`, `Ks`), clearance/backups (`$s`), toast notifications.

**State/actions:** Apply modal open flag, apply history array (recent runs), `qe` error string, `Ke` result payload, `ft` “Clear backups” spinner, `It/Bt` reused for gating.

**Dependencies:** Entities decisions summary, resolver metrics (counts for apply summary), duplicates (must be zero before apply), mask state (should be applied).

**Sub-components:**
- `ApplyCTA` (primary button + warnings)
- `ApplyModal` (mode select, confirmation copy)
- `ApplyHistoryList` (recent runs table)
- `BackupControls` (clear backups action)
- `ApplyResultAlert` (success/failure summary)

**Refactor steps**
- [ ] Extract `ApplyModal` with a typed payload (proposal metadata, decision counts, warnings).
- [ ] Wrap `/apply` POST + history fetching into `useApplyProposal(proposalId)` to share logic between modal + toast summarizer.
- [ ] Move backup clearing UI into a settings drawer or command palette to trim the main panel.
- [ ] Capture analytics/telemetry hooks to monitor apply failures post-refactor.
- [ ] Add optimistic UI updates for apply history list so users see entries immediately after submission.
- [ ] Write integration tests covering success, partial failure, hash override prompts, and auto-clear settings.
- [ ] QA: simulate apply success/failure, verify history entries, confirm auto-clear messaging + resolver summary match current output.

### 10. Global Resolver Rules Manager (`src/admin-app/index.js:3000-3320`)
**Scope:** Expandable section listing remembered resolver decisions, search/filter, checkbox selection, add/edit form with action/target/note fields, delete bulk action, CSV import/export future hooks.

**State/actions:** `D` (collapsed), `r/d` (loading/error), `P` (rule list), `U` (search query), `m` (selected map), `E` (edit form state), `R` (modal toggle), `R` ??? (maybe `setCollapsed`), `A/E` states for forms.

**Dependencies:** Resolver attachments keep this list in sync (remember-as-global), shared fetch helpers, toast stack.

**Sub-components:**
- `ResolverRulesSection` (collapsible wrapper)
- `ResolverRuleTable` (current rules list + selection)
- `ResolverRuleSearch` (filter input)
- `ResolverRuleForm` (add/edit modal)
- `ResolverRuleBulkActions` (delete/export)

**Refactor steps**
- [ ] Move rule fetching/mutation to `useGlobalResolverRules`.
- [ ] Separate concerns into `ResolverRuleList`, `ResolverRuleForm`, `ResolverRuleBulkActions`.
- [ ] Ensure local form state resets on open/close and integrate confirm dialogs centrally.
- [ ] Add optional CSV import/export stubs with TODOs so future automation knows where to hook in.
- [ ] Provide form validation helpers (target ID numeric, action required) with inline error messaging.
- [ ] Add Jest tests verifying bulk delete payloads and optimistic UI removal behave as expected.
- [ ] QA: search, add/edit rule, delete single rule, bulk delete, confirm remember-as-global pipeline reflects changes.

### 11. Notifications & Toast Stack (`src/admin-app/index.js:2052-2105`)
**Scope:** `nt` array of toasts, addition helpers (`Zs`, `Qs`), dismiss button `js`, severity-specific styling.

**State/actions:** Each toast stores `id`, `severity`, `title`, `message`, `timestamp`, optional detail.

**Dependencies:** All other modules push success/error states through this stack.

**Sub-components:**
- `ToastProvider` (context + reducer)
- `ToastViewport` (list rendering + auto-dismiss)
- `ToastItem` (content, timestamp, close button)
- `useToasts` hook façade (success/error helpers)

**Refactor steps**
- [ ] Implement a `useToasts` hook with context/provider so downstream modules call `toasts.success("…")`.
- [ ] Provide auto-expire + manual dismiss controls without duplicating setTimeout logic in `AdminApp`.
- [ ] Document severity guidelines (info/warn/error/success) so future modules stay consistent.
- [ ] Ensure toasts preserve focus/ARIA for accessibility when they appear/dismiss.
- [ ] Add timeout configuration (per severity) and expose it via hook options for long-running actions.
- [ ] Write unit tests to confirm queue trimming behavior when more than N toasts are pushed simultaneously.
- [ ] QA: trigger success/error toasts from uploader, resolver, masking, ensure dismissal + auto-expire still works.

## Suggested Module Layout
- `src/admin-app/api/` — `client.ts`, `proposals.ts`, `entities.ts`, `resolver.ts`, `masking.ts`.
- `src/admin-app/hooks/` — `useProposals`, `useEntities`, `useEntityDecisions`, `useDuplicates`, `useMasking`, `useResolverConflicts`, `useGlobalResolverRules`, `useToasts`.
- `src/admin-app/components/` — feature folders (`proposals`, `entities`, `entity-drawer`, `resolver`, `tools`, `masking`, `apply`, `duplicates`, `toast-stack`).
- `src/admin-app/state/` — shared context or Zustand/reducer implementations to coordinate selections, filters, and drawers.
- `src/admin-app/styles/` — co-locate SCSS/CSS modules per component to replace the single `style.css`.

## Process & Workflow Guidelines

### Branching & Releases
- Every epic/sub-epic should live on its own feature branch (e.g., `feature/refactor-entities-table`). Keep `main` pinned to the legacy bundle until the modular build passes full QA.
- Before merging, run the testing matrix (below) and update the REFRACTOR checklist with status + notes.
- Tag releases whenever a parity milestone is hit (e.g., `v1.1.0-refactor-m1`) so we can roll back to the legacy bundle if the modular build regresses.

### Feature Flag Strategy
- Introduce a WP constant or filter (e.g., `DBVC_USE_MODULAR_APP`) that controls which entry bundle enqueues.
- During the refactor, ship the new components behind that flag and document how to toggle via `wp-config.php` or a plugin setting.
- QA should test both states (legacy vs. modular) whenever the flag implementation changes.

### Testing Matrix (minimum per PR)
| Area | Automated | Manual |
| --- | --- | --- |
| JS Modules | `npm run lint`, `npm test` (unit + component) | Smoke test proposal load, entity selection, drawer decisions |
| PHP/WP Integration | `composer test` (when available) | Verify REST endpoints via plugin UI on min/max supported WP versions |
| Masking/Resolver | Jest tests for hooks | Run masking drawer (load/apply/undo) and resolver bulk apply with sample conflicts |
| Apply Flow | Integration test covering success/failure payloads | Run apply modal through success + failure scenarios with sample proposals |

Document any deviations from this matrix in PR notes and update the matrix as new tooling appears.

### Naming & Schema Timing
- [ ] Run a UI naming pass in late beta (before RC) to finalize labels/badges across the modular app.
- [ ] Keep display-label updates (badges/headings/copy) decoupled from stored-key changes so text can evolve without data risk.
- [ ] Schedule plugin-created meta field/key renames only after schema freeze, with backward-read compatibility and one-time migration logic.
- [ ] Add migration tests covering old-key read/new-key write behavior before enabling renamed meta keys by default.
- [ ] Freeze naming/schema changes at RC; avoid additional terminology or key churn after release candidate cut.

### Data Attribute Strategy
- [ ] Standardize `data-*` attribute hooks for refactored components and generated child elements to improve QA automation, instrumentation, and debugging.
- [ ] Define and document a shared attribute convention in `docs/UI-ARCHITECTURE.md` (e.g., `data-component`, `data-slot`, `data-entity-id`, `data-field-key`, `data-change-type`, `data-decision-state`, `data-resolver-state`).
- [ ] Ensure stable selectors exist across critical review surfaces:
  - Entity table rows/cells/actions
  - Entity drawer sections/field rows/decision controls
  - Resolver conflicts/actions
  - Apply CTA/modal/summary states
- [ ] Keep `data-*` attributes out of styling logic (`CSS` should not depend on these selectors).
- [ ] Avoid placing sensitive/raw content values in attributes; use IDs/enums only.
- [ ] Update QA tests to prefer `data-*` selectors over brittle text/class-based selectors.
- [ ] Add a PR checklist item to prevent selector drift while refactoring.

### Logging & Telemetry
- Add structured logging (via `console.info` in dev or remote logging hook) for critical actions: masking apply, resolver bulk actions, proposal apply, duplicate cleanup.
- Include correlation IDs (proposal ID, vf_object_uid) so support can trace issues quickly.
- When releasing flagged builds, monitor logs to ensure new telemetry doesn’t overwhelm WP debug output.

### Contribution Checklist / AGENT.md
- Create `AGENT.md` (or extend CONTRIBUTING.md) summarizing:
  - Repo structure (source vs. build artifacts) and the requirement to back up `src/admin-app/index.js`/`build/admin-app.js` before edits.
  - Coding standards (ESLint/Prettier settings, PHP coding style) and how to run lint/tests locally.
  - Steps for updating REFRACTOR status + QA notes after finishing a sub-step.
  - How to flip the feature flag for manual testing and which sample proposals to use (masking, duplicates, resolver conflicts).
- Link to this REFRACTOR doc plus the testing matrix so new contributors know the expectations upfront.

Document every extraction in this file (update status + add notes) and keep staging builds under `build/` so QA can compare behavior between the legacy bundle and the modular build before switching the main entry point.

## Additional Refactor Targets
- **REST upload helpers:** `upload_proposal()` and the new `upload_fixture()` repeat sideload validation, overwrite sanitization, and cleanup logic (including `rest_sanitize_boolean` fallbacks). Plan a shared helper (PHP trait or private method) so future upload surfaces reuse one battle-tested path and automatically benefit from enhanced logging/error handling.
- **Masking stores:** The suppression/override helper tree under `get_mask_suppression_store`, `store_mask_suppression`, `cleanup_mask_store`, etc., mirrors structure for both store types. Evaluate whether a generic store service (class or trait) could replace the duplicated functions and clarify the data model for future enhancements.
- **Decision/badge UI:** Badge rendering logic is scattered (`v()`, inline `<span className="dbvc-badge ...">`, resolver status chips, new-entity badges). Introduce a shared `Badge`/`Pill` component fed by entity state so labels, colors, and tooltips update automatically when selection counts change (e.g., dynamic “Pending decisions” or “New term accepted” copy).
- **Bulk action handlers:** Accept/keep/clear/new-entity handlers (`ls`, `as`, `is`, `Ss`, `Rs`, etc.) use near-identical POST payload setup and toast logic. Consider a `useBulkEntityMutation` hook (or reducer) that centralizes confirmation prompts, loading states, and success/error toasts so every bulk action inherits consistent UX and analytics.
- **Tooltip system:** We now ship a `TooltipWrapper` fallback plus CSS tooling in `src/admin-app/index.js`/`style.css`. As each component is modularized, wrap contextual hints (buttons, badges, status chips) with this shared wrapper (or a future dedicated tooltip component) rather than sprinkling ad-hoc `t?.Tooltip` checks. This keeps UX consistent and makes it easy to swap to a formal tooltip library later.

## Canonical Entity Notes (Draft)

### dbvc_entity meta object (single meta value)
- Store as one serialized meta object named `dbvc_entity`.
- Scope: per-entity "current state" only. Do not duplicate immutable history.
- Suggested shape:
  - `pStatus_current`: `NONE | REVIEW | CANONICAL | DIVERGED`
  - `canonical_revision_id`: `uuid|null`
  - `canonical_hash`: `sha256|null`
  - `canonical_version`: `int|semver|null`
  - `canonical_status`: `GOD|MOD|null`
  - `last_review_packet_id`: `uuid|null`
  - `last_review_at`: `iso8601|null`
  - `last_sync_at`: `iso8601|null`
  - `source_site_id`: `string|null` (authority that last approved)
  - `forked_from_revision_id`: `uuid|null` (if local divergence)

### Normalized hashing rules (canonical comparison)
- Input payload must be normalized before hashing to avoid order/format drift.
- Normalization steps:
  - Strip non-deterministic fields (timestamps, IDs, GUIDs, site-specific paths).
  - Sort object keys recursively (lexicographic).
  - For arrays that are order-insensitive (terms, meta lists), sort by stable key (e.g., `id` or `slug`).
  - Convert all numbers to JSON numbers, booleans to JSON booleans, nulls preserved.
  - Normalize strings by trimming trailing whitespace and normalizing line endings to `\n`.
  - Encode as UTF-8 JSON with no pretty-printing and no escaped slashes.
- Hashing:
  - `sha256(normalized_json)`
  - Store as `sha256:<hex>` in both history entries and `dbvc_entity`.

### Canonical authority settings (Authority Site)
- Store in one settings option (single object).
- Suggested shape:
  - `canonical_authority`:
    - `mode`: `authority_site`
    - `authority_url`: `https://example.com`
    - `auth_method`: `app_password | oauth | shared_token`
    - `auth_username`: `string|null` (app_password only)
    - `auth_secret`: `string|null` (token/app password)
    - `site_id`: `string|null` (optional)
    - `sync_direction`: `pull_only | pull_and_submit`
    - `last_test_at`: `iso8601|null`
    - `last_test_status`: `ok|error|null`
    - `last_test_error`: `string|null`
    - `last_sync_at`: `iso8601|null`
    - `last_sync_status`: `ok|error|null`
    - `last_sync_error`: `string|null`

### Canonical authority UI spec (Configure → Certified Canonicals → Authority Site)
- Fields:
  - `Authority URL` (required, URL)
  - `Auth method` (select: `Application Password`, `OAuth`, `Shared Token`)
  - `Username` (required only for Application Password)
  - `Secret/Token` (required for all auth methods)
  - `Site ID` (optional, text)
  - `Sync direction` (select: `Pull only`, `Pull + Submit proposals`)
  - `Test connection` (button)
  - `Last test status` (read-only)
  - `Last test time` (read-only)
  - `Last sync status` (read-only)
  - `Last sync time` (read-only)
- Validation:
  - Block save if `Authority URL` or `Secret/Token` is empty.
  - Require `Username` only when `Auth method` is `Application Password`.
  - Normalize URL (strip trailing slash).
- Defaults:
  - `Auth method`: `Shared Token`
  - `Sync direction`: `Pull + Submit proposals`
  - Status fields default to `null`/empty.

### Concrete settings API code (WP option registration + sanitization)
```php
// In plugin bootstrap or admin settings file.
add_action('admin_init', function () {
    register_setting(
        'dbvc_settings',
        'dbvc_canonical_authority',
        [
            'type' => 'array',
            'description' => 'Canonical authority (WordPress site) configuration.',
            'sanitize_callback' => 'dbvc_sanitize_canonical_authority',
            'default' => [
                'mode' => 'authority_site',
                'authority_url' => '',
                'auth_method' => 'shared_token',
                'auth_username' => '',
                'auth_secret' => '',
                'site_id' => '',
                'sync_direction' => 'pull_and_submit',
                'last_test_at' => null,
                'last_test_status' => null,
                'last_test_error' => null,
                'last_sync_at' => null,
                'last_sync_status' => null,
                'last_sync_error' => null,
            ],
            'show_in_rest' => [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'mode' => ['type' => 'string'],
                        'authority_url' => ['type' => 'string'],
                        'auth_method' => ['type' => 'string'],
                        'auth_username' => ['type' => 'string'],
                        'auth_secret' => ['type' => 'string'],
                        'site_id' => ['type' => 'string'],
                        'sync_direction' => ['type' => 'string'],
                        'last_test_at' => ['type' => ['string', 'null']],
                        'last_test_status' => ['type' => ['string', 'null']],
                        'last_test_error' => ['type' => ['string', 'null']],
                        'last_sync_at' => ['type' => ['string', 'null']],
                        'last_sync_status' => ['type' => ['string', 'null']],
                        'last_sync_error' => ['type' => ['string', 'null']],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ]
    );
});

function dbvc_sanitize_canonical_authority($raw) {
    $raw = is_array($raw) ? $raw : [];
    $auth_method = isset($raw['auth_method']) ? sanitize_key($raw['auth_method']) : 'shared_token';
    $sync_direction = isset($raw['sync_direction']) ? sanitize_key($raw['sync_direction']) : 'pull_and_submit';
    $authority_url = isset($raw['authority_url']) ? esc_url_raw(trim($raw['authority_url'])) : '';
    $authority_url = rtrim($authority_url, '/');

    $out = [
        'mode' => 'authority_site',
        'authority_url' => $authority_url,
        'auth_method' => in_array($auth_method, ['app_password', 'oauth', 'shared_token'], true) ? $auth_method : 'shared_token',
        'auth_username' => sanitize_text_field($raw['auth_username'] ?? ''),
        'auth_secret' => sanitize_text_field($raw['auth_secret'] ?? ''),
        'site_id' => sanitize_text_field($raw['site_id'] ?? ''),
        'sync_direction' => in_array($sync_direction, ['pull_only', 'pull_and_submit'], true) ? $sync_direction : 'pull_and_submit',
        'last_test_at' => null,
        'last_test_status' => null,
        'last_test_error' => null,
        'last_sync_at' => null,
        'last_sync_status' => null,
        'last_sync_error' => null,
    ];

    // Guard: require username for app_password
    if ($out['auth_method'] === 'app_password' && $out['auth_username'] === '') {
        add_settings_error('dbvc_canonical_authority', 'dbvc_auth_username', 'Username required for Application Password.', 'error');
    }

    // Guard: require authority URL + secret for all methods
    if ($out['authority_url'] === '' || $out['auth_secret'] === '') {
        add_settings_error('dbvc_canonical_authority', 'dbvc_auth_missing', 'Authority URL and Secret/Token are required.', 'error');
    }

    return $out;
}
```

### Additional specs to define before implementation
- REST endpoints and payloads for:
  - `POST /dbvc/v1/canonical/test-connection`
  - `POST /dbvc/v1/canonical/proposals`
  - `GET /dbvc/v1/canonical/entities/{entity_id}`
  - `POST /dbvc/v1/canonical/reviews/{packet_id}` (approve/reject)
- Auth strategy details (Application Password vs OAuth vs shared token):
  - Required headers/nonce usage and how secrets are stored/rotated.
  - How to verify authority site identity (site ID, public key, or fingerprint).
- Hash normalization contract (exact field allow/deny list per CPT/taxonomy).
- Canonical registry schema (WP CPT or custom table) and migration strategy.
- Sync cadence (manual vs scheduled) and retry/backoff policy.
- Conflict handling rules (two competing proposals, stale approvals).

### REST endpoint contracts (Authority Site)

#### Auth headers (examples)
- Shared Token:
  - `Authorization: Bearer <shared_token>`
- Application Password (WP):
  - `Authorization: Basic base64("username:app_password")`
- OAuth:
  - `Authorization: Bearer <oauth_access_token>`

#### Standard error schema (all endpoints)
```json
{
  "ok": false,
  "error": "string_code",
  "message": "Human-readable message",
  "details": {
    "field": "optional field info",
    "hint": "optional hint"
  }
}
```

#### Standard success envelope (when applicable)
```json
{
  "ok": true,
  "data": { "any": "payload" }
}
```

#### `POST /dbvc/v1/canonical/test-connection`
- Purpose: validate auth + reachability of authority site.
- Auth: `Authorization` header (method-dependent).
- Request body:
  - `site_id` (optional)
- Example request:
```json
{
  "site_id": "client-site-001"
}
```
- Response `200`:
  - `ok`: `true`
  - `authority_site_id`
  - `authority_version`
  - `timestamp`
- Example response:
```json
{
  "ok": true,
  "authority_site_id": "authority-site-main",
  "authority_version": "1.8.0",
  "timestamp": "2026-02-07T18:31:00Z"
}
```
- Error responses:
  - `400` invalid request (`error`: `invalid_request`)
  - `401/403` auth failure (`error`: `auth_failed`)
  - `500` server error (`error`: `server_error`)

#### `POST /dbvc/v1/canonical/proposals`
- Purpose: submit a revision for review/promote.
- Auth: `Authorization` header.
- Request body:
  - `packet_id`
  - `entity_id`
  - `revision_id`
  - `hash`
  - `payload` (normalized source payload)
  - `proposed_status`: `GOD|MOD`
  - `submitted_by_site`
  - `submitted_at`
  - `notes` (optional)
- Example request:
```json
{
  "packet_id": "pkt_9c3f",
  "entity_id": "ent_2b1a",
  "revision_id": "rev_8f2d",
  "hash": "sha256:8a7f...c2",
  "payload": {
    "post_type": "bricks_template",
    "title": "Global Header",
    "content": "<div>...</div>"
  },
  "proposed_status": "GOD",
  "submitted_by_site": "client-site-001",
  "submitted_at": "2026-02-07T18:42:00Z",
  "notes": "New conditional logic for VF ACF fields."
}
```
- Response `201`:
  - `ok`: `true`
  - `packet_id`
  - `status`: `REVIEW`
- Example response:
```json
{
  "ok": true,
  "packet_id": "pkt_9c3f",
  "status": "REVIEW"
}
```
- Error responses:
  - `400` invalid payload (`error`: `invalid_payload`)
  - `401/403` auth failure (`error`: `auth_failed`)
  - `409` duplicate packet (`error`: `duplicate_packet`)
  - `422` hash mismatch (`error`: `hash_mismatch`)
  - `500` server error (`error`: `server_error`)

#### `GET /dbvc/v1/canonical/entities/{entity_id}`
- Purpose: fetch canonical record for entity.
- Auth: `Authorization` header (or public read if desired).
- Response `200`:
  - `entity_id`
  - `canonical_revision_id`
  - `canonical_hash`
  - `canonical_version`
  - `canonical_status`: `GOD|MOD`
  - `updated_at`
  - `signature` (optional)
- Example response:
```json
{
  "entity_id": "ent_2b1a",
  "canonical_revision_id": "rev_8f2d",
  "canonical_hash": "sha256:8a7f...c2",
  "canonical_version": "3",
  "canonical_status": "GOD",
  "updated_at": "2026-02-01T12:10:00Z",
  "signature": "sig_abc123"
}
```
- Error responses:
  - `401/403` auth failure (`error`: `auth_failed`) if private
  - `404` not found (`error`: `not_found`)
  - `500` server error (`error`: `server_error`)

#### `POST /dbvc/v1/canonical/reviews/{packet_id}`
- Purpose: authority approves/rejects a proposal.
- Auth: authority-only (e.g., capability check).
- Request body:
  - `decision`: `approve|reject`
  - `reason` (optional)
  - `canonical_version` (required on approve)
  - `canonical_status`: `GOD|MOD` (required on approve)
- Example request (approve):
```json
{
  "decision": "approve",
  "canonical_version": "4",
  "canonical_status": "GOD",
  "reason": "Approved for global rollout."
}
```
- Example request (reject):
```json
{
  "decision": "reject",
  "reason": "Fails template QA checks."
}
```
- Response `200`:
  - `ok`: `true`
  - `packet_id`
  - `decision`
  - `canonical_revision_id` (on approve)
  - `canonical_hash` (on approve)
  - `reviewed_at`
- Example response:
```json
{
  "ok": true,
  "packet_id": "pkt_9c3f",
  "decision": "approve",
  "canonical_revision_id": "rev_8f2d",
  "canonical_hash": "sha256:8a7f...c2",
  "reviewed_at": "2026-02-07T19:05:00Z"
}
```
- Error responses:
  - `400` invalid decision (`error`: `invalid_decision`)
  - `401/403` auth failure (`error`: `auth_failed`)
  - `404` packet not found (`error`: `packet_not_found`)
  - `409` stale packet (`error`: `stale_packet`)
  - `500` server error (`error`: `server_error`)

### UI documentation tab: steps to add these contracts
- Add a new “Canonical Authority API” section in the existing Documentation tab.
- Include:
  - Auth header examples (Shared Token, App Password, OAuth).
  - Standard error schema and success envelope.
  - Each endpoint with:
    - Purpose
    - Method/path
    - Request JSON example
    - Response JSON example
    - Error codes list
- Provide a collapsible UI for each endpoint to keep the docs scannable.
- Link to the Configure → Certified Canonicals → Authority Site settings panel for setup prerequisites.

### PHP settings page stub (Authority Site subtab)
```php
// Admin menu hookup (existing Configure page assumed).
add_action('admin_menu', function () {
    add_submenu_page(
        'dbvc-configure',
        'Certified Canonicals',
        'Certified Canonicals',
        'manage_options',
        'dbvc-certified-canonicals',
        'dbvc_render_certified_canonicals_page'
    );
});

function dbvc_render_certified_canonicals_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    $options = get_option('dbvc_canonical_authority', []);
    $defaults = [
        'authority_url' => '',
        'auth_method' => 'shared_token',
        'auth_username' => '',
        'auth_secret' => '',
        'site_id' => '',
        'sync_direction' => 'pull_and_submit',
        'last_test_at' => '',
        'last_test_status' => '',
        'last_sync_at' => '',
        'last_sync_status' => '',
    ];
    $options = wp_parse_args($options, $defaults);
    ?>
    <div class="wrap">
        <h1>Certified Canonicals</h1>
        <h2 class="nav-tab-wrapper">
            <a href="#" class="nav-tab nav-tab-active">Authority Site</a>
            <!-- Future tabs: Object Types, Status Rules, Logs -->
        </h2>
        <form method="post" action="options.php">
            <?php settings_fields('dbvc_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="authority_url">Authority URL</label></th>
                    <td><input type="url" id="authority_url" name="dbvc_canonical_authority[authority_url]" value="<?php echo esc_attr($options['authority_url']); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="auth_method">Auth method</label></th>
                    <td>
                        <select id="auth_method" name="dbvc_canonical_authority[auth_method]">
                            <option value="shared_token" <?php selected($options['auth_method'], 'shared_token'); ?>>Shared Token</option>
                            <option value="app_password" <?php selected($options['auth_method'], 'app_password'); ?>>Application Password</option>
                            <option value="oauth" <?php selected($options['auth_method'], 'oauth'); ?>>OAuth</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="auth_username">Username (App Password)</label></th>
                    <td><input type="text" id="auth_username" name="dbvc_canonical_authority[auth_username]" value="<?php echo esc_attr($options['auth_username']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="auth_secret">Secret/Token</label></th>
                    <td><input type="password" id="auth_secret" name="dbvc_canonical_authority[auth_secret]" value="<?php echo esc_attr($options['auth_secret']); ?>" class="regular-text" required></td>
                </tr>
                <tr>
                    <th scope="row"><label for="site_id">Site ID (optional)</label></th>
                    <td><input type="text" id="site_id" name="dbvc_canonical_authority[site_id]" value="<?php echo esc_attr($options['site_id']); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="sync_direction">Sync direction</label></th>
                    <td>
                        <select id="sync_direction" name="dbvc_canonical_authority[sync_direction]">
                            <option value="pull_only" <?php selected($options['sync_direction'], 'pull_only'); ?>>Pull only</option>
                            <option value="pull_and_submit" <?php selected($options['sync_direction'], 'pull_and_submit'); ?>>Pull + Submit proposals</option>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <h2>Status</h2>
        <table class="widefat striped" style="max-width: 760px;">
            <tbody>
                <tr>
                    <th>Last test status</th>
                    <td><?php echo esc_html($options['last_test_status']); ?></td>
                </tr>
                <tr>
                    <th>Last test time</th>
                    <td><?php echo esc_html($options['last_test_at']); ?></td>
                </tr>
                <tr>
                    <th>Last sync status</th>
                    <td><?php echo esc_html($options['last_sync_status']); ?></td>
                </tr>
                <tr>
                    <th>Last sync time</th>
                    <td><?php echo esc_html($options['last_sync_at']); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php
}
```


---

> **Doc hygiene reminder:** Once an epic graduates to its own module(s), move the detailed steps/QA notes from this file into a wiki page or `docs/refactor/<epic>.md` entry. Replace the section here with a short summary + link. This keeps Codex/OpenAI context small and makes it easier to reference just the relevant notes during development.
