# Migration Mapper V2 Working State

## Purpose

This file is the low-token resume anchor for active V2 implementation work.

Use it when resuming implementation after a pause instead of rereading the full planning set.

## Current Anchor

- Branch: `codex/content-addon-v2`
- Active phase: `Phase 9`
- Active task: `P9-T3 OPEN`
- Current seam: Phase 9 import-history and package-workflow observability are now landed; the remaining open seam is the reuse-alignment audit ahead of any dedicated V2 crawl-start UI work
- Latest landed focus: the package surface now persists and reloads package-linked dry-run, preflight, and execute workflow state plus recent import execution history in the V2 workspace

## Phase Snapshot

- `Phase 1`: `CLOSED`
- `Phase 2`: `CLOSED`
- `Phase 3`: `CLOSED`
- `Phase 4`: `CLOSED`
- `Phase 5`: `CLOSED`
- `Phase 6`: `CLOSED`
- `Phase 7`: `CLOSED`
- `Phase 8`: `CLOSED`
- `Phase 9`: `OPEN`

## Current Runtime Shape

- Runtime gating is controlled from `DBVC -> Configure -> Add-ons`
- `disabled` registers no Content Collector runtime surfaces
- `v1` keeps the legacy runtime active
- `v2` keeps the legacy reviewer UI dormant and mounts the V2 workspace instead
- The V2 admin shell currently exposes workspace surfaces for:
  - `runs`
  - `overview`
  - `exceptions`
  - `readiness`
  - `package`
- The V2 REST namespace is `dbvc_cc/v2`
- UI `runId` maps to artifact `journey_id`

## Current Open Seam

All currently defined guide phases are closed through `Phase 8`.

What is already true:

- V2 package assembly is implemented
- V2 package QA and readiness are implemented
- `GET /dbvc_cc/v2/runs/{run_id}/dry-run` consumes package records and package QA as the preferred upstream input
- `POST /dbvc_cc/v2/runs/{run_id}/preflight-approve` issues package-scoped approval tokens through the shared import executor
- `POST /dbvc_cc/v2/runs/{run_id}/execute` executes package-backed imports through the shared guardrail, journaling, and rollback path
- the V2 package surface now reloads persisted workflow state for build, dry-run, preflight, and execute
- the V2 package workspace now shows recent package-linked import execution history without requiring raw artifact inspection

What is still open:

- `Phase 9` is now open for operational UI and runtime polish
- the current V2 admin flow still does not provide a first-class in-app crawl-start form; run creation remains REST-backed for now
- Phase 9 should confirm whether the reused Content Collector and crawl primitives still align cleanly with V2 before that follow-on UI tranche is defined
- browser QA remains paused unless resumed explicitly
- dedicated crawl-start UI work remains a follow-on tranche after Phase 9

## Last Validation Baseline

These were the last known green validation anchors for the active implementation branch:

```bash
vendor/bin/phpunit --filter 'ContentCollectorV2Phase7Test|ContentCollectorV2Phase8Test|ContentCollectorV2Phase9Test'
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/workspaces/package/PackageWorkspace.js addons/content-migration/v2/admin-app/components/package/PackageHistoryTable.js addons/content-migration/v2/admin-app/components/package/PackageImportPanel.js addons/content-migration/v2/admin-app/components/package/PackageWorkflowPanel.js addons/content-migration/v2/admin-app/components/package/PackageImportHistoryPanel.js addons/content-migration/v2/admin-app/hooks/usePackageSurface.js addons/content-migration/v2/admin-app/hooks/useDryRunSurface.js addons/content-migration/v2/admin-app/hooks/useImportExecutionBridge.js
npm run build
```

## Known Local Noise

These local changes were intentionally left out of the implementation commits:

- `.phpunit.result.cache`
- `docs/ROADMAP.md`
- `test-results/`

## Resume Pack

Read these first when resuming:

1. `addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md`
2. `addons/content-migration/docs/MIGRATION_MAPPER_V2_DECISIONS.md`
3. `addons/content-migration/docs/MIGRATION_MAPPER_V2_ROUTE_ARTIFACT_LEDGER.md`
4. `addons/content-migration/docs/MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md`

Suggested resume prompt:

```text
Resume V2 from codex/content-addon-v2. Read WORKING_STATE, DECISIONS, ROUTE_ARTIFACT_LEDGER, and IMPLEMENTATION_GUIDE only. Continue Phase 9 operational workflow polish.
```

## Update Rule

Update this file at the end of each landed V2 tranche.

Keep it short, current, and implementation-focused.
