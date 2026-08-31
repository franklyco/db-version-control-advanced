# DBVC Visual Editor Phases

## Scope: desktop only (D-058, 2026-08-23)

The Visual Editor and DBVC plugin are **desktop-only software**. Mobile/tablet/touch layouts, real-handset optimization, mobile-specific mockup or QA, and real assistive-technology (VoiceOver/JAWS/NVDA) sit-with-a-screen-reader QA are **permanent non-goals** — everywhere below where an older paragraph mentions "D-036 tables mobile work until reauthorized", "real AT residual", "real assistive-technology gate", or "responsive/mobile deferred", read those as **removed, not deferred**. D-036 is closed and superseded by D-058. See `docs/dropins/dbvc-visual-editor-brand-controls-guide/00-GOVERNING-DIRECTIVES.md` §0.

## Production Backlog And Release Program - 2026-08-14

This document is the canonical ranking for remaining Visual Editor work. It now carries two related queues:

- `P0`-`P5` are priorities for existing rendered-page editing paths and their regression/coverage backlog.
- `R0`-`R6` are independently releasable product phases for Media Manager, Brand Controls, and the frontend workspace.

The `R` program does not silently close or reorder `P0`-`P5`. Existing-path obligations remain cross-cutting regression gates whenever a release touches the same bootstrap, descriptor, resolver, mutation, journal, cache, asset, or frontend-shell code. Older thread-style notes were archived in `../archives/DBVC_VISUAL_EDITOR_OPEN_ITEMS_CONTEXT_2026_07_07.md` and should not be used as the execution order.

### Repository-reconciled program status

The R0 package under `docs/dropins/dbvc-visual-editor-brand-controls-guide/` was reconciled against the current DBVC and VerticalFramework implementations on branch `codex/visual-editor-linked-posts-plan` at clean, synchronized commit `5db4b40`. Future repository state remains authoritative.

| Release | Current status | Approved boundary / next crossing line |
|---|---|---|
| `R0` | Discovery complete; package reconciled | Current architecture, Vertical evidence, coverage, risks, and test limitations are documented. No production feature code or runtime data changed. |
| `R1` | In development; R1-A through R1-D implemented, R1-E in progress | Policy/catalog, bounded scan/snapshot, protected active-mode lifecycle/list/group routes, safe search/filter/sort/cursor projection, single-row current-state revalidation, the feature-gated shell, frontend lifecycle/query controller, server-driven laptop/desktop safe-results table, and lazy one-row field-status expansion are implemented. R1-E now covers focus continuity, native keyboard disclosure, reduced motion, named semantic regions/loading/live announcements, Chromium/Firefox/WebKit engine coverage, targeted lint, no-auto-scan proof, and synthetic 100/500/2,000-group snapshot/read/payload measurements; authenticated runtime, complete candidate-scan scale, real AT, real Safari, and aggregate lint completion remain. D-036 preserves the proven responsive baseline but tables additional mobile-friendly layouts, responsive cards/slide-overs, touch refinements, real-device optimization, and mobile-specific mockup/QA work across the remaining releases until explicitly reauthorized. Descriptor issuance, Media Library actions, and content mutation remain absent from R1. |
| `R2` | Planned; not implemented | Per-field remediation only after R1 is production-ready. A finding must be exchanged for a fresh descriptor and pass an expected-empty precondition before the existing resolver/mutation/journal/cache path runs. Initial R2 excludes `Save Row` and all cross-entity bulk mutation. |
| `R3` | Not started | Provider-agnostic control registry that preserves Shared Globals compatibility and creates no new write authority. |
| `R4` | Not started | Searchable, categorized Global & Brand Control Center over the accepted registry/read model. |
| `R5.1`-`R5.4` | Not started | Option-owned field-family releases, each independently proven against current owner, descriptor, stale, journal, and cache contracts. |
| `R6` | Not started | Persistent laptop/desktop Frontend Site Manager Workspace integrating existing tools; it must not duplicate Media Manager or Go To Object logic. Additional responsive/mobile variants remain tabled by D-036. |

R1 is divided into five reviewable slices:

1. eligibility policy and exact ACF media catalog;
2. bounded scanner and separate user/blog-bound snapshot;
3. safe list read model plus row-expansion revalidation;
4. frontend table and verified static-mockup translation;
5. security, performance, accessibility, Builder isolation, fallback, and release documentation.

