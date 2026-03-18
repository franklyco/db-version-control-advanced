# Migration Mapper V2 Domain Journey Logging

## Purpose

The `domain journey` is the transparent logging layer for `Migration Mapper V2`.

Each unique crawled domain should have one append-only journey that shows:

- what pipeline steps ran
- which URLs were discovered and processed
- what artifacts were created
- what AI layers ran
- what transformations ran
- what failed, was skipped, or needs review
- what was auto-accepted versus manually overridden
- what per-URL reruns were triggered
- what package build and QA status was reached
- what the latest state is for each URL and for the domain overall

This should be both human-readable and machine-readable.

## Design Goals

1. Domain-scoped first.
   Logging should be centered around the crawled domain, not just individual files.

2. Append-only event source.
   The raw journey log should never overwrite history.

3. Materialized latest state.
   The UI should not have to replay the full log for every page load.

4. URL-level traceability.
   Each URL should have a current stage state and a stage history.

5. Artifact linkage.
   Every event should be able to point to the artifacts it created or consumed.

6. Exception review visibility.
   The journey should make it obvious which URLs flowed straight through and which required manual review, overrides, or reruns.

7. Domain isolation.
   Journey state, learned behavior, and review history must stay isolated to the crawled domain.

## Isolation Rule

The journey subsystem must never mix state between domains.

Required behavior:

- each normalized domain gets its own journey files and materialized summaries
- reviewer actions written for one domain must not appear in another domain journey
- domain pattern memory updates must remain scoped to the same domain
- package build history must remain scoped to the same domain

## Proposed Files

Domain-level:
- `uploads/contentcollector/{domain}/_journey/domain-journey.ndjson`
- `uploads/contentcollector/{domain}/_journey/domain-journey.latest.v1.json`
- `uploads/contentcollector/{domain}/_journey/domain-stage-summary.v1.json`
- `uploads/contentcollector/{domain}/_journey/package-builds.v1.json`

Per-URL:
- `uploads/contentcollector/{domain}/_journey/urls/{slug}.url-journey.v1.json`

Optional reviewer audit:
- `uploads/contentcollector/{domain}/_journey/review/reviewer-actions.ndjson`

## Raw Event Model

Each event should include these common fields at minimum:

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

Use these only for URL-scoped events:

- `page_id`
- `path`
- `source_url`
- `override_scope`
- `override_target`

## Status Vocabulary

Recommended statuses:

- `queued`
- `started`
- `completed`
- `completed_with_warnings`
- `skipped`
- `blocked`
- `failed`
- `needs_review`

## Core Step Keys

Domain-level:
- `domain_journey_started`
- `url_discovery_completed`
- `target_schema_sync_completed`
- `target_object_inventory_built`
- `target_schema_catalog_built`
- `pattern_memory_updated`
- `package_validation_completed`
- `package_built`
- `domain_journey_completed`

URL-level:
- `url_discovered`
- `url_scope_decided`
- `page_capture_completed`
- `source_normalization_completed`
- `structured_extraction_completed`
- `context_creation_completed`
- `initial_classification_completed`
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

## Materialized Latest-State Model

`domain-journey.latest.v1.json` should summarize:

- `journey_id`
- `domain`
- `pipeline_version`
- `started_at`
- `updated_at`
- `status`
- `counts`
- `latest_stage_by_url`
- `urls_needing_review`
- `urls_auto_accepted`
- `urls_manually_overridden`
- `urls_rerun`
- `urls_blocked`
- `urls_failed`
- `urls_package_ready`
- `packages_built`
- `artifact_inventory`
- `latest_schema_fingerprint`

Suggested `counts`:
- `urls_discovered`
- `urls_captured`
- `urls_extracted`
- `urls_context_created`
- `urls_classified`
- `urls_mapped`
- `urls_finalized`
- `urls_reviewed`
- `urls_failed`
- `urls_blocked`

## Per-URL Journey Model

`{slug}.url-journey.v1.json` should summarize:

- `journey_id`
- `domain`
- `page_id`
- `path`
- `source_url`
- `current_status`
- `current_step`
- `completed_steps`
- `pending_steps`
- `artifact_refs`
- `latest_classification`
- `latest_recommendation_summary`
- `latest_review_status`
- `latest_package_status`
- `latest_error`

## Example Event Flow For One URL

1. `url_discovered`
2. `page_capture_completed`
3. `structured_extraction_completed`
4. `context_creation_completed`
5. `initial_classification_completed`
6. `mapping_index_completed`
7. `target_transform_completed`
8. `recommended_mappings_finalized`
9. `review_presented`
10. `review_decision_saved`
11. `qa_validation_completed`
12. `package_ready`

## Why This Improves Accuracy and Efficiency

Accuracy:
- makes it obvious which AI layer produced which output
- prevents silent stage skipping
- lets reviewers see whether a recommendation came from stale or fresh schema
- makes it visible when the system auto-accepted versus required human intervention

Efficiency:
- reruns can target only URLs and stages that are stale, failed, or blocked
- UI can load the materialized latest-state file instead of replaying the whole log
- domain-level summaries make batch progress visible without opening each URL
- package build progress can be monitored without walking every page

## UI Consumption Contract

The run-based UI should consume materialized journey outputs by default.

Recommended usage:

- run list and overview surfaces use `domain-journey.latest.v1.json`
- progress and stage breakdown surfaces use `domain-stage-summary.v1.json`
- URL drawers use `{slug}.url-journey.v1.json` plus URL artifacts
- raw `domain-journey.ndjson` should be treated as the audit source, not the default page-load payload

## Implementation Notes

- the append-only NDJSON log should be the source of truth
- the `latest` and `summary` JSON files should be derived materializations
- each stage should emit both `started` and terminal events
- AI events should include model and prompt version in `metadata`
- review events should include actor, changed fields, and decision counts
- manual override events should include override scope and selected target object details when applicable
- rerun events should include the triggering reason and parent stage
- package events should include `package_id`, readiness, and blocking counts

## Main V2 Requirement

The `domain journey` should be treated as a real subsystem, not just ad hoc event logging inside artifact writes.
