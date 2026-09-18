# R0 — Current-State Discovery and Production Baseline

## Production outcome

R0 produces the evidence and corrected implementation map required to begin Media Manager without duplicating existing scanners, weakening descriptor authority, or colliding with active Visual Editor work.

R0 contains no production feature implementation.

## Required outputs

1. Repository/working-tree status and protected-file map
2. Visual Editor architecture and request-flow map
3. Existing featured-image, ACF image, and gallery resolver/editor/save trace
4. Descriptor strategy for non-rendered owners
5. Existing scanner/job/session/catalog evidence in DBVC and VerticalFramework
6. Eligible object and field support matrices
7. Performance baseline and representative site-size counts
8. Existing Shared Globals and option-family support matrix
9. Corrected R1/R2 implementation plan with actual symbols/files
10. Test gaps, risk updates, decisions, observability, and rollback plan

## Required repository review

Follow `../01-DISCOVERY-AND-CURRENT-STATE-REVIEW.md` completely.

At minimum inspect:

- current branch and uncommitted files;
- Visual Editor toolbar/overflow and panel layering;
- descriptor sessions and stale checks;
- object navigation and permissions;
- featured-image, image, gallery, and Media Library code;
- composite/multi-field save behavior;
- journal and cache handling;
- current test/fixture structure;
- any Media Health or missing-file scanner;
- any ACF catalog/location-rule cache;
- VerticalFramework `acf-json`, docs, inventories, and scanner evidence.

## Mandatory evidence decisions

R0 must answer these before R1 code:

- What exact existing surface opens Media Manager?
- What current subsystem should enumerate objects?
- What current subsystem should enumerate applicable ACF fields?
- Can scan results be stored in an existing user/session mechanism?
- Can current descriptor factories hydrate fields not rendered on the current page?
- Which nested paths are safe to scan in R1?
- How will conditional ACF fields be handled?
- What representative result counts and query costs exist?
- What feature flag/fallback mechanism should isolate R1?
- Does a VerticalFramework change belong in R1, or can DBVC ship independently?

## Baseline commands

Use repository-provided commands first. Record actual output for:

- PHP syntax/static analysis;
- unit/integration tests;
- JavaScript tests/build/lint;
- browser/E2E suite;
- representative frontend smoke checks;
- Bricks Builder exclusion;
- existing image/gallery/manual QA.

Do not invent a new test harness before checking current tooling.

## Deliverable format

Produce a concise R0 report in the repository’s established location. Update these package files with factual references or linked notes:

- `tracking/EVIDENCE-LOG.md`
- `tracking/DECISION-LOG.md`
- `tracking/RISK-REGISTER.md`
- `tracking/MEDIA-SCAN-COVERAGE-MATRIX.md`
- `tracking/IMPLEMENTATION-TRACKER.md`

## Gate to R1

R1 may begin only when:

- the current working tree is understood and protected;
- scan/field/descriptor extension points are identified;
- eligible entities and fields have an evidence-backed policy;
- a bounded scan strategy is selected;
- no known architecture conflict makes the proposed R1 unsafe;
- the corrected R1 scope remains independently releasable;
- rollback and feature isolation are defined.

## 2026-08-14 disposition

R0 discovery is complete and the package is reconciled to the inspected repository. The detailed report is in `../01-DISCOVERY-AND-CURRENT-STATE-REVIEW.md`; evidence, decisions, risks, coverage, and progress are updated under `../tracking/`.

Selected baseline facts:

- current branch `codex/visual-editor-linked-posts-plan`, clean and synchronized with origin at HEAD `5db4b40` when reconciliation began; newer work must still be rechecked and protected;
- no reusable missing-assignment scanner or generic non-rendered media descriptor factory exists;
- exact ACF applicability can use active runtime definitions plus `acf_get_field_group_visibility()`;
- initial safe R1 paths are native featured image and unconditional top-level/group-only ACF image/gallery fields;
- repeater/flexible/mixed paths and conditional unknowns are deferred pending a dedicated proof slice;
- R1 uses a separate user/blog-bound bounded scan snapshot and creates no writable descriptors;
- R2 requires a fresh finding-to-descriptor bridge and expected-empty single-field precondition;
- current runtime baseline is 454 candidate entities and 2,119 applicable media-definition pairs; the 612.62 ms probe excludes raw-value scanning, persistence, REST, authorization, and UI cost.

Rollback/feature isolation for R1: a default-off feature flag, additive routes/services/UI entry, separately namespaced ephemeral snapshots, and no schema requirement for the first slice. Disabling the flag must remove the entry point and scan route availability without changing current descriptor or mutation behavior.

Validation baseline at this checkpoint: the focused Visual Editor instrumentation check passed 7 tests/15 assertions; the repository PHP suite still has 6 deterministic failures out of 684 tests; full JavaScript lint did not complete. These are limitations to preserve and compare, not green release evidence.

The R1 gate remains **closed** until the user explicitly authorizes implementation. D-022 is accepted as the reconciled five-slice planning sequence, not as code authorization. R0 and this reconciliation did not modify production code or runtime data.
