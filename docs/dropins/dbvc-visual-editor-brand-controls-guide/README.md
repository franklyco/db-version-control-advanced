# DBVC Visual Editor — Media Manager, Brand Controls, and Frontend Workspace Implementation Guide

**Package version:** 2.10.0
**Package date:** August 16, 2026
**Target location:** `DBVC/docs/dropins/dbvc-visual-editor-brand-controls-guide/`  
**Primary implementation environment:** the current DBVC local repository checkout  
**Secondary evidence source:** `/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/themes/vertical`

**Repository reconciliation:** R0 was completed against branch `codex/visual-editor-linked-posts-plan` and refreshed at clean, synchronized commit `5db4b40`. R1-A through R1-D are implemented in the current working tree for review. The default-off shell now has a protected API/state controller, server-driven laptop/desktop result table, and lazy one-row safe field-status expansion over the existing group route. R1-E hardening is in progress: isolated keyboard focus continuity, native disclosure operation, reduced-motion behavior, automated dialog/result/loading/expansion semantics, Chromium/Firefox/WebKit engine coverage, no-auto-scan proof, and synthetic 100/500/2,000-group snapshot/read/payload measurements are proven. Authenticated runtime, complete candidate-scan scale, real assistive-technology, and real Safari gates remain. Targeted Media Manager lint passes; the latest aggregate repository lint rerun did not complete and is not promoted to a current pass. Descriptor issuance, native Media Library choose/upload, and mutation remain R2 boundaries. Current frontend work targets normal laptop/desktop use; D-036 tables additional mobile/responsive design, implementation, mockup, and QA work across the remaining releases until explicitly reauthorized.

## Purpose

This package guides Codex through independently releasable, production-ready phases that evolve the DBVC Visual Editor into a safer and more efficient frontend site-management workspace.

The updated program makes the **Frontend Media Manager** one of the first implementation priorities. It is intentionally delivered before the larger Brand Control Center UI so client users can quickly find published entities with missing image assignments and repair those fields directly from the frontend.

The program now covers five product packages:

1. Frontend Media Manager scan and reporting
2. Frontend Media Manager direct remediation
3. Registry-backed Brand Control Center
4. Expanded Global & Brand Control Center plus point releases for explicitly proven ACF option-owner field families
5. Frontend Site Manager Workspace

These packages must not be implemented as one monolithic change. Each release must be independently reviewable, testable, feature-gated where appropriate, reversible, and suitable for production before the next release begins.

## Important update from package v1

This package replaces the earlier August 14, 2026 guide. Release numbers have changed because Media Manager now ships immediately after discovery.

| Previous package | Updated package |
|---|---|
| R0 Discovery | R0 Discovery, expanded with Media Manager evidence |
| R1 Registry-backed Brand Control Center | R3 Registry-backed Brand Control Center |
| R2 Expanded Global & Brand Control Center | R4 Expanded Global & Brand Control Center |
| R3 ACF option-family support | R5 ACF option-family support |
| R4 Frontend Site Manager Workspace | R6 Frontend Site Manager Workspace |
| Not included | R1 Media Manager scan/report |
| Not included | R2 Media Manager direct remediation |

Read `PACKAGE-UPDATE-NOTES.md` before replacing an already-dropped-in v1 package.

## Authority and interpretation

The original handoff was prepared without a fresh inspection. Its R0 findings, decisions, risks, coverage matrix, and phase boundaries have now been reconciled against the current DBVC implementation and the available VerticalFramework evidence at the checkpoint above. Conceptual future names remain directional; the recorded current-state symbols and gaps are evidence-backed as of that checkpoint.

The current repositories are authoritative. When this guide conflicts with current code, active uncommitted work, newer documentation, or an already-proven implementation pattern:

1. Preserve the working tree.
2. Record the conflict in `tracking/EVIDENCE-LOG.md` or the implementation session notes.
3. Adapt the plan to the current architecture.
4. Prefer extending proven systems over creating parallel ones.
5. Ask only when a true product decision cannot be resolved from repository evidence.

