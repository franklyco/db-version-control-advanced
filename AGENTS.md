# AGENTS.md

## Mission

You are working inside the DBVC plugin.

Current objective:
Build the new **Bricks Portability & Drift Manager** feature inside the DBVC **Bricks add-on**.

This feature must let users:

- export selected Bricks Builder settings domains from Site A
- package them into a portable zip
- import that package into Site B
- compare incoming data against current Site B Bricks settings
- surface drift in a fast bulk-friendly review UI
- approve actions per domain/object
- apply approved changes safely
- create backups before apply
- rollback if needed

Work in **Spartan Mode**:
minimal waste, minimal drift, minimal assumptions, maximum clarity.

---

## Prime Directive

Do not improvise architecture until you inspect the real DBVC codebase.

Follow this order:

1. Read this file first.
2. Read the Bricks portability docs in `docs/bricks-portability-drift-manager/`.
3. Read root `README.md` and any DBVC Bricks add-on docs.
4. Inspect the actual DBVC Bricks add-on structure before coding.
5. Reuse existing DBVC patterns for loaders, modules, admin pages, assets, logging, storage, history, REST/ajax, registries, and services.

If docs and code differ, prefer the real code pattern unless the task explicitly says otherwise.

---

## Current Focus

Only work on the Bricks feature unless the task explicitly broadens scope.

Target feature name:

**Bricks Portability & Drift Manager**

Primary supported domains for MVP:

- Bricks Settings
- Bricks Color Palettes
- Bricks Global Classes
- Bricks Global CSS Variables
- Bricks Pseudo Classes
- Bricks Theme Styles
- Bricks Components
- Bricks Breakpoints Settings

Known related option names to inspect and classify:

- `bricks_global_settings`
- `bricks_remote_templates`
- `bricks_color_palette`
- `bricks_breakpoints_last_generated`
- `bricks_global_classes`
- `bricks_global_pseudo_classes`
- `bricks_panel_width`
- `bricks_global_classes_changes`
- `bricks_global_classes_locked`
- `bricks_theme_styles`
- `bricks_global_elements`
- `bricks_pinned_elements`
- `bricks_global_classes_categories`
- `bricks_global_variables_categories`
- `bricks_global_variables`
- `bricks_global_classes_timestamp`
- `bricks_global_classes_user`
- `bricks_font_face_rules`
- `bricks_global_classes_trash`
- `bricks_components`
- `bricks_icon_sets`
- `bricks_custom_icons`

Do not assume all of these are canonical import/export domains.
Classify them first.

---

## Mandatory Product Shape

This is **not** a blind import/export tool.

It is a governed transfer system with:

- export package builder
- import package reader
- normalization layer
- drift detection
- review workbench
- apply engine
- backup snapshot
- rollback/revert history

The review UX must be **table/workbench based**.

Do not build a card-per-object decision UI.

---

## Operating Rules

- Keep scope tight.
- No monolith files.
- No parallel architecture if DBVC already has a pattern.
- Prefer small focused classes.
- Prefer registries over hardcoded conditionals.
- Prefer pure data transforms over tangled mutation logic.
- Prefer one write per affected option set where practical.
- No destructive delete-sync behavior in MVP unless explicitly requested.
- No placeholder code presented as finished.
- No unrelated refactors.

---

## Build Order

Before writing implementation code, produce a concise checklist covering:

1. best addon folder location
2. existing Bricks add-on architecture to reuse
3. admin screen placement
4. existing service/module conventions
5. existing logging/history/backup patterns
6. existing REST/ajax patterns
7. recommended file/class breakdown
8. naming conflicts or architectural risks
9. whether custom DB tables are needed for MVP
10. which Bricks option names are truly canonical

Then implement in phases:

1. discovery + checklist
2. domain registry + classification
3. export package builder
4. import reader + validator
5. normalization + diff engine
6. review workbench UI
7. apply engine
8. backup + rollback
9. validation + notes

---

## Non-Negotiable Technical Rules

### 1. Normalize before diffing
Never compare raw option blobs if normalized structures can be compared instead.

### 2. Match objects intentionally
Matching must be domain-aware.

Support cases like:

- new object
- changed values
- changed properties
- same object name, different ID
- same ID, changed payload
- missing on target
- missing in package
- category/relationship drift

### 3. Snapshot before apply
Always create a backup of affected option names before any mutation.

### 4. Apply only approved changes
Do not apply unreviewed drift decisions.

### 5. Rebuild in memory first
Assemble final payloads in memory, then write to options.

### 6. Fail visibly
Return clear success, warning, and failure messages.

---

## WordPress Standards

- Sanitize input.
- Escape output.
- Enforce capabilities.
- Verify nonces.
- Keep admin actions permission-aware.
- Validate uploaded packages before reading/applying.
- Treat zip contents as untrusted input.

---

## Data / Package Expectations

Portable package should include:

- manifest
- plugin/tool version
- source site metadata
- selected domains
- normalized export payloads
- raw backup payloads only if intentionally needed
- checksums where useful

Do not tie the package format too tightly to one brittle internal storage shape if a normalized contract can be used.

---

## UI Expectations

Keep the admin UI sharp and fast.

Prefer:

- toolbar
- domain filters
- summary counts
- review tables
- expandable detail rows
- bulk action controls
- per-row overrides
- confirmation modal
- post-apply result state
- rollback access

Avoid clutter.
Prefer progressive disclosure.

---

## LocalWP Safety Boundary

Treat `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges` and `dbvc-codexchanges.local` as the only allowed LocalWP environment unless the user explicitly expands scope.

Do not touch:

- other LocalWP sites
- other LocalWP databases
- shared LocalWP infrastructure
- LocalWP app state

Use disposable fixture data for destructive QA.

---

## Files and Structure

Place code in the most logical existing DBVC Bricks add-on location.

Separate concerns:

- registry
- normalizers
- diff logic
- package builder/reader
- admin UI
- apply handlers
- backup/rollback
- storage/history
- assets

Use clear names.
Keep files small.
Keep responsibilities narrow.

---

## Validation Standard

Before reporting completion, validate:

- export package generation
- package upload + parse
- drift detection by domain
- bulk action behavior
- per-row override behavior
- apply path
- backup creation
- rollback path
- permission checks
- nonce checks
- invalid package handling
- partial failure behavior

Do not claim tested unless actually tested.

---

## When Finished

Report back with:

1. what changed
2. files touched
3. validation performed
4. tradeoffs / blockers / assumptions
5. next steps

---

## Avoid

- giant controller classes
- hidden side effects
- direct raw option mutation scattered everywhere
- duplicate normalization logic
- brittle one-off UI state handling
- silent failure
- unnecessary framework churn
- expanding into unrelated DBVC modules

---

## Spartan Reminder

Be sharp.
Be modular.
Be reversible.
Be honest.

Inspect first.
Classify first.
Normalize first.
Backup first.
Then apply.