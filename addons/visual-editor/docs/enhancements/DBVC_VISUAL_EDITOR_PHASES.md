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
- Active implementation focus has shifted to Bricks native ACF query loops so the addon can classify and edit fields rendered through native repeater, relationship, and post-object loop types before returning to the paused grouped-save smoke work.
- Native Bricks ACF repeater loops are now materially hardened:
  - full native root selectors are used for row reads and writes
  - duplicate child keys can be rebound against the real container definition
  - nested group descendants inside native repeater rows now inherit the repeater context correctly
  - row-four false negatives caused by fake concrete post owners from bare numeric loop indices are now fixed
  - native loop provenance now travels through descriptor source/path/mutation metadata so panel summaries and save-contract details can distinguish repeater vs relationship vs post-object origins
- Recent implemented state before the hold:
  - live FrameworkFLO browser probing confirmed related-owner VE markers are present on previously failing elements such as `.brxe-ozyswq` and `.brxe-zecvno`
  - nested ACF group ancestry now participates in descriptor `source` / `path` metadata
  - repeater/flexible row reads and writes now traverse nested group ancestry before touching the leaf field
  - live `source_group` / `sync_group` hashing now includes nested group ancestry plus leaf selector identity so same-named grouped descendants do not cross-update after save
  - direct grouped ACF fields now preserve parent group ancestry in descriptor paths and prefer selector-based writes over ambiguous leaf-name writes
  - the running code-map and consolidation reference for these native ACF loop fixes now lives in `docs/knowledge/NATIVE_ACF_LOOP_HARDENING_MAP.md`
- Resume point after the current panel UX slice:
- current active slice:
  - finish native ACF relationship and post-object loop smoke coverage on real pages
  - keep native loop provenance first-class in descriptor/source/save-contract summaries
  - widen from the hardened native repeater slice into native relationship/post-object loop descendants before returning to the paused grouped-save smoke work
- paused slice to return to after the native loop work:
  - live-save smoke test nested grouped descendants inside supported repeater/flexible/related-owner paths
  - widen non-post/shared flexible descendants only after those grouped save paths are proven stable
