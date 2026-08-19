# Implementation Tracker

Update this file with evidence-backed status. Do not mark a release complete from code existence alone; all release gates must pass.

## Status legend

- `Not started`
- `Discovery`
- `Planned`
- `In development`
- `In review`
- `QA`
- `Feature-gated production`
- `Generally available`
- `Complete`
- `Blocked`
- `Deferred`

## Program summary

| Release | Outcome | Status | Owner/session | Evidence link | Release gate |
|---|---|---|---|---|---|
| R0 | Discovery and corrected baseline | Complete | Codex R0 / 2026-08-14 | `../01-DISCOVERY-AND-CURRENT-STATE-REVIEW.md`; `EVIDENCE-LOG.md` | Complete at repository-reconciled planning checkpoint `5db4b40` |
| R1 | Media Manager scan/report | Complete (signed off) | Codex R1-A/R1-B/R1-C/R1-D/R1-E / 2026-08-16 | `MediaManager/`; `MediaManagerController`; Media Manager R1 tests; corrected R1-D fixture/mockup; E-030/E-032/E-035/E-038-E-052 | R1-A through R1-E complete; live-REST auth-enforcement proof (401 before resolution) and complete multi-owner candidate-traversal/raw-read scaling (no field-definition/capability/permalink N+1) complete. **R1 signed off by the user on 2026-08-16** with the residual gates (authenticated table-data runtime, real AT/VoiceOver, real Safari, large-list responsiveness, aggregate lint completion) explicitly accepted as documented risk (D-044) |
| R2 | Media Manager direct remediation | In development (R2-C) | Codex R2-A/R2-B/R2-C / 2026-08-16 | Decisions D-044, D-045, D-046, D-047; `MediaManagerController`; `MediaFindingDescriptorBridge`; `MediaAssignmentService`; `media-manager-app.js`; R2-A/R2-B/R2-C tests | R2-A (bridge), R2-B (native `wp.media` staging), and R2-C (field-level save with expected-empty gate + no-reload reconciliation) complete after R1 sign-off. R2-C is the first content-mutating slice; R2-D UX states and R2-E production hardening remain |
| R3 | Registry-backed Brand Control Center | Not started |  |  |  |
| R4 | Expanded Global & Brand Control Center | Not started |  |  |  |
| R5.1 | Scalar ACF option fields | Not started |  |  |  |
| R5.2 | Choice/link/WYSIWYG option fields | Not started |  |  |  |
| R5.3 | Image/gallery option fields | Not started |  |  |  |
| R5.4 | Connected/taxonomy option fields | Not started |  |  |  |
| R6 | Frontend Site Manager Workspace | Not started |  |  |  |

## R0 checklist

- [x] Branch, status, recent commits, and uncommitted work recorded
- [x] Current Visual Editor architecture mapped
- [x] Existing media scanner/catalog evidence mapped
- [x] Featured-image/image/gallery read/write paths traced
- [x] Non-rendered descriptor strategy verified (confirmed absent generically; narrow R2 bridge required)
- [x] Eligible entity matrix completed
- [x] Media field/path matrix completed
- [x] Performance baseline recorded (enumeration/applicability only; full R1 scanner baseline remains gated)
- [x] Shared Globals/option support matrix completed
- [x] VerticalFramework evidence and exact paths recorded
- [x] Corrected R1/R2 planning sequence reconciled (does not authorize implementation)
- [x] Risks, decisions, rollback, and test gaps updated

### R0 stop line

- Production code changed: **No**
- DB/runtime writes performed: **No**
- R1 implementation authorized: **No**
- Reconciled Git checkpoint: **clean/synchronized `codex/visual-editor-linked-posts-plan` at `5db4b40` when this documentation refresh began**
- Known validation limitations: **6 deterministic PHP failures out of 684 tests; full JavaScript lint did not complete**
- Next crossing line: explicit user authorization for R1-A after refreshing Git status/HEAD and confirming the inherited test/lint baseline

## R1 Media Manager scan/report

### Policy/catalog

