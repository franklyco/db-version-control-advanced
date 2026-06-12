# Entity Editor Sync File Import Implementation Guide

Last updated: 2026-06-10

Status: `P5 AUTOMATED COVERAGE IMPLEMENTED; MANUAL QA RECOMMENDED`

## Objective

Add a focused Entity Editor enhancement that lets an authorized operator import already-staged DBVC entity JSON files from the sync folder as live WordPress entities.

The motivating workflow:

1. A user drops one or more DBVC post/CPT or term JSON files into the configured sync folder.
2. Entity Editor indexes those files.
3. The user can preview unmatched files and explicitly create the missing WordPress entities.

This should be a quick, safe operator tool. It should not become a second proposal system, a broad legacy import screen, or a replacement for the existing Entity Editor save/import flows.

## Current Shipped Slice

Implemented on 2026-06-09:

- stale entity-registry rows are ignored unless the referenced WordPress post/term still exists
- raw-intake create preview no longer reports `matched_entity` from stale registry rows
- sync-file import preview/commit REST routes support up to 25 paths per request
- P1/P2 shipped post/CPT JSON; P3 adds taxonomy term JSON under `taxonomy/{taxonomy}/`
- unmatched, eligible post/CPT and term rows show `Import as New`
- selected rows can be bulk-previewed with `Preview import selected`
- import preflight reads through Entity Editor safe path helpers and blocks live matches, creation-disabled settings, missing post types, unsupported payloads, and noncanonical duplicate rows
- commit delegates creation to `DBVC_Sync_Posts::import_post_from_json()`
- commit delegates term creation to `DBVC_Sync_Taxonomies::import_term_json_file()`
- commit suppresses normal auto-export hooks during the import call so DBVC does not generate a second canonical JSON file
- commit moves the importer-updated source JSON to the final canonical filename after the new local ID is known
- preview coverage now includes permission denial, invalid JSON, excluded paths, unsupported payloads, creation-disabled settings, whitelist blocking, stale duplicate blocking, bulk partial failures, and term creation
- successful commits return the created WP entity and rebuild the Entity Editor index in the UI

## Original Gap

Entity Editor already indexes valid post/term JSON in the sync folder and exposes:

- `Edit JSON`
- `Save JSON`
- `Save + Partial Import`
- `Save + Full Replace`
- `New From Raw JSON`

Before this enhancement, the missing path was:

- select or open an unmatched sync JSON file
- preview why DBVC believes it is safe to create
- commit creation using existing DBVC import engines

P3 now covers that path for one or more unmatched post/CPT or term JSON files.

## Scope

Include:

- post/CPT JSON already present under the sync root
- term JSON already present under `taxonomy/{taxonomy}/`
- single-file create from an unmatched file
- bulk create for selected unmatched files after single-file behavior is proven
- clear preflight results before any database write
- reuse of existing Entity Editor index, safe path checks, permission checks, logging, UID policy, and DBVC post/term importers

Exclude:

- media/attachments
- menus/nav menu items
- options/settings payloads
- arbitrary JSON transformation
- upload handling
- automatic import when a file appears in sync
- destructive replace from the table
- changing existing `Save + Partial Import`, `Save + Full Replace`, raw-intake, proposal review, or legacy import behavior

## Reuse Inventory

Use these existing pieces as the baseline.

### Entity Editor

- `DBVC_Entity_Editor_Indexer::get_index()`
  - scans sync JSON and exposes row metadata
- `DBVC_Entity_Editor_Indexer::load_entity_file_for_download()`
  - safely resolves and reads an existing sync-relative JSON file without taking an edit lock
- `DBVC_Entity_Editor_Indexer::load_entity_file()`
  - use only when the UI must open the editor and acquire a lock
- `DBVC_Entity_Editor_Indexer` matching behavior
  - reuse or expose small public helpers where needed rather than duplicating UID/slug matching logic
- `.dbvc_entity_editor_backups`
  - existing backup location for Entity Editor file operations
- existing table filters, notices, modals, success handoff, and index rebuild flow in `src/admin-entity-editor/index.js`

### Raw Intake

