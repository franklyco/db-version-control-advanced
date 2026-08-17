# Package Update Notes — Version 2.10.0

## R1-E automated screen-reader semantics and fallback checkpoint

Version 2.10.0 keeps the approved laptop/desktop UI and read-only R1 authority unchanged while strengthening the production semantic contract. The Media Manager dialog now references its visible description, the results heading is structural, the internally scrollable table region has a stable accessible name, and list/group loading states expose explicit polite/atomic status and busy state. Every expanded row now owns a persistent entity-specific heading across loading, success, and request-error presentations. The existing stable live region announces field-check start, completion counts, and request failure without adding a second UI or copying backend targets into the browser.

Focused validation passes 11 jsdom tests, 4 R1-D PHP tests/47 assertions, targeted Media Manager lint, and six Playwright cases across Chromium, Firefox, and WebKit at 1440x900 and 1280x720. The engine checks retain Enter/Space, focus, reduced-motion, geometry, paging, and expanded/collapsed axe zero-violation evidence while adding the named-region and completion-announcement contract. Combined R1-A through R1-E PHP coverage passes 22 tests/685 assertions. The full comparison runs 706 tests/7,820 assertions with the same six inherited failures. The latest aggregate repository lint rerun remained active without completing and was stopped; this package therefore records the targeted pass and does not promote a current repository-wide lint pass.

The existing default-off module/Builder gates remain the rollback contract: when Media Manager is disabled its CSS/JavaScript are not enqueued, the toolbar entry is absent, ordinary frontend enqueue creates no scan, and R1 introduces no content/data migration. Automated ARIA/DOM/axe and browser-engine checks are not real screen-reader or real Safari evidence; authenticated WordPress runtime, VoiceOver or equivalent assistive-technology review, real Safari, and complete candidate traversal/raw-read profiling remain open before R1 sign-off or an explicit risk acceptance.

## R1-E scale and no-auto-scan checkpoint

Version 2.9.0 adds an isolated R1-E regression contract without broadening production authority. Ordinary frontend Media Manager asset enqueue leaves the current user's latest scan absent, proving that a normal frontend page load does not start or advance a scan. Synthetic 100/500/2,000-group snapshots then exercise compressed transient storage plus server-side sort and bounded projection while retaining the existing 50-row response ceiling.

`VisualEditorMediaManagerR1ETest` passes 2 tests/50 assertions; combined R1-A through R1-E coverage passes 22 tests/681 assertions. In the combined run, the 2,000-group case measured 25.425 ms, zero additional WordPress queries, a 6,291,456-byte allocated-memory delta, a 120,475-byte stored snapshot, and a 24,833-byte 50-row response. The full PHP comparison runs 706 tests/7,816 assertions with exactly the same six inherited failure identities. This evidence covers snapshot/list/payload scaling only; complete 2,000-owner candidate traversal, raw ACF reads, authenticated REST/browser transport, assistive technology, and cross-browser proof remain open.

## R1-D lazy row-expansion checkpoint

Version 2.8.0 records the fourth bounded R1-D production translation slice. Each eligible entity row now exposes a real expansion button with `aria-expanded`/`aria-controls`; activating it calls the protected single-group route with the current opaque scan, generation, revision, and group references. The server remains authoritative: it rechecks the entity and supported fields, while the browser accepts only a matching safe projection and renders `missing`, `changed`, `resolved_or_changed`, or `unavailable` field status.

Expansion has a request sequence separate from list/lifecycle work, so a group failure leaves the loaded table intact and a slower response cannot reopen a collapsed or superseded row. Only one row is expanded at a time. Unknown payload keys are stripped, HTTP(S) frontend links remain separately allowlisted, and `hydrateDescriptor`/`assignMedia` remain false. R1 still issues no descriptor, opens no Media Library, mutates no content, writes no journal row, and invalidates no content cache.

