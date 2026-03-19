# Migration Mapper V2 Package Specification

## Purpose

This document defines the intended end product of `Migration Mapper V2`:

- a target-adapted
- import-ready
- QA-validated
- reviewer-aware

package that can be consumed by downstream dry-run and import systems.

The package is the primary output of the V2 pipeline.

## Package Definition

An `import-ready package` is a structured export of mapped, transformed, and validated content that has already been aligned to the current WordPress site's schema.

It is not a raw crawl dump.
It is not only a recommendation list.
It is not only a dry-run handoff.

It is the refined payload that should be ready for import workflows with minimal additional decision-making.

## Package Goals

- include only in-scope URLs
- align records to target object types
- include target-ready field values
- include media mapping and media source context
- include package-level QA results
- preserve traceability back to source URLs and source evidence
- preserve reviewer overrides and exception history

## Build Preconditions

A package should only be built when:

- target schema sync is current
- required mapping stages are complete
- target-value transformations are complete
- package QA has run
- blocking issues are either resolved or explicitly allowed by policy

## Package Storage

Recommended path:
- `uploads/contentcollector/{domain}/_packages/{package_id}/`

Recommended package index:
- `uploads/contentcollector/{domain}/_packages/package-builds.v1.json`

Recommended package index extensions per build entry:

- `workflow_state`
  - latest build, dry-run, preflight, and execute snapshots for that package
- `import_history[]`
  - recent package-linked import execution summaries with downstream import run identifiers and rollback state

## Package Files

Required files:

- `package-manifest.v1.json`
- `package-records.v1.json`
- `package-media-manifest.v1.json`
- `package-qa-report.v1.json`
- `package-summary.v1.json`
- `import-package.v1.zip`

Optional support files:

- `package-exceptions.v1.json`
- `package-review-history.v1.json`
- `package-build-log.ndjson`

## Package Manifest

`package-manifest.v1.json` should include:

- `artifact_schema_version`
- `artifact_type`
- `package_id`
- `journey_id`
- `domain`
- `generated_at`
- `target_schema_fingerprint`
- `target_object_inventory_fingerprint`
- `included_pages[]`
- `included_object_types[]`
- `package_readiness_status`
- `stats`

Suggested `stats`:

- `record_count`
- `media_item_count`
- `auto_accepted_count`
- `manual_override_count`
- `exception_count`
- `blocking_issue_count`

## Package Records

`package-records.v1.json` should contain the target-adapted content records.

Per-record fields should include:

- `page_id`
- `source_url`
- `path`
- `target_object_family`
- `target_object_key`
- `target_object_source`
- `target_entity_key`
- `target_action`
- `target_subtype`
- `field_values`
- `taxonomy_values`
- `media_refs`
- `seo_values`
- `trace`
- `review_state`

`media_refs` should preserve downstream-consumer identifiers, including:

- `media_id`
- `media_kind`
- `source_refs[]`

`target_action` should clearly indicate:

- `create`
- `update`
- `blocked`

## Media Manifest

`package-media-manifest.v1.json` should include:

- `package_id`
- `media_items[]`

Per-media-item fields:

- `media_id`
- `source_url`
- `normalized_url`
- `target_ref`
- `target_role`
- `ingest_mode`
- `local_asset_ref`
- `alt_text`
- `caption_text`
- `trace`
- `review_state`

## Package QA Report

`package-qa-report.v1.json` should include:

- `package_id`
- `generated_at`
- `readiness_status`
- `quality_score`
- `blocking_issues[]`
- `warnings[]`
- `record_checks`
- `media_checks`
- `schema_checks`

Suggested QA areas:

- required field coverage
- unresolved mappings
- unsupported target shapes
- stale schema dependencies
- blocked entity resolutions
- missing media sources
- duplicate or conflicting target refs

## Package Summary

`package-summary.v1.json` should be optimized for UI and operator review.

Suggested fields:

- `package_id`
- `generated_at`
- `readiness_status`
- `record_count`
- `auto_accepted_count`
- `manual_override_count`
- `exception_count`
- `blocking_issue_count`
- `top_reason_codes[]`

## Exception Review Model

The package system should assume:

- most records are auto-accepted
- only exceptions are routed to manual review

Package review should therefore focus on:

- blocked records
- low-confidence mappings
- unresolved required fields
- policy-sensitive media or content
- records with manual rerun or override history

## Manual Overrides and Reruns

The package must preserve:

- which fields were manually overridden
- which URLs were manually approved
- which stage reruns were triggered
- which automated outputs were regenerated after human intervention

Suggested fields:

- `review_state.manual_override_count`
- `review_state.last_override_at`
- `review_state.target_object_overridden`
- `review_state.selected_target_object_key`
- `review_state.selected_target_object_family`
- `review_state.rerun_history[]`

## Package Build Flow

Recommended flow:

1. collect eligible URLs
2. resolve final recommendations and decisions
3. build target-ready transforms
4. run package QA
5. mark readiness
6. assemble package files
7. optionally zip package contents

## Relationship To Dry-Run and Import

The package should become the preferred upstream input for:

- import plan generation
- executor dry-run
- guarded import execution

That means downstream systems should be able to trust that the package already contains:

- target-aligned records
- target-ready values
- QA state
- reviewer decisions
- source traceability

## Main Package Principle

The package should be understandable on its own.

An operator reviewing the package should be able to answer:

- what will be imported
- where it will go
- whether it creates or updates
- what still needs attention
- whether the package is safe to use

## UI Surface Usage

Recommended package UI usage:

- `package-summary.v1.json` powers the default package and readiness summaries
- `package-qa-report.v1.json` powers blocking and warning detail panels
- `package-manifest.v1.json` powers package metadata and included-scope views
- `package-records.v1.json` and `package-media-manifest.v1.json` power deeper inspectors, not the default landing surface
