# R1 — Frontend Media Manager Scan and Report

## Production outcome

R1 ships a production-ready, frontend Media Manager that can run a bounded scan of eligible live entities and display a complete, searchable, filterable, scrollable report of missing image assignments.

R1 is read-oriented. It does not assign or upload media yet. It proves object/field eligibility, scan performance, finding identity, permissions, table UX, and descriptor-hydration boundaries before mutation is added in R2.

## User problem

Editors need a single place to discover which published pages, posts, CPTs, and terms have empty featured-image, ACF image, or ACF gallery fields.

## Primary personas

- Client content editor
- Agency content loader
- Launch/onboarding specialist
- Agency QA reviewer
- Site administrator

## Existing surfaces extended

- Reserved toolbar overflow or the smallest verified Visual Editor entry point
- Existing frontend panel/popover shell
- Go To Object/object permission logic
- Descriptor sessions for future expansion
- Current loading/status/error renderer

## In scope

### Scan engine

- User-triggered start/refresh
- Eligible pages/posts/public CPTs/public terms
- Published/live default scope
- Native featured image
- ACF image
- ACF gallery
- Unconditional top-level and deterministic group-only ACF image/gallery paths
- Bounded request batches or existing safe scanner
- User/site-bound short-lived snapshot
- Progress, completion, expiry, and retry states

### Findings table

- Large popup/drawer over the live frontend
- Internal scroll region
- Sticky filters/header where compatible
- Search
- Entity-family filters
- Field-family filters
- Deterministic sorting
- Pagination/cursor/virtualization
- Summary counts
- Collapsed entity rows
- Explicit row expansion
- Lazy row hydration and revalidation
- Inspect-only/changed/unavailable states

### Mockup workflow

- Verified data/action/state brief from current R1 architecture
- Claude Code static HTML/CSS mockup using the included concept image only as visual direction
- Accepted/adapted/rejected design record
- Production implementation in current DBVC components and scoped CSS

## Out of scope

- Selecting, uploading, or assigning attachments
- Saving fields or rows
- Cross-entity selection/bulk operations
- Full Media Health scans
- Files, oEmbed, video, alt text, broken files, optimization, duplicates
- Users/options as scan owners
- Draft/private content
- Repeater, flexible-content, mixed nested ancestry, and conditional unknowns in the initial slice
- Option-owned and user-owned fields
- Custom persistent scan tables unless R0 proves necessity
- New generalized descriptor/mutation system

## Implementation slices

### R1-A — Scan policy and field catalog

- Reuse current object exclusion/capability policy conventions in a scan-specific eligibility service.
- Implement the narrow applicable image/gallery catalog from active ACF definitions and exact location visibility; reuse resolver normalization rather than another domain's runtime catalog.
- Add provider/filter seam only when needed for current code or Vertical evidence.
- Add unit coverage for post types, taxonomies, permissions, statuses, field types, location rules, and exclusions.

**Gate:** no arbitrary meta or unauthorized entity can enter the candidate set.

### R1-B — Bounded scanner and snapshot

- Add minimal request-batched scanning; R0 found no scanner/session contract that can be reused wholesale.
- Use a separate ephemeral user/blog-bound snapshot; do not repurpose `EditableRegistry`.
- Add snapshot versioning, expiry, progress, retry, cancellation/abandon behavior.
- Add deterministic finding/group identity.
- Measure representative chunk cost and memory.

**Gate:** ordinary frontend loads remain unaffected and a large scan cannot monopolize one request.

### R1-C — Read model and list API

- Add compact result-group records.
- Add summary counts, search, filter, sort, and bounded result retrieval.
- Do not include full descriptors.
- Add row-expansion request that rechecks entity/field state and returns safe details/statuses.

**Gate:** a snapshot reference cannot be used to target arbitrary fields or owners.

**Implemented comparison (2026-08-15):** `MediaScanReadModel` and `MediaManagerController` now provide protected active-mode start/latest/list/next/retry/cancel/group routes. Explicit scan and group reads require matching generation/revision values; latest is the deliberate resume exception. List work is bounded to 1–50 records plus one eligibility lookahead and returns no exact filtered total. Expansion resolves only a server-owned snapshot group, rescans that entity, and reports safe current statuses without descriptor or mutation authority. Focused R1-C coverage passed 6 tests/417 assertions; combined current Visual Editor focus passed 23/603.

### R1-D — Frontend table and verified mockup translation

