# Codex Prompt — R1 Frontend Media Manager Scan and Report

```text
Implement R1 of the updated DBVC Visual Editor program: Frontend Media Manager Scan and Report.

R0 must be complete. Read:
- docs/dropins/dbvc-visual-editor-brand-controls-guide/00-GOVERNING-DIRECTIVES.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/02-TARGET-ARCHITECTURE-AND-BOUNDARIES.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/media-manager/MEDIA-MANAGER-PRODUCT-SPEC.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/media-manager/FIELD-ELIGIBILITY-AND-SCOPE.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/media-manager/SCAN-AND-FINDING-CONTRACT.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/media-manager/TABLE-AND-ROW-INTERACTION-SPEC.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/releases/R1-MEDIA-MANAGER-SCAN-AND-REPORT.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/quality/SECURITY-AND-DATA-SAFETY.md
- docs/dropins/dbvc-visual-editor-brand-controls-guide/quality/TEST-QA-RELEASE-GATES.md
- the completed evidence, decisions, risks, coverage matrix, and tracker

Adapt to the actual R0 extension points. Preserve uncommitted work. Do not add R2 mutation behavior.

Reconfirm branch/HEAD/status first. The reconciled documentation checkpoint is clean, synchronized commit 5db4b40 on codex/visual-editor-linked-posts-plan, but newer work is authoritative and must be preserved. R1-A, R1-B, and R1-C are implemented in the current review working tree: default-off setting, owner eligibility, exact ACF visibility catalog, unsupported-path counts, raw media assignment classification, bounded candidate traversal, supported-value scanning, deterministic opaque findings, compressed user/blog-bound snapshot lifecycle, protected active-mode lifecycle/list routes, safe bounded result projection, and single-row current-state revalidation. Do not redo them. R1-D and every later slice still require an explicit user crossing line.

Required production outcome:
- user-triggered bounded scan of eligible live pages/posts/public CPTs/public terms;
- supported sources limited to native featured image, ACF image, and ACF gallery;
- a new narrow request-batched coordinator because R0 found no compatible scanner/session contract to reuse wholesale;
- an exact ACF applicability catalog over active runtime groups using acf_get_field_group_visibility();
- initial ACF paths limited to unconditional top-level and deterministic group-only image/gallery fields;
- compact user/site-bound scan snapshot with progress, expiry, retry, and summary counts;
- opaque non-authoritative finding/group references;
- searchable/filterable/sortable/paginated or virtualized result table in a frontend popup/drawer;
- explicit accessible row expansion;
- fresh row revalidation and descriptor-status discovery without assignment/saving;
- no eager full descriptors or attachment metadata and no new Media Library/editor enqueue beyond the current active-mode baseline;
- feature flag/fallback, tests, performance evidence, release notes, and rollback.

Before final production UI:
1. freeze the verified safe view model, actions, and states;
2. use ui-ux/MEDIA-MANAGER-CLAUDE-MOCKUP-HANDOFF.md with the included concept image and fixture;
3. review static HTML/CSS and record accepted/adapted/rejected decisions;
4. implement accepted intent in existing DBVC components and scoped styles.

Do not scan arbitrary meta, expose unauthorized entities, hardcode Vertical CPTs/fields into DBVC core, build a custom persistent job/table without measured necessity, or implement media selection, upload, save, Save Row, or Save Selected.

Implement only the five accepted planning slices in order. Policy/catalog (R1-A), bounded scanner/snapshot (R1-B), and safe list/read-row contract (R1-C) are complete for review. Continue with Claude static mockups and frontend table translation only when R1-D is explicitly authorized, then separately gate hardening/release. The R1-C view model is the authority for mockup data and actions. Do not include repeater, flexible-content, mixed nested ancestry, conditional unknowns, option owners, or user owners in the initial scan.

Known validation state: the inherited checkpoint had 6 deterministic PHP failures out of 684 tests. After R1-B, the clean comparison retained exactly those six identities across 694 tests/7,302 assertions. R1-C focused coverage passes 6 tests/417 assertions, and the combined R1-A/R1-B/R1-C plus current Visual Editor instrumentation focus passes 23/603. Use the tracker for the final R1-C full-suite and agent-document comparison. Full repository JavaScript lint did not complete, and R1-A/R1-B/R1-C changed no JavaScript. Run focused checks for touched code, distinguish inherited failures from regressions, and do not claim full lint success without a completed command. Run composer agent-docs:check when public REST/settings/add-on surfaces change.

Work in small reviewable slices. Run and record automated, browser, accessibility, builder-isolation, and large-site performance tests. Update all tracking files and stop after the R1 release gate.
```
