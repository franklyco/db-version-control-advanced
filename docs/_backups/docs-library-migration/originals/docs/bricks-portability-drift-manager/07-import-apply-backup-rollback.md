# 07 · Import, Apply, Backup, Rollback

## Import pipeline

### Step 1 — Upload
- user drags zip into import area
- system stores temp upload
- system records import session

### Step 2 — Validate
- unzip
- verify required files
- validate manifest schema
- validate checksums
- validate package version
- validate domain payload structure

### Step 3 — Normalize + compare
- load source domains
- load local target domains
- normalize both
- run matching
- compute drift rows
- store compare result for session

### Step 4 — Review
- user assigns or accepts decisions
- system validates that all actionable rows have decisions

### Step 5 — Backup
Before any write:
- capture current values of all affected option names
- save snapshot to durable storage
- record backup metadata

### Step 6 — Apply
- group decisions by domain
- build new canonical option payloads
- write option values
- log results
- verify post-write state

### Step 7 — Complete
- store final outcome
- show summary
- allow rollback

## Recommended apply strategy

Apply per domain, not per row directly into `wp_options`.

Example for classes:
1. read current `bricks_global_classes`
2. build mutated in-memory object set based on decisions
3. validate resulting structure
4. write full updated option once
5. write related categories option if affected

That is safer than many tiny partial writes.

## Backup scope

Backup only the option names that will actually be mutated.

Example:
If import decisions only touch:
- `bricks_color_palette`
- `bricks_global_variables`

then snapshot only those options, not every Bricks option.

## Backup format recommendation

### Stored metadata
- backup id
- linked job id
- created at
- source package id
- target site info
- option names included
- checksum
- actor user id

### Stored payload
A JSON file or zip with exact pre-apply values:

```json
{
  "backup_id": "bk_20260422_001",
  "options": {
    "bricks_color_palette": { "...": "..." },
    "bricks_global_variables": { "...": "..." }
  }
}
```

## Rollback model

Rollback should restore the **pre-apply option snapshot** for the affected options only.

### Rollback flow
1. user chooses a prior completed job
2. system loads its backup
3. system verifies backup integrity
4. system restores backed-up option values
5. system logs rollback job
6. system shows success/failure summary

## Important safety rules

### 1) No destructive delete-by-default
Target-only objects should not be removed unless a future “sync-down” mode is explicitly enabled.

### 2) No write if validation failed
If any selected domain cannot be rebuilt cleanly, abort apply for the whole job unless a scoped domain-only apply strategy is explicitly supported.

### 3) Prefer transactional thinking
WordPress options are not true DB transactions here, but the engine should emulate safe execution:
- preflight
- backup
- write
- verify
- record

### 4) Verification after write
After applying, immediately re-read the affected options and confirm expected fingerprints.

## Failure handling

If apply fails mid-run:

- show failure state
- do not silently continue
- offer immediate restore from just-created backup
- log precise domain + option failure details

## Conservative MVP write policy

Support these operations only:

- add object
- replace matched object
- replace singleton domain
- keep current
- skip

Do not start with:
- destructive sync
- delete missing objects
- multi-package merge
- live partial JSON patching inside options without full rebuild validation
