# DBVC Visual Editor Open-Items Context Archive - 2026-07-07

Archive status: historical context only. Do not use this file as current implementation guidance.

Current guidance lives in:
- `../enhancements/DBVC_VISUAL_EDITOR_PHASES.md`
- `../enhancements/DBVC_VISUAL_EDITOR_NATIVE_LOOP_EXPANSION_PLAN.md`
- `../enhancements/DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md`
- `../enhancements/DBVC_VISUAL_EDITOR_ADVANCED_IMPLEMENTATION_GUIDE.md`
- `../enhancements/DBVC_VISUAL_EDITOR_PERFORMANCE_UPGRADE_GUIDE.md`

## Why This Was Archived

The Visual Editor implementation guides accumulated a running thread-style backlog while source support was being widened. Many notes were correct at the time but became stale or lower priority after later implementation and user testing confirmed:
- query-collection badges and editable connected-item collection flows
- exact shared-option fallback editing plus explicit current-field seed actions
- empty query-loop collection markers
- direct/repeater/flexible gallery editing, append/remove/reorder, and live DOM updates
- missing/conditional media anchors for empty image/gallery sources
- archive entry-point support for direct option-backed fields, queried-term fields, and concrete loop owners
- native ACF repeater/flexible/group hardening across several Bricks query-loop shapes
- Toolbar 2.0 shell, Review Fields grouping, Shared Globals launcher, and Visual Editor settings/exclusion controls

The active `DBVC_VISUAL_EDITOR_PHASES.md` now contains a production-ranked P0-P5 backlog. The notes below preserve older implementation context so future agents can recover details without treating them as the active execution order.

## Superseded Running Hold Context

The older active hold context said the next paused advanced-data follow-up was nested ACF group and deeper flexible/repeater descendant save verification, not marker discovery.

The active focus then shifted to Bricks native ACF query loops so the addon could classify and edit fields rendered through native repeater, relationship, post-object, and taxonomy loop types before returning to grouped-save smoke work.

Native Bricks ACF repeater loops were materially hardened:
- full native root selectors are used for row reads and writes
- duplicate child keys can be rebound against the real container definition
- nested group descendants inside native repeater rows inherit the repeater context correctly
- row-four false negatives caused by fake concrete post owners from bare numeric loop indices were fixed
- nested repeater-in-repeater descendants canonicalize to the outer repeater root and carry explicit nested repeater row segments instead of flattening to the innermost repeater only
- native flexible descendants canonicalize against the actual row `acf_fc_layout` and layout key before subfield matching, fixing duplicate Bricks layout aliases such as `acf_flexible_layouts_dynamic_section_image` rendered inside real `standard_section` rows
- native loop provenance travels through descriptor source/path/mutation metadata so panel summaries and save-contract details distinguish repeater, relationship, post-object, and taxonomy origins
- nested native-loop descendants also carry parent native loop ancestry so `relationship -> repeater`, `post_object -> repeater/flexible`, and similar nested native paths can be summarized and keyed explicitly
- descriptor contracts carry full native ancestor chains through loop export, source/path metadata, live source/sync grouping, panel summaries, and mutation detail

Recent implementation state captured at that time:
- FrameworkFLO probing confirmed related-owner Visual Editor markers on previously failing elements such as `.brxe-ozyswq` and `.brxe-zecvno`
- nested ACF group ancestry participates in descriptor `source` and `path` metadata
- repeater/flexible row reads and writes traverse nested group ancestry before touching the leaf field
- live `source_group` and `sync_group` hashing includes nested group ancestry plus leaf selector identity so same-named grouped descendants do not cross-update after save
- direct grouped ACF fields preserve parent group ancestry in descriptor paths and prefer selector-based writes over ambiguous leaf-name writes
- the running code-map and consolidation reference for native ACF loop fixes lives in `docs/knowledge/NATIVE_ACF_LOOP_HARDENING_MAP.md`

