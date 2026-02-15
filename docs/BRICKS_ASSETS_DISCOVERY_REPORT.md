# Bricks Assets Discovery Report (DBVC Add-on Update)

Date: 2026-02-12  
Scope: Discovery Update only (no feature implementation)

## 1) Architecture Decision (Updated)

Per `docs/DBVC_BRICKS_ADDON_HANDOFF.md`, DBVC is now the system of record for:
- Golden Master package publishing
- Drift detection
- Governance/policy
- Proposal pipeline

This replaces the earlier VF-centered integration plan. Bricks will be a first-class DBVC Add-on under Configure UI.

## 2) Current DBVC Structure Relevant to Bricks Add-on

### 2.1 Admin UI shell and tab system
- Main admin screen renders in `admin/admin-page.php` (`dbvc_render_export_page`).
- Top-level tabs are built from `$main_tabs` in `admin/admin-page.php:1365`.
- Configure subtabs are built from `$config_subtabs` in `admin/admin-page.php:1382` and rendered at `admin/admin-page.php:2238`.
- Nested subtabs pattern already exists (Import Defaults) at `admin/admin-page.php:2417`.

Finding:
- There is no current generic Add-on registry or Add-ons subtab.
- Pattern to extend is clear: add another Configure subtab and optionally nested subtabs using the same `data-dbvc-subtabs` structure.

### 2.2 Configure settings save/sanitization pattern
- Single save handler in `admin/admin-page.php:355` (checks nonce/capability once, then section-specific saves).
- Pattern uses direct `update_option(...)` with explicit sanitizers:
  - `sanitize_key`, `sanitize_text_field`, `sanitize_textarea_field`, `absint`, and allowlists.
- Section save buttons use names like `dbvc_config_save[section]`.

Finding:
- Bricks Add-on settings should follow this exact pattern (same nonce, same section routing style, same sanitize + whitelist strategy).

### 2.3 Logging/debug patterns
- File logging helper: `includes/class-sync-logger.php` (`DBVC_Sync_Logger` with scoped methods `log_import`, `log_media`, etc.).
- Structured DB log: `DBVC_Database::log_activity(...)` in `includes/class-database.php:846`.
- Existing import/export/apply flows call both logging channels (e.g., `includes/class-sync-posts.php`, `admin/class-admin-app.php`).

Finding:
- Bricks Add-on should emit both file logs and structured activity logs for operations (drift scans, applies, proposal submits, approvals).

### 2.4 Extension points and module-like hooks
- DBVC already exposes many filters/actions (`apply_filters`/`do_action`) across export/import (`includes/class-sync-posts.php`, `includes/hooks.php`, `includes/class-sync-taxonomies.php`).
- There is no `dbvc_addons` registration API today.

Finding:
- MVP add-on bootstrap can be loaded from plugin bootstrap with explicit `require_once` and then register its own hooks/REST/admin integration.
- Longer-term improvement: introduce lightweight add-on registrar, but not required for discovery phase.

## 3) Re-Audit of Existing Engine Capabilities

### 3.1 Hashing / fingerprinting / canonicalization
- Canonical helpers:
  - `dbvc_normalize_for_json(...)` in `includes/functions.php:794`
  - `dbvc_sort_array_recursive(...)` in `includes/functions.php:830`
- Export hash:
  - `hash('sha256', $json_content)` in `includes/class-sync-posts.php:3132`
- Manifest hashes/checksum:
  - item hash `hash('sha256', $raw)` in `includes/class-backup-manager.php:259`
  - manifest checksum `hash('sha256', wp_json_encode($manifest))` in `includes/class-backup-manager.php:374`
- Import-state hash:
  - `_dbvc_import_hash` MD5 pattern in `includes/class-sync-posts.php:2869`

Finding:
- DBVC has reusable hash/canonical primitives, but semantics are split (SHA-256 artifact integrity vs MD5 import-state tracking).
- Bricks Add-on should define one external fingerprint contract (`sha256:<hex>`) and keep `_dbvc_import_hash` compatibility internal.

