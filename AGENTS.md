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


## vexp <!-- vexp v1.2.28 -->

**MANDATORY: use `run_pipeline` — do NOT grep or glob the codebase.**
vexp returns pre-indexed, graph-ranked context in a single call.

### Workflow
1. `run_pipeline` with your task description — ALWAYS FIRST (replaces all other tools)
2. Make targeted changes based on the context returned
3. `run_pipeline` again only if you need more context

### Available MCP tools
- `run_pipeline` — **PRIMARY TOOL**. Runs capsule + impact + memory in 1 call.
  Auto-detects intent. Includes file content. Example: `run_pipeline({ "task": "fix auth bug" })`
- `get_context_capsule` — lightweight, for simple questions only
- `get_impact_graph` — impact analysis of a specific symbol
- `search_logic_flow` — execution paths between functions
- `get_skeleton` — compact file structure
- `index_status` — indexing status
- `get_session_context` — recall observations from sessions
- `search_memory` — cross-session search
- `save_observation` — persist insights (prefer run_pipeline's observation param)

### Agentic search
- Do NOT use built-in file search, grep, or codebase indexing — always call `run_pipeline` first
- If you spawn sub-agents or background tasks, pass them the context from `run_pipeline`
  rather than letting them search the codebase independently

### Smart Features
Intent auto-detection, hybrid ranking, session memory, auto-expanding budget.

### Multi-Repo
`run_pipeline` auto-queries all indexed repos. Use `repos: ["alias"]` to scope. Run `index_status` to see aliases.
<!-- /vexp -->