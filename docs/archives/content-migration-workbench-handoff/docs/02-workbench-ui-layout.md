# 02 — Workbench UI Layout

## Desktop layout

Use a pinned top bar, three primary panes, and a bottom dock.

```text
┌───────────────────────────────────────────────────────────────────────────────────────────────┐
│ Top App Bar                                                                                  │
│ [Run] [Page Nav] [URL] [Target Object] [Status] [Search] [Save] [Next]                      │
├───────────────────────┬───────────────────────────────────────┬───────────────────────────────┤
│ Left Pane             │ Center Pane                           │ Right Pane                    │
│ Source Evidence       │ Target Page Workbench                │ Inspector / Decision Panel    │
├───────────────────────────────────────────────────────────────────────────────────────────────┤
│ Bottom Dock: Unmatched / Warnings / Conflicts / Activity / Batch Actions / Shortcuts        │
└───────────────────────────────────────────────────────────────────────────────────────────────┘
```

## Pane ratios

Recommended default proportions:

- left pane: 24–28%
- center pane: 46–52%
- right pane: 24–28%

Allow right pane collapse.
Allow bottom dock expand/collapse.
Allow center pane to dominate when previewing sections.

## Top app bar

### Required contents
- run selector
- page counter / previous / next
- current source URL or page label
- target object selector
- page status pills
- save
- save + next
- search
- more actions menu

### Recommended actions
- re-run page inference
- re-run section detection
- re-run recommendations
- reset manual overrides
- open original capture
- open QA summary
- open package readiness

## Left pane — Source Evidence

### Sections
- search/filter bar
- page outline tree
- extracted source block list
- optional section clustering
- unmatched source tray

### Design
Use compact, readable block cards with:

- source type icon
- source preview
- inferred section chip
- confidence chip
- mapped/unmatched/conflict status
- heading path or source location hint

### Behavior
Selecting a source block:
- highlights suggested destination in center pane
- updates inspector
- scrolls target pane into view

## Center pane — Target Page Workbench

### Design principle
Show the destination as a structured **wireframe of sections and slots**, not as raw ACF meta forms.

### Section examples
- Hero
- Intro
- Services
- Reviews
- FAQ
- CTA
- Contact
- Gallery
- Team
- Global meta, if applicable

### Section panel contents
- section header
- readiness score
- unresolved count
- warning state
- per-slot rows
- repeater rows when relevant
- actions menu

### Slot row contents
- human label
- current value preview
- status badge
- confidence indicator
- source evidence link
- quick actions

### Repeater view
Support:
- row stack view for readability
- table view for density

## Right pane — Inspector / Decision Panel

This panel is contextual based on selected item.

### Source-selected mode
Show:
- raw source
- normalized source
- extraction metadata
- current recommendation
- alternatives
- reason summary
- transform preview
- actions

### Target-selected mode
Show:
- target path
- contract info
- current assigned source
- alternative sources
- manual reassignment controls
- validation warnings
- override history

### Section-selected mode
Show:
- section summary
- status overview
- unresolved items
- batch actions
- rerun actions

## Bottom dock

### Tabs
- unmatched
- warnings
- conflicts
- activity
- batch actions
- keyboard shortcuts

### Purpose
Provide a safe place for unresolved or blocked items and keep diagnostic surfaces visible without overwhelming the main panes.

## Preview mode

A lightweight preview mode can be offered from the center pane, but it should remain structural and sanity-check oriented rather than becoming a visual builder.
