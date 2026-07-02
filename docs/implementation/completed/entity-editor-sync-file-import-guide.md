# Entity Editor Sync File Import Implementation Guide

Last updated: 2026-06-24

Status: `P10 RAW-INTAKE DUPLICATE JSON MINOR FIX IMPLEMENTED; P9 MATCHED-ENTITY UPDATE IMPLEMENTED; P8 BLOCKER RESOLUTION UI IMPLEMENTED; P7 DUPLICATE CANONICAL BUG FIX IMPLEMENTED`

Recurrence note: duplicate active JSON files have now been fixed in two Entity Editor paths. P7 fixed staged sync-file import and duplicate canonical grouping; P10 applies the same side-effect suppression and canonicalization guardrail to raw-intake commits.

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
- duplicate grouping now prefers the matched local WordPress entity ID before source payload ID, so source-ID and local-ID JSON files for the same entity collapse into one duplicate group
- successful sync-file import normalization archives older same-entity duplicate JSON files into `.dbvc_entity_editor_backups` after the canonical local-ID file is written
- Bricks template sync-file preview now includes a read-only advisory when the DBVC Bricks add-on is enabled, so users can see likely frontend rendering conflicts before creating a template
- blocked sync-file previews now return structured blocker details, settings links, setting remediations, advanced duplicate-resolution actions, and a stable preview hash
- the sync-file import popup now includes a `Blockers and fixes` panel with inline actions for safe setting fixes and stale duplicate resolution
- inline setting remediation is limited to `dbvc_allow_new_posts` and `dbvc_new_post_types_whitelist`, and stale duplicate archival is limited to a single verified stale duplicate file
- sync-file and raw-intake create-only previews now detect legacy payload-ID fallback risk: UID-less post JSON, or UID-bearing post JSON when UID fallback matching is enabled, is blocked as an existing entity if the incoming numeric ID already belongs to a local post of the same type
- raw-intake UID extraction now matches sync-file import for top-level UID, `dbvc_object_uid`, `meta.vf_object_uid`, and post/term history UID shapes
- raw-intake previews now return the same universal blocker detail/settings-link shape used by sync-file import, so both Entity Editor import modals surface matching configuration, existing-entity, unsupported-type, and file-collision guidance
- raw-intake commits now reuse sync-file import side-effect suppression and canonical filename normalization so `New From Raw JSON` create/update flows do not leave both source-ID and local-ID JSON files active in the sync index
- preview coverage now includes permission denial, invalid JSON, excluded paths, unsupported payloads, creation-disabled settings, whitelist blocking, stale duplicate blocking, bulk partial failures, and term creation
- successful commits return the created WP entity and rebuild the Entity Editor index in the UI

## P10. Minor Fix: Raw-Intake Duplicate Sync JSON Prevention

Status: `CLOSED` on 2026-06-24.

Regression history:
- This is the second duplicate-file class fixed for Entity Editor imports.
- P7 fixed source-ID/local-ID duplicate grouping and stale duplicate cleanup for staged sync-file import rows.
- P10 fixes the equivalent raw-intake create/update path, where the importer's auto-export side effects could race ahead of raw-intake source-file normalization.
- Future Entity Editor import paths that create or update live entities from JSON must suppress normal auto-export side effects during the importer call, rewrite source-site IDs to local IDs when needed, and normalize the active sync JSON to one canonical file before returning success.

Problem:
- `New From Raw JSON` wrote the pasted payload to its previewed sync path, then called the low-level post/term importers directly.
- Post creation and meta writes could trigger DBVC auto-export hooks before raw intake normalized the source file, leaving both the raw source filename and the local canonical filename active.
- Entity Editor correctly grouped those rows as duplicates because they pointed to the same matched WordPress entity or `vf_object_uid`.

Implementation plan:
- Reuse the sync-file import service's existing side-effect suppression instead of creating a separate raw-intake suppression path.
- After a successful raw-intake import, rewrite the source JSON with the resolved local post `ID` or term `term_id` when needed.
- Reuse the sync-file import canonical file normalization helper to move the importer-updated source JSON to the final DBVC filename and archive same-entity stale duplicates.
- Return the final canonical `relative_path` to the UI so the existing "open after success" behavior opens the surviving file.
- Add regression coverage for raw-intake post and term creation under local-ID-bearing filename modes, asserting that exactly one active JSON file remains.

