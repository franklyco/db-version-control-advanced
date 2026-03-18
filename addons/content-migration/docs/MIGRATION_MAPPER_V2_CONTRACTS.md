# Migration Mapper V2 Contracts

## Purpose

This document defines the proposed contract surface for `Migration Mapper V2`.

Its purpose is to freeze the high-level V2 data model before implementation work starts so that:

- pipeline stages are explicit
- artifact naming is consistent
- journey logging is standardized
- reviewer payloads are stable
- package outputs are stable
- downstream dry-run and execution services can consume V2 outputs without ambiguity

This is a planning contract, not an implementation-complete spec.

## Contract Rules

1. Deterministic stages write deterministic artifacts.
   Crawl, extraction, schema sync, normalization, transformations, and package assembly should be deterministic where possible.

2. AI stages write their own artifacts.
   V2 should not hide multiple reasoning steps inside one generic AI output file.

3. The current WordPress site is the target schema authority.
   The installed site's CPTs, taxonomies, registered meta, ACF fields, and media-capable fields are the target context for mapping and package generation.

4. Every recommendation must be traceable.
   Final recommendations should include source evidence, target evidence, confidence, and rationale.

5. Review is exception-based.
   The canonical review contract should prioritize blocked, low-confidence, or policy-sensitive cases. High-confidence cases should flow forward automatically.

6. Manual overrides and per-URL reruns are first-class.
   Reviewer decision artifacts should preserve both mapping overrides and rerun requests.

7. Package artifacts are first-class.
   The final package, manifest, QA report, and package summary should be contract-defined outputs rather than incidental files.

8. Runtime gating must be explicit.
   The add-on enable flag and selected runtime version must deterministically control which Content Collector runtime registers pages, routes, cron hooks, and assets.

## Version Anchors

Recommended anchors:

- `v2_pipeline_version`
- `artifact_schema_version`
- `prompt_version`
- `package_schema_version`

Recommended initial values:

- `v2_pipeline_version = 2.0.0`
- first V2 artifact families begin at `1.0`

## Runtime Gating Contract

Recommended settings:

- `dbvc_cc_addon_enabled`
- `dbvc_cc_runtime_version`

Allowed runtime values:

- `v1`
- `v2`

Required gating behavior:

- when disabled, no Content Collector runtime should register beyond minimal settings plumbing
- when enabled with `v1`, the current V1 runtime is the only active runtime
- when enabled with `v2`, the V2 runtime is the only active reviewer-facing runtime
- the selected runtime should determine which admin pages, routes, cron jobs, and assets are active

## V2 REST Surface Contract

The V2 UI should consume a run-based REST surface, even if lower-level internal services remain phase-oriented.

Recommended REST namespace:

- `dbvc_cc/v2`

Recommended resource families:

- `/runs`
- `/runs/{run_id}`
- `/runs/{run_id}/overview`
- `/runs/{run_id}/exceptions`
- `/runs/{run_id}/readiness`
- `/runs/{run_id}/package`
- `/runs/{run_id}/urls/{page_id}`
- `/runs/{run_id}/urls/{page_id}/decision`
- `/runs/{run_id}/urls/{page_id}/rerun`
- `/runs/{run_id}/dry-run`
- `/runs/{run_id}/import`

Recommended behavior:

- list and summary routes should be optimized for default workspace views
- deep evidence routes should support drawers and inspector panels
- mutation routes should be scoped to the affected run and URL where possible
- internal pipeline step names should not force the default UI to think in raw phase endpoints

Identifier rule:

- `runId` in route payloads should map directly to artifact `journey_id`
- do not invent a second independent run identity separate from `journey_id`

## Route and Query Naming Contract

Recommended route parameter names:

- `runId`

Recommended query or view-state keys:

- `pageId`
- `panel`
- `panelTab`
- `filter`
- `status`
- `q`
- `sort`
- `packageId`

Naming rules:

- use `pageId` instead of a raw `url` query key
- use `q` for free-text search
- use `panelTab` for nested inspector state
- use `filter` for operator queue filtering and `status` for lifecycle state
- use camelCase in UI route and query state even when persisted artifacts remain snake_case

