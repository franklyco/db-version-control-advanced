# Test, QA, and Release Gates

## Universal gate

Every release must pass the repository’s established automated/static checks plus release-specific browser and accessibility QA. Record exact commands and results; do not write “tests pass” without evidence.

**Viewport scope (D-036):** current release gates target ordinary laptop/desktop workflows. Preserve existing responsive protections, but do not block a release on new mobile layouts, responsive cards/slide-overs, touch refinements, handset optimization, or mobile-specific QA unless the user explicitly reauthorizes that work.

## Reconciled inherited baseline

At clean, synchronized commit `5db4b40`:

- the focused Visual Editor instrumentation check passed 7 tests and 15 assertions;
- the full PHP suite had 6 deterministic failures out of 684 tests;
- full repository JavaScript lint did not complete.

This baseline is not release approval. A phase must run focused tests and static checks for touched code, identify regressions separately from the six inherited PHP failures, and avoid claiming repository-wide lint success unless the full command completes. When a phase adds or changes public REST, settings, add-on, hook, or safety surfaces, run `composer agent-docs:check` as required by repository maintenance rules.

R1-A comparison on 2026-08-15: focused coverage passed 5 tests/65 assertions (12/80 with existing Visual Editor instrumentation), and the full PHP suite ran 689 tests/7,186 assertions with the same six inherited failure identities. No JavaScript changed, so the incomplete full-lint baseline was not rerun or promoted.

R1-B focused comparison on 2026-08-15: scanner/snapshot coverage passed 5 tests/106 assertions; combined R1-A/R1-B passed 10/171; combined with existing Visual Editor instrumentation passed 17/186. A 20-entity/60-finding isolated test chunk measured 4.661 ms, 24 queries, zero additional allocated/peak memory pages at reported granularity, and a 4,983-byte compressed snapshot. The clean full comparison ran 694 tests/7,302 assertions with exactly the inherited six failures after one transient extra failure passed alone and with its full class. Agent docs passed 54 records/408 surfaces/0 unmapped. R1-B changed no JavaScript; this remains an in-development slice, not the R1 release gate.

R1-C focused comparison on 2026-08-15: protected route/read-model/row coverage passed 6 tests/417 assertions; combined R1-A/R1-B/R1-C with existing Visual Editor instrumentation passed 23/603. Coverage includes feature/base-capability/active-mode gates, cross-user isolation, bounded query validation, opaque cursor/generation/revision tampering, list-time object permission loss, current row missing/changed/resolved/unpublished states, provider failure, safe payload omission, and real add-on route composition. The full PHP comparison ran 700 tests/7,723 assertions with exactly the inherited six failures. R1-C changes no JavaScript; browser/accessibility/Builder/scale gates remain open.

R1-D plus initial R1-E focused comparison on 2026-08-16: the production shell/table/expansion contract passed 4 tests/43 assertions; the controller passed 10 jsdom tests; and isolated Chromium at 1440x900 and 1280x720 passed exact group identity, loading/current/changed/resolved/provider-unavailable presentations, Enter/Space disclosure operation, row-focus continuity, reduced-motion suppression, bounded paging/replacement geometry, trigger focus, and expanded/collapsed axe WCAG A/AA with zero violations. Targeted and aggregate repository lint complete with stale dependency-data warnings only. The full PHP comparison immediately before the frontend-only focus/reduced-motion correction ran 704 tests/7,764 assertions with exactly the inherited six failures. Authenticated WordPress runtime, large-site profiling, assistive technology, and cross-browser evidence remain open.

R1-E scale/no-auto-scan comparison on 2026-08-16: the new contract passed 2 tests/50 assertions and the combined R1-A through R1-E focus passed 22/681. Ordinary frontend asset enqueue created no latest scan. Synthetic 100/500/2,000-group compressed snapshots returned only the bounded 50-row result page; the combined 2,000-group run measured 25.425 ms, zero additional WordPress queries, a 6,291,456-byte allocated-memory delta, 120,475 stored bytes, and a 24,833-byte response. The full PHP comparison ran 706 tests/7,816 assertions with exactly the inherited six failures. This is snapshot/read/payload proof, not complete candidate traversal, raw ACF scanning, authenticated REST/browser transport, or a production SLO.

