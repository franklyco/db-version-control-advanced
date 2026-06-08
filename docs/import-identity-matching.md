# Import Identity Matching

This document is the current contract for how DBVC matches imported post/CPT and term JSON to local WordPress entities.

## Stable Identity

- DBVC uses `vf_object_uid` as the stable cross-environment entity identity.
- Exporters write the UID at the top level and into entity meta/history where supported.
- Importers treat a non-empty incoming UID as authoritative. They do not replace it with a local destination UID during import, normalization, upload routing, or Entity Editor save/import flows.

## Default Matching Policy

When incoming JSON contains `vf_object_uid`:

1. DBVC first attempts a UID match against the entity registry and object meta.
2. If a UID match is found, the matched local entity is updated and its UID/meta/history are aligned to the incoming UID.
3. If no UID match is found and UID fallback matching is disabled, DBVC must not fall back to local numeric IDs, slugs, or `entity_refs`.
4. New entity creation can still proceed through explicit new-entity/create paths when the relevant import settings and review decisions allow it.

When incoming JSON has no UID:

1. DBVC may use the legacy identity fallbacks available to that flow.
2. Typical fallbacks are numeric ID, slug plus subtype, taxonomy slug, taxonomy ID, or `entity_refs`.
3. If the entity is created or safely matched, DBVC ensures a UID exists and writes it back into the local entity metadata.

## UID Fallback Toggle

Option: `dbvc_allow_uid_fallback_matching`

Default: disabled.

When disabled, UID-bearing JSON with an unmatched UID is treated as an unmatched identity and will not be applied to a different local entity by ID or slug. This is the expected setting for staging/production synchronization.

When enabled, DBVC allows legacy fallback matching even when the incoming UID is present but not found locally. Enable it only for intentional legacy imports where local UID records are missing and the operator accepts the ID/slug collision risk.

The option is available in:

- **DBVC Export -> Configure -> Import Defaults**
- the legacy import form
- configuration portability under the `core_import_export` import defaults group

## Flow-Specific Notes

- **Legacy post import:** `DBVC_Sync_Posts::import_post_from_json()` reads UID from top-level `vf_object_uid`, `dbvc_object_uid`, `meta.vf_object_uid`, or `dbvc_post_history`.
- **Legacy term import:** `DBVC_Sync_Taxonomies::import_term_json_file()` reads UID from top-level, meta, or `dbvc_term_history`, and resolves local terms by UID before any slug fallback.
- **Proposal term imports:** `DBVC_Sync_Posts::identify_local_term()` uses UID first. If the UID is unmatched and fallback is disabled, taxonomy slug/ID and `entity_refs` are not used for that UID-bearing item.
- **Entity Editor:** partial and full replace use the same UID-first policy. With fallback disabled, an unmatched incoming UID blocks slug fallback.
- **Upload routing/normalization:** incoming UIDs are preserved. Local slug-matched UIDs are used only when the incoming JSON has no UID.

## Validation Anchors

Primary regression coverage:

- `tests/phpunit/CoreImportUidPreservationTest.php`
- `tests/phpunit/EntityEditorEndpointsTest.php`

Useful focused filter:

```bash
vendor/bin/phpunit --filter 'CoreImportUidPreservationTest|test_full_replace_preserves_incoming_uid_when_slug_fallback_matches|test_full_replace_blocks_slug_fallback_when_uid_is_unmatched_and_fallback_disabled'
```