## Automation Policy Settings Contract

V2 automation policy should be configurable from advanced Content Collector settings.

Recommended option keys and defaults:

- `dbvc_cc_v2_auto_accept_min_confidence = 0.92`
- `dbvc_cc_v2_block_below_confidence = 0.55`
- `dbvc_cc_v2_resolution_update_min_confidence = 0.94`
- `dbvc_cc_v2_pattern_reuse_min_confidence = 0.90`
- `dbvc_cc_v2_require_qa_pass_for_auto_accept = true`
- `dbvc_cc_v2_require_unambiguous_resolution_for_auto_accept = true`
- `dbvc_cc_v2_require_manual_review_for_object_family_change = true`

Policy rules:

- blocked state wins before auto-accept
- auto-accept requires confidence and policy gates to pass together
- values between block and auto-accept thresholds remain reviewable exceptions

## Domain Isolation Contract

All learned V2 behavior must be isolated per normalized source domain.

Required isolation boundaries:

- domain journey logs
- pattern memory
- reviewer decisions
- QA state
- package outputs
- target resolution history

Allowed shared layers:

- code and services
- prompt templates
- default automation settings
- generic schema and transform logic

Disallowed cross-domain reuse:

- do not apply learned patterns from one domain to another
- do not auto-accept based on reviewer history from another domain
- do not consult another domain's package, decision, or pattern artifacts during active run execution

## Naming Conventions

### Domain-level artifacts

Stored under:
- `uploads/contentcollector/{domain}/`

Recommended domain-level artifacts:
- `_journey/domain-journey.ndjson`
- `_journey/domain-journey.latest.v1.json`
- `_journey/domain-stage-summary.v1.json`
- `_inventory/domain-url-inventory.v1.json`
- `_learning/domain-pattern-memory.v1.json`
- `_schema/dbvc_cc_target_object_inventory.v1.json`
- `_schema/dbvc_cc_target_field_catalog.v2.json`
- `_packages/package-builds.v1.json`

### URL-level artifacts

Stored under the page directory for the normalized path.

Recommended URL-level artifacts:
- `{slug}.json`
- `{slug}.elements.v2.json`
- `{slug}.attribute-scrub-report.v2.json`
- `{slug}.source-normalization.v1.json`
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

## Canonical Domain-Level Contracts

### 1. `domain-url-inventory.v1.json`

Purpose:
- canonical list of URLs discovered for the domain run

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `domain-url-inventory.v1`
- `journey_id`
- `domain`
- `generated_at`
- `source`
- `urls[]`
- `stats`

Per-URL fields:
- `page_id`
- `source_url`
- `normalized_url`
- `path`
- `slug`
- `discovery_status`
- `discovery_reason`
- `scope_status`
- `scope_reason`

### 2. `dbvc_cc_target_object_inventory.v1.json`

Purpose:
- lightweight target object inventory used for initial classification

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `target-object-inventory.v1`
- `domain`
- `generated_at`
- `inventory_fingerprint`
- `object_types[]`
- `taxonomy_types[]`
- `stats`

Per-object-type fields:
- `object_key`
- `label`
- `type_family`
- `public`
- `hierarchical`
- `supports`
- `taxonomy_refs[]`

### 3. `dbvc_cc_target_field_catalog.v2.json`

Purpose:
- full target schema catalog used after object type narrowing

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `target-field-catalog.v2`
- `domain`
- `generated_at`
- `inventory_fingerprint`
- `catalog_fingerprint`
- `source_artifacts`
- `object_catalog`
- `taxonomy_catalog`
- `term_catalog`
- `meta_catalog`
- `acf_catalog`
- `media_field_catalog`
- `stats`

### 4. `domain-pattern-memory.v1.json`

Purpose:
- stores reusable classification and mapping patterns across sibling URLs and similar object types
- reusable patterns are scoped to a single normalized source domain only

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `domain-pattern-memory.v1`
- `domain`
- `generated_at`
- `source_journey_ids[]`
- `pattern_groups[]`
- `stats`

