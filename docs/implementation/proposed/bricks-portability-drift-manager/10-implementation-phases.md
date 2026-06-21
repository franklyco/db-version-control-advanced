# 10 · Implementation Phases

## Phase 0 — Discovery inside actual DBVC add-on
Before coding, Codex should inspect:

- current DBVC Bricks add-on architecture
- existing admin screens
- existing DBVC storage helpers
- REST/API conventions
- DBVC logging helpers
- DBVC backup/history patterns if present
- any existing “golden master / drift” concepts already in the codebase

## Phase 1 — Core portability MVP
Build the smallest production-credible version.

### Included
- domain registry
- export selected domains to zip
- upload and validate zip
- compare source vs target
- workbench table with statuses
- per-row decisions
- domain bulk decisions
- create backup
- apply add/replace/keep/skip
- rollback from backup
- history screen

### Supported domains
- Bricks Settings
- Color Palettes
- Global Classes
- Global CSS Variables
- Pseudo Classes
- Theme Styles
- Components
- Breakpoints *(only if canonical structure is verified safely)*

### Deliberately excluded
- destructive sync-down
- remote package pulls
- media asset remapping
- icon file packaging
- fuzzy AI matching

## Phase 2 — Advanced portability
### Add
- Font Faces
- Icon Sets
- Custom Icons
- dependency graph viewer
- smarter subkey compare for global settings
- better conflict resolution hints

## Phase 3 — Governance features
### Add
- saved presets by domain
- “approved package baseline” storage
- site-to-site drift dashboard
- scheduled baseline check
- one-click safe sync presets
- team notes / labels / approval workflow

## Recommended implementation order

1. Registry and normalizers
2. Export package writer
3. Import validator + package reader
4. Diff engine + compare payload
5. Admin review table
6. Decisions persistence
7. Backup manager
8. Apply engine
9. Rollback manager
10. History/backups UI
11. Advanced domains

## Engineering rules for Codex

### Rule 1
Do not hardcode logic all over the UI.  
Centralize domain behavior in a registry.

### Rule 2
Normalize before comparing.  
Raw option diffs will be too noisy.

### Rule 3
Apply by rebuilding a domain payload in memory, then writing once.

### Rule 4
No silent overwrite behavior.

### Rule 5
Treat name-vs-id mismatches as first-class cases, not edge cases.

## Good first milestone

A testable milestone should be:

- export/import/compare/apply for **Color Palettes** and **Global Classes** only

That proves the model before expanding to other domains.