- [x] Object eligibility policy implemented/reused
- [x] Post type/taxonomy exclusions verified
- [x] ACF location/field catalog implemented/reused
- [x] Featured-image eligibility verified
- [x] Nested/conditional policy documented
- [x] Capability filtering covered

### R1-A review stop line

- Production scope: **default-off setting, policy, catalog, raw-value classification, focused tests, and required docs/agent-reference maintenance only**
- Explicitly absent: **scanner, snapshot, REST/AJAX, frontend assets/UI, descriptors, and mutation**
- Focused validation: **5 tests/65 assertions for R1-A; combined Visual Editor focus 12 tests/80 assertions; touched PHP syntax clean**
- Full comparison: **689 PHP tests/7,186 assertions with the same six inherited failure identities and no new failure**
- Remaining validation limitation: **full JavaScript lint did not complete at the inherited checkpoint and was not rerun because R1-A touches no JavaScript**
- Recorded crossing line: **R1-B was explicitly authorized after review of this policy/catalog contract and is now complete for review**

### Scan/session

- [x] Minimal bounded coordinator and separate snapshot implemented (no compatible existing scanner/session found)
- [x] Start/progress/next/complete states
- [x] User/site-bound snapshot
- [x] Expiry/cancel/retry behavior
- [x] Duplicate/stale response handling
- [x] Summary counts and finding identity
- [x] Representative performance measured

### R1-B review stop line

- Production scope: **bounded candidate provider, supported-value scanner, exact group-only traversal, deterministic opaque identity/fingerprints, compressed transient snapshot store, coordinator lifecycle/revision/config invalidation, performance metrics, focused tests, and docs maintenance only**
- Explicitly absent: **REST/AJAX controller/routes, public list/search/filter/pagination, row expansion/revalidation, descriptors, frontend assets/UI, and mutation**
- Focused validation: **5 tests/106 assertions for R1-B; combined R1-A/R1-B 10/171; combined current Visual Editor focus 17/186; touched PHP syntax clean**
- Representative chunk: **20 entities, 60 findings, 4.661 ms, 24 queries, zero additional allocated/peak memory pages at reported granularity, 4,983-byte compressed snapshot**
- Full comparison: **694 PHP tests/7,302 assertions with the same six inherited failure identities and no reproducible new failure; an initial extra Phase 4 failure passed in isolation, passed with its 32-test class, and disappeared on the clean full rerun**
- Agent documentation: **54 curated records; 408 discovered surfaces; zero unmapped**
- Remaining validation limitation: **R1-B changes no JavaScript; the inherited full JavaScript lint command still has no completed result**
- Recorded crossing line: **R1-C was explicitly authorized after review of the scanner/snapshot contract and is now complete for review**

### Read model/UI

- [x] Search/filter/sort/pagination
- [x] Row expansion revalidation
- [x] Safe display records and opaque references
- [x] UI requirements brief
- [x] Claude static mockup delivered/reviewed
- [x] Accepted/adapted/rejected design decisions recorded
- [x] D4A contract/state/responsive/interaction corrections accepted
- [x] Feature-gated toolbar entry and responsive shell implemented
- [x] API/state controller, request identity, state adapter, and stale-response suppression implemented
- [x] Production scrollable laptop/desktop table implemented
- [x] Table loading/empty/no-match/list-error/append-error states
- [ ] Keyboard and supported laptop/desktop QA; additional touch/mobile/responsive work tabled by D-036
- [ ] Bricks Builder isolation
- [ ] Feature flag/fallback/release notes

### R1-C review stop line

