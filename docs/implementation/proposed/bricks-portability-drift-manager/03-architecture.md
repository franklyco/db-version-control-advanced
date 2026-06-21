# 03 · Architecture

## High-level flow

```text
Source Site (Site A)
  -> collect selected Bricks domains
  -> normalize + package
  -> create zip

Target Site (Site B)
  -> upload zip
  -> validate package
  -> read local Bricks domains
  -> normalize local data
  -> compare source vs local
  -> build drift report
  -> user reviews decisions
  -> create backup snapshot
  -> apply selected changes
  -> record job + result
  -> allow rollback
```

## Recommended module layout

Inside the DBVC Bricks add-on, create a focused module set instead of one giant file.

```text
addons/bricks/
  portability/
    class-dbvc-bricks-portability.php
    class-dbvc-bricks-registry.php
    class-dbvc-bricks-exporter.php
    class-dbvc-bricks-package.php
    class-dbvc-bricks-importer.php
    class-dbvc-bricks-normalizer.php
    class-dbvc-bricks-diff-engine.php
    class-dbvc-bricks-matcher.php
    class-dbvc-bricks-apply-engine.php
    class-dbvc-bricks-backup-manager.php
    class-dbvc-bricks-rollback-manager.php
    class-dbvc-bricks-jobs-repository.php
    class-dbvc-bricks-rest-controller.php
    class-dbvc-bricks-admin-page.php
    assets/
      portability.js
      portability.css
```

## Core service responsibilities

### Registry
Defines:
- what domains exist
- which option names belong to each domain
- which are portable
- risk level
- normalizer to use
- matcher to use
- apply strategy to use

### Exporter
Responsible for:
- reading selected domains from `wp_options`
- normalizing canonical payloads
- assembling manifest
- writing package JSON
- zipping files

### Package reader
Responsible for:
- unzip / parse
- schema validation
- checksum validation
- package version compatibility checks

### Normalizer
Responsible for:
- removing volatile keys
- sorting arrays/objects for stable comparison
- canonicalizing values
- optionally extracting item-level records

### Matcher
Responsible for object identity matching.
Examples:
- class by `name`, then `id`
- variable by name/token
- palette by name or slug
- whole-object compare for singleton settings

### Diff engine
Responsible for:
- source-only
- target-only
- same match / no drift
- same match / value drift
- same name / different id
- same id / different name
- dependency warnings
- safety classification

### Apply engine
Responsible for:
- turning approved decisions into option mutations
- merge, replace, add, skip behavior
- reindexing and cleanup
- preserving untouched objects
- writing updated options atomically per domain

### Backup manager
Responsible for:
- storing pre-apply snapshots of affected option values
- writing backup metadata
- enabling restore

### Jobs repository
Responsible for:
- import session record
- apply session record
- backup record
- rollback record
- status transitions
- audit trail

## Recommended admin surface

Add a new screen under the DBVC Bricks add-on, for example:

- `DBVC > Bricks > Portability`

Tabs:
- Export
- Import / Review
- Backups
- History
- Settings

## Data path recommendation

Prefer REST-driven admin UI instead of legacy admin-post form chaining.

Reason:
- drift review is interactive
- needs filtering, bulk actions, preview requests
- large object payloads benefit from progressive fetch and pagination
- future React/Vue/vanilla table workbench becomes easier

## State model

At minimum, model these states:

### Import session
- uploaded
- validated
- normalized
- compared
- awaiting decisions
- approved
- applying
- complete
- failed
- archived

### Backup
- created
- available
- restored
- expired
- failed

## Design principle

Keep the engine independent from the UI.

The UI should ask the engine for:

- package summary
- domain summaries
- drift rows
- row detail
- apply preview
- apply execution
- rollback execution

That keeps Codex from coupling business logic to a specific admin table.
