# DBVC Engine Inventory

Date: 2026-02-12  
Scope: Inventory + decision support only (no implementation)

## 1) Repo Map (High Level)

| Path | Purpose |
|---|---|
| `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/db-version-control.php` | Plugin bootstrap, dependency loading, activation hook, core action wiring. |
| `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/` | Core engines: export/import, database, manifests, snapshots, media, Entity sync, hooks. |
| `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/Media/` | Media resolver/bundle/reconcile subsystem. |
| `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/Official/` | Official collections persistence for approved package-like releases. |
| `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/import-scenarios/` | Upload-router scenario handlers for post/term/options/menu JSON routing. |
| `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/` | WP Admin UI (`admin-page.php`) and React REST/API host (`class-admin-app.php`). |
| `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/commands/` | WP-CLI surfaces for export/import/proposals/resolver workflows. |
| `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/build/` | Compiled admin React assets. |
| `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/docs/` | Project architecture, handoffs, and discovery docs. |
| `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/addons/bricks/` | Bricks docs scaffold currently present; no active runtime add-on module loader found. |

## 2) Engine / Capability Inventory

### Legend
- Entity = DBVC term for post/term-backed objects.

| Subsystem | Purpose | Primary symbols | Paths | Key public methods | Data shapes | Storage touchpoints | Hooks/filters |
|---|---|---|---|---|---|---|---|
| Artifact export/import core (Entities + options + menus) | Export/import JSON for posts/CPTs (Entities), terms (Entities), options, menus, FSE payloads | `DBVC_Sync_Posts`, `DBVC_Sync_Taxonomies` | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-sync-posts.php`, `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-sync-taxonomies.php` | `export_post_to_json($post_id,$post,...)`, `import_post_from_json(...)`, `export_options_to_json()`, `import_options_from_json()`, `export_menus_to_json()`, `import_menus_from_json()`, `export_selected_taxonomies()`, `import_taxonomies(...)` | Per-Entity JSON payloads with `vf_object_uid`, `meta`, `tax_input`; `options.json`; taxonomy JSON | Sync filesystem (`dbvc_get_sync_path(...)`); WP posts/terms/options/meta | `dbvc_export_post_data`, `dbvc_export_options_data`, `dbvc_export_term_data`, `dbvc_after_export_*`, `dbvc_after_import_*` |
| Import router / upload staging | Route uploaded JSON payloads into sync structure by scenario | `DBVC_Import_Router`, `DBVC_Import_Scenario_*` | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-import-router.php`, `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/import-scenarios/*.php` | `route_uploaded_json(array $files,array $context=[])`, `determine_post_filename(...)`, `determine_term_filename(...)` | Routed payload result objects `{status,message,output_path}` | Sync folders, uploaded temp files | Filename format filters (`dbvc_allowed_export_filename_formats`, `dbvc_allowed_taxonomy_filename_formats`) |
| Canonicalization / normalization | Normalize payloads before JSON write/diff (UTF-8 + recursive sort helpers) | `dbvc_normalize_for_json`, `dbvc_sort_array_recursive`, `dbvc_sanitize_post_meta_safe` | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/functions.php` | function helpers | Nested arrays/objects, sanitized meta arrays | In-memory transform before file/database writes | `dbvc_meta_sanitize_skip_keys` |
| Hashing / fingerprinting | Compute content/file/checksum hashes for imports/manifests/diffs | `hash('sha256',...)`, `_dbvc_import_hash`, MD5 serialized payload hashes | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-sync-posts.php`, `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-backup-manager.php` | `store_import_hash($post_id,$content_hash)`; implicit hash generation in export/import/manifest flows | SHA-256 file hashes, MD5 import-state hashes | Post meta `_dbvc_import_hash`; manifest `items[].hash`, `items[].content_hash`, `manifest.checksum` | None centralized; spread across flows |
| Diff engine (review UI) | Compare current vs proposed payload snapshots and produce field-level diff summaries | `DBVC_Admin_App::compare_snapshots`, `summarize_entity_diff_counts`, `evaluate_entity_diff_state` | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/class-admin-app.php` | `compare_snapshots(array $current,array $proposed): array` (private), `get_proposal_entity(...)`, `get_proposal_entities(...)` | Diff object `{changes:[{path,label,section,from,to}],total}` | Proposal decision option store, snapshot files, manifest files | `dbvc_diff_ignore_paths` option + internal ignore parsing |
| Packaging / manifest engine | Build/read proposal/backup manifests and optional entities index | `DBVC_Backup_Manager` | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-backup-manager.php` | `generate_manifest($backup_path)`, `read_manifest($backup_path)`, `list_backups()`, `download_backup($folder)` | `manifest.json` schema 3, `items[]`, `media_index[]`, optional `entities.jsonl` | Backup folders under uploads `sync/db-version-control-backups`; manifest files | None explicit for manifest schema; logging hooks via `DBVC_Sync_Logger` calls |
| Restore points / rollback apply | Apply backup/proposal packages and reconcile media; partial/full/copy modes | `DBVC_Sync_Posts::import_backup`, `dbvc_create_backup_folder_and_copy_exports` | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-sync-posts.php` | `import_backup($backup_name,array $options=[])`, `copy_backup_to_sync(...)` | Apply result arrays `{imported,skipped,errors,media,...}` | Backup folders, sync folders, WP post/term/options/meta writes | `dbvc_import_backup_override` filter in admin apply flow |
| History / audit trail | Track snapshots/jobs/activity and optional official collections | `DBVC_Database`, `Dbvc\Official\Collections` | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-database.php`, `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/Official/Collections.php` | `insert_snapshot(...)`, `insert_snapshot_items(...)`, `create_job(...)`, `update_job(...)`, `log_activity(...)`, `Collections::mark_official(...)` | Snapshot rows/items, job contexts, activity events, collection metadata/items | Tables: `dbvc_snapshots`, `dbvc_snapshot_items`, `dbvc_jobs`, `dbvc_activity_log`, `dbvc_collections`, `dbvc_collection_items` | None for schema API; called directly by engines |
| Entity abstraction (posts/terms) | Stable Entity identity + object mapping across environments | `ensure_post_uid`, `ensure_term_uid`, `upsert_entity`, `get_entity_by_uid` | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-sync-posts.php`, `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-sync-taxonomies.php`, `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-database.php` | `ensure_post_uid($post_id,...)`, `ensure_term_uid($term_id,...)`, `resolve_local_post_id(...)`, `upsert_entity(...)` | Entity IDs (`vf_object_uid`) + local object refs | Post meta `vf_object_uid`; term meta `vf_object_uid`; table `dbvc_entities` | `dbvc_supported_post_types` influences Entity inclusion |
| Settings/config framework | Admin settings forms + sanitize/save logic | `dbvc_render_export_page` save handler | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-page.php` | N/A classless procedural handler | Option values and feedback arrays | Many `dbvc_*` options via `update_option` | Numerous filters used during save/export behavior |
| Add-on/module system | Dynamic add-on registration/enable-disable (if present) | **No dedicated registrar found** | N/A | N/A | N/A | N/A | Existing WordPress actions/filters can be used as extension points |
| Admin UI framework | Classic WP admin Configure tabs + React proposal review app | `admin-page.php`, `DBVC_Admin_App` | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-page.php`, `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/class-admin-app.php` | `DBVC_Admin_App::init()`, many REST handlers | Tab/subtab server-rendered forms + React REST resources | Options, manifest files, snapshot files, decision stores | React app localizes REST root + nonce |
| REST API scaffolding | Proposal review/apply/resolver/masking endpoints | `DBVC_Admin_App::register_rest_routes` | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/class-admin-app.php` | many handlers, all with `permission_callback => can_manage` | JSON REST request/response payloads | Options, DB tables, files under uploads/sync | Auth gate via `can_manage()` (`manage_options`) |
| Logging/debug utilities | File logs and structured activity logs | `DBVC_Sync_Logger`, `DBVC_Database::log_activity` | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-sync-logger.php`, `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-database.php` | `log`, `log_import`, `log_upload`, `log_media`, `heartbeat`, `log_activity` | Log lines + context JSON | File `dbvc-backup.log`; table `dbvc_activity_log` | Logging toggles from `dbvc_logging_*` options |
| Media resolver/bundle engine | Resolve attachment conflicts and bundle media transport | `DBVC_Media_Sync`, `Dbvc\Media\Resolver/Reconciler/BundleManager` | `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/class-media-sync.php`, `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/Dbvc/Media/*.php` | `sync_manifest_media(...)`, `preview_manifest_media(...)`, `handle_clear_cache_request()` | Resolver metrics/conflicts/maps, bundled file metadata | `dbvc_media_index` table, media bundle folders/options | `dbvc_media_use_legacy_sync` filter |

## 3) Public Surface Area

### 3.1 WP REST endpoints (namespace `dbvc/v1`)
Source: `DBVC_Admin_App::register_rest_routes()` in `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/class-admin-app.php:103`.

Auth model:
- All routes in this class use `permission_callback => [self::class, 'can_manage']`.
- `can_manage()` returns `current_user_can('manage_options')` (`admin/class-admin-app.php:1814`).

Endpoints:
- `GET /proposals` → `get_proposals`
- `POST /proposals/upload` → `upload_proposal`
- `DELETE /proposals/{proposal_id}` → `delete_proposal`
- `POST /fixtures/upload` → `upload_fixture`
- `GET /proposals/{proposal_id}/entities` → `get_proposal_entities`
- `GET /proposals/{proposal_id}/duplicates` → `get_proposal_duplicates`
- `POST /proposals/{proposal_id}/duplicates/cleanup` → `cleanup_proposal_duplicates`
- `GET /proposals/{proposal_id}/masking` → `get_proposal_masking`
- `POST /proposals/{proposal_id}/masking/apply` → `apply_proposal_masking`
- `POST /proposals/{proposal_id}/masking/revert` → `revert_proposal_masking`
- `GET /proposals/{proposal_id}/entities/{vf_object_uid}` → `get_proposal_entity`
- `POST /proposals/{proposal_id}/entities/{vf_object_uid}/selections` → `update_entity_decision`
- `POST /proposals/{proposal_id}/entities/{vf_object_uid}/selections/bulk` → `update_entity_decision_bulk`
- `POST /proposals/{proposal_id}/entities/accept` → `accept_entities_bulk`
- `POST /proposals/{proposal_id}/entities/unaccept` → `unaccept_entities_bulk`
- `POST /proposals/{proposal_id}/entities/unkeep` → `unkeep_entities_bulk`
- `POST /proposals/{proposal_id}/entities/{vf_object_uid}/snapshot` → `capture_entity_snapshot`
- `POST /proposals/{proposal_id}/entities/{vf_object_uid}/hash-sync` → `sync_entity_hash`
- `POST /proposals/{proposal_id}/entities/hash-sync` → `sync_entity_hash_bulk`
- `POST /proposals/{proposal_id}/snapshot` → `capture_proposal_snapshot`
- `POST /proposals/{proposal_id}/apply` → `apply_proposal`
- `GET /proposals/{proposal_id}/resolver` → `get_proposal_resolver`
- `POST /proposals/{proposal_id}/status` → `update_proposal_status`
- `POST /proposals/{proposal_id}/resolver/{original_id}` → `update_resolver_decision`
- `DELETE /proposals/{proposal_id}/resolver/{original_id}` → `delete_resolver_decision_endpoint`
- `GET /resolver-rules` → `list_resolver_rules`
- `POST /resolver-rules/bulk-delete` → `bulk_delete_resolver_rules`
- `DELETE /resolver-rules/{original_id}` → `delete_resolver_rule`
- `POST /resolver-rules` → `upsert_resolver_rule`
- `POST /resolver-rules/import` → `import_resolver_rules`
- `DELETE /maintenance/clear-proposals` → `clear_all_proposals`
- `POST /logs/client` → `log_client_error`

### 3.2 Non-REST public write surfaces
Source: `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/includes/hooks.php`.

- `admin_post_dbvc_purge_sync` → `dbvc_handle_purge_sync` (nonce checked)
- `wp_ajax_dbvc_purge_status` → inline callback
- `admin_post_dbvc_download_sync` → `DBVC_Sync_Posts::handle_download_sync`
- `admin_post_dbvc_upload_sync` → `DBVC_Sync_Posts::handle_upload_sync`
- `admin_post_dbvc_download_backup` → `DBVC_Backup_Manager::handle_download_request`
- `admin_post_dbvc_clear_media_cache` → `DBVC_Media_Sync::handle_clear_cache_request`

WP-CLI write surfaces exist in `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/commands/class-wp-cli-commands.php` (`export`, `import`, proposal apply/upload, resolver-rules mutation).

## 4) Security-Sensitive Hotspots

1. File write/extract/import paths
- Proposal ZIP ingestion and extraction (`DBVC_Admin_App::import_proposal_from_zip` path).
- Sync upload/import handlers (`DBVC_Sync_Posts::handle_upload_sync`, import router).
- Backup copy/restore operations (`DBVC_Sync_Posts::import_backup`, `copy_backup_to_sync`).

2. Remote/media retrieval
- Media resolver sync/download operations in `DBVC_Media_Sync::sync_manifest_media` and downloader path.

3. REST mutation endpoints
- High-impact mutations (`/proposals/*/apply`, `/maintenance/clear-proposals`, resolver-rule writes) gated only by `manage_options` capability.

4. Path/nonce checks are distributed
- Many nonce checks are per-handler (`class-sync-posts.php`, `class-backup-manager.php`, `class-media-sync.php`, `hooks.php`), so consistency is coupling-sensitive.

## 5) Coupling-Sensitive Hotspots

1. `DBVC_Sync_Posts` is monolithic
- High central coupling for export/import/hash/backup/apply/masking/new-Entity flows in one class (`class-sync-posts.php` ~177k).

2. `admin/admin-page.php` is monolithic procedural UI+save handler
- Configure tab additions can regress unrelated sections if not isolated.

3. `DBVC_Admin_App` route + business logic blend
- REST handlers and data transformation/diff logic are in one large class, making extension riskier.

4. Hash semantics split (SHA-256 + MD5)
- Callers can misuse semantics if API boundaries are not explicit.

## 6) Unknowns / How to Confirm

- Unknown: whether hidden/internal enterprise extension loader exists outside this repo.
  - Confirm by searching deployment mu-plugins/theme code for `do_action`/`apply_filters` integration that injects DBVC modules.
- Unknown: exact live Bricks option keys in your environments.
  - Confirm by runtime option inspection on target site(s) with Bricks installed.
- Unknown: final policy/status storage shape that best fits existing tables without add-on tables.
  - Confirm after mapping proposal lifecycle fields against `dbvc_collections` and decision stores in a data model review.