R1-E automated semantic/fallback comparison on 2026-08-16: 11 jsdom tests prove the named dialog/results/scroll/expanded regions, explicit list/group loading and busy semantics, stable entity-specific expansion headings, and polite field-check start/completion/failure updates. Four R1-D PHP tests/47 assertions retain the default-off asset/entry, Builder, no-write, and semantic source seams. Six Playwright cases pass at 1440x900 and 1280x720 across Chromium, Firefox, and WebKit with the new semantics plus the existing keyboard/focus/reduced-motion/axe checks. Combined R1-A through R1-E passes 22/685; the full PHP comparison runs 706/7,820 with the same six inherited failures. Targeted Media Manager lint passes. The latest aggregate repository lint rerun did not complete and is not a current pass. Automated semantics/axe/browser engines do not replace real assistive-technology, authenticated WordPress, or real Safari evidence.

R1-E closeout comparison on 2026-08-16: focused evidence was re-verified before trusting older counts — 11/11 jsdom, targeted Media Manager lint, and 6/6 Chromium/Firefox/WebKit Playwright cases. Runtime provenance was refreshed read-only and the live REST permission gate was proven unauthenticated: all seven Media Manager routes are registered and `scans/latest`, tampered scan/group refs, and POST `scans` each return HTTP 401 `rest_forbidden` before resource resolution, creating no snapshot (E-050). A new deterministic non-mutating traversal test drives the real provider/scanner/store pipeline to completion across 100 and 300 live owners, proving complete enumeration, constant 2 raw ACF reads per owner, one applicability evaluation per candidate, <=50 candidates and <=1 source query per chunk, and per-candidate DB cost falling ~1.25 -> ~0.83 as owners triple — no field-definition/capability/permalink N+1 (E-051). Focused R1-A-R1-E passes 23 tests/1,127 assertions and the full PHP comparison runs 707 tests/8,262 assertions with exactly the same six inherited failures (E-052). One bounded aggregate `npm run lint` attempt ran ~11 minutes without completing and was stopped. Authenticated active-site REST/table **data** behavior, real assistive technology, real Safari (the WebKit engine is not Safari), large-list responsiveness, and a completing aggregate lint run remain open.

## Test layers

Use current tooling where available:

- PHP unit/integration tests;
- WordPress/ACF integration fixtures;
- JavaScript unit/component tests;
- API/AJAX/REST handler tests;
- browser/E2E tests;
- manual frontend QA;
- keyboard/accessibility review for the supported laptop/desktop workflow; touch/mobile review only after D-036 is reauthorized;
- performance/query/payload profiling;
- Bricks Builder isolation checks.

## R0 gate

- [x] Working tree and active changes documented.
- [x] Actual scanner/catalog/descriptor extension points mapped.
- [x] Media entity/field coverage matrix completed.
- [x] Existing image/gallery/featured-image save trace verified.
- [x] Representative site counts/performance baseline recorded.
- [x] Shared Globals/option matrix completed.
- [x] Corrected R1/R2 plan, risks, decisions, and rollback documented.

## R1 Media Manager scan/report gate

### Automated

- [x] Candidate object policy for pages/posts/public CPTs/terms (R1-A/R1-B server contract).
- [x] Exclusions for private/internal objects (R1-A policy).
- [x] Object-specific permission filtering (R1-A scan plus R1-C list/row rechecks).
- [x] Featured-image eligibility (R1-A policy and R1-B scanner).
- [x] ACF image/gallery field applicability and empty detection (R1-A catalog/classifier and R1-B scanner).
- [x] Unconditional top-level and deterministic group-only ACF paths (R1-A/R1-B contract).
- [x] Repeater/flexible/mixed paths and conditional unknowns are excluded and counted honestly (R1-A catalog tests).
- [x] Scan start/chunk/progress/complete/expire/retry (R1-B internal contract).
- [x] Duplicate/stale chunk handling (R1-B internal contract).
- [x] Snapshot user/site isolation (R1-B store and R1-C controller boundaries).
- [x] Search/filter/sort/pagination (R1-C server read model).
- [x] Opaque reference validation/tampering (R1-C route/read-model contract).
- [x] Row hydration rechecks current state (R1-C safe status hydration; no descriptor).
- [x] Provider/ACF unavailable states (R1-C safe unavailable response).
- [x] Live active-site unauthenticated REST auth enforcement: all seven routes registered; `scans/latest`, tampered scan/group refs, and POST `scans` return HTTP 401 `rest_forbidden` before resolution and create no snapshot (E-050); authenticated data behavior open.

