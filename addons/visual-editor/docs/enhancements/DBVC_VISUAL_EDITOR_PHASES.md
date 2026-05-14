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
- Active implementation focus has shifted to Bricks native ACF query loops so the addon can classify and edit fields rendered through native repeater, relationship, post-object, and taxonomy loop types before returning to the paused grouped-save smoke work.
- Native Bricks ACF repeater loops are now materially hardened:
  - full native root selectors are used for row reads and writes
  - duplicate child keys can be rebound against the real container definition
  - nested group descendants inside native repeater rows now inherit the repeater context correctly
  - row-four false negatives caused by fake concrete post owners from bare numeric loop indices are now fixed
  - nested repeater-in-repeater descendants now canonicalize to the outer repeater root and carry explicit nested repeater row segments instead of flattening to the innermost repeater only
  - native flexible descendants now canonicalize against the actual row `acf_fc_layout` and layout key before subfield matching, which fixes duplicate Bricks layout aliases like `acf_flexible_layouts_dynamic_section_image` rendering inside real `standard_section` rows
  - native loop provenance now travels through descriptor source/path/mutation metadata so panel summaries and save-contract details can distinguish repeater vs relationship vs post-object vs taxonomy origins
  - nested native-loop descendants now also carry parent native loop ancestry so `relationship -> repeater`, `post_object -> repeater/flexible`, and similar nested native paths can be summarized and keyed explicitly instead of only showing the innermost loop
  - the descriptor contract now carries full native ancestor chains, not only one `parent_native_query`, through loop export, source/path metadata, live source/sync grouping, panel summaries, and mutation detail
- Recent implemented state before the hold:
  - live FrameworkFLO browser probing confirmed related-owner VE markers are present on previously failing elements such as `.brxe-ozyswq` and `.brxe-zecvno`
  - nested ACF group ancestry now participates in descriptor `source` / `path` metadata
  - repeater/flexible row reads and writes now traverse nested group ancestry before touching the leaf field
  - live `source_group` / `sync_group` hashing now includes nested group ancestry plus leaf selector identity so same-named grouped descendants do not cross-update after save
  - direct grouped ACF fields now preserve parent group ancestry in descriptor paths and prefer selector-based writes over ambiguous leaf-name writes
  - the running code-map and consolidation reference for these native ACF loop fixes now lives in `docs/knowledge/NATIVE_ACF_LOOP_HARDENING_MAP.md`
- the ordered scenario matrix for the next native owner-loop, mixed-nesting, and later collection-mutation branches now lives in `docs/enhancements/DBVC_VISUAL_EDITOR_NATIVE_LOOP_EXPANSION_PLAN.md`
- the dedicated narrow current-owner connected-items roadmap now lives in `docs/enhancements/DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md`
- Resume point after the current panel UX slice:
  - current active slice:
  - start with native `relationship -> repeater` and `relationship -> flexible` descendants
  - then widen to native `post_object -> repeater` and `post_object -> flexible` descendants
  - keep native loop provenance first-class in descriptor/source/save-contract summaries, including parent native ancestry for nested loops
  - treat native taxonomy nested descendants as inspect-first until real site validation proves owner/path stability for writes
  - use the native loop expansion plan as the runtime ordering source of truth before opening later mutation branches
  - stable flexible row mutation is now widened across shared post/term/user/option owners for the existing safe flexible field set, including gallery descendants when Bricks renders a direct gallery collection
  - direct gallery collections now support ordered Media Library replacement for top-level, repeater-row, and flexible-row ACF gallery fields, with page reload after save so Bricks can rebuild gallery markup cleanly
  - current WIP/paused items on the user side:
    - shared non-current post flexible descendants through `shared_flexible_layout`
    - direct/repeater/flexible gallery collection replacement flow
  - current active collection-editor slice:
    - current-owner native ACF `relationship` query roots can now surface as `Edit Connected` container markers instead of only descendant field markers
    - current-owner native ACF `post_object` query roots can now use that same connected-items container contract
    - direct current-owner repeater-row and flexible-row `relationship` / `post_object` query roots now target that same connected-items contract in code when the active row path is stable
    - mixed current-owner `repeater -> flexible` and `flexible -> repeater` `relationship` / `post_object` query roots now carry explicit container ancestry in code so the connected-items editor can traverse canonical nested row paths instead of only direct row roots
    - grouped current-owner row-owned `relationship` / `post_object` query roots now also flow through that same contract when the native query path can prove the intermediate group ancestry canonically
    - loop-owned related-post native ACF `relationship` / `post_object` query roots now also flow through the connected-items contract in code, with related-owner acknowledgement and collection-specific mutation contracts instead of falling back to generic loop-owned field messaging
    - reload-after-save reconciliation remains the intentional default for the whole collection-editor branch
- session-lifecycle hardening:
  - transient-backed VE sessions now default to a longer filterable TTL instead of the original short idle window
  - the frontend now refreshes the active session on an interval plus focus/visibility return so an open unattended page does not silently lose descriptor access as quickly
  - descriptor and save endpoints now return an explicit “session expired, refresh the page” message instead of a generic missing-descriptor failure when the session is gone
- runtime UX optimization after the current session-lifecycle hardening:
  - keep the current lightweight public-map bootstrap and active-marker dwell prefetch model
  - bounded viewport-aware descriptor warmup for nearby visible uncached markers is now implemented
  - it is driven by `IntersectionObserver` plus a small root margin, not by eager full-page hydration
  - it reuses the existing descriptor cache and in-flight request map so background warmup does not diverge from explicit field-open behavior
  - active field opens, saves, reload-after-save flows, and Media Library interactions remain higher priority than background warmup
- deferred within the collection-editor branch:
  - shared connected-item collections
  - loop-owned non-post connected-item collections
  - taxonomy collection mutation
  - true row insert/remove/reorder branches
- paused slice to return to after the native loop work:
  - live-save smoke test nested grouped descendants inside supported repeater/flexible/related-owner paths
  - widen any remaining collection-safe structured paths only after those grouped save paths are proven stable
  - defer broader relationship collection editing and repeater/flexible row insert-remove-reorder until after the native owner-loop and grouped-save branches are stable
