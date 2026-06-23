# 06 · UI and Interaction Model

The current problem with card-per-item design is that it does not scale.

For this feature, the best interface is a **workbench table with grouped review**, not individual cards.

## Recommended UI model

## Screen 1 — Export

A clean export panel with:

- checklist of portable domains
- info summary per domain
- toggle: include raw option dumps
- toggle: include advanced asset-dependent domains
- export button
- result download card

## Screen 2 — Import / Review Workbench

This is the core.

### Layout

```text
+----------------------------------------------------------------------------------+
| Header: Package Summary / Source Site / Bricks Version / Drift Counts           |
+----------------------------------------------------------------------------------+
| Left Rail              | Main Review Grid                         | Right Drawer |
|------------------------|------------------------------------------|-------------|
| Domain filters         | Tabular drift rows                       | Row details  |
| - All                  | [status][type][name][match][summary]     | Diff paths   |
| - Settings             | [decision dropdown][warnings][preview]   | Raw source   |
| - Colors               |                                          | Raw target   |
| - Classes              | Bulk toolbar                             | Dependencies |
| - Variables            | - Filter by status                       | Apply effect |
| - Components           | - Filter by warnings                     |              |
| - Theme Styles         | - Bulk decision                          |              |
| - Breakpoints          | - Search                                 |              |
+----------------------------------------------------------------------------------+
| Sticky Approval Bar: rows selected / warnings / backup notice / apply button    |
+----------------------------------------------------------------------------------+
```

## Why a table workbench is better than cards

Because the user needs to answer repeated questions quickly:

- What is new?
- What changed?
- What is risky?
- What should I bulk replace?
- Which rows need manual review?

A compact table is better for this than stacked cards.

## Table columns recommendation

- checkbox
- status
- domain
- object type
- display name
- source match keys
- target match keys
- drift summary
- warnings badge
- decision dropdown
- preview button

## Domain summary chips

At top, show counts like:

- 4 domains with drift
- 27 changed
- 11 new
- 2 dependency warnings
- 1 high-risk breakpoint change

## Domain tab behavior

Each domain should have:

- summary counts
- domain-level bulk actions
- domain-level warning banner
- optional “apply same decision to all value-changed rows”

## Row detail drawer

When a row is clicked, open a right-side drawer with:

- source object preview
- target object preview
- path-level diff summary
- extracted dependencies
- recommended action
- notes if id/name mismatch exists

## Decision model

### Per-row decision dropdown
MVP choices:

- Add from package
- Replace target with package
- Keep current site value
- Skip for now

### Per-domain bulk controls
- Apply “Add” to all new rows
- Apply “Replace” to all changed rows
- Apply “Keep current” to all conflicts
- Clear decisions

### Global bulk controls
- Suggested decisions
- Conservative decisions
- Replace all safe changes
- Skip all warnings

## Approval model

Do not apply until:

- every actionable row has a decision
- high-risk warnings are acknowledged
- backup target is confirmed

## Backup reminder area

Sticky bar should always say something like:

- “A snapshot of affected Bricks options will be created before apply.”

## Screen 3 — Apply summary modal

Before execution, show:

- domains affected
- rows by decision count
- high-risk items
- backup name / timestamp
- revert note

## Screen 4 — Backups / History

Show prior jobs in a compact table:

- job id
- package source
- date
- domains touched
- changed rows
- backup created
- rollback available
- status
- actions: inspect / restore

## UX principles

### 1) Hide raw option complexity until needed
Default users should not need to think in `wp_options`.

### 2) Favor grouping
Users think in domains and statuses, not individual JSON blobs.

### 3) Show “what will happen”
Every decision should have an understandable consequence label.

### 4) Keep cards only where they help
Cards are fine for:
- package summary
- export result
- backup history entry
- apply confirmation

The central review surface should stay table-first.
