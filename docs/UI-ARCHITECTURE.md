# DBVC Admin App UI Architecture

This document accompanies `docs/REFRACTOR.md` and defines the target UI structure for the modular admin app. Use it as the implementation-facing blueprint for layout, component boundaries, interaction patterns, and review behavior.

## How This Pairs With REFRACTOR
- `docs/REFRACTOR.md` defines epics, migration sequencing, and refactor tasks.
- `docs/UI-ARCHITECTURE.md` defines the intended UX structure and component hierarchy for each feature area.
- When a feature-level task in REFRACTOR is updated, update the matching section here if layout/contracts changed.

## Global UI Guidance

### Shared naming
- Use the same terminology everywhere:
- `Change Summary`
- `Additions`
- `Deletions`
- `Modifications`
- `Total Changes`

### Shared states
- Every major surface must define rendering for:
- `loading`
- `empty`
- `error`
- `stale` (data may be out of date after mutation)
- `busy` (mutation in progress)

### Shared interaction rules
- Keep status badges and change counts visible at section headers.
- Prefer inline actions where review happens; avoid forcing context switches.
- Keep critical review metadata visible while scrolling long content.
- Preserve keyboard and focus behavior in drawer/modal surfaces.

### Accessibility baseline
- Every interactive control must have an accessible name.
- Drawer/modal surfaces must trap focus while open and return focus to trigger on close.
- Keyboard support is required for:
- table row navigation
- drawer change navigation (`previous`, `next`)
- section expand/collapse
- decision toggles
- toasts dismiss
- Use `aria-live` for async action outcomes that are not otherwise obvious.

### Responsive baseline
- Desktop first for diff-heavy workflows; retain side-by-side review at wide breakpoints.
- At narrow widths, collapse side-by-side into stacked `Source` then `Destination` with sticky `Change Summary`.
- Keep primary actions reachable without horizontal scrolling.
- Preserve readable diff blocks for long text and code-like content.

### Completeness guardrails
- Each major component must define:
- loading, empty, error, stale, busy states
- zero-data behavior
- permission/disabled behavior
- retry behavior for failed mutations
- instrumentation hook points for failures

## Cross-Surface State Ownership
- Proposal selection is the top-level key for all downstream data fetches.
- Entities list state is the source of truth for table counts, filters, and selection.
- Entity decision state is the source of truth for drawer and apply summaries.
- Resolver conflict state is shared between resolver workspace and drawer `Media Attachments`.
- Duplicate summary state is shared gating for bulk actions and apply.
- Masking state is shared gating for entities/apply and tools drawer workflows.
- Apply history/result state is independent from list/drawer state and must update optimistically.

## Event and Sync Rules
- Mutation success must update all affected surfaces in the same UI cycle (or via a shared store tick).
- No surface should require manual refresh after a successful mutation.
- Surface-level caches must be invalidated by `proposalId` and mutation scope (`entity`, `resolver`, `duplicates`, `masking`, `apply`).
- Cross-surface contract:
- resolver actions update drawer media badges + entity row badges + resolver summary counts
- decision changes update drawer counts + entities table status + apply summary
- duplicate cleanup updates duplicate badges + gating banners + apply CTA state
- masking apply/revert updates masking badges + entity counts + apply gating

## Component Blueprint By Area

### 0. App Shell & Cross-Cutting Surfaces
Main component:
- `AdminAppShell`

Sub-components:
- `ProposalContextBanner`
- `PrimaryActionBar`
- `GlobalGateStatus`
- `ErrorBoundaryFallback`
- `ClientLogReporter`
- `RouteOrViewStateCoordinator`

UI guidance:
- Keep proposal context visible globally while navigating feature surfaces.
- Centralize blocker visibility (duplicates, masking pending, unresolved conflicts) in one predictable location.
- Keep global error fallback consistent with client logging behavior.

### 1. Proposal Intake & Header Area
Main component:
- `ProposalWorkspace`

Sub-components:
- `ProposalHeader`
- `ProposalUploader`
- `ProposalTable`
- `ProposalRowActions`
- `ProposalRefreshControl`
- `ProposalSelectionState`

### 2. All Entities Table Workspace
Main component:
- `EntitiesWorkspace`

Sub-components:
- `EntitiesHeader`
- `EntitiesTotalsSummary`
- `EntityStatusBadges`
- `EntityFiltersBar`
- `EntitySearchInput`
- `EntityColumnToggle`
- `EntityBulkActions`
- `EntityTable`
- `EntityTableRow`
- `EntityRowStatusBadges`
- `EntitySelectionCheckbox`
- `EntityPaginationOrVirtualList`

