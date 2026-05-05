# Migration Mapper V2 Implementation Guide

## Purpose

This guide defines the official implementation rules for `Migration Mapper V2`.

It exists to keep V2 aligned with the intended product shape while implementation is still phased and incomplete.

The core V2 promise is:

`crawl source content -> automatically build a target-ready import package -> only require user input for exceptions, overrides, and approvals`

For the coordinated V2 planning set and recommended reading order, start with:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOC_INDEX.md`

For low-token resume context during active implementation, read next:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DECISIONS.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_ROUTE_ARTIFACT_LEDGER.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_CRAWL_REUSE_AUDIT.md`

## Product Goal

V2 should let an operator:

1. choose a source crawl
2. use the current WordPress site as the target schema authority
3. run an automated package build
4. review only flagged exceptions
5. override when needed
6. export or import a refined package

The current WordPress site with the DBVC plugin installed is the target environment. If that site uses a framework such as `VerticalFramework`, its CPTs, taxonomies, registered meta, and ACF fields define the schema V2 must target.

## UX Direction

V2 should be built as a calm, modern, run-based operational workspace.

The intended operator flow is:

`start run -> monitor progress -> review exceptions -> inspect readiness -> approve dry-run/import`

Required UX principles:

- review by exception
- progressive disclosure
- preserve context while drilling down
- summary first, evidence second, controls third
- minimal shell, rich inspection surfaces
- calm at first glance, dense only when expanded

Default screens should emphasize:

- status
- summaries
- exceptions
- readiness
- next actions

Advanced detail should appear on demand through:

- row expansion
- drawers
- tabs
- accordions
- toggleable inspector panels
- evidence inspectors
- mapping previews
- audit and debug reveals

For detailed screen and component guidance, use:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_UI_ARCHITECTURE.md`

## Non-Negotiable Rules

1. V2 implementation must be tracked in phases with checklist status.
   Every phase and task should be marked `OPEN`, `WIP`, or `CLOSED`.

2. V2 must use runtime gating in the main DBVC Add-ons screen.
   The operator should be able to enable or disable Content Collector and choose `v1` or `v2` from `DBVC -> Configure -> Add-ons`.

3. V2 must live in a dedicated `v2` path.
   New runtime code should be built under `addons/content-migration/v2/`, not spread across V1 folders.

4. V2 must avoid monolith files.
   No new all-in-one service should combine crawl, AI, review, schema, and package logic in one file.

5. V2 operational surfaces should use a modular React workspace architecture.
   The main run workspace should be React-driven, while simple Add-ons settings stay server-rendered.

6. V2 must be automation-first.
   Manual work should be reduced through automation, pattern reuse, and confidence-driven auto-accept behavior.

7. Review must be exception-based.
   Operators should not have to inspect every URL by default.

8. Manual override must remain first-class.
   Per-URL manual controls must include target object type override, field-level override, media override, and per-stage rerun controls.

9. The import-ready package is the primary deliverable.
   Recommendations are an intermediate product. The package is the product output.

## Runtime Gating and Configure UI

V2 should follow the same broad operational model already used by the Bricks add-on:

- deterministic defaults
- runtime registration refresh
- Add-ons screen settings as the operator entry point
- conditional page, route, asset, and cron registration based on enablement

### Required Add-ons UI controls

In `DBVC -> Configure -> Add-ons`, add a `Content Collector` section with:

- `Enable Content Collector`
- `Runtime Version`

Recommended runtime version values:

- `v1`
- `v2`

### Required runtime behavior

When `Content Collector` is disabled:

- do not register Content Collector admin pages
- do not register V1 or V2 REST routes beyond any minimal settings bridge needed for the Add-ons screen
- do not run scheduled jobs for Content Collector
- do not enqueue addon-specific assets

When `Content Collector` is enabled and `Runtime Version = v1`:

- register the current V1 runtime
- render V1 pages and controllers
- keep V2 modules dormant

When `Content Collector` is enabled and `Runtime Version = v2`:

- register the V2 runtime
- render a dedicated V2 run workspace plus supporting V2 pages and controllers
- keep legacy V1 review and runtime surfaces dormant except for shared adapters explicitly reused by V2
- keep Add-ons configuration server-rendered while the operational workspace uses the V2 React app

### Recommended option keys

Reuse:

- `dbvc_cc_addon_enabled`

Add:

- `dbvc_cc_runtime_version`

Allowed values:

- `v1`
- `v2`

Recommended initial default:

- `dbvc_cc_runtime_version = v1`

## Advanced V2 Automation Settings

V2 confidence and automation policy should be adjustable from advanced Content Collector settings instead of being hard-coded.

Recommended placement:

- `DBVC -> Configure -> Add-ons -> Content Collector`
- advanced V2 automation settings should live behind a collapsed advanced section or settings group

Recommended option keys and defaults:

- `dbvc_cc_v2_auto_accept_min_confidence = 0.92`
- `dbvc_cc_v2_block_below_confidence = 0.55`
- `dbvc_cc_v2_resolution_update_min_confidence = 0.94`
- `dbvc_cc_v2_pattern_reuse_min_confidence = 0.90`
- `dbvc_cc_v2_require_qa_pass_for_auto_accept = true`
- `dbvc_cc_v2_require_unambiguous_resolution_for_auto_accept = true`
- `dbvc_cc_v2_require_manual_review_for_object_family_change = true`

Policy evaluation order:

1. hard block wins first
2. auto-accept only happens when confidence and policy gates all pass
3. all remaining items land in review-by-exception queues

Recommended initial behavior:

- confidence below `block_below_confidence` should mark the URL or recommendation as blocked
- confidence at or above `auto_accept_min_confidence` may auto-accept only if QA passes and target resolution is unambiguous
- confidence between those bounds should remain reviewable, not silently blocked

These defaults should be domain-safe but globally configurable. The settings themselves may be global, but learned patterns, reviewer decisions, and run outcomes must remain domain-scoped.

## V2 Path and Modularity Rule

All new V2 runtime code should live under:

- `addons/content-migration/v2/`

Recommended module layout:

```text
addons/content-migration/v2/
  admin/
  admin-app/
  ai-context/
  bootstrap/
  capture/
  discovery/
  extraction/
  import/
  journey/
  mapping/
  media/
  package/
  patterns/
  review/
  schema/
  shared/
  transform/
```

### Modularity rules

- one service per primary responsibility
- one orchestrator per pipeline layer at most
- controllers should stay thin
- contracts and option keys should stay centralized in `v2/shared/`
- V1 reuse should happen through adapters or wrapper services, not by letting V2 sprawl into V1 directories
- React workspaces, components, hooks, API clients, and state modules should remain separated

### Explicit anti-patterns

Avoid introducing:

- one giant AI service for all V2 reasoning
- one giant admin page controller for all V2 UI behavior
- one giant React screen that owns layout, data loading, table state, inspector state, and modal logic together
- one giant package builder that also owns review, schema sync, and journey logging
- one mixed folder where V1 and V2 runtime files are interleaved without clear boundaries

## Manual Override Requirements

Per-URL override controls must support:

- target object type override
- field mapping override
- taxonomy mapping override
- media mapping override
- explicit approve or reject decisions
- per-stage rerun requests

### Target object type override requirements

The reviewer must be able to override the recommended target object type for an individual URL.

Examples:

- change a URL from `page` to a CPT
- change a URL from one CPT to another CPT
- route a URL to a taxonomy or term workflow instead of a post-like object
- keep the AI recommendation but mark the object as blocked for follow-up

The decision artifact must preserve:

- whether the target object was auto-selected or manually overridden
- selected target family
- selected target object key
- selected taxonomy when applicable
- reviewer reason or note

## Review and Rerun Requirements

The operator should see:

- exception queues
- blocked URLs
- low-confidence URLs
- stale URLs
- policy-sensitive URLs
- manually overridden URLs

The operator should be able to trigger reruns on one URL for:

- context creation
- initial classification
- mapping index
- target transform
- recommendation finalization
- QA validation

## Target Resolution Policy Direction

V2 should reduce operator work, but target resolution still needs strict policy ordering.

Recommended resolution modes:

- `update_existing`
- `create_new`
- `blocked_needs_review`
- `skip_out_of_scope`

Recommended resolution priority:

1. manual override or explicit reviewer decision
2. prior DBVC linkage or idempotency metadata proving an existing target object
3. exact in-scope target match for the selected object family and object key
4. create-new when there is no eligible existing target and required create inputs are present
5. blocked when ambiguity, unsupported shape, or missing required context remains

Recommended edge-case handling:

- duplicate candidate matches in the same object family should block
- same slug in different object families should block unless the reviewer explicitly overrides the family
- taxonomy or term resolution must stay scoped to the selected taxonomy
- hierarchical targets with unresolved parent requirements should block
- front page, archive, feed, search, and utility URLs should be filtered earlier as out of scope and should not reach create or update resolution
- objects marked do-not-overwrite by future policy hooks should block
- if the system predicts a family change relative to a prior accepted decision, require manual review by default

Recommended rule for package assembly:

- package records should carry the selected resolution mode and the evidence used to derive it
- blocked resolution should prevent package readiness from becoming `ready_for_import`

## Domain Isolation Rule

V2 should treat every crawled domain as an isolated learning and decision boundary.

Required behavior:

- domain journey logs are domain-scoped
- pattern memory is domain-scoped
- reviewer decisions are domain-scoped
- package outputs are domain-scoped
- one domain's learned mapping behavior must not automatically influence another domain

Allowed shared layers:

- code
- prompt templates
- default automation settings
- generic schema and transformation logic

Disallowed spillover:

- reusing learned patterns from one source domain on another source domain
- carrying forward accepted mappings from one source domain into another domain without a fresh run
- resolving targets for one domain by consulting another domain's pattern memory or reviewer history

## Package-First Rule

V2 should be judged by whether it can produce a high-quality import-ready package for the current site schema.

That package should already contain:

- target object decisions
- target-ready field values
- target-ready taxonomy values
- target-ready media references
- traceability back to source evidence
- QA status
- override and rerun history

## Documentation and Task Convention

Implementation should use these document roles:

- `MIGRATION_MAPPER_V2_DOC_INDEX.md` as the hub
- `MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md` as the rules and phase source
- `MIGRATION_MAPPER_V2_UI_ARCHITECTURE.md` as the UI source
- `MIGRATION_MAPPER_V2_CONTRACTS.md` as the artifact and route contract source
- `MIGRATION_MAPPER_V2_DOMAIN_JOURNEY.md` as the event and journey source
- `MIGRATION_MAPPER_V2_PACKAGE_SPEC.md` as the package source
- `MIGRATION_MAPPER_V2_FILE_PLAN.md` as the code structure source

Recommended work item ID format:

- `P1-T2-S3`

Meaning:

- `P#` = phase
- `T#` = task
- `S#` = subtask

Identifier naming rule:

- use `runId` in UI routes and REST responses
- persist the same identifier in artifacts as `journey_id`
- treat `runId` and `journey_id` as the same logical run identity, not two separate IDs

Recommended status vocabulary:

- `OPEN`
- `WIP`
- `BLOCKED`
- `CLOSED`

Every implementation round should update:

- task status
- acceptance evidence
- any doc sync fallout caused by the change

## Phase Tracking Model

Status vocabulary:

- `OPEN`
- `WIP`
- `BLOCKED`
- `CLOSED`

Each implementation phase should keep:

- a phase status
- a checklist of tasks
- acceptance criteria
- known dependencies or blockers

## Official Phase Checklist

### Phase 0: Contract and Planning Freeze

Phase status:
- `CLOSED`

Checklist:
- `[WIP]` Approve the V2 workflow as automation-first and package-first.
- `[WIP]` Freeze the domain journey artifact family and event vocabulary.
- `[WIP]` Freeze the canonical reviewer payload contracts.
- `[CLOSED]` Freeze package artifact names and readiness vocabulary.
- `[OPEN]` Approve the V2 UI architecture and run-based workspace model.
- `[OPEN]` Approve runtime gating behavior for `disabled`, `v1`, and `v2` states.
- `[OPEN]` Approve the dedicated `addons/content-migration/v2/` path rule.
- `[CLOSED]` Expand this guide into a granular `P#-T#-S#` implementation checklist before Phase 1 work starts.

Acceptance criteria:
- V2 planning docs are approved as the authoritative direction.
- Runtime version selection and package-first output are explicitly accepted.
- A granular implementation checklist exists inside this guide or as one tightly scoped companion doc before coding begins.

### Phase 1: Runtime Gating and V2 Scaffolding

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Add Content Collector controls to `DBVC -> Configure -> Add-ons`.
- `[CLOSED]` Reuse `dbvc_cc_addon_enabled` as the addon enable flag.
- `[CLOSED]` Add `dbvc_cc_runtime_version` with `v1` and `v2` values.
- `[CLOSED]` Introduce V2 bootstrap and runtime registration services.
- `[CLOSED]` Gate V1 and V2 page, route, cron, and asset registration by selected runtime version.
- `[CLOSED]` Create the initial `addons/content-migration/v2/` directory structure.
- `[CLOSED]` Scaffold the V2 React app shell and route or view coordinator.
- `[CLOSED]` Mount a dedicated V2 app root for operational surfaces.
- `[CLOSED]` Add the V2 build entrypoint files and asset-loading contract using the recommended `content-collector-v2-app` naming.
- `[CLOSED]` Install Playwright tooling in the repo and add the initial browser QA scaffold once the V2 app root loads.

Acceptance criteria:
- The operator can enable or disable Content Collector from the Add-ons tab.
- The operator can select `v1` or `v2`.
- The chosen runtime actually controls which addon UI and routes load.
- The V2 app bundle and asset manifest build under the agreed entrypoint names.

Current tranche notes:
- `2026-03-18`: Server-rendered Add-ons controls, runtime gating, V2 scaffolding, V2 shell, build wiring, PHPUnit gating coverage, and Playwright smoke scaffolding landed.
- `2026-03-18`: LocalWP gating validation covered `disabled`, `v1`, and `v2`; the smoke harness was updated to support both wp-admin and custom front-end login flows, and further Playwright reruns were deferred to protect implementation velocity.

### Phase 2: Domain Journey and Target Schema Sync

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Implement append-only domain journey logging.
- `[CLOSED]` Add materialized latest-state and stage-summary files.
- `[CLOSED]` Build target object inventory from the current site.
- `[CLOSED]` Build target field schema catalog for narrowed targets.
- `[CLOSED]` Record schema fingerprints for freshness checks.

Acceptance criteria:
- Each domain has a transparent journey log.
- The current site's object and field schema can be queried by V2 services.