Focused validation passes 10 jsdom state/DOM tests, 4 PHPUnit tests/43 assertions, targeted and aggregate repository `wp-scripts` lint with only stale Baseline/Browserslist data warnings, direct Node syntax checks, and 2 isolated Chromium tests at 1440x900 and 1280x720. Chromium verifies exact group identity, native Enter/Space expansion, row-focus continuity through loading/success/collapse rerenders, reduced-motion transition suppression, expanded statuses and ARIA, absence of assignment actions, table paging/replacement/scroll behavior, geometry/trigger focus, and axe WCAG A/AA with zero violations in both expanded and collapsed states. The full PHP comparison immediately before the frontend-only focus/reduced-motion hardening ran 704 tests/7,764 assertions with exactly the inherited six failure identities. Browser responses remain mocked; authenticated WordPress runtime, assistive technology, cross-browser, large-site, and full R1-E release proof are not claimed.

R1-E hardening is in progress. After R1 is production-ready, R2-A creates the fresh descriptor bridge, R2-B adds the native WordPress Media Library choose/upload workflow inside the expanded row context, and R2-C persists one supported field at a time then targeted-revalidates and updates/removes the finding without a full Media Manager page/table reload. Users will not have to navigate through the row’s `Open` link for supported fields.

## R1-D server-driven result-table checkpoint

Version 2.7.0 records the third bounded R1-D production translation slice. The default-off Media Manager shell now renders the R1-C safe list projection as a semantic, internally scrollable laptop/desktop table. Search, entity family, field family, and all six allowlisted sorts remain server-driven. First-page requests replace the current rows and reset result scroll; opaque-cursor requests append de-duplicated group references while preserving internal scroll and the active query. Scan-wide counts remain clearly separate from the loaded/filtered page because the read model intentionally returns no exact filtered total.

The production UI includes explicit first-page and append loading/error/retry states, no-current-scan/no-findings/no-match states, safe family/count/timestamp cells, and allowlisted HTTP(S) frontend links. It creates row content through DOM text assignment and places only the opaque group reference on the row. It does not call the group route, expand a row, issue a descriptor, open the Media Library, mutate content, write the journal, invalidate content caches, or add new mobile/responsive behavior.

Focused validation passes 8 jsdom state/DOM tests, 4 PHPUnit tests/37 assertions, targeted `wp-scripts` lint for the new module/tests with stale browser-data warnings only, and 2 isolated Chromium tests at 1440x900 and 1280x720. Chromium verifies a 15-row first page, append to 25 with preserved scroll, server-driven replacement and `aria-sort`, nonce/scan/generation/revision/query/cursor identity, absence of expansion controls, bounded shell/document geometry, Escape/focus restoration, and axe WCAG A/AA with zero violations. The full PHP comparison ran 704 tests/7,760 assertions with exactly the same six inherited failure identities. The aggregate repository lint command remained non-terminating during a bounded rerun, so no full-lint pass is claimed. These tests use mocked protected responses and do not claim authenticated WordPress runtime, assistive-technology, or cross-browser coverage. The next slice is lazy row expansion only; responsive cards/mobile refinements remain tabled by D-036.

## R1-D API/state controller and desktop-priority checkpoint

Version 2.6.0 records the second bounded R1-D production translation slice. `DBVCVisualEditorApi.mediaManager` now wraps the protected latest/start/list/next/retry/cancel routes with the existing REST nonce and current opaque scan/generation/revision identity. The separate Media Manager module checks latest state only on first open, normalizes the bounded R1 query, stores copied safe list/query/pagination state, maps all five backend states plus no-scan/request-error/stale outcomes, exposes Retry/Cancel only when the server permits them, and suppresses slower or older same-generation responses.

The visible shell renders only lifecycle status, progress, and allowed scan actions. It does not render result rows, append cursors, expand groups, hydrate descriptors, open the Media Library, mutate content, write the Visual Editor journal, or invalidate content caches. Five focused jsdom tests cover the route adapter, identities, state map, expiry/conflict outcomes, and response ordering. An isolated Chromium harness at 1440x900 and 1280x720 passed latest/start/next/list state, exact nonce/identity/query, no-row DOM, geometry, Escape/focus, and 13 axe passes/0 violations at each viewport. The full PHP comparison ran 704 tests/7,755 assertions with exactly the same six inherited failures. Repository `npm run lint`, now including all touched Visual Editor JavaScript and the focused state test, completed with stale browser-data warnings only. Agent docs pass with 54 curated records, 415 discovered surfaces, and zero unmapped. Authenticated WordPress runtime is not claimed.

