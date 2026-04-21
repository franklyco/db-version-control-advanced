# AGENTS.md

## Purpose

Guidance for AI coding agents working in the DBVC plugin.

DBVC should remain modular, readable, low-risk, and easy to expand. Follow existing patterns before introducing anything new.

When a task touches the Content Collector addon, default focus to the V2 runtime and its active phased implementation unless the user explicitly asks for V1 or shared legacy behavior.

## Start Here

Before making changes:

1. Read this file first.
2. If the task touches Content Collector, read the V2 resume pack in this order:
   - `addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md`
   - `addons/content-migration/docs/MIGRATION_MAPPER_V2_DECISIONS.md`
   - `addons/content-migration/docs/MIGRATION_MAPPER_V2_ROUTE_ARTIFACT_LEDGER.md`
   - `addons/content-migration/docs/MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md`
3. Review `README.md`, `addons/content-migration/README.md`, and any additional docs directly relevant to the task.
4. Inspect the nearest related module, class, loader, registry, UI, assets, and helper files.
5. Follow existing DBVC naming, structure, and registration patterns.

If documentation and code differ, prioritize the current implementation pattern unless the task says otherwise.

## Content Collector V2 Rules

- Prefer implementing new Content Collector runtime work under `addons/content-migration/v2/`.
- Use V1 code only when reusing shared infrastructure or building a thin bridge.
- Preserve strict runtime gating:
  - `disabled` => no Content Collector runtime surfaces
  - `v1` => legacy runtime stays active
  - `v2` => V2 runtime stays active and legacy reviewer surfaces stay dormant
- Keep Add-ons configuration server-rendered.
- Keep V2 operational surfaces modular and React-driven where already established.
- Do not broaden scope across phases without an explicit request.
- Use the active phase or task in `MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md` as the delivery boundary.

## Rules

- Keep changes scoped to the task.
- Avoid monolith files.
- Prefer small focused classes, functions, components, and views.
- Extend existing architecture instead of creating parallel systems.
- Do not refactor unrelated areas without a clear reason.
- Preserve backward compatibility unless the task allows breaking changes.

## WordPress Standards

- Sanitize input.
- Escape output.
- Check capabilities.
- Verify nonces where needed.
- Keep admin features permission-aware.

## Files and Structure

- Place new code in the most logical existing location.
- Reuse existing helpers and services when possible.
- Keep business logic, rendering, and asset loading separated where practical.
- Use clear descriptive file and class names.
- For Content Collector V2 contract or route changes, update the relevant V2 docs in the same tranche.

## Docs to Maintain for V2

- `addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md`
  - update at the end of each landed tranche
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_DECISIONS.md`
  - update only when a stable decision is actually locked
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_ROUTE_ARTIFACT_LEDGER.md`
  - update when V2 routes, identifiers, or artifact families change
- `addons/content-migration/docs/MIGRATION_MAPPER_V2_IMPLEMENTATION_GUIDE.md`
  - keep checklist and phase status aligned with implementation

## Local Noise to Leave Alone

Unless the user explicitly asks otherwise, do not stage or clean up these recurring local changes:

- `.phpunit.result.cache`
- `docs/ROADMAP.md`
- `test-results/`

## LocalWP Safety Boundary

- Treat `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges` and the `dbvc-codexchanges.local` site as the only allowed LocalWP environment for commands, browser QA, file writes, or destructive runtime mutations in this repo unless the user explicitly broadens scope.
- Do not run commands that touch other LocalWP site directories, other LocalWP databases, shared LocalWP infrastructure, or the LocalWP desktop app itself.
- For destructive browser or runtime QA, prefer disposable fixture data inside `dbvc-codexchanges.local` and keep command cwd/path scope pinned to `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges`.
- If a requested action could affect another LocalWP site or the LocalWP app, stop and ask the user before proceeding.

## UI Expectations

Keep admin UI clean and intuitive.

Prefer progressive disclosure over clutter. Use expandable panels, drawers, tables, chips, toolbars, and modals when appropriate.

## When Finished

Report back with:

1. What changed
2. Files touched
3. Validation performed
4. Any notable tradeoffs, blockers, or follow-ups
5. Next steps

## Avoid

- Large single-file implementations
- Unnecessary rewrites
- Duplicate logic
- Hidden side effects
- Placeholder code presented as complete
- Editing unrelated docs or modules without need

## Repomix

- Repomix is a complement here, not a replacement for `AGENTS.md`, `README.md`, `handoff.md`, or the Content Collector V2 resume pack.
- Repo config lives in `repomix.config.json`, `.repomixignore`, and `repomix-instruction.md`; upkeep notes live in `docs/repomix-preflight.md` and `docs/repomix-maintenance.md`.
- Refresh the Repomix files when top-level structure, directive docs, or major noise folders change so packed context stays accurate and low-noise.