Blast-radius guardrails:
- Applies only to `POST /dbvc/v1/entity-editor/raw-intake/commit`.
- Does not change raw-intake preview matching, staged sync-file import preview/commit, proposal review/apply, legacy upload import, general DBVC export settings, Entity Editor `Save JSON`, `Save + Partial Import`, or `Save + Full Replace`.
- Uses the same importer calls as before; the change is limited to temporary hook suppression during the import call and post-import file canonicalization.

Acceptance checks:
- Raw-intake post create leaves one active `page/*.json` file at the local canonical filename.
- Raw-intake term create leaves one active `taxonomy/{taxonomy}/*.json` file at the local canonical filename.
- The created/updated WordPress entity keeps the incoming `vf_object_uid`, meta, taxonomy, and status behavior from the existing importer.
- Existing sync-file import duplicate-prevention tests continue to pass.

## Planned Next Slice

P9 adds an explicit `Update Matched Entity` path to the Import Sync JSON modal for high-confidence post/CPT UID matches. It reuses the existing DBVC post importer, requires a per-item `I confirm` checkbox plus server-side confirmation records, and keeps the current `Create Entity` flow create-only. Config-model cleanup for how main post type/taxonomy export selections relate to the new-post creation whitelist remains a separate follow-up.

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

- Update `docs/reference/entity-editor-usage.md`.
- Update Entity Editor manual QA docs.
- Add screenshots or short operator notes if UI wording changes materially.
- Add focused PHPUnit coverage.

Exit criteria:

- docs match shipped behavior
- tests cover create success, creation disabled, matched blocked, invalid JSON, and bulk partial failure
- browser QA confirms the wp-admin modal, row affordance, and index refresh on a real LocalWP site

### P6. Bricks Template Import Advisory

Status: `AUTOMATED COVERAGE CLOSED 2026-06-10; BROWSER QA OPEN`

Problem:

- A `bricks_template` JSON can import successfully but still not affect the frontend when another published Bricks template has the same or overlapping conditions.
- Imported Bricks templates may also contain source-site preview IDs, such as `templatePreviewPostId`, that do not exist locally.
- Users need this surfaced during quick-create preview, but the quick-create tool should not automatically rewrite Bricks conditions, disable templates, or become a second proposal system.

Scope:

- Run only for incoming post JSON where `post_type` is `bricks_template`.
- Surface only when `DBVC_Bricks_Addon::is_enabled()` returns true.
- Add read-only advisory data to sync-file preview/commit items.
- Render a compact Bricks advisory panel in the existing `Import Sync JSON` modal.
- Keep `Create Entity` behavior unchanged; warnings are informational unless the normal sync-file preflight already blocks the item.

Development items:

- Add a small Bricks advisory builder inside `SyncFileImportService` rather than adding a new import engine.
- Extract `_bricks_template_type`, `_bricks_template_settings.templateConditions`, `templatePreviewPostId`, and `templatePreviewTerm` from the incoming DBVC JSON.
- Query existing published `bricks_template` posts and compare template type plus normalized conditions.
- Treat exact condition matches and simple overlapping Bricks condition targets, such as `postType: listing`, as warnings.
- Warn when a preview post ID is present but does not resolve to a local post.
- Warn when a preview term target is present but does not resolve to a local term.
- Return links to conflicting live templates when available.
- Do not make conflict warnings blocking.
- Do not mutate Bricks conditions, preview IDs, template status, or template priority during import.
- Add focused PHPUnit coverage for enabled add-on conflict detection and stale preview post warnings.
- Update the React modal to render the advisory without changing existing warning/blocker rendering.

Exit criteria:

- previewing an unmatched `bricks_template` sync file with the Bricks add-on enabled reports Bricks-specific advisory data
- an incoming template that targets the same CPT condition as an existing published template shows a warning with the existing template link
- an incoming template with a missing preview post ID shows a warning
- the import button remains governed by the existing sync-file preflight, not by advisory warnings
- existing post/CPT and term import behavior remains unchanged

### P7. Source-ID Duplicate Canonical Bug Fix

Status: `CLOSED 2026-06-23`

Problem:

- A dropped source-site JSON can share the same `vf_object_uid` and slug as the newly created local entity but carry a different source `ID`.
- The Entity Index duplicate grouping was intended to prefer the matched local WordPress entity ID, then UID, then payload ID. The implementation grouped by payload ID first, which split source-ID and local-ID files into separate groups.
- That allowed stale source-ID files to appear as canonical rows while the real local-ID canonical file was not marked as part of the duplicate group.
- For Bricks templates, this made stale files without ACF dynamic tags look authoritative even after the live local template and canonical export were corrected.

Scope:

- Keep the fix inside Entity Editor index/import behavior.
- Do not change proposal review, raw JSON intake, legacy upload import, or general DBVC export filename settings.
- Archive stale same-entity files with backups instead of permanently removing them without recovery.

Development items:

- Update `DBVC_Entity_Editor_Indexer::build_duplicate_group_descriptor()` to prefer:
  - matched local WordPress entity ID
  - `vf_object_uid`
  - source payload ID
- Update duplicate canonical scoring so filename alignment is checked against the matched local WP ID before the source payload ID.
- After sync-file import canonical normalization, rebuild the Entity Index and archive stale duplicate files from the same duplicate group into `.dbvc_entity_editor_backups`.
- Treat stale duplicate archive failures as non-blocking warnings after a successful import, not as a failed entity creation.
- Add regression coverage for source-ID/local-ID duplicate grouping.
- Add regression coverage for sync-file import archiving a stale same-UID duplicate after creating the canonical local-ID JSON.
- Update operator docs to clarify that Import as New can archive redundant same-entity JSON files after successful normalization.

Exit criteria:

- source-ID and local-ID JSON files for the same live entity are grouped together
- the local-ID filename is selected as the canonical duplicate row when it matches the matched WP entity
- stale same-entity files are no longer left beside the canonical local-ID JSON after a successful sync-file import
- failed stale-file archival returns a warning without undoing a successful entity creation

### P8. Import Blocker Resolution UI

Status: `IMPLEMENTED 2026-06-23`

Problem:

- The `Import as New` popup can currently leave operators with only `Refresh Preview` when preflight blocks creation.
- The underlying response contains blocker codes, but the modal does not clearly connect those codes to:
  - the affected entity type
  - the setting or duplicate row causing the block
  - the relevant DBVC configuration screen
  - the safest next action
- Common cases such as `dbvc_allow_new_posts` disabled, `dbvc_new_post_types_whitelist` missing the incoming post type, and `stale_duplicate_file` require the operator to leave the modal, infer the setting, update config manually, rebuild the index, and retry.

Product goals:

- Make blocked import previews actionable without making the quick-create tool unsafe.
- Keep the primary behavior create-only and conservative.
- Let operators fix narrowly scoped configuration blockers from the modal when the required change is obvious and safe.
- Provide explicit links to `DBVC -> Export -> Configure -> Import Defaults -> Import Settings` for settings that require broader review.
- Provide a clearly labeled advanced bypass area only for blocker types that have a safe, deterministic server-side behavior.

Non-goals:

- Do not turn the quick-create modal into proposal review or merge review.
- Do not support arbitrary option writes from the browser.
- Do not bypass malformed JSON, missing post types, missing taxonomies, unsafe paths, ambiguous matches, or permission failures.
- Do not let a stale duplicate file silently create a second entity with the same `vf_object_uid`.
- Do not update existing matched entities from the create-only modal.

Blocker categories:

| Blocker | Category | Modal action |
| --- | --- | --- |
| `post_creation_disabled` | Config fixable | Show setting state, link to Import Settings, offer `Enable new post creation` inline. |
| `post_type_whitelist_blocked` / whitelist message | Config fixable | Show current whitelist summary, link to Import Settings, offer `Allow this post type` inline. |
| `stale_duplicate_file` | Resolution action | Show canonical path and actions: `Open canonical JSON`, `Use canonical row`, `Archive stale duplicate after confirmation`. |
| `matched_entity` | Existing entity | Show matched WP entity link and actions: `Open WP entity`, `Open canonical JSON` when known. Do not offer create. |
| `missing_post_type` | Dependency missing | Show post type key and guidance that the CPT/plugin must be registered on this site. Link to config only as secondary context. |
| `missing_taxonomy` | Dependency missing | Show taxonomy key and guidance that the taxonomy/plugin must be registered on this site. |
| `unsupported_entity_kind`, `invalid_json`, `excluded_path`, path safety errors | Hard blocker | Explain the reason; no inline bypass. |
| ambiguous UID/slug matches | Hard blocker | Show candidate IDs if safe; require manual cleanup or proposal/diff flow. |

