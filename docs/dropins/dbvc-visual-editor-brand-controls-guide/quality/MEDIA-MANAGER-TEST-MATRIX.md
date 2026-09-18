# Media Manager Detailed Test Matrix

Use this as a planning matrix; adapt fixture names and exact test layers to current repository tooling.

## Entity coverage

| Case | Expected result |
|---|---|
| Published page with no featured image | Finding when page type supports thumbnails and policy includes it |
| Published post with featured image | No featured-image finding |
| Public CPT discovered dynamically | Included when policy/capability pass |
| Internal/non-public CPT | Excluded |
| Draft/private/scheduled/trash post | Excluded in v1 |
| Attachment/revision/nav menu item | Excluded |
| Public taxonomy term with empty ACF image | Finding |
| Private taxonomy term | Excluded |
| Entity user cannot edit | Hidden/excluded |
| Entity deleted/unpublished during scan | Unavailable/removed on hydration |

## Field coverage

| Case | Expected result |
|---|---|
| Top-level ACF image empty | Finding |
| Top-level ACF image populated | No finding |
| ACF gallery empty array | Finding |
| ACF gallery populated | No finding |
| Image field group not applicable by location | Excluded |
| Deterministic group child image/gallery empty | Finding in initial R1 when the unconditional group ancestry and exact owner applicability are proven |
| Existing repeater row image empty | Deferred/unsupported in initial R1; never inferred from render-derived resolver support alone |
| Nonexistent repeater row | Never reported |
| Flexible layout child in existing row | Deferred/unsupported in initial R1 pending a shared enumerator/resolver path contract |
| Conditional field inactive | Excluded or explicitly non-actionable according to R0 decision |
| ACF file/oEmbed field empty | Excluded |
| Arbitrary meta with image-like value | Excluded |

## Scan lifecycle

| Case | Expected result |
|---|---|
| Start scan | New user-bound scan reference and progress |
| Duplicate next-chunk call | Idempotent/no corruption |
| Stale out-of-order response | Ignored using response/snapshot version |
| User closes panel | No corrupted snapshot; request behavior documented |
| Snapshot expires | Clear expired state and refresh action |
| ACF/provider unavailable mid-scan | Classified error; no partial false completion |
| Very large candidate set | Bounded requests and navigable result set |
| Second user scans same site | Isolated state/permissions |

## Table interaction

| Case | Expected result |
|---|---|
| Search title | Filtered bounded results |
| Entity type filter | Correct result subset/counts |
| Field family filter | Entities with matching findings only |
| No matches | Clear no-results state |
| Expand row | Fresh hydration, not cached client targets |
| Expand resolved row | Finding removed/marked resolved |
| Keyboard expand/collapse | Fully operable with visible focus |
| Internal scrolling | Header/filters usable; page does not jump unexpectedly |
| Large rows | Stable performance and preserved state |

Slice 4 plus initial R1-E automated status (2026-08-16): search/entity/field/six-sort replacement, no-match and independent list/group request-error states, 15-to-25 opaque-cursor append, group-reference de-duplication, preserved internal scroll, safe collapsed and expanded row DOM, exact group identity, all supported current-state presentations, native Enter/Space disclosure, row-focus continuity, reduced-motion suppression, laptop/desktop geometry, trigger focus restoration, and expanded/collapsed axe WCAG A/AA pass in focused jsdom/Chromium coverage. Authenticated WordPress runtime, large-scale profiling, assistive technology, and cross-browser evidence remain open.

R1-E semantic status (2026-08-16): the production dialog, results heading/focusable scroll region, list/group loading, and expanded entity region now have stable accessible names or explicit status/busy semantics. The persistent polite live region announces each field check's start, final status counts, or request failure. Eleven jsdom tests and six Chromium/Firefox/WebKit viewport cases prove the DOM/announcement contract while retaining zero confirmed expanded/collapsed axe violations. This is automated semantic/browser-engine evidence only; real screen-reader/assistive-technology and real Safari review remain open.

R1-E synthetic scale status (2026-08-16): 100/500/2,000-group compressed snapshots and bounded 50-row server projections pass in focused PHPUnit. The combined 2,000-group run measured 25.425 ms, 0 additional WordPress queries, 6,291,456 allocated-memory bytes, 120,475 stored bytes, and a 24,833-byte response. Ordinary frontend asset enqueue creates no scan. Complete large-owner candidate traversal/raw ACF reads and authenticated REST/browser transport remain open.

