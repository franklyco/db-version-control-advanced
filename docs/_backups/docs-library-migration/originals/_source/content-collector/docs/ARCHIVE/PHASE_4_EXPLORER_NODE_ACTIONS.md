# Phase 4: Explorer Node Actions and Workflow Modules

## Why this phase

Explorer already visualizes crawl structure, preview content, and supports AI reruns/exports. The next step is workflow speed: reduce clicks and give users node-level actions that mirror common tree and mindmap software behavior while keeping migration quality controls visible.

## Phase Goal

Add a node-centric action layer and inspector modules so users can move from discovery to export decisions directly from the selected node context.

## Scope (v1)

- Node Action Center in inspector.
- Branch-focused interaction controls (focus, isolate, expand, collapse).
- Quick source/canonical open actions.
- Content Signals module (counts and structural indicators).
- Migration Readiness module (score/checks/status).

## Out of Scope (v1)

- Saved personal view presets.
- Advanced search/filter expression builder.
- Drag-and-drop node grouping.
- Bulk node tagging and assignment workflows.

## Concrete Workstreams and Checklist

### 4.1 Node Action Center (Immediate)
- [x] Add inspector action group for selected node actions.
- [x] Add graph controls: `Focus Node`, `Fit Branch`, `Isolate Branch`, `Clear Isolation`.
- [x] Add structure controls: `Expand Branch (N levels)` and `Toggle Branch Collapse`.
- [x] Add quick URL actions: `Open Source`, `Open Canonical`.
- [x] Add status messaging for branch expansion/isolation actions.
- [x] Keep existing toolbar controls compatible (no behavior regressions).

### 4.2 Inspector Workflow Modules (Immediate)
- [x] Extend explorer content API response with `metrics` object.
- [x] Extend explorer content API response with `readiness` object.
- [x] Render Content Signals card in inspector.
- [x] Render Migration Readiness score/checklist card in inspector.
- [x] Ensure readiness reflects AI fallback mode and legal-review flags.

### 4.3 Explorer API Contract Updates
- [x] Update schema docs for new preview payload fields.
- [x] Add optional node action hints map in `/explorer/node`.

### 4.4 Quality and Safety
- [x] Preserve existing permissions (`manage_options`) and nonce usage.
- [x] Keep branch expansion bounded by request limits to avoid UI lockups.
- [x] Keep deterministic export path unchanged.
- [x] Add fixture coverage for metrics/readiness payload (next test slice).

## Additional Subtasks to Queue Next

- [ ] Persist per-user Explorer action preferences (expand levels, last layout/filter).
- [x] Add quick-search across label/path/source URL with highlight.
- [x] Add node keyboard shortcuts (`F` focus, `I` isolate, `E` expand, `R` rerun AI, `Shift+R` branch rerun, `B` toggle branch).
- [x] Add side-by-side diff module for raw vs sanitized snippet comparison.
- [x] Add node audit trail module (crawl/AI/export events scoped to selected path).
- [x] Add one-click “Rerun AI for branch” with bounded queueing and progress rollup.

## Definition of Done (for this phase rollout)

- Node click exposes actionable controls that reduce navigation overhead.
- Users can isolate and inspect a subtree quickly, then return to full context.
- Inspector clearly communicates readiness and quality signals before export.
- No regressions in existing Explorer tree rendering, AI rerun, or export flow.