Current tranche notes:
- `2026-03-18`: A V2-only journey module now registers storage-root hooks, schema snapshot seeding, and run-based readiness routes only when the addon runtime is set to `v2`.
- `2026-03-18`: Phase 2 artifacts now include append-only `domain-journey.ndjson`, materialized latest and stage summary projections, `dbvc_cc_target_object_inventory.v1.json`, and `dbvc_cc_target_field_catalog.v2.json`.
- `2026-03-18`: PHPUnit validation covered schema-sync artifact generation, per-domain journey isolation, and V2-only REST route gating. Per-URL journey projections, discovery inventory, crawl, AI, mapping, media, QA, and package logic remain deferred to later phases.

### Phase 3: Discovery, Scope, Capture, and Extraction

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Implement domain URL discovery inventory.
- `[CLOSED]` Add URL normalization and dedupe.
- `[CLOSED]` Add URL eligibility and migration scope decisions.
- `[CLOSED]` Reuse or adapt page capture and artifact storage from V1.
- `[CLOSED]` Add source normalization before AI interpretation.
- `[CLOSED]` Reuse or adapt deterministic extraction and ingestion packaging from V1.

Acceptance criteria:
- V2 can crawl a domain into normalized, scoped, structured URL artifacts.
- Out-of-scope URLs are excluded before heavy downstream work.

Current tranche notes:
- `2026-03-18`: Phase 3 introduced a V2 sitemap-driven URL inventory, normalization and dedupe, explicit eligibility and scope decisions, and run-based overview payloads under the existing `dbvc_cc/v2` route family.
- `2026-03-18`: Eligible URLs now produce contract-shaped raw page artifacts, `*.source-normalization.v1.json`, `*.elements.v2.json`, `*.sections.v2.json`, and `*.ingestion-package.v2.json` while out-of-scope URLs stop before capture.
- `2026-03-18`: PHPUnit validation covered sitemap discovery, dedupe, out-of-scope exclusion, per-page artifact generation, generated `runId` format, and V2-only `overview` route gating. AI context, classification, mapping, media, QA, and package assembly remain deferred.

### Phase 4: AI Context and Classification

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Implement context creation artifacts and prompts.
- `[CLOSED]` Implement initial classification artifacts and prompts.
- `[CLOSED]` Persist confidence, rationale, and traceability for both stages.
- `[CLOSED]` Add targeted per-URL rerun support for both stages.

Acceptance criteria:
- Each eligible URL receives explainable context and object-type classification output.

Current tranche notes:
- `2026-03-18`: Phase 4 added deterministic `*.context-creation.v1.json` and `*.initial-classification.v1.json` artifacts with prompt-input traces, fallback metadata, confidence, rationale, alternate classifications, taxonomy hints, and review-state summaries.
- `2026-03-18`: V2 run creation now advances eligible captured URLs through context creation and initial classification automatically, while `POST /dbvc_cc/v2/runs/{run_id}/urls/{page_id}/rerun` supports per-URL reruns for `context_creation` and `initial_classification`.
- `2026-03-18`: PHPUnit validation covered contract-shaped Phase 4 artifact generation, needs-review materialization, auto-accept candidate classification, and V2-only rerun route gating. Mapping, media, QA, and package assembly remain deferred.

### Phase 5: Mapping, Media, Learning, and Transform

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Implement initial mapping and indexing against narrowed schema.
- `[CLOSED]` Reuse or adapt media candidate logic as a V2 media track.
- `[CLOSED]` Implement pattern reuse and learning across sibling URLs.
- `[CLOSED]` Implement target-value transformation into field-ready output shapes.
- `[CLOSED]` Implement target entity resolution preview.
- `[CLOSED]` Implement recommendation finalization into one canonical payload.

Acceptance criteria:
- Each URL can produce one canonical recommendation payload with mapped content, media, target object intent, and transform previews.

Current tranche notes:
- `2026-03-18`: Phase 5 added deterministic `*.mapping-index.v1.json`, `*.media-candidates.v2.json`, `*.target-transform.v1.json`, and `*.mapping-recommendations.v2.json` outputs for each eligible V2 URL without introducing Phase 6 override or reviewer-decision logic.
- `2026-03-18`: V2 runs now finalize one canonical recommendation payload per eligible URL, including narrowed-field candidates, media alignment, create-or-update resolution preview, and domain-scoped pattern memory in `_learning/domain-pattern-memory.v1.json`.
- `2026-03-18`: PHPUnit validation covered Phase 1 through Phase 5, including contract-shaped mapping artifacts, update-existing preview behavior, and domain-isolated pattern reuse. QA/package assembly remains deferred to later phases.

### Phase 6: Exception Review, Overrides, and Reruns

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Build an exception-first review queue.
- `[CLOSED]` Allow target object type override per URL.
- `[CLOSED]` Allow field and media overrides per URL.
- `[CLOSED]` Persist decision artifacts and reviewer notes.
- `[CLOSED]` Add per-stage rerun controls for individual URLs.
- `[CLOSED]` Show create, update, or blocked target resolution preview in the review UI.
- `[CLOSED]` Deliver inspector drawers, evidence tabs, and progressive disclosure rules in the review workspace.

Acceptance criteria:
- The operator can review exceptions instead of every URL.
- Manual overrides are fully captured and traceable.

Current tranche notes:
- `2026-03-18`: Phase 6 added `/dbvc_cc/v2/runs/{run_id}/exceptions`, `/dbvc_cc/v2/runs/{run_id}/urls/{page_id}`, and `/dbvc_cc/v2/runs/{run_id}/urls/{page_id}/decision` so the V2 workspace can load exception-first queue rows, URL inspector detail payloads, and decision mutations from dedicated review services.
- `2026-03-18`: V2 now persists `*.mapping-decisions.v2.json` and `*.media-decisions.v2.json` alongside the canonical recommendation artifact, including reviewer note capture, target object override state, field or taxonomy overrides, media overrides, recommendation fingerprints, and rerun metadata slots.
- `2026-03-18`: The V2 React app now renders an exception queue with route-backed filters, a populated inspector drawer with summary or source or mapping or audit tabs, and per-stage rerun controls for `context_creation`, `initial_classification`, `mapping_index`, and `recommendation_finalization`.
- `2026-03-18`: PHPUnit validation now covers Phase 1 through Phase 6, including exception queue payloads, inspector payload hydration, decision artifact writes, and single-URL mapping-stage reruns. QA and package assembly remain deferred to later phases.

### Phase 7: QA and Package Assembly

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Build URL-level QA reports.
- `[CLOSED]` Build package-level QA reports.
- `[CLOSED]` Assemble package manifest, records, media manifest, summary, and zip output.
- `[CLOSED]` Include override and rerun history in the package state.
- `[CLOSED]` Add package build history and readiness summaries.
- `[CLOSED]` Deliver readiness and preflight surfaces before import approval.

Acceptance criteria:
- V2 can produce a target-adapted import-ready package as the main deliverable.
- Package readiness is visible before dry-run or import.

Current tranche notes:
- `2026-03-18`: Phase 7 added a dedicated V2 package subsystem with `/dbvc_cc/v2/runs/{run_id}/package`, `_packages/package-builds.v1.json`, first-class package artifact families, and per-build zip output rooted under each domain-scoped `_packages/{package_id}/` directory.
- `2026-03-18`: The V2 readiness route now materializes `*.qa-report.v1.json` artifacts per URL, derives `ready_for_import` or `needs_review` or `blocked` readiness states from canonical recommendation and decision artifacts, and exposes blocker and warning summaries for the new readiness workspace.
- `2026-03-18`: The V2 React app now binds the `readiness` and `package` workspaces to live REST payloads, showing per-URL QA rows, aggregate blocker and warning groups, package build history, selected package detail, and a package build action with stable selectors.
- `2026-03-18`: PHPUnit validation now covers Phase 1 through Phase 7, including readiness route payloads, per-URL QA artifact writes, package build history persistence, package artifact family output, and zip generation. Dry-run and import consumers remain deferred to Phase 8.

### Phase 8: Dry-Run and Import Consumers

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Make dry-run consume the package as the preferred upstream input.
- `[CLOSED]` Make executor planning consume package-aligned records and QA state.
- `[CLOSED]` Preserve existing guardrails, journaling, and rollback behavior where reusable.

Acceptance criteria:
- Downstream import systems can consume V2 packages without depending on legacy V1 review artifacts.

Current tranche notes:
- `2026-03-18`: Phase 8 introduced a V2 import bridge under `addons/content-migration/v2/import/` and added `GET /dbvc_cc/v2/runs/{run_id}/dry-run`, using selected package records and media manifests as the preferred upstream input for dry-run planning.
- `2026-03-18`: The bridge now derives dry-run readiness from the selected package QA artifact instead of the broader run readiness surface, so package-first consumers stay scoped to the chosen package build.
- `2026-03-18`: The V2 package workspace now exposes a dry-run preview action and stable selectors for the downstream import-consumer summary, including surfaced write-barrier counts from the reused executor guardrails.
- `2026-03-18`: Phase 8 now adds `POST /dbvc_cc/v2/runs/{run_id}/preflight-approve` and `POST /dbvc_cc/v2/runs/{run_id}/execute`, reusing the shared import executor guardrails, journaling, approval tokens, and rollback behavior against package-backed dry-run executions.
- `2026-03-18`: The V2 package workspace now exposes package preflight and execute actions with stable selectors and shared import-run summary output.
- `2026-03-18`: PHPUnit validation now covers the missing-package guard, seeded package-record dry-run bridge flow, package preflight approval, package execute bridging, and rollback continuity in `ContentCollectorV2Phase8Test`. Phase 8 is closed.
- `2026-03-18`: Post-close UI hardening now resets package dry-run state whenever the selected run or package changes, preventing stale or auto-carried previews across package switches, and aligns the shell copy with the live Phase 8 bridge status.

### Phase 9: Operational Workflow Polish and Observability

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Surface import execution history in the V2 package workspace.
- `[CLOSED]` Improve package workflow observability across build, dry-run, preflight, and execute stages.
- `[CLOSED]` Confirm current V2 alignment with reused Content Collector and crawl primitives before a dedicated crawl-start UI tranche.

Acceptance criteria:
- Operators can review recent import executions, downstream import run identifiers, approval state, and rollback outcomes without reading raw artifacts directly.
- The selected package shows clear build, dry-run, preflight, and execute status, timing, and blocker context in one workspace flow.
- The implementation guide records whether the reused Content Collector and crawl primitives still align with V2 runtime needs and identifies any gaps to address before a first-class V2 crawl-start surface is added.

Current tranche notes:
- `2026-03-18`: Phase 9 is intentionally defined as an operational polish tranche, not a new business-pipeline tranche.
- `2026-03-18`: The immediate focus is import execution history, package workflow observability, and runtime-state clarity in the existing V2 workspace shell.
- `2026-03-18`: A dedicated in-app crawl-start workflow remains a follow-on tranche after Phase 9, but Phase 9 should explicitly confirm whether the currently reused Content Collector and crawl primitives still align with V2.
- `2026-03-18`: The V2 package surface now persists and reloads package-linked dry-run, preflight, and execute snapshots through package build history, exposing workflow state and import execution history without requiring direct artifact browsing.
- `2026-03-18`: The V2 package workspace now renders a workflow panel and persisted import history panel, and refreshes package workflow state after dry-run, preflight, and execute mutations.
- `2026-03-18`: PHPUnit validation now covers the persisted Phase 9 package workflow state and import history surface in `ContentCollectorV2Phase9Test`.
- `2026-03-18`: The Phase 9 crawl audit confirms that V2 already reuses the shared crawl override sanitizer, effective crawl option resolver, sitemap parser, artifact manager, selector helpers, extraction primitives, schema snapshot/catalog services, and import executor bridge.
- `2026-03-18`: `POST /dbvc_cc/v2/runs` now accepts `crawlOverrides`, closing the transport gap between the reused crawl override model and the V2 run-creation route ahead of a first-class crawl-start UI.
- `2026-03-18`: The audit also confirms that the V1 collect tab and `admin-ajax` crawl flow should stay dormant; the follow-on crawl-start tranche should build on the V2 `runs` workspace and `/runs` route instead.

### Phase 10: V2 Crawl-Start UI

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Add a first-class crawl-start flow to the V2 `runs` workspace.
- `[CLOSED]` Surface supported per-run crawl overrides in V2 without reviving the V1 collect tab.
- `[CLOSED]` Add operator-friendly run-creation progress, validation, and error states around the existing V2 `/runs` contract.

Acceptance criteria:
- Operators can start a crawl-backed V2 run from the `runs` workspace without leaving the V2 app or issuing manual REST requests.
- The run-create surface uses the existing V2 `/runs` route with `domain`, `sitemapUrl`, `maxUrls`, `forceRebuild`, and `crawlOverrides`.
- Advanced per-run crawl controls are prefilled from the shared Configure defaults and remain aligned with the shared crawl override sanitizer.
- The new UI does not reactivate the V1 collect tab, legacy collect-page JavaScript, or `admin-ajax` crawl handlers.

Current tranche notes:
- `2026-03-18`: Phase 10 begins only after the Phase 9 audit closed the reuse boundary between healthy shared crawl primitives and dormant V1 operator transport.
- `2026-03-18`: The next implementation focus is operator-facing V2 run creation, not new backend crawl pipeline stages.
- `2026-03-18`: The V2 `runs` workspace now includes a first-class run-start form that submits `domain`, `sitemapUrl`, `maxUrls`, and `forceRebuild` through the existing `/runs` contract and selects the created run after success.
- `2026-03-18`: The same form now exposes the supported per-run advanced crawl override surface, prefilled from the shared Configure defaults and submitted through `crawlOverrides`.
- `2026-03-18`: Stable selectors for the run-start form, submit button, and advanced-toggle surface are now localized for future Playwright coverage.
- `2026-03-18`: The `runs` workspace now renders a request lifecycle panel with elapsed timing, attempted input summary, success and failure alerts, and the latest stage snapshot returned by the run-create response.
- `2026-03-18`: Targeted LocalWP browser QA is resumed and now covers direct V2 workspace load, drawer toggle behavior, and run-start lifecycle visibility without relying on the legacy admin-menu hover path.
- `2026-03-18`: The LocalWP site's custom `/login` page can still render its front-end form for authenticated admins, so the Playwright harness now navigates directly to `admin.php?page=dbvc_cc&view=runs` and only submits credentials when the admin bar is absent.

### Phase 11: Run Monitoring and Overview Observability

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Replace the placeholder selected-run overview with a real operational summary.
- `[CLOSED]` Surface stage progress, counts, and next actions for a selected run without raw artifact browsing.
- `[CLOSED]` Add refresh and stale-state clarity for active runs while keeping scope out of new pipeline logic.

Acceptance criteria:
- Operators can select a run and see current stage, pipeline progress, high-signal counts, and next actions from the existing V2 overview payload.
- The overview surface uses the current V2 `/runs/{run_id}/overview` route before any new backend route is introduced.
- Active runs show clear refresh state, last-updated context, and stale-state cues without requiring the operator to bounce between workspaces.
- The tranche does not add new crawl, capture, AI, mapping, media, QA, or package business logic beyond what is needed to present existing run state clearly.