Per user direction D-036, current frontend implementation targets ordinary laptop and desktop use. The responsive protections already proven in D4A and slice 1 remain a regression floor, but additional mobile-friendly layouts, responsive cards/slide-overs, touch refinements, real-device optimization, and mobile-specific mockup/QA work are tabled across the remaining releases until explicitly reauthorized. The next slice is the server-driven laptop/desktop result table; row expansion remains later.

## R1-D4A sign-off, shared contrast correction, and production shell checkpoint

Version 2.5.0 records Codex acceptance of the corrected D4A package as qualified static visual direction. D4A now carries the contract-valid R1-C fixture and expansion projections, explicit backend/view-state mapping, distinct expansion-request and row-provider failure states, one reachable short/mobile scroll model, corrected announcements and count grammar, practical touch targets, 13 state cases, and seven true-viewport screenshots.

The original default-view axe run found one serious 12-node contrast violation inherited from the authoritative `--dbvc-ve-color-text-subtle` production token. Codex corrected the production mix from 56% to 60%, synchronized the static mirror, reran axe against both mockup pages, and regenerated all seven screenshots. The rerun returned 33 passing rule groups and zero violations for `index.html` with 38 contrast nodes still incomplete, plus 22 passing rule groups, zero violations, and zero incomplete results for `states.html`. This is targeted automated evidence, not assistive-technology, cross-browser, real-device, or production accessibility sign-off.

The first R1-D production translation slice is implemented for review. Active Visual Editor mode conditionally enqueues a separate Media Manager CSS/JavaScript module only when the default-off Media Manager setting is enabled. The existing overlay adds only its toolbar trigger/event seam; the module owns a responsive non-modal shell, close/Escape behavior, `aria-expanded`, and trigger-focus restoration. The enabled entry replaces the existing disabled overflow placeholder so the complete toolbar remains inside a 320-pixel viewport. It makes no scan/list request, renders no server result, issues no descriptor, opens no Media Library, and performs no content mutation, journal write, or cache invalidation. Focused coverage passed 4 tests/32 assertions; an isolated Chromium harness passed four viewport/focus/overflow checks and returned zero shell axe violations. The full PHP comparison ran 704 tests/7,755 assertions with exactly the same six inherited failures. Repository `npm run lint` completed with stale browser-data warnings; the raw Visual Editor assets are outside that lint target and passed direct Node syntax checks. Agent docs pass with 54 curated records, 415 discovered surfaces, and zero unmapped.

The next separately reviewed slice is the Media Manager API/state controller for latest/start/next/retry/cancel/list, generation/revision identity, stale-response suppression, and explicit state/error mapping. Table and expansion rendering remain later slices.

## R1-D static-mockup review and D4A correction checkpoint

Version 2.4.2 records delivery and Codex review of the 15-file Claude static mockup under `docs/ui-mockups/dbvc-visual-editor/r1-media-manager/`. The visual hierarchy, strict read-only R1 boundary, `dbvc-ve-*` naming and token strategy, server-driven query model, opaque references, cursor paging, row expansion, and single-markup responsive direction are accepted. The mockup remains non-production: it calls no route and adds no production PHP, JavaScript, CSS, descriptor, Media Library action, or mutation.

Review resolved the five handback questions against `MediaScanReadModel` and `MediaManagerController`: `entity_asc` must be alphabetic; six items under limit 20 cannot report `hasMore`; expansion freshness compares empty fingerprints rather than `modifiedGmt`; `missing_desc` and `scanned_desc` are valid allowlisted sorts; and current expansion responses include safe `fields[]` projections for changed, resolved-or-changed, and provider-unavailable rows. The current fixture must be corrected before it is treated as a production translation source.

Browser review added a required D4A correction checkpoint. At 390x844, the pinned header/scan/notices/filter stack exceeds the drawer while the drawer hides overflow and only the results region scrolls, leaving sort, legend, results, and footer clipped and unreachable. D4A must also document the explicit backend-to-view state adapter (`scanning`, `failed`, `canceled`, `complete`, `invalidated`), distinguish expansion 404/expired failures from row-level provider-unavailable projections, correct pending/revalidation announcements and singular copy, and increase practical mobile targets. Accessibility automation, assistive-technology testing, Safari/Firefox, real-handset QA, production frontend translation, and R1-E release hardening remain open.