- Freeze the verified view model and actions.
- Use `ui-ux/MEDIA-MANAGER-CLAUDE-MOCKUP-HANDOFF.md`.
- Require scan, results, expanded row, stale, error, loading, large-list, and responsive states.
- Review the returned static mockup against DBVC layering/accessibility constraints.
- Implement the panel/table using current UI patterns.
- Preserve table scroll and filters during passive progress updates.
- Add accessible expansion and status handling.
- Integrate toolbar/overflow entry and fallback.
- Add no new Media Library/editor enqueue in R1; active Visual Editor mode already loads those assets.

**Gate:** no mockup or production action exceeds R1’s read-only scope.

**Current viewport priority (2026-08-16):** production implementation and review now target normal laptop and desktop use. Preserve the responsive protections already implemented in D4A and production slice 1, and do not introduce a regression that makes the existing shell unusable at narrower widths. Additional mobile-friendly layouts, responsive cards, touch refinements, real-handset optimization, and mobile-specific QA are tabled and are not blockers for the remaining R1 slices unless explicitly reauthorized.

**Delivery/review checkpoint (2026-08-15):** Claude completed D1-D4 and delivered the read-only static mockup under `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/`. Codex accepts its visual hierarchy, strict R1 boundary, scoped naming/tokens, server-driven query direction, opaque-reference rows, cursor paging, expansion pattern, and single-markup responsive approach. No production frontend asset, route, descriptor, Media Library action, or mutation was added.

The mockup is **accepted with required adaptations**, not approved for verbatim production translation. D4A completed the following corrections on 2026-08-16:

1. Correct the safe fixture so its declared sort, page limit/items, `hasMore`/cursor, scan-time metadata, and expanded-row `fields[]` projections agree with `MediaScanReadModel`.
2. Add an explicit client view-state adapter for backend states `scanning`, `failed`, `canceled`, `complete`, and `invalidated`; treat a latest-scan 404 as “No current scan” because the current contract does not distinguish never-created from expired.
3. Bind Cancel and Retry to `canCancel` and `canRetry`, not to invented state assumptions.
4. Separate row-level provider-unavailable projections from expansion request failures such as expired, unpublished, deleted, out-of-scope, or otherwise unavailable groups.
5. Replace the desktop-only pinned-stack assumption on short/mobile viewports. At 390x844 the drawer clips sort, legend, results, and footer because its non-result stack exceeds the available height. Use one reachable scroll body on short/mobile viewports while keeping focus and toolbar layering intact.
6. Correct singular announcements, pending-versus-revalidated copy, practical touch targets, and `aria-sort`/rendered-order agreement.
7. Re-run targeted structural, 1440x900, 900-class, 390x844, 375x667, and 320x568 checks; refresh screenshots and limitations without claiming axe, assistive-technology, cross-browser, or real-device validation unless actually run.

Codex accepted D4A as qualified static visual direction after the corrected fixture/state handback, short/mobile reachability evidence, seven true-viewport screenshots, and documentation reconciliation. The default-view axe pass reported 33 passing rule groups and one serious 12-node contrast violation inherited from the shared production subtle-text token. That token must be corrected and regression-tested separately; D4A is not production accessibility sign-off.

Translate the accepted design in small production slices:

1. Feature-gated toolbar entry, shell, scoped stylesheet, strings/configuration, open/close/focus lifecycle, and Builder isolation.
2. Media Manager API/state controller for latest/start/next/retry/cancel/list, generation/revision identity, stale response suppression, and explicit state/error mapping.
3. Server-driven laptop/desktop result table for search/filter/sort, cursor append, loading/empty/error states, and preserved query/scroll state. Responsive cards and additional mobile layouts are tabled.
4. Lazy row expansion with pending/current/changed/resolved-or-changed/provider-unavailable/request-error states and accessible focus/live announcements.
5. R1-E security, authenticated-runtime, scale, accessibility, Builder, fallback, and release gates. Initial keyboard-focus and reduced-motion hardening is complete in isolated coverage.

**Production slice 1 checkpoint (2026-08-16):** the default-off feature-gated toolbar entry, separately scoped shell assets, localized strings/configuration, open/close/Escape/focus lifecycle, and inherited Builder isolation are implemented for review. Focused PHP coverage and an isolated four-viewport browser/axe harness pass. The slice calls no scan route, renders no server result, issues no descriptor, opens no Media Library, and mutates no content.

