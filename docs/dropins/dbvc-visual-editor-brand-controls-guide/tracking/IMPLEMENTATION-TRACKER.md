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
| R1 | Media Manager scan/report | In development | Codex R1-A/R1-B/R1-C/R1-D/R1-E / 2026-08-16 | `MediaManager/`; `MediaManagerController`; Media Manager R1 tests; corrected R1-D fixture/mockup; E-030/E-032/E-035/E-038-E-049 | R1-A through R1-D complete for review; R1-E keyboard/reduced-motion, synthetic scale/no-auto-scan, automated semantic/fallback, and Chromium/Firefox/WebKit engine hardening complete; authenticated runtime, complete candidate-scan scale, real AT, real Safari, and a completing aggregate lint run remain |
| R2 | Media Manager direct remediation | Planned | Unassigned | Decisions D-010, D-020; coverage matrix | R1 read model stable; fresh descriptor/expected-empty contract approved |
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

## R2 Media Manager remediation

- [ ] Finding-to-descriptor bridge
- [ ] Existing image/media-frame integration reused
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
