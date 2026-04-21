# Migration Mapper V2 Working State

## Purpose

This file is the low-token resume anchor for active V2 implementation work.

Use it when resuming implementation after a pause instead of rereading the full planning set.

## Current Anchor

- Branch: `codex/content-addon-v2`
- Active phase: `Phase 24`
- Active task: `P24-T1`
- Current seam: Phase 23 is now closed. Historical exception and review browser flows are now green through the existing V2 routes, including save and rerun follow-up after a deterministic same-URL overwrite chain. The next open slice is browser validation for historical overview, readiness, and package surfaces.
- Latest landed focus: `P23-T1`, `P23-T2`, and `P23-T3` are now landed. Historical review browser validation now uses a dev-only synthetic source-run fixture on the existing `POST /runs` transport plus an upgraded deterministic replay fixture that clones page artifacts into a true same-URL overwrite chain, and the targeted unsandboxed Playwright smoke is green.

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
- `Phase 13`: `CLOSED`
- `Phase 14`: `CLOSED`
- `Phase 15`: `CLOSED`
- `Phase 16`: `CLOSED`
- `Phase 17`: `CLOSED`
- `Phase 18`: `CLOSED`
- `Phase 19`: `CLOSED`
- `Phase 20`: `CLOSED`
- `Phase 21`: `CLOSED`
- `Phase 22`: `CLOSED`
- `Phase 23`: `CLOSED`
- `Phase 24`: `OPEN`

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

