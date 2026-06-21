# 03 — Components and State

This document proposes a component inventory and state model. Adapt the names to the actual DBVC repo.

## Component inventory

### Layout shell
- `WorkbenchShell`
- `TopAppBar`
- `PageNavigator`
- `RunContextBar`
- `LeftEvidencePane`
- `CenterTargetPane`
- `RightInspectorPane`
- `BottomDock`

### Source-side components
- `PageOutlineTree`
- `SourceFilterBar`
- `SourceSearchInput`
- `SourceBlockList`
- `SourceBlockCard`
- `UnmatchedTray`
- `SourceEvidencePopover`

### Target-side components
- `TargetPageHeader`
- `SectionPanel`
- `SectionHeader`
- `SlotRow`
- `SlotStatusBadge`
- `SlotConfidenceMeter`
- `RepeaterRowCard`
- `RepeaterTableView`
- `SectionActionMenu`
- `WireframeMediaPlaceholder`

### Inspector components
- `SelectionSummary`
- `RecommendationCard`
- `AlternativeTargetsList`
- `TransformPreviewCard`
- `ValidationChecklist`
- `OverrideHistoryCard`
- `ActionButtonCluster`
- `ManualFieldPicker`

### Support components
- `StatusPill`
- `WarningPill`
- `BatchActionToolbar`
- `KeyboardShortcutModal`
- `AuditActivityFeed`
- `RerunMenu`
- `PackageReadinessModal`
- `StructuredDiffModal`

## UI state slices

These can be implemented in whatever state/store pattern the repo already uses.

### Run context state
- `runId`
- `schemaVersion`
- `providerVersion`
- `selectedPageId`
- `pageIndex`
- `pageCount`

### Page workbench state
- `selectedSourceBlockId`
- `selectedTargetSlotId`
- `selectedSectionId`
- `activeInspectorMode`
- `activeBottomDockTab`
- `isPreviewMode`
- `paneVisibility`
- `filterState`
- `searchQuery`

### Source evidence state
- `sourceBlocks`
- `outlineTree`
- `unmatchedBlocks`
- `sourceBlockStatuses`
- `sourceSectionGuesses`

### Target workbench state
- `targetObject`
- `targetTemplate`
- `sections`
- `slots`
- `repeaters`
- `sectionStatuses`
- `slotAssignments`
- `assignmentConflicts`
- `validationWarnings`

### Decision state
- `recommendations`
- `alternatives`
- `manualOverrides`
- `acceptances`
- `unresolvedReasons`
- `rerunFlags`

### Activity state
- `auditEvents`
- `lastModifiedBy`
- `lastModifiedAt`

## Core domain view-models

### Source block view-model
Suggested fields:
- `id`
- `type`
- `preview`
- `rawValue`
- `normalizedValue`
- `inferredSection`
- `headingPath`
- `domPath`
- `sourceUrl`
- `status`
- `confidence`
- `recommendedTargetSlotIds`
- `warnings`

### Target section view-model
Suggested fields:
- `id`
- `label`
- `sectionType`
- `status`
- `readinessScore`
- `unresolvedCount`
- `warningCount`
- `slots`
- `actions`

### Target slot view-model
Suggested fields:
- `id`
- `label`
- `technicalPath`
- `fieldType`
- `status`
- `confidence`
- `valuePreview`
- `assignedSourceBlockId`
- `alternativeSourceBlockIds`
- `validation`
- `contractSummary`

## UI events

### Primary events
- `SOURCE_BLOCK_SELECTED`
- `TARGET_SLOT_SELECTED`
- `SECTION_SELECTED`
- `ASSIGNMENT_ACCEPTED`
- `ASSIGNMENT_REASSIGNED`
- `ASSIGNMENT_REMOVED`
- `ASSIGNMENT_MARKED_UNRESOLVED`
- `SECTION_ACCEPT_ALL_SAFE`
- `REPEATER_ROW_CREATED`
- `REPEATER_ROW_MERGED`
- `REPEATER_ROW_SPLIT`
- `PAGE_MARKED_READY`

### Secondary events
- `PAGE_RERUN_REQUESTED`
- `SECTION_RERUN_REQUESTED`
- `OVERRIDE_RESET`
- `FILTER_CHANGED`
- `SEARCH_CHANGED`
- `DOCK_TAB_CHANGED`

## Implementation note

Do not create a second competing canonical data model if the repo already has useful run/recommendation models.

Prefer:
- adapters,
- selectors,
- UI-specific view models,
- thin transformation layers.
