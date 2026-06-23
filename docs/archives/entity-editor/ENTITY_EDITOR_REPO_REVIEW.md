# ENTITY EDITOR — Repo Review (Phase 0)

## Scope + document availability check

Requested source docs in the kickoff were not present in this repository snapshot:
- `docs/ENTITY_EDITOR_HANDOFF.md` (missing)
- `DBVC_ENGINE_INVENTORY.md` (missing)

Closest architecture/handoff references found:
- `handoff.md` (media/diff/proposals handoff; not Entity Editor specific).
- `README.md` and term/masking docs under `docs/`.

Because the Entity Editor phase checklist document is missing, implementation phases cannot be followed verbatim yet. This review maps confirmed integration points and collisions against current code.

---

## Confirmed integration points (with paths)

### 1) Sync folder resolution + directory layout

- Canonical resolver for DBVC sync root is `dbvc_get_sync_path($subfolder = '')` in `includes/functions.php`.
  - Uses option `dbvc_sync_path` when valid, otherwise defaults to plugin `sync/` folder.
  - Supports subfolder suffixing (sanitized via `sanitize_file_name`).
- Typical DBVC JSON layout inferred from exporter/import router:
  - Posts/CPTs: `<sync>/<post_type>/<post_type>-<id|slug|slug-id>.json`
  - Terms: `<sync>/taxonomy/<taxonomy>/<taxonomy>-<id|slug|slug-id>.json`
  - Root special files: `<sync>/options.json`, `<sync>/menus.json`
  - Proposal/manifest material is produced from sync content by backup manager (`manifest.json`, optional `entities.jsonl` inside backup/proposal directories).

### 2) Post vs term schema markers (and exclusion signals)

- Post entity marker in JSON files: presence of `ID` + `post_type` (`DBVC_Import_Scenario_Post::can_handle`).
- Term marker: presence of `taxonomy` and (`slug` or `term_id`) (`DBVC_Import_Scenario_Term::can_handle`).
- Options marker: filename `options.json` or options-group metadata (`DBVC_Import_Scenario_Options`).
- Menus marker: filename `menus.json` (`DBVC_Import_Scenario_Menus`).
- Backup manifest item typing additionally classifies `item_type` as `post`, `term`, `options`, `options_group`, or `menus`.

**Entity Editor implications (Posts + Terms only):**
- Include: post JSON + term JSON.
- Exclude: `options.json`, `menus.json`, options group files under `options/`, and anything not matching post/term schemas.
- Exclude media-only records: media bundle files are tracked via resolver/media index, not as editable entity JSON payload rows.

### 3) Import/export engines + normalization pipeline

- Export (posts): `DBVC_Sync_Posts::prepare_post_export()` + `write_export_payload()`.
  - Uses `dbvc_export_post_data` filter and `dbvc_normalize_for_json()` before write.
- Export (terms): `DBVC_Sync_Taxonomies::export_term()`.
  - Uses `dbvc_export_term_data` filter + optional sort + `dbvc_normalize_for_json()`.
- Import router for uploaded JSON: `DBVC_Import_Router` + scenario classes in `includes/import-scenarios/*.php`.
- Proposal apply/import engine: `DBVC_Sync_Posts::import_backup()` which processes manifest entries and delegates:
  - posts → `import_post_from_json()`
  - terms → `apply_term_entity()`
  - options/menus/group imports through existing handlers.

### 4) Matching strategy (UID/history/slug+subtype)

- Primary identity: `vf_object_uid` for posts and terms.
- Posts:
  - `import_post_from_json()` resolves by UID first (`find_post_id_by_uid()`), then falls back to IDs and other existing matching.
  - History metadata (`dbvc_post_history`) and import hash are maintained.
- Terms:
  - `identify_local_term()` uses a layered match strategy:
    1. UID table lookup (`DBVC_Database::get_entity_by_uid`)
    2. direct term ID
    3. slug + taxonomy
    4. manifest/entity reference fallback (`taxonomy_slug`/`taxonomy_id`).
  - `dbvc_term_history` stored after apply.

### 5) Logger + audit patterns

- Shared logger helper: `DBVC_Sync_Logger` with scoped methods:
  - `log`, `log_import`, `log_term_import`, `log_upload`, `log_media`.
