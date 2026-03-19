# Migration Mapper V1 Reuse Audit

## Purpose

This document identifies what the current `Migration Mapper V1` implementation already provides and how each subsystem should be treated while planning `Migration Mapper V2`.

Reuse categories:

- `keep`: strong fit for V2 with only minor interface cleanup
- `repurpose`: useful implementation, but the role or contract should change in V2
- `deprecate`: V1-specific behavior that should be retired or merged away

## Best Reuse Candidates

### 1. Bootstrap, settings, contracts, and module wiring

Disposition:
- `keep`

Why:
- the addon already has a modular runtime boundary
- feature flags, settings registration, and service container patterns are reusable
- V2 should extend this instead of replacing it

Primary files:
- `bootstrap/dbvc-cc-addon-bootstrap.php`
- `shared/dbvc-cc-contracts.php`
- `shared/dbvc-cc-service-container.php`
- `settings/dbvc-cc-settings-service.php`

### 2. Artifact storage, canonical URL handling, and per-domain file structure

Disposition:
- `keep`

Why:
- V1 already has deterministic file storage, domain indexing, redirect map updates, and append-only event logging
- this is a strong base for the V2 `domain journey`

Primary files:
- `collector/dbvc-cc-artifact-manager.php`

V2 note:
- extend this with journey-specific files instead of inventing a second storage model

### 3. Sitemap parsing and page capture

Disposition:
- `keep`

Why:
- V1 already handles sitemap expansion, per-URL crawl processing, and raw artifact generation
- this is still the correct front of the pipeline

Primary files:
- `collector/dbvc-cc-crawler-service.php`
- `collector/dbvc-cc-ajax-controller.php`
- `collector/assets/dbvc-cc-crawler-admin.js`

V2 note:
- simplify orchestration, but keep the crawler foundation

### 4. Structured extraction layer

Disposition:
- `keep`

Why:
- deep capture, element extraction, section segmentation, attribute scrubbing, and ingestion packaging are exactly the kind of deterministic inputs V2 needs
- these are among the most reusable parts of V1

Primary files:
- `content-context/dbvc-cc-element-extractor-service.php`
- `content-context/dbvc-cc-section-segmenter-service.php`
- `content-context/dbvc-cc-attribute-scrub-policy-service.php`
- `content-context/dbvc-cc-attribute-scrubber-service.php`
- `content-context/dbvc-cc-ingestion-package-service.php`

### 5. Schema snapshot and target field catalog

Disposition:
- `keep`

Why:
- V2 still needs target-side introspection
- the current schema snapshot and catalog foundation are already aligned with V2 mapping needs

Primary files:
- `schema-snapshot/dbvc-cc-schema-snapshot-service.php`
- `mapping-catalog/dbvc-cc-target-field-catalog-service.php`

V2 note:
- add a lightweight `target object inventory` before the full field catalog, but keep the existing schema foundations

### 6. Media candidate and decision handling

Disposition:
- `keep`

Why:
- media is already first-class in V1
- the current media candidate inventory, role hints, and decision persistence are directly reusable in V2

Primary files:
- `mapping-media/dbvc-cc-media-candidate-service.php`
- `mapping-media/dbvc-cc-media-decision-service.php`

### 7. Import handoff, dry-run planning, guarded execution, rollback journal

Disposition:
- `keep`

Why:
- even if V2 redesigns the mapping recommendation stages, downstream dry-run and execution guardrails are still valuable
- V2 should feed these subsystems better inputs rather than replacing them

Primary files:
- `import-plan/dbvc-cc-import-plan-handoff-service.php`
- `import-plan/dbvc-cc-import-plan-service.php`
- `import-executor/dbvc-cc-import-executor-service.php`
- `import-executor/dbvc-cc-import-run-store.php`

## Repurpose Candidates

### 1. Explorer

Disposition:
- `repurpose`

Current value:
- good artifact inspection layer
- domain tree, node preview, content context views, and audit/event visibility

Why it should change:
- in V2 it should become a `source evidence and domain journey inspector`, not just a crawl tree browser

Primary files:
- `explorer/dbvc-cc-explorer-service.php`
- `explorer/dbvc-cc-rest-controller.php`

### 2. AI service

Disposition:
- `repurpose`

Current value:
- job queueing, status lifecycle, fallback behavior, and status artifacts already exist