UI guidance:
- Keep filters, search, status badges, and selection actions in one stable toolbar row.
- Keep row actions predictable: open drawer, accept/keep shortcuts (if enabled), resolver indicator.
- Preserve sticky header behavior for dense datasets.
- Surface mismatch/gating badges directly in the table row.

### 3. Entity Drawer Review Workspace
Main component:
- `EntityDrawer`

Sub-components:
- `EntityDrawerShell`
- `EntityPanelHeader`
- `ChangeSummaryRail`
- `ChangeNavigator`
- `EntitySectionAccordion`
- `FieldDiffRow`
- `InlineDecisionControls`
- `RawDiffView`
- `SnapshotControls`
- `DrawerQuickActions`

Target layout:
- Three-column workspace:
- `Source (Current)` panel
- center `Change Summary` rail
- `Destination (Proposed)` panel

Section registry:
- `Content`
- `Custom Fields`
- `SEO Metadata`
- `Categories & Tags`
- `Media Attachments`
- `Raw Diff View`

Review model:
- Each field row carries:
- `changeType` (`addition|deletion|modification|unchanged`)
- source value
- destination value
- decision (`accept_proposed|keep_current|unresolved`)

Behavior guidance:
- Navigate by changed fields only.
- Keep section order identical on both side panels.
- Support `View All` mode for unchanged rows.
- Highlight changed spans/tokens, not entire blocks, where possible.

### 4. Media Resolver Workspace
Main component:
- `MediaResolverWorkspace`

Sub-components:
- `ResolverSummaryPanel`
- `ResolverConflictList`
- `ResolverConflictCard`
- `ResolverDecisionForm`
- `ResolverBulkApplyForm`
- `ResolverFilters`
- `RememberGlobalToggle`
- `ResolverActionStatus`

Integration guidance:
- Media resolver status must appear inside Entity Drawer `Media Attachments`.
- Resolver actions taken in drawer must update resolver workspace without manual refresh.
- Resolver counts should remain aligned with drawer and entity-list badges.

### 5. Tools & Masking Workspace
Main component:
- `ToolsDrawer`

Sub-components:
- `ToolsDrawerShell`
- `MaskingPanel`
- `MaskFieldList`
- `MaskFieldRow`
- `MaskBulkControls`
- `MaskProgressMeter`
- `MaskUndoBanner`
- `HashUtilitiesPanel`

UI guidance:
- Keep masking workflow explicit:
- load fields
- review generated actions
- apply
- undo/revert
- Show progress and validation errors inline at the panel level.
- Keep masking warnings/gating visible in entities and apply surfaces.

### 6. Duplicate Summary & Resolution
Main component:
- `DuplicateResolutionWorkspace`

Sub-components:
- `DuplicateSummaryBadge`
- `DuplicateGateNotice`
- `DuplicateModal`
- `DuplicateTable`
- `CanonicalSelectionControl`
- `BulkDuplicateCleanupActions`

UI guidance:
- Block sensitive actions (apply, some bulk ops) when duplicates are unresolved.
- Keep duplicate counts visible in global header and entities toolbar.

### 7. Bulk Actions & Gating Surface
Main component:
- `EntityBulkActionsWorkspace`

Sub-components:
- `SelectionSummary`
- `BulkDecisionButtons`
- `NewEntityActions`
- `DuplicateWarningBanner`
- `MaskingWarningBanner`
- `SnapshotHashActions`

UI guidance:
- Centralize action enable/disable logic from duplicate/masking/resolver state.
- Keep selection counts and scope clear (`selected`, `new`, `all visible`).

### 8. Apply Flow & History
Main component:
- `ApplyWorkspace`

Sub-components:
- `ApplyCTA`
- `ApplyModal`
- `ApplyWarnings`
- `ApplyResultSummary`
- `ApplyHistoryList`
- `BackupControls`

UI guidance:
- Gate apply when unresolved blockers exist.
- Show decision and resolver summaries before confirmation.
- Keep recent history visible and consistent after submit.

### 9. Global Resolver Rules Manager
Main component:
- `ResolverRulesWorkspace`

Sub-components:
- `ResolverRulesSection`
- `ResolverRuleSearch`
- `ResolverRuleTable`
- `ResolverRuleRow`
- `ResolverRuleFormModal`
- `ResolverRuleBulkActions`

UI guidance:
- Keep rule CRUD behavior consistent with resolver decisions.
- Preserve clear validation and conflict messaging in rule forms.

