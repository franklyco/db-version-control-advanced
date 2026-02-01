# DBVC Admin App REFRACTOR Plan

> **TL;DR:** This file maps the monolithic admin bundle into 11 epics, each with sub-components, refactor tasks, QA notes, and now also process guidance (branching, feature flag, testing matrix). Treat it as the high-level table of contents. As the sprint progresses, migrate completed/active epics into dedicated wiki pages (one per epic or per related group) so contributors can work from smaller docs and Codex/OpenAI sessions can reference focused summaries instead of this entire file. Keep this top section updated with links to those wiki pages.

This document maps the current React bundle (`src/admin-app/index.js`) into logical domains so we can rebuild the admin UI from manageable modules. The plan emphasizes keeping a backup/staging copy of the generated bundle (`build/admin-app.js` + the current `src/admin-app/index.js`) while new source files are created, and encourages working branch-by-branch so the production plugin always has a working reference artifact.

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
| ⬜ | Proposal intake & uploader | Proposal list, uploader dropzone, refresh, toasts | `src/admin-app/index.js:240-360`, `1349-1408`, `2052-2140` | Shared utilities |
| ⬜ | Duplicate summary & gating | `/duplicates` fetch, modal, canonical marking, bulk cleanup | `src/admin-app/index.js:1164-1442`, `2304-2356`, `2570-2650` | Proposal intake, HTTP helpers |
| ⬜ | Entities dataset, filters & All Entities table | Status filters, search, selections, column toggles, table render | `src/admin-app/index.js:420-520`, `2300-2560` | Proposal intake, duplicates |
| ⬜ | Entity drawer & diff engine | Drawer focus trap, field decisions, bulk Accept/Keep, snapshot capture | `src/admin-app/index.js:499-900`, `2650-2890` | Entities dataset |
| ⬜ | Media resolver attachments & conflict actions | Resolver summary, attachment list, bulk apply tool, remember-as-global | `src/admin-app/index.js:860-1110`, `2780-3050` | Entity drawer |
| ⬜ | Tools drawer & masking workflow | Mask fetch/apply/undo, session storage, tool toggle, hash helpers | `src/admin-app/index.js:1187-1348`, `1664-1755`, `2390-2520` | Entities dataset, duplicates |
| ⬜ | Bulk entity actions & new-entity gating | Accept new entities, selection batches, snapshot capture, hash sync | `src/admin-app/index.js:2320-2560`, `1390-1485` | Entities dataset |
| ⬜ | Apply flow & history | Apply modal, close/apply POST, success/failure toasts, backups | `src/admin-app/index.js:1720-2295` | Entities dataset, resolver |
| ⬜ | Global resolver rules manager | Rule table, search, selection, add/edit/delete forms | `src/admin-app/index.js:3000-3320` | Media resolver attachments, shared utilities |
| ⬜ | Notifications & toast stack | Toast state, dismissal, severity rendering | `src/admin-app/index.js:2052-2105` | Proposal intake |

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
- [ ] Document these helpers in `AGENT.md`/README so contributors know which files are generated vs. source.
- [ ] Update downstream modules to import helpers instead of relying on closure scope.
- [ ] Add lint rule or CI check to prevent direct `window.fetch` usage outside the client module.
- [ ] Add unit tests verifying date/value formatting handles empty/null/boolean inputs as expected.
- [ ] QA: smoke test formatter usage in the All Entities table + badges to ensure nothing regresses.

### 2. Proposal Intake & Uploader (`src/admin-app/index.js:240-360`, `1349-1408`, `2052-2140`)
**Scope:** Proposal table component (`w`), uploader dropzone (`C`), GET `/proposals` loader (`zt`), refresh button, upload success/error toasts (`Zs`, `Qs`).

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

**State/actions:** Entities array `te`, filter `ne`, search `le`, column toggles `xt`, selection set `Ot`, refresh button `refreshEntities`.

**Dependencies:** Shared columns metadata, duplicates (to disable actions), new-entity subset (`ss`), mask attention.

**Sub-components:**
- `EntityFilters` (status dropdown + search)
- `EntityStatusBadges` (counts row)
- `EntityColumnToggle` (checkbox grid)
- `EntityTable` (table + selection checkboxes)
- `useEntitySelection` (Set management)

**Refactor steps**
- [ ] Split data fetching into `useEntities(proposalId, filter)` returning list + summary + refresh.
- [ ] Extract table, filters, status badges, and column toggle UIs into separate components (e.g., `EntityFilters`, `EntityStatusBadges`, `EntityTable`).
- [ ] Replace raw `Set` manipulations with dedicated selection hook so the drawer/bulk actions can subscribe cleanly.
- [ ] Memoize column configs and selection state to avoid unnecessary rerenders.
- [ ] Add virtualization or pagination abstraction so large proposals don’t re-render entire tables during filter changes.
- [ ] Write snapshot/unit tests for selection reducer to ensure toggling/clear/select-all flows behave.
- [ ] QA: filter by each status, search by slug/type, multi-select entities, toggle columns, ensure selection persists while paging.

### 5. Entity Drawer & Diff Engine (`src/admin-app/index.js:499-900`, `2650-2890`)
**Scope:** Drawer focus trap, diff renderer with Accept/Keep radios, new-entity card/gating, bulk Accept/Keep, snapshot capture, hash sync, inline resolver summary.

**State/actions:** `oe` (active entity id), `de` (detail payload), `me` (decisions), `pt`/`mt` flags for clear/snapshot, `T`/`P` for hash sync, `S` component props `onDecisionChange`, `onBulkDecision`.

**Dependencies:** Entities dataset for summary updates, resolver attachments for inline display, snapshot endpoints.

**Sub-components:**
- `EntityDrawerShell` (modal + focus trap)
- `EntityToolbar` (meta/status badges)
- `EntityDiffSections` (accordion)
- `DecisionControls` (Accept/Keep radios + bulk buttons)
- `NewEntityCard` (gating UI)
- `SnapshotControls` (hash/snapshot actions)

**Refactor steps**
- [ ] Move drawer UI into `components/entity-drawer/EntityDrawer.tsx` that accepts typed props (entity, decisions, callbacks).
- [ ] Extract diff table + decision controls into smaller components (`DiffSection`, `FieldDecisionRow`) for readability.
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

---

> **Doc hygiene reminder:** Once an epic graduates to its own module(s), move the detailed steps/QA notes from this file into a wiki page or `docs/refactor/<epic>.md` entry. Replace the section here with a short summary + link. This keeps Codex/OpenAI context small and makes it easier to reference just the relevant notes during development.
