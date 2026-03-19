# Migration Mapper V2 UI Architecture

## Purpose

This document defines the intended UI and React architecture for `Migration Mapper V2`.

It should be read alongside:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOC_INDEX.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_OVERVIEW.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_FILE_PLAN.md`
- `docs/UI-ARCHITECTURE.md`

The goal is to make the V2 operational experience feel simple by default and powerful on demand.

## Experience North Star

V2 should feel like a calm, modern, run-based operational workspace.

Desired feel:

- calm at first glance
- dense only when expanded
- summary first
- evidence second
- controls third

Reference quality bar:

- HubSpot import flow
- Airtable review grid
- modern SaaS operator dashboards

This is inspiration, not a requirement to copy those products literally.

## Core Mental Model

The primary mental model is:

`start run -> monitor progress -> review exceptions -> inspect readiness -> approve dry-run/import`

The run is the top-level organizing object for the UI.

That means:

- the operator should think in terms of runs, not isolated artifacts
- the default UI should answer `what is happening`, `what needs attention`, and `what is ready`
- advanced evidence and debugging should stay available without becoming the default experience

## Primary UX Principles

1. Review by exception.
   High-confidence items should flow through quietly. The UI should emphasize flagged work.

2. Progressive disclosure.
   Default screens should show summaries, counts, status, readiness, and next actions. Deep evidence appears only when requested.

3. Preserve context while drilling down.
   Opening a URL, evidence trace, or mapping preview should not eject the user from the main workspace.

4. Summary first, evidence second, controls third.
   Each surface should present outcomes before raw detail and place mutation controls after evidence.

5. Minimal shell, rich inspection surfaces.
   The global frame should stay light. Complexity should live inside drawers, inspectors, tables, and focused panels.

6. Calm at first glance, dense only when expanded.
   Avoid overwhelming first-load screens with every artifact, control, or debug field.

## Default User-Facing Workflow

1. Start or select a run
2. Monitor run progress
3. Review flagged exceptions
4. Inspect readiness and package status
5. Approve dry-run or import

This should be the visible workflow even if the internal pipeline remains deeper.

## Default Screen Priorities

Default screens should emphasize:

- status
- summaries
- exceptions
- readiness
- next actions

Advanced detail should appear on demand through:

- row expansion
- drawers
- tabs
- accordions
- toggleable inspector panels
- evidence viewers
- mapping previews
- audit and debug reveals

## Primary Screens

### 1. Runs Workspace

Purpose:
- entry point for recent and active runs
- quick understanding of run status and next actions

Should emphasize:
- run status
- progress
- exception counts
- readiness state
- latest activity

### 2. Run Overview Workspace

Purpose:
- default landing view for a selected run
- high-level operational summary without raw technical overload

Should emphasize:
- current stage
- pipeline progress
- blockers
- auto-resolved versus exception counts
- readiness summary

### 3. Exceptions Workspace

Purpose:
- focused queue for items needing human attention

Should emphasize:
- only flagged URLs by default
- confidence and severity chips
- target object preview
- quick next actions

### 4. Readiness Workspace

Purpose:
- package and preflight readiness review before dry-run or import

Should emphasize:
- blocking issues
- warning counts
- package completeness
- schema freshness
- dry-run readiness

### 5. Package Workspace

Purpose:
- final package summary and downstream handoff visibility

Should emphasize:
- what will be imported
- where it will land
- what still needs attention
- package build history

### 6. URL Inspector Drawer

Purpose:
- preserve workspace context while revealing evidence and controls for a single URL

Should contain:
- summary header
- evidence tabs
- mapping preview
- review actions
- rerun actions
- audit/debug reveals

## Layout Regions

### App Shell

Main component:
- `ContentCollectorV2AppShell`

Responsibilities:
- stable page shell
- route or view coordination
- top-level run selection context
- error boundary and notification region

### Run Context Header

Responsibilities:
- selected run identity
- run state badge
- readiness badge
- primary next action

### Summary Strip

Responsibilities:
- high-signal metrics only
- progress
- exceptions
- readiness
- package state

### Workspace Toolbar

Responsibilities:
- search
- filters
- chips and badges
- bulk actions when appropriate
- sticky positioning where tables are long

### Primary Workspace Region

Responsibilities:
- run list, overview, exception table, readiness table, or package surface
- should remain the anchor context for the user

### Inspector Drawer

Responsibilities:
- on-demand deep inspection
- evidence and previews
- targeted controls
- preserves place in the primary workspace

### Modal Layer

Responsibilities:
- destructive confirms
- focused approvals
- narrow decision points only

Rule:
- no modal-on-modal flows

## Preferred Interaction Patterns

### Tables

Preferred behavior:
- dense, legible, summary-first tables
- sticky toolbar and sticky header where useful
- row-level status chips
- row expansion for quick secondary detail
- open drawer for deeper inspection

Avoid:
- card-heavy replacements for data-rich tables
- giant always-expanded rows

### Drawers and Inspector Panels

Preferred behavior:
- keep the main workspace visible in the background
- use tabs and accordions inside the drawer
- place summary first, evidence second, actions after

Use drawers for:
- URL detail
- evidence inspection
- mapping preview
- rerun controls

### Tabs

Use tabs for:
- workspace-level mode changes
- inspector content groupings
- evidence categories

Do not use tabs to hide critical blockers that should be visible at first glance.

### Accordions

Use accordions for:
- secondary evidence groups
- verbose debug blocks
- audit history
- raw artifacts

### Chips and Badges

Use chips and badges to show:

- run status
- exception severity
- confidence level
- readiness state
- create versus update versus blocked

### Sticky Toolbars

Use sticky toolbars for:

- filters
- search
- selection context
- bulk actions
- view mode toggles

The toolbar should remain compact and not become a second header.

### Modals

Use modals only for:

- destructive confirms
- dry-run or import approvals
- narrow focused tasks that should interrupt flow briefly

Do not use modals for:

- deep inspection
- long review workflows
- nested evidence browsing

## React Architecture Direction

V2 should use a dedicated React app for its operational workspace.

The Add-ons settings surface should remain server-rendered PHP.

The complex operational surfaces should be React-driven:

- runs workspace
- run overview
- exception review
- readiness and package surfaces
- inspector drawers
- targeted approval flows

### Existing DBVC pattern to follow

Use the same broad direction already established by:

- `admin/class-admin-app.php`
- `src/admin-app/index.js`
- `docs/UI-ARCHITECTURE.md`

That means:

- WordPress React stack
- REST-first data access
- localized root and nonce
- modular component boundaries

Do not introduce a second unrelated UI framework.

## Route and View Contract

The V2 UI should be organized around stable run-centric routes or view keys.

Recommended primary route set:

- `runs`
- `runs/:runId/overview`
- `runs/:runId/exceptions`
- `runs/:runId/readiness`
- `runs/:runId/package`

Recommended drill-in state:

- selected URL or record should open in a drawer or inspector without replacing the main workspace
- drawer state should be representable through query state when practical

Recommended route parameter names:

- `runId`

Recommended query or view-state keys:

- `pageId`
- `panel`
- `panelTab`
- `filter`
- `status`
- `q`
- `sort`
- `packageId`

Recommended query or view-state examples:

- `pageId=:pageId`
- `panel=summary|evidence|mapping|media|audit|qa`
- `panelTab=summary|source|context|classification|mapping|media|qa|audit`
- `filter=all|blocked|low-confidence|stale|policy|overridden`
- `status=active|needs-review|ready|blocked|completed`

Naming rules:

- use `pageId`, not raw `url`, for inspector state because the pipeline contracts key by `page_id`
- use `q` for free-text search
- use `panelTab`, not the generic `tab`, for nested inspector content
- use `filter` for operator queue state and `status` for object or run lifecycle state

Route rule:

- the main workspace should remain stable while inspectors, row expansion, tabs, and drawers reveal advanced detail

## UI Data Ownership by Surface

### Runs Workspace

Owns:

- run list query
- run filters
- run sort
- selected run action state

Consumes:

- run summaries
- readiness counts
- exception counts

### Run Overview Workspace

Owns:

- overview-local filter and panel state

Consumes:

- run summary
- stage progress
- blocker summary
- recent activity

### Exceptions Workspace

Owns:

- exception filters
- row expansion state
- selected URL drawer state
- bulk review state

Consumes:

- exception queue
- recommendation summaries
- target resolution previews

### Readiness Workspace

Owns:

- readiness panel toggles
- preflight detail expansion state

Consumes:

- package readiness summary
- blocking issues
- warning groups
- schema freshness

### Package Workspace

Owns:

- package history selection
- package detail panel state
- package workflow-state visibility
- package import-history visibility

Consumes:

- package summary
- manifest summary
- QA summary
- persisted workflow state for build, dry-run, preflight, and execute
- recent package-linked import execution history
- downstream dry-run or import state

## Build and Bundle Guidance

V2 should reuse the existing repo build system:

- `package.json`
- `@wordpress/scripts`
- `npm run start`
- `npm run build`

Preferred direction:

- add a dedicated V2 admin app entrypoint
- generate a separate V2 bundle and asset file
- use the root build entry file `content-collector-v2-app.js`
- have that entry import:
  - `addons/content-migration/v2/admin-app/index.js`
  - `addons/content-migration/v2/admin-app/style.css`
- use script handle `dbvc-content-collector-v2-app`
- use localized bootstrap object `DBVC_CC_V2_APP`
- keep the Add-ons settings page server-rendered and only mount the V2 React app for operational surfaces

Do not introduce a second JS toolchain unless the current toolchain proves inadequate.

## Automation and QA Hooks

The V2 UI should expose stable selectors for browser QA.

Preferred direction:

- use stable component and surface identifiers
- keep route or view state externally observable
- avoid selectors that depend on fragile visual copy alone

Playwright readiness expectations:

- primary workspaces should have stable roots
- drawers and modals should have stable roots
- tables, toolbars, badges, and primary actions should be targetable without brittle DOM assumptions

## State and View Ownership

### Top-level state

Top-level keys should include:

- selected run
- current workspace route or view
- global notifications
- feature and capability gates

### Workspace state

Each workspace should own only its local concerns:

- filter state
- sort state
- row expansion state
- selection state
- drawer open state

### Shared domain state

Shared cross-surface state should include:

- run summary
- exception counts
- readiness status
- package state
- active URL detail snapshot

### Sync rules

- successful mutations should update all affected surfaces in the same UI cycle or through a shared invalidation tick
- no surface should require manual refresh after a successful mutation
- caches should invalidate by `runId` and scope such as `exceptions`, `readiness`, `package`, or `url`

## Modular Component Strategy

Prefer composition over oversized components.

Separate these concerns where practical:

- workspace layout
- table behavior
- drawer and inspector behavior
- modal flows
- mutation logic
- REST data hooks
- pure presentation components

## Recommended V2 UI File Structure

```text
addons/content-migration/v2/
  admin/
    dbvc-cc-v2-admin-menu-service.php
    dbvc-cc-v2-configure-addon-settings.php
    dbvc-cc-v2-app-loader.php
  admin-app/
    index.js
    style.css
    app/
      ContentCollectorV2App.tsx
      ContentCollectorV2AppShell.tsx
      routes/
      providers/
      layout/
    workspaces/
      runs/
      run-overview/
      exceptions/
      readiness/
      package/
    components/
      badges/
      chips/
      tables/
      toolbars/
      drawers/
      inspectors/
      panels/
      tabs/
      accordions/
      modals/
    hooks/
    api/
    state/
    utils/
    types/
    styles/