### 3.2 Artifact export/import primitives
- Entity export/import:
  - posts/CPT: `DBVC_Sync_Posts` (`prepare_post_export`, `export_post_to_json`, `import_post_from_json`)
  - terms: `DBVC_Sync_Taxonomies` (`export_term`, `import_term_from_file`)
- Options export/import:
  - `export_options_to_json`, `import_options_from_json` in `includes/class-sync-posts.php`
- Upload router/scenarios:
  - `includes/class-import-router.php`
  - `includes/import-scenarios/*.php`

Finding:
- Bricks Add-on can reuse these as the baseline artifact transport layer.

### 3.3 Packages/manifests/restore/history
- Package manifest creation/read:
  - `DBVC_Backup_Manager::generate_manifest/read_manifest` (`includes/class-backup-manager.php`)
- Restore/apply:
  - `DBVC_Sync_Posts::import_backup(...)` (`includes/class-sync-posts.php:1020`)
- Restore-point-like backup:
  - `DBVC_Sync_Posts::dbvc_create_backup_folder_and_copy_exports()` (`includes/class-sync-posts.php:838`)
- Snapshot history tables and APIs:
  - `dbvc_snapshots`, `dbvc_snapshot_items` in `includes/class-database.php`
- Official packaged collections:
  - `Dbvc\Official\Collections::mark_official(...)` in `includes/Dbvc/Official/Collections.php`

Finding:
- DBVC already has most package/history primitives needed for Golden Master and rollback workflows.

### 3.4 Entity abstraction and UID strategy
- In DBVC, posts/terms are represented as Entities via:
  - `dbvc_entities` table and helpers in `includes/class-database.php:447`
  - post UID lifecycle in `includes/class-sync-posts.php:148`
  - term UID lifecycle in `includes/class-sync-taxonomies.php:392`
- Save hook ensures post UID assignment (`db-version-control.php:83`).

Finding:
- `bricks_template` should be treated as Entity-backed artifact and reuse `vf_object_uid` + `dbvc_entities` mapping.

## 4) Add-on Gaps vs Reuse

### 4.1 What can be reused directly
- UI tab/subtab rendering and save patterns in `admin/admin-page.php`.
- REST registration pattern in `admin/class-admin-app.php`.
- Logging channels (`DBVC_Sync_Logger` + `DBVC_Database::log_activity`).
- Entity UID lifecycle and registry (`vf_object_uid`, `dbvc_entities`).
- Manifest/snapshot/apply primitives (`DBVC_Backup_Manager`, `DBVC_Sync_Posts`, `DBVC_Snapshot_Manager`, `DBVC_Database`, `Dbvc\Official\Collections`).

### 4.2 What must be added for Bricks Add-on
- Add-on bootstrap and UI insertion under Configure with Bricks-specific subtabs:
  - Connection, Golden Source, Policies, Operations, Proposals.
- Bricks artifact adapter layer:
  - `bricks_template` Entity extraction/apply
  - targeted options extraction for `bricks_global_classes`, `bricks_global_variables`.
- Drift scanner using canonical + fingerprint compare against last applied golden.
- Governance policy model and per-artifact status transitions.
- Mothership proposal + package endpoints (server/client roles).
- Proposal queue persistence if existing DBVC tables are insufficient for status lifecycle.

## 5) Storage Strategy Recommendation (Minimal New Storage)

### Reuse first
- Reuse existing package/snapshot/history infrastructure (`dbvc_snapshots`, `dbvc_snapshot_items`, manifests, official collections).
- Reuse Entity mapping (`dbvc_entities`) for Entity-backed Bricks artifacts.

### Add only if required
Introduce add-on-specific tables only for data not represented today:
- proposal lifecycle queue (status transitions + review metadata)
- per-artifact governance state (policy overrides, last golden hash/version applied, status)

Notes:
- Existing `dbvc_collections`/`dbvc_collection_items` is useful for approved packages but does not currently model proposal review workflow lifecycle.

