# Repomix Instruction

DB Version Control Advanced is a WordPress plugin with a PHP runtime, classic WP admin screens, and Node-built React admin surfaces. Use Repomix here as a fast structural pack, then defer to the documentation library and module-local docs for task-specific rules.

## Read First

- `AGENTS.md`
- `README.md`
- `docs/README.md`
- `docs/agent-entrypoints.md`
- `docs/roadmap.md`

## Main Code Areas

- `db-version-control.php` plugin bootstrap
- `includes/` core PHP runtime and services
- `addons/` addon modules, with module-local docs for active work
- `admin/` classic admin pages and loaders
- `src/` source for the React bundles
- `_source/` guarded legacy/reference material; inspect only when directly relevant

## Tests And Commands

- PHP: `tests/phpunit/`, `vendor/bin/phpunit`
- Browser: `tests/playwright/`, `npm run playwright:test:ccv2`
- JS build/lint: `npm run build`, `npm run lint`

## High-Signal Context Docs

- `docs/architecture/dbvc-engine-inventory.md`
- `docs/implementation/completed/progress-summary.md`
- `docs/roadmap.md`
- `docs/architecture/admin-app-ui-architecture.md`
- `docs/reference/import-identity-matching.md`

## Usually Low-Value Or Noisy

- `node_modules/`, `vendor/`, `tmp/`, `sync/`
- `build/`, `test-results/`, `docs/_backups/`
- `dbvc-backup.log`, `dbvc_diff_*.png`, `repomix-starter-kit/`
