# Legacy Upload Immediate Import Phase Plan

This phase adds a narrow enhancement to the legacy sync-folder upload flow: when an admin uploads one or more post JSON files from the "Upload to Sync Folder" area, they can opt to keep the existing sync folder intact and immediately import only those uploaded post files instead of running the full sync-folder importer.

Last updated: 2026-03-18

## Phase Name

Phase 19: Targeted Upload Immediate Import

## Problem Statement

The current upload flow and the current legacy import flow are both broader than the targeted "single post JSON" maintenance workflow:

- Single JSON uploads clear the sync folder before routing the file.
- The legacy "Run Import" action scans all supported post-type folders under sync and processes every eligible JSON file it finds.
- Reviewers who only want to upload and apply one or two post JSON files must either:
  - accept the sync-folder wipe, or
  - manually manage sync-folder contents before running import.

This phase adds a safe, explicit fast path for targeted post imports without changing the default legacy behavior for ZIP uploads, dry runs, or normal full-folder imports.

## Scope

- Add one new primary toggle under the upload field in the legacy upload area.
- When that toggle is enabled for JSON uploads:
  - do not clear the sync folder before routing the uploaded JSON files,
  - route the files into their normal post-type folders,
  - immediately import only the routed post JSON files from that upload request,
  - leave unrelated sync-folder content untouched,
  - surface upload-plus-import results in the existing upload report area.
- Keep current upload behavior unchanged when the new toggle is off.

## Non-Goals

- Replacing the existing "Run Import" form.
- Changing ZIP upload behavior.
- Importing every routed scenario automatically (menus, options, terms, media manifests).
- Refactoring the legacy importer into a proposal-style workflow.
- Changing how filename-mode filtering works across the rest of the plugin.

## UX Decision

The primary control should be explicit and conservative:

- New checkbox label:
  - `Import uploaded post JSON immediately (keep current sync folder contents)`
- Help text:
  - Clarify that this only applies to JSON uploads, skips the sync-folder wipe, and imports only routed post JSON files from the current upload request.

To avoid hidden behavior differences between upload-driven imports and the legacy import form, this phase should also expose one subordinate checkbox when the primary toggle is enabled:

- `Only import new or modified posts`

Immediate import should also note that it reuses the existing legacy post-matching behavior, including entity UID matching when the uploaded JSON contains `vf_object_uid`.

## Implementation Guide

### 1. Extend the upload form

Add the new primary toggle directly below the upload field in the legacy upload section of `admin/admin-page.php`.

Tasks:

- Add the primary checkbox and description text under the existing dry-run control.
- Add a conditional subordinate checkbox for smart import.
- Add helper text clarifying that entity UID matching is already reused when the uploaded JSON includes `vf_object_uid`.
- Ensure labels make it clear the fast path is for uploaded post JSON files only.
- Keep the existing upload action, nonce, and file input unchanged.

Checklist:

- [x] Primary immediate-import checkbox added to the upload form.
- [x] Subordinate smart-import checkbox added for the immediate-import path.
- [x] Help text explains scope and preserves the default upload behavior when unchecked.

### 2. Add request parsing and guardrails in the upload handler

Update `DBVC_Sync_Posts::handle_upload_sync()` so the new mode is explicit and safe.

Tasks:

- Read the new upload-form flags from `$_POST`.
- Only allow the immediate-import path for non-dry-run JSON uploads.
- Ignore the new mode for ZIP uploads and preserve current ZIP behavior.
- Preserve current behavior when the new toggle is absent or unchecked.
- Preserve current multi-file JSON routing support.

Checklist:

- [x] Immediate-import flags are sanitized in `handle_upload_sync()`.
- [x] Dry-run uploads bypass immediate import.
- [x] ZIP uploads bypass immediate import and retain current behavior.
- [x] Unchecked uploads still clear the sync folder exactly as they do today.

### 3. Override the sync-folder wipe only for the new mode

The new behavior should change only the single part of the flow that currently blocks targeted uploads: the automatic sync-folder clear before JSON routing.

Tasks:

- Gate the existing `delete_folder_contents()` call so JSON immediate-import requests keep the sync folder intact.
- Keep current clear behavior for standard single JSON uploads, standard batch JSON uploads, and ZIP uploads.
- Log whether the sync folder was retained because immediate import mode was enabled.

Checklist:

- [x] Immediate-import JSON uploads skip the sync-folder wipe.
- [x] Standard uploads still wipe or retain the sync folder exactly as before.
- [x] Upload logging distinguishes the new retained-folder reason from existing batch-routing logs.

### 4. Capture only the routed post files from the current upload request

The handler already receives per-file routing details from `DBVC_Import_Router::route_uploaded_json()`. Reuse that report instead of rescanning the sync folder.

Tasks:

- Read routed entries from `route_uploaded_json()` results.
- Filter candidates to:
  - `scenario === post`
  - `status === routed`
  - valid output paths
- Exclude menus, options, terms, and skipped/error entries from the immediate-import candidate set.
- Add a defensive check that each candidate path is safe and exists before importing.

Checklist:

- [x] Immediate-import candidate collection reuses router output instead of `import_all()`.
- [x] Only routed post JSON files are eligible for immediate import.
- [x] Non-post routed JSON files are reported but not auto-imported.
- [x] Candidate path safety/existence checks are in place.

### 5. Add a targeted import helper

The current legacy entry point is `DBVC_Sync_Posts::import_all()`, which scans the whole sync folder. This phase needs a narrow helper for importing only selected files.

