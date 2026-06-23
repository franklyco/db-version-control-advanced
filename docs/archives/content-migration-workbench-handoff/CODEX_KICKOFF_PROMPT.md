# Codex Kickoff Prompt — DBVC Content Migration Workbench

You are working inside the local DBVC repository.

Your job is to implement or scaffold a new **DBVC Content Migration Workbench** UI that replaces or materially improves the current field-card decision experience for Content Migration V2 / page review.

Do not assume the repo matches this handoff exactly. You must adapt to the existing codebase.

## Required working style

1. Inspect first. Do not start patching blindly.
2. Favor reuse over reinvention.
3. Preserve current run pipeline assumptions unless a change is clearly necessary.
4. Prefer incremental implementation over large rewrites.
5. Keep the interface section-based and page-oriented.
6. Bias toward unresolved instead of incorrect forced mapping.
7. Maintain auditability for manual overrides and approvals.
8. Build for review-by-exception.

## Discovery checklist

Before implementing, inspect and summarize:

- the current Content Migration addon structure
- the current UI entry point(s) for migration results or review
- any run/page/recommendation models currently used
- existing page review components
- existing action APIs for approve / reject / reassign / rerun
- current state/store pattern
- current design system / UI primitives / component library
- route conventions
- styling approach
- keyboard shortcut infrastructure, if any
- table/grid libraries already in use
- drag-drop utilities already in use
- existing audit/logging/history surfaces
- any existing “unresolved”, “blocked”, or “confidence” state concepts

Do not skip discovery.

## Implementation target

Implement a **page-level workbench** with the following structure:

- left pane = source evidence
- center pane = target page workbench grouped by section
- right pane = inspector / decision panel
- bottom dock = unmatched, warnings, conflicts, activity, shortcuts

Primary interaction model:

- click-to-assign first
- manual field picker for reassignment
- drag-and-drop only where spatial movement is clearly useful, such as unmatched items into repeaters or section buckets

## Minimum viable implementation target

Phase 1 should deliver:

- workbench shell
- page-level review route or embedded view
- section-based target rendering
- source evidence list
- contextual inspector
- accept / reassign / unresolved actions
- unmatched tray
- page and section status model surfaced in UI

## Strong constraints

Do **not** build:

- a full visual page builder
- a raw ACF form UI dump
- a field-card feed as the main interaction model
- a drag-and-drop-only workflow
- a giant unstructured diff screen as the primary review experience

## Adaptation requirement

If the existing DBVC repo already has names, models, or UI concepts that overlap with this handoff:

- align to existing terminology
- preserve current patterns
- avoid introducing duplicate concepts

If the repo differs substantially, create a compatibility layer or adapter rather than forcing a data rewrite.

## Deliverables expected from you

1. discovery summary
2. implementation plan mapped to actual repo files
3. phase 1 implementation
4. notes on what was adapted
5. follow-up TODOs for later phases

## Files to read from this handoff package

Read these before implementing:

- `README.md`
- `docs/01-product-overview.md`
- `docs/02-workbench-ui-layout.md`
- `docs/03-components-and-state.md`
- `docs/04-interaction-flows.md`
- `docs/05-implementation-plan.md`
- `docs/06-acceptance-criteria.md`
- `docs/07-adaptation-notes.md`
- `docs/08-wireframes-ascii.md`
- `docs/09-data-contracts-and-status-model.md`
- `docs/10-risk-and-anti-patterns.md`

## Final instruction

Do not ask the user to re-explain the goal if the repo already contains enough evidence to proceed. Inspect the repo and adapt this workbench to the real DBVC architecture.
