# Phase 3.7 to Phase 4 Handoff Runbook

This runbook defines how Phase 3.7 mapping artifacts are handed off into Phase 4 dry-run planning without performing import writes.

Phase 4 implementation planning and execution slices are tracked in:
- `addons/content-migration/docs/PHASE4_IMPORT_EXECUTION_PLAN.md`

## Scope

- Source: Phase 3.7 mapping catalog, section candidates, media candidates, and reviewer decisions.
- Output: deterministic `phase4_input` payload preview for dry-run planning.
- No import writes are performed in this runbook.

## Preconditions

1. Feature flags:
- `dbvc_cc_flag_mapping_catalog_bridge=1`
- `dbvc_cc_flag_media_mapping_bridge=1` (optional for media handoff; text-only still works)
- `dbvc_cc_flag_mapping_workbench=1`
2. Domain/path crawl artifacts exist under addon storage.
3. Mapping decisions are reviewed in Workbench.
4. Workbench domain source endpoint is reachable:
- `GET dbvc_cc/v1/workbench/domains`

## Operator Flow

1. Open Workbench and select a node.
2. In `Map Collection for Imports`, click `Load Mapping Package`.
3. Review or edit section and media mappings.
4. Save both decision artifacts:
- `Save Mapping Decision`
- `Save Media Decision`
5. Click `Preview Dry-Run Handoff`.
6. Click `Generate Dry-Run Plan`.
7. Confirm `Phase 4 Handoff` summary is `ready` and `Phase 4 Dry-Run Plan` summary is `ready` (or inspect `warnings`/`issues` if `needs_review`/`blocked`).
8. If AI warning badge appears, run domain AI refresh; Workbench now queues chunk batches and auto-refreshes review queue on completion.

## Pre-Phase 4 Approval Gate

Complete all items before approving Phase 4 implementation start:

1. Contract gate:
- `GET /mapping/handoff` returns `handoff_schema_version` and `phase4_input` for representative nodes.
- handoff fixture tests pass (`ready` and `needs_review` snapshots).
2. Endpoint gate:
- `GET /workbench/domains` returns current crawl domains used by Workbench selectors.
- Workbench domain dropdowns and mapping domain selector remain in sync during operator flow.
- `GET /import-plan/dry-run` returns a deterministic plan payload with `status`, `operation_counts`, `issues`, and `phase4_input`.
3. Review-state gate:
- representative mappings include manual override, ignore, and approved entries for both text and media.
- no blocking handoff warnings on at least one representative node.
4. Policy gate:
- media policy settings are confirmed (`allowlist`, `denylist`, mime, size, private-host block).
- scrub-policy approval workflow is validated for representative nodes.
5. QA gate:
- Phase 3.7 mapping decision tests and handoff bridge tests pass in LocalWP runtime.
- Local admin smoke pass is completed for `Map Collection for Imports` -> `Preview Dry-Run Handoff`.

## Direct Input Required Before Phase 4 Approval

These decisions should be explicitly confirmed to avoid rework during Phase 4:

1. Unresolved behavior:
- confirm if Phase 4 dry-run should hard-block on any unresolved text/media queues.
2. Smart object type mapping behavior:
- confirm if AI object-type mapping remains suggestion-only (recommended) or can auto-apply at a confidence threshold.
3. Media ingestion default:
- confirm default behavior for approved media in Phase 4 (`download_selected` recommended vs `remote_only`).
4. Duplicate media policy:
- confirm whether to prefer reuse of existing Media Library assets when URL/hash matches are found.
5. Auto-mapping confidence policy:
- confirm confidence threshold(s) for pre-approval suggestions vs mandatory reviewer action.
6. Attribute scrub scope defaults:
- confirm default scrub targets (`class`, `id`, `data-*`, inline styles, event attrs) for Phase 4 planner assumptions.
7. Batch sizing and timeout expectations:
- confirm target limits for dry-run batch size and execution time budget in LocalWP.
8. Audit/report expectations:
- confirm minimum report fields required in dry-run outputs for sign-off (`warnings`, mappings, media decisions, trace refs).

## REST Contract

Namespace: `dbvc_cc/v1`

- `GET /mapping/handoff`
  - Required query args:
    - `domain`
    - `path`
  - Optional query args:
    - `build_if_missing` (`true|false`, default `true`)
- `GET /import-plan/dry-run`
  - Required query args:
    - `domain`
    - `path`
  - Optional query args:
    - `build_if_missing` (`true|false`, default `true`)
- `POST /mapping/domain/rebuild`
  - Optional async args:
    - `run_now` (`true|false`, default `false`)
    - `batch_size` (`1..200`)
- `GET /mapping/domain/rebuild/status`
  - Required query args:
    - `batch_id`
- `POST /ai/rerun-branch`
  - Optional batch args:
    - `max_jobs` (chunk size, Workbench default `50`)
    - `offset` (zero-based chunk offset)
- `GET /ai/status`
  - Optional query args:
    - `batch_id`

Response shape (summary):

- `handoff_schema_version`
- `handoff_generated_at`
- `status`: `ready` | `needs_review`
- `domain`
- `path`
- `source_url`
- `dry_run_required`
- `idempotent_upsert_required`
- `trace` (`source_pipeline_id`, `artifact_refs`)
- `catalog`
- `section_candidates`
- `media_candidates`
- `mapping_decision_summary`
- `media_decision_summary`
- `blocking_warning_count`
- `warnings[]`
- `phase4_input`

Warning schema is fixed:
- `code`
- `message`
- `blocking`

## Phase 4 Input Contract (Preview)

`phase4_input` includes:

- `domain`
- `path`
- `source_url`
- `catalog_fingerprint`
- `approved_mappings[]`
- `mapping_overrides[]`
- `mapping_rejections[]`
- `unresolved_fields[]`
- `unresolved_media[]`
- `approved_media_mappings[]`
- `media_overrides[]`
- `media_ignored[]`
- `media_conflicts[]`
- `dry_run_required` (must be `true` before write execution)
- `idempotent_upsert_required` (must be `true`)

Deterministic ordering guarantees:
- mapping/media decision row lists are normalized and sorted before handoff payload emission.
- warning rows are normalized to fixed schema and sorted by `code|message|blocking`.

## Ready vs Needs Review

`status=ready` requires:

- text mapping decision is `approved`
- text unresolved queues are empty
- approved text mappings exist
- media decision is `approved` when media bridge is enabled
- media conflicts are empty
- no blocking handoff warnings (informational warnings may still be present)

Otherwise `status=needs_review` is returned and dry-run planning should not proceed automatically.

## Failure Handling

- Invalid domain/path returns `WP_Error` with status `400` or `404`.
- Disabled mapping bridge returns `403`.
- Missing optional media artifacts return warnings while keeping text handoff available.

## Guardrail Notes

- Dry-run remains mandatory before import writes.
- Upsert-only policy remains mandatory.
- Handoff payload is read-only and deterministic over current artifacts.
- No runtime code is loaded from `_source/content-collector`.