### Browser/manual

- [x] Entry point and close/focus behavior in focused mocked-response laptop/desktop coverage; authenticated runtime remains open.
- [x] No-scan, scanning, partial/complete, empty, no-results, and list/append error states in focused state/DOM coverage.
- [x] Sticky header/internal scrolling in focused Chromium coverage.
- [ ] Large list responsiveness.
- [x] Expanded row loading and changed/resolved states in isolated mocked-response coverage; authenticated runtime remains open.
- [x] Filter/search/sort query replacement and append-scroll state preservation.
- [x] Supported 1440x900 and 1280x720 laptop/desktop behavior in isolated Chromium, Firefox, and WebKit engines. Authenticated browser and real Safari coverage remain open; additional tablet/mobile/slide-over refinement and mobile-specific QA are tabled by D-036.
- [x] Keyboard expansion and visible focus in isolated laptop/desktop Chromium; assistive-technology review remains open.
- [x] Automated screen-reader status/heading semantics for dialog, results scroll, loading/busy, expanded-row heading, and field-check announcements; real assistive-technology review remains open.
- [x] No new R1 Media Library/editor enqueue in source/PHP contract coverage; existing active-mode eager loading is measured/documented.
- [x] Bricks Builder asset isolation in source/PHP contract coverage; authenticated Builder runtime remains open.

### Performance

- [x] Ordinary frontend asset enqueue creates no scan in isolated WordPress coverage; authenticated page-load observation remains part of runtime QA.
- [x] Bounded per-request scan work verified by the R1-B candidate/chunk contract.
- [x] Synthetic 100/500/2,000-group snapshot storage, server sort/read projection, and response payload measured.
- [x] Complete candidate traversal/raw-read measured against live fixtures at representative tiers (100/300 owners, E-051); authenticated transport behavior remains open.
- [x] Result payload and DOM row counts remain bounded in synthetic server and isolated browser coverage; authenticated runtime remains open.
- [x] No obvious N+1 field-definition/capability/permalink pattern: per-candidate DB cost falls ~1.25 -> ~0.83 as owners triple, raw reads constant at 2/owner, applicability evaluated once per candidate (E-051).

## R2 Media Manager remediation gate

R2-A comparison on 2026-08-16: the `MediaFindingDescriptorBridge` and its protected finding-descriptor route exchange one opaque finding for one fresh standard descriptor after full snapshot/owner/capability/applicability/family/empty revalidation, expose only opaque token/session ids and safe status, and stop before Media Library selection and mutation. Focused coverage passes 11 tests/200 assertions across the three writable families, single-resolver routing, no-raw-target projection, user-bound isolation, and the tamper/expiry/stale/format/populated/changed/unpublished/deleted fail-closed cases. The full comparison ran 718 tests/8,462 assertions with the same six inherited failures; agent docs pass 54 records/416 surfaces/0 unmapped. Media Library, upload, staged selection, and save remain R2-B/R2-C.

R2-B comparison on 2026-08-16: the detail panel now opens the native `wp.media` frame from the R2-A descriptor — single-select for featured/ACF image, multi-select for ACF gallery — and stages an unsaved selection with an `Unsaved selection` badge, preview, and `Replace`/`Clear`. It reuses `overlay-app.js`'s standard frame config, gates on `supportsWpMedia`, surfaces a notice (no frame) for non-writable descriptors, and performs no save/mutation/journal/cache. jsdom passes 16 tests (5 new R2-B); the R1-D read-only invariant was updated to allow staged, unsaved `wp.media`; full suite 718/8,467 with the same six inherited failures; agent docs 54/416/0. Field save remains R2-C.

