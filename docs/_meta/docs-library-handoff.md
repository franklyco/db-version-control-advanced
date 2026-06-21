# DBVC Docs Library Reorganization Handoff

## Purpose

Reorganize the DBVC plugin repository documentation into a simple, durable, AI-agent-friendly documentation library.

The goal is not to create a large documentation system. The goal is to make the existing docs easier for Codex and future AI agents to navigate, understand, and maintain without loading noisy or outdated context.

Keep the final structure simple, obvious, and easy to maintain.

## Core goals

1. Create clear entry points for agents working on DBVC.
2. Reduce scattered planning notes and duplicate docs.
3. Preserve all useful historical context without letting outdated docs guide new work.
4. Make active, proposed, completed, and archived work easy to distinguish.
5. Keep docs lightweight, accurate, and linked.
6. Avoid unnecessary folder churn.
7. Maintain a backup of every documentation file before moving, merging, editing, or archiving it.

## Flexibility directive

Use this plan as the intended direction, not as a rigid script.

Before making changes, inspect the actual repo structure, existing docs, naming conventions, and current codebase. If the repository already has a sensible convention, prefer adapting this plan to fit the repo instead of forcing a new structure.

Do not over-engineer the docs library. Prefer fewer folders, fewer files, and clearer links.

If a proposed folder or file does not make sense for the current DBVC repo, revise it or skip it.

## Mandatory backup rule

Before modifying, moving, merging, renaming, deleting, or archiving any documentation-like file, create a backup copy of its original version.

Documentation-like files include, at minimum:

- `*.md`
- `*.mdx`
- `*.txt`
- `*.rst`
- planning notes
- implementation guides
- temporary docs
- roadmap/checklist docs
- README files
- agent instruction files
- docs-related config files when relevant

Do not include `.git/`, dependency folders, build outputs, cache folders, or generated backup folders in the backup process.

### Backup location

Use a simple backup folder such as:

```text
docs/_backups/docs-library-migration/
```

If `docs/` does not exist yet, use:

```text
_docs_backup/docs-library-migration/
```

### Backup manifest

Create and maintain:

```text
docs/_backups/docs-library-migration/manifest.md
```

The manifest must record the original location of every backed-up file.

Use this format:

```md
# Docs Library Migration Backup Manifest

| Original Path | Backup Path | Action Taken | New Path | Notes |
|---|---|---|---|---|
| README.md | docs/_backups/docs-library-migration/root/README.md | kept | README.md | Root project README |
| docs/old-plan.md | docs/_backups/docs-library-migration/docs/old-plan.md | archived | docs/archives/old-plan/old-plan.md | Superseded by roadmap |
```

Rules:

- Preserve the original file contents exactly in the backup.
- Preserve the original path in the manifest.
- If a file is merged into another doc, record that in `Action Taken`.
- If a file is archived, record the archive path in `New Path`.
- If a file is deleted later, the manifest must still point to the backup.
- Do not edit backup copies after creating them.

## Recommended final structure

Use this as the target shape unless the repo already has a better convention.

```text
/
├── AGENTS.md
├── README.md
├── docs/
│   ├── README.md
│   ├── agent-entrypoints.md
│   ├── roadmap.md
│   ├── requests.md
│   ├── architecture/
│   │   └── README.md
│   ├── development/
│   │   └── README.md
│   ├── implementation/
│   │   ├── README.md
│   │   ├── active/
│   │   ├── proposed/
│   │   └── completed/
│   ├── reference/
│   │   └── README.md
│   ├── troubleshooting/
│   │   └── README.md
│   ├── archives/
│   │   └── README.md
│   └── _meta/
│       ├── README.md
│       ├── inventory.md
│       ├── review-log.md
│       └── doc-governance.md
```

Keep this structure lean. Do not add empty folders unless they are clearly useful.

## Phase 1 — Inspect the repo and create the backup

### Tasks

1. Check the current repo structure.
2. Find all documentation-like files.
3. Create the backup folder.
4. Copy every documentation-like file into the backup folder.
5. Create the backup manifest.
6. Do not reorganize anything yet.

### Expected outputs

```text
docs/_backups/docs-library-migration/
docs/_backups/docs-library-migration/manifest.md
```

If `docs/` does not exist, use the fallback backup path described above.

### Notes

The backup phase should be the first actual change. This makes the cleanup reversible and preserves memory of where everything came from.

## Phase 2 — Create the docs inventory

Create:

```text
docs/_meta/inventory.md
```

The inventory should list all current documentation-like files and the planned action for each one.

