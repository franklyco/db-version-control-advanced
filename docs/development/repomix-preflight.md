# Repomix Preflight

## Directive Inventory

- `AGENTS.md` — current repo-wide agent rules; authoritative
- `AGENT.md` — current admin-app workflow guide; authoritative for that slice
- `README.md` — current repo overview and primary commands; authoritative
- No repo-local `CODEX.md`, `CLAUDE.md`, `.cursor/rules/*`, `.github/copilot-instructions.md`, or `CONTRIBUTING.md`

## Existing Context Systems

- Docs library entry points in `docs/README.md` and `docs/agent-entrypoints.md` — current repo-level routing
- Module-local docs under `addons/*/docs/` — current and authoritative for module-specific work
- `docs/roadmap.md` and `docs/architecture/admin-app-ui-architecture.md` — backlog and architectural context, useful but secondary
- `repomix-starter-kit/` — bootstrap reference only; not project source

## Chosen Role

`Complement`

Repomix should pack the live repo structure and point future agents back to the existing authoritative docs. It should not replace the directive layer, docs-library entry points, or module-local planning sets.

## Prioritize First

- `AGENTS.md`
- `README.md`
- `docs/README.md`
- `docs/agent-entrypoints.md`
- module-local docs for the touched addon
- `package.json`, `composer.json`, `db-version-control.php`

## Install Strategy

Use zero-friction CLI execution with `npx --yes repomix@latest`. The repo already has Node tooling, but the working tree is dirty and this bootstrap does not justify dependency or lockfile churn.
