# Documentation Governance

Keep the docs library small and navigable.

## Rules

- Start from `docs/README.md` and `docs/agent-entrypoints.md`.
- Prefer updating an existing doc over creating a new plan.
- Keep active implementation plans under `docs/implementation/active/` or module-local docs.
- Move completed plans to `docs/implementation/completed/` when they still explain current behavior.
- Move stale or superseded docs to a topic folder under `docs/archives/`.
- Do not use archived docs as current implementation guidance.
- Add unresolved corrections or status questions to `docs/requests.md`.
- Update `docs/roadmap.md` whenever implementation docs change status.
- Keep folder README files current and only link files that exist.

## Canonical Paths

- Repo docs entry point: `docs/README.md`
- Agent routing: `docs/agent-entrypoints.md`
- Planning index: `docs/roadmap.md`
- Open doc questions: `docs/requests.md`
- Inventory: `docs/_meta/inventory.md`
- Backup manifest: `docs/_backups/docs-library-migration/manifest.md`