### Automated

- [x] Fresh descriptor issuance from finding reference (R2-A mints the descriptor; R2-B hydrates the media frame from it).
- [x] Featured/ACF image findings open single-image selection; ACF gallery opens ordered multi-image selection (R2-B).
- [x] Upload tab availability follows the WordPress `upload_files` capability via native `wp.media` (R2-B; no custom uploader).
- [x] Selected media is visibly unsaved until save; Media Manager stays open behind the core modal and Escape/layering is preserved (R2-B).
- [x] Field populated after scan blocks the write with `409 media_assignment_stale` and does not overwrite it (R2-C, proven by `VisualEditorMediaManagerR2CTest`).
- [x] Every save uses a freshly server-resolved descriptor and the existing family contract; attachment MIME/type and cardinality validated; non-image/empty rejected without a write (R2-C).
- [x] Every successful assignment is journaled/audited and relevant caches invalidated via `MutationService` (R2-C).
- [x] A successful save is followed by a canonical reread; resolved field/row counts update and a fully resolved row is marked in place without a table reload (R2-C).
- [x] Entity deleted/unpublished/permission changed returns `unavailable` without a descriptor (R2-A).
- [x] Field definition/path changed reported as `changed`/`resolved` without a descriptor (R2-A).

R2-E1 comparison on 2026-08-16: journal/cache verification (the first R2-E sub-slice; laptop/desktop browser+keyboard QA is deferred per D-049). `VisualEditorMediaManagerR2ETest` proves a successful assignment records a `completed` change-journal item (correct resolver + new value), fires the audit event once with the empty old value and the new attachment, and invalidates the correct post cache once; a failed save records a `failed` journal item and fires neither the audit nor the cache hook. 3 tests/39 assertions; combined Media Manager PHP 45/1,468.

R2-F comparison on 2026-08-17: all three R2-F slices (entity media inventory, thumbnail presentation, gated replace) are implemented. Slice 1 lists populated fields as `assigned` with a sanitized preview and no raw-target leak; Slice 2 renders a left-aligned lazy square thumbnail per field (accent placeholder for empty, `+{count}` gallery badge); Slice 3 replaces a populated image/gallery through a dedicated `.../replacement` endpoint that enforces an expected-current-value fingerprint precondition (`hash_equals`), fails closed with `409 media_replace_stale`/`media_replace_not_populated` (or `400 media_replace_value_ref_invalid`), reuses the audited `MutationService` pipeline, never deletes attachments, and reconciles in place with no table reload. Coverage: `VisualEditorMediaManagerR2FTest` 3 PHP tests/29 assertions + `VisualEditorMediaManagerR2FReplaceTest` 6 PHP tests/62 assertions + 5 R2-F jsdom cases (28 total). The R1-D read-only invariant is extended to assert the distinct gated replace endpoint and still forbids `fetch(`/`.save(`/composite-save. Full suite 738/8,705 with the same six inherited failures; media-manager lint clean; agent docs 54/418/0 (the new `/replacement` route is registered and mapped). Real-browser assign/replace/upload QA is the residual R2-F gate (D-049).

R2-F automated gates:

- [x] Populated fields listed with a sanitized preview only; top-level list/counts unchanged; no raw-target leak (Slice 1, `VisualEditorMediaManagerR2FTest`).
- [x] Left-aligned lazy thumbnail per field with an accent placeholder for empty fields and a gallery count badge (Slice 2, jsdom).
- [x] Populated image/gallery fields offer a replace control gated on the server `valueRef` (Slice 3, jsdom).
- [x] The expected-current-value precondition runs immediately before the write; a field changed or emptied since it was read is not overwritten (`media_replace_stale`/`media_replace_not_populated`, `VisualEditorMediaManagerR2FReplaceTest`).
- [x] Attachment MIME/type/cardinality validated; malformed value ref rejected; replacing never deletes attachments (Slice 3).
- [x] Every replace is journaled/audited and caches invalidated via `MutationService`; the field/preview reconcile in place without a table reload (Slice 3).

