# ENTITY_EDITOR_MANUAL_QA.md

Manual QA checklist for Entity Editor security and behavior validation.

---

## Preconditions

- Have at least one post JSON and one term JSON in the sync folder.
- Have one secondary wp-admin user for lock/capability checks.
- Know one test post/term ID and its `vf_object_uid`.

---

## 1) Capability + Nonce

- Login as a non-admin user and open Entity Editor.
  - Expected: route/API calls are blocked by capability checks.
- In browser devtools, replay an Entity Editor API request without `X-WP-Nonce`.
  - Expected: request fails (REST nonce/cookie auth gate).
- Replay request with invalid nonce.
  - Expected: request fails closed.

---

## 2) Path Safety + Data Exposure

- Call file-load API with `path=../wp-config.php`.
  - Expected: rejected as invalid path.
- Call file-load API with absolute path outside sync root.
  - Expected: rejected as path escape.
- Inspect Entity Editor UI metadata.
  - Expected: only relative file paths are shown, no absolute server paths.

---

## 3) Save JSON Only

- Open an entity file, change only formatting, click `Save JSON`.
- Verify JSON file changed and backup exists in `.dbvc_entity_editor_backups/`.
- Verify DB entity fields/meta did not change.

---

## 4) Locking

- User A opens an entity file.
- User B opens same file.
  - Expected: lock conflict warning with owner details.
- User B clicks takeover and retries save/import.
  - Expected: lock transfer succeeds and operation proceeds.

---

## 5) Partial Import (Non-destructive)

- Add/update a subset of fields/meta keys in JSON.
- Click `Save + Partial Import`.
- Verify only JSON-present core fields changed.
- Verify only JSON-present meta keys changed.
- Verify meta keys absent from JSON are preserved.
- Verify operation blocks with actionable message on zero/ambiguous match.
- With `dbvc_allow_uid_fallback_matching` disabled, change the JSON `vf_object_uid` to a non-matching value while keeping the same slug.
  - Expected: operation blocks instead of falling back to slug.
- Enable `dbvc_allow_uid_fallback_matching` only in a disposable test environment and repeat the slug case.
  - Expected: fallback is allowed and the local entity UID is aligned to the incoming UID.

---

## 6) Full Replace (Destructive)

- Click `Save + Full Replace`.
- In modal, do not type `REPLACE`.
  - Expected: blocked.
- Type `REPLACE` and confirm.
- Verify:
  - non-protected meta keys absent from JSON were deleted.
  - protected keys were preserved unless explicitly in JSON.
  - snapshot artifact exists in `.dbvc_entity_editor_backups/`.
  - operation log includes counts + backup/snapshot references.

---

## 7) Sync File Import

- Click the Entity index `Import status` header.
  - Expected: unimported rows sort to the top on ascending sort, imported rows show an `Imported` badge, and unmatched rows show `Not imported`.
- Drop one unmatched post/CPT JSON into the sync folder, rebuild/open Entity Editor, and verify the row shows `Import as New`.
- Preview the file.
  - Expected: modal shows entity kind, subtype, title, slug, UID, source path, `create` action, and no blocking reasons.
- Commit the import.
  - Expected: WP entity is created, result shows the created entity link, index refreshes, and only one final canonical JSON file remains.
- Repeat with one unmatched `taxonomy/{taxonomy}/...json` term file.
  - Expected: term is created, incoming UID/meta are preserved, and the JSON is normalized to the local `term_id` filename when filename mode includes IDs.
- Preview a matched file.
  - Expected: create-only is blocked with `matched_entity`; UID-matched post/CPT files show a matched-update panel.
- Check the matched-update `I confirm` checkbox.
  - Expected: `Update Matched Entity` becomes clickable and identifies the matched WP entity.
- Try matched update without checking the confirmation box, or after refreshing the preview without rechecking.
  - Expected: server blocks the update and the live WP entity remains unchanged.
- Commit a confirmed UID-matched post/CPT update.
  - Expected: JSON-present post fields/meta/taxonomies update on the matched WP entity, the modal reports `updated`, and the sync JSON is normalized to the local canonical filename when needed.
- Preview a slug-only matched file.
  - Expected: create is blocked and matched update is not offered.
- Preview an older duplicate file when a duplicate group exists.
  - Expected: stale duplicate is blocked and points to the canonical row.
- Preview invalid, unsupported, or excluded JSON.
  - Expected: modal shows a blocking reason and disables commit.
- Run a mixed bulk preview with one creatable file and one blocked file.
  - Expected: commit creates only the eligible entity and preserves the blocked result in the modal.

---

## 8) Known current limitation

- Full replace modal does not currently show preflight delete-count preview before apply; only post-operation counts are returned.
- Sync-file import browser QA should use disposable JSON because successful commits create live WP entities.