Safe inline config updates:

- Add a new dedicated Entity Editor REST endpoint:
  - `POST /dbvc/v1/entity-editor/sync-file-import/remediate`
- Request shape:

```json
{
  "path": "listing/example-listing-120818.json",
  "mode": "create_only",
  "remediation": "allow_post_type_creation",
  "preview_hash": "..."
}
```

- Supported remediations for the first slice:
  - `enable_new_post_creation`: sets `dbvc_allow_new_posts = 1`
  - `allow_post_type_creation`: appends the incoming registered post type to `dbvc_new_post_types_whitelist`
  - `use_canonical_row`: returns a fresh preview for the canonical duplicate row
  - `archive_stale_duplicate`: backs up and removes one verified stale duplicate sync file, then returns a fresh preview for the canonical row
- Server requirements:
  - require `manage_options`
  - re-read the sync file
  - re-run preview
  - verify the requested remediation is still present and applicable
  - verify the post type exists locally before adding it to the whitelist
  - preserve existing whitelist values
  - write only the specific allowed option
  - log the setting change or stale duplicate archive with user ID, file path, old value/new value where applicable, and canonical path where applicable
  - return a fresh preview payload after the setting update or stale duplicate resolution

Config links:

- Add a normalized `settings_links` array to preview items where relevant:

```json
{
  "settings_links": [
    {
      "id": "import_settings",
      "label": "Open Import Settings",
      "url": "admin.php?page=dbvc-export&tab=tab-config&subtab=dbvc-config-import&import_subtab=dbvc-config-import-settings#dbvc-config-import-settings"
    },
    {
      "id": "post_type_settings",
      "label": "Open Post Type Settings",
      "url": "admin.php?page=dbvc-export&tab=tab-config&subtab=dbvc-config-post-types"
    }
  ]
}
```

- If the current tab URL scheme cannot activate subtabs from query args, add a lightweight anchor fallback first and defer deeper tab auto-selection.

Bypass design:

- Add a separate `advanced_overrides` array to preview items, distinct from config fixes.
- Initial bypasses should be opt-in, per-file, and require confirmation in the modal.
- Supported first bypass:
  - `use_canonical_row`
  - behavior: re-preview the canonical sync JSON row for the duplicate group and switch the modal selection to that path
  - `archive_stale_duplicate`
  - behavior: back up the selected stale duplicate JSON into `.dbvc_entity_editor_backups`, remove it from the active sync index, and refresh preview against the canonical row
- Deferred bypass:
  - `create_as_new_unique_entity_from_duplicate`
  - requires a separate payload transformation plan: mint new UID, neutralize source ID, resolve slug collision, preview resulting canonical path, and show a destructive/identity warning. Do not include this in the first P8 implementation.

UI requirements:

- Replace the current blocked-state dead end with a `Blockers and fixes` panel in the import modal.
- For each blocker, show:
  - severity
  - plain-language reason
  - affected entity type/subtype
  - matched entity or canonical path when available
  - relevant current setting value when available
  - available action buttons
- Action button examples:
  - `Enable new post creation`
  - `Allow listing imports`
  - `Open Import Settings`
  - `Open canonical JSON`
  - `Use canonical row`
  - `Archive stale duplicate`
- After an inline config update or stale duplicate archive, automatically refresh preview and keep the modal open.
- Keep `Create Entity` disabled until the fresh preview reports `available_actions.create_only = true`.
- For bulk previews, keep fixes per-item in the first implementation and keep `Create Entity` disabled until the refreshed preview reports at least one eligible create candidate.

Backend development items:

- Extend `SyncFileImportService::preview()` item payload with:
  - `blocker_details`
  - `settings_links`
  - `setting_remediations`
  - `advanced_overrides`
  - stable `preview_hash` derived from path, payload UID, payload ID, post type/taxonomy, and blocker codes
- Normalize blocker codes so whitelist failures are machine-readable instead of relying only on the current message text.
- Add remediation endpoint and service method.
- Add stale duplicate maintenance action that can archive one selected stale duplicate file after confirming its duplicate group canonical path.
- Ensure remediation endpoints never accept arbitrary option names or values.
- Add audit logging for inline setting changes and stale duplicate archive actions.