R2-E4 feature-isolation status (2026-08-18): `VisualEditorMediaManagerR2E4Test` (4 tests/10 assertions) proves the kill switch gates the whole remediation surface — `is_media_manager_enabled()` requires both the Visual Editor master flag and the feature flag, and the REST permission gate `canAccess` (the permission_callback for every route) is closed when the MM flag is off, the master flag is off, the user lacks the base capability, or the request is logged out, and open only when all hold. A consolidated release-notes/rollback runbook accompanies it. R2-E (E1–E4) is complete; real-browser/AT QA remains the standing residual gate (D-049).

R2-E3 repeated-remediation + DOM-patch status (2026-08-18): the wp.media frame lifecycle and the no-reload save patch are proven by 4 jsdom cases (34 total). `openAssignFrame` keeps a single active frame: 3 repeated opens leave at most one live frame (2 disposed), and collapsing the row or closing the manager disposes it (RK-011). A save patches only the affected entity's row — the untouched sibling row is the SAME DOM node (not rebuilt) and no list/scan request accompanies the reconcile, so the current-page DOM patch is truthful with no reload. Real-browser memory/listener profiling over long sessions remains the residual gate (D-049).

R2-E2 security/permission hardening status (2026-08-18): the end-to-end assign and replace mutation paths are proven to fail closed with content unchanged by `VisualEditorMediaManagerR2E2Test` (9 tests/67 assertions): a foreign user's user/blog-bound snapshot is rejected (`media_scan_expired_or_invalid`), a changed scan revision blocks the write (`media_scan_revision_changed`), an owner unpublished after the scan/read blocks it (`media_assignment_stale` / `media_replace_unavailable` via a pre-write eligibility re-check), an edit capability revoked mid-flow blocks it (eligibility failure before the write), and a non-existent attachment id is rejected (`media_assignment_save_failed`). This complements the R2-C/R2-F edge coverage (populated-after-scan, stale generation, non-image, cardinality, stale value ref, emptied-after-read). Real-browser/authenticated-runtime QA remains the standing residual gate.

R2-F entity media inventory & replace status (2026-08-17): all three slices are implemented and verified. Slice 1 (inventory + preview) is covered by `VisualEditorMediaManagerR2FTest` (3 PHP tests/29 assertions) — populated fields listed as `assigned` with a sanitized `{ url, alt, count }` preview, top-level list/counts unchanged, no raw-target leak — plus 1 jsdom case. Slice 2 (thumbnail presentation) is covered by 1 jsdom case: `[thumbnail | content]` cells, `loading="lazy"`/`decoding="async"` attributes, an accent placeholder for empty fields, and a gallery `+{count}` badge. Slice 3 (gated replace) is covered by `VisualEditorMediaManagerR2FReplaceTest` (6 PHP tests/62 assertions) and 3 jsdom cases: a successful replace overwrites the field, keeps the prior attachment, fires cache invalidation, and reissues a fresh value fingerprint; a stale `expectedValueRef` returns `409 media_replace_stale`; a field emptied after the read returns `409 media_replace_not_populated`; a malformed ref and a non-image selection are rejected without a write; the gallery overwrite reorders ids; the frontend gates the control on `valueRef`, sends the opaque ref, reconciles in place with no table reload, and retains the staged selection on a 409. The R1-D read-only invariant is extended to assert the distinct gated `/replacement` endpoint and still forbids direct `fetch(`/`.save(`/composite-save. Real-browser assign/replace/upload QA is the residual R2-F gate (authenticated runtime unavailable, D-049).

R2-D UX-states status (2026-08-16): the nine assignment/save states are implemented and covered by 4 new jsdom cases (23 total). Verified: an `Opening Media Library…` in-flight state (`aria-busy`), unsaved image/gallery selection, an upload-unavailable hint gated by `canUpload`, save-in-progress, a verified `Saved` field chip with a `Resolved` row badge, a polite `role="status"` refresh notice for changed-since-scan, and an assertive `role="alert"` validation-error notice that retains the staged selection. No new REST/mutation surface. Real-browser and assistive-technology verification of the states is the residual R2-D gate (authenticated runtime unavailable).