- Production scope: **protected active-mode start/latest/list/next/retry/cancel/group routes, safe lifecycle/result projections, bounded search/filter/sort/opaque-cursor retrieval, list-time object permission/status rechecks, single-owner row rescanning, focused tests, and required docs/agent-reference maintenance only**
- Explicitly absent: **frontend assets/table, descriptor issuance, Media Library selection/upload, content mutation, journal writes, cache invalidation, and R2 actions**
- Focused validation: **6 tests/417 assertions for R1-C; combined R1-A/R1-B/R1-C and current Visual Editor instrumentation 23/603; touched PHP syntax clean**
- Full comparison: **700 PHP tests/7,723 assertions with exactly the same six inherited failure identities and no new failure**
- Agent documentation: **54 curated records; 415 discovered surfaces; zero unmapped**
- Security boundary: **browser payloads contain no owner IDs/subtypes, ACF object IDs, field keys/names/selectors/paths, empty/configuration fingerprints, raw values, descriptors/tokens, or mutation actions**
- Remaining release gates: **production frontend shell/table, shared subtle-text contrast correction, browser/accessibility/Builder QA, large-site/payload profiling, feature fallback/release evidence**
- Remaining validation limitation: **R1-C changes no JavaScript; the inherited full JavaScript lint command still has no completed result**
- Recorded crossing line: **R1-D was explicitly authorized for Claude static mockups and frontend-table translation against the frozen R1-C view model**

### R1-D authorization, static direction, and production crossing line

- Authorization: **R1-D approved by the user on 2026-08-15; Claude completed the separately reviewed D1-D4 static-mockup sub-phases**
- Context optimization: **the minimal launcher delegates to one self-contained context capsule and one R1-C-safe fixture; broader package/source reads are exception-only**
- Production scope at D4A handback: **No; the delivered files are static documentation/mockup artifacts and add no production frontend asset, component, route, descriptor, or mutation code**
- Claude D1-D4 state: **complete; 15-file read-only static mockup delivered under `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/` and reviewed by Codex**
- Review decision: **D4A accepted as qualified static visual direction; production must use the authoritative read model and a narrow module, not copy the fixture or local mockup script**
- D4A corrections: **complete—contract-valid sort/pagination/timestamp/expanded fields; explicit backend-to-view state adapter; distinct expansion 404 versus provider-unavailable states; reachable 390x844/375x667/320x568 layout; corrected announcements/count copy/touch targets/ARIA/order; seven refreshed screenshots and reconciled active docs**
- D4A validation: **30/30 structural checks, `node --check`, seven true-viewport captures, and package 46/46 integrity passed. The original default view exposed one serious shared-token contrast violation; after the authoritative token correction and screenshot refresh, axe-core 4.11.0 returned 33 passing rule groups/0 violations/38 incomplete for `index.html` and 22 passes/0 violations/0 incomplete for `states.html`. No assistive-technology, Safari/Firefox, real-device, or authenticated production-integration pass is claimed.**
- Accessibility qualification: **the confirmed failures trace to the shared production `--dbvc-ve-color-text-subtle` token; RK-036 owns its production correction. RK-035 remains open for broader production accessibility evidence.**
- Recorded crossing line: **production translation slice 1 was authorized for the feature-gated toolbar entry/shell boundary and is now complete for review**

### R1-D production slice 1 review stop line

- Production scope: **feature-gated Media Manager toolbar entry; separate scoped CSS/JavaScript shell; localized feature/config strings; non-modal close/Escape lifecycle; trigger `aria-expanded` and focus restoration; inherited active-mode and Builder asset guards**
- Explicitly absent: **scan/list/group requests, API/state controller, result rows/table, descriptors, Media Library actions, content mutation, journal writes, and cache invalidation**
- Focused validation: **4 PHPUnit tests/32 assertions; PHP and JavaScript syntax clean; disabled and Builder asset gates covered**
- Isolated browser validation: **1440x900, 1440x600, 390x844, and 320x568 geometry/focus pass; no horizontal overflow; 44px short/mobile close target; shell axe 13 passes/0 violations at every viewport**
- Shared contrast correction: **authoritative and mirrored subtle token now use the 60% mix; post-correction axe reports 0 violations on both mockup pages, while 38 incomplete default-view contrast nodes keep broader accessibility evidence open**
- Full comparison: **704 PHP tests/7,755 assertions with the same six inherited failures; repository `npm run lint` completed with stale browser-data warnings; agent docs passed 54 curated records/415 discovered surfaces/0 unmapped**
- JavaScript qualification: **the repository lint script does not include raw Visual Editor assets; touched files pass direct Node syntax checks and the isolated Chromium harness**
- Viewport priority: **preserve the proven responsive baseline, but pause additional mobile-friendly layouts, responsive cards, touch refinements, real-device optimization, and mobile-specific QA; current implementation targets normal laptops and desktops per D-036**
- Recorded crossing line: **R1-D production slice 2 was authorized by the user on 2026-08-16 and is complete for review**