The initial R1 nested boundary is deliberately narrower than current render-derived resolver support. Repeater, flexible-content, mixed ancestry, conditional unknowns, option owners, and user owners remain deferred until an off-page enumerator and the existing resolver agree on one tested canonical path contract.

R1-A implementation evidence on 2026-08-15: `VisualEditorMediaManagerR1ATest` passed 5 tests/65 assertions; the combined Media Manager and existing Visual Editor instrumentation focus passed 12 tests/80 assertions. PHP syntax checks passed for all touched production classes. The full PHP comparison ran 689 tests/7,186 assertions and retained exactly six failures with the inherited identities; no new failure was introduced.

R1-B implementation evidence on 2026-08-15: `VisualEditorMediaManagerR1BTest` passed 5 tests/106 assertions; combined R1-A/R1-B passed 10/171; combined with existing Visual Editor instrumentation passed 17/186. A representative isolated PHPUnit chunk covering 20 entities and 60 findings measured 4.661 ms, 24 WordPress queries, zero additional allocated/peak memory pages at PHP's reported granularity, and a 4,983-byte compressed snapshot. The clean full comparison ran 694 tests/7,302 assertions and retained exactly the six inherited failures. R1-B changes no JavaScript, so the full-JavaScript-lint limitation below is not superseded. Agent docs passed with 54 curated records, 408 discovered surfaces, and zero unmapped.

R1-C implementation evidence on 2026-08-15: `VisualEditorMediaManagerR1CTest` passed 6 tests/417 assertions; combined R1-A/R1-B/R1-C plus existing Visual Editor instrumentation passed 23/603. Touched PHP syntax is clean. The full PHP comparison ran 700 tests/7,723 assertions and retained exactly the six inherited failure identities. Agent docs pass with 54 curated records, 415 discovered surfaces, and zero unmapped. R1-C changes no JavaScript and does not supersede the incomplete full-lint baseline.

R1-D mockup evidence through D4A-3 on 2026-08-16: Claude delivered and corrected the read-only package under `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/`. The final direction has a contract-valid R1-C fixture, explicit backend-to-view state adapter, distinct request/provider failure presentations, 13 state cases, one reachable short/mobile scroll body, corrected live announcements and count copy, practical touch targets, and seven true-viewport screenshots including 1440x600, 390x844, and 320x568. Structural checks passed 30/30, `node --check` and package integrity checks passed, and axe-core 4.11.0 returned 33 passing rule groups plus one serious 12-node `color-contrast` violation inherited from the shared `--dbvc-ve-color-text-subtle` production token. D4A is accepted as qualified static visual direction, not production markup or accessibility sign-off. Assistive-technology testing, Safari/Firefox, real-handset testing, production integration, and the shared-token correction remain separate gates.

R1-D production evidence on 2026-08-16: slice 1 added the default-off toolbar/shell and corrected the authoritative subtle-text token. Slice 2 added nonce-authenticated latest/start/list/next/retry/cancel client methods plus lifecycle/query state, exact backend-to-presentation mapping, current generation/revision identity, server-authorized action visibility, and out-of-order response suppression. Slice 3 renders safe collapsed rows in a semantic internally scrollable laptop/desktop table with server-driven search/entity/field/six-sort replacement, opaque-cursor append/de-duplication, preserved append scroll, and explicit loading/empty/list-error/append-error states. Slice 4 adds the group client, one-row lazy expansion, independent expansion sequencing/error state, strict safe-field normalization, and accessible buttons/regions for missing/changed/resolved-or-changed/provider-unavailable outcomes without descriptor or mutation controls. The first R1-E hardening slice now preserves row-button focus across table-body rerenders, verifies native Enter/Space expansion at both supported viewports, and removes disclosure animation under reduced-motion preference. Ten focused jsdom tests and 4 PHP tests/43 assertions pass; isolated mocked Chromium checks at 1440x900 and 1280x720 pass keyboard expansion, focus continuity, reduced motion, identity/status/ARIA, expanded and collapsed axe WCAG A/AA, 15-to-25 paging, server replacement/ARIA sort, bounded geometry, and trigger focus. The full PHP comparison immediately before this frontend-only hardening ran 704 tests/7,764 assertions with exactly the inherited six failure identities. Both targeted and aggregate repository `wp-scripts` lint complete successfully with stale Baseline/Browserslist data warnings only. Descriptors, Media Library actions, and mutation remain absent. D-036 targets normal laptop/desktop use, preserves existing narrow-width protections, and tables additional responsive/mobile work program-wide until explicitly reauthorized.