Why it should change:
- V1 AI is too broad and bundles too many concerns together
- V2 should split it into separate stages:
  - context creation
  - initial classification
  - initial data mapping and indexing
  - finalize recommended mappings

Primary files:
- `ai-mapping/dbvc-cc-ai-service.php`
- `ai-mapping/dbvc-cc-rest-controller.php`

### 3. Workbench shell and transport

Disposition:
- `repurpose`

Current value:
- already provides a reviewer-facing UI shell, domain filtering, suggestion transport, decision persistence, and payload inspection

Why it should change:
- V2 should review one canonical recommendation artifact rather than mixed legacy and newer review flows

Primary files:
- `mapping-workbench/dbvc-cc-workbench-service.php`
- `mapping-workbench/dbvc-cc-workbench-rest-controller.php`
- `mapping-workbench/assets/dbvc-cc-workbench.js`

### 4. Observability module

Disposition:
- `repurpose`

Current value:
- the module boundary exists but the implementation is not realized

Why it should change:
- this is the natural home for V2 `domain journey` logging and materialized status views

Primary files:
- `observability/dbvc-cc-observability-module.php`
- `collector/dbvc-cc-artifact-manager.php`

### 5. Mapping rebuild queue

Disposition:
- `repurpose`

Current value:
- V1 already has async domain rebuild orchestration

Why it should change:
- V2 can use the same pattern for targeted reruns of stale or failed journey stages

Primary files:
- `mapping-workbench/dbvc-cc-mapping-rebuild-service.php`

## Deprecate or Merge Candidates

### 1. Legacy AI suggestion artifacts

Disposition:
- `deprecate`

Why:
- `*.mapping.suggestions.json` and `*.mapping.review.json` are part of the older review lane
- V2 should collapse review into one canonical recommendation artifact

Primary files:
- `ai-mapping/dbvc-cc-ai-service.php`
- `mapping-workbench/dbvc-cc-workbench-service.php`

### 2. Generic single-pass AI thinking

Disposition:
- `deprecate`

Why:
- V2 needs more explicit and intelligible AI stages
- one large AI pass hides where classification or mapping reasoning came from

### 3. Stub exports module as an active planning anchor

Disposition:
- `deprecate or explicitly defer`

Why:
- export remains a planned boundary, but it is not a meaningful part of the live runtime today
- V2 planning should not depend on it unless export becomes an intentional deliverable again

Primary files:
- `exports/dbvc-cc-exports-module.php`

## V1 Functionality To Carry Forward Immediately

- crawl request intake and sitemap parsing
- deterministic page artifact storage
- element extraction and section packaging
- schema snapshot and target field catalog generation
- media candidate extraction and review state
- Workbench shell as a reviewer-facing entry point
- dry-run, approval, execute, and rollback guardrails

## V1 Functionality To Rebuild Around V2 Contracts

- AI stage boundaries
- review queue and recommendation payload model
- observability and per-domain logging
- rerun orchestration based on stale or blocked stages instead of one generic AI refresh flow

## Recommended Additional Reference Materials For V2 Planning

These are worth documenting before implementation starts:

- artifact exemplars:
  - one small brochure site
  - one directory or listings site
  - one service-business site
- prompt catalog:
  - one file per AI stage with purpose, inputs, outputs, and fallback rules
- feature-flag matrix:
  - current V1 flags
  - planned V2 flags
  - migration path
- route catalog:
  - AJAX, REST, cron, and admin page inventory
- settings matrix:
  - option key
  - default
  - owner subsystem
  - V2 disposition
- artifact lifecycle map:
  - which stage creates each file
  - which stage consumes it
  - stale dependencies
- failure taxonomy:
  - crawl failures
  - extraction failures
  - AI failures
  - schema failures
  - review-blocking states
- reviewer decision examples:
  - approved
  - overridden
  - unresolved
  - send-back
- domain journey examples:
  - successful full run
  - partial run with retries
  - stale rerun after schema change

## Main V1-to-V2 Planning Insight

V1 already has strong foundations in:

- deterministic capture
- structured extraction
- schema introspection
- review transport
- guarded import execution

The weakest part, and the part V2 should redesign most aggressively, is the middle interpretation layer:

- AI structure
- recommendation model
- logging clarity
- reviewer mental model