### 10. Notifications & Feedback
Main component:
- `ToastProvider`

Sub-components:
- `ToastViewport`
- `ToastItem`
- `ToastDismissControl`
- `InlineErrorNotice`
- `InlineSuccessNotice`

UI guidance:
- Use toasts for action outcomes and inline notices for contextual issues.
- Keep severity mapping consistent across all areas.

## Shared Contracts (Cross-Area)
- `EntitySummary`
- `EntityDetailPayload`
- `FieldDiffItem`
- `DecisionState`
- `ResolverConflict`
- `DuplicateSummary`
- `MaskingFieldAction`
- `ApplyResult`
- `ToastEvent`

## Data Attribute Contract

### Purpose
- Provide stable selectors for QA automation.
- Expose non-sensitive UI state for instrumentation/debugging.
- Reduce brittleness from text/class-based selectors during refactor.

### Global conventions
- Use kebab-case for values and attribute names.
- Prefer IDs/enums over raw content values.
- Do not use `data-*` attributes for styling hooks.
- Required base attributes on root feature components:
- `data-component`
- `data-surface`
- Optional scoped attributes for state introspection:
- `data-state`
- `data-busy`
- `data-disabled`

### Canonical attributes
- `data-component` (component identity, e.g., `entity-drawer`)
- `data-slot` (sub-region identity, e.g., `change-summary`)
- `data-entity-id` (entity identifier)
- `data-field-key` (field/meta key)
- `data-change-type` (`addition|deletion|modification|unchanged`)
- `data-decision-state` (`unresolved|accept-proposed|keep-current`)
- `data-resolver-state` (`unresolved|resolved|conflict`)
- `data-gate-state` (`blocked|warning|clear`)
- `data-proposal-id` (proposal identifier)

### Required coverage by surface
- All Entities table:
- table root, row, row actions, selection checkbox, status badge, mismatch badge
- Entity Drawer:
- drawer root, source/destination headers, summary rail, section rows, field rows, decision controls, raw diff blocks
- Media Resolver:
- conflict row/card, decision form, bulk action controls, remember-global toggle
- Tools/Masking:
- masking panel root, field row, bulk controls, progress meter, undo/revert controls
- Apply:
- apply CTA, modal root, warning blocks, summary counts, history row
- Duplicates:
- duplicate badge, modal/table rows, canonical selector, cleanup controls

### Example selector usage
- `[data-component="entity-drawer"][data-entity-id="1247"]`
- `[data-slot="field-row"][data-field-key="post_title"][data-change-type="modification"]`
- `[data-component="resolver-conflict"][data-resolver-state="unresolved"]`

## Component Contracts (Implementation Ticket Seed)

### All Entities Table Contracts
| Component | Props In | Events Out | Owned State | Depends On |
| --- | --- | --- | --- | --- |
| `EntitiesWorkspace` | `proposalId`, `featureFlags` | `onOpenEntity(entityId)`, `onApplyRequested()` | local UI mode flags | `useEntities`, `useEntitySelection`, duplicate/masking gates |
| `EntitiesHeader` | `title`, `totals`, `busy` | `onRefresh()` | none | entities summary |
| `EntitiesTotalsSummary` | `proposalTotals`, `currentTotals`, `filteredCount` | none | none | normalized entity stats |
| `EntityFiltersBar` | `statusFilter`, `search`, `availableFilters` | `onStatusFilterChange(value)`, `onSearchChange(value)`, `onClearFilters()` | debounced search value | filter model |
| `EntityColumnToggle` | `columns`, `visibleColumns` | `onColumnToggle(key)`, `onResetColumns()` | local popover open/close | column config constants |
| `EntityBulkActions` | `selectionCount`, `gates`, `busy` | `onAcceptSelected()`, `onUnacceptSelected()`, `onAcceptAllNew()`, `onClearSelection()` | none | `useEntitySelection`, gate resolver |
| `EntityTable` | `rows`, `visibleColumns`, `selection`, `sort` | `onRowToggle(id)`, `onRowOpen(id)`, `onSortChange(sort)` | scroll/pagination cursor | `EntityTableRow` |
| `EntityTableRow` | `entity`, `visibleColumns`, `selected`, `badges` | `onToggleSelect(id)`, `onOpen(id)` | none | row badge formatter |

