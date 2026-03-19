# Phase 3 AI Mapping + Workbench Status

## Implemented Components
- AI service: `addons/content-migration/ai-mapping/dbvc-cc-ai-service.php`
- AI REST: `addons/content-migration/ai-mapping/dbvc-cc-rest-controller.php`
- AI module wiring: `addons/content-migration/ai-mapping/dbvc-cc-ai-mapping-module.php`
- Workbench service: `addons/content-migration/mapping-workbench/dbvc-cc-workbench-service.php`
- Workbench REST: `addons/content-migration/mapping-workbench/dbvc-cc-workbench-rest-controller.php`
- Workbench module wiring: `addons/content-migration/mapping-workbench/dbvc-cc-mapping-workbench-module.php`
- Workbench admin UI:
  - `addons/content-migration/mapping-workbench/views/dbvc-cc-workbench-page.php`
  - `addons/content-migration/mapping-workbench/assets/dbvc-cc-workbench.js`
  - `addons/content-migration/mapping-workbench/assets/dbvc-cc-workbench.css`

## REST Contract (AI)
- Namespace: `dbvc_cc/v1`
- `POST /ai/rerun`
- `POST /ai/rerun-branch`
- `GET /ai/status`

## REST Contract (Workbench)
- Namespace: `dbvc_cc/v1`
- `GET /workbench/domains`
- `GET /workbench/review-queue`
- `GET /workbench/suggestions`
- `POST /workbench/decision`
- `GET /mapping/handoff` (Phase 3.7 bridge to Phase 4 dry-run planner input; read-only)

## Artifact Contract Additions
- Per-page mapping suggestion artifact:
  - `{slug}.mapping.suggestions.json`
- Per-page review decision artifact:
  - `{slug}.mapping.review.json`

## Fixture Baselines Added
- `addons/content-migration/tests/fixtures/ai/batch-status.expected.json`
- `addons/content-migration/tests/fixtures/workbench/review-queue.expected.json`

## Behavior Notes
- AI lifecycle statuses supported: `queued`, `processing`, `completed`, `failed`.
- Fallback mode persists deterministic outputs and returns `mode: fallback`.
- Domain AI warning refresh runs in chunked batches (default 50) with polling timeout retry and auto queue refresh on completion.
- Workbench queue isolates uncertain/conflicting pages using:
  - `review.needs_review`
  - conflict flags
  - confidence thresholds
- Decisions are idempotent upserts per node path.

## Feature Flags Applied
- `dbvc_cc_flag_ai_mapping`
- `dbvc_cc_flag_mapping_workbench`

## Next Phase Entry
- Phase 3.5: tabbed admin consolidation (`Collect`, `Explore`, `Configure`).
- Phase 3.6: deep capture + context packaging + advanced AI section typing.
- Phase 3.7: target-field catalog + section/field/media mapping bridge (`Map Collection for Imports`) before Phase 4.
- Phase 4 follows after 3.7: import planning + dry-run gating + idempotent executor.