- `Dbvc\EntityEditor\RawJsonIntakeService`
  - already validates DBVC post/term payloads
  - previews entity kind, subtype, title, slug, UID, live match state, and blocking reasons
  - delegates actual post creation to `DBVC_Sync_Posts::import_post_from_json()`
  - delegates term create/update to `DBVC_Sync_Taxonomies::import_term_json_file()`

Do not call raw-intake commit directly for existing sync files, because it is designed to write a pasted payload to the canonical sync path and intentionally blocks many file-collision cases.

### Import Engines

- `DBVC_Sync_Posts::import_post_from_json()`
  - existing post/CPT importer
  - supports creating missing posts when `dbvc_allow_new_posts` and `dbvc_new_post_types_whitelist` allow it
  - preserves incoming UID and syncs entity registry
  - imports meta and taxonomies through the existing rules
- `DBVC_Sync_Taxonomies::import_term_json_file()`
  - existing single-term importer
  - supports creating or updating terms
  - preserves incoming UID and writes term history
- `DBVC_Sync_Posts::import_tax_input_for_post()`
  - existing taxonomy assignment logic for post imports
- `DBVC_Sync_Posts::export_post_to_json()`
  - existing post export normalization after Entity Editor matched imports
- `DBVC_Sync_Taxonomies::export_selected_taxonomies()`
  - existing term export refresh path

### Helpers And Settings

- `dbvc_get_sync_path()`
- `dbvc_is_safe_file_path()`
- `dbvc_normalize_for_json()`
- `DBVC_Import_Router::determine_post_filename()`
- `DBVC_Import_Router::determine_term_filename()`
- `DBVC_Import_Router::ensure_directory()`
- `DBVC_Sync_Logger::log()` and specialized import log methods where appropriate
- `dbvc_allow_new_posts`
- `dbvc_new_post_status`
- `dbvc_new_post_types_whitelist`
- `dbvc_auto_create_terms`
- `dbvc_allow_uid_fallback_matching`

### Hooks And Filters To Preserve

- `dbvc_allow_uid_fallback_matching`
- `dbvc_allowed_export_filename_formats`
- `dbvc_allowed_taxonomy_filename_formats`
- `dbvc_bricks_meta_keys`
- `dbvc_after_import_term`
- `dbvc_after_export_post`
- `dbvc_after_export_term`
- `dbvc_entity_editor_protected_post_meta_keys`
- `dbvc_entity_editor_protected_term_meta_keys`

Do not bypass these by writing raw post, term, or meta data directly from the new endpoint.

## Product Contract

### Primary UX

Add a small import affordance to Entity Editor rows and toolbar:

- Unmatched eligible row action: `Import as New`
- Bulk action after P2: `Preview import selected`
- Existing matched rows keep the current `Edit JSON` flow
- Rows that are ineligible show no create button, but the preview endpoint can still return diagnostic reasons

The first modal should show:

- entity kind
- subtype
- title/name
- slug
- UID
- relative sync path
- detected live match status
- action that will run: `create`, `skip`, or `blocked`
- settings that affect creation, such as new-post setting, whitelist, and term creation setting
- warnings for missing optional fields or taxonomy references
- blocking reasons for unsafe cases

Primary action:

- `Create Entity`

Secondary actions:

- P1: `Refresh Preview`, `Close`
- Future: `Open JSON`, `Rebuild Index`

Avoid adding partial/full replace controls to this modal. Existing matched-file workflows already cover those cases with lock tokens and explicit destructive confirmation.

### Single-File Behavior

For a selected sync-relative JSON path:

1. Resolve and read the file using existing Entity Editor safe path behavior.
2. Decode and classify the payload.
3. Confirm the payload is a supported post/CPT or taxonomy term entity.
4. Confirm the row is currently unmatched by UID and slug/subtype.
5. Confirm creation is allowed by DBVC settings.
6. Commit through the existing DBVC import engine.
7. Return the created WP entity ID, edit URL, import result, and refreshed sync path.
8. Rebuild or invalidate the Entity Editor index.

### Bulk Behavior

Bulk create should be a wrapper around the single-file preflight/commit contract.

Rules:

