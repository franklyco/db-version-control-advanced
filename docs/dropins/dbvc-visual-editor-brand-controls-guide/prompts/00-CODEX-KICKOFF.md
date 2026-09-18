# Codex Kickoff Prompt — Repository-Reconciled Program

Copy/paste this prompt into a new Codex session started from the DBVC repository root.

```text
We are resuming the repository-reconciled DBVC Visual Editor Media Manager, Brand Controls, and Frontend Workspace release program.

First read the implementation package at:

docs/dropins/dbvc-visual-editor-brand-controls-guide/

Read at minimum:
- README.md
- PACKAGE-UPDATE-NOTES.md
- 00-GOVERNING-DIRECTIVES.md
- 01-DISCOVERY-AND-CURRENT-STATE-REVIEW.md
- 02-TARGET-ARCHITECTURE-AND-BOUNDARIES.md
- media-manager/MEDIA-MANAGER-PRODUCT-SPEC.md
- media-manager/FIELD-ELIGIBILITY-AND-SCOPE.md
- releases/R0-DISCOVERY-BASELINE.md
- tracking/IMPLEMENTATION-TRACKER.md
- tracking/MEDIA-SCAN-COVERAGE-MATRIX.md
- tracking/EVIDENCE-LOG.md
- tracking/DECISION-LOG.md
- tracking/RISK-REGISTER.md

This package is product direction plus an evidence-backed R0 snapshot, not immutable code requirements. It was reconciled on codex/visual-editor-linked-posts-plan at clean, synchronized commit 5db4b40. Recheck branch, HEAD, status, divergence, and newer work without resetting, stashing, or discarding anything.

R0 is complete at that checkpoint. R1-A, R1-B, and R1-C are also implemented in the current review working tree as the default-off policy/catalog, bounded scanner/snapshot, and protected safe list/row-read foundation. Perform a read-only delta where repository state has changed, preserve the R0/R1-A/R1-B/R1-C work, and stop unless the user explicitly authorizes R1-D or another named slice. Documentation review or adaptation alone is not later-slice authorization.

The first implementation priority is a frontend Media Manager that scans eligible published/live pages, posts, public CPTs, and public/show-UI terms for empty native featured-image, ACF image, and ACF gallery assignments. Initial R1 ACF coverage is unconditional top-level and deterministic group-only paths. Repeater/flexible/mixed ancestry, conditional fields, option owners, and user owners are deferred. R1 is scan/report only; R2 adds per-field direct remediation through fresh descriptors, an expected-empty precondition, existing Visual Editor contracts, and the native WordPress Media Library. Initial R2 has no Save Row or cross-entity bulk save.

Inspect the current DBVC branch, recent commits, working tree, Visual Editor architecture, toolbar/overflow, descriptor/resolver/mutation systems, image/gallery/featured-image controls, Media Library lifecycle, object navigation/permissions, journal/cache behavior, existing scanners/jobs/sessions, tests, fixtures, docs, drop-ins, context files, JSON inventories, and any field catalogs.

Also inspect the VerticalFramework repository as read-only evidence at:
/Users/rhettbutler/Documents/LocalWP/frameworkflo-live/app/public/wp-content/themes/vertical

Search its docs, context, inventories, manifests, acf-json files, field groups/location rules, image/gallery fields, option pages, current Media Health/missing-file scanner work, and existing DBVC integration evidence. Do not modify VerticalFramework during discovery.

For a changed repository, refresh only affected parts of:
1. repository and working-tree status;
2. current architecture/file/symbol map;
3. existing scan/job/catalog evidence;
4. end-to-end featured-image, ACF image, and ACF gallery read/edit/save trace;
5. descriptor strategy for fields not rendered on the current page;
6. eligible entity and field/path coverage matrices;
7. representative scan/performance baseline;
8. useful VerticalFramework evidence with exact paths;
9. current Shared Globals trace and ACF option-family support matrix;
10. current test baseline and gaps;
11. conflicts between the package and current code;
12. the five-slice R1 plan and per-field R2 plan;
13. risks, decisions, feature isolation, observability, and rollback.

Known validation state: the inherited checkpoint had 6 deterministic PHP failures out of 684 tests. After R1-B, the clean comparison retained exactly those six identities across 694 tests/7,302 assertions. R1-C focused coverage passes 6 tests/417 assertions, and the combined R1-A/R1-B/R1-C plus current Visual Editor instrumentation focus passes 23/603. Use the package tracker for the final R1-C full-suite and agent-document comparison. Full repository JavaScript lint did not complete, and R1-A/R1-B/R1-C changed no JavaScript. Preserve those explicit limits and do not claim a green full suite/lint without newer completed evidence.

Update the package tracking files with actual evidence where appropriate. Search locally before asking questions. Ask only if a product decision materially changes behavior and cannot be resolved from the repositories.
```