Current tranche notes:
- `2026-03-19`: Phase 11 is intentionally a post-run operator observability tranche, not a new crawl pipeline tranche.
- `2026-03-19`: The selected-run `overview` workspace now consumes the existing `latest`, `inventory`, and `stageSummary` payload from `/runs/{run_id}/overview` instead of placeholder copy.
- `2026-03-19`: Active runs now auto-refresh every `10s`, expose manual refresh at all times, and show explicit last-updated or stale-state copy without introducing new backend routes.
- `2026-03-19`: The overview surface stays read-oriented in this tranche and limits next actions to deterministic navigation into `exceptions`, `readiness`, and `package`.
- `2026-03-19`: The current implementation intentionally stops at the materialized overview payload and leaves richer event-timeline data for a later tranche if manual testing proves it necessary.
- `2026-03-19`: Targeted LocalWP Playwright coverage now validates direct V2 workspace load, drawer toggle behavior, run-start lifecycle visibility, and selected-run overview hydration plus refresh state.

### Phase 12: Recent Activity Timeline and Event Observability

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Add a read-only recent-activity slice to the selected-run overview.
- `[CLOSED]` Reuse the existing journey log and overview route instead of inventing a parallel event API prematurely.
- `[CLOSED]` Extend validation so recent activity is visible in both PHP and browser QA.

Acceptance criteria:
- Operators can inspect recent run activity from the selected-run overview without leaving the V2 app or opening raw artifacts.
- The recent-activity surface is sourced from the existing V2 journey log and exposed through the current `/runs/{run_id}/overview` payload.
- The activity feed remains read-oriented and does not add new mutation controls or broaden pipeline logic.
- Active runs continue using the existing overview refresh behavior so recent activity and summary cards stay in sync.

Current tranche notes:
- `2026-03-19`: Phase 12 should extend the existing overview route before adding a dedicated timeline endpoint.
- `2026-03-19`: The safest backend reuse path is the append-only journey NDJSON already materialized for V2 runs.
- `2026-03-19`: The UI should treat recent activity as operator evidence, not as a control-center mutation surface.
- `2026-03-19`: `GET /runs/{run_id}/overview` now exposes a bounded `recentActivity` slice derived from the existing journey log for the selected run.
- `2026-03-19`: The overview workspace now renders a dedicated recent-activity panel with stable selectors while keeping the surface read-oriented.
- `2026-03-19`: Targeted validation now covers recent activity through PHPUnit route/service assertions and LocalWP Playwright overview assertions.

### Phase 13: Reviewability Foundation and Schema Label Enrichment

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Replace raw target refs with human-readable schema labels across review surfaces.
- `[CLOSED]` Build explicit single-item recommendation decisions before expanding bulk or control-center actions.
- `[CLOSED]` Add stale and unsaved-decision safeguards so review work is trustworthy.

Acceptance criteria:
- Field, taxonomy, media, and ACF targets are displayed with human-readable labels plus machine refs as secondary detail.
- Operators can explicitly `approve`, `reject`, `override`, or `leave unresolved` for individual recommendations without typing raw target refs first.
- The V2 inspector warns on unsaved changes and clearly marks stale decisions caused by recommendation drift.
- The tranche reuses the existing target field catalog and review payloads instead of inventing a second schema-label source.

Current tranche notes:
- `2026-03-19`: Raw `target_ref` strings are still too technical for operators, especially for ACF-heavy targets, so schema label enrichment is the first dependency for the next UX batch.
- `2026-03-19`: Single-item review quality must be fixed before adding bulk review or control-center shortcuts.
- `2026-03-19`: The next implementation round should enrich existing review payloads and inspector surfaces rather than adding parallel review pages.
- `2026-03-19`: `P13-T1` is now landed. V2 review payloads, readiness-adjacent package preview rows, and inspector surfaces all carry additive schema presentation metadata while preserving stable machine refs.
- `2026-03-19`: The new schema presentation resolver reuses the existing target field catalog and exposes human-readable labels, object context, field types, taxonomy labels, and ACF group context without creating a second schema source.
- `2026-03-19`: Inspector mapping and override surfaces now present human-readable target labels first and raw refs as secondary evidence, which clears the path for explicit single-item review controls in `P13-T2`.
- `2026-03-19`: Targeted validation for `P13-T1` now includes `ContentCollectorV2Phase13Test`, targeted inspector linting, and a full V2 asset build.
- `2026-03-19`: `P13-T2` is now landed. The inspector no longer treats overrides as the only actionable decision path; field and media recommendations now expose explicit `approve`, `reject`, `override`, and `unresolved` controls.
- `2026-03-19`: The mapping tab now renders side-by-side source evidence, recommended target evidence, and final decision state from the shared inspector draft layer.
- `2026-03-19`: Existing decision artifacts remain stable. The new UI draft layer translates explicit per-item controls back into the current mapping and media decision payloads instead of widening downstream import contracts.
- `2026-03-19`: LocalWP Playwright smoke remains green after the reviewability UI refactor, but the smoke intentionally stays data-agnostic and does not assume a seeded inspector record always contains recommendation rows.
- `2026-03-19`: `P13-T3` is now landed. The inspector now surfaces stale-decision drift explicitly, exposes a deterministic reset-to-latest path, and warns before unsaved local edits are lost through drawer close, tab changes, workspace changes, run changes, or record navigation.
- `2026-03-19`: The unsaved-change guard is enforced at the V2 shell route boundary, so the same discard-confirm behavior now applies whether the operator leaves through the drawer chrome, a row action, or workspace navigation.
- `2026-03-19`: Targeted validation for the safety-rail tranche includes inspector linting, a full V2 asset build, and LocalWP Playwright coverage for the new guard flow. A final targeted rerun hit a transient Chromium launch crash after the guard assertion was corrected, so browser validation notes should treat that as an environment issue, not a product regression.

### Phase 14: Conflict-First Review Workflow

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Make conflicts and unresolved recommendations directly actionable from the queue.
- `[CLOSED]` Add fast review navigation so operators can move through flagged URLs without table hopping.
- `[CLOSED]` Add operator-facing explanations for why items are blocked, stale, conflicted, or review-required.

Acceptance criteria:
- Conflict counts in the exception queue lead to dedicated conflict-resolution surfaces, not just generic inspector entry.
- Operators can move `previous`, `next`, or `save and next` through flagged URLs from the drawer workflow.
- Exception filters reflect real review states such as conflicts, unresolved items, stale decisions, and manual overrides.
- Conflict and decision surfaces clearly explain confidence, policy, resolution, and stale-state reasons.

Current tranche notes:
- `2026-03-19`: `P14-T1` is now landed. The exception queue now exposes conflict, unresolved, stale, manual-override, blocked, and ready-after-review filters with queue-state-aware ordering and row-level quick actions into the relevant inspector tab.
- `2026-03-19`: `P14-T2` is now landed. The inspector now exposes a dedicated conflict tab with editable decision cards, current resolution reasoning, review reasons, confidence context, and stale-state guidance instead of forcing operators to infer conflicts from counts alone.
- `2026-03-19`: `P14-T3-S1` and `P14-T3-S2` are now landed. Previous, next, save-and-next, and save-and-close controls now move through the currently filtered exception queue without dropping queue context.
- `2026-03-19`: `P14-T3-S3` is now closed. Targeted validation covers `ContentCollectorV2Phase14Test`, targeted linting, a V2 asset build, a dedicated conflict-flow Playwright spec, and live LocalWP browser QA for `Resolve conflicts`, `Next`, `Previous`, and `Save and next`. The Playwright runner still shows intermittent Chromium launch crashes on this machine, so the browser-validation record should treat those as environment issues rather than product regressions.

### Phase 15: Readiness and Package Actionability

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Make readiness blockers route directly into actionable review paths.
- `[CLOSED]` Harden package and import controls with clearer confirmations, disabled reasons, and follow-up visibility.
- `[CLOSED]` Turn package artifact references into usable operator actions.

Acceptance criteria:
- Blocking readiness items can open the first relevant review target or filtered queue directly from the readiness workspace.
- Package preflight and execute controls explain why they are disabled and require intentional operator confirmation before mutation.
- Operators can inspect or download relevant package artifacts without leaving the V2 workflow or reading raw storage paths.
- The tranche preserves existing import guardrails, rollback, and journaling behavior.

Current tranche notes:
- `2026-03-19`: The package workspace already exposes core actions, but still needs safer action framing and more useful artifact access.
- `2026-03-19`: This tranche should stay workflow-glue focused and not broaden package-building logic.
- `2026-03-19`: `P15-T1` is now landed. The readiness workspace reuses the existing `GET /runs/{run_id}/readiness` payload to drive blocker actions without adding a second readiness endpoint.
- `2026-03-19`: Blocking issues and warnings now expose direct action buttons into the filtered exceptions queue, QA audit tab, or package workspace based on existing issue codes such as `target_conflicts`, `manual_review_pending`, `empty_package_record`, and `missing_target_transform`.
- `2026-03-19`: The readiness table now exposes focused filter chips for `review`, `qa`, `package`, and `ready`, and per-page primary actions now mirror the same route-aware action model used by the blocker lists.
- `2026-03-19`: Targeted validation for `P15-T1` now includes readiness UI linting, a full V2 asset build, and live LocalWP browser QA covering readiness filter chips plus blocker routes into exceptions and the audit drawer.
- `2026-03-19`: `P15-T2` is now landed. The package workspace now requires explicit confirmation before preflight approval or execute, surfaces disabled-reason messaging when package actions are not yet eligible, and keeps recent import or rollback follow-up visible in the same bridge panel.
- `2026-03-19`: `P15-T3` is now landed. Selected packages now expose signed artifact download actions plus in-app drill-ins for manifest, summary, QA, records, and media so operators do not have to parse raw storage-relative paths.
- `2026-03-19`: Targeted validation for the closed Phase 15 tranche now includes `ContentCollectorV2Phase15Test`, package-surface JS linting, a full V2 asset build, and live LocalWP browser QA covering package artifact drill-ins plus signed ZIP download links. The CLI Playwright spec is also landed, but intermittent local Chromium `SIGTRAP` launch failures still make the command-line runner less reliable than the live browser session on this machine.

### Phase 16: Operator Efficiency and Control-Center Enhancements

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Add carefully guarded bulk review operations once single-item review is stable.
- `[CLOSED]` Add pragmatic run-level actions for rerun, duplication, and noise management.
- `[CLOSED]` Add cross-workspace navigation shortcuts that turn the overview into a usable control center later.

Acceptance criteria:
- Bulk review actions operate on explicit filtered selections and preserve auditability.
- Run-level actions such as rerun failed stage groups, duplicate run settings, or archive noisy test runs are available without reviving V1 transport patterns.
- Overview, readiness, and package surfaces can jump directly to the most relevant blocking review targets.
- The tranche keeps automation and audit guardrails intact while improving operator speed.

Current tranche notes:
- `2026-03-19`: Bulk actions should be deferred until single-item reviewability and stale-state handling are trustworthy.
- `2026-03-19`: Run-level convenience actions are useful, but they are lower priority than fixing conflict review and schema-label clarity.
- `2026-03-19`: Control-center behavior should grow from the stabilized monitoring and review surfaces, not bypass them.
- `2026-03-19`: `P16-T1` is now landed. The exceptions workspace now supports explicit low-risk row selection, family-scoped selection helpers, and audited bulk apply actions without adding a second review write endpoint.
- `2026-03-19`: Bulk review stays deliberately narrow: conflict rows, stale rows, blocked rows, unresolved rows, and manual-override rows remain single-item workflows, while low-risk approval and defer actions iterate through the existing per-page `POST /runs/{run_id}/urls/{page_id}/decision` contract.
- `2026-03-19`: Targeted validation for `P16-T1` includes exceptions-surface JS linting, a full V2 asset build, `git diff --check`, and a Playwright smoke update. The new bulk-review smoke is currently skip-guarded when the LocalWP dataset does not contain a qualifying low-risk queue row.
- `2026-03-19`: `P16-T3` is now landed. Overview next actions, readiness actions, and package blocker shortcuts now share the same route-aware action model instead of each workspace inventing its own navigation behavior.
- `2026-03-19`: Overview shortcuts can now jump directly into the first blocked or reviewable URL, open the matching readiness audit target, or reopen the latest built package without adding a second overview endpoint.
- `2026-03-19`: The package workspace now exposes direct blocker shortcuts from both the package action cards and the execute-blocked notice so operators can move straight into the relevant exceptions or readiness target while preserving package selection in route state.
- `2026-03-19`: Targeted validation for `P16-T3` includes control-center JS linting, a full V2 asset build, `git diff --check`, and a Playwright smoke update that now proves the overview shortcut path reaches the exceptions workspace through the shared route patch model.
- `2026-03-19`: `P16-T2` is now landed. The V2 `/runs` surface now carries additive latest-run request profiles, stage-group rerun candidates, and user-scoped hidden-run visibility state without introducing a second run list model or reviving V1 transport patterns.
- `2026-03-19`: The runs workspace now supports duplicate-settings prefill, rerun helpers for failed or blocked stage groups through the existing per-URL rerun route, and hide or restore controls with a hidden-run toggle for noisy operator cleanup.
- `2026-03-19`: Targeted validation for the closed Phase 16 tranche includes `ContentCollectorV2Phase16Test`, runs-surface JS linting, a full V2 asset build, `git diff --check`, and a LocalWP Playwright smoke update covering the new run-card actions.

### Phase 17: Run Replay and Recovery

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Add direct replay flows on top of stored run request profiles.
- `[CLOSED]` Make run-card action eligibility and disabled reasons clearer.
- `[OPEN]` Add targeted validation for replay and recovery flows.

Acceptance criteria:
- Operators can replay a profiled run without manually re-entering the crawl form.
- Run cards explain why replay or duplicate helpers are disabled for older runs that predate request-profile capture.
- Replay keeps using the existing `POST /dbvc_cc/v2/runs` contract instead of adding a second replay transport.
- The tranche preserves the existing run-start lifecycle visibility so replay progress is observable in the `runs` workspace.