All currently defined guide phases are closed through `Phase 23`.

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
- the package bridge now requires explicit confirmation before preflight approval or execute and explains why actions are disabled when guardrails or fresh session approvals are still missing
- selected packages now expose signed manifest, summary, QA, records, media, and ZIP artifact actions, with in-app drill-ins for the JSON artifacts instead of raw storage-relative paths
- the Phase 9 audit confirms that shared crawl helpers, sitemap parsing, artifact storage, extraction primitives, schema services, and import guardrails already align with V2
- the LocalWP Playwright smoke now drives the V2 workspace by direct route, validates drawer toggle behavior, and verifies that run creation enters a visible lifecycle state
- the LocalWP Playwright smoke remains green after the inspector decision refactor and continues to validate shell load, drawer behavior, run creation, and overview refresh without depending on a seeded recommendation row
- the selected-run `overview` workspace now shows real summary metrics, stage monitoring, explicit refresh state, and deterministic next-action links without adding a new backend route
- the selected-run `overview` workspace now exposes bounded recent activity for the current run from the existing journey log without introducing a dedicated event endpoint
- the inspector now warns before unsaved local edits are dropped through drawer close, tab change, workspace change, run change, or record navigation
- stale recommendation drift is now surfaced directly in the inspector action surface, with a deterministic reset-to-latest-recommendations path that preserves the current artifact contracts
- ACF target presentation in the inspector now surfaces the field label, the actual ACF field name, and the raw machine ref together so operators do not have to decode raw `acf:group:field` strings by eye
- resolved duplicate-target conflicts now collapse correctly after saved approve or reject decisions, so the inspector payload and exception queue stop reporting stale conflict groups that were already resolved by the reviewer
- `Save and close` now commits the current draft as the saved baseline before running the close transition, so the unsaved-changes guard does not incorrectly reopen on a successful save
- the exceptions workspace now exposes dedicated queue-state chips for conflicts, unresolved items, stale decisions, manual overrides, blocked items, and ready-after-review rows
- queue rows now surface a queue-state label and direct quick action so conflict and unresolved items open straight into the mapping resolver instead of a generic summary tab
- the inspector now exposes a dedicated conflicts tab with conflict-target context, resolution reasoning, review reasons, confidence framing, and editable conflicting decisions
- the shell now preserves exception-queue context so previous, next, save-and-next, and save-and-close actions can move across the current filtered queue without dropping route state
- live LocalWP browser QA now confirms `Resolve conflicts` opens the dedicated conflict tab and that `Next`, `Previous`, and `Save and next` preserve the filtered queue while advancing to the expected flagged URL
- the exceptions workspace now exposes low-risk bulk review controls, visible-row selection, family-scoped selection helpers, and audited bulk approve or defer actions through the existing per-page decision route
- the runs workspace now consumes additive run-profile, action-summary, and hidden-state metadata from `GET /runs` without introducing a second run list endpoint
- run cards now expose duplicate-settings prefill into the existing run-start form, stage-group rerun helpers that iterate through the existing per-URL rerun route, and hide or restore cleanup controls backed by a user-scoped visibility route
- replay success in the existing lifecycle panel now exposes direct follow-up actions for the created run and its source run without introducing a second replay surface
- rerun outcomes in the existing run-action status panel now expose direct follow-up actions into the affected run overview and exception workflow
- historical source-run routes now resolve against run-specific materialized journey state instead of only the per-domain latest snapshot, so replay follow-up can reopen older runs after same-domain replays
- the readiness workspace now exposes direct blocker actions plus `review`, `qa`, `package`, and `ready` filter chips without adding a second readiness REST route
- readiness blocking issues and warnings now route directly into the filtered exceptions queue, the inspector audit tab, or the package workspace from the same page-report payload
- the overview workspace now turns next-action cards into direct route-aware shortcuts for the first blocked or reviewable URL, the matching readiness audit target, and the latest built package
- the package workspace now exposes direct blocker shortcuts from both the package action cards and the execute-blocked notice without dropping the selected `packageId` from route state
- the runs workspace now preserves the latest rerun recovery follow-up context inside the existing run-action status panel, even when later non-recovery actions update the same run session
- duplicate-settings prefill now clears transient run-action messages without discarding the latest rerun recovery shortcuts from the current runs workspace session
- the Playwright smoke now asserts replay follow-up buttons in the lifecycle panel, verifies that `Open source run` reaches the source overview without a not-found state, and includes a skip-guarded rerun recovery-context test that checks the preserved follow-up shortcuts after duplicate-settings prefill
- headed LocalWP browser QA now confirms a runs-workspace rerun helper can complete, expose its `Open overview` follow-up action, and route back into the affected source run overview
- headed LocalWP browser QA now confirms replay can create `ccv2_dbvc-codexchanges-local_20260325T182037Z_49db4b` and that the lifecycle panel's `Open source run` follow-up still routes back to `ccv2_dbvc-codexchanges-local_20260320T071726Z_921d8b`
- the LocalWP PHPUnit environment now runs against the site's MySQL socket through the repo's WordPress test bootstrap, so the Phase 16 and Phase 18 filter can execute locally again
- the V2 REST surface now exposes a dev-only, current-user-scoped recovery fixture helper that can seed deterministic rerun candidates for a chosen run without editing journey artifacts
- the V2 create-run route now also accepts a dev-only deterministic replay source flag so replay follow-up validation can stay on the existing `POST /runs` transport without waiting for LocalWP sitemap refetch to succeed
- the rerun recovery smoke now seeds its own fixture data, clears that overlay after the test, and passes in unsandboxed CLI Playwright against LocalWP
- the replay smoke now injects its deterministic replay source through the existing replay UI path, reads the created run ID from the lifecycle success alert, and passes in unsandboxed CLI Playwright against LocalWP
- historical review browser validation now uses a dev-only synthetic single-page fixture domain on the normal `POST /runs` transport, so the source-run side of the overwrite chain no longer depends on incidental LocalWP data
- the deterministic replay helper now clones page artifacts into a real same-URL overwrite chain, so the historical review browser smoke can exercise older-run save and rerun behavior without waiting for a live sitemap recrawl
- the targeted unsandboxed Playwright smoke `preserves historical exception review actions after a same-url overwrite run` is now green, and it proves the older run still opens, saves, and reruns without drifting into the newer same-URL run
- the runs status panel now shows the preserved recovery follow-up block after duplicate-settings prefill even when there is no newer hide or restore completion message
- historical overview inventory now resolves through a run-scoped inventory reader, so reopening an older run after a later same-domain run no longer shows the newer run's domain-latest discovery inventory
- readiness now resolves eligible pages and page contexts from the requested run, so reopening an older run after a later same-domain run no longer swaps in the newer run's discovery rows
- the package workspace now defaults and filters package history by run, so reopening an older run no longer auto-selects the newest package built for the domain
- run-aware package artifact loading now refuses page-level artifacts whose `journey_id` belongs to a newer run, so historical readiness and package reads stop showing newer-run page artifacts when direct historical files are unavailable

