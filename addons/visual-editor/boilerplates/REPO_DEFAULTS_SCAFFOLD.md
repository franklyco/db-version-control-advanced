# Repo Defaults Scaffold
**Updated:** 2026-03-02

Use this as the normalized baseline for small-team repos.

## Goal
Create a lean, consistent repo structure that supports accurate builds, lower drift, clear onboarding, and modular growth.

## Required root files
- `AGENTS.md`
- `README.md`
- `ARCHITECTURE.md`
- `CONTRIBUTING.md`
- `CHANGELOG.md`
- `SECURITY.md`
- `.gitignore`
- `.editorconfig`
- `.gitattributes`

## Required docs structure
- `docs/README.md`
- `docs/path-migration-map.md`
- `docs/archives/`
- `docs/enhancements/`
- `docs/knowledge/`

## Recommended docs structure
- `docs/runbooks/`
- `docs/standards/`
- `docs/qa/`
- `docs/handoffs/`

## Behavioral rules
- `AGENTS.md` is canonical.
- Archived docs are reference-only and must say so.
- Do not keep duplicate active instruction files.
- Do not move paths casually.
- Avoid monolith files.
- Keep docs updated alongside structural changes.

## Branching Standard

- The canonical default branch is `main`.
- Do not use `main` as the primary work branch for non-trivial implementation.
- For meaningful work, create short-lived task branches and merge them back into `main` promptly.
- Preferred branch naming:
  - `task/<short-slug>`
  - `fix/<short-slug>`
  - `docs/<short-slug>`
  - `refactor/<short-slug>`
  - `codex/<short-slug>` for Codex-led work when useful
- Avoid long-lived branches unless explicitly required by the project.
- Delete or close completed task branches after merge unless there is a clear reason to keep them.

## Validation Standard

- Do not rerun the full smoke test suite after every small completed step.
- Use risk-based validation:
  - local/file sanity first
  - targeted checks for touched areas
  - broader smoke checks only at logical checkpoints
  - full smoke/end-to-end checks for merge-ready, shared, structural, or high-risk work
- Prefer validating one coherent batch of related changes rather than retesting after each micro-edit.

## `.gitignore` baseline
```gitignore
# OS
.DS_Store
**/.DS_Store

# Local temp / working artifacts
temp/
tmp/

# Editor / IDE
.vscode/
.idea/
*.swp
*.swo

# Env / secrets
.env
.env.*
```

## Suggested file purposes
### `README.md`
Entry point, local setup, quickstart, commands, main concepts.

### `ARCHITECTURE.md`
System shape, boundaries, modules, major flows, conventions.

### `CONTRIBUTING.md`
How to work in the repo, branching assumptions, review expectations, file/folder rules.

### `CHANGELOG.md`
Human-readable notable changes with an `Unreleased` section.

### `SECURITY.md`
How to report vulnerabilities and how secrets/envs are handled.

### `docs/README.md`
Docs index and where different doc types live.

### `docs/path-migration-map.md`
Canonical record of meaningful moves/renames.
