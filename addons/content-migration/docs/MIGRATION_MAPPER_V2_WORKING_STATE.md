# Migration Mapper V2 Working State

## Purpose

This file is the low-token resume anchor for active V2 implementation work.

Use it when resuming implementation after a pause instead of rereading the full planning set.

## Current Anchor

- Branch: `codex/content-addon-v2`
- Active phase: `Phase 32`
- Active task: `FC-07`
- Current seam: Vertical Field Context mapping accuracy remains the active V2 product seam. The provider baseline, target-catalog enrichment, candidate trace propagation, compact reviewer visibility pass, chain-aware target slot graph, nested ACF ancestry preservation, deterministic eligibility filters, benchmark-driven section semantics, structured heading or body or CTA source-unit splitting, utility-navigation suppression, page-aware deterministic selection, unresolved bias, transform-side `value_contract` enforcement, package/readiness blocking for definite contract violations, slot-graph provider-drift blocking, benchmark rollups, benchmark-backed release thresholds, truthful preservation of provider `missing` status, runtime ACF purpose fallback for provider-empty catalogs, semantic slot-role inference for `wysiwyg` hero fields, same-runtime provider criteria correction, post-object exclusion of option-page and taxonomy slots, raw sort-score candidate ordering, unresolved-frontier preservation in recommendation payloads, ACF `link` slot-role correction, section-body scoring against nested hero card or popup branches, page-description separation from hero section claims, structural competition groups for non-repeatable sibling slots, typed unresolved classes that survive review and QA, the new persisted `routing-artifact.v1` evidence layer, route-intent normalization from slug-safe page paths, route-intent expansion into slot-pattern hints, and slot-graph classification for process and pricing sections are landed. The next open slice is measured benchmark coverage across additional real Vertical pages plus residual conversion-page ambiguity reduction, followed by final package-preview policy hardening tracked in `MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_PLAN.md`. Do not touch any other LocalWP site, database, directory, shared LocalWP infrastructure, the LocalWP app, or the Vertical theme repo itself.
- Latest landed focus: `P27-T1`, `P27-T2`, `P27-T3`, `P28-T1`, `P28-T2`, `P28-T3`, `P29-T1`, `P29-T2`, `P29-T3`, `P30-T1`, `P30-T2`, `P30-T3`, `P31-T1`, `P31-T2`, and `P31-T3` remain landed from the historical-fidelity stream. For the new field-context stream, the landed baseline is: provider bootstrap, normalized provider metadata and indexes, target-catalog field-context embedding, candidate field-context trace, reviewer visibility surfaces, `_schema/dbvc_cc_target_slot_graph.v1.json`, nested ACF chain projection, bounded candidate pools, object-scope filtering, section-family filtering, clone-projection write filtering in the mapping index, benchmark-calibrated section source-unit extraction, utility-navigation section skipping, deterministic recommendation assignment, unresolved bias in transform and review, transform-level `value_contract_validation`, package QA blocking for invalid URL or reference-shape writes plus slot-graph provider-drift blocking, benchmark-summary rollups in readiness/package QA, and benchmark-gate thresholds driven by quality score plus reviewed ambiguity plus manual override plus rerun counts.

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
- `Phase 24`: `CLOSED`
- `Phase 25`: `CLOSED`
- `Phase 26`: `CLOSED`
- `Phase 27`: `CLOSED`
- `Phase 28`: `CLOSED`
- `Phase 29`: `CLOSED`
- `Phase 30`: `CLOSED`
- `Phase 31`: `CLOSED`
- `Phase 32`: `OPEN`

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

