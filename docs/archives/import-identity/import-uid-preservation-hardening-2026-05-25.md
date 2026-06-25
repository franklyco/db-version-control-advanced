# Import UID Preservation + Fallback Hardening Archive

Date archived: 2026-05-25

This note archives the implementation pass that corrected DBVC import behavior around `vf_object_uid` preservation and added an opt-in fallback toggle.

## Problem

Core import paths could match a destination entity by ID or slug, then rewrite the imported JSON/entity metadata with the destination site's local `vf_object_uid`. That broke exact cross-site matching for staging/production synchronization because the source UID was lost after import.

## Resolution

- Incoming non-empty `vf_object_uid` values are now authoritative.
- Post and term import paths read UID values from top-level payload fields, `meta.vf_object_uid`, and DBVC history metadata.
- Import/export rewrite paths align top-level UID, meta UID, and history UID to the authoritative incoming UID.
- Entity Editor partial/full import stamps matched posts/terms with the incoming UID before auto-export.
- A new `dbvc_allow_uid_fallback_matching` option controls the legacy fallback pass.

## Final Policy

- Default: fallback disabled.
- UID-bearing JSON must match by UID before it can update an existing local entity.
- If the UID is not found and fallback is disabled, DBVC does not fall back to ID, slug, or reference matching for that item.
- Operators can enable fallback only for intentional legacy JSON imports where local UID records are missing.

Current behavior is documented in `docs/reference/import-identity-matching.md`.

## Validation Recorded

- PHP syntax checks passed for touched PHP files.
- Focused PHPUnit passed:
  - `CoreImportUidPreservationTest`
  - `test_full_replace_preserves_incoming_uid_when_slug_fallback_matches`
  - `test_full_replace_blocks_slug_fallback_when_uid_is_unmatched_and_fallback_disabled`
- `git diff --check` passed.

Known unrelated test noise at the time:

- vendor deprecation warnings from `plugin-update-checker`
- existing `sync/options.json` unsafe path log line in the PHPUnit environment