- preflight all selected paths before showing the commit button
- commit only eligible `create` candidates
- skip matched, duplicate, invalid, excluded, unsupported, or blocked files
- return per-file results
- never treat a partial bulk failure as total success
- keep batch size bounded; use a conservative server-side limit

Suggested initial limit: 25 selected files per request.

## Backend Shape

Add one small service:

- `includes/Dbvc/EntityEditor/SyncFileImportService.php`

Responsibilities:

- validate sync-relative paths
- read existing JSON files through Entity Editor-safe helpers
- build preflight results
- enforce create-only rules for unmatched files
- call the existing post/term import engines
- format single and bulk responses
- log import attempts and results

Do not grow `DBVC_Entity_Editor_Indexer` into an import service. Use the indexer for indexing, safe reads, and small reusable helpers only.

If raw-intake and sync-file import need shared classification or matching, extract the smallest practical helper, for example:

- `Dbvc\EntityEditor\EntityPayloadInspector`

Keep it pure:

- decode/classify payload
- derive title, slug, subtype, UID
- detect canonical relative path
- inspect live match
- return warnings/blocking reasons

Avoid a broad refactor of `RawJsonIntakeService` in the first implementation slice.

## REST Shape

Add two routes under the existing Entity Editor namespace:

- `POST /dbvc/v1/entity-editor/sync-file-import/preview`
- `POST /dbvc/v1/entity-editor/sync-file-import/commit`

Use the existing `can_manage()` permission callback.

### Preview Request

```json
{
  "paths": ["page/example-page.json"],
  "mode": "create_only"
}
```

P2 supports multiple paths up to the server-side batch limit.

### Preview Response

```json
{
  "mode": "create_only",
  "summary": {
    "requested": 1,
    "creatable": 1,
    "blocked": 0,
    "skipped": 0
  },
  "items": [
    {
      "relative_path": "page/example-page.json",
      "entity_kind": "post",
      "subtype": "page",
      "title": "Example Page",
      "slug": "example-page",
      "uid": "vf_...",
      "detected_action": "create",
      "match": {
        "status": "none"
      },
      "warnings": [],
      "blocking": []
    }
  ]
}
```

### Commit Request

```json
{
  "paths": ["page/example-page.json"],
  "mode": "create_only"
}
```

### Commit Response

```json
{
  "mode": "create_only",
  "summary": {
    "requested": 1,
    "created": 1,
    "updated": 0,
    "blocked": 0,
    "skipped": 0,
    "errors": 0
  },
  "items": [
    {
      "relative_path": "page/example-page.json",
      "action": "create",
      "entity_kind": "post",
      "subtype": "page",
      "created": true,
      "imported": true,
      "wp_entity": {
        "id": 123,
        "kind": "post",
        "subtype": "page",
        "edit_url": "..."
      },
      "import_result": {
        "status": "applied"
      },
      "warnings": []
    }
  ]
}
```

## Preflight Rules

### Supported Payloads

Post/CPT payload must include:

- `post_type`
- `post_title`
- one safe identifier: `post_name` or `ID`

Term payload must include:

- `taxonomy`
- `slug`
- `name` or a usable slug fallback

### Blocking Rules

Block when:

- path escapes sync root
- file is missing
- file is invalid JSON
- file is excluded from Entity Editor
- entity kind cannot be detected
- post type is not registered
- taxonomy is not registered
- live match already exists by UID
- live match already exists by slug/subtype
- multiple possible live matches exist
- post creation is disabled
- post type is not allowed by `dbvc_new_post_types_whitelist`
- term creation would fail because taxonomy is unavailable or the payload is unsafe

### Warning Rules

Warn, but do not necessarily block, when:

- JSON has no UID and creation will mint/sync one
- post status in JSON differs from `dbvc_new_post_status`
- taxonomy terms referenced by a post may be created according to `dbvc_auto_create_terms`
- filename is not canonical for the current filename mode
- payload contains parent references that may not resolve on the target site

## Commit Rules

### Posts/CPTs

Call:

```php
DBVC_Sync_Posts::import_post_from_json($absolute_path, false, null, null, null, null, false, []);
```

Accept success only when the result is `applied`.