The R1-C fixture is already package-scoped. When D4A changes it, update its existing byte/hash entry in `PACKAGE-MANIFEST.json` and its line in `PACKAGE-CONTENTS.sha256`; do not add the out-of-package static mockup directory to either file.

## R1-D context optimization checkpoint

Version 2.4.1 records explicit R1-D authorization and replaces the long copy/paste handoff with a progressive context-loading contract. `ui-ux/MEDIA-MANAGER-CLAUDE-MOCKUP-HANDOFF.md` is now the self-contained context capsule; `ui-ux/CLAUDE-CODE-MEDIA-MANAGER-PROMPT-TEMPLATE.md` is only a minimal launcher; and `ui-ux/fixtures/media-manager-r1c-view-model.json` supplies a current read-only fixture without the older R2 writable implications.

Claude is directed to inspect only the repository/module instructions, capsule, safe fixture, targeted existing CSS evidence, and primary image at startup. Broader package/code reads are exception-only. D1 remains read-only and each D2-D4 sub-phase requires a separate approval. This checkpoint changes no production Visual Editor code, frontend assets, REST contract, descriptor authority, or content data.

## R1-C implementation checkpoint

Version 2.4.0 records the third authorized implementation slice on 2026-08-15. R1-C adds seven protected active-mode REST routes over the existing coordinator and snapshot: start, latest, explicit scan list, next, retry, cancel, and single-group expansion. Its new safe read model supports bounded search, entity/field filters, allowlisted sort, opaque cursor paging, current object-specific permission/status checks at list time, and one-entity rescanning on row expansion.

Browser-visible records exclude owner IDs/subtypes, ACF object IDs, field keys/names/selectors/paths, empty/configuration fingerprints, raw values, full descriptors, descriptor tokens, and mutation actions. Expansion reports only safe `missing`, `changed`, `resolved_or_changed`, or `unavailable` states. Lifecycle POST requests mutate only expiring scan state; R1-C introduces no frontend assets/table, Media Library action, content mutation, journal write, or cache invalidation.

Focused R1-C coverage passed 6 tests/417 assertions; the combined R1-A/R1-B/R1-C and current Visual Editor instrumentation focus passed 23/603. Touched PHP syntax is clean. The full PHP comparison ran 700 tests/7,723 assertions and retained exactly the same six inherited failure identities. Agent docs pass with 54 curated records, 415 discovered surfaces, and zero unmapped. R1-C changes no JavaScript, so the inherited incomplete full-lint limitation remains. The safe view model is now stable enough for the separately authorized R1-D Claude static-mockup and frontend-table slice.

## R1-B implementation checkpoint

Version 2.3.0 records the second authorized implementation slice on 2026-08-15. R1-B adds internal request-batched candidate traversal, supported native/ACF raw-value scanning, deterministic opaque group and finding references, empty-value fingerprints, a separately namespaced compressed user/blog-bound transient snapshot, latest-generation replacement, monotonic revisions, short update locks, start/next/complete/cancel/retry/invalidate states, safe payload limits, summary counts, and per-chunk duration/query/memory/storage evidence.

R1-B adds no REST/AJAX surface, frontend assets/table, descriptor hydration, or mutation. It cannot run from ordinary frontend page loads and is only instantiated when a future protected controller deliberately calls it. R1-C remains behind a separate explicit crossing line.

Focused R1-B coverage passed 5 tests/106 assertions; R1-A/R1-B passed 10/171; the combined current Visual Editor focus passed 17/186. A representative isolated PHPUnit chunk covering 20 entities and 60 findings measured 4.661 ms, 24 queries, zero additional allocated/peak memory pages at PHP's reported granularity, and a 4,983-byte compressed snapshot. The clean full comparison ran 694 tests/7,302 assertions and retained exactly the six inherited failure identities. R1-B changes no JavaScript, so the prior incomplete full-lint limitation remains.

## R1-A implementation checkpoint