R1-E scale/fallback evidence on 2026-08-16: `VisualEditorMediaManagerR1ETest` passes 2 tests/50 assertions, and the combined R1-A through R1-E focus passes 22 tests/681 assertions. Ordinary frontend asset enqueue leaves the current user's latest scan absent. Synthetic 100/500/2,000-group snapshots remain compressed and the read model returns only the bounded 50-row page. In the combined run the 2,000-group case measured 25.425 ms, zero additional WordPress queries, a 6,291,456-byte allocated-memory delta, a 120,475-byte stored snapshot, and a 24,833-byte response. The full comparison now runs 706 tests/7,816 assertions with exactly the same six inherited failure identities. This proves snapshot/list/payload scaling, not complete 2,000-owner candidate traversal, raw ACF reads, authenticated REST transport, or browser rendering.

R1-E automated semantic/fallback evidence on 2026-08-16: the dialog and results scroll region now have stable names; list/group loading exposes polite atomic status plus busy state; each expanded entity keeps one heading through loading/success/error; and the stable live region announces field-check start/completion/failure. Eleven jsdom tests, 4 R1-D PHP tests/47 assertions, targeted Media Manager lint, and six 1440x900/1280x720 cases across Chromium/Firefox/WebKit pass with expanded/collapsed axe still at zero confirmed violations. Combined R1-A through R1-E passes 22/685 and full PHP runs 706/7,820 with the same six inherited failures. The latest aggregate lint rerun did not complete. Automated semantics/browser engines are not real screen-reader or real Safari evidence; authenticated runtime, AT, real Safari, and complete traversal/raw-read evidence remain open.

### Validation baseline at the reconciliation checkpoint

- The focused `VisualEditorElementInstrumentationTest` R0 check passed 7 tests and 15 assertions.
- The repository PHP suite still has 6 deterministic failures out of 684 tests. This is a known inherited baseline, not a green release gate; future slices must record the exact command/output and prove they introduce no additional failures.
- Full repository JavaScript lint did not complete, so no full-lint pass is claimed. Future slices should run focused lint/syntax checks for touched source and either complete the full command or record the same limitation precisely.
- R0 added no public runtime contract, so `composer agent-docs:check` was not required then. Run it when R1 adds public REST/settings/add-on surfaces.

Priority scale:
- `P0` - production blocker or confidence gap for already-supported client-site editing paths.
- `P1` - high-value client-site coverage gap for common Bricks/ACF patterns.
- `P2` - important widening or hardening after P0/P1 are stable.
- `P3` - UX and operator efficiency improvements that reduce friction but are not blockers.
- `P4` - advanced mutation work that changes collection/row structure and needs stronger rollback/contracts.
- `P5` - strategic/future work, broad generic support, analytics, or optimizations that should wait for evidence.

### P0 - Production Confidence And Existing-Path Closeout

| Priority | Item | Current State | Next Action | Source Guide |
|---|---|---|---|---|
| `P0` | Composite text `Save All` stale-state browser UI | Backend/runtime stale probes and live browser QA now cover no-write behavior and stale UI on `/our-process/` page `24732`. | Keep the exact-source stale harness in regression notes; do not widen source families while closing remaining QA documentation. | `DBVC_VISUAL_EDITOR_ADVANCED_IMPLEMENTATION_GUIDE.md` |
| `P0` | Existing-path regression QA on representative pages | Composite stale UI, idle descriptor availability, Builder-mode exclusion, query-collection `Save`/`Save and Reload`, and missing-image anchor panel open now have current browser evidence. Gallery save still has runtime/user QA but no current rendered browser marker; empty-loop badge browser QA still needs a live fixture. Reversible probes confirmed the current contractors vertical renders the gallery container empty even with known attachment IDs, and an emptied current benefits field falls back to a shared populated source instead of an empty loop. | Finish only the remaining real fixture gaps when a page actually renders them: current rendered `gallery_collection` browser save/reload and empty query-loop synthetic badge panel open. | `docs/qa/QA_CHECKLIST.md` |
| `P0` | Session/descriptor availability after idle or cross-page editing | TTL, touch, focus refresh, explicit expired-session messaging, and a marker-heavy idle browser check are implemented/verified. | Keep authenticated marker-heavy idle descriptor opening in the regression checklist, especially after session or enqueue changes. | `DBVC_VISUAL_EDITOR_PERFORMANCE_UPGRADE_GUIDE.md` |
| `P0` | Builder-mode exclusion regression | Live smoke on `/our-process/?bricks=run` confirmed Builder mode has zero Visual Editor markers and no toolbar, panel, or bootstrap payload. | Keep a lightweight builder-mode smoke check in the QA checklist whenever frontend bootstrapping or enqueue guards change. | `frontend-plugin-builder-mode-guard.md` |

