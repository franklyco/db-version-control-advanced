Please read and follow the root `AGENTS.md` first.

Task:
Normalize this repo to our standard documentation and repo-management baseline.

Objectives:
1. Create baseline repo files and docs folders if they do not already exist.
2. Preserve existing good docs/content where possible instead of overwriting blindly.
3. Keep `AGENTS.md` lean and evergreen by removing one-time "new repo starting" instructions after the scaffold work is complete.
4. Maintain a modular, dependency-aware structure.

Standards:
- Canonical default branch is `main`.
- For non-trivial work, use short-lived task branches merged back into `main` promptly.
- Preferred branch names:
  - `task/<short-slug>`
  - `fix/<short-slug>`
  - `docs/<short-slug>`
  - `refactor/<short-slug>`
  - `codex/<short-slug>` when helpful
- Avoid long-lived branches unless explicitly required.
- Do not rerun full smoke tests after every minor step.
- Use risk-based validation:
  - local sanity first
  - targeted checks for touched areas
  - broader smoke only at logical checkpoints
  - full smoke for shared, structural, build, export, or merge-ready changes
- Do not create extra long-lived branches unless explicitly instructed.
- Avoid monolith files.
- Do not move or rename files/folders unless dependency-aware and necessary.
- If meaningful paths change, update `docs/path-migration-map.md`.
- Archived docs must be marked reference-only and non-canonical.

Required files to create if missing:
- `README.md`
- `ARCHITECTURE.md`
- `CONTRIBUTING.md`
- `CHANGELOG.md`
- `SECURITY.md`
- `.gitignore`
- `.editorconfig`
- `.gitattributes`
- `docs/README.md`
- `docs/path-migration-map.md`

Required folders to create if missing:
- `docs/archives/`
- `docs/enhancements/`
- `docs/knowledge/`

Recommended folders to create if missing:
- `docs/runbooks/`
- `docs/standards/`
- `docs/qa/`
- `docs/handoffs/`

`.gitignore` minimum baseline:
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

Execution rules:
1. Inspect what already exists before creating anything.
2. Reuse and refine existing docs instead of duplicating them.
3. If a file exists, improve it only where helpful and preserve project-specific substance.
4. If a file does not exist, create a lean but useful version.
5. Do not create `.github/ISSUE_TEMPLATE/`, `PULL_REQUEST_TEMPLATE.md`, or `CODEOWNERS` unless explicitly requested.
6. After scaffolding is complete, remove or condense one-time repo-startup instructions from the active `AGENTS.md` so it remains a durable operating guide.

Deliverables:
- scaffolded missing files/folders
- updated `AGENTS.md` with startup-only instructions removed or condensed
- short summary of what was created, what was preserved, and any recommended follow-up docs

Report back with:
- files created
- files updated
- any docs preserved instead of replaced
- any notable conflicts or ambiguity

When done, include a completion report with:
- **Scope completed**
- **Validation level**
- **Checks run**
- **Checks not run**
- **Why this validation level was appropriate**
- **Residual risk / recommended follow-up**

Done means: objective completed, structure preserved or improved, docs updated if needed, validation matched to risk, and residual risk clearly reported.