Current tranche notes:
- `2026-03-19`: Phase 16 added the underlying request-profile and rerun-candidate metadata, but the operator still needs a one-click replay path on top of that data.
- `2026-03-19`: This tranche should stay additive to the current `runs` workspace and reuse the existing run-start lifecycle panel instead of introducing a second replay-only status surface.
- `2026-03-19`: Disabled replay and duplicate helpers should explain legacy-data limitations directly on the run card, rather than silently rendering unavailable controls.
- `2026-03-19`: `P17-T1` is now landed. Profile-backed run cards now expose a direct `Replay run` action that reuses the existing `POST /runs` contract instead of adding a second replay transport.
- `2026-03-19`: Replay now flows through the existing run-start lifecycle panel, which makes replay mode, source run, stored request inputs, and replay success visible without leaving the `runs` workspace.
- `2026-03-19`: `P17-T2` is now landed. Older runs that predate request-profile capture now explain why replay and duplicate helpers are unavailable instead of silently rendering disabled controls with no context.
- `2026-03-19`: Targeted validation for the current Phase 17 slice includes runs-surface JS linting, a full V2 asset build, `git diff --check`, a landed Playwright replay smoke update, and live LocalWP browser QA proving replay, duplicate-settings prefill, and hide or restore behavior. The CLI Playwright runner is still intermittently blocked by the same local Chromium `SIGTRAP` launch failure on this machine.

### Phase 18: Recovery Follow-up Context

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Make replay and rerun outcomes actionable from the existing runs workspace surfaces.
- `[CLOSED]` Keep recovery follow-up state contextual without introducing a second run-recovery route model.
- `[CLOSED]` Add targeted validation for recovery follow-up actions.

Acceptance criteria:
- Replay follow-up actions remain available through the existing run-create lifecycle panel.
- Rerun follow-up actions remain available through the existing run-action status panel even when later non-recovery actions occur in the same runs workspace session.
- The tranche does not add a second recovery route model or a new recovery-only workspace panel.
- Recovery actionability grows from the existing runs surfaces instead of bypassing them.

Current tranche notes:
- `2026-03-19`: `P18-T1` is now landed. Replay success now exposes direct follow-up actions to reopen the source run or the newly created run from the existing lifecycle panel, while rerun outcomes expose direct follow-up actions into overview and exceptions from the existing run-action status panel.
- `2026-03-20`: `P18-T2` is now landed. The runs workspace now preserves the latest rerun recovery context inside the existing run-action status panel even when later non-recovery actions such as hide, restore, or duplicate-settings prefill occur during the same session.
- `2026-03-20`: Duplicate-settings prefill now clears transient run-action messages without discarding the latest rerun recovery follow-up context from the current runs workspace session.
- `2026-03-20`: `P18-T3` is now in progress. The Playwright smoke was refreshed to assert replay follow-up buttons in the lifecycle panel and to add a skip-guarded rerun recovery-context test, but the CLI runner is still intermittently blocked by the local Chromium `SIGTRAP` launcher failure before the refreshed suite can finish green.
- `2026-03-20`: Same-domain replay no longer strands the lifecycle panel's `Open source run` shortcut on a missing overview. Historical `GET /runs/{run_id}`, `/overview`, and readiness payloads now materialize state for the requested run from the domain journey log when the domain latest snapshot has already advanced to a newer run.
- `2026-03-20`: Headed LocalWP QA now confirms replay follow-up opens the historical source overview for `ccv2_dbvc-codexchanges-local_20260320T070018Z_298600` after replay created `ccv2_dbvc-codexchanges-local_20260320T071726Z_921d8b`, and the refreshed browser smoke now checks that path before it continues to duplicate-settings and hide or restore actions on the new replay run.
- `2026-03-20`: `P18-T3` remains open because the current LocalWP dataset has no visible run whose `actionSummary.rerunCandidates` exposes a runs-workspace rerun shortcut. The rerun follow-up smoke still has no eligible target even in headed QA, while CLI Playwright on this machine continues to intermittently crash Chromium with `SIGTRAP`.
- `2026-03-25`: `P18-T3` is now landed. LocalWP headed QA seeded a temporary rerun candidate for `ccv2_dbvc-codexchanges-local_20260320T071726Z_921d8b`, confirmed the runs workspace exposed `Rerun recommendation finalization (1)`, confirmed the rerun follow-up `Open overview` shortcut routed back into the affected run overview, replayed that same run into `ccv2_dbvc-codexchanges-local_20260325T182037Z_49db4b`, and confirmed the lifecycle panel's `Open source run` shortcut still re-opened the historical source overview.
- `2026-03-25`: Local PHPUnit is restored for the current LocalWP site through the socket-backed WordPress test bootstrap, so `vendor/bin/phpunit --filter "ContentCollectorV2Phase(16|18)Test"` is now green again alongside the existing lint and build baseline.
- `2026-03-25`: CLI Playwright on this machine is still intermittently blocked by the local Chromium `SIGTRAP` launcher failure, so the current Phase 18 validation record remains headed/manual browser QA plus the refreshed spec updates rather than a fresh green CLI browser run.

### Phase 19: Deterministic Recovery QA Harness

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Add a deterministic way to materialize rerun-candidate runs for recovery QA without depending on incidental LocalWP failures.
- `[CLOSED]` Capture replay and rerun follow-up browser coverage against deterministic recovery data.
- `[CLOSED]` Keep LocalWP validation bootstrap guidance aligned with the socket-backed PHPUnit and browser workflow.

Acceptance criteria:
- Recovery QA can surface at least one runs-workspace rerun helper without manual artifact editing in the active LocalWP dataset.
- Replay and rerun follow-up browser coverage can exercise the intended shortcuts against deterministic seeded data.
- The tranche does not add production-only debug surfaces or weaken V1/V2 runtime gating to make QA easier.
- Local validation guidance stays explicit about the current LocalWP browser route and socket-backed PHPUnit expectations.

Current tranche notes:
- `2026-03-25`: This phase starts only after Phase 18 closes. The immediate goal is to replace ad hoc LocalWP data surgery with a repeatable recovery-fixture path that keeps browser validation stable even while CLI Playwright remains environment-blocked on this machine.
- `2026-03-25`: Any recovery-fixture helper should stay out of production operator flows unless it is explicitly gated for development or test use only.
- `2026-03-26`: `P19-T2` is now landed. Replay browser validation no longer depends on live sitemap refetch during QA: the refreshed smoke now injects a dev-only deterministic replay source through the existing `POST /runs` transport, the lifecycle success payload exposes a stable created-run identifier for follow-up actions, and the runs-workspace opener now tolerates transient LocalWP `ERR_ABORTED` admin navigations instead of failing on the second retry.
- `2026-03-26`: Targeted validation for the closed Phase 19 tranche now includes `ContentCollectorV2Phase19Test`, targeted JS linting for the revised runs lifecycle and Playwright harness, a full V2 asset build, and green unsandboxed LocalWP Playwright coverage for both replay and rerun follow-up shortcuts.

### Phase 20: Historical Run Artifact Fidelity

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Make historical run detail surfaces resolve run-scoped discovery artifacts after same-domain replay chains instead of falling back to domain-latest files when that would drift the requested run.
- `[CLOSED]` Audit readiness and package entry points for historical run artifact drift after later same-domain replays.
- `[CLOSED]` Add targeted validation for historical run fidelity across same-domain replay chains.

Acceptance criteria:
- Opening an older run after a later same-domain replay does not show discovery or package artifacts from the newer run.
- Historical overview, readiness, and package readers share the same run-scoped artifact-resolution rule instead of each surface inventing its own fallback.
- The tranche keeps the existing run routes and recovery QA helpers intact while improving historical-run fidelity.

Current tranche notes:
- `2026-03-26`: Phase 18 and Phase 19 stabilized replay and rerun follow-up actions in the runs workspace, but some artifact families still remain domain-latest even when the operator reopens an older run after a same-domain replay.
- `2026-03-26`: This phase should stay focused on run-scoped artifact resolution and validation, not on adding new run-card actions or a second recovery transport.
- `2026-03-26`: `P20-T1` is now landed. Historical overview reads now resolve discovery inventory per requested run: the shared inventory reader reuses the persisted `domain-url-inventory.v1` file only when it already belongs to the requested run and otherwise reconstructs that inventory from the run's journey events plus run-scoped materialized latest state.
- `2026-03-26`: Reopening an older run after a newer same-domain run no longer drifts overview inventory rows or counts toward the newer run's domain-latest inventory artifact.
- `2026-03-26`: `P20-T2` is now landed. Readiness now starts from the requested run's inventory and page context instead of the domain-latest inventory file, run-aware artifact loading refuses page artifacts whose `journey_id` belongs to a newer run, and the package surface now defaults and filters package history to the requested run instead of the latest package built for the domain.
- `2026-03-26`: `P20-T3` is now landed. `ContentCollectorV2Phase20Test` now covers overview, readiness, and package surface behavior across later same-domain runs, and the combined `ContentCollectorV2Phase(19|20)Test` filter is green alongside syntax checks and `git diff --check`.

### Phase 21: Historical Page Artifact Preservation

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Preserve run-scoped page artifacts when later same-domain reruns touch the same URL again.
- `[CLOSED]` Reuse preserved historical page artifacts in readiness and package readers before falling back to mismatch-blocked reads.
- `[CLOSED]` Add targeted validation for same-URL historical fidelity after rerun or replay chains.

Acceptance criteria:
- Older runs can still reopen their own page-level mapping and transform artifacts after a later same-domain run processes the same URL again.
- Historical readiness and package readers no longer degrade to missing-artifact blockers solely because a later run overwrote the current page artifact files for the same URL.
- The tranche keeps the existing run routes, recovery QA helpers, and workspace surfaces intact while improving historical fidelity.

Current tranche notes:
- `2026-03-26`: Phase 20 closed the known read-side drift where overview inventory, readiness inventory, or package history could jump to a newer same-domain run.
- `2026-03-26`: The remaining historical-fidelity gap is artifact preservation for the same URL. Page-level recommendation and transform files still live at domain-scoped paths, so older runs can avoid newer-run drift but may still lose direct access to their own historical files after a later overwrite.
- `2026-03-26`: This phase should stay focused on run-scoped artifact preservation and lookup, not on new operator actions, new replay transport, or workspace expansion.
- `2026-03-26`: `P21-T1` and `P21-T2` are now landed. Capture, AI pipeline, and review writes now preserve per-run page artifact copies under the page's `_runs/{run_id}/` directory, and historical `resolve_page_context_for_run()` reads now prefer the current URL-scoped file only when its `journey_id` still matches the requested run.
- `2026-03-26`: Historical readiness and package flows already reuse `resolve_page_context_for_run()`, so they now reopen preserved same-URL page artifacts without adding new readiness or package routes.
- `2026-03-26`: `P21-T3` is now landed. `ContentCollectorV2Phase21Test` covers a same-domain same-URL overwrite chain where the older run reopens its preserved raw artifact and still builds a package after the later run replaced the current page files.

### Phase 22: Historical Review Fidelity

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Make historical exceptions and review payloads resolve run-scoped page artifacts after later same-domain same-URL runs.
- `[CLOSED]` Keep decision-save and rerun flows scoped to the requested historical run instead of mutating whichever same-URL page file is currently latest.
- `[CLOSED]` Add targeted validation for historical review fidelity after same-URL replay or rerun chains.

Acceptance criteria:
- Opening an older run's exceptions queue or URL review payload after a later same-domain same-URL run still shows the older run's own recommendations, transform preview, and persisted decisions.
- Saving review decisions or triggering reruns from a historical run does not silently overwrite a newer same-domain run's current page artifacts.
- The tranche keeps the current review routes and workspace structure intact while extending the same run-scoped artifact rule into the remaining review surfaces.

Current tranche notes:
- `2026-03-26`: Phase 21 closed readiness and package fidelity for same-URL historical runs, but exception queue and review loaders still resolve page artifacts from the latest domain-scoped page path.
- `2026-03-26`: This phase should stay focused on historical review fidelity and write scoping, not on new review surfaces or route expansion.
- `2026-03-28`: `P22-T1` is now landed. Historical exceptions and review payloads now resolve inventory and page artifacts by requested run instead of by the latest domain-scoped same-URL page path.
- `2026-03-28`: `P22-T2` is now landed. Historical decision-save and rerun flows now choose run-scoped write paths whenever the current same-URL page belongs to a newer run, so those actions stop mutating the newer run's current page files.
- `2026-03-28`: `P22-T3` is now landed. `ContentCollectorV2Phase22Test` covers both historical read fidelity and write scoping after a same-domain same-URL overwrite chain, and the combined `ContentCollectorV2Phase(20|21|22)Test` filter is green.

### Phase 23: Historical Review Browser Validation

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Add targeted browser validation for same-URL historical exceptions and review flows.
- `[CLOSED]` Verify historical queue navigation, review payload loading, and rerun follow-up actions from operator-facing V2 routes after a later same-domain same-URL run exists.
- `[CLOSED]` Document the final validation record and any remaining environment-specific browser constraints without broadening runtime scope.

Acceptance criteria:
- Browser validation proves that reopening an older run after a later same-domain same-URL run still loads the older run's exception queue and review payloads.
- Historical decision-save or rerun actions in the browser no longer push the operator into a newer run's page state or silently mutate the newer run's same-URL current files.
- The tranche keeps the existing review routes, current Playwright transport choices, and workspace architecture intact while closing the remaining browser-validation gap for historical review fidelity.

Current tranche notes:
- `2026-03-28`: Phase 22 closed the backend review-fidelity gap in PHPUnit, but the same historical review paths still need operator-facing browser validation.
- `2026-03-28`: This phase should stay focused on validation and route-state behavior, not on new review controls or additional REST routes.
- `2026-03-28`: Phase 23 is now landed. Historical review browser validation now uses the existing `POST /runs` transport with two dev-only helpers: a synthetic single-page fixture domain for deterministic source runs and the upgraded replay fixture path that clones page artifacts into a true same-URL overwrite chain.
- `2026-03-28`: The unsandboxed targeted Playwright smoke `preserves historical exception review actions after a same-url overwrite run` is green, and it proves the older run still opens, saves, and reruns without drifting into the newer same-URL run.

### Phase 24: Historical Workspace Browser Validation

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Add targeted browser validation for historical overview, readiness, and package surfaces after a later same-domain same-URL run exists.
- `[CLOSED]` Verify that historical workspace shortcuts and selected package state stay pinned to the requested run in operator-facing browser flows.
- `[CLOSED]` Record the final validation result and any remaining browser-environment constraints without expanding the runtime surface.

Acceptance criteria:
- Browser validation proves that reopening an older run after a later same-domain same-URL run still loads the older run's overview, readiness, and package surfaces rather than silently drifting to newer domain-latest state.
- Historical workspace shortcuts and selected package actions in the browser stay pinned to the requested run and package context after same-URL overwrite chains.
- The tranche keeps using the current V2 routes, deterministic QA helper transport, and existing workspace architecture instead of introducing new operator surfaces.

Current tranche notes:
- `2026-03-28`: Phase 23 closed the historical exception and review browser-validation gap, so the next remaining browser seam is the rest of the historical workspace stack.
- `2026-03-28`: This phase should stay focused on operator-facing overview, readiness, and package route fidelity after overwrite chains, not on new package or overview controls.
- `2026-03-31`: The targeted unsandboxed Playwright smoke `preserves historical overview, readiness, and package routes after a same-url overwrite run` is green. It proves overview shortcuts, readiness flows, package routing, and selected package state stay pinned to the requested older run after a deterministic overwrite chain.