### P1 - Common Client-Site Source Coverage

| Priority | Item | Current State | Next Action | Source Guide |
|---|---|---|---|---|
| `P1` | Native `relationship -> flexible` descendants | No current native fixture was found; branch remains WIP until a real template is identified or added. | Find or create a controlled fixture, then verify scalar/media/gallery descendants with concrete related-post owner and canonical flexible ancestry. | `DBVC_VISUAL_EDITOR_NATIVE_LOOP_EXPANSION_PLAN.md` |
| `P1` | Native `post_object -> repeater/flexible` live save confirmation | Classification and resolver-read groundwork exist; live fixture work is paused and must not be marked confirmed. | Resume only when a real client-site fixture exists or the user explicitly approves a disposable fixture. | `DBVC_VISUAL_EDITOR_NATIVE_LOOP_EXPANSION_PLAN.md` |
| `P1` | Taxonomy nested repeater/flexible descendants | Direct term fields work; nested term-owned descendants are guarded to existing rows/layouts with concrete term owner and canonical ancestry. | Identify a rendered fixture for term-owned nested rows and test existing-row scalar/media saves without enabling row/layout lifecycle mutation. | `DBVC_VISUAL_EDITOR_NATIVE_LOOP_EXPANSION_PLAN.md` |
| `P1` | Post-owned linked-term collection editing in loop cards | Dedicated branch is WIP for Bricks term roots and native taxonomy/terms elements. Active LocalWP browser QA on the contractors vertical now mounts distinct `Category Terms` badges for repeated post cards after keying related term badges by public source group. Runtime probes confirmed three concrete loop-owned category descriptors with term search, related acknowledgement contracts, no-op save preservation, and reversible add/remove/restore mutation for one concrete owner. Reverse-order submission saved the same term set but did not persist the submitted order. | Finish browser panel QA for the concrete owner cards: open each term panel, confirm owner/source copy, add/remove terms, no-reload save, optional reload, and rendered chip update/restore. Keep term reorder disabled/hidden or add a dedicated term-order contract before presenting reorder as supported. | `DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md` |
| `P1` | Archive-page regression suite | CPT/taxonomy archive entry points, option-backed fields, queried-term fields, native term name/description, and concrete loop descendants are implemented. | Add/refresh a small archive QA matrix covering Features, Services, and at least one taxonomy archive with option-backed and term-owned fields. | `DBVC_VISUAL_EDITOR_ARCHIVE_CONTEXT_PLAN.md` |

### P2 - Query Collection And Fallback Widening

