# 10 — Risks and Anti-Patterns

## High-risk product mistakes

### 1. Rebuilding the old card UI in columns
If the center pane simply becomes a prettier stack of recommendation cards, the core usability problem remains.

### 2. Turning the workbench into a mini page builder
The goal is structured migration review, not visual design editing.

### 3. Drag-and-drop everywhere
Drag-and-drop is attractive but often slower and more error-prone than click + keyboard for review workflows.

### 4. Surfacing too much technical detail by default
Operators need labels, sections, source evidence, status, and actions. Raw field keys and full contract detail should be secondary.

### 5. Hiding unresolved items too aggressively
Unmatched or unresolved items need a visible safe home, not silent disappearance.

### 6. Forcing backend rewrites before UI progress
A thin adapter is often enough to unlock the first useful version.

## High-risk implementation mistakes

### 1. Creating a giant parallel frontend data model
Keep the view-model layer thin and derived where possible.

### 2. Ignoring current repo conventions
This should feel native to DBVC, not pasted in from a separate product.

### 3. Overbuilding phase 1
Phase 1 should solve the main review pain without waiting for every advanced interaction.

### 4. Shipping shell before wiring critical decisions
The workbench is only useful if accept/reassign/unresolved actions are real.

## Practical quality bar

A successful first version should make an operator say:

- “I can understand this page quickly.”
- “I can approve most sections without hunting through fields.”
- “I can fix ambiguous assignments without fighting the UI.”
- “I can clearly see what still needs attention.”

If those are not true, the workbench has not yet solved the core problem.
