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

## 7) Known current limitation

- Full replace modal does not currently show preflight delete-count preview before apply; only post-operation counts are returned.