| Priority | Item | Current State | Next Action | Source Guide |
|---|---|---|---|---|
| `P2` | Native include/post__in dynamic-control widening | Writable only when saved Bricks control exposes ACF dynamic-tag evidence and the final IDs prove a source field. | Add fixtures for native include controls that use ACF dynamic tags; keep static/manual and opaque ID lists locked. | `DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md` |
| `P2` | Custom Query Editor exact fallback QA | Current-owner exact matches, exact shared-option fallback matches, and explicit current-page seed actions are implemented narrowly. | Browser-smoke Save, Save and Reload, seed, undo, and reload controls on real fallback loops; record branch labels and acknowledgement copy. | `DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md` |
| `P2` | Nested grouped query-collection matching | Nested-group current-owner and exact shared-option matching is implemented when flattened selector and grouped metadata are proven. | Add focused QA for grouped selectors such as `benefits_section_benefitsContent_related_items` and preserve raw selector evidence in panel/source summaries. | `DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md` |
| `P2` | Empty query-loop collection badges | Synthetic registration and parent-anchor mounting are implemented for proven empty current-owner derived loops. | Add regression QA for empty loops where no Bricks row renders and for loops where raw IDs exist but all are outside the target post type. | `DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md` |
| `P2` | Missing/conditional media anchors | Missing image and empty gallery parent-anchor flows are implemented for proven sources. Phase B3 now supports exact unique-wrapper IDs only for the proven missing-media ancestor path and keeps general/repeated repair class-based. | Keep non-empty condition-skipped media deferred; widen only with concrete fixtures and unchanged source/owner/path proof. | `DBVC_VISUAL_EDITOR_ADVANCED_IMPLEMENTATION_GUIDE.md` |

### P3 - Editor UX And Large-Page Usability

| Priority | Item | Current State | Next Action | Source Guide |
|---|---|---|---|---|
| `P3` | Field index search/filter | Review Fields grouping is implemented; search/filter is deferred. | Add lightweight search and source-scope filters without hydrating full descriptors on page load. | `DBVC_VISUAL_EDITOR_FIELD_INDEX_PLAN.md` |
| `P3` | Field index virtualization and persisted state | Grouping and scroll preservation exist; virtualized list rendering and persisted expanded group state are deferred. | Add only after testing large pages shows list rendering or state reset is a real pain point. | `DBVC_VISUAL_EDITOR_FIELD_INDEX_PLAN.md` |
| `P3` | Toolbar 2.0 polish | Shell, upward status/review, Go To Object, Shared Globals, active-object links, and mode exit are implemented. | Confirm tooltip hover/focus behavior, large connected-items list scrolling, and shared global save UX in the browser. | `DBVC_VISUAL_EDITOR_TOOLBAR_2_0_IMPLEMENTATION_GUIDE.md` |
| `P3` | Rendered HTML tag in Source details | Phase B4 records the verified source-bearing semantic tag, rendered image tag, or marker-tag fallback in private hydrated descriptor metadata and displays `field_name = <tag>` without changing save authority. | Browser-smoke populated image and CSS-background markers; keep missing/unrendered elements unlabeled rather than guessing from parent anchors. | `DBVC_VISUAL_EDITOR_ADVANCED_IMPLEMENTATION_GUIDE.md` |
| `P3` | Mobile/tablet touch selection | Shared badge supports touch selection; additional responsive/mobile and real-device refinement is tabled by D-036. | Take no further action until explicitly reauthorized or a defect also blocks the supported laptop/desktop workflow. | `DBVC_VISUAL_EDITOR_BADGE_AND_HYDRATION_PLAN.md` |
| `P3` | Panel ergonomics for tall/complex controls | Dragging, close-on-outside-click, viewport fitting, Save, Save and Reload, media/gallery controls, and gallery drag sorting are implemented. | Keep minor panel UI fixes tied to concrete usability bugs; avoid broad redesign until production QA stabilizes. | `UI_STATES_AND_COPY.md` |

### P4 - Advanced Mutation Contracts

| Priority | Item | Current State | Next Action | Source Guide |
|---|---|---|---|---|
| `P4` | Relationship/post_object collection editing beyond proven current/shared-option paths | Direct current-owner, related-post loop-owned, and exact shared-option cases exist; broader shared/loop-owned/non-post collections are deferred. | Define each owner family separately with source proof, stale checks, acknowledgement, rollback, and journal detail before enabling writes. | `DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md` |
| `P4` | Taxonomy collection mutation | Post-owned linked terms are WIP; shared term collection mutation and taxonomy selector collection mutation remain deferred. | Finish post-owned terms first, then design term/shared-term contracts separately. | `DBVC_VISUAL_EDITOR_NATIVE_LOOP_EXPANSION_PLAN.md` |
| `P4` | Repeater row insert/remove/reorder | Field-level row descendants can save; row lifecycle mutation is deferred. | Add row inventory, stable row identity, snapshots, journal item detail, and rollback rules before any row create/delete/reorder UI. | `DBVC_VISUAL_EDITOR_REPEATER_IMPLEMENTATION_PLAN.md` |
| `P4` | Flexible row insert/remove/reorder | Existing layout descendants can save; layout lifecycle mutation is deferred. | Add layout inventory, stable row identity, layout-key validation, snapshots, and rollback rules before any layout create/delete/reorder UI. | `DBVC_VISUAL_EDITOR_NATIVE_LOOP_EXPANSION_PLAN.md` |
| `P4` | Structured descendants beyond gallery | Gallery has a dedicated flow; file/oEmbed/other structured projections remain inspect-first. | Add one explicit mutation contract and verifier per structured field family. | `DBVC_VISUAL_EDITOR_NATIVE_LOOP_EXPANSION_PLAN.md` |