### R1-D production slice 2 review stop line

- Production scope: **shared API-client methods for latest/start/list/next/retry/cancel; first-open resume; bounded query normalization; copied safe state; generation/revision identity; explicit backend/no-scan/error/stale mapping; server-authorized action visibility; out-of-order/older-revision suppression; lifecycle-only status/actions**
- Explicitly absent: **result rows/table, cursor append rendering, row expansion, descriptors, Media Library actions, content mutation, journal writes, cache invalidation, and additional responsive/mobile work**
- Focused JavaScript validation: **5 state/route tests pass, covering current-scan resume, exact request identity/query, all backend-state mappings, latest 404, revision conflict, and slower-response suppression**
- Isolated browser validation: **1440x900 and 1280x720 latest/start/next/list flow; exact nonce and identity; safe list retained without row DOM; geometry and Escape/focus pass; shell axe 13 passes/0 violations at each viewport**
- Full comparison: **704 PHP tests/7,755 assertions with exactly the same six inherited failures; repository lint including all touched Visual Editor JavaScript and the focused state test completed with stale browser-data warnings only; agent docs passed 54 curated records/415 discovered surfaces/0 unmapped**
- Next implementation line: **R1-D production slice 3—server-driven laptop/desktop result table for search/filter/sort, replacement/cursor append, loading/empty/error state, and preserved query/scroll state; no expansion yet and no responsive cards/mobile refinement**

### R1-D production slice 3 review stop line

- Production scope: **semantic internally scrollable laptop/desktop table over safe R1-C rows; server-driven search/entity/field/six-sort query; first-page replacement; opaque-cursor append with group-reference de-duplication; explicit loading/no-findings/no-match/list-error/append-error/retry states; preserved query and append scroll**
- Explicitly absent: **row expansion/group calls, descriptors, Media Library actions, content mutation, journal writes, cache invalidation, and additional responsive/mobile work**
- Focused JavaScript validation: **8 state/DOM tests pass for safe row projection, request identity, state/error mapping, search/filter/sort replacement, cursor append/de-duplication, scroll retention, and stale suppression**
- JavaScript static validation: **the new module and both new test files pass targeted `wp-scripts` lint with stale browser-data warnings only; the aggregate repository command did not complete during a bounded rerun, so no full-lint pass is claimed**
- Focused PHP validation: **4 tests/37 assertions pass for feature/Builder asset gates, toolbar/focus/read-only seams, safe table markers, and scoped contrast/styles**
- Isolated browser validation: **2 Playwright tests pass at 1440x900 and 1280x720 for 15-to-25 row paging, append scroll, server replacement/ARIA sort, exact nonce/identity/query/cursor, no expansion control, bounded geometry, Escape/focus, and axe WCAG A/AA with zero violations**
- Full comparison: **704 PHP tests/7,760 assertions with exactly the same six inherited failure identities and no slice-3 regression**
- Evidence boundary: **browser responses are mocked; authenticated WordPress runtime, large-scale profiling, assistive technology, and cross-browser evidence remain R1-E work**
- Next implementation line: **R1-D production slice 4—lazy row expansion and pending/current/changed/resolved-or-changed/provider-unavailable/request-error presentation over the existing server-owned group route; no descriptor or mutation bridge**

### R1-D production slice 4 review stop line

