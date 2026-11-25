# DBVC Agent Instructions

Start here before changing code or docs:

1. `docs/README.md`
2. `docs/agent-entrypoints.md`
3. The task-specific docs linked from those files

Rules:

- Do not read the whole docs folder by default.
- Do not treat `docs/archives/` as current implementation guidance.
- Do not reorganize folders unless the user explicitly asks for docs-library work.
- Put proposed corrections to canonical or read-only docs in `docs/requests.md`.
- Update `docs/roadmap.md` when active, proposed, completed, or archived implementation docs change.
- Prefer updating existing docs over creating duplicate planning files.
- For module-specific work, follow the module-local instructions first, such as `addons/visual-editor/AGENTS.md`.
- The repository this one is mirrored to is public. Name a client only in `docs/private/` or another path `scripts/distribution-drift.json` lists as `development_only`; see `docs/development/distribution-drift.md`.
- If a public CLI, REST, admin, hook, setting, table, scheduled-hook, add-on, or safety contract changes, follow `docs/agents/MAINTENANCE.md` and run `composer agent-docs:check`.