### P5 - Strategic, Broad, Or Evidence-Driven Work

| Priority | Item | Current State | Next Action | Source Guide |
|---|---|---|---|---|
| `P5` | Materialized inventory/cache | Request-time registry remains authoritative; inventory cache is optional only if profiling proves request-time classification is the bottleneck. | Do not build until profiling identifies classification as the user-visible bottleneck. | `DBVC_VISUAL_EDITOR_PERFORMANCE_UPGRADE_GUIDE.md` |
| `P5` | Generic non-ACF or opaque Bricks query editing | Unsupported unless a resolver-owned content source can be proven. | Keep inspect-only or no badge for static/manual builder settings and opaque final-ID lists. | `DBVC_VISUAL_EDITOR_COLLECTION_EDITOR_PLAN.md` |
| `P5` | Generic options editing | Shared Globals is intentionally allowlisted to configured option-owned relationship/post_object fields. | Avoid generic options editor until there is an explicit source inventory, capability model, and UX boundary. | `DBVC_VISUAL_EDITOR_TOOLBAR_2_0_IMPLEMENTATION_GUIDE.md` |
| `P5` | Cross-page live DOM patching after shared/global saves | Current page projections can update; cross-page live patching is deferred. | Treat as future collaboration/realtime work, not part of current production readiness. | `DBVC_VISUAL_EDITOR_TOOLBAR_2_0_IMPLEMENTATION_GUIDE.md` |
| `P5` | Approval workflows, analytics, exportable diffs | Listed as future Phase 4 ideas, not current production blockers. | Revisit after core editing contracts and client-site QA stabilize. | This guide |

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
- collapsible statusbar field index for reviewing marked fields

## Phase 3
- descriptor V2 owner/page/path/loop/mutation metadata
- durable Visual Editor change journal tables
- dedicated save-contract groundwork for loop-owned sources
- repeater scalar subfield editing
- flexible content scalar subfield editing
- image/media support
- missing/conditional Bricks image media badges that anchor to a safe parent/container when a proven empty image source prevents the image element from rendering
- structured repeater/flexible subfields
- draggable, closable session-persistent overlay panel UX
- revision restore UX
- grouped change queue / review mode
- runtime profiling and performance instrumentation
- optional materialized inventory cache only if profiling proves request-time classification is the bottleneck

## Performance Upgrade Tranche
- Dedicated guide: `DBVC_VISUAL_EDITOR_PERFORMANCE_UPGRADE_GUIDE.md`.
- Goal: make frontend field loading and editing feel near live without weakening render-time source verification, server-side descriptor authority, or mutation-contract safety.
- Current audit summary: startup is already public-map-only with on-demand descriptor hydration, in-flight request reuse, active-marker dwell prefetch, and bounded viewport-aware warmup. Remaining likely bottlenecks are unmeasured PHP render/classification cost, repeated ACF/Bricks lookups, eager WordPress editor/media assets, single-token descriptor prefetch requests, broad marker scans, query-collection badge remount work, and full public-map session refreshes used as keepalive.
- Recommended order:
  - add disabled-by-default server/frontend profiling
  - add request-local memoization for page context, loop context, ACF provider tags, field objects, field inventories, and derived-query value lookups
  - add capped batch descriptor hydration and a minimal session touch endpoint
  - replace repeated browser marker scans with a maintained token-to-node map
  - profile heavy editor/media asset loading before introducing late-load or prediction behavior
  - introduce a materialized inventory layer only after profiling proves request-time classification is the bottleneck