## Revised release sequence

| Stage | Release | Production outcome |
|---|---|---|
| R0 | Current-state discovery and baseline | Verified architecture map, Media Manager feasibility report, option-field support matrix, test baseline, and corrected implementation plan |
| R1 | Media Manager scan and report | User-triggered, bounded scanning of eligible published pages/posts/CPTs/terms and a production frontend table of missing featured-image, ACF image, and ACF gallery assignments |
| R2 | Media Manager direct remediation | Lazy field hydration, native WordPress Media Library assignment/upload workflow, safe per-field saves, expected-empty conflicts, revalidation, journal, and cache handling |
| R3 | Registry-backed Brand Control Center | Provider-agnostic registry plus a minimal center using already-proven global controls |
| R4 | Expanded Global & Brand Control Center | Client-facing categories, search, filtering, source/status clarity, and mockup-informed production UI |
| R5.1 | Scalar ACF option fields | Prove and expose text, textarea, URL, email, number, and range option-owned controls |
| R5.2 | Choice, link, and rich text | Checkbox, select, radio, button group, link, and WYSIWYG option-owned controls |
| R5.3 | Media option fields | Image and gallery option-owned controls |
| R5.4 | Connected and taxonomy option fields | Regression-complete relationship/post-object support; taxonomy only with a separately proven option-owner contract |
| R6 | Frontend Site Manager Workspace | Persistent laptop/desktop navigation workspace integrating existing Visual Editor tools, Media Manager, and Global & Brand controls; additional responsive/mobile variants remain tabled by D-036 |

R1 and R2 are deliberately separated. The first release proves complete, performant, permission-safe finding discovery. The second release adds mutation only after the finding and descriptor boundaries are verified.

## Media Manager v1 boundary

The initial Media Manager is a focused **missing image-assignment workflow**, not a generic Media Health suite.

### Included

- Published/live pages, posts, public CPTs, and public taxonomy terms
- Native featured images for eligible post types
- ACF image fields
- ACF gallery fields
- Unconditional top-level and deterministic group-only ACF paths in the initial scan boundary
- Scrollable, filterable, internally paginated or virtualized findings table
- Expandable entity rows
- Native Media Library selection and upload path
- Per-field saves in R2 after a fresh descriptor and expected-empty precondition
- Targeted finding revalidation after save

### Not included

- Broken physical file scans
- Orphaned attachments
- Duplicate media detection
- Alt-text, dimensions, compression, format, or SEO analysis
- ACF file, oEmbed, or video fields
- Static Bricks image settings or arbitrary Bricks JSON
- Draft/private/scheduled/trash content in the default release
- Users, option pages, or global brand assets in the Media Manager scan
- Cross-entity `Save selected`
- Same-entity `Save Row` in the initial R2 contract
- Automatic placeholder assignment
- Attachment deletion
- A replacement for the WordPress Media Library

## How to use this package

1. Read `PACKAGE-UPDATE-NOTES.md` if v1 is already present.
2. Read `00-GOVERNING-DIRECTIVES.md`.
3. On this checkpoint, run only a read-only R0 delta for changed repository state; on another checkout, complete the full R0 workflow in `01-DISCOVERY-AND-CURRENT-STATE-REVIEW.md`.
4. Update the evidence, decision, risk, coverage, and implementation tracking files before feature code.
5. Complete one release document under `releases/` at a time.
6. Use the matching prompt under `prompts/` to start a focused Codex session.
7. For UI-heavy releases, follow the files under `ui-ux/`; the included PNGs are concept references, not production specifications.
8. Do not begin the next release until the current release gates in `quality/TEST-QA-RELEASE-GATES.md` are satisfied.

## Package map