All currently defined guide phases are closed through `Phase 31`.

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
- the targeted unsandboxed Playwright smoke `preserves historical overview, readiness, and package routes after a same-url overwrite run` is now green, and it proves overview shortcuts, readiness flows, package routing, and selected package state stay pinned to the older source run after a newer same-URL overwrite run exists
- the targeted unsandboxed Playwright smoke `preserves historical package dry-run and guardrail shortcuts after a same-url overwrite run` is now green, and it proves historical dry-run preview plus the execute-blocked resolve-shortcut stay pinned to the older source run and selected package after a newer same-URL overwrite run exists
- the targeted unsandboxed Playwright smoke `preserves historical package preflight approval and persisted summary after a same-url overwrite run` is now green, and it proves historical preflight requests plus the persisted preflight summary stay pinned to the older source run and selected package after a newer same-URL overwrite run exists
- the runs status panel now shows the preserved recovery follow-up block after duplicate-settings prefill even when there is no newer hide or restore completion message
- historical overview inventory now resolves through a run-scoped inventory reader, so reopening an older run after a later same-domain run no longer shows the newer run's domain-latest discovery inventory
- readiness now resolves eligible pages and page contexts from the requested run, so reopening an older run after a later same-domain run no longer swaps in the newer run's discovery rows
- the package workspace now defaults and filters package history by run, so reopening an older run no longer auto-selects the newest package built for the domain
- run-aware package artifact loading now refuses page-level artifacts whose `journey_id` belongs to a newer run, so historical readiness and package reads stop showing newer-run page artifacts when direct historical files are unavailable
- package QA now filters recommendation conflicts through saved decision state, so resolved conflict groups do not keep historical package preflight or execute blocked just because the raw recommendation artifact still lists the original conflict set
- the targeted unsandboxed Playwright smoke `preserves historical package execute mutation after a same-url overwrite run` is now green, and it proves a real historical `POST /runs/{run_id}/execute` mutation stays pinned to the approved older `runId` and `packageId` on disposable data inside `dbvc-codexchanges.local`

What is still open:

- the LocalWP site's custom `/login` page still renders a front-end login form even for an authenticated admin, so browser QA should keep using direct V2 admin routes instead of depending on the admin-menu path
- the next open tranche slice is now measured benchmark coverage against `ccv2_flourishweb-co_20260421T223255Z_0cc20e` and follow-on package-preview hardening now that benchmark-backed release thresholds are landed
- the landed implementation targets in the redesign are `FC-01` context chain and slot graph, `FC-03` deterministic eligibility and candidate-pool rebuild, `FC-04` deterministic selection and unresolved bias, and the active `FC-06` slices for degraded-provider QA warnings plus transform-side contract blocking
- `_schema/dbvc_cc_target_slot_graph.v1.json` now persists chain-aware slot projections and indexes by `target_ref`, `key_path`, `name_path`, group plus ACF key, object type, section family, and slot role
- the mapping index now consumes the slot graph to exclude service-only groups from page runs, exclude blocked clone projections from direct-write candidates, and prefer structured section-family slots over broad core fallbacks when those slots exist
- benchmark tuning against `ccv2_flourishweb-co_20260421T223255Z_0cc20e` now drives section semantics so utility or navigation fragments like Home `/` skip links do not enter hero matching, while structured sections split into headline, body, CTA label, and CTA URL source units before Field Context candidate scoring
- recommendation finalization and target transforms now run through a shared deterministic assignment service instead of blindly trusting candidate `0`, and ambiguous section items now default to `unresolved`
- URL QA now carries additive Field Context readiness signals for missing or degraded provider state and for recommendations that were manually kept despite ambiguous deterministic selection evidence
- target transforms now load the same slot graph used during mapping so ACF slots carry additive `value_contract` and `value_contract_validation` metadata into review and package QA
- target transforms now block definite contract violations such as text landing in URL or reference-only fields, while package QA raises `field_value_contract_blocked` and `field_value_contract_warnings` inside the existing readiness surface instead of building a parallel QA screen
- URL QA now compares recommendation-carried provider metadata against the current slot graph and blocks readiness with `field_context_provider_drift` when `source_hash`, `schema_version`, `contract_version`, or `site_fingerprint` no longer match
- provider-empty Vertical catalogs now stay truthfully `missing` in DBVC artifacts instead of collapsing to `available`, while the enriched target catalog and slot graph can still backfill `vf_field_context` purpose text from the current ACF runtime arrays
- slot graph projections now infer `headline`/`subheadline`/`cta_label` from semantic field names before generic `wysiwyg` or `textarea` fallback, so real Vertical fields like `hero_h1` do not drop into `rich_text` role buckets just because the stored field type is `wysiwyg`
- same-runtime Vertical catalog lookups now use empty provider criteria for site-wide target schema builds, which restores a fresh provider-backed catalog on `dbvc-codexchanges.local` instead of incorrectly returning `missing` through `domain`-scoped ACF queries
- page and CPT mappings now exclude option-page and taxonomy-only slots at the eligibility layer, so Home `/` no longer leaks into `site_settings_group` banner fields during hero matching
- candidate ordering now keeps an internal raw sort score even when the visible confidence rounds to `0.99`, and unresolved recommendations preserve the raw frontier target instead of surfacing a coherence-shifted fallback
- ACF `link` fields now classify as `link` or `cta_url` slots, section-body scoring now penalizes nested hero card or popup branches for main hero body units, and page-description metadata scoring now avoids claiming `hero` section slots before real hero section content is evaluated
- run readiness and package QA now expose a compact benchmark rollup for every page report, including unresolved, ambiguous, transform-blocked, and provider-drift counts; the current Flourish benchmark run shows Home `/` as the dominant high-risk page with 61 unresolved items and 32 ambiguous recommendations
- benchmark release gating is now enforced inside existing page QA, readiness, and package surfaces: pages block when quality score falls to `69` or below, reviewed ambiguous selections reach `3` or more, manual overrides reach `5` or more, or reruns reach `3` or more; they remain benchmark-review until quality score recovers above `84` and reviewed ambiguity, manual overrides, and reruns each return to `0`
- real LocalWP acceptance reruns against `ccv2_flourishweb-co_20260421T223255Z_0cc20e` now show Home `/` with a fresh provider-backed catalog end to end; `rec_003 -> hero_h1` and `rec_004 -> hero_description` both land as deterministic `approve` recommendations, and the review payload now hydrates compact Field Context evidence from the slot graph even when the raw recommendation artifact omits it
- routing is now persisted as additive page-level artifact evidence through `*.routing-artifact.v1.json`, and mapping consumes that route summary opportunistically instead of inferring object and page intent ad hoc from classification alone
- live benchmark reruns now prove the next accuracy seam is narrower than before: `/our-process` now normalizes to `page_intent = process`, `/pricing` stays `pricing`, and both pages now reach real ACF targets instead of collapsing almost entirely into `core:post_title` / `core:post_content`; `/get-started` still remains mostly generic, so conversion-page section routing and slot discoverability are the next benchmark-driven focus
- structural competition groups and typed unresolved classes are now part of the live slot-graph and review/QA path; the next accuracy slice should prioritize labeled benchmark truth fixtures before adding heavier semantic retrieval or embeddings
- use the provider payload as runtime semantic truth and use Vertical `acf-json/field-groups/*.json` only for topology, coverage, and benchmark reference
- keep `key_path` opaque, keep `value_contract` authoritative for shape, and keep `clone_context` authoritative for clone restrictions
- sandboxed CLI Playwright still dies on this machine when Chromium launches under the Codex shell sandbox, and the same bare Chromium launch now reproduces that split directly: sandboxed launch exits with `SIGTRAP`, while the identical headless launch succeeds unsandboxed
- treat that split as a Codex shell sandbox browser-launch boundary, not as a V2 spec-level regression; use unsandboxed repo Playwright scripts when browser launch is required
- `dbvc-codexchanges.local` still keeps real execute guardrail-blocked for this historical fixture flow, so the approved destructive execute recorded zero import runs and left rollback cleanup as a no-op
- do not disable guardrails or broaden scope to another LocalWP site, database, directory, shared LocalWP infrastructure, or the LocalWP desktop app merely to fabricate rollback coverage
- the next open work should come from a real product/runtime seam or release-readiness need, not from more rollback-specific QA on the current LocalWP target

## Last Validation Baseline

These were the last known green validation anchors for the active implementation branch:

```bash
vendor/bin/phpunit --filter ContentCollectorV2Phase32Test
git diff --check
vendor/bin/phpunit --filter "ContentCollectorV2Phase(19|20)Test"
vendor/bin/phpunit --filter "ContentCollectorV2Phase(19|22)Test"
vendor/bin/phpunit --filter ContentCollectorV2Phase21Test
vendor/bin/phpunit --filter ContentCollectorV2Phase22Test
vendor/bin/phpunit --filter "ContentCollectorV2Phase(20|21)Test"
vendor/bin/phpunit --filter "ContentCollectorV2Phase(20|21|22)Test"
vendor/bin/phpunit --filter ContentCollectorV2Phase29Test
vendor/bin/phpunit --filter "ContentCollectorV2Phase(27|29)Test"
./node_modules/.bin/wp-scripts lint-js tests/playwright/content-collector-v2.spec.js
npm run playwright:test:ccv2 -- --grep 'preserves historical package execute mutation after a same-url overwrite run'
git diff --check -- addons/content-migration/v2/discovery/dbvc-cc-v2-url-inventory-service.php addons/content-migration/v2/journey/dbvc-cc-v2-domain-journey-rest-controller.php addons/content-migration/v2/shared/dbvc-cc-v2-page-artifact-service.php addons/content-migration/v2/package/dbvc-cc-v2-package-selection-service.php addons/content-migration/v2/package/dbvc-cc-v2-package-qa-service.php addons/content-migration/v2/package/dbvc-cc-v2-url-qa-report-service.php addons/content-migration/v2/package/dbvc-cc-v2-package-build-service.php tests/phpunit/ContentCollectorV2Phase20Test.php addons/content-migration/docs/MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md
vendor/bin/phpunit --filter "ContentCollectorV2Phase(16|18)Test"
vendor/bin/phpunit --filter ContentCollectorV2Phase19Test
vendor/bin/phpunit --filter ContentCollectorV2Phase15Test
./node_modules/.bin/wp-scripts lint-js addons/content-migration/v2/admin-app/components/runs/RunActionStatusPanel.js addons/content-migration/v2/admin-app/components/runs/RunCreateLifecyclePanel.js tests/playwright/content-collector-v2.spec.js
npm run build
npm run playwright:test:ccv2 -- --grep 'supports replay, duplicate-settings prefill, and hide or restore on run cards'
npm run playwright:test:ccv2 -- --grep 'keeps rerun recovery follow-up context after duplicate-settings prefill'
npm run playwright:test:ccv2 -- --grep 'preserves historical exception review actions after a same-url overwrite run'
npm run playwright:test:ccv2 -- --grep 'preserves historical overview, readiness, and package routes after a same-url overwrite run'
npm run playwright:test:ccv2 -- --grep 'preserves historical package dry-run and guardrail shortcuts after a same-url overwrite run'
npm run playwright:test:ccv2 -- --grep 'preserves historical package preflight approval and persisted summary after a same-url overwrite run'
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
6. `addons/content-migration/docs/MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_PLAN.md`
7. `addons/content-migration/docs/MIGRATION_MAPPER_V2_VERTICAL_FIELD_CONTEXT_IMPLEMENTATION_GUIDE.md`

Suggested resume prompt:

```text
Resume V2 from codex/content-addon-v2. Read WORKING_STATE, DECISIONS, ROUTE_ARTIFACT_LEDGER, CRAWL_REUSE_AUDIT, IMPLEMENTATION_GUIDE, VERTICAL_FIELD_CONTEXT_PLAN, and VERTICAL_FIELD_CONTEXT_IMPLEMENTATION_GUIDE. Continue the Vertical Field Context mapping-accuracy seam from FC-04 and keep the next tranche limited to deterministic recommendation selection, unresolved bias, and reviewer-visible ambiguity framing inside the existing V2 runtime.
Resume V2 from codex/content-addon-v2. Read WORKING_STATE, DECISIONS, ROUTE_ARTIFACT_LEDGER, CRAWL_REUSE_AUDIT, IMPLEMENTATION_GUIDE, VERTICAL_FIELD_CONTEXT_PLAN, and VERTICAL_FIELD_CONTEXT_IMPLEMENTATION_GUIDE. Continue the Vertical Field Context mapping-accuracy seam from FC-07 and keep the next tranche limited to measured benchmark coverage across additional Vertical pages plus residual non-home ambiguity reduction now that provider freshness, option-page exclusion, raw-score ordering, and the Home hero acceptance rerun are landed, then finish package-preview policy hardening inside the existing V2 runtime.
```

## Update Rule

Update this file at the end of each landed V2 tranche.

Keep it short, current, and implementation-focused.
