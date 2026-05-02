# DBVC Visual Editor Phases

## Phase 1
- activation
- Bricks instrumentation
- descriptor registry
- singular entity support
- post title + ACF text-like resolvers
- save pipeline
- audit hook point
- basic overlay

## Phase 2
- taxonomy and term support
- options/global scope with warnings
- query loop item support
- better unsupported/derived state handling
- side panel inspection mode
- more text-like field types
- non-current-owner badges for related/query-loop items
- inspect-only repeater/flexible/relationship-collection markers
- shared active hover/focus badge controller
- lazy session bootstrap with on-demand descriptor hydration

## Phase 3
- descriptor V2 owner/page/path/loop/mutation metadata
- durable Visual Editor change journal tables
- dedicated save-contract groundwork for loop-owned sources
- repeater scalar subfield editing
- flexible content scalar subfield editing
- image/media support
- structured repeater/flexible subfields
- draggable, closable session-persistent overlay panel UX
- revision restore UX
- grouped change queue / review mode
- runtime profiling and performance instrumentation
- optional materialized inventory cache only if profiling proves request-time classification is the bottleneck

## Phase 4
- relationship collection editing
- advanced query-loop owner coverage beyond the current safe related-post slice
- DBVC sync-awareness
- field lock policies
- approval workflows
- usage analytics
- exportable change sets / diffs

## Current Hold Context
- The next paused advanced-data follow-up is nested ACF group and deeper flexible/repeater descendant save verification, not marker discovery.
- Recent implemented state before the hold:
  - live FrameworkFLO browser probing confirmed related-owner VE markers are present on previously failing elements such as `.brxe-ozyswq` and `.brxe-zecvno`
  - nested ACF group ancestry now participates in descriptor `source` / `path` metadata
  - repeater/flexible row reads and writes now traverse nested group ancestry before touching the leaf field
  - live `source_group` / `sync_group` hashing now includes nested group ancestry plus leaf selector identity so same-named grouped descendants do not cross-update after save
- Resume point after the current panel UX slice:
  - live-save smoke test nested grouped descendants inside supported repeater/flexible/related-owner paths
  - widen non-post/shared flexible descendants only after those grouped save paths are proven stable