## 6) UID Strategy for Bricks Add-on (DBVC Ownership)

### Entity-backed artifacts (`bricks_template`)
- Use `vf_object_uid` as canonical UID.
- Reuse existing `ensure_post_uid(...)` and `dbvc_entities` upsert flow.

### Options-backed artifacts (`bricks_global_*`)
- No native `vf_object_uid` in options.
- Recommended stable UID derivation for artifact-level identity:
  - `asset_uid = "option:" . <option_key>` for singleton option artifacts.
- If future split by nested item is required, derive deterministic child IDs from canonical payload keys (e.g., `option:<key>:<stable-subkey>`), but keep MVP at option-level artifacts.

## 7) Risks / Unknowns to Validate in Live Bricks Environment

1. Exact option keys vary by Bricks version.
- Need live validation for MVP keys and future keys (beyond classes/variables).

2. Noisy fields in Bricks JSON/meta.
- Need canonicalization rule list for generated IDs/timestamps/non-deterministic structures.

3. Performance for large template payloads.
- Admin diff flattening can be expensive for deep Bricks trees.

4. Compatibility with legacy manifests/snapshots.
- Must preserve current schema compatibility while adding Bricks package metadata.

5. Configure IA mismatch.
- Current Configure UI has no “General Settings > Add-ons” layer; this must be introduced using existing tab/subtab patterns without breaking existing sections.

## 8) Discovery Update Conclusion

DBVC already has the core engine primitives and Entity identity system needed to host Bricks Golden Master, drift detection, governance, and proposal workflows. Primary work is additive: add-on registration/UI scaffolding, Bricks artifact adapter/canonicalization rules, and proposal lifecycle endpoints/storage.

No replacement of core DBVC engine components is recommended.

## DBVC Inventory Findings & Architecture Decision

This section summarizes findings from:
- `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/DBVC_ENGINE_INVENTORY.md`
- `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/DBVC_BRICKS_ADDON_VS_FORK_RECOMMENDATION.md`

### Key inventory findings
- DBVC already contains core engines needed for Bricks Golden Master workflows:
  - Entity export/import
  - canonicalization helpers
  - hash/fingerprint primitives
  - manifest/package generation
  - restore/apply flows
  - snapshot/history/audit tables
  - Entity UID registry and resolution
- No formal dynamic add-on registry is present yet; extension currently relies on direct bootstrap wiring + WordPress hooks/filters.
- Configure UI and REST surfaces are robust but coupling-sensitive due to monolithic classes.

### Architecture decision summary
- Preferred architecture: **DBVC Add-on module**.
- Reason: required Bricks capabilities are mostly additive on top of existing engines; forking would still require extracting most of the same core and introduces long-term divergence/security patch lag.
- If a fork is chosen for organizational reasons, namespace/prefix and storage isolation must be planned up front to avoid collision with DBVC.

## Bricks Add-on Field Matrix + Missing Task Inventory

Concrete matrix source:
- `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/addons/bricks/docs/BRICKS_ADDON_FIELD_MATRIX.md`
- `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/addons/bricks/docs/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST.md`

Highlights captured there:
- full artifact registry (Entity-backed and option-backed Bricks artifacts),
- per-field UI configuration contract (keys, types, defaults, validation),
- canonicalization/fingerprint rules by artifact type,
- required REST surface and endpoint guardrails,
- missing implementation tasks/sub-tasks grouped by:
  - data contract hardening,
  - safety rails,
  - governance pipeline details,
  - performance controls,
  - test/QA coverage.

This matrix should be treated as the implementation rails document for the Bricks Add-on.

Add-on activation architecture (updated):
- Add-ons are configured in core `Configure -> Add-ons`.
- Bricks add-on has enable/disable toggle (`dbvc_addon_bricks_enabled`).
- Bricks submenu is registered under DBVC wp-admin menu only when enabled.
- Disabled state must suppress Bricks routes/hooks/jobs to avoid accidental execution.