Version 2.2.0 records the first authorized implementation slice on 2026-08-15. R1-A adds only the default-off Media Manager setting, scan-specific owner eligibility, exact active ACF visibility catalog, unsupported nested/conditional counts, raw media assignment classification, focused tests, and required documentation/agent-reference maintenance.

It does not add a scanner, snapshot, REST/AJAX surface, frontend table, descriptor bridge, or mutation. The focused R1-A suite passed 5 tests/65 assertions and the combined Visual Editor focus passed 12 tests/80 assertions. A full PHP comparison ran 689 tests/7,186 assertions and retained the same six inherited failure identities; no new failure was introduced. Full JavaScript lint was not rerun because R1-A changes no JavaScript, so the prior incomplete-lint limitation remains.

## Repository reconciliation update

Version 2.1.0 preserves the v2 release order and adapts it to the inspected implementation at clean, synchronized DBVC commit `5db4b40` on `codex/visual-editor-linked-posts-plan`.

The reconciliation:

- makes the canonical addon phase guide carry both the existing P0-P5 backlog and this R0-R6 program;
- records R0 as complete while leaving R1 feature implementation unauthorized and unstarted;
- limits initial R1 ACF paths to unconditional top-level and deterministic group-only image/gallery fields;
- removes same-entity `Save Row` from initial R2 rather than treating the collection-specific composite route as a general media batch contract;
- records that active Visual Editor mode already enqueues editor/media assets, so R1 must add no new enqueue but cannot claim those assets are absent;
- records the inherited validation baseline of 6 deterministic PHP failures out of 684 tests and an incomplete full JavaScript lint run.

Future code and newer working-tree evidence remain authoritative. Recheck branch, HEAD, status, and validation before every implementation slice.

## Why this update exists

The original implementation package focused on Brand Control Center and the broader frontend workspace. The product priority has changed: a frontend **Media Manager** for missing image assignments must now ship near the beginning of the program.

This update does not discard the earlier product direction. It inserts two production releases ahead of the Brand Control Center work and updates every roadmap, quality gate, prompt, mockup instruction, and tracking file accordingly.

## Replacement guidance

If the previous folder already exists at:

```text
docs/dropins/dbvc-visual-editor-brand-controls-guide/
```

replace the guide folder as a documentation package rather than merging old and new release files. Keeping old `R1`–`R4` documents beside the new numbering will create conflicting instructions.

Before replacement:

1. Preserve any repository-specific evidence or decisions already written into the old tracking files.
2. Copy those factual entries into the matching v2 tracking files.
3. Do not overwrite or revert implementation code that may already have started.
4. If earlier R1 work has begun, use `prompts/PACKAGE-UPDATE-RECONCILIATION.md` to map it to the revised R3 release.

## Release renumbering

| v1 release | v2 release |
|---|---|
| R0 Discovery | R0 Discovery |
| R1 Registry foundation | R3 Registry foundation |
| R2 Expanded center | R4 Expanded center |
| R3 Option families | R5 Option families |
| R4 Site Manager Workspace | R6 Site Manager Workspace |

New releases:

- **R1:** Media Manager scan and report
- **R2:** Media Manager direct remediation

## Scope correction

The phrase “Media Health” is deliberately avoided for R1–R2 because the release is narrower. It scans for **empty image assignments** on eligible live entities. It does not inspect file-system integrity, attachment health, image optimization, alt text, duplicates, or external media.

## Concept image

The package includes an initial Media Manager PNG under `ui-ux/reference-images/`. It is a visual concept only. It does not authorize:

- cross-entity bulk saving;
- a custom upload pipeline;
- direct client-provided field targeting;
- arbitrary metadata scanning;
- exact labels, counts, table columns, or endpoint behavior.

Codex must produce a verified data/action/state contract before Claude Code creates or refines static HTML/CSS.

## Existing implementation work

If registry or Brand Control Center code already exists:

- preserve it;
- assess whether it is production-ready and isolated;
- do not reset it merely to follow the revised order;
- document dependencies between that work and R1–R2;
- implement Media Manager in the smallest compatible slices.

The revised order is a product sequencing preference, not a reason to rewrite stable completed work.
