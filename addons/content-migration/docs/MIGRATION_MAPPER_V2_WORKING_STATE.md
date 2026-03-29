# Migration Mapper V2 Working State

## Purpose

This file is the low-token resume anchor for active V2 implementation work.

Use it when resuming implementation after a pause instead of rereading the full planning set.

## Current Anchor

- Branch: `codex/content-addon-v2`
- Active phase: `Phase 13`
- Active task: `P13-T1 OPEN`
- Current seam: the next blocker is reviewability, not observability. Raw field refs, weak conflict actions, and low-level override UX now constrain real operator testing more than missing run telemetry.
- Latest landed focus: the guide now sequences the next UX batch as `Phase 13` schema-label and single-item review foundations, `Phase 14` conflict-first review flow, `Phase 15` readiness and package actionability, and `Phase 16` operator efficiency and control-center additions

## Phase Snapshot

- `Phase 1`: `CLOSED`
- `Phase 2`: `CLOSED`
- `Phase 3`: `CLOSED`
- `Phase 4`: `CLOSED`
- `Phase 5`: `CLOSED`
- `Phase 6`: `CLOSED`
- `Phase 7`: `CLOSED`
- `Phase 8`: `CLOSED`
- `Phase 9`: `CLOSED`
- `Phase 10`: `CLOSED`
- `Phase 11`: `CLOSED`
- `Phase 12`: `CLOSED`
- `Phase 13`: `OPEN`
- `Phase 14`: `OPEN`
- `Phase 15`: `OPEN`
- `Phase 16`: `OPEN`

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

All currently defined guide phases are closed through `Phase 12`. `Phase 13` through `Phase 16` are now defined and open.

What is already true:

- V2 package assembly is implemented
- V2 package QA and readiness are implemented
- `GET /dbvc_cc/v2/runs/{run_id}/dry-run` consumes package records and package QA as the preferred upstream input
- `POST /dbvc_cc/v2/runs/{run_id}/preflight-approve` issues package-scoped approval tokens through the shared import executor
- `POST /dbvc_cc/v2/runs/{run_id}/execute` executes package-backed imports through the shared guardrail, journaling, and rollback path
- `POST /dbvc_cc/v2/runs` now accepts `crawlOverrides`, so the reusable crawl override model is exposed through the V2 run-create contract
- the V2 `runs` workspace now provides a first-class in-app run-start form
- the run-start surface now pre-fills supported advanced crawl overrides from shared Configure defaults
- the run-start surface now auto-selects the created run after success and exposes stable selectors for future browser QA
- the run-start surface now shows lifecycle timing, attempted request inputs, success and failure alerts, and a stage snapshot without leaving the `runs` workspace
- the V2 package surface now reloads persisted workflow state for build, dry-run, preflight, and execute
- the V2 package workspace now shows recent package-linked import execution history without requiring raw artifact inspection
- the Phase 9 audit confirms that shared crawl helpers, sitemap parsing, artifact storage, extraction primitives, schema services, and import guardrails already align with V2
- the LocalWP Playwright smoke now drives the V2 workspace by direct route, validates drawer toggle behavior, and verifies that run creation enters a visible lifecycle state
- the selected-run `overview` workspace now shows real summary metrics, stage monitoring, explicit refresh state, and deterministic next-action links without adding a new backend route
- the selected-run `overview` workspace now exposes bounded recent activity for the current run from the existing journey log without introducing a dedicated event endpoint

What is still open:

- `Phase 13` now starts with schema label enrichment and explicit single-item review actions because current exception and inspector UX are still too raw for intuitive conflict handling
- `Phase 14` is reserved for conflict-first queueing, explanations, and save-and-next review navigation once single-item decision controls are trustworthy
- `Phase 15` and `Phase 16` cover readiness or package actionability and later operator-efficiency or control-center actions, but they should not start before the review foundations are fixed
- the LocalWP site's custom `/login` page still renders a front-end login form even for an authenticated admin, so browser QA should keep using direct V2 admin routes instead of depending on the admin-menu path

## Last Validation Baseline

These were the last known green validation anchors for the active implementation branch:

```bash
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/app/ContentCollectorV2AppShell.js addons/content-migration/v2/admin-app/components/run-overview/OverviewNextActions.js addons/content-migration/v2/admin-app/components/run-overview/OverviewStageCards.js addons/content-migration/v2/admin-app/components/run-overview/OverviewSummaryCards.js addons/content-migration/v2/admin-app/hooks/useRunOverview.js addons/content-migration/v2/admin-app/workspaces/run-overview/RunOverviewWorkspace.js addons/content-migration/v2/admin-app/workspaces/run-overview/overviewTransforms.js tests/playwright/content-collector-v2.spec.js
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/app/ContentCollectorV2AppShell.js addons/content-migration/v2/admin-app/components/run-overview/OverviewRecentActivity.js addons/content-migration/v2/admin-app/hooks/useRunOverview.js addons/content-migration/v2/admin-app/workspaces/run-overview/RunOverviewWorkspace.js addons/content-migration/v2/admin-app/workspaces/run-overview/overviewTransforms.js tests/playwright/content-collector-v2.spec.js
vendor/bin/phpunit --filter ContentCollectorV2Phase12Test
npm run build
DBVC_E2E_WP_ADMIN_URL=... DBVC_E2E_WP_ADMIN_USER=... DBVC_E2E_WP_ADMIN_PASS=... npx playwright test tests/playwright/content-collector-v2.spec.js
```

## Known Local Noise

These local changes were intentionally left out of the implementation commits:

- `.phpunit.result.cache`
- `AGENTS.md`
- `admin/admin-page.php`
- `db-version-control.php`
- `dbvc-backup.log`
- `docs/ROADMAP.md`
- `docs/legacy-upload-immediate-import-plan.md`
- `docs/progress-summary.md`
- `includes/class-sync-posts.php`
- `test-results/`

## Resume Pack

Read these first when resuming:

1. `addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md`
2. `addons/content-migration/docs/MIGRATION_MAPPER_V2_DECISIONS.md`
3. `addons/content-migration/docs/MIGRATION_MAPPER_V2_ROUTE_ARTIFACT_LEDGER.md`
4. `addons/content-migration/docs/MIGRATION_MAPPER_V2_CRAWL_REUSE_AUDIT.md`
5. `addons/content-migration/docs/MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md`

Suggested resume prompt:

```text
Resume V2 from codex/content-addon-v2. Read WORKING_STATE, DECISIONS, ROUTE_ARTIFACT_LEDGER, CRAWL_REUSE_AUDIT, and IMPLEMENTATION_GUIDE only. Continue Phase 13 reviewability foundation work.
```

## Update Rule

Update this file at the end of each landed V2 tranche.

Keep it short, current, and implementation-focused.
