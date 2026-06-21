# Repomix Instruction

DB Version Control Advanced is a WordPress plugin with a PHP runtime, classic WP admin screens, and Node-built React admin surfaces. The largest active subsystem is the Content Collector addon, whose V2 runtime lives under `addons/content-migration/v2/`. Use Repomix here as a fast structural pack, then defer to the existing directive and planning docs for task-specific rules.

## Read First

- `AGENTS.md`
- `README.md`
- `addons/content-migration/README.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DECISIONS.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_ROUTE_ARTIFACT_LEDGER.md`
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md`

## Main Code Areas

- `db-version-control.php` plugin bootstrap
- `includes/` core PHP runtime and services
- `addons/` addon modules, especially `addons/content-migration/` and `addons/bricks/`
- `admin/` classic admin pages and loaders
- `src/` source for the React bundles
- `_source/` guarded legacy/reference material; inspect only when directly relevant

## Tests And Commands

- PHP: `tests/phpunit/`, `vendor/bin/phpunit`
- Browser: `tests/playwright/`, `npm run playwright:test:ccv2`
- JS build/lint: `npm run build`, `npm run lint`

## High-Signal Context Docs

- `handoff.md`
- `docs/progress-summary.md`
- `docs/ROADMAP.md`
- `docs/UI-ARCHITECTURE.md`

## Usually Low-Value Or Noisy

- `node_modules/`, `vendor/`, `tmp/`, `sync/`
- `build/`, `test-results/`, `docs/fixtures/`
- `dbvc-backup.log`, `dbvc_diff_*.png`, `repomix-starter-kit/`