Recommended helper:

- `DBVC_Sync_Posts::import_selected_post_files(array $filepaths, bool $smart_import = false, array $options = [])`

Tasks:

- Normalize and de-duplicate incoming file paths.
- Loop over each selected path and call `import_post_from_json()` directly.
- Pass through the immediate-import smart-import flag.
- Return a structured result summary:
  - processed
  - applied
  - skipped
  - errors
  - per-file results

Checklist:

- [x] New targeted import helper added to `DBVC_Sync_Posts`.
- [x] Helper imports only provided post file paths.
- [x] Smart-import flag is honored in the targeted path.
- [x] UID-matching behavior is reused automatically from the legacy importer path.
- [x] Helper returns a structured per-file result payload.

### 6. Extend upload reporting and notices

The upload area already stores and renders `dbvc_sync_upload_report`. Extend that report instead of inventing a new notice system.

Tasks:

- Append immediate-import summary data to the existing upload report when the new mode runs.
- Include counts for:
  - routed post files
  - imported/applied files
  - skipped files
  - errors
- Include per-file import results alongside or beneath the existing routing details.
- Update admin notice copy so successful upload-plus-import runs are distinguishable from plain upload-only runs.

Checklist:

- [x] Upload report stores immediate-import mode metadata.
- [x] Upload report includes per-file import results.
- [x] Admin notice copy distinguishes upload-only vs upload-plus-import outcomes.
- [x] Failure states remain readable when routing succeeds but import partially fails.

### 7. Logging and activity visibility

This phase should be observable in the same way the rest of the legacy import flow is observable.

Tasks:

- Add upload log entries for immediate-import mode enabled/disabled.
- Add import log entries for targeted upload-driven imports.
- Include enough context to troubleshoot:
  - upload mode
  - smart import on/off
  - file count
  - applied/skipped/error totals
- If appropriate, log a lightweight activity row or snapshot note indicating the import source was `upload_immediate`.

Checklist:

- [x] Upload logging covers the immediate-import branch.
- [x] Import logging covers targeted file imports.
- [x] Result context is sufficient to troubleshoot partial failures.
- [ ] Activity/source labeling is documented and implemented if chosen.

### 8. Validation plan

Manual QA should prove the new branch is narrowly scoped and does not regress the default upload/import flows.

Required scenarios:

1. Standard single JSON upload with the new toggle off.
   - Expect current sync-folder wipe behavior.
   - Expect no immediate import.
2. Single post JSON upload with the new toggle on.
   - Expect no sync-folder wipe.
   - Expect only that uploaded post file to be imported.
3. Multi-post JSON upload with the new toggle on.
   - Expect no sync-folder wipe.
   - Expect only the uploaded post JSON files in that request to be imported.
4. Mixed JSON upload that includes post + non-post scenarios.
   - Expect routing for all supported files.
   - Expect only post scenario files to auto-import.
5. Dry-run JSON upload with the new toggle on.
   - Expect routing analysis only.
   - Expect no sync-folder writes and no import.
6. ZIP upload with the new toggle on.
   - Expect current ZIP handling only.
   - Expect no immediate import branch.
7. Immediate import with smart import enabled.
   - Expect unchanged files to skip by `_dbvc_import_hash`.
8. Immediate import with UID matching enabled.
   - Expect uploaded JSON with `vf_object_uid` to match the intended local entity when available.

Checklist:

- [ ] Default upload flow regression-tested.
- [ ] Single-file immediate import verified.
- [ ] Multi-file immediate import verified.
- [ ] Mixed-scenario routing verified.
- [ ] Dry-run and ZIP guardrails verified.
- [ ] Smart-import behavior verified in the new path.
- [ ] UID-matching behavior verified in the new path.

## Suggested Task Order

1. Update upload form controls.
2. Parse flags and gate the handler branch.
3. Skip sync-folder wipe only for the new mode.
4. Add targeted import helper.
5. Wire router results into targeted import execution.
6. Extend reporting and notices.
7. Add logging.
8. Run manual QA and record outcomes.

## Progress Tracker

### Status

- Phase state: Implemented, pending manual QA
- Delivery scope: Legacy upload area only
- Risk level: Low to medium

### Master Checklist

- [x] Form controls defined and documented.
- [x] Upload handler flags added with JSON-only guardrails.
- [x] Sync-folder wipe bypassed only for immediate-import JSON requests.
- [x] Routed post-file collection implemented.
- [x] Targeted import helper implemented.
- [x] Smart-import control wired into targeted import and legacy UID matching preserved.
- [x] Upload report and notices extended for import outcomes.
- [x] Logging/activity coverage added.
- [ ] Manual QA completed across the required scenarios.

## Tradeoffs / Notes

- This phase intentionally does not auto-import terms, menus, or options. That keeps the new behavior aligned with the "individual post file(s)" workflow and limits regression risk.
- The immediate-import path should not call `import_all()`. Doing so would reintroduce the current sync-folder-wide behavior and defeat the point of the enhancement.
- The existing legacy smart-import hash only compares `post_content + meta`, not every imported field. That limitation remains unless a later phase expands the hash contract.

## Follow-up Candidates

- Add a WP-CLI equivalent for importing an explicit list of routed JSON paths.
- Add an optional "import routed term JSON too" mode if the targeted workflow proves useful beyond posts.
- Revisit the smart-import hash contract so title, excerpt, slug, status, and taxonomy-only changes are not skipped.
