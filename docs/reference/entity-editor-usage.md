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
- Full replace confirmation modal does not currently include preflight delete-count preview; counts are returned after operation.
- CodeMirror JSON editor/linting is not yet integrated; editor currently uses textarea.

## Related Docs

- `docs/reference/import-identity-matching.md`
- `docs/implementation/completed/entity-editor-sync-file-import-guide.md`