**Production slice 2 checkpoint (2026-08-16):** the shared frontend API client now wraps latest/start/list/next/retry/cancel with the existing nonce and current generation/revision identity. The Media Manager module rehydrates latest state on first open, normalizes the bounded query, stores only the safe R1-C list projection, maps every backend/request state explicitly, exposes lifecycle controls strictly from scan state plus `canRetry`/`canCancel`, and suppresses out-of-order or older same-generation responses. It renders lifecycle status/actions only—no result row or expansion—and adds no descriptor, Media Library action, content mutation, journal write, or cache invalidation.

**Production slice 3 checkpoint (2026-08-16):** the shell now renders a semantic, internally scrollable laptop/desktop table from only the R1-C safe row projection. Search, entity family, field family, and all six sorts issue bounded server requests; first-page replacement and opaque-cursor append preserve query state, de-duplicate by opaque group reference, and retain append scroll. Scan-wide summary copy does not impersonate a filtered total. Loading, no-scan/no-findings/no-match, list error, append error, and retry states remain explicit. The browser allowlists HTTP(S) frontend links and uses DOM text assignment rather than interpolated row markup.

Focused coverage passes 8 jsdom state/DOM tests and 2 isolated Chromium tests at 1440x900 and 1280x720. The browser pass verifies 15-row first page, append to 25 with preserved scroll, server-driven replacement, request nonce/identity/query/cursor, no expansion controls, bounded shell/document geometry, Escape/focus restoration, and axe WCAG A/AA with zero violations. This is mocked protected-response evidence, not authenticated WordPress runtime or assistive-technology/cross-browser proof.

The full PHP comparison ran 704 tests/7,760 assertions with exactly the same six inherited failure identities. Targeted `wp-scripts` lint passes for the new module/tests with only stale browser-data warnings; the aggregate repository lint command did not complete in the bounded current rerun.

**Production slice 4 checkpoint (2026-08-16):** eligible entity labels are now real expansion buttons with `aria-expanded`/`aria-controls`. One row at a time calls the protected group route with the current opaque scan/generation/revision identity, displays an independent pending state, and renders only normalized safe field label/context/family/message/status projections. Request failures leave the loaded list intact; provider failures remain successful `unavailable` row projections; a collapse or newer row request suppresses any slower response. First-page query replacement clears expansion, while append and passive rerender preserve the internal table scroll.

Focused coverage now passes 10 jsdom state/DOM tests and 4 PHPUnit tests/43 assertions. The permanent 1440x900 and 1280x720 Chromium tests verify the exact group request identity, all current/changed/resolved field presentations, row ARIA/region behavior, native Enter/Space expansion, row-focus continuity through rerenders, reduced-motion suppression, collapse, absence of assignment actions, and axe WCAG A/AA with zero violations in both expanded and collapsed states while retaining the slice-3 paging/geometry/trigger-focus checks. The full PHP comparison immediately before the frontend-only focus/reduced-motion hardening ran 704 tests/7,764 assertions with exactly the inherited six failure identities. Targeted and aggregate repository lint pass with stale Baseline/Browserslist data warnings only. This remains mocked protected-response evidence; authenticated WordPress runtime, assistive technology, cross-browser, large-site, and full release proof are R1-E work.

**Next crossing line:** R1-E hardening and release evidence. Descriptor issuance, native Media Library choose/upload, field saves, journals, cache invalidation, and no-reload finding reconciliation remain R2. Responsive cards and additional mobile work remain tabled.

### R1-E — Hardening and release

- Permission and nonce tests
- Bricks Builder isolation
- Large-site performance
- Accessibility/keyboard QA for the supported laptop/desktop workflow; additional touch/mobile QA is tabled
- Snapshot expiry/retry QA
- Feature flag and rollback
- Release notes and tracking updates

**R1-E scale/no-auto-scan checkpoint (2026-08-16):** focused coverage proves ordinary frontend asset enqueue creates no scan and measures compressed 100/500/2,000-group snapshots through the bounded read model. The 2,000-group combined run returned 50 rows in a 24,833-byte response after 25.425 ms with zero additional WordPress queries; the snapshot occupied 120,475 bytes and the measured allocated-memory delta was 6,291,456 bytes. Complete 2,000-owner candidate traversal/raw ACF reads, authenticated transport/browser integration, assistive technology, and cross-browser proof remain release gates.

