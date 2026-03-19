# Migration Mapper V2 File and Module Plan

## Purpose

This document maps the `Migration Mapper V2` proposal to a realistic file plan inside the current addon.

The goal is to reuse proven V1 primitives where they help, while keeping the V2 runtime isolated inside a dedicated `v2` path instead of spreading new code across legacy folders.

## Recommended New Docs

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_OVERVIEW.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOC_INDEX.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOMAIN_JOURNEY.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_FILE_PLAN.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_CONTRACTS.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_PACKAGE_SPEC.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_UI_ARCHITECTURE.md`

## V2 Path Rule

All new V2 runtime code should live under:

- `addons/content-migration/v2/`

Thin bridges to shared V1 primitives are acceptable when intentional, but V2 should not be implemented by scattering new pipeline logic across the existing V1 directory layout.

Recommended top-level layout:

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

Recommended React app layout:

```text
addons/content-migration/v2/admin-app/
  app/
  workspaces/
  components/
  hooks/
  api/
  state/
  utils/
  types/
  styles/
```

## Contract and Settings Updates

Primary V2 file:
- `addons/content-migration/v2/shared/dbvc-cc-v2-contracts.php`

Add:
- runtime option keys for:
  - addon enabled
  - runtime version selection
- new artifact suffixes for:
  - `*.source-normalization.v1.json`
  - `*.context-creation.v1.json`
  - `*.initial-classification.v1.json`
  - `*.mapping-index.v1.json`
  - `*.target-transform.v1.json`
  - `*.mapping-recommendations.v2.json`
  - `dbvc_cc_target_object_inventory.v1.json`
  - `domain-pattern-memory.v1.json`
  - package artifacts and manifest names
- new journey step constants
- feature flags for:
  - domain journey logging
  - source normalization transforms
  - context creation
  - initial classification
  - initial mapping indexing
  - target-value transforms
  - pattern learning
  - recommendation finalization
  - package QA
  - package build

Recommended bridge:
- `addons/content-migration/shared/dbvc-cc-contracts.php` can remain the V1 contract surface
- V2 should define its own contract class and only reuse old constants intentionally

Build system guidance:
- reuse the existing `package.json` and `@wordpress/scripts` toolchain
- add a dedicated V2 admin app entrypoint instead of introducing a second JS toolchain by default
- use the root build entry file `content-collector-v2-app.js`
- have that entry import:
  - `addons/content-migration/v2/admin-app/index.js`
  - `addons/content-migration/v2/admin-app/style.css`
- output:
  - `build/content-collector-v2-app.js`
  - `build/content-collector-v2-app.asset.php`
- use script handle `dbvc-content-collector-v2-app`
- use localized bootstrap object `DBVC_CC_V2_APP`

## Runtime Gating and Add-ons UI

Reference pattern:
- `addons/bricks/bricks-addon.php`

Recommended V2 files:
- `addons/content-migration/v2/bootstrap/dbvc-cc-v2-addon.php`
- `addons/content-migration/v2/bootstrap/dbvc-cc-v2-runtime-registrar.php`
- `addons/content-migration/v2/admin/dbvc-cc-v2-configure-addon-settings.php`
- `addons/content-migration/v2/admin/dbvc-cc-v2-admin-menu-service.php`
- `addons/content-migration/v2/admin/dbvc-cc-v2-app-loader.php`

Responsibilities:
- expose `Enable Content Collector` and `Runtime Version` settings in `DBVC -> Configure -> Add-ons`
- gate route, cron, page, and asset registration by `disabled`, `v1`, or `v2`
- keep runtime selection logic out of the legacy V1 bootstrap
- keep Add-ons configuration server-rendered while mounting the V2 React workspace for operational surfaces

## V2 React App Structure

Recommended V2 UI source root:
- `addons/content-migration/v2/admin-app/`

Recommended V2 UI entry files:
- `addons/content-migration/v2/admin-app/index.js`
- `addons/content-migration/v2/admin-app/style.css`

Recommended workspace folders:
- `addons/content-migration/v2/admin-app/workspaces/runs/`
- `addons/content-migration/v2/admin-app/workspaces/run-overview/`
- `addons/content-migration/v2/admin-app/workspaces/exceptions/`
- `addons/content-migration/v2/admin-app/workspaces/readiness/`
- `addons/content-migration/v2/admin-app/workspaces/package/`

Recommended reusable UI folders:
- `addons/content-migration/v2/admin-app/components/tables/`
- `addons/content-migration/v2/admin-app/components/toolbars/`
- `addons/content-migration/v2/admin-app/components/badges/`
- `addons/content-migration/v2/admin-app/components/chips/`
- `addons/content-migration/v2/admin-app/components/drawers/`
- `addons/content-migration/v2/admin-app/components/inspectors/`
- `addons/content-migration/v2/admin-app/components/panels/`
- `addons/content-migration/v2/admin-app/components/tabs/`
- `addons/content-migration/v2/admin-app/components/accordions/`
- `addons/content-migration/v2/admin-app/components/modals/`

Ownership rules:
- `app/` owns shell, top-level providers, and route coordination
- `workspaces/` owns screen-level composition only
- `components/` owns reusable building blocks and composed panels
- `hooks/` owns REST and UI behavior hooks
- `api/` owns transport clients
- `state/` owns shared cross-surface stores or reducers
- `utils/` owns pure helpers only

Anti-patterns:
- do not place the full run workspace into one or two oversized files
- do not combine layout, business logic, table behavior, drawer behavior, and modal flows in one component
- do not default to raw technical pipeline screens as the main landing surfaces

## Journey and Observability

Recommended files:
- `addons/content-migration/v2/journey/dbvc-cc-v2-domain-journey-service.php`
- `addons/content-migration/v2/journey/dbvc-cc-v2-domain-journey-materializer-service.php`
- `addons/content-migration/v2/journey/dbvc-cc-v2-domain-journey-rest-controller.php`
- `addons/content-migration/v2/journey/dbvc-cc-v2-journey-module.php`

Responsibilities:
- append raw journey events
- materialize latest-state and summary files
- provide domain and URL journey inspection endpoints

## Discovery and Capture Layer

Keep:
- `addons/content-migration/collector/dbvc-cc-crawler-service.php`
- `addons/content-migration/collector/dbvc-cc-artifact-manager.php`
- `addons/content-migration/collector/dbvc-cc-ajax-controller.php`

Recommended additions:
- `addons/content-migration/v2/discovery/dbvc-cc-v2-url-inventory-service.php`
- `addons/content-migration/v2/discovery/dbvc-cc-v2-url-scope-service.php`
- `addons/content-migration/v2/capture/dbvc-cc-v2-capture-orchestrator-service.php`

Reuse strategy:
- wrap V1 crawl and storage primitives where appropriate
- emit V2 journey events at crawl start, URL discovery, capture start, capture finish, and capture failure
- write a reusable domain URL inventory artifact
- support explicit URL eligibility or scope decisions

## Extraction Layer

Keep:
- `addons/content-migration/content-context/dbvc-cc-element-extractor-service.php`
- `addons/content-migration/content-context/dbvc-cc-section-segmenter-service.php`
- `addons/content-migration/content-context/dbvc-cc-attribute-scrubber-service.php`
- `addons/content-migration/content-context/dbvc-cc-ingestion-package-service.php`

Recommended additions:
- `addons/content-migration/v2/extraction/dbvc-cc-v2-source-normalization-service.php`
- `addons/content-migration/v2/extraction/dbvc-cc-v2-structured-extraction-service.php`
- `addons/content-migration/v2/extraction/dbvc-cc-v2-ingestion-package-service.php`

Purpose:
- deterministic cleanup and normalization before AI interpretation
- extracted outputs become the canonical inputs for all V2 AI stages

## AI Context and Classification Layer Split

The current `dbvc-cc-ai-service.php` is too broad for V2 and should not be expanded further for V2 behavior.

Recommended new service split:
- `addons/content-migration/v2/ai-context/dbvc-cc-v2-context-creation-service.php`
- `addons/content-migration/v2/ai-context/dbvc-cc-v2-initial-classification-service.php`
- `addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-index-service.php`
- `addons/content-migration/v2/patterns/dbvc-cc-v2-pattern-learning-service.php`
- `addons/content-migration/v2/transform/dbvc-cc-v2-target-transform-service.php`
- `addons/content-migration/v2/mapping/dbvc-cc-v2-recommendation-finalizer-service.php`
- `addons/content-migration/v2/ai-context/dbvc-cc-v2-ai-pipeline-orchestrator-service.php`

Recommended role of each service:

`context-creation-service`
- interprets element and section purpose
- emits short context summaries and downstream hints

`initial-classification-service`
- maps the URL to likely target object types and taxonomy hints

`mapping-index-service`
- compares collected content items against narrowed schema targets
- builds field-level candidate indexes and unresolved lists

`pattern-learning-service`
- reuses successful mapping patterns across sibling URLs and similar object types

`target-transform-service`
- converts mapped content into target-ready values for the current site's field shapes

`recommendation-finalizer-service`
- consolidates all upstream signals into one recommendation payload

`ai-pipeline-orchestrator-service`
- decides which AI stages need to run or rerun
- writes journey events for each AI stage

## Target Object and Schema Introspection

Keep:
- `addons/content-migration/schema-snapshot/dbvc-cc-schema-snapshot-service.php`
- `addons/content-migration/mapping-catalog/dbvc-cc-target-field-catalog-service.php`

Add:
- `addons/content-migration/v2/schema/dbvc-cc-v2-target-object-inventory-service.php`
- `addons/content-migration/v2/schema/dbvc-cc-v2-target-field-catalog-service.php`

V2 split:
- `target object inventory` is a lightweight classification input
- `target field catalog` remains the full field-level mapping input
- the current WordPress site remains the target schema authority

## Review and Override

Keep and refactor:
- `addons/content-migration/mapping-workbench/dbvc-cc-workbench-service.php`
- `addons/content-migration/mapping-workbench/dbvc-cc-workbench-rest-controller.php`

Recommended additions:
- `addons/content-migration/v2/review/dbvc-cc-v2-exception-queue-service.php`
- `addons/content-migration/v2/review/dbvc-cc-v2-recommendation-review-service.php`
- `addons/content-migration/v2/review/dbvc-cc-v2-review-rest-controller.php`
- `addons/content-migration/v2/review/dbvc-cc-v2-rerun-controller.php`

UI goal:
- the Workbench should review `mapping-recommendations.v2.json` as the canonical payload
- the Workbench should surface only exceptions by default
- the Workbench should allow manual override and stage rerun on one URL
- the Workbench should allow per-URL target object type override
- the V2 review experience should keep the main workspace stable while revealing evidence in drawers, inspectors, row expansion, tabs, and accordions
- legacy AI suggestion review should be retired after migration

## Package Assembly and QA

Repurpose the current `exports` boundary into the V2 package subsystem.

Recommended files:
- `addons/content-migration/v2/package/dbvc-cc-v2-package-build-service.php`
- `addons/content-migration/v2/package/dbvc-cc-v2-package-qa-service.php`
- `addons/content-migration/v2/package/dbvc-cc-v2-package-rest-controller.php`
- `addons/content-migration/v2/package/dbvc-cc-v2-package-module.php`

Responsibilities:
- assemble mapped records and media manifests
- validate package readiness
- build zip or exportable package artifacts
- expose package summaries and build history

## Import Handoff and Downstream Consumers

Keep:
- `addons/content-migration/import-plan/dbvc-cc-import-plan-handoff-service.php`
- `addons/content-migration/import-plan/dbvc-cc-import-plan-service.php`
- `addons/content-migration/import-executor/dbvc-cc-import-executor-service.php`

Enhance:
- consume the final V2 recommendation artifact as the main mapping input
- keep dry-run and execute as downstream consumers of V2 decisions

Recommended V2 import bridge additions:
- `addons/content-migration/v2/import/dbvc-cc-v2-import-plan-bridge-service.php`
- `addons/content-migration/v2/import/dbvc-cc-v2-import-executor-bridge-service.php`

## Keep, Enhance, Deprecate

Keep:
- raw page artifact generation
- element extraction
- section segmentation
- schema snapshot
- target field catalog
- dry-run and execute guardrails

Enhance:
- observability into a real domain journey subsystem
- AI mapping into multiple explicit stages
- Workbench into one canonical recommendation review UI
- package output into a first-class subsystem

Deprecate or merge over time:
- `*.mapping.suggestions.json`
- `*.mapping.review.json`
- any review queue that depends on the legacy suggestion format as the primary model

## Reuse and Adapter Notes

Best V1 reuse candidates:
- low-level crawl primitives
- artifact path and write helpers
- deterministic extraction services
- schema snapshot primitives
- target field catalog logic where still useful
- media candidate normalization
- dry-run, execute, journal, and rollback guardrails
- DBVC React admin app patterns for shell, workspace, drawer, table, and modal composition

Recommended reuse style:
- adapt lower-level capabilities behind V2 services
- do not let V2 depend directly on V1 reviewer artifacts or V1 broad AI orchestration

## Suggested Implementation Order

### W0
- contract additions
- journey file layout
- step naming freeze
- runtime gating contract freeze
- `v2/` path scaffold freeze

### W1
- Add-ons UI enablement and runtime version selector
- V2 bootstrap and runtime registrar
- real domain journey service
- V2 admin app entrypoint wiring into the existing build system
- `package.json` start, build, and lint wiring for `content-collector-v2-app`
- Playwright install and initial browser smoke scaffold after the first V2 app root mounts

### W2
- target object inventory artifact
- source normalization service
- URL scope decisions
- initial classification service

### W3
- context creation service
- mapping index service
- media alignment pass

### W4
- pattern-learning service
- target-transform service
- recommendation finalizer
- Workbench review payload integration
- target object type override controls

### W5
- package QA service
- package build service
- handoff alignment
- legacy artifact deprecation plan

## Main V2 Architectural Decision

The addon should move from:

- one broad AI service
- one legacy suggestion lane
- one newer deterministic bridge lane

to:

- one canonical recommendation pipeline
- one package build subsystem
- explicit AI stages with separate artifacts
- one domain journey logging subsystem
- one reviewer-facing recommendation payload per URL
- one Add-ons runtime gate that selects `disabled`, `v1`, or `v2`
- one dedicated `v2/` modular runtime path
- one import-ready package as the default end product