## Canonical URL-Level Contracts

### 1. Raw page artifact

Path:
- `{slug}.json`

Purpose:
- source-of-truth raw crawl capture

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `page-artifact.v1`
- `journey_id`
- `page_id`
- `source_url`
- `normalized_url`
- `path`
- `captured_at`
- `content_hash`
- `metadata`
- `headings`
- `text_blocks`
- `links`
- `images`
- `sections_raw`

### 2. `*.elements.v2.json`

Purpose:
- normalized element capture

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `elements.v2`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `elements[]`
- `stats`

### 3. `*.source-normalization.v1.json`

Purpose:
- records deterministic normalization performed before AI interpretation

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `source-normalization.v1`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `normalizations[]`
- `stats`

### 4. `*.sections.v2.json`

Purpose:
- deterministic section segmentation

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `sections.v2`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `sections[]`
- `stats`

### 5. `*.ingestion-package.v2.json`

Purpose:
- AI- and import-ready structured content package

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `ingestion-package.v2`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `sections`
- `trace`
- `stats`

### 6. `*.context-creation.v1.json`

Purpose:
- AI interpretation layer for content meaning and audience intent

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `context-creation.v1`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `prompt_version`
- `model`
- `status`
- `items[]`
- `summary`

Per-item fields:
- `item_id`
- `item_type`
- `source_refs`
- `context_tag`
- `audience_purpose`
- `authoring_intent`
- `technical_intent`
- `seo_intent`
- `downstream_instructions`
- `confidence`
- `rationale`

### 7. `*.initial-classification.v1.json`

Purpose:
- AI classification of the URL into likely target object types

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `initial-classification.v1`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `prompt_version`
- `model`
- `inventory_fingerprint`
- `status`
- `primary_classification`
- `alternate_classifications[]`
- `taxonomy_hints[]`
- `review`

### 8. `*.mapping-index.v1.json`

Purpose:
- candidate matrix between structured content items and target schema fields

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `mapping-index.v1`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `catalog_fingerprint`
- `classification_ref`
- `content_items[]`
- `unresolved_items[]`
- `stats`

Per-content-item fields:
- `item_id`
- `item_type`
- `source_refs`
- `target_candidates[]`
- `candidate_group`
- `confidence_summary`
- `notes`

### 9. `*.target-transform.v1.json`

Purpose:
- stores target-ready transformed values derived from the current mapping model

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `target-transform.v1`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `classification_ref`
- `transform_items[]`
- `stats`

Per-transform-item fields:
- `source_refs`
- `target_ref`
- `transform_type`
- `transform_status`
- `output_shape`
- `preview_value`
- `warnings[]`

### 10. `*.mapping-recommendations.v2.json`

Purpose:
- canonical reviewer-facing recommendation payload

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `mapping-recommendations.v2`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `classification`
- `recommended_target_object`
- `candidate_target_objects[]`
- `recommendations[]`
- `media_recommendations[]`
- `unresolved_items[]`
- `conflicts[]`
- `review`
- `trace`

Per-recommendation fields:
- `recommendation_id`
- `source_refs`
- `target_ref`
- `target_family`
- `target_field_key`
- `recommended_value_type`
- `confidence`
- `rationale`
- `source_evidence`
- `target_evidence`
- `requires_review`

### 11. `*.mapping-decisions.v2.json`

Purpose:
- reviewer decisions against the canonical recommendation payload

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `mapping-decisions.v2`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `recommendation_fingerprint`
- `decision_status`
- `target_object_decision`
- `approved[]`
- `overrides[]`
- `rejected[]`
- `unresolved[]`
- `reruns[]`
- `reviewer_meta`

Required `target_object_decision` fields:
- `decision_mode`
- `selected_target_family`
- `selected_target_object_key`
- `selected_taxonomy`
- `selected_resolution_mode`
- `based_on_recommendation_id`
- `reviewer_note`

Allowed `selected_resolution_mode` values:

