# Migration Mapper V2 Route and Artifact Ledger

## Purpose

This is the thin runtime ledger for the current V2 implementation.

Use it as a quick index for active V2 REST surfaces, identifiers, and artifact families.

This file is intentionally short. The authoritative contract detail still lives in:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_CONTRACTS.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOMAIN_JOURNEY.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_PACKAGE_SPEC.md`

## Naming Rules

- REST namespace: `dbvc_cc/v2`
- UI `runId` == artifact `journey_id`
- UI `pageId` == inventory `page_id`
- `packageId` selects a package build for a run

## Active REST Surface

### Runs and journey

- `GET /runs`
  - list latest runs
- `POST /runs`
  - create a run
  - can trigger schema sync, capture, and AI pipeline flow
- `GET /runs/{run_id}`
  - get run summary
- `GET /runs/{run_id}/overview`
  - get run overview surface
- `GET /runs/{run_id}/readiness`
  - get readiness summary and page reports
- `POST /runs/{run_id}/urls/{page_id}/rerun`
  - rerun a supported stage for one URL

### Review

- `GET /runs/{run_id}/exceptions`
  - load the exception-first review queue
- `GET /runs/{run_id}/urls/{page_id}`
  - load one URL review payload
- `POST /runs/{run_id}/urls/{page_id}/decision`
  - persist mapping and media review decisions

### Package

- `GET /runs/{run_id}/package`
  - load the package, readiness, workflow-state, and import-history surface
- `POST /runs/{run_id}/package`
  - build a package for the run

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

### Package build artifacts

- `{package_id}/package-manifest.v1.json`
- `{package_id}/package-records.v1.json`
- `{package_id}/package-media-manifest.v1.json`
- `{package_id}/package-qa-report.v1.json`
- `{package_id}/package-summary.v1.json`
- `{package_id}/import-package.v1.zip`

## Current Workspace Mapping

- `runs`
  - run listing and selection
- `overview`
  - run-level summary
- `exceptions`
  - review queue and inspector flow
- `readiness`
  - page-level QA and package readiness
- `package`
  - package history, package detail, workflow state, persisted import history, dry-run bridge surface, and package import bridge controls

## Current Runtime Note

The currently defined implementation-guide phases are closed through `Phase 8`, and `Phase 9` is now open for operational UI and runtime polish.

The route surface now includes package dry-run, preflight approval, and execute bridging under the V2 namespace.

The remaining documented runtime focus inside `Phase 9` is the reuse-alignment audit for the future crawl-start UI tranche, not new pipeline-stage logic.