Frontend development items:

- Add a `Blockers and fixes` section to the sync-file import modal.
- Render config fix buttons only when the preview item includes a matching `setting_remediations` entry.
- Render settings links using localized admin URLs from the preview payload.
- Add confirmation states for:
  - enabling new post creation
  - adding a post type to the whitelist
  - archiving a stale duplicate file
- After action success, replace the modal preview with the returned fresh preview.
- Preserve existing warning/advisory rendering, including the Bricks advisory.

Exit criteria:

- A blocked Listing JSON with `listing` missing from `dbvc_new_post_types_whitelist` shows a clear explanation and an `Allow listing imports` action.
- Clicking `Allow listing imports` updates only `dbvc_new_post_types_whitelist`, logs the change, refreshes preview, and enables `Create Entity` if no other blockers remain.
- A blocked preview caused by `dbvc_allow_new_posts = 0` shows `Enable new post creation` and links to Import Settings.
- A `stale_duplicate_file` preview shows the canonical path and lets the user switch to the canonical row or archive the stale row after confirmation.
- Hard blockers never show bypass or config-write buttons.
- Bulk modal remains safe when selected files have mixed blockers.

### P9. Update Matched Entity From Sync Import

Status: `IMPLEMENTED 2026-06-23`

Problem:

- The Import Sync JSON modal is intentionally create-only today.
- When DBVC finds a high-confidence match, such as a UID/registry/history match, the modal blocks `Create Entity` as `matched_entity`.
- That is correct for duplicate prevention, but it leaves operators without a simple sync-file path for the common cross-site workflow: "this JSON belongs to an existing local entity; update that entity from this file after I confirm it."
- Raw JSON intake already has a `Create or Update Matched` mode, but the staged sync-file modal does not expose the equivalent update path or confirmation UX.

Product goals:

- Add a clear, explicit matched-entity update action without weakening create-only safety.
- Reuse `DBVC_Sync_Posts::import_post_from_json()` for post/CPT updates instead of introducing a second write engine.
- Show the matched WordPress entity, match source, and canonical sync file before any update.
- Require a per-file confirmation checkbox before enabling update.
- Keep the primary button label accurate: `Create Entity` for unmatched files, `Update Matched Entity` for confirmed matched files.
- Preserve the P8 blocker panel for config blockers and duplicate-resolution blockers.

Non-goals:

- Do not relabel an update as a create.
- Do not update ambiguous matches, slug-only matches with an incoming UID mismatch, stale duplicate files, invalid JSON, missing post types, or missing taxonomies.
- Do not add field-level merge/diff decisions in this phase.
- Do not add automatic bulk overwrite for every selected matched entity without per-item confirmation.
- Do not implement the deferred `create_as_new_unique_entity_from_duplicate` bypass here.

Allowed first-slice matches:

| Match source | Eligible? | Notes |
| --- | --- | --- |
| `uid` | yes | Preferred. The incoming UID resolves to one local entity of the same subtype. |
| DBVC entity registry / history UID | yes | Eligible when it resolves to one live local entity of the same subtype. |
| `payload_id` | no by default | Keep as a blocker unless UID fallback matching is explicitly enabled and preview says the match is safe. |
| slug/subtype only | no in first slice | Defer until diff/merge UI can show stronger evidence. |
| stale duplicate row | no | User must first choose `Use canonical row` or `Archive stale duplicate`. |
| ambiguous match | no | Requires manual cleanup or future proposal/diff workflow. |

Proposed preview contract additions:

```json
{
  "available_actions": {
    "create_only": false,
    "update_matched": true
  },
  "matched_update": {
    "eligible": true,
    "requires_confirmation": true,
    "match_source": "uid",
    "wp_entity": {
      "id": 107642,
      "kind": "post",
      "subtype": "listing",
      "label": "Charming Renovated 1.5-Bedroom Apartment Near Downtown Urbana",
      "edit_url": "..."
    },
    "scope_summary": {
      "core_fields": true,
      "meta": true,
      "taxonomies": true
    }
  }
}
```

Backend development items:

- Add one reusable confirmed-update service method that can be called by REST now and by later DBVC workflows without duplicating validation:
  - input: sync-relative paths, per-path confirmation records, and user ID
  - confirmation record: `confirmed = true`, `preview_hash`, and matched WP entity ID
  - output: the same bulk-style summary/items response used by sync-file create commits
  - responsibility: normalize paths, re-run preview immediately before writing, verify confirmation, verify preview hash, verify matched entity drift, and delegate the write to DBVC's existing import engine