### Phase 25: Historical Package Workflow Browser Validation

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Add targeted browser validation for historical package workflow follow-through after a later same-domain same-URL run exists.
- `[CLOSED]` Verify that dry-run and package guardrail surfaces stay pinned to the requested historical `runId` and `packageId` in operator-facing browser flows.
- `[CLOSED]` Record the validation result and any remaining browser-environment constraints without broadening the runtime surface.

Acceptance criteria:
- Browser validation proves that reopening an older run after a later same-domain same-URL run still keeps package dry-run or closely adjacent workflow state pinned to the requested run and package rather than silently drifting to newer domain-latest state.
- Historical package workflow blocker shortcuts, dry-run preview state, and route parameters in the browser stay pinned to the requested run and package context after same-URL overwrite chains.
- The tranche keeps using the current V2 routes, deterministic QA helper transport, and existing package workspace architecture instead of introducing new operator surfaces.

Current tranche notes:
- `2026-03-31`: Phase 24 closed the historical overview, readiness, and package route-fidelity gap, so the next remaining browser seam is package workflow follow-through on historical runs.
- `2026-03-31`: This phase should stay focused on dry-run and guardrail/browser-state fidelity for historical package workspaces, not on new import controls or route families.
- `2026-04-05`: The targeted unsandboxed Playwright smoke `preserves historical package dry-run and guardrail shortcuts after a same-url overwrite run` is green. It proves dry-run preview and the execute-blocked resolve shortcut stay pinned to the requested older run and selected package after a deterministic overwrite chain.

### Phase 26: Historical Package Preflight Browser Validation

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Add targeted browser validation for historical package preflight approval after a later same-domain same-URL run exists.
- `[CLOSED]` Verify that preflight request state and persisted preflight summary stay pinned to the requested historical `runId` and `packageId` in operator-facing browser flows.
- `[CLOSED]` Record the validation result and any remaining browser-environment constraints without broadening the runtime surface or executing destructive import flows.

Acceptance criteria:
- Browser validation proves that reopening an older run after a later same-domain same-URL run still keeps preflight approval requests and closely adjacent persisted preflight state pinned to the requested run and package rather than silently drifting to newer domain-latest state.
- Historical preflight route parameters, summary surfaces, and follow-up browser state stay pinned to the requested run and package context after same-URL overwrite chains.
- The tranche keeps using the current V2 routes, deterministic QA helper transport, and existing package workspace architecture instead of introducing new operator surfaces.

Current tranche notes:
- `2026-04-05`: Phase 25 closed the historical package dry-run and guardrail browser gap, so the next remaining package seam is preflight fidelity on historical runs.
- `2026-04-05`: This phase should stay focused on preflight approval and persisted preflight state, not on execute mutations or new route families.
- `2026-04-05`: The targeted unsandboxed Playwright smoke `preserves historical package preflight approval and persisted summary after a same-url overwrite run` is green. It proves historical preflight requests and persisted preflight summary stay pinned to the requested older run and selected package after a deterministic overwrite chain.

### Phase 27: Historical Package Execution Observability Browser Validation

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Add targeted browser validation for historical package execution-observability surfaces after a later same-domain same-URL run exists.
- `[CLOSED]` Verify that latest recorded import summary and import-history route state stay pinned to the requested historical `runId` and `packageId` in operator-facing browser flows.
- `[CLOSED]` Record the validation result and any remaining browser-environment constraints without broadening the runtime surface or triggering fresh destructive imports by default.

Acceptance criteria:
- Browser validation proves that reopening an older run after a later same-domain same-URL run still keeps latest recorded import and import-history surfaces pinned to the requested run and package rather than silently drifting to newer domain-latest state.
- Historical execution-observability route parameters, summary surfaces, and selected package context stay pinned to the requested run and package context after same-URL overwrite chains.
- The tranche keeps using the current V2 routes, deterministic QA helper transport, and existing package workspace architecture instead of introducing new operator surfaces or executing a fresh import by default.

Current tranche notes:
- `2026-04-05`: Phase 26 closed the historical package preflight browser gap, so the next remaining package seam is execution observability on historical runs.
- `2026-04-05`: This phase should stay focused on persisted import-summary and history fidelity, not on firing a new execute mutation unless explicitly approved as a follow-up.
- `2026-04-05`: The targeted unsandboxed Playwright smoke `preserves historical package execution observability after a same-url overwrite run` is green. It proves the historical latest-recorded-import card, execute workflow summary, and import-history table stay pinned to the requested older run and package after a deterministic overwrite chain.
- `2026-04-05`: When LocalWP lacks real historical import history, the dev-only package execution QA fixture can overlay one selected package's latest execute summary and import history without firing a real import.

### Phase 28: Historical Package Execute Mutation Approval Boundary

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Keep historical package browser coverage non-destructive by default now that dry-run, preflight, and execution-observability routes are green.
- `[CLOSED]` Define the explicit approval and disposable-data requirements for any future real historical execute browser mutation QA.
- `[CLOSED]` Record the current validation boundary and environment constraints before any destructive import follow-up is attempted.

Acceptance criteria:
- The guide makes clear that historical package browser fidelity is closed through read-only execution observability without firing a real import by default.
- Any future browser validation that actually triggers `POST /runs/{run_id}/execute` on historical packages is treated as an explicit opt-in tranche with disposable LocalWP data and rollback expectations called out first.
- No new runtime surface is introduced while establishing that boundary.

Current tranche notes:
- `2026-04-05`: Phase 27 closed the remaining non-destructive historical package browser seam.
- `2026-04-05`: The next unvalidated package action would be a real execute mutation, so Phase 28 should stay focused on approval boundaries and disposable-data requirements before any destructive QA is attempted.
- `2026-04-05`: Phase 28 is now closed as a documentation and approval-boundary tranche. The next open work would require explicit approval before any real historical execute mutation runs.

### Phase 29: Historical Package Execute Mutation Browser QA

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Obtain explicit approval before any browser test triggers a real `POST /runs/{run_id}/execute` mutation on disposable LocalWP data.
- `[CLOSED]` Scope destructive execute QA to disposable historical run and package data with rollback or cleanup expectations called out first.
- `[CLOSED]` Record the browser result and any remaining environment blockers after the explicit approval boundary is satisfied.

Acceptance criteria:
- No browser validation triggers a real historical execute mutation until explicit approval is granted for the disposable LocalWP data set being used.
- If approved, browser validation proves the historical execute mutation route stays pinned to the requested `runId` and `packageId` after a same-domain same-URL overwrite chain.
- The tranche records rollback or cleanup expectations and keeps unsandboxed Playwright requirements explicit.

Current tranche notes:
- `2026-04-05`: All currently planned historical package browser seams short of a real execute mutation are now green.
- `2026-04-05`: This phase should not start execution work until explicit approval is granted for destructive LocalWP import QA.
- `2026-04-06`: Destructive execute QA for this tranche must stay scoped to disposable data inside `dbvc-codexchanges.local` and `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges` only. Do not touch any other LocalWP site, database, directory, or the LocalWP desktop app.
- `2026-04-06`: Explicit approval was granted for a real historical `POST /runs/{run_id}/execute` mutation on disposable fixture data inside `dbvc-codexchanges.local` only.
- `2026-04-06`: Package readiness now filters recommendation conflicts through saved decision state, so resolved conflict groups do not keep preflight or execute blocked just because the raw recommendation artifact still lists the original conflict set.
- `2026-04-06`: The targeted unsandboxed Playwright smoke `preserves historical package execute mutation after a same-url overwrite run` is now green. It proves the real execute mutation, route state, selected package state, and persisted execution follow-up all stay pinned to the approved historical run and package after a same-domain same-URL overwrite chain.
- `2026-04-06`: The approved destructive execute on `dbvc-codexchanges.local` remained guardrail-blocked and recorded `import_runs = 0`, so rollback cleanup was a no-op on this site. That still closes the route-fidelity seam because the real execute mutation, guardrail-blocked outcome, and persisted execution state were all validated without bypassing site protections.

### Phase 30: Rollback-Eligible Historical Execute Boundary

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Record that `dbvc-codexchanges.local` now validates real historical execute route fidelity, but still does not provide rollback-eligible import runs under the site's current guardrails.
- `[CLOSED]` Define the exact isolation and approval requirements for any future rollback-specific historical execute QA.
- `[CLOSED]` Keep rollback-specific follow-up explicitly out of scope for `dbvc-codexchanges.local` unless the user deliberately reopens that boundary.

Acceptance criteria:
- The guide makes clear that Phase 29 closed the real historical execute route-fidelity seam on `dbvc-codexchanges.local`, even though site guardrails prevented write execution and rollbackable import runs.
- Any future rollback-specific QA requires a separately designated disposable target and must not disable guardrails or broaden LocalWP scope just to fabricate rollback coverage on `dbvc-codexchanges.local`.
- The LocalWP boundary remains explicit: no commands, writes, deletions, destructive runtime mutations, or browser QA should touch any LocalWP environment other than `dbvc-codexchanges.local` and `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges` unless the user explicitly broadens scope.

Current tranche notes:
- `2026-04-06`: Phase 30 begins as a boundary-definition tranche, not a new runtime tranche.
- `2026-04-06`: The next open work is no longer route fidelity on `dbvc-codexchanges.local`; it is deciding whether rollback-eligible execute QA is needed at all and, if so, where it can happen safely.
- `2026-04-06`: Do not disable guardrails or broaden to other LocalWP sites, databases, directories, or the LocalWP desktop app without explicit user approval.
- `2026-04-06`: The Playwright environment split is now directly confirmed on this machine: a bare Chromium launch exits with `SIGTRAP` under the Codex shell sandbox but succeeds immediately outside it, so browser QA should treat that as a sandbox boundary and keep using unsandboxed repo scripts when launch is required.
- `2026-04-06`: Phase 30 is now closed as a boundary-definition tranche. `dbvc-codexchanges.local` remains the approved route-fidelity baseline only; rollback-specific QA is deferred until a separate rollback-enabled disposable target is explicitly designated by the user.

### Phase 31: Rollback-Enabled Historical Execute Target Selection

Phase status:
- `CLOSED`

Checklist:
- `[CLOSED]` Decide whether rollback-specific historical execute QA is required beyond the closed Phase 29 route-fidelity coverage.
- `[CLOSED]` If rollback-specific QA is required, require an explicit user-designated disposable target before any further execute mutation work starts.
- `[CLOSED]` Preserve the current safety boundary so `dbvc-codexchanges.local` stays the route-fidelity baseline unless the user deliberately broadens scope.

Acceptance criteria:
- The guide makes clear that rollback-specific follow-up is now a separate opt-in decision, not an automatic continuation of the closed historical execute tranche.
- Any future rollback-focused QA must use an explicitly approved disposable target and must not broaden into another LocalWP environment by default.
- The current boundary remains explicit: `dbvc-codexchanges.local` is still the only approved LocalWP environment for current V2 work unless the user directs otherwise.

Current tranche notes:
- `2026-04-06`: Phase 31 starts only after the rollback boundary is documented and closed in Phase 30.
- `2026-04-06`: The immediate next step is a decision gate, not a runtime code tranche.
- `2026-04-06`: Do not start rollback-focused execute QA, disable guardrails, or touch any other LocalWP site, database, directory, shared LocalWP infrastructure, or the LocalWP desktop app unless the user explicitly opens that scope.
- `2026-04-06`: Phase 31 is now closed by decision. Rollback-specific historical execute QA is not needed now on `dbvc-codexchanges.local`, and no broader LocalWP target should be opened for that purpose unless the user explicitly reopens it later.

### Phase 32: Post-Historical V2 Tranche Selection

Phase status:
- `OPEN`

Checklist:
- `[OPEN]` Audit the now-closed historical-fidelity stream and identify the next highest-value V2 product or release-readiness seam.
- `[OPEN]` Separate product/runtime gaps from environment-only backlog items before defining the next tranche.
- `[OPEN]` Keep rollback-specific QA deferred unless the user explicitly reopens it with a separate disposable target.

Acceptance criteria:
- The guide identifies the next real V2 tranche or explicitly records that the historical-fidelity stream is complete and awaiting a new product seam.
- Tooling-only items such as agent-session transport problems or Codex sandboxed Playwright launch issues stay visible as backlog items, but they do not get mistaken for V2 runtime gaps.
- No new destructive LocalWP scope is opened by default.

Current tranche notes:
- `2026-04-06`: Phase 32 begins only after Phase 31 closes by decision.
- `2026-04-06`: Historical route, review, readiness, package, and execute-route fidelity are now closed on `dbvc-codexchanges.local`.
- `2026-04-06`: The next logical work is to choose the next real V2 product seam instead of extending rollback-specific QA on the current LocalWP target.
- `2026-04-21`: `P32-T4-S3` is now landed. Target transforms validate slot-graph `value_contract` data before package readiness, recommendations carry that validation forward, and URL QA blocks definite contract violations such as invalid URL writes or text landing in reference-only fields.
- `2026-04-21`: Provider-drift blocking is now landed inside the existing URL QA flow. Readiness compares recommendation-carried provider metadata to the current slot graph and blocks when `source_hash`, `schema_version`, `contract_version`, or `site_fingerprint` drift.
- `2026-04-21`: Benchmark rollups are now landed in run readiness and package QA. The existing readiness route and package QA artifact now summarize unresolved, ambiguous, transform-blocked, and provider-drift counts per page so benchmark pressure points stay visible without a second QA route.
- `2026-04-21`: `P32-T4-S4` is now landed. The shared benchmark gate now raises review or blocking issues inside the existing URL QA, readiness, and package flows based on quality score, reviewed ambiguity count, manual overrides, and rerun count, and the package workspace now surfaces benchmark status without adding a new screen.
- `2026-04-21`: Provider truthfulness and runtime ACF fallback are now landed for the Vertical Field Context seam. DBVC preserves provider `missing` status instead of collapsing it to `available`, backfills `vf_field_context` purpose text from the current ACF runtime when the provider catalog is empty, and infers semantic slot roles like `hero_h1 -> headline` before generic `wysiwyg` fallback. The live `ccv2_flourishweb-co_20260421T223255Z_0cc20e` Home `/` rerun now includes the local `hero_h1` slot in `map_003`, so the next open seam is ambiguity reduction rather than missing context ingestion.
- `2026-04-22`: Same-runtime provider criteria correction is now landed. V2 target schema builds no longer pass crawl-domain strings into the Vertical provider, so `dbvc-codexchanges.local` now rebuilds against a fresh provider-backed catalog and slot graph instead of collapsing to `missing` through invalid ACF criteria.
- `2026-04-22`: Post-object eligibility now excludes option-page and taxonomy-only slots, which removes `site_settings_group` banner fields from page hero matching on the live Flourish Home `/` rerun.
- `2026-04-22`: Candidate ordering now preserves an internal raw sort score beyond the rounded `0.99` display value, and unresolved recommendations now preserve the raw frontier target instead of surfacing a coherence-shifted fallback. The live Home `/` rerun now surfaces `hero_description` for `rec_004` while still marking the item unresolved because the low-margin ambiguity remains.
- `2026-04-22`: The next ambiguity tranche is now landed on the live `ccv2_flourishweb-co_20260421T223255Z_0cc20e` Home `/` rerun. ACF `link` fields no longer enter section-body candidate pools, direct hero-description scoring now beats nested hero card or popup descriptions, page-description metadata no longer claims `hero` section fields ahead of real section units, and the review payload now hydrates compact Field Context evidence from the slot graph. Home `/` now lands `rec_003 -> hero_h1` and `rec_004 -> hero_description` as deterministic `approve` recommendations under a fresh provider-backed catalog.
- `2026-04-22`: The next mapping-quality slice should stay additive to the current V2 runtime. Do not treat the remaining work as a greenfield rewrite. The structural gap list is now partially landed: routing now persists as a page-scoped artifact, slot projections expose structural competition groups for non-repeatable sibling slots, and unresolved items now carry typed classes with stable reason codes. The remaining open requirement is labeled benchmark fixtures that measure real precision instead of only exception counts.
- `2026-04-22`: Benchmark-driven route tuning is now partially landed. Slug-safe route normalization now recovers `page_intent = process` for `/our-process` and `page_intent = conversion` for `/get-started`, broad route scopes can now defer to a more specific page intent during section matching, route-intent expansion now emits `process`, `step`, `service`, and pricing-family pattern keys, and slot projections now classify `process_section.process_steps.step_name` as a repeatable `headline` in the `process` section family. Live reruns against `ccv2_flourishweb-co_20260421T223255Z_0cc20e` now show `/our-process` and `/pricing` reaching real ACF targets, while `/get-started` remains the next open conversion-page benchmark seam.