- `update_existing`
- `create_new`
- `blocked_needs_review`
- `skip_out_of_scope`

### 12. `*.media-candidates.v2.json`

Purpose:
- media inventory aligned to the V2 recommendation model

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `media-candidates.v2`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `catalog_fingerprint`
- `media_items[]`
- `stats`

### 13. `*.media-decisions.v2.json`

Purpose:
- reviewer media decisions aligned to the V2 recommendation flow

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `media-decisions.v2`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `decision_status`
- `approved[]`
- `overrides[]`
- `ignored[]`
- `conflicts[]`

### 14. `*.qa-report.v1.json`

Purpose:
- URL-level QA validation summary before package assembly

Required top-level fields:
- `artifact_schema_version`
- `artifact_type` = `qa-report.v1`
- `journey_id`
- `page_id`
- `source_url`
- `generated_at`
- `readiness_status`
- `blocking_issues[]`
- `warnings[]`
- `quality_score`

## AI Stage Operating Contract

Each AI stage should have a stable operating contract.

Required per-stage metadata:

- `stage_key`
- `prompt_version`
- `model`
- `started_at`
- `finished_at`
- `input_artifacts`
- `output_artifacts`
- `fallback_mode`
- `status`
- `trace`

Recommended AI stage keys:

- `context_creation`
- `initial_classification`
- `mapping_index`
- `recommendation_finalization`

Required behavior:

- every AI stage writes an explicit artifact or explicit failure record
- AI stage failure should not silently skip the stage
- fallback behavior should be recorded as structured metadata, not hidden
- rerun requests should target a named stage and preserve the prior run trace
- prompt version changes should participate in freshness invalidation where applicable
- `stats`

## Package Contract

Package builds should be stored as first-class outputs.

Recommended package path:
- `uploads/contentcollector/{domain}/_packages/{package_id}/`

Required package artifacts:
- `package-manifest.v1.json`
- `package-records.v1.json`
- `package-media-manifest.v1.json`
- `package-qa-report.v1.json`
- `package-summary.v1.json`
- `import-package.v1.zip`

### `package-manifest.v1.json`

Required fields:
- `artifact_schema_version`
- `artifact_type` = `package-manifest.v1`
- `package_id`
- `journey_id`
- `domain`
- `generated_at`
- `target_schema_fingerprint`
- `included_pages[]`
- `included_object_types[]`
- `stats`

### `package-records.v1.json`

Required fields:
- `artifact_schema_version`
- `artifact_type` = `package-records.v1`
- `package_id`
- `records[]`

Per-record fields:
- `page_id`
- `source_url`
- `target_entity_key`
- `target_action`
- `field_values`
- `media_refs`
- `trace`

### `package-media-manifest.v1.json`

Required fields:
- `artifact_schema_version`
- `artifact_type` = `package-media-manifest.v1`
- `package_id`
- `media_items[]`

### `package-qa-report.v1.json`

Required fields:
- `artifact_schema_version`
- `artifact_type` = `package-qa-report.v1`
- `package_id`
- `generated_at`
- `readiness_status`
- `blocking_issues[]`
- `warnings[]`
- `quality_score`

### `package-summary.v1.json`

Required fields:
- `artifact_schema_version`
- `artifact_type` = `package-summary.v1`
- `package_id`
- `generated_at`
- `record_count`
- `exception_count`
- `auto_accepted_count`
- `manual_override_count`

## Domain Journey Event Contract

Raw event file:
- `_journey/domain-journey.ndjson`

Required common event fields:
- `journey_id`
- `pipeline_version`
- `domain`
- `step_key`
- `step_name`
- `status`
- `started_at`
- `finished_at`
- `duration_ms`
- `actor`
- `trigger`
- `input_artifacts`
- `output_artifacts`
- `source_fingerprint`
- `schema_fingerprint`
- `message`
- `warning_codes`
- `error_code`
- `metadata`
- `exception_state`
- `rerun_parent_event_id`
- `package_id`

Optional URL-scoped event fields:

- `page_id`
- `path`
- `source_url`
- `override_scope`
- `override_target`