What is still open:

- the LocalWP site's custom `/login` page still renders a front-end login form even for an authenticated admin, so browser QA should keep using direct V2 admin routes instead of depending on the admin-menu path
- the next open tranche slice is now `P24-T1`, focused on browser validation for historical overview, readiness, and package fidelity when the same URL is processed again by a later same-domain run
- sandboxed CLI Playwright still dies on this machine when Chromium launches under the Codex shell sandbox, so browser runs still need unsandboxed execution
- the remaining browser-validation gap is historical overview, readiness, and package route fidelity after same-URL overwrite chains, not historical review save or rerun behavior

## Last Validation Baseline

These were the last known green validation anchors for the active implementation branch:

```bash
vendor/bin/phpunit --filter "ContentCollectorV2Phase(19|20)Test"
vendor/bin/phpunit --filter "ContentCollectorV2Phase(19|22)Test"
vendor/bin/phpunit --filter ContentCollectorV2Phase21Test
vendor/bin/phpunit --filter ContentCollectorV2Phase22Test
vendor/bin/phpunit --filter "ContentCollectorV2Phase(20|21)Test"
vendor/bin/phpunit --filter "ContentCollectorV2Phase(20|21|22)Test"
git diff --check -- addons/content-migration/v2/discovery/dbvc-cc-v2-url-inventory-service.php addons/content-migration/v2/journey/dbvc-cc-v2-domain-journey-rest-controller.php addons/content-migration/v2/shared/dbvc-cc-v2-page-artifact-service.php addons/content-migration/v2/package/dbvc-cc-v2-package-selection-service.php addons/content-migration/v2/package/dbvc-cc-v2-package-qa-service.php addons/content-migration/v2/package/dbvc-cc-v2-url-qa-report-service.php addons/content-migration/v2/package/dbvc-cc-v2-package-build-service.php tests/phpunit/ContentCollectorV2Phase20Test.php addons/content-migration/docs/MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md
vendor/bin/phpunit --filter "ContentCollectorV2Phase(16|18)Test"
vendor/bin/phpunit --filter ContentCollectorV2Phase19Test
vendor/bin/phpunit --filter ContentCollectorV2Phase15Test
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/components/runs/RunActionStatusPanel.js addons/content-migration/v2/admin-app/components/runs/RunCreateLifecyclePanel.js tests/playwright/content-collector-v2.spec.js
npm run build
npm run playwright:test:ccv2 -- --grep 'supports replay, duplicate-settings prefill, and hide or restore on run cards'
npm run playwright:test:ccv2 -- --grep 'keeps rerun recovery follow-up context after duplicate-settings prefill'
npm run playwright:test:ccv2 -- --grep 'preserves historical exception review actions after a same-url overwrite run'
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/app/ContentCollectorV2AppShell.js addons/content-migration/v2/admin-app/components/package/PackageActionConfirmDialog.js addons/content-migration/v2/admin-app/components/package/PackageArtifactActionsPanel.js addons/content-migration/v2/admin-app/components/package/PackageArtifactInspectorPanel.js addons/content-migration/v2/admin-app/components/package/PackageDetailPanel.js addons/content-migration/v2/admin-app/components/package/PackageImportPanel.js addons/content-migration/v2/admin-app/workspaces/package/PackageWorkspace.js tests/playwright/content-collector-v2.spec.js
npm run build
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/components/drawers/InspectorDrawer.js addons/content-migration/v2/admin-app/components/inspectors/InspectorActionPanel.js addons/content-migration/v2/admin-app/components/inspectors/InspectorMappingTab.js addons/content-migration/v2/admin-app/components/inspectors/RecommendationDecisionCard.js addons/content-migration/v2/admin-app/hooks/useInspectorDecisionDraft.js tests/playwright/content-collector-v2.spec.js
npm run build
DBVC_E2E_WP_ADMIN_URL=... DBVC_E2E_WP_ADMIN_USER=... DBVC_E2E_WP_ADMIN_PASS=... npx playwright test tests/playwright/content-collector-v2.spec.js
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/components/inspectors/targetPresentation.js addons/content-migration/v2/admin-app/components/inspectors/InspectorMappingTab.js addons/content-migration/v2/admin-app/components/inspectors/InspectorActionPanel.js
vendor/bin/phpunit --filter ContentCollectorV2Phase13Test
npm run build
vendor/bin/phpunit --filter ContentCollectorV2Phase14Test
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/components/exceptions/ExceptionsToolbar.js addons/content-migration/v2/admin-app/components/exceptions/ExceptionsTable.js
git diff --check
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/app/ContentCollectorV2AppShell.js addons/content-migration/v2/admin-app/components/drawers/InspectorDrawer.js addons/content-migration/v2/admin-app/components/inspectors/InspectorActionPanel.js addons/content-migration/v2/admin-app/components/inspectors/InspectorConflictsTab.js addons/content-migration/v2/admin-app/workspaces/exceptions/ExceptionsWorkspace.js
vendor/bin/phpunit --filter ContentCollectorV2Phase14Test
npm run build
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/app/ContentCollectorV2AppShell.js addons/content-migration/v2/admin-app/components/run-overview/OverviewNextActions.js addons/content-migration/v2/admin-app/components/run-overview/OverviewStageCards.js addons/content-migration/v2/admin-app/components/run-overview/OverviewSummaryCards.js addons/content-migration/v2/admin-app/hooks/useRunOverview.js addons/content-migration/v2/admin-app/workspaces/run-overview/RunOverviewWorkspace.js addons/content-migration/v2/admin-app/workspaces/run-overview/overviewTransforms.js tests/playwright/content-collector-v2.spec.js
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/app/ContentCollectorV2AppShell.js addons/content-migration/v2/admin-app/components/run-overview/OverviewRecentActivity.js addons/content-migration/v2/admin-app/hooks/useRunOverview.js addons/content-migration/v2/admin-app/workspaces/run-overview/RunOverviewWorkspace.js addons/content-migration/v2/admin-app/workspaces/run-overview/overviewTransforms.js tests/playwright/content-collector-v2.spec.js
vendor/bin/phpunit --filter ContentCollectorV2Phase12Test
DBVC_E2E_WP_ADMIN_URL=... DBVC_E2E_WP_ADMIN_USER=... DBVC_E2E_WP_ADMIN_PASS=... npx playwright test tests/playwright/content-collector-v2.spec.js
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/app/ContentCollectorV2AppShell.js addons/content-migration/v2/admin-app/components/drawers/InspectorDrawer.js addons/content-migration/v2/admin-app/components/drawers/InspectorUnsavedChangesDialog.js addons/content-migration/v2/admin-app/components/inspectors/InspectorActionPanel.js addons/content-migration/v2/admin-app/hooks/useInspectorDecisionDraft.js tests/playwright/content-collector-v2.spec.js
npm run build
DBVC_E2E_WP_ADMIN_URL=... DBVC_E2E_WP_ADMIN_USER=... DBVC_E2E_WP_ADMIN_PASS=... npx playwright test tests/playwright/content-collector-v2.spec.js
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/app/ContentCollectorV2AppShell.js addons/content-migration/v2/admin-app/components/readiness/ReadinessToolbar.js addons/content-migration/v2/admin-app/components/readiness/ReadinessIssuesList.js addons/content-migration/v2/admin-app/components/readiness/ReadinessPagesTable.js addons/content-migration/v2/admin-app/components/readiness/readinessActions.js addons/content-migration/v2/admin-app/workspaces/readiness/ReadinessWorkspace.js
npm run build
git diff --check
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/app/ContentCollectorV2AppShell.js addons/content-migration/v2/admin-app/app/operatorActionRoutes.js addons/content-migration/v2/admin-app/components/package/PackageImportPanel.js addons/content-migration/v2/admin-app/components/package/PackageNextActionsPanel.js addons/content-migration/v2/admin-app/components/package/packageNextActions.js addons/content-migration/v2/admin-app/components/run-overview/OverviewNextActions.js addons/content-migration/v2/admin-app/workspaces/package/PackageWorkspace.js addons/content-migration/v2/admin-app/workspaces/readiness/ReadinessWorkspace.js addons/content-migration/v2/admin-app/workspaces/run-overview/RunOverviewWorkspace.js addons/content-migration/v2/admin-app/workspaces/run-overview/overviewTransforms.js tests/playwright/content-collector-v2.spec.js
npm run build
git diff --check
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/app/ContentCollectorV2AppShell.js addons/content-migration/v2/admin-app/components/exceptions/BulkReviewPanel.js addons/content-migration/v2/admin-app/components/exceptions/ExceptionsTable.js addons/content-migration/v2/admin-app/components/exceptions/bulkReviewHelpers.js addons/content-migration/v2/admin-app/hooks/useBulkReviewActions.js addons/content-migration/v2/admin-app/workspaces/exceptions/ExceptionsWorkspace.js tests/playwright/content-collector-v2.spec.js
npm run build
git diff --check
DBVC_E2E_WP_ADMIN_URL=... DBVC_E2E_WP_ADMIN_USER=... DBVC_E2E_WP_ADMIN_PASS=... npx playwright test tests/playwright/content-collector-v2.spec.js
vendor/bin/phpunit --filter ContentCollectorV2Phase16Test
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/workspaces/runs/RunsWorkspace.js addons/content-migration/v2/admin-app/components/runs/RunCreateForm.js addons/content-migration/v2/admin-app/components/runs/RunCard.js addons/content-migration/v2/admin-app/components/runs/RunActionStatusPanel.js addons/content-migration/v2/admin-app/hooks/useRunActions.js addons/content-migration/v2/admin-app/hooks/useRunList.js addons/content-migration/v2/admin-app/workspaces/runs/runCreateFields.js tests/playwright/content-collector-v2.spec.js
npm run build
git diff --check
DBVC_E2E_WP_ADMIN_URL=... DBVC_E2E_WP_ADMIN_USER=... DBVC_E2E_WP_ADMIN_PASS=... npx playwright test tests/playwright/content-collector-v2.spec.js
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/components/inspectors/targetPresentation.js addons/content-migration/v2/admin-app/components/inspectors/RecommendationDecisionCard.js addons/content-migration/v2/admin-app/components/inspectors/InspectorConflictsTab.js
npm run build
git diff --check
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
Resume V2 from codex/content-addon-v2. Read WORKING_STATE, DECISIONS, ROUTE_ARTIFACT_LEDGER, CRAWL_REUSE_AUDIT, and IMPLEMENTATION_GUIDE only. Continue Phase 24 from P24-T1 and keep the next tranche limited to browser validation for historical overview, readiness, and package fidelity after same-URL rerun or replay chains.
```

## Update Rule

Update this file at the end of each landed V2 tranche.

Keep it short, current, and implementation-focused.
