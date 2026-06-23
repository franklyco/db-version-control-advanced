# 07 — Adaptation Notes

This handoff is intentionally product-forward, not repo-naive.

## Important rule

Adapt to the real DBVC codebase. Do not force literal names or structures from this package if the repo already has working equivalents.

## Areas likely to require adaptation

### Route / screen mounting
The repo may already have:
- a run details screen,
- a page review modal,
- a recommendation board,
- a results table.

The workbench can be:
- a new route,
- a split-view mode,
- a replacement screen,
- an embedded review mode.

### Domain terminology
The repo may use different names for:
- source blocks
- recommendations
- pages
- targets
- assignments
- unresolved items
- validation blockers

Prefer existing terminology if it is clear and stable.

### Data shape mismatch
If the current recommendation payload is field-centric, do not immediately rewrite the backend contract.

Instead:
- create UI adapters,
- build section grouping in selectors/view-models,
- progressively improve the underlying contract later.

### Existing design system
Use the existing design system where possible:
- split panes
- cards
- drawers
- tables
- command palette
- keyboard shortcut primitives
- iconography

### State management
If the repo already uses:
- Zustand
- Redux
- React Query
- local view state patterns
- server actions
- route loaders

align to that pattern.

## Suggested adaptation strategy

1. keep back-end/domain logic stable at first
2. create a page-level workbench shell
3. derive section-based rendering from current data
4. wire actions into current handlers
5. only then evaluate deeper data contract changes

## Safe fallback strategy

If a full workbench cannot be built immediately:

- add a workbench mode that handles the top 80% of review
- keep old detailed surfaces available behind “advanced details”
- migrate operators gradually

## Avoid these implementation mistakes

- building a brand-new parallel migration system
- inventing a giant frontend-only data model that drifts from the backend
- overcommitting to drag-and-drop before basic section review works
- shipping a polished shell with weak decision wiring