## Granular Implementation Checklist

Use these task IDs during implementation updates.

### Phase 0 Task Matrix

- `[CLOSED]` `P0-T1` Lock V2 naming and configuration contracts in docs.
- `[CLOSED]` `P0-T1-S1` Lock `content-collector-v2-app` as the recommended V2 bundle entry name.
- `[CLOSED]` `P0-T1-S2` Lock route and query naming around `runId`, `pageId`, `panel`, and `panelTab`.
- `[CLOSED]` `P0-T1-S3` Lock advanced automation setting keys and default values.
- `[CLOSED]` `P0-T2` Lock baseline resolution and domain-isolation policy in docs.
- `[CLOSED]` `P0-T2-S1` Lock `update_existing`, `create_new`, `blocked_needs_review`, and `skip_out_of_scope` as the base resolution modes.
- `[CLOSED]` `P0-T2-S2` Lock the domain isolation rule so learned behavior cannot spill across domains.
- `[CLOSED]` `P0-T3` Expand the implementation guide into a granular task checklist before coding begins.
- `[CLOSED]` `P0-T4` Freeze run and package identifier conventions.
- `[CLOSED]` `P0-T4-S1` Define `runId` format and generation source.
- `[CLOSED]` `P0-T4-S2` Define `packageId` format and relationship to `runId`.
- `[CLOSED]` `P0-T5` Freeze AI operating budgets.
- `[CLOSED]` `P0-T5-S1` Define timeout budgets per AI stage.
- `[CLOSED]` `P0-T5-S2` Define retry counts and deterministic fallback policy.

### Recommended Identifier Defaults

Recommended run identity:

- UI and REST name: `runId`
- artifact name: `journey_id`
- logical meaning: same identifier

Recommended format:

- `runId = ccv2_{domainSlug}_{utcCompact}_{token6}`
- example: `ccv2_example-com_20260318T154500Z_a1b2c3`

Recommended generation rules:

- `domainSlug` should be derived from the normalized source domain
- `utcCompact` should be generated in UTC as `YYYYMMDDTHHMMSSZ`
- `token6` should be a short random or collision-resistant suffix

Recommended package identity:

- `packageId = pkg_{runId}_{buildSeq}`
- example: `pkg_ccv2_example-com_20260318T154500Z_a1b2c3_001`

### Recommended AI Budget Defaults

Recommended initial AI operating defaults:

- per-stage timeout: `45s`
- transient retry count: `1`
- backoff after retryable failure: `5s`
- invalid or schema-mismatched response should count as retryable once, then fall back
- deterministic fallback is required after timeout, retry exhaustion, or invalid final response

Recommended stage budget notes:

- `context_creation`: `45s`, 1 retry
- `initial_classification`: `30s`, 1 retry
- `mapping_index`: `45s`, 1 retry
- `recommendation_finalization`: `45s`, 1 retry

### Phase 1 Task Matrix

- `[CLOSED]` `P1-T1` Add runtime gating controls to the Add-ons screen.
- `[CLOSED]` `P1-T1-S1` Surface `Enable Content Collector`.
- `[CLOSED]` `P1-T1-S2` Surface `Runtime Version` with `v1` and `v2`.
- `[CLOSED]` `P1-T1-S3` Surface advanced V2 automation settings behind an advanced section.
- `[CLOSED]` `P1-T2` Scaffold the V2 runtime path.
- `[CLOSED]` `P1-T2-S1` Create `addons/content-migration/v2/` folders.
- `[CLOSED]` `P1-T2-S2` Add V2 bootstrap and runtime registrar services.
- `[CLOSED]` `P1-T2-S3` Add V2 admin app loader service.
- `[CLOSED]` `P1-T3` Wire the V2 admin build entry.
- `[CLOSED]` `P1-T3-S1` Add `content-collector-v2-app.js`.
- `[CLOSED]` `P1-T3-S2` Add `addons/content-migration/v2/admin-app/index.js`.
- `[CLOSED]` `P1-T3-S3` Add `addons/content-migration/v2/admin-app/style.css`.
- `[CLOSED]` `P1-T3-S4` Update `package.json` `start`, `build`, and `lint` scripts to include `content-collector-v2-app`.
- `[CLOSED]` `P1-T4` Mount the first V2 workspace shell.
- `[CLOSED]` `P1-T4-S1` Register the V2 admin page and app root.
- `[CLOSED]` `P1-T4-S2` Localize `DBVC_CC_V2_APP` bootstrap data.
- `[CLOSED]` `P1-T4-S3` Render a minimal run workspace shell.
- `[CLOSED]` `P1-T5` Establish browser QA tooling.
- `[CLOSED]` `P1-T5-S1` Install `@playwright/test`.
- `[CLOSED]` `P1-T5-S2` Run `npx playwright install`.
- `[CLOSED]` `P1-T5-S3` Add one V2 smoke test for app load and drawer behavior.

### Phase 2 Task Matrix

- `[CLOSED]` `P2-T1` Build the domain journey subsystem.
- `[CLOSED]` `P2-T1-S1` Add append-only journey event writing.
- `[CLOSED]` `P2-T1-S2` Add latest-state materialization.
- `[CLOSED]` `P2-T1-S3` Add stage summary materialization.
- `[CLOSED]` `P2-T2` Build target schema sync primitives.
- `[CLOSED]` `P2-T2-S1` Build target object inventory.
- `[CLOSED]` `P2-T2-S2` Build target field catalog.
- `[CLOSED]` `P2-T2-S3` Record schema fingerprints for freshness.

### Phase 3 Task Matrix

- `[CLOSED]` `P3-T1` Build discovery and scope decisions.
- `[CLOSED]` `P3-T1-S1` Reuse sitemap discovery primitives.
- `[CLOSED]` `P3-T1-S2` Add normalization and dedupe.
- `[CLOSED]` `P3-T1-S3` Add eligibility and scope rules.
- `[CLOSED]` `P3-T2` Build capture and extraction flow.
- `[CLOSED]` `P3-T2-S1` Reuse or adapt raw page capture.
- `[CLOSED]` `P3-T2-S2` Add source-normalization output.
- `[CLOSED]` `P3-T2-S3` Reuse or adapt structured extraction and ingestion packaging.

### Phase 4 Task Matrix

- `[CLOSED]` `P4-T1` Build AI context creation.
- `[CLOSED]` `P4-T1-S1` Define prompt input contract.
- `[CLOSED]` `P4-T1-S2` Persist artifact output with traceability.
- `[CLOSED]` `P4-T1-S3` Support per-URL rerun.
- `[CLOSED]` `P4-T2` Build AI initial classification.
- `[CLOSED]` `P4-T2-S1` Use target object inventory as context.
- `[CLOSED]` `P4-T2-S2` Persist alternate classifications and taxonomy hints.
- `[CLOSED]` `P4-T2-S3` Persist confidence and rationale.

### Phase 5 Task Matrix

- `[CLOSED]` `P5-T1` Build mapping index generation.
- `[CLOSED]` `P5-T1-S1` Compare structured content against narrowed field catalog.
- `[CLOSED]` `P5-T1-S2` Record unresolved items and confidence summaries.
- `[CLOSED]` `P5-T2` Build the media mapping track.
- `[CLOSED]` `P5-T2-S1` Reuse or adapt media candidate discovery.
- `[CLOSED]` `P5-T2-S2` Align media candidates to recommendation payloads.
- `[CLOSED]` `P5-T3` Build domain-scoped pattern reuse.
- `[CLOSED]` `P5-T3-S1` Persist domain pattern memory.
- `[CLOSED]` `P5-T3-S2` Reuse only sibling-domain patterns, never cross-domain patterns.
- `[CLOSED]` `P5-T4` Build target transforms and resolution preview.
- `[CLOSED]` `P5-T4-S1` Shape target-ready values for fields, taxonomies, and media.
- `[CLOSED]` `P5-T4-S2` Produce create or update or blocked resolution preview.
- `[CLOSED]` `P5-T5` Finalize canonical recommendations.
- `[CLOSED]` `P5-T5-S1` Build `mapping-recommendations.v2.json`.
- `[CLOSED]` `P5-T5-S2` Carry forward resolution metadata and review signals.

### Phase 6 Task Matrix

- `[CLOSED]` `P6-T1` Build the exception review workspace.
- `[CLOSED]` `P6-T1-S1` Build exception-first tables and filters.
- `[CLOSED]` `P6-T1-S2` Build URL inspector drawer surfaces.
- `[CLOSED]` `P6-T1-S3` Build evidence and mapping inspector tabs.
- `[CLOSED]` `P6-T2` Build override actions.
- `[CLOSED]` `P6-T2-S1` Add target object override.
- `[CLOSED]` `P6-T2-S2` Add field and taxonomy override.
- `[CLOSED]` `P6-T2-S3` Add media override.
- `[CLOSED]` `P6-T3` Build rerun actions and decision persistence.
- `[CLOSED]` `P6-T3-S1` Persist `mapping-decisions.v2.json`.
- `[CLOSED]` `P6-T3-S2` Persist `media-decisions.v2.json`.
- `[CLOSED]` `P6-T3-S3` Trigger per-stage reruns for a single URL.

### Phase 7 Task Matrix

- `[CLOSED]` `P7-T1` Build readiness and QA reports.
- `[CLOSED]` `P7-T1-S1` Build per-URL QA reports.
- `[CLOSED]` `P7-T1-S2` Build package QA reports.
- `[CLOSED]` `P7-T2` Build package assembly.
- `[CLOSED]` `P7-T2-S1` Build manifest and summary outputs.
- `[CLOSED]` `P7-T2-S2` Build records and media manifests.
- `[CLOSED]` `P7-T2-S3` Build package zip output.
- `[CLOSED]` `P7-T3` Build readiness UI surfaces.
- `[CLOSED]` `P7-T3-S1` Show blockers and warnings.
- `[CLOSED]` `P7-T3-S2` Show package history and readiness status.

### Phase 8 Task Matrix

- `[CLOSED]` `P8-T1` Make dry-run package-first.
- `[CLOSED]` `P8-T1-S1` Consume V2 package records for dry-run planning.
- `[CLOSED]` `P8-T1-S2` Surface dry-run readiness against package QA state.
- `[CLOSED]` `P8-T2` Align executor planning to package outputs.
- `[CLOSED]` `P8-T2-S1` Consume package-aligned records and media manifests.
- `[CLOSED]` `P8-T2-S2` Preserve guardrails, journaling, and rollback behavior.

### Phase 9 Task Matrix

- `[CLOSED]` `P9-T1` Build import execution history observability.
- `[CLOSED]` `P9-T1-S1` Surface package-linked import execution summaries in the V2 package workspace.
- `[CLOSED]` `P9-T1-S2` Surface downstream import run identifiers, approval state, and rollback status for recent executions.
- `[CLOSED]` `P9-T1-S3` Keep import execution detail inspectable without requiring direct artifact browsing.
- `[CLOSED]` `P9-T2` Build package workflow observability.
- `[CLOSED]` `P9-T2-S1` Show build, dry-run, preflight, and execute timestamps or latest-status markers for the selected package.
- `[CLOSED]` `P9-T2-S2` Show stage-scoped blockers, warnings, and deferred counts in one package workflow surface.
- `[CLOSED]` `P9-T2-S3` Keep package workflow surfaces synchronized after build, preflight, and execute mutations without manual refresh.
- `[CLOSED]` `P9-T3` Audit V2 reuse alignment before crawl-start UI work.
- `[CLOSED]` `P9-T3-S1` Confirm which existing Content Collector and crawl primitives are still actively reused by V2 run creation.
- `[CLOSED]` `P9-T3-S2` Record any misalignment, dormant dependency, or operator gap that would block a first-class V2 crawl-start flow.
- `[CLOSED]` `P9-T3-S3` Define the follow-on tranche boundary for a dedicated V2 crawl-start UI after Phase 9 closes.

### Phase 10 Task Matrix

- `[CLOSED]` `P10-T1` Build the V2 run-create surface in the `runs` workspace.
- `[CLOSED]` `P10-T1-S1` Add operator inputs for `domain`, `sitemapUrl`, `maxUrls`, and `forceRebuild`.
- `[CLOSED]` `P10-T1-S2` Submit run creation through `POST /dbvc_cc/v2/runs` and hydrate the selected run after success.
- `[CLOSED]` `P10-T1-S3` Keep stable selectors for future Playwright coverage around run creation.
- `[CLOSED]` `P10-T2` Build advanced per-run crawl override controls in V2.
- `[CLOSED]` `P10-T2-S1` Prefill supported override fields from the shared Configure defaults.
- `[CLOSED]` `P10-T2-S2` Submit supported overrides through `crawlOverrides` on the V2 `/runs` contract.
- `[CLOSED]` `P10-T2-S3` Keep Add-ons and Configure as the server-rendered source of default values.
- `[CLOSED]` `P10-T3` Build run-create observability and operator feedback.
- `[CLOSED]` `P10-T3-S1` Show request lifecycle and long-running state clearly in the `runs` workspace.
- `[CLOSED]` `P10-T3-S2` Surface validation, transport, and pipeline-start failures without requiring artifact inspection.
- `[CLOSED]` `P10-T3-S3` Add targeted browser QA once Playwright testing is resumed.