### App Shell Contracts
| Component | Props In | Events Out | Owned State | Depends On |
| --- | --- | --- | --- | --- |
| `AdminAppShell` | `proposalId`, `featureFlags`, `initialView` | `onProposalChange(id)`, `onGlobalRefresh()` | active view, global busy flags | global stores + query invalidation |
| `GlobalGateStatus` | `duplicates`, `masking`, `resolver`, `applyReady` | `onJumpToDuplicates()`, `onJumpToMasking()`, `onJumpToResolver()` | none | gating selectors |
| `ErrorBoundaryFallback` | `error`, `errorId` | `onRetry()`, `onReport(error)` | retry count | client log endpoint |

### Entity Drawer Contracts
| Component | Props In | Events Out | Owned State | Depends On |
| --- | --- | --- | --- | --- |
| `EntityDrawer` | `entityId`, `proposalId`, `open` | `onClose()`, `onDecisionChange(payload)`, `onNavigate(changeIndex)` | active section, view mode (`diff/view-all/raw`) | `useEntityDetails`, `useEntityDecisions`, resolver bridge |
| `EntityDrawerShell` | `open`, `title`, `sourceMeta`, `destinationMeta` | `onClose()`, `onEscape()` | focus trap refs | modal/focus utilities |
| `ChangeSummaryRail` | `summaryCounts`, `activeChangeIndex`, `totalChanges` | `onPrevChange()`, `onNextChange()`, `onToggleMetadata()`, `onToggleRawDiff()` | none | change index map |
| `EntitySectionAccordion` | `sections`, `expanded`, `viewMode` | `onToggleSection(key)`, `onExpandChangedOnly()` | expanded map | section registry |
| `FieldDiffRow` | `field`, `sourceValue`, `destinationValue`, `decision` | `onDecision(fieldKey, value)` | inline inspect state | diff/token formatter |
| `InlineDecisionControls` | `decision`, `disabled`, `reason` | `onAcceptProposed()`, `onKeepCurrent()` | none | decision policy |
| `RawDiffView` | `rawDiff`, `highlightMode` | `onJumpToField(fieldKey)` | local fold/unfold blocks | diff engine |
| `SnapshotControls` | `hashState`, `snapshotState`, `busy` | `onCaptureSnapshot()`, `onStoreHashes()`, `onClearHashes()` | none | snapshot/hash endpoints |

### Media Resolver Contracts
| Component | Props In | Events Out | Owned State | Depends On |
| --- | --- | --- | --- | --- |
| `MediaResolverWorkspace` | `proposalId`, `entityId?` | `onConflictResolved(conflictId)`, `onOpenEntity(entityId)` | filter draft state | `useResolverConflicts`, global rules |
| `ResolverSummaryPanel` | `counts`, `warnings`, `busy` | `onRefresh()` | none | resolver aggregates |
| `ResolverConflictList` | `conflicts`, `selection`, `saving` | `onSelectConflict(id)`, `onResolveSingle(payload)` | list virtualization cursor | resolver decision API |
| `ResolverDecisionForm` | `conflict`, `defaults`, `rememberGlobal` | `onSubmit(payload)`, `onCancel()` | form state, validation errors | resolver mutation hooks |
| `ResolverBulkApplyForm` | `filters`, `action`, `busy` | `onApply(payload)`, `onReset()` | bulk form state | bulk resolver endpoint |

### Tools and Masking Contracts
| Component | Props In | Events Out | Owned State | Depends On |
| --- | --- | --- | --- | --- |
| `ToolsDrawer` | `proposalId`, `open`, `gates` | `onClose()`, `onMaskingApplied(result)` | active tool tab | `useMasking`, hash utilities |
| `MaskingPanel` | `fields`, `progress`, `errors`, `busy` | `onLoadFields()`, `onApply(actionSet)`, `onUndo()`, `onRevert()` | local selection/preview | masking endpoints |
| `MaskFieldList` | `rows`, `selected`, `bulkAction` | `onToggleRow(id)`, `onBulkActionChange(action)` | row expansion map | masking formatter |
| `MaskBulkControls` | `bulkAction`, `override`, `note`, `disabled` | `onChange(payload)`, `onApply()` | control form state | validation helpers |

### Apply and History Contracts
| Component | Props In | Events Out | Owned State | Depends On |
| --- | --- | --- | --- | --- |
| `ApplyWorkspace` | `proposalId`, `gates`, `decisionSummary`, `resolverSummary` | `onApplyStarted()`, `onApplyFinished(result)` | modal open/close | `useApplyProposal`, gate resolver |
| `ApplyCTA` | `disabled`, `reasons`, `busy` | `onOpenApplyModal()` | none | gate status |
| `ApplyModal` | `summary`, `warnings`, `open` | `onConfirm(payload)`, `onCancel()` | mode selection, confirmation text | apply payload builder |
| `ApplyHistoryList` | `entries`, `loading`, `error` | `onRefreshHistory()` | none | apply history endpoint |
| `ApplyResultSummary` | `result`, `severity` | `onDismiss()` | none | toast + inline notices |

