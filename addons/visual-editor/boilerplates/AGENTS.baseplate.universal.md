# AGENTS.md - Universal Repo Baseplate
**Updated:** 2026-03-02  
**Mode:** Modular, dependency-aware, documentation-first, low-drift execution

This file defines the operating rules for working in this repository.

If project-specific constraints exist, they should either:
- live below in a clearly labeled project-specific section, or
- live in a scoped doc under `docs/standards/` or `docs/knowledge/`

This file is the canonical repo-level operating guide unless a direct user instruction overrides it.

---

## 1) Mission

Maintain a repository that is:
- modular
- accurate
- easy to understand
- safe to change
- well documented
- low drift over time

Outputs should be production-minded, organized, and traceable.

---

## 2) Core Operating Principles

### Read before editing
Before making changes:
- read this `AGENTS.md`
- read the root `README.md`
- inspect `ARCHITECTURE.md`, `CONTRIBUTING.md`, and relevant docs in `/docs/`
- inspect neighboring modules/files before changing patterns

### Modular by default
Favor small, focused, reusable files over large all-in-one files.

### No monolith drift
Do not create new monolith files or continue growing oversized files when logic can be extracted safely.

### Dependency-aware changes only
Do not move or rename files/folders unless imports, references, paths, docs, build tooling, and downstream dependencies are understood.

### Canonical docs win
Avoid duplicate instruction sources. Archived or legacy docs are reference-only and must not compete with canonical files.

### Improve without destabilizing
Prefer additive, localized, reversible changes. Avoid unnecessary churn.

---

## 3) Repo Rules

### 3.1 File and folder movement
Do not move, rename, or reorganize existing files or directories unless:
- the dependency surface is understood
- references are updated in the same change set
- relevant docs are updated
- the move materially improves maintainability or correctness

If meaningful paths change, update `docs/path-migration-map.md`.

### 3.2 New feature structure
New features, addons, enhancements, or collection-type systems should be modularized.

Preferred default pattern:

- `feature-name/`
  - `README.md` or `notes.md`
  - `data/`
  - `partials/`
  - `templates/`
  - `utils/`
  - `tests/` if applicable
  - `dist/` or `exports/` if applicable

Use the existing repo's conventions if they are already stronger and consistent.

### 3.3 Naming
Use names that are:
- specific
- stable
- readable
- purpose-revealing

Avoid vague names like:
- `new-file.md`
- `misc-notes.md`
- `final-v2.md`
- `temp-helper.js`

### 3.4 One file, one role
Keep concerns separated where practical:
- setup docs
- architecture docs
- standards
- enhancement plans
- knowledge/domain context
- archived material
- generated outputs
- scripts/utilities

---

## 4) Branch Workflow Standard

### Default branch
- `main` is the canonical default branch.
- Non-trivial work should be performed on short-lived task branches and merged back into `main` promptly.
- `main` should remain stable, readable, and close to deployable.

### Short-lived task branches
- Do not perform non-trivial implementation work directly on `main`.
- For any meaningful task, feature, fix, cleanup, refactor, or documentation change set, create a short-lived task branch and merge back into `main` when complete.
- Preferred branch naming:
  - `task/<short-slug>`
  - `fix/<short-slug>`
  - `docs/<short-slug>`
  - `refactor/<short-slug>`
- If work is explicitly Codex-led, `codex/<short-slug>` is also acceptable.
- Keep branches short-lived and narrowly scoped.
- Avoid long-running parallel branches unless explicitly required.

### Branch scope rules
- One branch should correspond to one primary objective or tightly related change set.
- Avoid mixing unrelated work into the same branch.
- If a task grows beyond its original scope, split follow-up work into a separate branch.

### Merge standard
- Merge completed short-lived branches back into `main` promptly.
- Preserve `main` as the source of truth.
- Do not allow stale branches to accumulate.
- After merge, close or delete the completed task branch unless there is a clear reason to retain it.

### Direct-to-main exceptions
Direct commits to `main` are acceptable only for very small, low-risk changes such as:
- typo fixes
- comment-only edits
- tiny documentation wording changes
- trivial non-structural metadata edits

If there is any doubt, use a short-lived task branch.

---

## 5) Validation and Smoke Test Discipline

### Goal
Validate intelligently without wasting time on repetitive full smoke tests after every minor completed run.

### Core rule
- Do not rerun the full smoke test suite after every small edit or micro-step.
- Batch related changes into a logical checkpoint, then validate at the appropriate level.

### Validation ladder
Use the lightest validation that still gives confidence:

1. **File-level / local sanity**
   - syntax checks
   - obvious lint/type/build errors
   - markdown/doc structure sanity
   - path/reference sanity

2. **Targeted validation**
   - test only the feature, module, route, template, or flow affected
   - validate touched dependencies and nearby integration points only

3. **Smoke validation**
   - run a broader smoke pass when the task affects shared flows, builds, exports, routing, generation logic, or multiple modules

4. **Full validation**
   - reserve full end-to-end or full smoke coverage for:
     - merge-ready handoff
     - shared infrastructure changes
     - path or architecture changes
     - build/export pipeline changes
     - dependency upgrades
     - high-risk refactors

### Batch-change guidance
- Group related edits before running broader validation.
- Avoid “test after every file save” behavior.
- Prefer “implement a coherent slice, then validate once.”

### When full smoke is required
Run a fuller smoke pass when changes touch:
- shared build tooling
- export pipelines
- path or folder structure
- core architecture
- repo-wide config
- reusable modules used across multiple flows
- deployment-sensitive logic