- Inventory rule: a DB table can be used for fast runtime hints and sync-path JSON can be used for reviewable artifacts, but neither may store request tokens, skip live render verification, or make descriptors writable by itself.

## Phase 4
- relationship collection editing
- advanced query-loop owner coverage beyond the current safe related-post slice
- CPT archive and taxonomy archive entry-point support, with direct queried-term ACF saves, direct option-backed archive saves, and native queried-term name/description saves enabled first
- DBVC sync-awareness
- field lock policies
- approval workflows
- usage analytics
- exportable change sets / diffs

## Archive Context Tranche
- Dedicated plan: `DBVC_VISUAL_EDITOR_ARCHIVE_CONTEXT_PLAN.md`.
- Current runtime state: supported entry points with archive-aware descriptors. `PageContextResolver` supports public CPT archives, the posts archive, and taxonomy archives. Direct ACF fields owned by the queried taxonomy term can save when they are outside active archive query loops and outside repeater/flexible/collection contexts. Direct option-backed ACF fields on CPT and taxonomy archive pages can save through the existing shared-field acknowledgement path, including options-page field groups discovered from ACF location rules. Native `{term_name}` and `{term_description}` fields can save for the queried taxonomy archive term and for concrete Bricks term-loop owners on archive and non-archive pages/templates through the dedicated term resolver. Archive query-loop descendants now reuse existing concrete loop-owner save contracts when Bricks exposes a stable per-row post or term owner. Derived native tags such as `{post_url}`, `{term_url}`, `{term_id}`, and `{archive_title}` surface inspect-only where they can be resolved safely. Collection fields, galleries, and non-concrete archive loop owners remain inspect-only.
- Verified template/source shape:
  - CPT and taxonomy archive templates render option-backed ACF fields from options-page field groups such as `services-settings`, `features-settings`, and `treatments-settings`.
  - taxonomy archives render current term data and term meta such as `term_name`, `term_description`, `core_tax_group_*`, and `vf_sa_group_*`.
  - archive templates also include shared/global option fields and query-loop post/term rows; those must keep existing owner-specific badge and acknowledgement rules.
- Recommended implementation order:
  - archive-aware page context first: implemented
  - surface render-verified archive markers inspect-only: implemented
  - enable queried taxonomy term ACF field saves: initial direct-field slice implemented
  - enable archive option-backed ACF field saves with explicit shared-option warnings: initial direct-field slice implemented and broadened to taxonomy archives
  - add native term field support only through dedicated term resolvers: initial queried-term name/description slice implemented
  - reuse existing concrete loop-owner support for archive query-loop descendants: initial post/ACF/term-field slice implemented
  - surface derived native archive tags inspect-only: initial `post_url`, `term_url`, `term_id`, and `archive_title` slice implemented

## Field Index UX Tranche
- Dedicated plan: `DBVC_VISUAL_EDITOR_FIELD_INDEX_PLAN.md`.
- Goal: add a collapsible nested list in `dbvc-ve-statusbar__meta` so users can review all marked fields on the current page without hovering every element.
- Recommended shape: keep the statusbar compact by default, add a `Review fields` toggle, then render owner/source grouped rows with `Locate` and `Open` actions.
- Data contract: extend the startup public map with shallow, safe index metadata. Do not use full descriptor hydration on page load and do not expose field values in the public map.
- Current runtime state: passive statusbar refreshes preserve `.dbvc-ve-field-index.scrollTop`, and the expanded review list now clusters field item accordions under immediate parent sections such as ACF group fields, row container roots, field groups, option pages, or native-loop labels using existing public index metadata first. The earlier redundant source subgroup summary/toggle layer has been removed, so item summaries now carry the field label and marked-field count in one row.
- Follow-up: add safe group-label metadata only if live testing shows humanized field names are insufficient.
- Initial grouping order:
  - current entity fields
  - related posts
  - related terms
  - shared options
  - shared terms/users
  - archive fields
  - inspect-only / derived fields
- Recommended first implementation slice:
  - public-map `index` metadata
  - client-side field index model
  - collapsed/expanded statusbar UI
  - `Locate` and `Open` marker actions
- Deferred:
  - search/filter
  - lazy enriched source summaries
  - virtualized list rendering
  - persisted expanded group state
  - bulk actions