### Duplicates and Global Rules Contracts
| Component | Props In | Events Out | Owned State | Depends On |
| --- | --- | --- | --- | --- |
| `DuplicateResolutionWorkspace` | `proposalId`, `open` | `onCanonicalMarked(payload)`, `onCleanupRun()` | modal state | `useDuplicates` |
| `DuplicateModal` | `rows`, `busy`, `errors` | `onMarkCanonical(id)`, `onBulkCleanup(payload)`, `onClose()` | selected rows | duplicate cleanup endpoints |
| `ResolverRulesWorkspace` | `rules`, `query`, `busy` | `onCreateRule(payload)`, `onUpdateRule(payload)`, `onDeleteRules(ids)` | form modal state | `useGlobalResolverRules` |
| `ResolverRuleFormModal` | `initialValues`, `open`, `errors` | `onSubmit(payload)`, `onCancel()` | controlled form state | rule validators |

### Notifications Contracts
| Component | Props In | Events Out | Owned State | Depends On |
| --- | --- | --- | --- | --- |
| `ToastProvider` | `maxToasts`, `timeoutsBySeverity` | `onToastPushed(event)`, `onToastDismissed(id)` | toast queue reducer | app-wide context |
| `ToastViewport` | `toasts`, `position` | `onDismiss(id)` | none | `ToastItem` |
| `ToastItem` | `toast`, `autoDismissMs` | `onDismiss(id)` | timer ref | severity styles |

## REFRACTOR Coverage Matrix
| REFRACTOR area | Primary UI section | Contract coverage |
| --- | --- | --- |
| Shared HTTP + formatting utilities | Global UI Guidance, Shared Contracts | Partial (implementation helpers referenced, not API details) |
| Proposal intake & uploader | Proposal Intake & Header Area | Complete |
| Duplicate summary & gating | Duplicate Summary & Resolution, GlobalGateStatus | Complete |
| Entities dataset/filters/table | All Entities Table Workspace | Complete |
| Entity drawer & diff engine | Entity Drawer Review Workspace | Complete |
| Media resolver attachments/actions | Media Resolver Workspace | Complete |
| Tools drawer & masking workflow | Tools & Masking Workspace | Complete |
| Bulk entity actions/new-entity gating | Bulk Actions & Gating Surface | Complete |
| Apply flow & history | Apply Flow & History | Complete |
| Global resolver rules manager | Global Resolver Rules Manager | Complete |
| Notifications & toasts | Notifications & Feedback | Complete |

## Ticket-Readiness Checklist
- Each component selected for implementation has:
- owner hook/store identified
- explicit props/events contract
- loading/empty/error states listed
- gating/dependency conditions listed
- QA acceptance test row in this document or `docs/REFRACTOR.md`
- If any item is missing, component is not ticket-ready.

## Open Architecture Decisions
- Table scaling strategy: strict pagination, virtualization, or hybrid.
- Shared state mechanism: context reducers only vs. introducing a dedicated store.
- Raw diff rendering limits: max payload size before truncation/download fallback.
- Report export format: JSON only vs. JSON + CSV/PDF output.
- Breakpoint policy for drawer comparison mode and sticky summary rail behavior.
- Telemetry transport and sampling rules for client-side instrumentation.
- Final fallback behavior when resolver or masking endpoints are unavailable.

## Suggested Folder Mapping
- `src/admin-app/components/proposals/*`
- `src/admin-app/components/entities/*`
- `src/admin-app/components/entity-drawer/*`
- `src/admin-app/components/resolver/*`
- `src/admin-app/components/tools/*`
- `src/admin-app/components/duplicates/*`
- `src/admin-app/components/apply/*`
- `src/admin-app/components/toasts/*`

## QA Seed Checklist By Surface
- All Entities table: filtering, selection, status/mismatch badges, column toggles, dense-list rendering.
- Entity Drawer: side-by-side parity, navigation index correctness, decision persistence, `Raw Diff View`, `View All`.
- Media Resolver: single + bulk resolution, remember-as-global, immediate count sync with drawer/list.
- Masking: load/apply/undo/revert, progress states, validation and retry behavior.
- Apply: blocker gating, success/failure summaries, history refresh.
- Global rules: search, add/edit/delete, bulk delete, validation.
- Notifications: toast severity, dismissal, queue limits, focus/ARIA behavior.