- Production scope: **nonce-authenticated group client; current scan/generation/revision plus opaque group identity; one-row lazy expansion; independent pending/error sequencing; safe normalized current/changed/resolved-or-changed/provider-unavailable field projections; accessible row button and labeled detail region; collapse/new-row stale suppression; preserved list and table scroll**
- Explicitly absent: **descriptor hydration, Media Library choose/upload, content mutation, journal writes, cache invalidation, full-page/table reload logic, and additional responsive/mobile work**
- Focused JavaScript validation: **10 state/DOM tests pass for group identity, all field status families, unknown-key stripping, forced false mutation actions, one-row behavior, late-response suppression, isolated group errors, table queries/paging, and backend-state mapping**
- JavaScript static validation: **targeted and aggregate repository `wp-scripts` lint plus direct Node syntax pass with stale Baseline/Browserslist data warnings only**
- Focused PHP validation: **4 tests/41 assertions pass for feature/Builder asset gates, toolbar/focus/read-only seams, group-client/expansion markers, absence of `wp.media`/write actions, and scoped styles**
- Isolated browser validation: **2 Playwright tests pass at 1440x900 and 1280x720 for exact group identity, expanded status/ARIA/region/collapse behavior, no assignment action, retained paging/scroll/geometry/focus, and expanded/collapsed axe WCAG A/AA with zero violations**
- Full comparison: **704 PHP tests/7,764 assertions with exactly the same six inherited failure identities and no slice-4 regression**
- Evidence boundary: **browser responses are mocked; authenticated WordPress runtime, large-scale profiling, assistive technology, and cross-browser remain R1-E gates**
- Next implementation line: **R1-E hardening; after that R2-A descriptor bridge, R2-B native Media Library choose/upload in the loaded table context, and R2-C field-level save plus targeted no-reload finding/count reconciliation**

### R1-E initial hardening checkpoint

- Production correction: **restore focus to the current replacement row button after loading/success/collapse table-body rerenders without moving the results scroller; suppress the disclosure transition under reduced-motion preference**
- Keyboard/browser validation: **Enter expands and Space collapses at 1440x900 and 1280x720; row focus remains stable; expanded/collapsed axe remains zero violations; reduced-motion computed transition is `0s`**
- Static/automated validation: **10 jsdom tests pass including focus continuity; focused R1-D PHP passes 4 tests/43 assertions; targeted and aggregate lint pass with stale dependency-data warnings; agent docs and package integrity pass after reconciliation**
- Current broad comparison: **704 PHP tests/7,764 assertions immediately before this frontend-only correction, with exactly the same six inherited failure identities**
- Still open: **authenticated active-site REST/table proof, complete candidate traversal/raw-read performance where fixtures permit, assistive-technology review, Safari/Firefox, and final release/fallback evidence**

### R1-E synthetic scale and no-auto-scan checkpoint

- Added `VisualEditorMediaManagerR1ETest` with ordinary frontend enqueue/no-scan proof and 100/500/2,000-group compressed snapshot/read/payload measurements.
- Focused R1-E passes 2 tests/50 assertions; combined R1-A through R1-E passes 22/681.
- Full PHP comparison passes all new coverage while retaining exactly the inherited six failures: 706 tests/7,816 assertions.
- Combined 2,000-group evidence: 25.425 ms; 0 additional WordPress queries; 6,291,456 allocated-memory bytes; 120,475 stored bytes; 24,833 response bytes; 50 returned rows.
- Boundary: synthetic groups share one eligible owner to isolate snapshot/list/payload scale. Complete candidate enumeration, owner-specific capability/raw ACF reads, authenticated REST/browser transport, AT, and cross-browser work remain open.
- Runtime provenance was refreshed read-only: active site `dbvc-codexchanges.local`, active plugin checkout, `vertical` child theme over Bricks. The persistent Visual Editor option is off and Media Manager option is on; the available browser session is logged out. No option, session, or credential state was changed.
- Next implementation line: **continue R1-E with authenticated runtime and representative scale checks; do not cross into R2 descriptor or mutation authority until the remaining release gate is reviewed**

### R1-E automated semantic and fallback checkpoint

