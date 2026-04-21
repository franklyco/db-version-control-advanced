# Migration Mapper V2 Doc Index

## Purpose

This is the primary reference hub for `Migration Mapper V2`.

Use it to understand:

- which V2 planning docs exist
- the recommended reading order
- which doc is authoritative for which concern
- which docs must stay in sync during implementation

## Recommended Reading Order

### 0. Fast resume pack

Read these first when continuing active implementation work:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DECISIONS.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_ROUTE_ARTIFACT_LEDGER.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_CRAWL_REUSE_AUDIT.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_RUNTIME_HANDOFF.md`

These are the shortest path to:

- the current phase anchor
- the locked decisions
- the active route and artifact surface
- the current crawl reuse boundary
- the next implementation seam
- the current Vertical Field Context runtime implementation state

### 1. Product and workflow

Read first:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_OVERVIEW.md`
- `addons/content-migration/docs/CONTENT_COLLECTOR_PIPELINE_SWIMLANE.md`

These define:

- the product goal
- the user-facing workflow
- the internal pipeline
- the high-level step directory

### 2. Implementation rules

Read second:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md`

This defines:

- non-negotiable rules
- phase structure
- runtime gating
- modularity constraints
- QA and rollout expectations

### 3. UI and React architecture

Read third:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_UI_ARCHITECTURE.md`

This defines:

- the run-based workspace mental model
- screen inventory
- route and drawer behavior
- component boundaries
- React file and state ownership

### 4. Data and integration contracts

Read fourth:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_CONTRACTS.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOMAIN_JOURNEY.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_PACKAGE_SPEC.md`

These define:

- artifact families
- reviewer payloads
- journey event schema
- REST surface expectations
- AI stage expectations
- package outputs

### 5. Code structure and implementation mapping

Read fifth:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_FILE_PLAN.md`

This defines:

- expected V2 runtime folders
- React app structure
- module responsibilities
- reuse versus replacement boundaries

### 6. Drift and historical references

Use as support material:

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_CRAWL_REUSE_AUDIT.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_PIPELINE_REVIEW.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V1_REUSE_AUDIT.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V1_SYSTEM_INDEX.md`
- `addons/content-migration/README.md`
- `tests/fixtures/content-collector-v2/README.md`

These are useful for:

- explaining why V2 exists
- identifying reusable V1 primitives
- understanding current addon boundaries and legacy implementation context

## Authority Map

- Product goal and workflow:
  - `MIGRATION_MAPPER_V2_OVERVIEW.md`
- Official implementation rules and phase model:
  - `MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md`
- UI and React architecture:
  - `MIGRATION_MAPPER_V2_UI_ARCHITECTURE.md`
- Artifact, REST, and AI-stage contracts:
  - `MIGRATION_MAPPER_V2_CONTRACTS.md`
- Vertical Field Context runtime handoff:
  - `MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_RUNTIME_HANDOFF.md`
- Current crawl reuse boundary and landed run-start model:
  - `MIGRATION_MAPPER_V2_CRAWL_REUSE_AUDIT.md`
- Journey logging model:
  - `MIGRATION_MAPPER_V2_DOMAIN_JOURNEY.md`
- Package output contract:
  - `MIGRATION_MAPPER_V2_PACKAGE_SPEC.md`
- File and module layout:
  - `MIGRATION_MAPPER_V2_FILE_PLAN.md`
- Visual workflow reference:
  - `CONTENT_COLLECTOR_PIPELINE_SWIMLANE.md`

## Sync Rules

When one of these changes, update the others if needed:

- If the user-facing workflow changes:
  - update `OVERVIEW`
  - update `SWIMLANE`
  - update `UI_ARCHITECTURE`

- If screen layout, drawers, or route behavior changes:
  - update `UI_ARCHITECTURE`
  - update `FILE_PLAN`
  - update `IMPLEMENTATION_GUIDE` if rules or phase scope changed

- If crawl-start reuse boundaries or the `/runs` crawl-start contract change:
  - update `CRAWL_REUSE_AUDIT`
  - update `CONTRACTS`
  - update `UI_ARCHITECTURE`
  - update `IMPLEMENTATION_GUIDE`

- If artifact names, journey events, or reviewer payloads change:
  - update `CONTRACTS`
  - update `DOMAIN_JOURNEY`
  - update `PACKAGE_SPEC` if package-facing

- If Vertical Field Context provider normalization, target catalog enrichment, mapping traces, or review/package usage changes:
  - update `MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_RUNTIME_HANDOFF.md`
  - update `MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_IMPLEMENTATION_GUIDE.md` if the implementation plan changes
  - update `CONTRACTS` if artifact fields become formal contract fields

- If package outputs or readiness rules change:
  - update `PACKAGE_SPEC`
  - update `OVERVIEW`
  - update `IMPLEMENTATION_GUIDE`

- If V2 folder structure or module ownership changes:
  - update `FILE_PLAN`
  - update `UI_ARCHITECTURE`

## Current V2 Planning Set

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DECISIONS.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_ROUTE_ARTIFACT_LEDGER.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_CRAWL_REUSE_AUDIT.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_RUNTIME_HANDOFF.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOC_INDEX.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_OVERVIEW.md`
- `addons/content-migration/docs/CONTENT_COLLECTOR_PIPELINE_SWIMLANE.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_UI_ARCHITECTURE.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_CONTRACTS.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOMAIN_JOURNEY.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_PACKAGE_SPEC.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_FILE_PLAN.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_PIPELINE_REVIEW.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V1_REUSE_AUDIT.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V1_SYSTEM_INDEX.md`
- `tests/fixtures/content-collector-v2/README.md`

## Main Rule

Implementation should start from this index and treat the linked V2 docs as one coordinated planning set, not as isolated notes.