**R1-E automated semantic/fallback checkpoint (2026-08-16):** the dialog references its visible description; the results heading and focusable scroll region have stable names; list/group loading exposes polite atomic status and busy state; and an entity-specific expansion heading persists through loading, success, and request-error presentations. The existing stable live region now announces field-check start, completion counts, and failure. Eleven jsdom tests, 4 R1-D PHP tests/47 assertions, targeted Media Manager lint, and six laptop/desktop Playwright cases across Chromium, Firefox, and WebKit pass; expanded/collapsed axe remains at zero confirmed WCAG A/AA violations. Combined R1-A through R1-E PHP coverage passes 22/685, and the full comparison runs 706/7,820 with the same six inherited failures. The current aggregate repository lint rerun did not complete, so only the targeted lint pass is current evidence. Default-off asset/entry gates and no-auto-scan proof remain the rollback contract and no content/data migration exists. Real screen-reader/assistive-technology, authenticated WordPress, real Safari, and complete candidate traversal/raw-read evidence remain open.

**R1-E closeout checkpoint (2026-08-16):** focused evidence was re-verified before trusting older counts (11/11 jsdom, targeted Media Manager lint, 6/6 Chromium/Firefox/WebKit Playwright cases). Runtime provenance was refreshed read-only: active site `dbvc-codexchanges.local`, `bricks`+`vertical` theme, DBVC plugin active, Media Manager option ON, and the persistent Visual Editor option now ON (a recorded drift from the prior OFF state, left unchanged). The live REST permission gate was proven unauthenticated — all seven Media Manager routes are registered and `scans/latest`, tampered scan/group refs, and POST `scans` each return HTTP 401 `rest_forbidden` before resource resolution and create no snapshot. A new deterministic, non-mutating traversal test drives the real provider/scanner/store pipeline to completion across 100 and 300 live owners: every owner is enumerated exactly once and raw-read into three empty findings, raw ACF reads stay constant at 2 per owner, applicability is evaluated once per candidate, each chunk stays at <=50 candidates and <=1 source query, and per-candidate DB cost falls ~1.25 -> ~0.83 as owners triple — no field-definition/capability/permalink N+1. Focused R1-A-R1-E passes 23 tests/1,127 assertions; the full PHP comparison runs 707 tests/8,262 assertions with exactly the same six inherited failures (no new regression). One bounded aggregate `npm run lint` attempt ran ~11 minutes without completing and was stopped. Authenticated active-site REST/table **data** behavior (no already-authorized session available), real assistive technology (VoiceOver), real Safari (the WebKit engine is not Safari), large-list browser responsiveness, and a completing aggregate JavaScript lint run remain the accepted residual R1-E gates. No descriptor, Media Library, mutation, journal, or cache work was performed.

## Data and safety rules

- Scan records use opaque references.
- Field keys, owner IDs, and nested paths remain server-authoritative.
- List time and expansion time both enforce permissions.
- Expansion rechecks whether fields remain empty.
- A resolved field disappears or becomes changed/resolved; it does not remain falsely actionable.
- No mutation endpoint is introduced in R1.

## Performance requirements

- No scan on ordinary page load.
- No full descriptors in collapsed results.
- No attachment thumbnails/metadata until needed.
- Bounded server work per request.
- Complete results available through pagination/virtualization.
- Stale client responses ignored.
- UI remains interactive during scan/progress.

## Acceptance criteria

### Scan

- [ ] Authorized user can start and refresh a scan from the frontend.
- [x] Scan is bounded and progress-aware at the server contract.
- [x] Eligible public post types/taxonomies are discovered rather than hardcoded.
- [x] Only supported featured-image, ACF image, and ACF gallery sources are checked.
- [x] Unauthorized/private entities do not appear in scanner/list/row contract tests.
- [x] Snapshot expiry and retry work at the server contract.

### Results

- [ ] Summary counts match the completed snapshot.
- [x] Search and entity/field filters work through the bounded server read model.
- [ ] Large result sets do not freeze the browser.
- [ ] Table header/controls remain usable while scrolling.
- [x] Each server row identifies safe entity label/family/type, permitted frontend location, and missing count.
- [x] Row expansion is accessible and lazy in the frontend UI.

### Revalidation

- [x] Expanded rows recheck current owner, capability, field definition, and empty state.
- [x] Fields resolved after scan are not presented as writable missing assignments.
- [x] Unsupported nested/conditional paths fail closed or are labeled honestly.

