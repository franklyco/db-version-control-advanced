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
- `WIP`

Checklist:
- `[CLOSED]` Surface import execution history in the V2 package workspace.
- `[CLOSED]` Improve package workflow observability across build, dry-run, preflight, and execute stages.
- `[OPEN]` Confirm current V2 alignment with reused Content Collector and crawl primitives before a dedicated crawl-start UI tranche.

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
- `[OPEN]` `P9-T3` Audit V2 reuse alignment before crawl-start UI work.
- `[OPEN]` `P9-T3-S1` Confirm which existing Content Collector and crawl primitives are still actively reused by V2 run creation.
- `[OPEN]` `P9-T3-S2` Record any misalignment, dormant dependency, or operator gap that would block a first-class V2 crawl-start flow.
- `[OPEN]` `P9-T3-S3` Define the follow-on tranche boundary for a dedicated V2 crawl-start UI after Phase 9 closes.

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