- Production semantics: **the dialog references visible title/description; the results heading and focusable scroll region have stable names; list/group loading exposes polite atomic status and busy state; and the entity-specific expansion heading persists across loading, success, and error**
- Announcements: **the existing stable live region reports field-check start, completion status counts, and request failure without exposing descriptor/owner/path targets**
- Focused validation: **11 jsdom tests, 4 R1-D PHP tests/47 assertions, targeted Media Manager lint, and 6 Playwright cases across Chromium/Firefox/WebKit at 1440x900/1280x720 pass; expanded/collapsed axe remains zero confirmed WCAG A/AA violations**
- Broad comparison: **combined R1-A through R1-E passes 22/685; full PHP runs 706/7,820 with the same six inherited failures; the latest aggregate repository lint rerun did not complete and is not promoted to a pass**
- Fallback: **the existing default-off and Builder gates omit the Media Manager module/entry, ordinary frontend enqueue creates no scan, and R1 has no content/data migration or write path**
- Evidence boundary: **automated DOM/ARIA/axe/browser-engine evidence is not real assistive-technology or real Safari proof. Authenticated WordPress runtime, VoiceOver or equivalent, real Safari, complete candidate traversal/raw reads, and aggregate lint completion remain open**
- Next implementation line: **finish or explicitly accept the remaining R1-E runtime/AT/browser/performance/lint gates before crossing into R2-A descriptor issuance**

### R1-E closeout checkpoint (2026-08-16)

- Re-verified focused evidence before trusting older counts: 11/11 Media Manager jsdom tests, targeted Media Manager lint (stale Baseline/Browserslist warnings only), and 6/6 Playwright cases across Chromium/Firefox/WebKit at 1440x900/1280x720.
- Runtime provenance refreshed read-only (E-050): active site `dbvc-codexchanges.local`, `bricks`+`vertical` theme, DBVC plugin active, Media Manager option ON. The persistent Visual Editor option is now ON (drift from the previously recorded OFF; recorded, not reverted per D-043). No option/login/content/LocalWP state changed.
- Live REST auth-enforcement proven unauthenticated (E-050): all seven Media Manager routes registered; `scans/latest`, tampered scan/group refs, and POST `scans` each return HTTP 401 `rest_forbidden` with no data and no snapshot created — auth enforced before resource resolution.
- Complete candidate-traversal/raw-read scale added (E-051): new `test_complete_candidate_traversal_and_raw_reads_scale_without_field_definition_n_plus_one` drives the real provider/scanner/store pipeline to completion across 100 and 300 live owners, proving per-owner raw reads constant at 2, applicability evaluated once per candidate, max 50 candidates and <=1 source query per chunk, and per-candidate DB cost falling ~1.25 -> ~0.83 as owners triple (no N+1). Focused R1-A-R1-E now passes 23 tests/1,127 assertions.
- Full PHP comparison (E-052): 707 tests/8,262 assertions with exactly the six inherited failures (+1 test/+442 assertions over 706/7,820 is the new traversal test; no new regression).
- Aggregate lint: one bounded attempt on 2026-08-16 ran ~11 minutes without completing and was stopped; not promoted to a pass (RK-032).
- Accepted residual R1-E gates: authenticated active-site REST/table **data** behavior (no authorized session available), real VoiceOver/assistive technology, real Safari (WebKit engine is not Safari), large-list browser responsiveness, and a completing aggregate JavaScript lint run.
- Fallback/rollback unchanged: default-off asset/entry gates, no-auto-scan, and no content/data migration remain the R1 rollback contract.
- Next crossing line: **R1 sign-off (or explicit acceptance of the residual gates above) before R2-A descriptor-bridge issuance. No descriptor/Media Library/mutation work performed in this closeout.**

## R2 Media Manager remediation

### R2-A descriptor bridge review stop line