- Add a sync-file import mode/action for matched updates, for example `update_matched`.
- Extend `SyncFileImportService::preview()` so matched post/CPT rows can expose `matched_update.eligible = true` only when the match is high-confidence.
- Keep `available_actions.create_only = false` for matched rows.
- Add a commit path that:
  - accepts only confirmed `update_matched` requests
  - re-reads the file
  - re-runs preview immediately before write
  - verifies the same matched local entity still exists
  - verifies the match source is still allowed
  - delegates post/CPT updates to the existing DBVC post importer with export suppression
  - rewrites the staged JSON's `ID` to the matched local post ID before canonical filename normalization when the source file came from another site
  - returns `action: update_matched`, `updated: true`, and the matched WP entity
  - rebuilds/invalidates the Entity Editor index after success
- Keep term update support deferred unless the existing term importer update behavior is verified and covered by tests.
- Log matched updates with user ID, relative path, matched entity ID, subtype, match source, and import result.

Frontend development items:

- In the Import Sync JSON modal, render a matched-entity update panel when `matched_update.eligible = true`.
- Show:
  - matched WP entity title/ID/subtype
  - match source
  - target sync path
  - a concise "this will update the matched WordPress entity" notice
- Add a checkbox per eligible item:
  - `I confirm updating this matched WordPress entity from the selected JSON`
- Enable `Update Matched Entity` only when all items to be updated are confirmed and the preview remains fresh.
- For mixed selections, keep create and update counts distinct:
  - `Create 2 Entities`
  - `Update 1 Matched Entity`
  - first implementation should use separate create and update buttons rather than one mixed commit button.
- Continue rendering P8 blocker actions for non-eligible matched rows and stale duplicate rows.

Safety rules:

- The update action must never appear for `stale_duplicate_file` until the user switches to the canonical row.
- The update action must never appear for hard blockers.
- A checked confirmation must be invalidated when the preview is refreshed or the selected path changes.
- The server must not trust the checkbox alone; it must revalidate path, preview hash, match source, and matched entity immediately before import.
- The action should reuse existing importer snapshots/logging where available; if no snapshot is produced by the current importer path, add a small pre-update audit record before writing.

Exit criteria:

- A canonical sync JSON that matches an existing local listing by UID shows `Update Matched Entity` after the user checks the confirmation box.
- A stale duplicate JSON still shows duplicate-resolution actions and does not offer update until the canonical row is selected.
- A slug-only match does not offer update in the first slice.
- A missing whitelist entry still shows the P8 whitelist remediation when the file is unmatched and creatable after config is fixed.
- Preview hash or match drift between preview and commit blocks the update.
- Existing create-only behavior and bulk create behavior remain unchanged.

Implemented notes:

- `SyncFileImportService::commit_confirmed_matched_updates()` is the reusable matched-update entry point for REST and future DBVC workflows.
- REST uses `mode: "update_matched"` with a `confirmations` map keyed by sync-relative path.
- Each confirmation must include `confirmed: true`, the current `preview_hash`, and the matched WP entity ID.
- The server re-previews immediately before writing and blocks stale hashes, missing confirmations, changed matched IDs, non-UID matches, term matches, stale duplicates, and hard blockers.
- Successful post/CPT matched updates run through `DBVC_Sync_Posts::import_post_from_json()` with normal auto-export hooks suppressed, then rewrite the staged JSON `ID` to the matched local post ID before canonical filename normalization.

### Later Enhancement. Merge Entities

Status: `SUPERSEDED BY PROPOSED GUIDE`

The deferred merge idea from this completed sync-file import guide has been promoted into a dedicated proposed guide:

- `docs/implementation/proposed/entity-editor-merge-incoming-json-guide.md`