### Compatibility

- [ ] Existing Visual Editor toolbar, Review Fields, Shared Globals, main panel, and saves are unchanged.
- [x] R1 introduces no new Media Library/editor enqueue in source/PHP contract coverage and records the existing eager asset baseline honestly.
- [x] Bricks Builder asset isolation is retained in source/PHP contract coverage; authenticated Builder runtime remains open.
- [x] Disabling Media Manager restores prior frontend behavior with no content/data migration in source/PHP contract coverage.

## Rollback

1. Disable the Media Manager feature/entry through the current feature mechanism.
2. Stop issuing scan requests.
3. Allow expiring snapshots/transients to age out or clear them through a documented safe cleanup path.
4. Revert code without modifying WordPress/ACF content.

R1 creates no content mutation and should require no data rollback.

## Known validation baseline before implementation

- Full PHP suite: 6 deterministic failures out of 684 tests.
- Full repository JavaScript lint: did not complete.

R1-A comparison: the focused contract passed 5 tests/65 assertions, the combined Visual Editor focus passed 12/80, and the full suite retained the same six failure identities across 689 tests/7,186 assertions. R1-A changed no JavaScript, so no newer full-lint result exists.

R1-B comparison: the focused scanner/snapshot contract passed 5 tests/106 assertions; combined R1-A/R1-B passed 10/171; combined with current Visual Editor instrumentation passed 17/186. A representative 20-entity/60-finding test chunk measured 4.661 ms, 24 queries, zero additional allocated/peak memory pages at PHP's reported granularity, and a 4,983-byte compressed snapshot. The clean full comparison ran 694 tests/7,302 assertions with exactly the inherited six failures; agent docs passed 54 records/408 surfaces/0 unmapped. R1-B changed no JavaScript.

R1-C comparison: the protected read/list/row contract passed 6 tests/417 assertions; combined R1-A/R1-B/R1-C and current Visual Editor instrumentation passed 23/603. The full PHP comparison ran 700 tests/7,723 assertions with exactly the inherited six failures. It adds seven protected REST routes but no frontend assets, descriptors, or mutation. The final agent-document comparison is recorded in the tracker/evidence log. R1-C changed no JavaScript.

R1-D production shell comparison: the default-off asset/Builder gates and read-only shell contract passed 4 tests/32 assertions. The full PHP comparison ran 704 tests/7,755 assertions with exactly the inherited six failures. Isolated Chromium geometry/focus/axe checks passed at four viewports, including 320x568; authenticated WordPress runtime is not claimed. Repository `npm run lint` completed with stale browser-data warnings, while the raw Visual Editor assets—outside that configured lint target—passed direct Node syntax checks.

R1-E synthetic scale/no-auto-scan and semantic comparison: the scale contract passed 2 tests/50 assertions; the frontend semantic controller passed 11 jsdom tests; six Playwright cases pass across Chromium, Firefox, and WebKit; the shell/static contract passed 4 tests/47 assertions; and combined R1-A through R1-E passed 22/685. Snapshot/read/payload measurements cover 100/500/2,000 groups with a fixed 50-row response ceiling. The latest full PHP comparison ran 706 tests/7,820 assertions with the same six inherited failures. Complete candidate traversal/raw reads, authenticated transport, real assistive technology, real Safari, and a completing aggregate repository lint run remain open.

R1-E closeout comparison (2026-08-16): a new deterministic non-mutating candidate-traversal test was added to `VisualEditorMediaManagerR1ETest`, raising focused R1-A-R1-E to 23 tests/1,127 assertions and the full PHP comparison to 707 tests/8,262 assertions with exactly the same six inherited failures (+1 test/+442 assertions is the traversal test only; no new regression). It measures complete traversal across 100/300 live owners with no field-definition/capability/permalink N+1. The live REST permission gate was proven unauthenticated (401 before resolution on all probed routes; no snapshot created). 11/11 jsdom, targeted lint, and 6/6 Chromium/Firefox/WebKit Playwright cases were re-verified. One bounded aggregate `npm run lint` attempt ran ~11 minutes without completing and was stopped. Authenticated REST/table data behavior, real AT, real Safari, large-list responsiveness, and aggregate lint completion remain open.

R1 must add focused tests/lint for touched code and prove it introduces no additional PHP failures. A release cannot claim repository-wide lint success unless that command completes.