- Production scope: **`MediaFindingDescriptorBridge` plus a protected `POST .../scans/{scan_ref}/groups/{group_ref}/findings/{finding_ref}/descriptor` route that exchanges one opaque finding for one fresh standard `EditableDescriptor`. It resolves the owner/field only from the user/site-bound snapshot, revalidates snapshot identity/owner status/capability/field applicability/field family/empty value, routes to exactly one existing resolver family (featured/ACF image/ACF gallery), and persists the descriptor via the narrow `EditableRegistry::persistDetachedDescriptor()`. The response returns only opaque token/session ids plus safe labels/status.**
- Explicitly absent: **Media Library selection/upload, staged selection, `wp.media`, value hydration, content mutation, journal writes, cache invalidation, and any exposure of owner ids/field keys/selectors/ACF object ids/paths/fingerprints.**
- Focused validation: **11 R2-A tests/200 assertions (three writable families and correct single-resolver routing, server-resolved selector/group-path carriage, no-raw-target projection, user-bound session isolation, tampered/malformed refs, stale generation/revision, expired snapshot, populated-after-scan `resolved`, changed-evidence `changed`, unpublished/deleted owner `unavailable`); combined R1-A-R1-E plus R2-A 34/1,327; touched PHP syntax clean.**
- Full comparison: **718 PHP tests/8,462 assertions with exactly the same six inherited failures (+11 tests/+200 assertions is R2-A; no new regression).**
- Agent documentation: **54 curated records; 416 discovered surfaces (+1 for the new route); 0 unmapped; the shifted session-compression hook and the eight Media Manager routes were remapped in `manifest.json`.**
- Recorded crossing line: **R2-B native Media Library choose/upload in the loaded table context is the next slice and is not authorized by this slice.**

### R2-B media-library selection review stop line

- Production scope: **the `dbvc-ve-media-manager__detail-panel` gains a capability-gated `assign-media` control per still-`missing` field. Activating it calls the R2-A bridge and, on a `writable` descriptor, opens the native `wp.media` frame — single image for featured/ACF image, multiple ordered for ACF gallery — reusing the same standard frame config as `overlay-app.js`. The selection is staged client-side (unsaved) with an `Unsaved selection` badge, thumbnail preview, `Replace`/`Clear`, and a live announcement.**
- Explicitly absent: **any field save, mutation, expected-empty precondition, journal write, cache invalidation, no-reload reconciliation, or exposure of the descriptor token/session or raw targets in the DOM. `overlay-app.js` is untouched.**
- Focused validation: **jsdom 16 tests (11 prior + 5 R2-B: single-select staging, gallery multi-select staging, non-writable notice with no frame, clear-selection, and no control when `wp.media` is unavailable); targeted `lint:visual-editor-media-manager` clean; R1-D read-only invariant updated (staged `wp.media`, still no save) + R2-A focused = 15/252.**
- Full comparison: **718 PHP tests/8,467 assertions with exactly the same six inherited failures (+5 assertions is the updated R1-D invariant; no new regression). Agent docs 54/416/0 (no new surface).**
- Recorded crossing line: **R2-C field-level save (expected-empty precondition, attachment/MIME/cardinality validation, journal/cache, targeted reread, no-reload row/count reconciliation) is the next slice and is not authorized by this slice.**

### R2-C field-level save review stop line

- Production scope: **`MediaAssignmentService` + `POST .../findings/{finding_ref}/assignment` save the staged selection. It re-runs the R2-A revalidation as the expected-empty precondition, mutates through the shared `MutationService` (resolver save, journal/audit, cache invalidation), and rereads via `expandGroup`. The client reconciles the expanded field, the row's missing count, and the scan summary from the reread and marks a fully resolved row in place — with no list/scan reload.**
- Gate satisfied: **a field populated after scan is blocked with `409 media_assignment_stale` and never overwritten; the write target is always the freshly server-resolved descriptor.**
- Focused validation: **`VisualEditorMediaManagerR2CTest` 7 tests/81 assertions (three-family save + reconcile, expected-empty block, non-image rejection, empty-value rejection, stale-generation block); 3 new jsdom cases (19 total): no-reload reconciliation, save conflict retains selection, saving state; R1-D read-only invariant updated; combined Media Manager PHP 41/1,413.**
- Full comparison: **725 PHP tests/8,550 assertions with exactly the same six inherited failures (+7 tests/+83 assertions is R2-C; no new regression). Agent docs 54/417/0 (the new assignment route registered and remapped).**
- Recorded crossing line: **R2-D verified UX states (media modal open, unsaved, save in progress, saved, changed-since-scan, validation error, resolved) is the next slice; R2-E is production hardening. Neither is authorized by this slice.**