### When targeted validation is enough
Targeted checks are usually sufficient for:
- localized content updates
- isolated docs changes
- narrowly scoped styling or layout updates
- single-module fixes with no shared dependency impact
- additive, low-risk enhancements

### Reporting
When work is complete, report:
- what level of validation was run
- what was intentionally not rerun
- why that validation level was appropriate
- any residual risk or recommended follow-up checks

---

## 6) Definition of Done

A task is not done until the change is complete at the correct level of quality, risk, and maintainability.

### A task is done when:
- the primary objective is completed
- the change is scoped appropriately and does not include unnecessary unrelated edits
- touched files remain organized, readable, and modular
- no monolith file was created or unnecessarily expanded
- any new files/folders follow the repo’s structure and naming conventions
- no existing file or folder was moved/renamed without dependency awareness
- any meaningful path/location changes are documented in `docs/path-migration-map.md`
- documentation is updated if setup, workflow, architecture, paths, or usage changed
- validation was run at the appropriate risk-based level
- known limitations, follow-ups, or residual risks are explicitly reported

### A task is not done if:
- the feature works but the repo was made messier
- implementation introduced avoidable structural debt
- validation was skipped without explanation
- the change requires hidden assumptions not documented anywhere
- related docs now drift from actual repo behavior
- unrelated opportunistic edits increased review risk

### Done quality standard
Prefer:
- complete and scoped
- modular and reversible
- documented where necessary
- validated at the right level
- understandable by the next operator

---

## 7) Validation Report Standard

When reporting completed work, always include a brief validation summary.

### Required report fields
- **Scope completed:** what was changed
- **Validation level:** file sanity, targeted validation, smoke validation, or full validation
- **Checks run:** exact checks, tests, builds, previews, or manual verifications performed
- **Checks not run:** what was intentionally not rerun
- **Why this level was appropriate:** brief risk-based justification
- **Residual risk:** any known uncertainty, edge cases, or recommended follow-up checks

### Example format
- **Scope completed:** Added modular theme settings panel and wired toolbar toggle.
- **Validation level:** Targeted validation.
- **Checks run:** Typecheck, local build, toolbar interaction test, settings open/close behavior, theme state persistence check.
- **Checks not run:** Full presentation smoke suite not rerun.
- **Why this level was appropriate:** Changes were localized to the presentation editor UI and did not affect export/build pipeline.
- **Residual risk:** Recommend broader smoke check before merging if additional theme-library logic is added.

---

## 8) Documentation Baseline

The repo should standardize around a `/docs/` folder with these baseline subfolders:

- `docs/archives/`
- `docs/enhancements/`
- `docs/knowledge/`

Recommended additional defaults when useful:

- `docs/runbooks/`
- `docs/standards/`
- `docs/qa/`
- `docs/handoffs/`

### Root file baseline
Create and maintain these when applicable:
- `AGENTS.md`
- `README.md`
- `ARCHITECTURE.md`
- `CONTRIBUTING.md`
- `CHANGELOG.md`
- `SECURITY.md`
- `.gitignore`
- `.editorconfig`
- `.gitattributes`

### Docs baseline
Create and maintain these when applicable:
- `docs/README.md`
- `docs/path-migration-map.md`

### Archive rule
Archived docs must clearly state at the top:
- reference-only
- non-canonical
- `AGENTS.md` or current canonical doc wins on conflict

---

## 9) Branch and Workflow Standards

### Branch naming
- The canonical default branch is `main`.
- Unless explicitly instructed otherwise, assume the repo's active branch baseline is `main`.
- Do not create alternate long-lived branches casually.

### Change style
- keep changes scoped
- keep commits logically grouped when applicable
- avoid unrelated refactors during focused work
- preserve existing behavior unless change is intended

### If initializing or normalizing a repo
It is acceptable to create missing baseline files/folders if they do not exist.
Once the repo has been normalized, remove one-time "new repo starting" guidance from active instructions so `AGENTS.md` stays lean and evergreen.

---

## 10) Accuracy and Build Discipline

### Verify assumptions
Do not assume a pattern is canonical until the surrounding repo confirms it.

### Respect existing architecture
Prefer adapting to the current repo architecture rather than forcing a new one without cause.

### Keep docs aligned with reality
If setup, structure, paths, workflows, or architecture change, update the relevant docs in the same pass.

### Avoid hidden process knowledge
If a recurring process matters, document it in a canonical file instead of burying it in chat logs, ad hoc notes, or temporary docs.

---

## 11) Git Hygiene Defaults

Default `.gitignore` baseline should include at minimum:

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

Expand only as the repo stack requires.

---

## 12) Refactor Rules

Refactor only when it improves one or more of:
- clarity
- modularity
- maintainability
- reusability
- correctness
- documentation alignment

When refactoring:
- keep scope controlled
- update references in the same pass
- avoid style-only churn
- prefer extraction over wholesale rewrites

---

## 13) Decision Order

When multiple valid options exist, prioritize in this order:
1. correctness
2. repo stability
3. modular maintainability
4. documentation clarity
5. reversibility
6. speed

---

## 14) Done Definition

A task is not done until:
- the implementation is accurate
- the repo is not messier than before
- docs are updated where needed
- file growth is controlled
- no accidental monolith drift occurred
- any path changes are documented
- the next operator can understand what changed and why

---

## 15) Optional Project-Specific Section

Project-specific standards may be added below this line in a clearly labeled section.
Keep repo-wide rules above, scoped/project rules below.