### Phase 11 Task Matrix

- `[CLOSED]` `P11-T1` Hydrate the selected-run overview from the existing V2 overview route.
- `[CLOSED]` `P11-T1-S1` Add a dedicated overview data hook or client module for `GET /dbvc_cc/v2/runs/{run_id}/overview`.
- `[CLOSED]` `P11-T1-S2` Replace placeholder overview cards with real run status, inventory, and stage-summary data.
- `[CLOSED]` `P11-T1-S3` Keep stable selectors around the overview root, stage cards, summary metrics, and next-action surfaces.
- `[CLOSED]` `P11-T2` Surface high-signal run monitoring state in the overview workspace.
- `[CLOSED]` `P11-T2-S1` Show current stage, progress, blockers, and stage-status distribution from the materialized run state.
- `[CLOSED]` `P11-T2-S2` Show key inventory and outcome counts such as discovered URLs, in-scope pages, exceptions, and readiness-adjacent signals where already available.
- `[CLOSED]` `P11-T2-S3` Add deterministic next-action affordances that route operators to `exceptions`, `readiness`, or `package` without introducing new mutation flows.
- `[CLOSED]` `P11-T3` Improve refresh and stale-state clarity for active runs.
- `[CLOSED]` `P11-T3-S1` Add manual refresh to the selected-run overview and related run-selection state.
- `[CLOSED]` `P11-T3-S2` Add a polling or auto-refresh strategy for active runs with explicit loading and stale-state indicators.
- `[CLOSED]` `P11-T3-S3` Add targeted validation for overview hydration and refresh behavior before broadening the tranche.

### Phase 12 Task Matrix

- `[CLOSED]` `P12-T1` Add recent activity to the selected-run overview payload.
- `[CLOSED]` `P12-T1-S1` Add a thin V2 service that derives recent run activity from the existing journey log without altering pipeline writes.
- `[CLOSED]` `P12-T1-S2` Extend `GET /dbvc_cc/v2/runs/{run_id}/overview` with a bounded recent-activity slice for the selected run.
- `[CLOSED]` `P12-T1-S3` Keep the activity payload read-oriented and deterministic so later timeline enhancements can layer on top cleanly.
- `[CLOSED]` `P12-T2` Render a recent-activity timeline in the V2 overview workspace.
- `[CLOSED]` `P12-T2-S1` Add a dedicated overview activity component with stable selectors and status treatment aligned to existing overview styling.
- `[CLOSED]` `P12-T2-S2` Keep the current overview read-oriented while surfacing step, time, scope, and message context for recent events.
- `[CLOSED]` `P12-T3` Add targeted validation for recent activity observability.
- `[CLOSED]` `P12-T3-S1` Add PHPUnit coverage for the overview payload and run-level event filtering.
- `[CLOSED]` `P12-T3-S2` Extend LocalWP Playwright coverage to assert the recent-activity surface appears in the overview workspace.

### Phase 13 Task Matrix

- `[CLOSED]` `P13-T1` Enrich V2 review payloads with human-readable schema labels.
- `[CLOSED]` `P13-T1-S1` Add a thin schema presentation resolver that maps `target_ref` values to field labels, machine refs, field types, object labels, and ACF group context from the existing target field catalog.
- `[CLOSED]` `P13-T1-S2` Extend review, readiness-adjacent, and package-adjacent payloads with additive display metadata instead of replacing machine refs.
- `[CLOSED]` `P13-T1-S3` Render human-readable labels everywhere the operator currently sees raw field or ACF refs as the primary label.
- `[CLOSED]` `P13-T2` Replace the raw override-first inspector workflow with explicit single-item recommendation decisions.
- `[CLOSED]` `P13-T2-S1` Add per-recommendation controls for `approve`, `reject`, `override`, and `leave unresolved` across field and media recommendations.
- `[CLOSED]` `P13-T2-S2` Keep raw refs and low-level override targets visible as secondary evidence, not the primary call to action.
- `[CLOSED]` `P13-T2-S3` Rework the mapping tab into side-by-side source evidence, recommended target evidence, and final decision state.
- `[CLOSED]` `P13-T3` Add single-item decision safety rails.
- `[CLOSED]` `P13-T3-S1` Warn on unsaved inspector changes before close, tab change, or record navigation.
- `[CLOSED]` `P13-T3-S2` Surface stale recommendation drift clearly and provide a deterministic reset or re-review path.
- `[CLOSED]` `P13-T3-S3` Add targeted validation for enriched review payloads and single-item decision state handling.

### Phase 14 Task Matrix

- `[CLOSED]` `P14-T1` Make the exception queue conflict-first and action-oriented.
- `[CLOSED]` `P14-T1-S1` Add dedicated queue filters and counts for conflicts, unresolved items, stale decisions, manual overrides, and ready-after-review items.
- `[CLOSED]` `P14-T1-S2` Add row-level quick actions that open the operator directly into the relevant conflict or unresolved resolver state.
- `[CLOSED]` `P14-T2` Build a conflict-resolution workflow inside the inspector.
- `[CLOSED]` `P14-T2-S1` Add a dedicated conflict-focused panel or tab that compares conflicting targets, source evidence, and current resolution reasoning.
- `[CLOSED]` `P14-T2-S2` Surface confidence, policy, resolution, and stale-state explanations in operator-readable language.
- `[CLOSED]` `P14-T3` Add fast flagged-record navigation.
- `[CLOSED]` `P14-T3-S1` Add `previous`, `next`, `save and next`, and `save and close` review actions inside the drawer.
- `[CLOSED]` `P14-T3-S2` Preserve queue context while moving across flagged URLs so operators do not lose filter state.
- `[CLOSED]` `P14-T3-S3` Add targeted validation for conflict filtering, resolver state, and save-and-next navigation.

### Phase 15 Task Matrix

- `[CLOSED]` `P15-T1` Make readiness blockers actionable.
- `[CLOSED]` `P15-T1-S1` Add shortcuts from readiness blockers and per-page QA rows into the first relevant review target or filtered queue state.
- `[CLOSED]` `P15-T1-S2` Add readiness-focused filters for pages blocked by review, QA, or package prerequisites.
- `[CLOSED]` `P15-T2` Harden package preflight and execute controls.
- `[CLOSED]` `P15-T2-S1` Add intentional confirmation UX for preflight approval and execute operations.
- `[CLOSED]` `P15-T2-S2` Add clear disabled-reason messaging when package actions are not yet eligible.
- `[CLOSED]` `P15-T2-S3` Surface post-execute follow-up state such as rollback status and recent import outcome more clearly.
- `[CLOSED]` `P15-T3` Turn package artifact references into useful operator actions.
- `[CLOSED]` `P15-T3-S1` Add inspect, open, or download actions for selected package artifacts where safe.
- `[CLOSED]` `P15-T3-S2` Add clearer package manifest drill-ins so operators can see what will land where before execute.

### Phase 16 Task Matrix

- `[CLOSED]` `P16-T1` Add bulk review actions on top of the stabilized review workflow.
- `[CLOSED]` `P16-T1-S1` Support explicit filtered selection and bulk apply for low-risk review actions with auditability preserved.
- `[CLOSED]` `P16-T1-S2` Add carefully scoped bulk target-family assignment or approval helpers where the review model supports it.
- `[CLOSED]` `P16-T2` Add pragmatic run-level actions.
- `[CLOSED]` `P16-T2-S1` Add rerun helpers for failed or blocked stage groups without reviving V1 transport paths.
- `[CLOSED]` `P16-T2-S2` Add duplicate-run and noisy-run archive or hide affordances for operator cleanup.
- `[CLOSED]` `P16-T3` Add cross-workspace control-center shortcuts.
- `[CLOSED]` `P16-T3-S1` Add direct jumps from overview, readiness, and package surfaces to the most relevant blocking review targets.
- `[CLOSED]` `P16-T3-S2` Keep route and query state stable so control-center additions do not eject the operator from queue context.

### Phase 17 Task Matrix

- `[CLOSED]` `P17-T1` Add direct run replay actions.
- `[CLOSED]` `P17-T1-S1` Add a run-card replay action that reuses the stored run request profile through the existing `POST /runs` contract.
- `[CLOSED]` `P17-T1-S2` Keep replay progress, success, and failure visible through the existing run-start lifecycle surface instead of introducing a second replay panel.
- `[CLOSED]` `P17-T2` Clarify run-card action eligibility.
- `[CLOSED]` `P17-T2-S1` Explain why replay and duplicate helpers are unavailable for runs that predate request-profile capture.
- `[CLOSED]` `P17-T2-S2` Keep run-card state stable while replay is in progress so other control surfaces do not misreport availability.
- `[CLOSED]` `P17-T3` Add targeted validation for replay and recovery flows.
- `[CLOSED]` `P17-T3-S1` Extend browser coverage to prove replay starts from a stored profile and reuses lifecycle feedback.
- `[CLOSED]` `P17-T3-S2` Keep lint and build validation current for the revised runs surface.

### Phase 18 Task Matrix

- `[CLOSED]` `P18-T1` Make replay and rerun outcomes actionable from the existing runs workspace surfaces.
- `[CLOSED]` `P18-T1-S1` Add replay follow-up actions that let operators open the source run or the newly created run from the lifecycle panel.
- `[CLOSED]` `P18-T1-S2` Add rerun follow-up actions that let operators jump into the affected run overview or exception flow from the existing run-action status panel.
- `[CLOSED]` `P18-T2` Keep recovery follow-up state contextual without introducing a second run-recovery route model.
- `[CLOSED]` `P18-T2-S1` Keep replay and rerun follow-up actions scoped to the last visible recovery outcome in the current runs workspace session.
- `[CLOSED]` `P18-T2-S2` Preserve the existing run-start lifecycle and run-action status surfaces as the only recovery panels while actionability expands.
- `[CLOSED]` `P18-T3` Add targeted validation for recovery follow-up actions.
- `[CLOSED]` `P18-T3-S1` Add or refresh targeted browser coverage for replay follow-up and rerun follow-up shortcuts.
- `[CLOSED]` `P18-T3-S2` Keep lint and build validation current for the revised runs workspace.

### Phase 19 Task Matrix

- `[CLOSED]` `P19-T1` Materialize deterministic rerun-candidate recovery data for QA.
- `[CLOSED]` `P19-T1-S1` Add a non-production helper or fixture path that can surface `actionSummary.rerunCandidates` without waiting for incidental live failures.
- `[CLOSED]` `P19-T1-S2` Keep the seeded recovery data scoped so it does not change normal operator runtime behavior outside explicit QA or development use.
- `[CLOSED]` `P19-T2` Capture deterministic browser validation for replay and rerun follow-up shortcuts.
- `[CLOSED]` `P19-T2-S1` Update the refreshed browser smoke or companion validation path so replay and rerun follow-up shortcuts no longer depend on incidental LocalWP state.
- `[CLOSED]` `P19-T3` Keep LocalWP validation bootstrap guidance aligned with the active recovery QA workflow.
- `[CLOSED]` `P19-T3-S1` Document the current socket-backed PHPUnit setup and direct-admin browser entry expectations alongside the deterministic recovery-fixture workflow.

Phase 19 notes:

- deterministic rerun QA now uses a current-user-scoped recovery fixture route that overlays `actionSummary.rerunCandidates` without mutating stored journey artifacts
- the runs status panel now keeps the preserved recovery follow-up visible after duplicate-settings prefill even when no newer non-rerun completion message exists
- deterministic replay QA now reuses the existing `POST /runs` transport through an explicit dev-only `qaReplaySourceRunId` request flag, so replay follow-up coverage no longer depends on live sitemap refetch during LocalWP validation
- the replay lifecycle success alert now exposes a stable created-run identifier for browser validation, and the refreshed smoke keeps using the existing replay UI path while tolerating transient `ERR_ABORTED` admin navigations in LocalWP
- unsandboxed targeted CLI Playwright is now green for both the replay smoke and the rerun recovery smoke

### Phase 20 Task Matrix

- `[CLOSED]` `P20-T1` Make historical overview reads use run-scoped discovery artifacts after same-domain replay chains.
- `[CLOSED]` `P20-T1-S1` Add or reuse a run-scoped inventory resolver so historical overview reads stop depending on domain-latest inventory when a newer replay exists.
- `[CLOSED]` `P20-T2` Audit readiness and package readers for historical artifact drift after later same-domain replays.
- `[CLOSED]` `P20-T2-S1` Reuse the same run-scoped artifact-resolution rule across readiness and package entry points instead of adding surface-specific exceptions.
- `[CLOSED]` `P20-T3` Add targeted validation for same-domain historical run fidelity.
- `[CLOSED]` `P20-T3-S1` Cover at least one same-domain replay chain in PHPUnit or browser validation so older overview, readiness, or package surfaces prove they keep the requested run's artifacts.

### Phase 21 Task Matrix

- `[CLOSED]` `P21-T1` Preserve run-scoped page artifact references for same-URL historical reads.
- `[CLOSED]` `P21-T1-S1` Add a per-run snapshot, alias, or equivalent resolver for historical page artifacts that currently live at domain-scoped URL paths.
- `[CLOSED]` `P21-T2` Reuse preserved page artifacts in historical readiness and package flows.
- `[CLOSED]` `P21-T2-S1` Teach package and QA readers to prefer preserved run-scoped page artifacts before they treat a historical artifact as missing.
- `[CLOSED]` `P21-T3` Add targeted validation for same-URL historical fidelity.
- `[CLOSED]` `P21-T3-S1` Cover at least one same-domain rerun or replay where the same URL is processed twice and the older run still keeps its own page-level artifacts.

### Phase 22 Task Matrix

- `[CLOSED]` `P22-T1` Make historical exceptions and review payloads resolve run-scoped page artifacts after later same-domain same-URL runs.
- `[CLOSED]` `P22-T1-S1` Update exception queue and review context loaders to prefer `resolve_page_context_for_run()` or an equivalent run-aware page artifact resolver.
- `[CLOSED]` `P22-T2` Keep review-save and rerun writes scoped to the requested historical run.
- `[CLOSED]` `P22-T2-S1` Ensure review decisions and stage reruns do not overwrite a newer same-domain run's current page files when invoked from an older run.
- `[CLOSED]` `P22-T3` Add targeted validation for historical review fidelity.
- `[CLOSED]` `P22-T3-S1` Cover at least one same-domain same-URL review or rerun flow where the older run keeps its own recommendations and decisions after a newer run exists.