Use this simple table:

```md
# Documentation Inventory

| Current Path | Type | Status | Planned Action | Proposed Path | Notes |
|---|---|---|---|---|---|
| docs/example.md | implementation guide | active | move | docs/implementation/active/example.md | Still relevant |
```

Suggested statuses:

```text
canonical
active
proposed
completed
stale
duplicate
superseded
archive
unknown
```

Suggested actions:

```text
keep
move
merge
archive
rewrite-lightly
delete-later
needs-review
```

Do not delete anything during this phase.

## Phase 3 — Add the core library entry points

Create or update these files:

```text
AGENTS.md
docs/README.md
docs/agent-entrypoints.md
docs/roadmap.md
docs/requests.md
docs/_meta/doc-governance.md
```

### `AGENTS.md`

Keep this short. It should tell agents where to start and what rules to follow.

Suggested content:

```md
# DBVC Agent Instructions

Before making code changes, start with:

1. `docs/README.md`
2. `docs/agent-entrypoints.md`
3. Any task-specific docs linked from there

Rules:

- Do not reorganize folders unless the user explicitly asks.
- Do not treat archived docs as current implementation guidance.
- Do not edit read-only or canonical docs casually.
- Put proposed doc corrections in `docs/requests.md`.
- When completing an implementation guide, update `docs/roadmap.md`.
- Prefer updating existing docs over creating duplicate planning files.
- Keep documentation concise and linked.
```

### `docs/README.md`

This is the main docs library index.

Suggested content:

```md
# DBVC Documentation Library

This is the official documentation library for the DBVC plugin.

## Start here

- `agent-entrypoints.md` — choose the right docs for a task
- `roadmap.md` — active, proposed, completed, and archived work
- `requests.md` — requested documentation changes and unresolved doc issues

## Sections

- `architecture/` — long-lived system design
- `development/` — setup, testing, debugging, and release notes
- `implementation/` — active, proposed, and completed implementation guides
- `reference/` — lookup material such as commands, config, and glossary
- `troubleshooting/` — known issues and fixes
- `archives/` — historical or superseded docs
- `_meta/` — docs maintenance files

## Agent guidance

Start with `agent-entrypoints.md`. Do not read the entire docs folder by default.
```

### `docs/agent-entrypoints.md`

This should route agents by task type.

Suggested content:

```md
# DBVC Agent Entry Points

Use this file to choose the smallest useful documentation path for a task.

## Understand DBVC at a high level

Read:

1. `docs/architecture/README.md`
2. Relevant architecture docs linked from that file

## Change core plugin behavior

Read:

1. `docs/architecture/README.md`
2. Relevant active implementation guide under `docs/implementation/active/`
3. `docs/development/README.md`

## Work on an active implementation task

Read:

1. `docs/roadmap.md`
2. The relevant file under `docs/implementation/active/`

## Propose a future enhancement

Read:

1. `docs/roadmap.md`
2. Existing related docs under `docs/implementation/proposed/`

## Debug a problem

Read:

1. `docs/troubleshooting/README.md`
2. `docs/development/README.md`
3. Any related architecture or reference docs

## Update documentation

Read:

1. `docs/_meta/doc-governance.md`
2. `docs/_meta/inventory.md`
3. `docs/requests.md`

## If docs conflict

Do not guess which one is correct.

1. Prefer canonical docs over archived docs.
2. Check the current code if needed.
3. Add unresolved issues to `docs/requests.md`.
```

## Phase 4 — Sort docs into simple categories

Use the inventory to move docs into the simplest useful location.

### Recommended destinations

```text
docs/architecture/
```

Use for stable explanations of how DBVC works.

```text
docs/development/
```

Use for setup, testing, debugging, build, release, and contributor workflow docs.

```text
docs/implementation/active/
```

Use for currently active implementation plans.

```text
docs/implementation/proposed/
```

Use for possible future work, ideas, or unapproved plans.

```text
docs/implementation/completed/
```

Use for completed implementation guides that still help explain current behavior.

```text
docs/reference/
```

Use for lookup material such as commands, config, hooks, glossary, file conventions, and API notes.

```text
docs/troubleshooting/
```

Use for known problems and fix paths.

```text
docs/archives/
```

Use for historical, stale, superseded, or completed docs that should not guide current work.

```text
docs/_meta/
```

Use only for docs-library maintenance files.

### Rules

- Prefer moving over rewriting.
- Prefer archiving over deleting.
- Prefer one canonical doc over several overlapping docs.
- Avoid creating deeply nested folders.
- If unsure whether a doc is still true, mark it as `needs-review` and add an item to `docs/requests.md`.