- Apply/import paths already emit structured context arrays for summaries and skips.

### 6) Admin menu/page conventions + request routing

- Admin menu slug and top-level page: `dbvc-export` (`admin/admin-menu.php`).
- React app only enqueued on `toplevel_page_dbvc-export` (`DBVC_Admin_App::enqueue_assets`).
- DBVC admin app routing is REST-first (`register_rest_route` under `/dbvc/v1/...`), permission callback `can_manage` (manage_options), and REST nonce via localized script.
- Legacy/form actions still exist via `admin-post.php` for backup/download/upload tasks.

---

## Reusable classes/functions for Entity Editor

### High-value reuse candidates

- Path + sync location:
  - `dbvc_get_sync_path()`
  - `dbvc_validate_sync_path()`
  - `dbvc_is_safe_file_path()` (note limitations below)
- Manifest/proposal introspection:
  - `DBVC_Backup_Manager::generate_manifest()` and related manifest readers.
- Import behavior:
  - `DBVC_Sync_Posts::import_post_from_json()` for field-decision-aware partial updates.
  - `DBVC_Sync_Posts::apply_term_entity()` for term create/update.
- Directory hardening:
  - `DBVC_Sync_Posts::ensure_directory_security()` / `DBVC_Sync_Taxonomies::ensure_directory_security()`.
- Logging:
  - `DBVC_Sync_Logger::*` scoped methods.
- Admin REST conventions:
  - `DBVC_Admin_App::register_rest_routes()` patterns and `can_manage` permissions.

---

## Handoff assumption corrections / conflicts discovered

1. **Entity Editor handoff doc is not present.**
   The requested `docs/ENTITY_EDITOR_HANDOFF.md` phase checklist is unavailable in this repo snapshot.

2. **Engine inventory doc is not present.**
   Requested `DBVC_ENGINE_INVENTORY.md` is unavailable.

3. **Current sync path default differs from README statement.**
   README describes uploads-based default, while `dbvc_get_sync_path()` defaults to plugin-local `sync/` when no option is set.

4. **Current import semantics are not fully aligned with “non-destructive partial merge” for all entities.**
   - Posts currently update only accepted paths (good baseline for partial).
   - Terms currently clear each targeted term meta key before writing replacement values (`delete_term_meta`) and can be destructive per-key.
   - Existing “full/partial” modes in `import_backup()` are proposal-apply semantics, not a dedicated Entity Editor “save+partial merge” contract for arbitrary file edits.

---

## Collision risks + mitigation

1. **Routing/UI collision risk**
   - Existing React app is tightly bound to `dbvc-export` page and large REST surface in `DBVC_Admin_App`.
   - **Mitigation:** Add Entity Editor as a dedicated tab/route namespace under DBVC REST with lazy fetch + conditional enqueue/render on entity-editor state only.

2. **File safety risk (path traversal/symlink escape)**
   - Existing helpers include traversal checks but not a strict reusable “realpath containment + symlink resolution + reject missing parent realpath fallback” utility for all writes.
   - **Mitigation:** introduce a single strict resolver for Entity Editor writes that enforces containment inside `realpath(dbvc_get_sync_path())`, rejects symlink escapes, and validates extensions/schemas.

3. **Atomic write + backup consistency risk**
   - Existing write paths mostly use direct `file_put_contents`.
   - **Mitigation:** implement temp-file write + rename + timestamped backup before replacement for Entity Editor save actions.

4. **Import behavior drift risk**
   - Re-implementing import logic in Entity Editor could diverge from proposal apply behavior.
   - **Mitigation:** wrap existing import helpers where possible (post/term import paths) and pass mode-specific options rather than duplicate field-application logic.

5. **Decision-store cross-talk risk**
   - Existing review/apply uses global options (`dbvc_proposal_decisions`, masking stores).
   - **Mitigation:** keep Entity Editor action state isolated (separate option keys/transient state), avoid writing proposal decision keys unless intentionally invoking proposal workflow.

---

## Phase 0 outcome

- Integration points identified.
- Reuse targets identified.
- Blocking conflict identified: missing Entity Editor handoff checklist doc needed to execute the requested phased checklist exactly.