R2-C field-save status (2026-08-16): the dedicated assignment endpoint and `MediaAssignmentService` are covered by 7 PHP tests/81 assertions and 3 jsdom cases. Featured-image, ACF-image, and ACF-gallery saves persist to the field and reconcile the finding to resolved. A field populated after the scan is blocked with `409 media_assignment_stale` and is not overwritten; non-image and empty selections are rejected without a write; a stale generation is blocked. The frontend reconciles the field, row counts, and scan summary from the reread and marks a fully resolved row in place with no table reload, keeps the staged selection on a save conflict, and shows an in-progress saving state. Real-browser save/upload/reconciliation QA is the residual R2-C gate (authenticated runtime unavailable).

R2-B media-selection status (2026-08-16): the native `wp.media` staging flow is covered by 5 jsdom tests (16 total in the state suite). Choosing opens a single-image frame for featured/ACF image and a multi-image frame for ACF gallery, stages the selection unsaved (badge + preview + `Replace`/`Clear`), and never issues a save/mutation request. Non-writable descriptors surface a notice without opening the frame, the control is absent when `wp.media` is unavailable, and the descriptor token/session and raw targets never enter the DOM. Real-browser `wp.media` open/upload/focus-layering QA is the residual R2-B gate (authenticated runtime unavailable), consistent with the accepted R1 gates. Save/mutation rows remain R2-C.

R2-A descriptor-bridge status (2026-08-16): the finding->fresh-descriptor bridge is covered by 11 focused tests/200 assertions. Tampered scan/group/finding references, browser-supplied targets, stale generation/revision, expired snapshots, populated-after-scan (`resolved`), changed empty evidence (`changed`), and unpublished/deleted owners (`unavailable`) all fail closed, and the response never exposes owner ids, field keys/names/selectors, ACF object ids, paths, or the empty fingerprint. The minted descriptor routes to exactly one existing resolver family and is retrievable only by the same user. Media selection and mutation cases below remain R2-B/R2-C and are not yet covered.

R1-E closeout status (2026-08-16): complete candidate traversal/raw reads are now measured against live fixtures — the real provider/scanner/store pipeline runs to completion across 100 and 300 owners with constant 2 raw ACF reads per owner, one applicability evaluation per candidate, <=50 candidates and <=1 source query per chunk, and per-candidate DB cost falling ~1.25 -> ~0.83 as owners triple (no field-definition/capability/permalink N+1, E-051). The live REST permission gate is proven unauthenticated (all seven routes registered; `scans/latest`, tampered refs, and POST `scans` return 401 before resolution, E-050). Authenticated REST/table data behavior, real assistive technology, real Safari (the WebKit engine is not Safari), and large-list responsiveness remain open.

## Media selection and mutation

| Case | Expected result |
|---|---|
| Choose existing valid image | Unsaved preview |
| Upload new with permission | Core upload then selectable attachment |
| Upload without permission | Upload affordance absent/disabled; server denies bypass |
| Choose non-image attachment | Rejected |
| Attachment deleted before save | Save rejected clearly |
| Field populated by another user | Stale conflict; no overwrite |
| Gallery populated by another user | Conflict; no automatic replace/merge |
| Save featured image | Native contract, journal, cache, revalidation |
| Save ACF image | Existing ACF contract, journal, cache, revalidation |
| Save gallery | Ordered validated IDs, journal, cache, revalidation |
| Same-entity multiple dirty fields | No `Save Row` in initial R2; save each supported field independently |
| Cross-entity selected rows | No mutation action in R1/R2 |

## Layering and lifecycle

| Case | Expected result |
|---|---|
| Media modal open | Media Manager remains behind; outside-click ignored |
| Escape in media modal | Closes modal first |
| Media modal close | Focus returns to initiating button |
| Main editor panel coexistence | No conflicting drag/focus/z-index behavior |
| Exit Visual Editor | Scan UI closes safely; no mutation from drafts |
| Bricks Builder request | No Media Manager assets or scan calls |

## Security

| Case | Expected result |
|---|---|
| Tampered scan reference | Rejected |
| Tampered finding reference | Rejected |
| Browser-supplied field key/owner | Ignored/rejected; server resolves authority |
| Unauthorized post/term ID guessing | No disclosure/mutation |
| XSS in entity/field label | Escaped |
| Oversized search/filter input | Bounded/rejected |
| Replay old save descriptor | Current session/stale policy enforced |

## Observability

| Case | Expected result |
|---|---|
| Failed chunk | Classified log with no sensitive value/token |
| Provider exception | Observable and fails closed |
| Stale conflict | Countable/diagnosable event |
| Save verification mismatch | Explicit failure/manual-review state |
| Journal failure | Save response follows current criticality policy; never silently claims full audit success |