## Domain Journey Status Contract

Allowed statuses:
- `queued`
- `started`
- `completed`
- `completed_with_warnings`
- `skipped`
- `blocked`
- `failed`
- `needs_review`

## Domain Journey Step Keys

### Domain-level

- `domain_journey_started`
- `target_schema_sync_started`
- `target_schema_sync_completed`
- `url_discovery_started`
- `url_discovery_completed`
- `target_object_inventory_started`
- `target_object_inventory_built`
- `target_schema_catalog_started`
- `target_schema_catalog_built`
- `pattern_memory_updated`
- `package_validation_completed`
- `package_built`
- `domain_journey_completed`

### URL-level

- `url_discovered`
- `url_scope_decided`
- `page_capture_started`
- `page_capture_completed`
- `source_normalization_completed`
- `structured_extraction_started`
- `structured_extraction_completed`
- `context_creation_started`
- `context_creation_completed`
- `initial_classification_started`
- `initial_classification_completed`
- `mapping_index_started`
- `mapping_index_completed`
- `target_transform_completed`
- `recommended_mappings_finalized`
- `review_presented`
- `review_decision_saved`
- `manual_override_saved`
- `stage_rerun_requested`
- `stage_rerun_completed`
- `qa_validation_completed`
- `package_ready`
- `dry_run_completed`
- `execute_completed`

## Freshness and Fingerprint Contract

Every artifact that depends on earlier outputs should capture the relevant fingerprint references.

Recommended fingerprints:
- `content_hash`
- `inventory_fingerprint`
- `catalog_fingerprint`
- `pattern_memory_fingerprint`
- `recommendation_fingerprint`
- `package_fingerprint`
- `journey_id`
- `prompt_version`

Minimum freshness rule:
- a downstream artifact is stale when one of its required upstream fingerprints changes

## Review Contract

The Workbench should consume `mapping-recommendations.v2.json` as the canonical source.

Reviewer actions should be limited to:
- `approve`
- `override`
- `reject`
- `ignore`
- `leave_unresolved`
- `send_back`
- `rerun_stage`

Decision artifacts should preserve:
- actor
- timestamp
- changed recommendation IDs
- freeform reviewer note
- rerun target stage when applicable
- selected target object family and object key when the object type is manually overridden

## Target Resolution Contract

Resolution should follow this priority order:

1. explicit manual override or reviewer decision
2. prior DBVC linkage or idempotency evidence proving an existing target object
3. exact in-scope target match for the selected family and object key
4. create-new when no eligible target exists and required create inputs are complete
5. block when ambiguity, unsupported shape, or missing required context remains

Recommended block conditions:

- multiple viable target matches in the selected family
- a candidate match exists only in a different object family and no override is present
- taxonomy or term parent requirements cannot be resolved
- the selected family or object key is no longer present in the current target schema
- the source URL is out of scope for import even if it reached the decision stage

Recommended metadata on final recommendation and decision payloads:

- `resolution_mode`
- `resolution_confidence`
- `resolution_reasons[]`
- `matched_target_ids[]`
- `requires_manual_resolution`

## Downstream Consumer Contract

Phase 4 services should consume:
- `mapping-decisions.v2.json`
- `media-decisions.v2.json`
- `mapping-recommendations.v2.json`
- `target-transform.v1.json`
- `qa-report.v1.json`
- `dbvc_cc_target_field_catalog.v2.json`

They should not depend on:
- legacy `*.mapping.suggestions.json`
- legacy `*.mapping.review.json`

## Contract Freeze Recommendation

Before implementation begins, the team should explicitly approve:

1. the canonical V2 reviewer artifact names
2. the domain journey event schema
3. the allowed status and step key vocabulary
4. the split between `target object inventory` and `target field catalog`
5. the target-value transformation contract
6. the package artifact family and manifest shape
7. the decision to treat review as exception-based with override and rerun controls
8. the runtime gating contract for `disabled`, `v1`, and `v2`
9. the requirement that target object type override is part of the canonical reviewer decision model
