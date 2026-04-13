# Repomix Preflight

## Directive Inventory

- `AGENTS.md` — current repo-wide agent rules; authoritative
- `AGENT.md` — current admin-app workflow guide; authoritative for that slice
- `README.md` — current repo overview and primary commands; authoritative
- No repo-local `CODEX.md`, `CLAUDE.md`, `.cursor/rules/*`, `.github/copilot-instructions.md`, or `CONTRIBUTING.md`

## Existing Context Systems

- Content Collector V2 resume pack in `addons/content-migration/docs/` — current and authoritative for active addon work
- `handoff.md` and `docs/progress-summary.md` — useful working context, but not more authoritative than code plus the directive layer
- `docs/ROADMAP.md` and `docs/UI-ARCHITECTURE.md` — backlog and architectural context, useful but secondary
- `repomix-starter-kit/` — bootstrap reference only; not project source

## Chosen Role

`Complement`

Repomix should pack the live repo structure and point future agents back to the existing authoritative docs. It should not replace the directive layer, handoff docs, or the V2 planning set.

## Prioritize First

- `AGENTS.md`
- `README.md`
- `addons/content-migration/README.md`
- the V2 resume pack when touching Content Collector
- `package.json`, `composer.json`, `db-version-control.php`

## Install Strategy

Use zero-friction CLI execution with `npx --yes repomix@latest`. The repo already has Node tooling, but the working tree is dirty and this bootstrap does not justify dependency or lockfile churn.