Keep this completed guide focused on current sync-file import behavior. Use the proposed guide for future selected-entity pasted JSON merge work.

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
- Bricks template advisory reports overlapping published template conditions when the Bricks add-on is enabled
- Bricks template advisory reports missing preview post IDs without blocking import
- index duplicate grouping prefers matched WP ID over source payload ID for source-ID/local-ID duplicate files
- commit archives stale same-entity duplicate files after canonical filename normalization
- preview exposes structured blocker details, setting remediations, settings links, and advanced override metadata
- remediation endpoint updates only allowed DBVC import settings after re-previewing the same file
- stale duplicate modal action can switch to the canonical row or archive the stale row after backup
- hard blockers do not expose bypass actions
- UID-less post JSON with a local same-type payload ID collision is blocked before commit
- UID-bearing post JSON ignores source numeric ID collisions when UID fallback matching is disabled
- UID-bearing post JSON is blocked as an existing entity when UID fallback matching is enabled and the source numeric ID matches a local same-type post
- raw-intake recognizes `meta.vf_object_uid` and history UID values before considering legacy payload-ID fallback
- raw-intake post create leaves one active local canonical `page/*.json` file after the importer writes the created local ID
- raw-intake term create leaves one active local canonical `taxonomy/{taxonomy}/*.json` file after the importer writes the created local term ID
- sync-file preview exposes `matched_update` only for high-confidence UID-matched post/CPT rows
- sync-file matched update requires a confirmation record and blocks missing confirmation, stale preview hash, match drift, slug-only matches, and payload-ID-only matches
- sync-file matched update applies JSON-present post fields, meta, and taxonomies through the existing DBVC post importer and normalizes source-site IDs to the matched local canonical JSON filename

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
- Bricks template import preview shows a Bricks advisory when the add-on is enabled and a condition conflict exists
- blocked Listing import with missing whitelist entry shows `Allow listing imports`, updates the setting, refreshes preview, and enables creation only if no other blockers remain
- disabled new-post creation shows `Enable new post creation`, updates the setting, and refreshes preview
- stale duplicate preview shows the canonical path, supports `Use canonical row`, and archives stale duplicate only after confirmation
- UID-matched post/CPT preview shows `Update Matched Entity` only after the per-item `I confirm` checkbox is checked
- matched update without a confirmation checkbox leaves the live WordPress entity unchanged
- stale preview hash or changed matched ID blocks matched update until the preview is refreshed and reconfirmed
- slug-only matched files block create and do not offer matched update in this first slice
- hard blockers such as invalid JSON and missing post type show explanation text but no bypass action
- index refresh shows matched WP entity after commit
- existing `Edit JSON`, `Save JSON`, `Save + Partial Import`, `Save + Full Replace`, `New From Raw JSON`, transfer packet, and delete selected still behave as before

## Rollback And Safety

- No automatic import on file drop.
- Stale duplicate cleanup backs up each redundant sync JSON into `.dbvc_entity_editor_backups` before removing it from the active sync index.
- No direct raw meta writes from the new service.
- No updates to existing matched entities from the create-only action; matched updates use the separate explicit `update_matched` confirmation path.
- Existing DBVC import settings remain authoritative.
- Inline setting remediation is limited to explicit allowlisted DBVC import options and is logged.
- Bypass actions are per-file and do not create duplicate entities with reused UIDs.
- Every commit is explicit and logged.
- Bulk commit should be repeat-safe: already-created files become matched and are skipped or blocked on re-preview.

## Open Questions

- Should filename non-canonical warnings block imports or remain warnings?
- Should batch limit default to 25, 50, or a setting-backed value?
- Should successful post import auto-open the WP edit screen link only, or also open the resulting Entity Editor JSON modal?
- Should import commits require a file lock, or is preflight plus explicit commit enough because the action does not edit the JSON file itself?
- Should inline config remediation be limited to administrators even if future Entity Editor access is delegated to non-admin managers?
- Should `Allow this post type` also sync the main `dbvc_post_types` export/import selection when the post type is absent there, or should it only affect the creation whitelist?
- Should P9 matched updates support taxonomy terms in the first slice, or stay post/CPT-only until term update semantics are independently verified?

Recommended answers for first implementation:

- treat filename mismatch as warning
- batch limit 25
- show both WP edit link and `Open JSON`
- do not require editor lock for commit, but re-read and re-preflight immediately before import
- keep inline remediation administrator-only through `manage_options`
- for P8, update only the creation whitelist; handle synchronization with main post type/taxonomy selections as a separate config-model cleanup unless we first add a shared settings abstraction
- for P9, ship post/CPT matched updates first and defer term updates unless a focused audit confirms the current term importer is already safe for matched updates