## Toolbar 2.0 UX Tranche
- Dedicated guide: `DBVC_VISUAL_EDITOR_TOOLBAR_2_0_IMPLEMENTATION_GUIDE.md`.
- Goal: replace the bottom-corner `dbvc-ve-statusbar` presentation with a bottom-center Visual Editor toolbar that hosts status, field review, object navigation, shared/global collection launchers, active object links, and session/mode controls.
- Recommended shape: fixed dark compact dock, icon-first controls with accessible labels, circular satellite buttons, and upward-opening popovers that reuse current statusbar, field index, descriptor hydration, panel, and collection-editor contracts.
- Migration rule: move the existing statusbar into the toolbar through a compatibility wrapper first. Preserve marker counts, save/session messages, field index filters/state, `Locate`, `Open`, and active-owner edit links before retiring the old statusbar root.
- Go To Object: navigation-only popover for capability-aware post and term search. It must not expose descriptor payloads, field values, or mutable save targets.
- Shared Globals: configured option-owned ACF `relationship` / `post_object` fields from the Visual Editor settings allowlist only. Writable configured globals require exact ACF metadata, option capability, shared acknowledgement, the existing shared collection mutation contract, and reload-after-save behavior. Current-page fallback query-loop descriptors remain in the status/review flow and do not appear in the Shared Globals popover.
- Current runtime state: first toolbar shell slice is implemented. The existing `dbvc-ve-statusbar` renderer is parked inside the toolbar and opens upward for status/review so current marker counts, field index filters/state, `Locate`, `Open`, active-owner edit links, and save/session messages stay on the existing code path. Go To Object is implemented as navigation-only capped post/term search with capability filtering and explicit frontend/backend links. Shared Globals is implemented for configured option fields, defaulting to `settings_globals_default_posts`; configured fields are attached to the active session as toolbar-scoped descriptors and open through the existing connected-items panel/save flow.
- Recommended first implementation slice:
  - toolbar shell behind a reversible migration path
  - shared upward popover manager
  - statusbar/field-index popover parity
  - active object edit-link button parity
- Deferred:
  - object search writes or arbitrary URL navigation
  - generic options editing
  - shared global writes before inspectable inventory and exact collection contracts
  - row/layout lifecycle mutation
  - cross-page live DOM patching after shared global saves

## Add-on Settings and Frontend Exclusions Tranche
- Dedicated guide section: `DBVC_VISUAL_EDITOR_TOOLBAR_2_0_IMPLEMENTATION_GUIDE.md` -> `Add-on Settings Submenu and Exclusions`.
- Goal: give Visual Editor its own DBVC submenu settings page while preserving the existing Configure -> Add-ons settings source of truth, then allow site admins to exclude internal or non-content post types/taxonomies from every frontend Visual Editor surface.
- Default exclusions:
  - post types: `bricks_template`
  - taxonomies: `template_tag`, `template_bundle`
- Exclusion surfaces:
  - page-context support checks for singular, CPT archive, and taxonomy archive entry points
  - request/session descriptor registration and public-map export
  - Go To Object post/term search
  - descriptor-scoped connected-item and linked-term searches
  - Toolbar Shared Globals configured-field inventory
- Save safety rule: excluded selected values must not be silently deleted just because the panel hides them. Full replacement collection saves must preserve excluded stored IDs that were hidden from the editor UI.
- Current runtime state: implementation slice added a Visual Editor submenu page, content visibility settings, centralized exclusion helpers, descriptor/session filtering, object/reference search filtering, shared-global target filtering, page-context guards, and hidden-excluded-ID preservation for ACF reference collection saves.
- Validation focus:
  - settings page saves and reflects values
  - excluded `bricks_template`, `template_tag`, and `template_bundle` do not appear in Go To Object
  - excluded taxonomy archives do not activate Visual Editor
  - connected-items searches omit excluded post types
  - linked-term searches omit excluded taxonomies
  - mixed relationship fields preserve hidden excluded IDs on save

## Archived Running Context

Older thread-style implementation notes that previously lived in this file were moved to `../archives/DBVC_VISUAL_EDITOR_OPEN_ITEMS_CONTEXT_2026_07_07.md`.

Use this file's P0-P5 backlog for active planning. Use the archive only when recovering historical fixture details or understanding why an item was deferred.
