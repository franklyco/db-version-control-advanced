# Phase 4 Import Execution Plan

Goal: convert approved Phase 3.7 mapping decisions into deterministic, dry-run-first import execution with idempotent upserts, explicit operator approval before writes, and no runtime dependency on `_source/content-collector`.

## Baseline From Prior Phases

Phase 4 starts with these completed prerequisites:

1. Mapping/handoff contracts are live:
- `GET dbvc_cc/v1/mapping/handoff`
- `GET dbvc_cc/v1/import-plan/dry-run`
- handoff now emits `handoff_schema_version=1.1.0` plus a structured `review` block so blocked runs expose deterministic reason codes before execution planning starts

2. Workbench and data freshness controls are live:
- async mapping rebuild queue:
  - `POST dbvc_cc/v1/mapping/domain/rebuild`
  - `GET dbvc_cc/v1/mapping/domain/rebuild/status`
- AI warning refresh supports domain batch processing with chunking + retry and queue auto-refresh:
  - `POST dbvc_cc/v1/ai/rerun-branch` (`max_jobs`, `offset`)
  - `GET dbvc_cc/v1/ai/status` (`batch_id`)

3. Media mapping is first-class and part of handoff:
- `approved_media_mappings[]`
- `media_overrides[]`
- `media_ignored[]`
- `media_conflicts[]`

4. Guardrails already enforced:
- single-site only
- dry-run required policy
- idempotent upsert required policy
- deterministic fallback when AI is unavailable

Current safety gap to close next:
- execute now requires a separately approved dry-run fingerprint, journals entity/field/media writes, and automatically rolls back those journaled writes when a later entity/field/media mutation fails.
- media execution is now active for attachment ingest/reuse and post attachment-ID targets, but richer media target shapes and Workbench recovery/history UX still need their own finishing slice.

## Phase 4 Scope

In scope:
- import executor dry-run service and transport (`import-executor`)
- deterministic operation graph generation from `phase4_input`
- policy gate enforcement prior to any write-capable path
- structured execution report payloads for QA and sign-off

Out of scope for initial Phase 4 slice:
- full write execution to posts/meta/terms/media
- interactive React/Vue mapping workbench uplift
- multisite and block-template features

## Workstreams

### W0: Contract + Policy Anchors
- lock `import-plan` -> `import-executor` input/output contract.
- enforce hard block when dry-run/idempotent policies are disabled.
- freeze required report fields (`status`, `issues`, `operation_counts`, `simulated_operations`, `trace`).

### W1: Dry-Run Executor (Start Here)
- implement `DBVC_CC_Import_Executor_Service` with:
  - deterministic operation IDs
  - no-write simulation status per operation
  - blocking issue propagation from dry-run planner
- add REST transport:
  - `GET dbvc_cc/v1/import-executor/dry-run`
- wire module/bootstrap and add PHPUnit coverage.

### W2: Operation Graph Builder
- split operations into typed buckets:
  - entity/object operations (post/CPT/term target resolution)
  - field upsert operations (text/meta)
  - media mapping operations
- preserve stable ordering and fingerprints across reruns.
- include per-operation dependency hints (e.g., target entity created/located before field upsert).

Status update:
- implemented in current Phase 4 branch as dry-run graph output:
  - `operation_graph.entity_operations[]`
  - `operation_graph.field_operations[]`
  - `operation_graph.media_operations[]`
  - per-operation `depends_on[]`, `dependency_hints[]`, `execution_order`
- deterministic graph fingerprint now emitted:
  - `operation_graph.graph_fingerprint`
- executor now consumes `phase4_input.default_entity_key` (derived from mapping + suggestions) for core/acf target routing.
- manual object-type overrides from mapping decisions are now propagated through handoff and honored as highest-priority default entity routing.
- entity operations now include deterministic post-resolution outcomes:
  - `would_update_existing`
  - `would_create_new`
  - `blocked_needs_review`
- current match order is:
  - reserved source URL meta (`_dbvc_cc_source_url`)
  - reserved source path meta (`_dbvc_cc_source_path`)
  - reserved source hash meta (`_dbvc_cc_source_hash`)
  - hierarchical page path
  - subtype slug
- ambiguous or unsupported entity resolutions now emit blocking executor issues before any future write path is allowed.

### W3: Guarded Execute Path
- design write-capable endpoint/service skeleton and activate only the safest first slice.
- require explicit policy + capability + non-blocked dry-run report.
- preserve append-only observability logging for execution attempts.

Status update:
- guarded execute transport added:
  - `POST dbvc_cc/v1/import-executor/execute`
- current behavior is guarded entity + field + first-slice media execution:
  - returns `status=blocked` when guardrails fail.
  - returns `status=blocked` when write-preparation barriers exist even if guardrails pass.
  - returns `status=completed` when entity + field + supported media writes succeed.
  - returns `status=completed_partial` when execution succeeds but some later-stage work is still explicitly deferred by policy or unsupported target shape.
  - returns `status=completed_with_failures` when execution begins but one or more guarded write operations fail.