The older resume point after the panel UX slice was:
- start with native `relationship -> repeater` and `relationship -> flexible` descendants
- then widen to native `post_object -> repeater` and `post_object -> flexible` descendants
- keep native loop provenance first-class in descriptor/source/save-contract summaries, including parent native ancestry for nested loops
- keep native taxonomy nested descendants guarded to current taxonomy archive terms and concrete loop-owned term owners only, with canonical row/group ancestry and no row/layout lifecycle mutation
- use the native loop expansion plan before opening later mutation branches
- keep stable flexible row mutation widened across shared post/term/user/option owners for the safe flexible field set, including gallery descendants when Bricks renders a direct gallery collection
- keep direct gallery collections on ordered Media Library replacement for top-level, repeater-row, and flexible-row ACF gallery fields
- keep empty/conditional direct Bricks gallery collections on the missing-media parent-anchor path

User-side WIP/paused items then included:
- shared non-current post flexible descendants through `shared_flexible_layout`
- populated direct/repeater/flexible gallery collection replacement browser flow

Collection-editor status at that time:
- current-owner native ACF `relationship` query roots surfaced as `Edit Connected` container markers
- current-owner native ACF `post_object` query roots used the same connected-items container contract
- direct current-owner repeater-row and flexible-row `relationship` / `post_object` query roots used the same connected-items contract when row paths were stable
- mixed current-owner `repeater -> flexible` and `flexible -> repeater` collection roots carried explicit container ancestry
- grouped current-owner row-owned collection roots flowed through the same contract when intermediate group ancestry was canonical
- loop-owned related-post native ACF `relationship` / `post_object` query roots used the connected-items contract with related-owner acknowledgement
- reload-after-save reconciliation was the intentional default for the collection-editor branch

Session-lifecycle hardening at that time:
- transient-backed Visual Editor sessions used a longer filterable TTL
- the frontend refreshed the active session on an interval plus focus/visibility return
- descriptor and save endpoints returned an explicit expired-session message when session state was gone
- background descriptor warmup used `IntersectionObserver`, a small root margin, descriptor cache reuse, and in-flight request reuse

Derived query-loop status at that time:
- Query Editor loops backed by one current-owner relationship/post_object field had a writable filtered-subset contract when final `post__in` exactly matched one source field
- Bricks native dynamic include/post__in controls participated when saved control evidence exposed ACF dynamic tags
- static/manual native ID lists and opaque final-ID lists stayed unsupported
- direct current-owner `get_field('field_name')` calls contributed source hints
- option/user/explicit-object reads were split into fallback provenance
- exact ACF options fallback matches could use shared collection contracts with acknowledgement
- exact options fallback matches could expose current-page seed action when one current-owner relationship/post_object hint was proven and the current field was empty for that target branch
- locked fallback branches used read-only connected-items preview mode
- mixed/`any` derived post queries could use full collection contract only when source evidence existed and final ordered query IDs exactly equaled the full source field value
- nested-group relationship/post_object fields were included in current-owner and exact shared-option matching when flattened selectors and grouped metadata were proven
- empty derived query loops used synthetic descriptor registration from captured query-vars plus hidden marker injection near Bricks loop-start evidence
- post-owned linked-term collections were WIP/live QA as a separate branch from ACF connected posts
- Bricks native taxonomy/terms elements such as `post-taxonomy` were planned as a guarded branch using the existing `post_terms_collection` save contract

Deferred collection branches at that time:
- custom Query Editor fallback branch writes beyond exact shared-option target-CPT/full-field matches and the narrow current-page seed action
- recent-post fallbacks
- empty shared-option fallback branches
- ambiguous fallback branch selection
- shared connected-item collections
- loop-owned non-post connected-item collections
- taxonomy collection mutation
- shared term collection mutation
- true row insert/remove/reorder branches

Paused follow-up slice:
- live-save smoke test nested grouped descendants inside supported repeater/flexible/related-owner paths
- widen remaining collection-safe structured paths only after grouped save paths are proven stable
- defer broader relationship collection editing and repeater/flexible row insert/remove/reorder until native owner-loop and grouped-save branches are stable

## Current Replacement

Use the P0-P5 backlog in `../enhancements/DBVC_VISUAL_EDITOR_PHASES.md`.

Production priority changed from “continue widening every source family” to:
1. close QA/confidence gaps that affect existing client-site usage
2. harden common client-site Bricks/ACF source shapes
3. improve UX for large real pages
4. delay high-risk collection and row lifecycle mutation until contracts, fixtures, and rollback evidence are stronger
