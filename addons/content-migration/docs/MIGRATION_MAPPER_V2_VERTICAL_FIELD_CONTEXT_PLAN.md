# Migration Mapper V2 Vertical Field Context Integration Plan

## Purpose

Plan the DBVC Content Migration V2 integration with Vertical's resolved ACF field-context service so mapping, AI mapping prompts, reviewer QA, confidence scoring, and migration safety checks use Vertical's service payload as the canonical runtime source when it is available.

This plan is intentionally docs-only. It does not change the Vertical child-theme repo and does not implement runtime code yet.

## Runtime Source Policy

- Prefer Vertical's resolved field-context service payload over any DBVC-side recursive traversal of bundled theme ACF JSON.
- Use raw ACF JSON from the Vertical theme only for diagnostics and fixture comparison.
- Keep smart-authoring artifacts under Vertical's `_field-context-artifacts/` diagnostic only until their values are approved/applied and visible through the resolved catalog/service payload.
- Preserve the existing DBVC ACF runtime catalog as a fallback path for non-Vertical targets or unavailable providers, not as the primary source for Vertical field context.
- Treat Content Migration V2 as the primary integration surface. Legacy/shared services may receive thin adapters only when needed to keep existing catalog contracts stable.
- Treat Vertical group `location` as implemented provider data even if current examples are stale. Fixture drift is not a reason to parse raw Vertical ACF JSON.
- Treat Vertical `key_path` values as opaque provider IDs. Use exact string comparison only and rely on explicit hierarchy fields for display and scoring.

## Current DBVC Findings

- The current V2 target field catalog is built by `DBVC_CC_V2_Target_Field_Catalog_Service`, which wraps the legacy `DBVC_CC_Target_Field_Catalog_Service` catalog artifact.
- The legacy target field catalog currently uses active ACF runtime APIs such as `acf_get_field_groups()` and `acf_get_fields()` and captures shallow field/group data, including raw group `location`, but it does not consume Vertical's resolved purpose/context/status contract.
- The current V2 mapping index narrows ACF references with group `location` and simple field-name pattern extraction. It does not yet use group, branch, or field resolved context.
- The current repo does not contain DBVC field-context provider classes or `vf_field_context` references under `addons/content-migration/`, despite Vertical docs noting a remote adapter foundation. Treat this branch as missing that foundation unless a later branch says otherwise.
- The safest first integration point is the V2 target field catalog composition layer, followed by mapping index candidate scoring, recommendation trace propagation, schema presentation, review payloads, and package/readiness QA.
- The current DBVC landing zones already exist for this split: `shared/`, `mapping-catalog/`, `mapping-workbench/`, and `ai-mapping/`.

## Target Architecture

### Provider Layer

Add a DBVC field-context provider abstraction in `addons/content-migration/shared/` with local same-runtime and remote REST implementations.

Recommended responsibilities:

- Detect provider availability and contract version.
- Prefer local same-runtime Vertical helpers for same-site LocalWP and agency workflows.
- Fetch catalog, group, entry, and object-field payloads through the preferred Vertical helpers or the REST endpoint when remote mode is configured.
- Validate and normalize provider envelopes into a DBVC-internal shape.
- Preserve transport and catalog metadata for artifact traceability.
- Return structured unavailable/degraded states instead of forcing DBVC back into raw theme ACF JSON traversal.
- Keep raw REST calls and direct Vertical helper calls out of UI, mapping, and review code. Route all provider access through the DBVC provider/service abstraction.

Preferred same-runtime helpers:

- `vf_field_context_get_service_catalog_payload( $criteria, $profile )`
- `vf_field_context_get_service_group_payload( $group_identifier, $criteria, $profile )`
- `vf_field_context_get_service_entry_payload( $key_path, $criteria, $profile )`
- `vf_field_context_get_service_object_field_payload( $post_id, $field_selector, $profile )`
- `vf_field_context_get_entry_for_post_field( $post_id, $field_selector )`

Preferred remote endpoint:

- `GET /wp-json/vertical-framework/v1/field-context`

Remote mode should request scoped catalog/group payloads and normalize/cache them inside DBVC. Avoid per-field REST loops until Vertical ships batch lookup support.

Supported query concepts to normalize:

- `post_id`
- `profile`
- `group`
- `key_path`
- `name_path`
- `acf_key`
- `acf_name`

Projection profiles to support:

- `mapping` for catalog and mapper candidate usage.
- `summary` for status and lightweight diagnostics.
- `full` for reviewer details or focused object-field inspection.

### Normalized DBVC Shape

Normalize provider envelopes into DBVC structures that can be embedded in existing artifacts without replacing every artifact contract at once.

Catalog metadata to preserve:

- `catalog_meta.status`
- `catalog_meta.source_hash`
- `catalog_meta.cache_layer`
- `catalog_meta.cache_version`
- `provider.name`
- `provider.contract_version`
- `request.matched_by`

Group-level signals to preserve:

- `key_path`
- `name_path`
- `location`
- `context`
- `default_context`
- `resolved_purpose`
- `default_purpose`
- `status_meta`
- `coverage`
- `resolved_from`
- `fields` when present in the selected profile