```text
dbvc-visual-editor-brand-controls-guide/
├── README.md
├── PACKAGE-UPDATE-NOTES.md
├── PACKAGE-MANIFEST.json
├── 00-GOVERNING-DIRECTIVES.md
├── 01-DISCOVERY-AND-CURRENT-STATE-REVIEW.md
├── 02-TARGET-ARCHITECTURE-AND-BOUNDARIES.md
├── media-manager/
│   ├── MEDIA-MANAGER-PRODUCT-SPEC.md
│   ├── FIELD-ELIGIBILITY-AND-SCOPE.md
│   ├── SCAN-AND-FINDING-CONTRACT.md
│   ├── TABLE-AND-ROW-INTERACTION-SPEC.md
│   └── MUTATION-STALE-DATA-AND-REVALIDATION.md
├── releases/
│   ├── R0-DISCOVERY-BASELINE.md
│   ├── R1-MEDIA-MANAGER-SCAN-AND-REPORT.md
│   ├── R2-MEDIA-MANAGER-DIRECT-REMEDIATION.md
│   ├── R3-REGISTRY-BACKED-BRAND-CONTROL-CENTER.md
│   ├── R4-EXPANDED-GLOBAL-BRAND-CONTROL-CENTER.md
│   ├── R5-ACF-OPTION-FIELD-FAMILY-SUPPORT.md
│   └── R6-FRONTEND-SITE-MANAGER-WORKSPACE.md
├── ui-ux/
│   ├── CLAUDE-CODE-MOCKUP-HANDOFF.md
│   ├── MEDIA-MANAGER-CLAUDE-MOCKUP-HANDOFF.md
│   ├── CLAUDE-CODE-MEDIA-MANAGER-PROMPT-TEMPLATE.md
│   ├── MOCKUP-DELIVERABLE-CONTRACT.md
│   ├── MOCKUP-TO-PRODUCTION-INTEGRATION.md
│   ├── REFERENCE-IMAGES.md
│   ├── fixtures/media-manager-fixture.json
│   ├── fixtures/media-manager-r1c-view-model.json
│   └── reference-images/*.png
├── quality/
│   ├── TEST-QA-RELEASE-GATES.md
│   ├── MEDIA-MANAGER-TEST-MATRIX.md
│   ├── SECURITY-AND-DATA-SAFETY.md
│   └── OBSERVABILITY-ROLLBACK-AND-COMPATIBILITY.md
├── tracking/
│   ├── IMPLEMENTATION-TRACKER.md
│   ├── MEDIA-SCAN-COVERAGE-MATRIX.md
│   ├── EVIDENCE-LOG.md
│   ├── DECISION-LOG.md
│   └── RISK-REGISTER.md
└── prompts/
    ├── 00-CODEX-KICKOFF.md
    ├── PACKAGE-UPDATE-RECONCILIATION.md
    ├── R1-CODEX-PROMPT.md
    ├── R2-CODEX-PROMPT.md
    ├── R3-CODEX-PROMPT.md
    ├── R4-CODEX-PROMPT.md
    ├── R5-CODEX-PROMPT.md
    └── R6-CODEX-PROMPT.md
```

## Program-level non-goals

The following remain outside this release program:

- Arbitrary Bricks element, class, layout, responsive, condition, or template editing
- Generic `wp_options`, post-meta, term-meta, or user-meta browsing
- Design-token, color-palette, typography, spacing, radius, shadow, or CSS-variable mutation
- Full Site Assurance implementation
- Broad Media Health functionality beyond missing image assignments
- Cross-owner or cross-entity bulk saving
- Generalized change carts or true change-set undo
- Repeater-row or flexible-layout creation, deletion, duplication, or reordering
- Automatic creation or rewriting of Bricks dynamic bindings
- Multi-site brand deployment
- Real-time collaborative editing
- AI-generated automatic corrections

Extension points may be designed only where they are directly required by R1–R6. Do not add speculative tables, endpoints, navigation items, or placeholder modules.

## Current validation baseline

