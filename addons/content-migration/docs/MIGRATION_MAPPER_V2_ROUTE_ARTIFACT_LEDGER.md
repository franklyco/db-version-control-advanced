# Migration Mapper V2 Route and Artifact Ledger

## Purpose

This is the thin runtime ledger for the current V2 implementation.

Use it as a quick index for active V2 REST surfaces, identifiers, and artifact families.

This file is intentionally short. The authoritative contract detail still lives in:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_CONTRACTS.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOMAIN_JOURNEY.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_PACKAGE_SPEC.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_CRAWL_REUSE_AUDIT.md`

## Naming Rules

- REST namespace: `dbvc_cc/v2`
- UI `runId` == artifact `journey_id`
- UI `pageId` == inventory `page_id`
- `packageId` selects a package build for a run

## Active REST Surface

### Runs and journey

- `GET /runs`
  - list latest runs
  - now returns additive `runProfile`, `actionSummary`, `hidden`, and `hiddenAt` metadata for run-card actions
- `POST /runs`
  - create a run
  - accepts `domain`, `sitemapUrl`, `maxUrls`, `forceRebuild`, and `crawlOverrides`
  - also accepts optional dev-only `qaReplaySourceRunId` to clone deterministic replay QA state through the same create-run transport when the helper is enabled
  - can trigger schema sync, capture, and AI pipeline flow
  - the current V2 UI now reuses this same route for both first-run creation and profile-backed replay from run cards
- `GET /runs/{run_id}`
  - get run summary
- `POST /runs/{run_id}/visibility`
  - hide or restore a run from the default runs list for the current operator
- `POST /runs/{run_id}/qa/recovery-fixture`
  - dev-only, current-user-scoped helper for deterministic rerun QA
  - overlays `actionSummary.rerunCandidates` for the selected run without mutating stored journey artifacts
  - intended for local or PHPUnit validation only, with helper availability gated outside normal production runtime
- `GET /runs/{run_id}/overview`
  - get run overview surface
  - powers the selected-run overview summary cards, stage cards, recent activity, and read-oriented next-action navigation
  - current V2 UI auto-refreshes this route every `10s` for non-terminal runs and does not require a separate recent-activity route yet
- `GET /runs/{run_id}/readiness`
  - get readiness summary and page reports
  - readiness-adjacent package preview rows can now carry additive target presentation metadata for `field_values[]` and `media_refs[]`
- `POST /runs/{run_id}/urls/{page_id}/rerun`
  - rerun a supported stage for one URL

### Review

- `GET /runs/{run_id}/exceptions`
  - load the exception-first review queue
  - now returns queue-state counts for `conflicts`, `unresolved`, `stale`, `overridden`, `blocked`, and `readyAfterReview`
  - queue rows can now carry additive `queueState`, `queueStateLabel`, and `quickAction` metadata so the UI can route directly into the relevant resolver tab without changing stored artifacts
- `GET /runs/{run_id}/urls/{page_id}`
  - load one URL review payload
  - now returns additive schema presentation metadata for target objects, field or media recommendations, conflicts, and persisted decisions while preserving stable machine refs such as `target_ref`
  - the current inspector uses this route to drive `summary`, `mapping`, `conflicts`, `source`, and `audit` tabs plus queue-preserving next/previous navigation
- `POST /runs/{run_id}/urls/{page_id}/decision`
  - persist mapping and media review decisions
  - the current inspector now uses this stable decision route together with local stale-reset and unsaved-change guardrails instead of introducing a second decision-write endpoint

### Package

- `GET /runs/{run_id}/package`
  - load the package, readiness, workflow-state, and import-history surface
  - the selected package can now carry additive `artifactActions` metadata for manifest, summary, QA, records, media, and ZIP actions
- `POST /runs/{run_id}/package`
  - build a package for the run

### Package artifact download

- `admin-post.php?action=dbvc_cc_v2_package_artifact_download`
  - authenticated package artifact download transport for manifest, summary, QA, records, media, and ZIP files
  - requires `runId`, `packageId`, `artifact`, and a per-artifact nonce
  - keeps downloads scoped to the selected V2 package without exposing raw storage paths as the primary UI affordance

### Import bridge

- `GET /runs/{run_id}/dry-run`
  - build the package-first dry-run surface
- `POST /runs/{run_id}/preflight-approve`
  - issue package-scoped preflight approval tokens from package-backed dry-run executions
- `POST /runs/{run_id}/execute`
  - execute the package import bridge through the shared import executor guardrails and journaling path

## Current Artifact Families

### Domain-scoped system artifacts

- `_journey/domain-journey.ndjson`
- `_journey/domain-journey.latest.v1.json`
- `_journey/domain-stage-summary.v1.json`
- `_journey/run-request-profile.latest.v1.json`
- `_inventory/domain-url-inventory.v1.json`
- `_learning/domain-pattern-memory.v1.json`
- `_packages/package-builds.v1.json`
- `_inventory/dbvc_cc_target_object_inventory.v1.json`
- `_inventory/dbvc_cc_target_field_catalog.v2.json`

### Per-page artifacts

- `{slug}.json`
- `{slug}.source-normalization.v1.json`
- `{slug}.elements.v2.json`
- `{slug}.sections.v2.json`
- `{slug}.ingestion-package.v2.json`
- `{slug}.context-creation.v1.json`
- `{slug}.initial-classification.v1.json`
- `{slug}.mapping-index.v1.json`
- `{slug}.target-transform.v1.json`
- `{slug}.mapping-recommendations.v2.json`
- `{slug}.mapping-decisions.v2.json`
- `{slug}.media-candidates.v2.json`
- `{slug}.media-decisions.v2.json`
- `{slug}.qa-report.v1.json`
- `_runs/{run_id}/{artifact filename}`
  - preserved per-run copies of page-level artifacts for same-URL historical reads
  - historical run readers prefer the current URL-scoped artifact only when its `journey_id` still matches the requested run; otherwise they fall back to the preserved `_runs/{run_id}/` copy before treating the artifact as missing

### Package build artifacts

- `{package_id}/package-manifest.v1.json`
- `{package_id}/package-records.v1.json`
- `{package_id}/package-media-manifest.v1.json`
- `{package_id}/package-qa-report.v1.json`
- `{package_id}/package-summary.v1.json`
- `{package_id}/import-package.v1.zip`

## Current Workspace Mapping

- `runs`
  - run listing, run selection, direct replay, duplicate-settings prefill, stage-group rerun helpers, hidden-run cleanup, and the V2-native run-start surface
- `overview`
  - run-level summary plus route-aware next-action shortcuts into the first blocked or reviewable URL, readiness audit targets, and the latest built package
- `exceptions`
  - review queue and inspector flow
- `readiness`
  - page-level QA and package readiness, with blocker actions that route into exceptions, audit, or package without a second readiness endpoint
- `package`
  - package history, package detail, workflow state, persisted import history, dry-run bridge surface, package import bridge controls, selected-package artifact drill-ins plus signed download actions, and direct blocker shortcuts back into exceptions or readiness

## Current Runtime Note

The currently defined implementation-guide phases are now closed through `Phase 22`, with `Phase 23` focused on browser validation for historical review fidelity after same-domain same-URL replay or rerun chains.

The route surface now includes run-start, selected-run monitoring, recent activity, enriched review payloads, explicit single-item review controls in the inspector, stale reset affordances, route-level unsaved-change safeguards, readiness blocker shortcuts, package dry-run, preflight approval, and execute bridging under the V2 namespace.

The run-create route is the current backend for the V2 crawl-start UI and accepts per-run crawl settings through `crawlOverrides`.

The readiness workspace now reuses `GET /runs/{run_id}/readiness` plus route-state filters such as `filter=review`, `filter=qa`, `filter=package`, and `filter=ready` to open the filtered exceptions queue, the inspector audit tab, or the package workspace without introducing a second readiness endpoint.

Overview, readiness, and package now share the same route-aware operator action model, which means direct shortcuts preserve `runId`, keep `packageId` available for package return paths, and reset only the query fragments needed to make the targeted blocker visible in the destination workspace.

The exceptions workspace now layers low-risk bulk review on top of the existing single-page review contract. Bulk approve and defer actions do not introduce a new REST route; they iterate through the existing `POST /runs/{run_id}/urls/{page_id}/decision` route so each selected URL still writes its own decision artifacts and journey events.

The package surface now exposes confirmation-guarded preflight or execute controls, signed artifact download actions, and in-app drill-ins for manifest, summary, QA, records, and media before execute.

The current open guide slice is `P23-T1`. The runtime now applies the same run-scoped page artifact rule across overview, readiness, package, exceptions, review payloads, historical decision saves, and historical rerun writes, and the next tranche should validate those operator-facing browser paths rather than adding new review surfaces.
