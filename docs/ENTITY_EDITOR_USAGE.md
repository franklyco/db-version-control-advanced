# ENTITY_EDITOR_USAGE.md

Brief usage notes for the DBVC Entity Editor.

---

## Scope

- Supports JSON entities for posts and terms.
- Excludes media/attachments, menus/nav menu items, options.

---

## Actions

- `New From Raw JSON`
  - Opens a dedicated intake modal from the Entity Editor toolbar.
  - Accepts one DBVC post/CPT or term JSON payload.
  - Previews detected kind, subtype, target sync path, live match state, warnings, and blocking reasons before commit.
  - Supports `Create only`, `Create or Update Matched`, and `Stage JSON Only`.
  - Can auto-open the resulting sync file in the normal Entity Editor modal after success.

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

- Full replace confirmation modal does not currently include preflight delete-count preview; counts are returned after operation.
- CodeMirror JSON editor/linting is not yet integrated; editor currently uses textarea.

## Related Docs

- `docs/import-identity-matching.md`