- Focused R0 Visual Editor instrumentation: 7 tests, 15 assertions, passed.
- Full PHP suite: 6 deterministic failures out of 684 tests. This is an inherited baseline and not a passing release result.
- R1-A full PHP comparison: 689 tests, 7,186 assertions, 6 failures with the same inherited identities; no new failure introduced.
- R1-B focused scanner/snapshot contract: 5 tests, 106 assertions; combined R1-A/R1-B 10/171; combined with current Visual Editor instrumentation 17/186.
- R1-B representative 20-entity/60-finding test chunk: 4.661 ms, 24 queries, zero additional allocated/peak memory pages at PHP's reported granularity, 4,983-byte compressed snapshot.
- R1-B full PHP comparison: 694 tests, 7,302 assertions, 6 failures with the same inherited identities; no reproducible new failure introduced.
- R1-C protected read/list/row contract: 6 tests, 417 assertions; combined R1-A/R1-B/R1-C and current Visual Editor instrumentation 23/603.
- R1-C full PHP comparison: 700 tests, 7,723 assertions, 6 failures with the same inherited identities; no new failure introduced.
- R1-D production shell/table/expansion PHP contract: 4 tests, 47 assertions; initial shell Chromium geometry/focus/axe passed at 1440x900, 1440x600, 390x844, and 320x568.
- R1-D API/state controller: 5 jsdom tests passed for route identity, all backend-state mappings, no-scan/conflict mapping, and stale-response suppression; isolated Chromium latest/start/next/list flow passed at 1440x900 and 1280x720 with zero shell axe violations and no result-row DOM.
- R1-D server-driven table and lazy expansion plus R1-E semantic hardening: 11 jsdom tests and 6 isolated Playwright tests across Chromium, Firefox, and WebKit pass for safe row/field DOM, search/entity/field/all-sort replacement, 15-to-25 opaque-cursor append, de-duplication, scroll preservation, independent group errors and response ordering, exact list/group identity, native Enter/Space expansion, row-focus continuity, reduced motion, named dialog/results/scroll/expanded regions, explicit busy/loading semantics, stable polite field-check announcements, bounded laptop/desktop geometry, trigger focus, and expanded/collapsed axe WCAG A/AA with zero violations. This is engine automation, not real assistive-technology or real Safari proof; no descriptor/media/mutation action is present.
- R1-E scale/no-auto-scan contract: 2 tests/50 assertions; combined R1-A through R1-E 22/685 after the four semantic source assertions. Synthetic 100/500/2,000-group storage/list/payload projections stay compressed and capped at 50 response rows; the latest combined 2,000-group run measured 41.441 ms, zero additional WordPress queries, 6,291,456 allocated-memory bytes, a 120,515-byte snapshot, and a 24,833-byte response. Complete candidate traversal/raw reads/authenticated transport remain open.
- R1-E full PHP comparison: 706 tests, 7,820 assertions, the same six inherited failure identities and no new regression.
- R1-D slice-3 full PHP comparison: 704 tests, 7,760 assertions, the same six inherited failure identities and no new regression.
- R1-D slice-4 full PHP comparison: 704 tests, 7,764 assertions, the same six inherited failure identities and no new regression.
- R1-D slice-2 full PHP comparison: 704 tests, 7,755 assertions, the same six inherited failure identities.
- Targeted Media Manager `wp-scripts` lint passes with stale Baseline/Browserslist data warnings only. The latest aggregate repository lint rerun did not complete and remains an open release caveat; no repository-wide pass is claimed from this slice.
- Agent docs: 54 curated records, 415 discovered surfaces, zero unmapped.
- Package manifest/checksum validation is regenerated after documentation reconciliation.

Future release work must record exact commands and distinguish inherited baseline failures from regressions. Focused checks for touched code are required even when a repository-wide command is incomplete.

## Completion definition

This package is complete when R1–R6 have each passed an independent production release gate, existing Visual Editor behavior remains backward compatible, unsupported storage remains inaccessible, Media Manager scans remain bounded and permission-safe, and the old interfaces remain available as fallbacks until replacement surfaces demonstrate production stability.