- execute responses now include a deterministic `write_preparation` payload:
  - `entity_writes[]`
  - `field_writes[]`
  - `media_writes[]`
  - `write_barriers[]`
- execute responses now include guarded execution results:
  - `entity_write_execution.operations[]`
  - `entity_write_execution.failures[]`
  - `field_write_execution.operations[]`
  - `field_write_execution.failures[]`
  - `executed_stages[]`
  - `deferred_stages[]`
- prepared field/media writes now include source payload bundles from stored crawl artifacts:
  - section text blocks, tagged text blobs, link targets, section media refs
  - media alt/caption/surrounding text, preview refs, role candidates, policy trace
- media source resolution now falls back from `media_id` to approved `source_url` when candidate IDs drift.
- prepared entity writes now stage reserved idempotency meta stamps:
  - `_dbvc_cc_source_url`
  - `_dbvc_cc_source_path`
  - `_dbvc_cc_source_hash`
- hierarchical create paths now prepare synthetic parent entity writes when an ancestor page path is missing, instead of immediately blocking the child write.
- create paths now recheck reserved idempotency meta before insert so reruns converge on update instead of duplicate create.
- execute attempts now emit append-only observability events (`stage=import_executor`).
- observability events now include phase4 context hints (`default_entity_key`, default-entity reason, override subtype, handoff schema version).
- Workbench now surfaces recent run history for the selected domain/path, selected-run journal actions, and targeted rollback controls without requiring raw debug payload review.

### W3A: Mandatory Preflight Approval Gate
- add a separate approval step between executor dry-run and execute.
- require execute to present a fresh approval token that is bound to the exact dry-run fingerprint.
- keep browser confirmation as a secondary guard only; it must not be the sole write gate.

Planned transport:
- `POST dbvc_cc/v1/import-executor/preflight-approve`
- `GET dbvc_cc/v1/import-executor/preflight-status`

Planned behavior:
- approval is allowed only when:
  - the executor dry-run status is `completed`
  - blocking issue count is `0`
  - write barriers are `0`
  - the operator explicitly confirms the reviewed dry-run summary
- approval must be bound to:
  - `dry_run_execution_id`
  - `plan_id`
  - `operation_graph.graph_fingerprint`
  - `phase4_context.default_entity_key`
  - `handoff_schema_version`
  - a source-freshness fingerprint derived from the latest handoff/planner inputs
- approval must expire after a short TTL so stale dry-runs cannot be executed later.
- approval must be invalidated when any of these change:
  - mapping decisions
  - target field catalog
  - handoff payload
  - dry-run plan fingerprint
  - source-domain artifact freshness marker
- `POST /import-executor/execute` must reject missing, expired, or stale approvals even when `confirm_execute=true`.

Operator UX requirements:
- Workbench adds an `Approve Import` action after `Run Executor Dry-Run`.
- approval UI must show a concise immutable summary before approval:
  - domain + path
  - default object type / override
  - create vs update counts
  - field write counts
  - deferred media counts
  - blocking issues / warnings
- Workbench execute button must remain disabled until a valid approval exists for the current dry-run fingerprint.

Status update:
- approval transport added:
  - `POST dbvc_cc/v1/import-executor/preflight-approve`
  - `GET dbvc_cc/v1/import-executor/preflight-status`
- approval tokens are transient-backed, short-lived, and user-bound.
- approval fingerprints currently bind to:
  - `dry_run_execution_id`
  - `plan_id`
  - `operation_graph.graph_fingerprint`
  - `write_preparation.write_plan_id`
  - `phase4_context.default_entity_key`
  - `handoff_schema_version`
  - source-freshness fingerprint derived from handoff/plan trace data
- execute now rejects:
  - missing approvals
  - invalid/expired approvals
  - stale approvals after dry-run graph changes
  - cross-user approval reuse
- Workbench now enforces `Run Executor Dry-Run` -> `Approve Import` -> `Run Execute`.
- local phase4 smoke now acquires preflight approval before execute.

### W3B: Rollback Journal + Recovery Controls
- durable run-ledger and action-journal storage is now active across entity, field, and first-slice media execution.
- entity, field, and media mutations now capture exact before-state and after-state data so partial runs can be reversed deterministically.

Recommended storage model:
- introduce custom tables because rollback data is operational state, not content artifacts:
  - `{$wpdb->prefix}dbvc_cc_import_runs`
  - `{$wpdb->prefix}dbvc_cc_import_run_actions`

Proposed `dbvc_cc_import_runs` fields:
- `id`
- `run_uuid`
- `approval_token_hash`
- `domain`
- `path`
- `dry_run_execution_id`
- `plan_id`
- `graph_fingerprint`
- `status`
- `created_by`
- `created_at`
- `approved_at`
- `started_at`
- `finished_at`
- `rollback_started_at`
- `rollback_finished_at`
- `rollback_status`