### R2 checklist

- [x] Finding-to-descriptor bridge (R2-A: server-authoritative, revalidated, no client target authority)
- [x] Existing image/media-frame integration reused (R2-B: native `wp.media`, staged unsaved, no write)
- [x] Field-level save through the existing family contract (R2-C, expected-empty enforced)
- [x] Journal/audit and cache invalidation on save (R2-C, via `MutationService`)
- [x] Targeted finding revalidation and no-reload row/count reconciliation (R2-C)
- [ ] Existing gallery media-frame integration reused
- [ ] Upload capability behavior
- [ ] Draft/unsaved selection state
- [ ] Featured-image save
- [ ] ACF image save
- [ ] ACF gallery save
- [ ] Expected-old-value/stale conflict behavior
- [ ] Journal/audit/cache integration
- [ ] Targeted finding revalidation
- [ ] Counts/row update behavior
- [x] Same-entity `Save Row` deferred from initial R2 (D-010)
- [ ] No row-save endpoint/action introduced in initial R2
- [ ] Cross-entity bulk save absent
- [ ] Media modal focus/layering QA
- [ ] Feature flag/fallback/release notes

## R3 Registry-backed Brand Control Center

- [ ] Registry/provider contract
- [ ] Validation/duplicates/failure handling
- [ ] Shared Globals compatibility provider
- [ ] Minimal center
- [ ] Fresh descriptor opening
- [ ] Existing main panel/save behavior
- [ ] Diagnostics/tests/fallback

## R4 Expanded Global & Brand Control Center

- [ ] Categories/grouping/sorting
- [ ] Search/filters
- [ ] Safe value summaries/statuses
- [ ] Empty/loading/error/unavailable/inspect-only states
- [ ] Claude mockup and design decision record
- [ ] Responsive/accessibility/performance
- [ ] Shared Globals fallback

## R5 ACF option-family support

### R5.1

- [ ] text
- [ ] textarea
- [ ] url
- [ ] email
- [ ] number
- [ ] range

### R5.2

- [ ] checkbox
- [ ] select
- [ ] radio
- [ ] button_group
- [ ] link
- [ ] wysiwyg

### R5.3

- [ ] image
- [ ] gallery

### R5.4

- [ ] post_object
- [ ] relationship
- [ ] taxonomy

For every family:

- [ ] exact field key/canonical option owner
- [ ] options read/write/stale tests
- [ ] current family editor reused
- [ ] acknowledgement/journal/cache/reload
- [ ] regression across current owner types
- [ ] unsupported configurations fail closed

## R6 Frontend Site Manager Workspace

- [ ] Current Go To Object behavior mapped/reused
- [ ] Object policy and bounded navigation
- [ ] Desktop persistent drawer
- [ ] **Tabled by D-036:** small-screen slide-over and additional responsive/mobile workspace work
- [ ] Current object and routes
- [ ] Review Fields integration
- [ ] Media Manager integration
- [ ] Global & Brand Control Center integration
- [ ] Main panel coexistence
- [ ] Mode preservation
- [ ] Focus/Escape/layering
- [ ] Claude mockup and design decision record
- [ ] Fallback to current toolbar/popovers
- [ ] Performance/accessibility/builder isolation

## Per-release sign-off template

```text
Release:
Status:
Branch/commit/change scope:
Feature flag:
Automated test commands/results:
Browser/device QA:
Accessibility QA:
Performance evidence:
Security review:
Known limitations:
Rollback procedure:
Approved by/date:
```
