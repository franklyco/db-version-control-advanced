# 05 — Implementation Plan

## Goal

Implement the workbench incrementally without forcing a risky rewrite.

## Phase 0 — Repo discovery and mapping

Before building, inspect:

- current migration routes/views
- current result card system
- run/page/recommendation schemas
- state/store strategy
- shared UI primitives
- APIs/actions for approve/reassign/rerun
- existing unresolved/conflict status handling

### Deliverables
- discovery summary
- mapping of proposed surfaces to actual files
- implementation approach note

## Phase 1 — Workbench shell + core review loop

### Scope
- new workbench route or embedded mode
- top app bar
- left source evidence pane
- center target section workbench
- right inspector
- bottom unmatched/warnings dock
- page/section/slot status rendering
- accept / reassign / unresolved actions

### Success criteria
- a user can review a page by section
- a user can inspect source evidence
- a user can manually reassign without returning to the old card feed
- unresolved items remain visible and safe

## Phase 2 — Better operator speed

### Scope
- manual field picker
- section-level batch actions
- repeater row management
- keyboard shortcuts
- structured diff preview
- conflict surfaces

### Success criteria
- operator throughput improves
- ambiguous items are easier to resolve
- repeater-heavy pages become manageable

## Phase 3 — Triage and readiness

### Scope
- bulk triage table across pages
- preview mode
- page/package readiness modal
- activity feed
- richer warnings/conflict dock

### Success criteria
- operators can prioritize attention quickly
- page readiness is easy to judge
- auditability improves

## Phase 4 — Advanced refinement

### Scope
- drag-and-drop enhancements for leftovers
- richer rerun controls
- benchmark overlays if available
- collaboration/review ownership if needed

### Success criteria
- the system feels mature without becoming overbuilt

## Suggested implementation order inside Phase 1

1. route / screen shell
2. page selector + status header
3. source block list
4. target section panels
5. inspector basics
6. actions wired to existing handlers
7. unmatched dock
8. section acceptance helpers
9. polish and audit surfaces

## Anti-rewrite guardrails

Do not rebuild:
- the migration engine
- all domain models
- the entire design system

Prefer:
- adapters
- wrapper components
- progressive replacement of old card UI
- coexistence during rollout if needed