R2-D comparison on 2026-08-16: the nine verified UX states (media-modal-open/opening, image/gallery unsaved, upload-unavailable, save-in-progress, saved/verified, changed-since-scan, validation error, entity resolved marked in place) are implemented as presentation-only refinements — no new REST or mutation surface — with correct ARIA (polite `status` vs assertive `alert`), a `canUpload` bootstrap flag, and the resolved row marked in place. jsdom passes 23 tests (4 new R2-D); the R1-D read-only invariant is intact; the full suite is 726/8,564 with the same six inherited failures; agent docs 54/417/0. Real-browser/assistive-technology verification of the states is the residual gate.

R2-E4 comparison on 2026-08-18: feature isolation + ship-readiness. `VisualEditorMediaManagerR2E4Test` (4 tests/10 assertions) proves the kill switch gates the whole remediation surface: `is_media_manager_enabled()` requires BOTH the Visual Editor master flag and the feature flag, and the REST permission gate `canAccess` (the permission_callback for every route) is closed when the MM flag is off, the master flag is off, the user lacks the base capability, or the request is logged out — open only when all hold. A consolidated release-notes/rollback runbook (`releases/MEDIA-MANAGER-RELEASE-NOTES-AND-ROLLBACK.md`) documents the feature summary, gates, side-effect boundaries, and the non-destructive rollback path. Full suite 755/8,832 with the same six inherited failures; agent docs 54/418/0. R2-E (E1–E4) is complete; real-browser/AT QA remains the standing residual gate (D-049).

R2-E3 comparison on 2026-08-18: repeated-remediation frame lifecycle + surgical DOM patch. `openAssignFrame` previously created a new `wp.media` frame on every open and never disposed it; it now keeps a single active frame torn down on re-open/collapse/group-switch/list-reload/close (`disposeActiveFrame`, RK-011). 4 jsdom cases (34 total) prove at most one live frame across repeated opens (3 opens → 2 disposed), disposal on collapse and close, and that a save patches only the affected row (untouched sibling is the SAME DOM node) with no list/scan reload. R1-D read-only invariant intact; media-manager lint clean; full PHP suite unchanged at 751/8,815 with the same six inherited failures; agent docs 54/418/0. Real-browser memory/listener profiling remains the residual gate (D-049).

R2-E2 comparison on 2026-08-18: security/stale/permission hardening of the end-to-end mutation paths. `VisualEditorMediaManagerR2E2Test` proves `assign()` and `replace()` fail closed AND leave content untouched for a foreign user's user/blog-bound snapshot (`media_scan_expired_or_invalid`), a changed scan revision (`media_scan_revision_changed`), an owner unpublished after the scan/read (`media_assignment_stale` / `media_replace_unavailable` — eligibility is re-checked before every write), and an edit capability revoked mid-flow (surfaces as an eligibility failure before the write). A non-existent attachment id is rejected without writing (`media_assignment_save_failed`). 9 tests/67 assertions; combined Media Manager PHP 67/1,680; full suite 751/8,815 with the same six inherited failures; agent docs 54/418/0.

R2-C comparison on 2026-08-16: field-level save is implemented through the dedicated `.../assignment` endpoint and `MediaAssignmentService`, enforcing the expected-empty precondition immediately before the write, reusing the audited `MutationService` mutation pipeline, and reconciling the finding/row/summary from a targeted reread without a table reload. Focused coverage passes 7 PHP tests/81 assertions and 3 jsdom cases; the full suite is 725/8,550 with the same six inherited failures; agent docs 54/417/0. Real-browser save/upload QA remains the residual gate under the accepted authenticated-runtime limit.
- [x] Featured-image assignment validation. *(R2-C)*
- [ ] ACF image assignment validation and return-format independence. *(R2-C covers ACF image assignment; return-format independence is not yet isolated in the ACF-less env.)*
- [x] Gallery ordered IDs and changed-gallery conflict. *(R2-C ordered IDs; R2-F replace changed-value conflict.)*
- [x] Invalid/deleted/non-image attachment rejection. *(R2-C non-image; R2-E2 non-existent id.)*
- [ ] Upload capability UI/server behavior. *(server `canUpload` flag in R2-D; UI is browser QA.)*
- [x] Journal/audit invocation. *(R2-E1)*
- [x] Cache invalidation. *(R2-E1)*
- [x] Targeted revalidation and counter updates. *(R2-C; owner re-eligibility in R2-E2.)*
- [ ] Same-entity `Save Row` endpoint/action absent from initial R2.
- [ ] Cross-entity bulk endpoint absent.