After commit:

- inspect the live entity again by UID/slug
- return the created post ID and edit URL
- move the source JSON to the final canonical filename when the new local post ID changes the filename
- back up any pre-existing canonical file before replacement
- refresh the Entity Editor index
- log a dedicated Entity Editor sync-file import event

Do not manually call `wp_insert_post()` from the new service unless the legacy importer cannot support a required safe case.

### Terms

Call:

```php
DBVC_Sync_Taxonomies::import_term_json_file($absolute_path, $taxonomy);
```

Accept success when the result returns a valid `term_id`.

After commit:

- return term ID and edit URL
- write the created local `term_id` back into the source JSON before canonical filename normalization
- move the source JSON to the final canonical filename when the new local term ID changes the filename
- refresh the Entity Editor index
- log a dedicated Entity Editor sync-file import event

## UI Details

P1 single-file UI:

- Add `Import as New` row action only when `matched_wp` is empty and the row is not a stale duplicate.
- The modal can reuse raw-intake preview/result components where practical.
- If preflight says blocked, disable `Create Entity`.
- On success, keep the result visible, rebuild index, and show a success notice with the created WP entity ID/link.

P2 bulk UI:

- Add bulk action `Preview import selected`.
- Bulk preview modal groups rows by:
  - creatable
  - blocked
  - skipped/matched
  - invalid/unsupported
- Commit button should state the exact number of entities that will be created.
- After commit, show per-file status and preserve failures in the modal.

## Phases

### P0. Fix Stale Entity Registry Matching

Status: `CLOSED 2026-06-09`

Problem:

- Raw-intake and Entity Editor matching can treat a DBVC entity-registry row as a live match even when the referenced WordPress post or term no longer exists.
- This blocks legitimate create flows as `matched_entity`.
- The sync-file import preflight must not inherit that false-positive behavior.

Tasks:

- Update raw-intake UID matching so registry rows only count when `get_post(object_id)` or `get_term(object_id)` confirms a live object of the expected subtype.
- Update Entity Editor matched-file partial/full matching with the same live-object requirement.
- Update formatting helpers so a missing WP object cannot be returned as `status = matched`.
- Add regression coverage for stale post registry rows.
- Document that stale rows should be ignored for preflight; cleanup can be added later as a separate maintenance task.

Exit criteria:

- a stale `wp_dbvc_entities` post row no longer blocks raw-intake create preview
- matched-file imports no longer treat stale registry rows as live matches
- no existing real matched-entity behavior regresses

### P1. Single-File Create From Sync JSON

Status: `CLOSED 2026-06-09 FOR POST/CPT`

Tasks:

- Confirm current index rows include enough metadata for unmatched-file affordances.
- Confirm `load_entity_file_for_download()` is suitable for sync-file preflight reads.
- Confirm P1 ships post/CPT only, with term creation deferred to P3.
- Add `SyncFileImportService`.
- Add preview and commit REST routes for one path.
- Implement create-only preflight.
- Use existing post import engine for commit.
- Add row action for unmatched rows.
- Rebuild Entity Editor index after success.
- Block noncanonical duplicate rows by reusing existing duplicate metadata.
- Suppress normal post auto-export hooks during quick-create import.
- Normalize the imported source JSON to the final canonical path after creation.

Exit criteria:

- one unmatched post JSON can be created from the Entity Editor table
- matched rows remain on the existing edit/import path
- invalid and blocked files return actionable reasons
- quick-create import leaves one canonical JSON file, not both the dropped original-ID file and the new local-ID export

### P2. Bulk Preview And Commit

Status: `CLOSED 2026-06-09 FOR POST/CPT`

Tasks:

- Extend preview/commit endpoints to accept multiple paths.
- Add bulk action and bulk preview modal.
- Enforce server-side batch limit.
- Return per-file status and aggregate counts.

Exit criteria:

- multiple unmatched post JSON files can be imported in one explicit commit
- partial failures are visible and do not hide successful creates

### P3. Term Support Hardening

Status: `CLOSED 2026-06-09`

Tasks:

- Confirm term preflight parity with raw-intake behavior.
- Verify UID-first and slug fallback behavior for terms.
- Validate parent term handling and blocked states.
- Use the existing `DBVC_Sync_Taxonomies::import_term_json_file()` importer for commit.
- Add term live-match checks by UID and slug/taxonomy.
- Normalize created term JSON to the final canonical term filename after the new local term ID is known.
- Add focused PHPUnit coverage for unmatched term creation from an existing sync file.

Exit criteria:

- unmatched term JSON can be created safely
- term parent edge cases are documented

### P4. Duplicate And Canonical File Handling

Status: `CLOSED 2026-06-10`

Tasks:

- Use existing duplicate group metadata from the index.
- Block stale duplicate rows by default.
- Allow import only from canonical duplicate row when duplicate metadata is present.
- Surface clear duplicate reasons in preflight.

Exit criteria:

- duplicate sync files cannot accidentally create or update the wrong entity
- PHPUnit confirms stale duplicate rows are blocked while the canonical duplicate row remains importable

### P5. Documentation And QA Closure

Status: `AUTOMATED COVERAGE CLOSED 2026-06-10; BROWSER QA OPEN`

Tasks:

- Update `docs/ENTITY_EDITOR_USAGE.md`.
- Update Entity Editor manual QA docs.
- Add screenshots or short operator notes if UI wording changes materially.
- Add focused PHPUnit coverage.

Exit criteria:

- docs match shipped behavior
- tests cover create success, creation disabled, matched blocked, invalid JSON, and bulk partial failure
- browser QA confirms the wp-admin modal, row affordance, and index refresh on a real LocalWP site

## Test Plan

### PHPUnit

Initial focused tests were added to `tests/phpunit/EntityEditorRawIntakeTest.php`. A dedicated `EntityEditorSyncFileImportTest` can still be split out as P2/P3 coverage grows.

Coverage:

- preview requires `manage_options`
- preview blocks invalid JSON
- preview blocks excluded paths
- preview blocks unsupported entity kind
- preview blocks matched post by UID
- preview blocks matched post by slug/subtype
- preview blocks post creation when `dbvc_allow_new_posts` is disabled
- preview blocks post type outside whitelist
- preview blocks stale duplicate files while allowing the canonical duplicate file
- commit creates an unmatched post from an existing sync file
- commit preserves incoming UID
- commit returns created WP entity metadata
- commit skips or blocks stale duplicate files
- bulk commit reports mixed success/failure without losing per-file errors
- commit creates an unmatched term from an existing sync file
- commit preserves incoming term UID
- commit returns created WP term metadata
- commit normalizes term JSON to the final local-ID canonical filename

### Manual QA

Use disposable fixture files.

Checks:

- dropped JSON appears in Entity Editor index after rebuild
- unmatched row shows `Preview Import`
- matched row does not show create affordance
- preview modal reports correct kind/subtype/title/slug/UID
- disabled settings produce blocked preflight
- successful commit creates WP post or term
- created entity has imported core fields/meta/taxonomies where applicable
- incoming UID is preserved
- index refresh shows matched WP entity after commit
- existing `Edit JSON`, `Save JSON`, `Save + Partial Import`, `Save + Full Replace`, `New From Raw JSON`, transfer packet, and delete selected still behave as before

## Rollback And Safety

- No automatic import on file drop.
- No destructive operations in this enhancement.
- No direct raw meta writes from the new service.
- No updates to existing matched entities from the new quick-create modal.
- Existing DBVC import settings remain authoritative.
- Every commit is explicit and logged.
- Bulk commit should be repeat-safe: already-created files become matched and are skipped or blocked on re-preview.

## Open Questions

- Should filename non-canonical warnings block imports or remain warnings?
- Should batch limit default to 25, 50, or a setting-backed value?
- Should successful post import auto-open the WP edit screen link only, or also open the resulting Entity Editor JSON modal?
- Should import commits require a file lock, or is preflight plus explicit commit enough because the action does not edit the JSON file itself?

Recommended answers for first implementation:

- treat filename mismatch as warning
- batch limit 25
- show both WP edit link and `Open JSON`
- do not require editor lock for commit, but re-read and re-preflight immediately before import
