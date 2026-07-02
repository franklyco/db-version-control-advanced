# ENTITY_EDITOR_USAGE.md

Brief usage notes for the DBVC Entity Editor.

---

## Scope

- Supports JSON entities for posts and terms.
- Excludes media/attachments, menus/nav menu items, options.

---

## Actions

- `Entity index`
  - Lists indexed post/CPT and taxonomy term JSON files from the sync folder.
  - The `Import status` column is sortable; click it once to bring unimported/unmatched files to the top.
  - Rows with no matched WordPress entity are labeled `Not imported` and can show `Import as New` when the payload is eligible.

- `New From Raw JSON`
  - Opens a dedicated intake modal from the Entity Editor toolbar.
  - Accepts one DBVC post/CPT or term JSON payload.
  - Previews detected kind, subtype, target sync path, live match state, warnings, and blocking reasons before commit.
  - Uses the same blocker detail and settings-link guidance as staged sync-file import for configuration, existing-entity, unsupported-type, and file-collision blockers.
  - Supports `Create only`, `Create or Update Matched`, and `Stage JSON Only`.
  - After successful create/update, returns the final canonical sync file path and does not leave both source-ID and local-ID JSON files active in the index.
  - Can auto-open the resulting sync file in the normal Entity Editor modal after success.

- `Import as New`
  - Appears on unmatched post/CPT and term rows that already exist as JSON files in the sync folder.
  - Opens a preview modal before any database write.
  - Creates selected live WordPress entities through the existing DBVC post or term importer when preflight passes.
  - Supports up to 25 selected files per request.
  - Blocks live matches, creation-disabled settings, unsupported payloads, and older duplicate sync files.
  - Uses the same blocker detail and settings-link guidance as raw JSON intake, with additional inline fixes for safe sync-file blockers.
  - Renames the imported source JSON to the final canonical filename after the new local ID is known.
  - Archives redundant same-entity duplicate JSON files into `.dbvc_entity_editor_backups` after successful canonicalization.
  - Keeps the result visible after commit and refreshes the Entity Editor index.

- `Update Matched Entity`
  - Appears inside the `Import Sync JSON` modal only for high-confidence UID-matched post/CPT sync files.
  - Requires checking `I confirm updating this matched WordPress entity from the selected JSON` before the update button is enabled.
  - Applies JSON-present core fields, meta, and taxonomies through the existing DBVC post importer.
  - Re-validates the preview hash and matched WP entity ID on the server before writing.
  - Rewrites source-site IDs to the matched local post ID and normalizes the sync JSON to the local canonical filename when needed.
  - Does not appear for slug-only matches, payload-ID-only matches, stale duplicate files, taxonomy terms, or hard blockers.

- `Save JSON`
  - Validates JSON.
  - Creates backup.
  - Atomically replaces sync file.
  - Does not update WP DB.

- `Save + Partial Import`
  - Saves JSON first.
  - Matches one local entity by UID/history first. If JSON contains a UID and fallback matching is disabled, an unmatched UID blocks slug fallback.
  - Falls back to slug+subtype only when the JSON has no UID or the `dbvc_allow_uid_fallback_matching` option is explicitly enabled.
  - Updates only JSON-present core fields/meta/taxonomies.
  - Does not delete missing meta keys.

- `Merge Incoming JSON`
  - Appears inside the selected entity JSON editor modal.
  - Lets the operator paste one incoming DBVC post/CPT or term JSON payload and preview a proposed merge into the selected file.
  - Keeps the matched local WordPress entity as the authority for ID and, by default, UID and slug, even when the selected sync JSON has drifted to a source-site UID.
  - Includes simple controls for UID, slug, and title policy; post/term ID remains local-only.
  - Shows blockers, soft notes, and proposed merged JSON before any write.
  - Requires `I confirm merging this incoming JSON into the selected entity file` before save actions enable.
  - Supports `Save Merged JSON` and `Save Merged JSON + Partial Import`.
  - Reuses existing Entity Editor save, backup, lock-token, and partial-import paths.
  - For Bricks templates, preserves local template type/condition/preview reference values when incoming values differ and surfaces notes instead of attempting element-level merging.

- `Save + Full Replace`
  - Saves JSON first.
  - Requires typed `REPLACE` confirmation in modal.
  - Deletes non-protected meta keys not present in JSON.
  - Creates pre-replace snapshot artifact and logs operation counts.

---

## Locking

- Opening a file acquires a transient lock.
- Save/import requires lock token.
- UI offers takeover flow if another user holds the lock.

---

## Known limitations

- `Import as New` supports post/CPT and taxonomy term JSON only; media, menu, and option JSON are intentionally excluded.
- Sync-file `Update Matched Entity` is post/CPT-only in the first slice; term updates remain deferred until term update semantics are separately audited.
- `Merge Incoming JSON` v1 does not provide field-by-field accept/reject decisions, destructive full-replace merge, media hydration, or selective Bricks element-level merging.
- Full replace confirmation modal does not currently include preflight delete-count preview; counts are returned after operation.
- CodeMirror JSON editor/linting is not yet integrated; editor currently uses textarea.

## Related Docs

- `docs/reference/import-identity-matching.md`
- `docs/implementation/completed/entity-editor-sync-file-import-guide.md`
- `docs/implementation/completed/entity-editor-merge-incoming-json-guide.md`
- `docs/implementation/completed/entity-editor-enhancements.md`