### Browser/manual

- [ ] Media Library opens in correct single/multiple mode.
- [ ] Upload tab obeys permissions.
- [ ] Panel remains behind modal; outside-click/Escape layering is correct.
- [ ] Focus returns to initiating field.
- [ ] Draft selection is visibly unsaved.
- [ ] Field save success/error/stale states.
- [ ] Gallery management/reorder behavior.
- [ ] Resolved field/row removal preserves scroll/filter context.
- [x] Current-page DOM patch or reload behavior is truthful. *(R2-E3 jsdom: a save patches only the affected row — sibling node preserved — with no list/scan reload; real-browser confirmation deferred.)*
- [x] Repeated remediation does not leak frames/listeners or degrade performance. *(R2-E3 jsdom: single active wp.media frame, prior frames disposed on re-open/collapse/close; real-browser memory profiling deferred, D-049.)*

## R3 Registry gate

- [ ] Provider validation, duplicate handling, absence/failure states.
- [ ] Existing Shared Globals compatibility.
- [ ] Fresh descriptor resolution.
- [ ] No new mutation family.
- [ ] Permission filtering and unregistered storage exclusion.
- [ ] Existing center fallback/feature rollback.

## R4 Expanded center gate

- [ ] Categories/search/filters/sorting/value summaries.
- [ ] Loading/empty/no-results/error/inspect-only/unavailable states.
- [ ] Static mockup decisions recorded.
- [ ] Existing main panel reused.
- [ ] Large registry performance.
- [ ] Responsive/accessibility/CSS isolation.

## R5 ACF option-family point-release gate

For each point release:

- [ ] Exact registered field keys and canonical option owners.
- [ ] Options read/write/stale behavior.
- [ ] Existing family editor reused.
- [ ] Validation/sanitization/return formats.
- [ ] Nested option paths only where proven.
- [ ] Shared acknowledgement, journal, cache, reload.
- [ ] Regression tests for current post/term/user owners.
- [ ] Unsupported configurations remain inspect-only.
- [ ] Independent feature/revert path.

## R6 Site Manager Workspace gate

- [ ] Lazy bounded object navigation.
- [ ] Capability and route filtering.
- [ ] Visual Editor mode preservation.
- [ ] Review Fields, Media Manager, Global & Brand controls integration.
- [ ] No duplicated Media Manager scan/mutation logic.
- [ ] Main panel remains the field editor.
- [ ] Desktop persistent behavior.
- [ ] **Tabled by D-036:** small-screen slide-over behavior and mobile-specific QA until explicitly reauthorized.
- [ ] Focus/Escape/layering with main panel and Media Library.
- [ ] Existing toolbar/Go To Object fallback.
- [ ] Large-site performance and CSS/builder isolation.

## Static mockup gate

Before production UI coding for R1/R2/R4/R6:

- [ ] Codex has verified the actual view model/actions/states.
- [ ] Claude receives no secrets or production client data.
- [ ] Static deliverables cover required states.
- [ ] Mockup CSS is scoped.
- [ ] Added interactions are checked against release scope.
- [ ] Accepted/adapted/rejected decisions are documented.
- [ ] Production code reuses current components; mockup JS is not copied as architecture.

## Release sign-off record

For each release record:

- branch/commit or change scope;
- automated commands and results;
- browser/device matrix;
- accessibility evidence;
- performance measurements;
- security review;
- known limitations;
- feature flag;
- rollback steps;
- approval/date.

Do not begin the next release until this record is complete or the user explicitly reprioritizes with documented risk.