## Phase 5 — Merge obvious duplicates

After files are sorted, merge only the obvious duplicates.

Examples:

- Multiple roadmaps should become `docs/roadmap.md`.
- Multiple setup guides should become one development setup doc or section.
- Multiple temporary TODO docs should become roadmap items or archive entries.
- Multiple agent notes should be consolidated into `AGENTS.md`, `docs/agent-entrypoints.md`, or `docs/_meta/doc-governance.md`.

Do not aggressively rewrite everything. Preserve useful context.

When merging:

1. Backup originals first.
2. Move unique useful content into the canonical doc.
3. Archive or mark the old docs as superseded.
4. Update the manifest.
5. Update the inventory.

## Phase 6 — Archive stale or completed docs

Move outdated, superseded, or historical docs to:

```text
docs/archives/<topic-name>/
```

Each archive folder should include a short `README.md`.

Example:

```md
# Archive: Old DBVC Planning

These files are historical and should not guide current implementation work.

Current replacement docs:

- `docs/roadmap.md`
- `docs/architecture/README.md`

Notes:

- Preserved for reference.
- Use current docs and code as the source of truth.
```

Rules:

- Do not delete archived docs in this pass.
- Make it obvious that archived docs are historical.
- Link to replacement docs when possible.

## Phase 7 — Add folder README files

Each major folder should have a small `README.md`.

Keep each README simple:

```md
# Architecture

This folder contains long-lived explanations of how DBVC works.

## Start here

- `system-overview.md` — high-level architecture
- `data-model.md` — main entities and state
- `execution-flow.md` — runtime flow

## Rules

- Keep this folder focused on current behavior.
- Put temporary plans in `docs/implementation/`.
- Put historical material in `docs/archives/`.
```

Only link files that actually exist.

## Phase 8 — Update roadmap and requests

### `docs/roadmap.md`

Use this as the single planning index.

Suggested structure:

```md
# DBVC Roadmap

## Active work

| Topic | Status | Guide | Notes |
|---|---|---|---|

## Proposed work

| Topic | Status | Proposal | Notes |
|---|---|---|---|

## Completed work

| Topic | Summary | Related Docs |
|---|---|---|

## Archived or superseded work

| Topic | Archive Path | Replacement Doc | Notes |
|---|---|---|---|
```

### `docs/requests.md`

Use this for unresolved documentation questions or recommended edits.

Suggested structure:

```md
# DBVC Documentation Requests

## Open requests

| File | Issue | Suggested Fix | Priority | Notes |
|---|---|---|---|---|

## Completed requests

| File | Resolution | Date | Notes |
|---|---|---|---|
```

## Phase 9 — Final validation pass

Before finishing, verify:

- Every original documentation-like file has a backup.
- Every backed-up file appears in the manifest.
- Every moved, merged, or archived doc appears in the inventory.
- `docs/README.md` links to the correct entry points.
- `docs/agent-entrypoints.md` routes agents by task.
- `docs/roadmap.md` reflects active/proposed/completed/archived work.
- `docs/requests.md` contains unresolved doc issues.
- Archive folders clearly say they are historical.
- No important docs are orphaned.
- No folder README links to missing files.
- The final structure is simple enough for future agents to follow.

## Preferred working style

Use small, reviewable changes.

Recommended commit order:

1. Backup and manifest.
2. Inventory.
3. Core entry-point files.
4. Folder structure and README routers.
5. File moves.
6. Duplicate merges.
7. Archive pass.
8. Roadmap and requests cleanup.
9. Final link validation.

## Do not do

- Do not delete docs during the first cleanup unless explicitly instructed.
- Do not create a complicated taxonomy.
- Do not create empty folders just because they are in the suggested structure.
- Do not rewrite large docs unless necessary.
- Do not bury active implementation work in archives.
- Do not let archived docs appear to be current.
- Do not scatter new planning notes across the repo.
- Do not create multiple competing roadmaps.
- Do not modify backup copies after creating them.

## Definition of done

This task is complete when:

- The docs library has clear entry points.
- The docs are sorted into simple, logical categories.
- Scattered planning docs are consolidated into `docs/roadmap.md`.
- Unresolved documentation issues are tracked in `docs/requests.md`.
- Historical docs are archived and clearly labeled.
- Every original documentation-like file is backed up.
- The backup manifest records every original path.
- Future agents can quickly find the right docs without reading the entire repo.