Proposed `dbvc_cc_import_run_actions` fields:
- `id`
- `run_id`
- `action_order`
- `stage` (`entity`, `field`, `media`)
- `action_type`
- `target_object_type`
- `target_object_id`
- `target_subtype`
- `target_meta_key`
- `before_state_json`
- `after_state_json`
- `rollback_status`
- `rollback_error`
- `created_at`

Rollback rules:
- created post/CPT:
  - permanently delete the created object so reserved idempotency meta cannot leak through trash
- updated post core fields:
  - restore exact prior values for `post_title`, `post_content`, `post_excerpt`, `post_name`, `menu_order`, `post_parent`
- updated meta/ACF fields:
  - restore the full prior value set exactly, including repeatable meta ordering
- future media stage:
  - first slice now deletes newly created attachments and restores prior attachment/meta references exactly

Recovery transport:
- `POST dbvc_cc/v1/import-executor/rollback`
- `GET dbvc_cc/v1/import-executor/runs`
- `GET dbvc_cc/v1/import-executor/run`

Failure-handling policy:
- first release should support manual rollback from a recorded run.
- manual rollback is now active for journaled entity/field writes.
- automatic rollback on failure is now active for journaled entity/field/media writes when execute encounters post/field/media mutation failures after write start.
- execution responses must surface:
  - `run_id`
  - `rollback_available`
  - `rollback_status`
  - `auto_rollback`
  - per-stage failure summaries

### W4: QA + Regression
- add fixtures/tests for:
  - ready plan -> completed dry-run execution report
  - blocked plan -> blocked execution report
  - deterministic operation ID/order across reruns
- add local smoke script for end-to-end:
  - safe-by-default path:
    - `Load Mapping Package` -> `Generate Dry-Run Plan` -> `Run Executor Dry-Run` -> `Approve Import`
    - `wp eval-file addons/content-migration/tools/dbvc-cc-phase4-smoke.php -- <domain> <path>`
  - opt-in write verification path:
    - `wp eval-file addons/content-migration/tools/dbvc-cc-phase4-smoke.php -- <domain> <path> --execute=1 --rollback-after-execute=1`

Status update:
- handoff metadata now propagates through planner/executor payloads:
  - `handoff_schema_version`
  - `handoff_generated_at`
- executor responses now include `phase4_context` for default entity/object-hint traceability.
- Workbench now surfaces a persistent note that AI refresh actions auto-refresh the queue.
- traversal-like page path inputs now return consistent `400` validation errors across handoff/import-plan/import-executor flows.
- Workbench summary now exposes `Phase 4 Context` so operators can see handoff version and default-entity reasoning without opening debug JSON.
- execute now applies first-slice media writes, including attachment ingest/reuse for featured-image and attachment-ID targets.
- smoke automation now defaults to a non-writing verification pass and can optionally execute + rollback for local QA.
- execute now auto-rolls back journaled entity/field/media writes when a later entity/field/media mutation fails after writes have started.
- Workbench summary and recovery status now surface `auto_rollback` execution results alongside manual rollback availability.

## Initial Implementation Items (Adapted)

1. Use the current handoff/dry-run planner outputs as the only executor input source.
2. Activate post/CPT entity upserts first, then mapped field/meta/ACF writes against resolved entity IDs.
3. Execute the first supported media slice with the same approval/journal/rollback guardrails; defer only unsupported media shapes or blocked policy cases.
4. Carry through media decisions in operation simulation outputs.
5. Surface stale/refresh requirements explicitly in execution issues (do not silently proceed).
6. Keep Workbench as the operator entry point; no standalone execution UI yet.

## Next Safety Slice (Planned)

1. Keep deferred media non-blocking for unsupported nested repeater/flexible targets and any broader target families that remain out of scope for Phase 4.
2. Expand media normalization only where it preserves idempotent writes and rollback safety, especially around richer object-style target metadata and attachment metadata policies.
3. Extend Workbench recovery from single-run inspection to richer cross-run compare/review workflows.
4. Tighten media policy UX so deferred-policy and deferred-missing-source reasons are easier to review before execute.
5. Only after approval-gated execution, auto-rollback, and current post/CPT media safety are stable should broader write stages be expanded.

## Immediate Recommendation After W3A/W3B

1. Treat current execute as controlled QA behavior until richer media target coverage and Workbench run-history visibility are implemented.
2. Keep the import execute feature flag intentional per environment while media execution is still in its first slice.
3. Expand media target support and operator history UX before calling Phase 4 execution complete.

## Acceptance Criteria For Phase 4 Entry Slice

1. `GET /import-executor/dry-run` returns deterministic payload for a `ready` handoff/plan.
2. Payload includes stable `execution_id`, `operation_counts`, and ordered `simulated_operations`.
3. Blocked inputs return `status=blocked` with issue propagation and no write actions.
4. Guarded execute can create/update entities idempotently without duplicating reruns.
5. Guarded execute can apply mapped field values idempotently without duplicating repeatable meta on reruns.
6. Media stages execute with journaling/rollback for the first supported slice and remain explicit in execute payloads when blocked or deferred by policy.
7. PHPUnit coverage passes for both service and endpoint.
8. No runtime imports from `_source/content-collector`.