Field/container signals to preserve:

- `key_path`
- `name_path`
- `parent_key_path`
- `parent_name_path`
- `group_key`
- `group_name`
- `acf_key`
- `acf_name`
- `scope`
- `type`
- `container_type`
- `context`
- `legacy`
- `default_context`
- `resolved_purpose`
- `default_purpose`
- `status_meta`
- `has_override`
- `resolved_from`
- `matched_by`
- ancestor or branch context where the provider profile exposes it

## Integration Phases

### Phase 1 - Provider Contract Baseline

- Add provider interface, local provider, remote provider, normalizer, match scorer, and structured error/degraded-state helpers.
- Treat the provider adapter foundation as not present in this branch unless it is discovered during implementation.
- Add fixture-based normalizer tests using snapshots copied into DBVC test fixtures from Vertical's example payloads.
- Keep default behavior unchanged when Vertical's provider is unavailable.
- Record provider status in target field catalog artifacts so downstream V2 services can make explicit fallback decisions.

### Phase 2 - Target Field Catalog Enrichment

- Compose Vertical field-context provider data into the V2 target field catalog artifact.
- Keep existing `target_ref` formats stable where possible, especially `acf:{group_key}:{field_key}` references.
- Add a field-context block to ACF group and field entries rather than forcing all consumers to switch immediately.
- Normalize group `location` into object compatibility signals for pages, CPTs, options pages, taxonomy contexts, and future object formats.
- Preserve source hash and cache metadata for schema fingerprinting and rerun warnings.

### Phase 3 - Mapping Candidate Scoring

- Update the V2 mapping index to use group, branch, and field context hierarchically:
  1. Field group context and object `location`.
  2. Branch/section ancestor context.
  3. Individual field context and purpose.
- Prefer exact opaque `key_path` comparison or scoped `acf_key`.
- Use explicit hierarchy fields such as `name_path`, `parent_key_path`, `parent_name_path`, `group_key`, `group_name`, `acf_key`, `acf_name`, `scope`, `type`, and `container_type` for display and hierarchy instead of parsing `key_path`.
- Use `acf_name` only as a lower-confidence fallback, especially when names repeat across groups or branches.
- Add confidence adjustments for `status_meta` and `resolved_from`:
  - Complete or override context can strengthen semantic matches.
  - Legacy-only context should warn and lower confidence.
  - Missing context should require review or block automated acceptance when the match depends on context.
  - Fallback-derived matches should be visible to reviewers.

### Phase 4 - Recommendation And AI Prompt Enrichment

- Carry normalized field-context evidence from the mapping index into recommendation artifacts.
- Enrich AI mapping prompt context with object compatibility, group purpose, branch purpose, field purpose, status metadata, and matched-by evidence.
- Keep AI prompt enrichment additive. Do not make generated AI text authoritative over Vertical's resolved catalog values.
- Preserve field-context trace in recommendation decisions so reviewer choices remain auditable across package generation.

### Phase 5 - Reviewer QA And Safety Checks

- Surface concise field-context diagnostics in the existing Content Migration workflow rather than adding a new complex UI.
- Show why a field matched: `matched_by`, provider status, source hash, branch context, field purpose, and status warnings.
- Add QA warnings or blockers for stale/missing provider payloads, location mismatch, duplicated-name fallback, legacy-only context, and missing context when semantic confidence depends on it.
- Add package/readiness checks that prevent silent automated migration when field-context evidence is degraded.
- Use local same-runtime provider degradation more strongly in scoring and QA. Keep remote provider degradation additive/warning-oriented until REST auth, batch lookup, retry/backoff, and error semantics are hardened.

### Phase 6 - Validation And Runtime Probes

- Add unit tests for fixture normalization, provider unavailable fallback, status/confidence adjustments, and object compatibility scoring.
- Add runtime probes against `dbvc-codexchanges.local` only when the Vertical provider is installed in that LocalWP environment.
- Exercise remote REST only when provider access is explicitly enabled and authenticated for the target site.
- Keep all destructive runtime QA scoped to disposable fixture data in the allowed DBVC LocalWP site.

## Recommended DBVC Files

### New Files

- `addons/content-migration/shared/dbvc-cc-field-context-provider-interface.php`
- `addons/content-migration/shared/dbvc-cc-field-context-provider-service.php`
- `addons/content-migration/shared/dbvc-cc-field-context-local-provider.php`
- `addons/content-migration/shared/dbvc-cc-field-context-remote-provider.php`
- `addons/content-migration/shared/dbvc-cc-field-context-normalizer.php`
- `addons/content-migration/shared/dbvc-cc-field-context-match-scorer.php`
- `addons/content-migration/shared/dbvc-cc-field-context-errors.php`
- `tests/phpunit/ContentCollectorV2VerticalFieldContextTest.php`
- `tests/phpunit/fixtures/field-context/*.json`

### Existing Files To Update In The Implementation Tranche