### Phase 23 Task Matrix

- `[CLOSED]` `P23-T1` Add targeted browser validation for same-URL historical exceptions and review flows.
- `[CLOSED]` `P23-T1-S1` Cover at least one same-domain same-URL chain where the older run's exception queue and review payload still open in the browser after a newer run exists.
- `[CLOSED]` `P23-T2` Validate historical decision-save and rerun follow-up route behavior in browser flows.
- `[CLOSED]` `P23-T2-S1` Confirm the browser follows the requested historical run after save or rerun instead of drifting into the newer same-URL run.
- `[CLOSED]` `P23-T3` Record the browser validation result and any remaining environment blockers.
- `[CLOSED]` `P23-T3-S1` Keep the validation record explicit if CLI Playwright or LocalWP browser constraints still require a headed or manual fallback.

### Phase 24 Task Matrix

- `[CLOSED]` `P24-T1` Add targeted browser validation for historical overview and readiness flows after same-URL overwrite chains.
- `[CLOSED]` `P24-T1-S1` Cover at least one overwrite chain where an older run's overview and readiness surfaces still load in the browser after a newer same-domain same-URL run exists.
- `[CLOSED]` `P24-T2` Validate historical package workspace route and selection fidelity in browser flows.
- `[CLOSED]` `P24-T2-S1` Confirm the package workspace keeps the requested `runId` and `packageId` pinned after historical shortcuts and artifact actions.
- `[CLOSED]` `P24-T3` Record the browser validation result and any remaining environment blockers for historical workspace fidelity.
- `[CLOSED]` `P24-T3-S1` Keep the validation record explicit if unsandboxed CLI Playwright or LocalWP still requires a narrowed fallback path.

### Phase 25 Task Matrix

- `[CLOSED]` `P25-T1` Add targeted browser validation for historical package dry-run follow-through after same-URL overwrite chains.
- `[CLOSED]` `P25-T1-S1` Cover at least one overwrite chain where an older run's package dry-run preview still opens in the browser after a newer same-domain same-URL run exists.
- `[CLOSED]` `P25-T2` Validate historical package workflow blocker shortcuts and guardrail state in browser flows.
- `[CLOSED]` `P25-T2-S1` Confirm package workflow blocker shortcuts and route parameters stay pinned to the requested historical `runId` and `packageId` instead of drifting into the newer run or package.
- `[CLOSED]` `P25-T3` Record the browser validation result and any remaining environment blockers for historical package workflow fidelity.
- `[CLOSED]` `P25-T3-S1` Keep the validation record explicit if unsandboxed CLI Playwright or LocalWP still requires a narrowed fallback path.

### Phase 26 Task Matrix

- `[CLOSED]` `P26-T1` Add targeted browser validation for historical package preflight approval after same-URL overwrite chains.
- `[CLOSED]` `P26-T1-S1` Cover at least one overwrite chain where an older run's package preflight approval request still completes in the browser after a newer same-domain same-URL run exists.
- `[CLOSED]` `P26-T2` Validate historical preflight summary and route-state fidelity in browser flows.
- `[CLOSED]` `P26-T2-S1` Confirm persisted preflight summary, route parameters, and selected package context stay pinned to the requested historical `runId` and `packageId` instead of drifting into the newer run or package.
- `[CLOSED]` `P26-T3` Record the browser validation result and any remaining environment blockers for historical preflight fidelity.
- `[CLOSED]` `P26-T3-S1` Keep the validation record explicit if unsandboxed CLI Playwright or LocalWP still requires a narrowed fallback path.

### Phase 27 Task Matrix

- `[CLOSED]` `P27-T1` Add targeted browser validation for historical package execution-observability after same-URL overwrite chains.
- `[CLOSED]` `P27-T1-S1` Cover at least one overwrite chain where an older run's latest recorded import summary still opens in the browser after a newer same-domain same-URL run exists.
- `[CLOSED]` `P27-T2` Validate historical import-history and route-state fidelity in browser flows.
- `[CLOSED]` `P27-T2-S1` Confirm import-history surfaces, route parameters, and selected package context stay pinned to the requested historical `runId` and `packageId` instead of drifting into the newer run or package.
- `[CLOSED]` `P27-T3` Record the browser validation result and any remaining environment blockers for historical execution observability.
- `[CLOSED]` `P27-T3-S1` Keep the validation record explicit if unsandboxed CLI Playwright or LocalWP still requires a narrowed fallback path.

### Phase 28 Task Matrix

- `[CLOSED]` `P28-T1` Record the current non-destructive historical package browser-validation boundary in the guide and working state.
- `[CLOSED]` `P28-T1-S1` Make it explicit that dry-run, preflight, and execution-observability routes are green without firing a real import by default.
- `[CLOSED]` `P28-T2` Define the approval and disposable-data prerequisites for any future historical package execute browser mutation QA.
- `[CLOSED]` `P28-T2-S1` Require explicit approval before browser validation triggers a real `POST /runs/{run_id}/execute` mutation on LocalWP data.
- `[CLOSED]` `P28-T3` Preserve the environment record for unsandboxed CLI Playwright and the dev-only package execution QA fixture transport.
- `[CLOSED]` `P28-T3-S1` Keep the validation record explicit if the execution-observability smoke still depends on unsandboxed Playwright or fixture overlays when real import history is absent.

### Phase 29 Task Matrix

- `[CLOSED]` `P29-T1` Obtain explicit approval before any browser test triggers a real historical package execute mutation on LocalWP data.
- `[CLOSED]` `P29-T1-S1` Confirm the disposable data and rollback expectations for the exact run and package scope before execute QA starts.
- `[CLOSED]` `P29-T2` Validate that a real historical execute mutation stays pinned to the requested `runId` and `packageId` after a same-domain same-URL overwrite chain.
- `[CLOSED]` `P29-T2-S1` Keep the browser route, selected package context, and follow-up execution state on the approved historical package instead of drifting into the newer run or package.
- `[CLOSED]` `P29-T3` Record destructive-QA results and any remaining environment blockers.
- `[CLOSED]` `P29-T3-S1` Keep unsandboxed Playwright requirements and cleanup expectations explicit for the approved execute mutation run.

### Phase 30 Task Matrix

- `[CLOSED]` `P30-T1` Record the post-Phase-29 boundary for rollback-eligible historical execute QA.
- `[CLOSED]` `P30-T1-S1` Make it explicit that `dbvc-codexchanges.local` validated real execute route fidelity but still produced zero rollbackable import runs under current guardrails.
- `[CLOSED]` `P30-T2` Define the isolation requirements for any future rollback-specific execute QA.
- `[CLOSED]` `P30-T2-S1` Keep all LocalWP scope pinned to `dbvc-codexchanges.local` and `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges` unless the user explicitly broadens it.
- `[CLOSED]` `P30-T3` Preserve the guardrail boundary for future rollback follow-up.
- `[CLOSED]` `P30-T3-S1` Do not disable guardrails or touch another LocalWP site or the LocalWP desktop app merely to fabricate rollback coverage.

### Phase 31 Task Matrix

- `[CLOSED]` `P31-T1` Decide whether rollback-specific historical execute QA is actually needed beyond the closed route-fidelity tranche.
- `[CLOSED]` `P31-T1-S1` Keep `dbvc-codexchanges.local` positioned as the approved route-fidelity baseline unless the user explicitly designates a different disposable target.
- `[CLOSED]` `P31-T2` Define the opt-in requirements for any future rollback-enabled target.
- `[CLOSED]` `P31-T2-S1` Require explicit user approval before touching another LocalWP environment, changing guardrail assumptions, or reopening destructive execute scope.
- `[CLOSED]` `P31-T3` Preserve the no-drift safety boundary for future follow-up.
- `[CLOSED]` `P31-T3-S1` Do not broaden beyond `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges` and `dbvc-codexchanges.local` unless the user explicitly says to do so.

### Phase 32 Task Matrix

- `[CLOSED]` `P32-T1` Re-anchor the next real V2 tranche on Vertical Field Context mapping accuracy.
- `[CLOSED]` `P32-T1-S1` Separate product/runtime gaps from environment-only backlog items such as agent-session transport problems or sandboxed Playwright launch failures.
- `[CLOSED]` `P32-T2` Land the chain-aware target slot graph and deterministic eligibility layer without reopening rollback-specific QA.
- `[CLOSED]` `P32-T2-S1` Keep `dbvc-codexchanges.local` as the approved current LocalWP scope unless the user explicitly broadens it.
- `[CLOSED]` `P32-T3` Replace greedy first-candidate finalization with deterministic assignment, unresolved bias, and reviewer-visible ambiguity framing inside the existing V2 runtime.
- `[CLOSED]` `P32-T3-S1` Do not create a new destructive execute target or rollback-specific tranche unless the user directly asks for it.
- `[CLOSED]` `P32-T4` Extend package and readiness gating so degraded Field Context coverage and reviewed ambiguity remain visible after deterministic selection.
- `[CLOSED]` `P32-T4-S1` Keep the gating work inside the current V2 readiness and package surfaces instead of building a new reviewer or QA screen.
- `[CLOSED]` `P32-T4-S2` Use benchmark-driven section semantics to suppress utility navigation fragments and split structured sections into role-specific source units before Field Context matching.
- `[CLOSED]` `P32-T4-S3` Enforce transform-side `value_contract` behavior before claiming client-ready Field Context package automation.
- `[CLOSED]` `P32-T4-S4` Add benchmark-based release thresholds now that benchmark rollups, transform-side contracts, and provider-drift checks are landed.
- `[CLOSED]` `P32-T5` Formalize the strongest missing structural mapping signals inside the current V2 pipeline before adding heavier retrieval.
- `[CLOSED]` `P32-T5-S1` Extend the slot graph and deterministic assignment with structural competition groups so non-repeatable sibling slots compete explicitly instead of only through soft scoring.
- `[CLOSED]` `P32-T5-S2` Replace generic unresolved buckets with typed unresolved classes that stay visible through review, readiness, and benchmark reporting.
- `[CLOSED]` `P32-T5-S3` Persist routing as a first-class page artifact with `primary_route` and `section_routes` so mapping can consume stable object and page-intent evidence without introducing a second routing subsystem.
- `[OPEN]` `P32-T6` Use measured Vertical benchmark pages to reduce remaining low-margin ambiguity now that routing evidence, competition groups, and typed unresolved classes are landed.
- `[OPEN]` `P32-T6-S1` Build labeled expected field/group matches for Home `/`, `/our-process`, `/pricing`, and `/get-started` on the Flourish benchmark run.
- `[OPEN]` `P32-T6-S2` Tune only against measured benchmark deltas, not anecdotal wins on one page, before broadening release claims.

## Reuse Strategy

V2 should reuse existing V1 capabilities where they already solve real problems well.

Good reuse candidates:

- sitemap parsing and crawl primitives
- artifact storage and domain pathing
- deterministic extraction and sectioning
- schema snapshot and target field catalog primitives
- media candidate discovery
- dry-run planning, journaling, and rollback guardrails

Reuse should not force V2 back into a V1-shaped architecture.

The rule is:

- reuse proven lower-level primitives
- replace broad or confusing mid-pipeline orchestration

## Build Toolchain Decision

V2 should reuse the existing WordPress React build stack already present in this repo.

Current baseline:

- `package.json`
- `@wordpress/scripts`
- `npm run start`
- `npm run build`

Implementation rule:

- add a dedicated V2 entrypoint to the existing build system instead of introducing a second JS toolchain by default

Preferred direction:

- keep using `@wordpress/scripts`
- add a separate V2 admin app entrypoint
- use the root build entry file `content-collector-v2-app.js`
- have that entry import:
  - `./addons/content-migration/v2/admin-app/index.js`
  - `./addons/content-migration/v2/admin-app/style.css`
- output:
  - `build/content-collector-v2-app.js`
  - `build/content-collector-v2-app.asset.php`
- use:
  - script handle `dbvc-content-collector-v2-app`
  - localized app bootstrap object `DBVC_CC_V2_APP`

When Phase 1 begins, remember to update `package.json` so `start`, `build`, and `lint` all include `content-collector-v2-app`.

Do not introduce Vite or a second frontend toolchain unless the current build system becomes a proven blocker.

## Testing and QA Plan

V2 should be validated in layers:

### 1. Contract and PHP tests

- PHPUnit coverage for deterministic services and contracts
- fixture-locked artifact tests
- REST permission and payload tests where practical

### 2. Frontend build and lint checks

- `npm run build`
- `npm run lint`
- V2 entrypoint build smoke for `content-collector-v2-app`

### 3. Browser QA

- manual QA in the LocalWP environment
- Playwright-based browser QA once V2 UI surfaces exist

Playwright direction:

- install and run Playwright from the local repo in Phase 1, immediately after the first V2 app root mounts
- add `@playwright/test` to the repo dev workflow
- run `npx playwright install` after dependency approval so browser binaries are available locally
- prefer browser QA against the LocalWP environment used for this addon
- capture stable selectors and route states for critical flows
- start with one smoke flow:
  - load the V2 workspace
  - confirm run shell visibility
  - confirm drawer open and close behavior

### 4. End-to-end operational QA

Use the local site as the target schema authority and verify:

- run creation and progress surfaces
- exception review flows
- per-URL override flows
- rerun flows
- readiness and package states
- dry-run and import gating

### QA prerequisites

- LocalWP site access
- authenticated admin credentials for V2 browser flows
- permission to install and run Playwright in the repo when implementation starts

Preferred credential handoff:

- provide the LocalWP site URL and the exact `wp-admin` login URL
- provide a temporary admin username and password dedicated to QA
- note any HTTP basic auth, unusual local domain, or login guard the browser must satisfy first
- avoid storing credentials in repo docs, fixtures, or committed config

Preferred credential type:

- use a temporary admin user for browser QA
- an Application Password is useful later for API-only smoke tests, but it does not replace full `wp-admin` access for Playwright review flows

Those credentials and environment details do not need to live in these docs, but implementation should expect them before browser QA starts.

## Rollout and Activation Strategy

V2 rollout should remain manual.

Planned approach:

- ship V2 behind the Content Collector runtime version toggle
- keep default runtime on `v1` until V2 is implementation-complete enough for controlled use
- switch to `v2` manually through `DBVC -> Configure -> Add-ons`
- validate in local and controlled environments before broader use

No automatic V1-to-V2 migration or forced rollout is assumed in the planning set.

## Remaining Freeze Points Before Coding

No blocking plan-level freeze points remain.

The first implementation round should confirm these documented defaults in code:

- run and package ID generation matches the recommended format
- AI timeout, retry, and fallback behavior matches the recommended defaults

## Main Implementation Principle

The V2 implementation should optimize for this operator experience:

`enable addon -> choose v2 -> crawl source -> build package automatically -> review only exceptions -> override only when needed -> import or export the package`