```

### Ownership guidance

- `app/` owns shell, routing, and top-level providers
- `workspaces/` owns screen-level composition
- `components/` owns reusable UI primitives and composed surface pieces
- `hooks/` owns stateful UI behavior and REST integration hooks
- `api/` owns transport functions and request shaping
- `state/` owns shared stores or reducers
- `utils/` owns pure helpers

## Table, Drawer, Toolbar, Inspector, and Modal Expectations

### Reusable Data Tables

Every major table should support:

- loading
- empty
- error
- stale
- busy
- filters
- search
- sort
- row badges
- row actions
- optional row expansion

### Drawers

Every major drawer should support:

- summary header
- tabs or accordions for evidence
- quick actions
- keyboard close behavior
- stable focus return to invoking row

### Toolbars

Every major toolbar should support:

- filter chips
- search
- quick status toggles
- next-action controls

Toolbars should remain stable as content beneath them changes.

### Inspectors

Inspectors should reveal:

- source evidence
- mapping preview
- recommendation rationale
- target schema hints
- journey and audit context

Inspectors should not be the default first-load surface.

### Modals

Modals should be short, explicit, and action-focused.

Use them for:

- approve dry-run
- approve import
- confirm rerun
- confirm destructive reset or rebuild

## Anti-Patterns and Constraints

Avoid:

- giant settings pages
- raw technical pipeline screens as the default UI
- oversized card-heavy layouts
- too many always-visible controls
- modal-on-modal flows
- exposing all metadata by default
- deeply nested stateful components without clear ownership
- single large React files that combine layout, business logic, table logic, drawer behavior, and modal flows
- implementation that ignores existing DBVC React and architecture patterns

## Main UI Architecture Principle

V2 should operate like a calm run workspace:

`status and next actions by default, rich evidence and controls only when the operator asks for them`