- `addons/content-migration/v2/schema/dbvc-cc-v2-target-field-catalog-service.php`
- `addons/content-migration/mapping-catalog/dbvc-cc-target-field-catalog-service.php`
- `addons/content-migration/v2/mapping/dbvc-cc-v2-mapping-index-service.php`
- `addons/content-migration/v2/mapping/dbvc-cc-v2-recommendation-finalizer-service.php`
- `addons/content-migration/v2/schema/dbvc-cc-v2-schema-presentation-service.php`
- `addons/content-migration/v2/review/dbvc-cc-v2-recommendation-review-service.php`
- `addons/content-migration/v2/package/dbvc-cc-v2-url-qa-report-service.php`
- `addons/content-migration/v2/package/dbvc-cc-v2-package-selection-service.php`
- `addons/content-migration/v2/package/dbvc-cc-v2-package-artifact-service.php`
- `addons/content-migration/v2/package/dbvc-cc-v2-package-qa-service.php`
- `addons/content-migration/mapping-workbench/` reviewer QA surfaces that display context status, `matched_by`, `resolved_from`, and missing/legacy-only warnings.
- `addons/content-migration/ai-mapping/` AI mapping prompt and candidate scoring helpers that can consume normalized group/branch/field context.

### Optional Later Files

- `addons/content-migration/v2/ai-context/dbvc-cc-v2-context-creation-service.php`
- `addons/content-migration/v2/ai-context/dbvc-cc-v2-initial-classification-service.php`
- Existing admin-app review components, only if a minimal diagnostic display cannot be handled by existing payload surfaces.

## Provider Contract Assumptions

- Vertical's provider payload is the canonical runtime context source when available.
- The provider envelope includes `provider`, `request`, `catalog_meta`, and `data`.
- `catalog_meta.source_hash` is stable enough to drive DBVC cache invalidation and stale-artifact warnings.
- `request.matched_by` is authoritative evidence for how a lookup resolved.
- Group `location` is implemented provider data, uses raw ACF field-group location rules, and should be normalized by DBVC into object compatibility signals.
- Example object compatibility from `location`: `core_group` applies to `post_type == page` and `post_type == vertical`; `services_group` applies to `post_type == service`.
- `status_meta` and `resolved_from` can be used to lower confidence and trigger reviewer warnings.
- The REST endpoint supports ETag/If-None-Match based on `source_hash`.
- Remote REST does not support local-only `field_selector`; remote consumers must use `key_path`, `name_path`, `acf_key`, or `acf_name`.
- `key_path` is an opaque provider ID. Do not infer hierarchy or separators from it.
- Hierarchy and display should use explicit fields such as `name_path`, `parent_key_path`, `parent_name_path`, `group_key`, `group_name`, `acf_key`, `acf_name`, `scope`, `type`, and `container_type`.

## Concerns And Vertical Handoff Gaps

- The Vertical handoff and implementation now expose group-level `location`, but the reviewed examples may be stale. Ask the Vertical session to refresh `docs/examples/field-context/provider-group-mapping.json` and `docs/examples/field-context/provider-catalog-summary.json` with group `location` examples.
- The Vertical docs mention a DBVC remote adapter foundation, but this repo branch does not include field-context adapter files. Confirm whether that work exists on another branch or should be implemented from scratch here.
- DBVC should treat provider `key_path` as opaque canonical data. Vertical fixtures/docs should avoid implying that consumers can parse a separator.
- Batch lookup is not implemented. Large catalog consumers should start with catalog/group profile requests and avoid per-field REST loops until a batch endpoint exists.
- REST `409` and `503` error modes are planned but not implemented. DBVC should initially rely on `400`, `401/403`, `404`, transport errors, and catalog status metadata.
- Remote authentication and site-to-site configuration need a stable policy before making remote mode the default.
- Options-page and taxonomy `location` normalization should be specified with representative examples before DBVC treats them as automation-safe object compatibility checks.

## Requested Vertical Fixture Updates

Ask the Vertical session to add or update these examples under `docs/examples/field-context/`:

- `provider-group-mapping.json` with current group `location` data.
- `provider-catalog-summary.json` with current group `location` data.
- `provider-group-mapping-page.json` showing `post_type == page`.
- `provider-group-mapping-service.json` showing `post_type == service`.
- `provider-group-summary-location.json` showing summary-profile group `location`.
- `provider-entry-branch-context-full.json` showing parent branch/section context, parent paths, and resolved purpose.
- `provider-duplicate-acf-name.json` showing repeated `acf_name` matches across different groups or branches.
- `provider-remote-error-401.json` for normal WordPress REST auth failure.
- `provider-remote-error-403.json` for capability failure.
- `provider-remote-error-404.json` for no matching field or group.
- `provider-remote-error-400-field-selector-without-post-id.json` for invalid selector requests.
- `provider-batch-planned-shape.json`, marked non-implemented, for future batch lookup design.

## Recommended Delivery Boundary

Implement this as a V2-focused tranche:

1. Provider abstraction and fixture normalization tests.
2. Target field catalog enrichment with metadata preservation.
3. Context-aware match scorer, mapping index scoring, and recommendation trace propagation.
4. Reviewer/QA diagnostics and package/readiness safety checks.
5. Runtime probe against a provider-enabled DBVC LocalWP fixture.

Do not implement a DBVC raw theme ACF JSON parser as part of this tranche.
