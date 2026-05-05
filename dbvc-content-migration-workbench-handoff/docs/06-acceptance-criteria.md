# 06 — Acceptance Criteria

These criteria are written for practical implementation review.

## Core page review criteria

- An operator can open a crawled page in a dedicated page-level workbench.
- The workbench clearly displays:
  - source evidence,
  - target object/page structure,
  - decision details,
  - unmatched/warning surfaces.
- The operator does not need to use a card-per-field feed as the main review model.

## Section review criteria

- Sections are visible as structured panels in the center pane.
- Each section shows readiness/warning state.
- Each section supports quick approval of safe recommendations.
- Repeater sections are reviewable without collapsing into raw field controls.

## Decision criteria

- A source block can be selected and traced to its recommended target.
- A target slot can be selected and traced back to the assigned source.
- The operator can:
  - accept,
  - reassign,
  - remove assignment,
  - mark unresolved.
- Reassignment uses a searchable field picker or equivalent fast interaction.

## Safety criteria

- Unmatched or unresolved items remain visible.
- Contract mismatch or blocked states are surfaced clearly.
- Conflicts are visible and resolvable.
- Manual overrides are distinguishable from auto recommendations.

## Efficiency criteria

- Easy pages can be reviewed mostly at the section level.
- Ambiguous pages do not require hunting through long forms.
- The interface supports keyboard-first review where feasible.

## Auditability criteria

- Acceptance, reassignment, unresolved actions, and overrides are recorded or surface-compatible with existing audit patterns.
- The page readiness state reflects the current review state.

## Anti-pattern rejection criteria

The implementation should fail review if it mainly becomes:
- a raw ACF form dump,
- a full visual page builder,
- a drag-and-drop-only system,
- a simple reskin of the current field-card feed without structural improvement.
