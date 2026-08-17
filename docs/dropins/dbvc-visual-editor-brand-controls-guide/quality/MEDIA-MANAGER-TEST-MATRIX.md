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
