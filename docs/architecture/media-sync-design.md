# DBVC Media Transport & Incremental Sync Design

## Goals
- Support both remote media retrieval and bundled media packages inside the sync directory.
- Enable incremental (“diff”) exports/imports driven by change tracking.
- Provide structured storage for job progress, hash metadata, and snapshot history.

## Current Implementation Snapshot
- Proposal exports now package deterministic bundles under `sync/media-bundles/<proposal-id>/...` whenever media capture is enabled. `DBVC_Backup_Manager` stamps bundle path, hash, dimensions, and resolver decisions directly into `dbvc-manifest.json` (schema `3`).
- `Dbvc\Media\Resolver` runs during proposal upload and prior to apply. Its REST payload feeds the React admin UI so reviewers can filter attachments by status (reused, downloaded, blocked, unresolved) and run bulk actions (reuse, download, skip, remap + remember globally).
- Resolver rules persist in `dbvc_resolver_decisions`. The admin UI provides CSV import/export plus inline add/edit/delete; every new proposal loads those rules automatically.
- Duplicate media/conflict overlays block entity review until the resolver reports zero blocking conflicts. Cleanup APIs prune stray JSON/media artifacts so each proposal stays canonical.
- Import/apply flows reuse the resolver `id_map` so once an attachment is accepted the importer does not redownload it; fallbacks to the legacy media sync are still available via filters.

## Database Additions
Create tables on plugin activation (with versioned schema constants).  
_Status: ✅ Implemented via `DBVC_Database::create_or_update_tables()` (schema version 2) in the production plugin._

### `{$wpdb->prefix}dbvc_snapshots` _(✅ live)_
| Column            | Type              | Notes |
|-------------------|-------------------|-------|
| `id`              | bigint PK         | Auto increment snapshot identifier. |
| `name`            | varchar(190)      | Optional label/slug. |
| `created_at`      | datetime          | UTC timestamp. |
| `initiated_by`    | bigint nullable   | WP user ID or `NULL` (CLI/cron). |
| `type`            | varchar(32)       | e.g. `full`, `diff`, `import`, `backup`. |
| `sync_path`       | text              | Absolute path used for this run. |
| `bundle_hash`     | char(64) nullable | SHA-256 of bundle contents (optionally computed). |
| `notes`           | text              | Free-form metadata/JSON. |

Indexes: primary, `(type, created_at)`, `(initiated_by)`.

### `{$wpdb->prefix}dbvc_snapshot_items` _(✅ live)_
| Column            | Type              | Notes |
|-------------------|-------------------|-------|
| `id`              | bigint PK         | |
| `snapshot_id`     | bigint            | FK → snapshots.id. |
| `object_type`     | varchar(32)       | `post`, `option`, `taxonomy`, etc. |
| `object_id`       | bigint            | Post/term/option hash key. |
| `content_hash`    | char(64)          | Hash of exported data (slug/JSON). |
| `media_hash`      | char(64) nullable | Aggregated media hash for the item. |
| `status`          | varchar(20)       | `created`, `updated`, `unchanged`, `skipped`. |
| `payload_path`    | text              | Relative path inside sync folder (JSON). |
| `exported_at`     | datetime          | Timestamp entry was written. |

Indexes: `(snapshot_id)`, `(object_type, object_id)`, `(content_hash)`.

### `{$wpdb->prefix}dbvc_media_index` _(✅ live)_
| Column            | Type         | Notes |
|-------------------|--------------|-------|
| `id`              | bigint PK    | |
| `attachment_id`   | bigint       | Local attachment ID (if present). |
| `original_id`     | bigint       | Source ID from Site A. |
| `source_url`      | text         | Remote URL (for remote mode). |
| `relative_path`   | text         | `media/yyyy/mm/filename.ext` within bundle. |
| `file_hash`       | char(64)     | SHA-256 of original file. |
| `file_size`       | bigint       | Bytes. |
| `mime_type`       | varchar(100) | Stored MIME type. |
| `last_seen`       | datetime     | Last time referenced/exported. |

Indexes: `(original_id)`, `(file_hash)`, `(relative_path(191))`.

### `{$wpdb->prefix}dbvc_jobs` _(✅ live)_
| Column        | Type          | Notes |
|---------------|---------------|-------|
| `id`          | bigint PK     | |
| `job_type`    | varchar(32)   | `export`, `import`, `media_sync`, etc. |
| `status`      | varchar(20)   | `pending`, `running`, `paused`, `failed`, `done`. |
| `context`     | longtext      | JSON payload (batch size, snapshot refs, errors). |
| `progress`    | float         | 0–1 for UI feedback. |
| `created_at`  | datetime      | |
| `updated_at`  | datetime      | |

Indexes: `(job_type, status)`, `(updated_at)`.

### Optional `{$wpdb->prefix}dbvc_activity_log`
Structured log entries (mirrors current file log but queryable). Columns: `id`, `event`, `severity`, `context JSON`, `created_at`, `user_id`, `job_id`.  
_Status: ✅ Table exists and receives entries when verbose logging is enabled._

## Manifest Enhancements
Add keys to `manifest.json` for each media entry.  
_Status: ✅ `DBVC_Backup_Manager::generate_manifest()` writes `media_index`, hashes, and resolver decisions so current proposals meet this schema._
```json
{
  "schema": 2,
  "media_index": [
    {
      "original_id": 28190,
      "source_url": "https://site-a/uploads/2024/10/asset.png",
      "relative_path": "media/2024/10/asset.png",
      "hash": "sha256:abcdef...",
      "file_size": 123456
    }
  ],
  "items": [
    {
      "item_type": "post",
      "post_id": 123,
      "media_refs": {
        "meta": [...],
        "content": [...]
      },
      "snapshot": {
        "content_hash": "sha256:....",
        "media_hash": "sha256:....",
        "last_exported": "2025-11-05T03:52:12Z"
      }
    }
  ]
}
```

Key additions:
- `relative_path` always points to the bundled copy if present.
- `hash` uses a consistent prefix (`sha256:`) for future flexibility.
- Each manifest item captures `snapshot` metadata so Site B can skip unchanged content during diff imports.

## Media Transport Modes
Expose a setting (UI + CLI flag) with these options:

1. **Auto** (default): Check bundled media first; if missing, fall back to remote download.
2. **Bundled only**: Require local `relative_path`; fail when files are absent.
3. **Remote only**: Skip bundle and use `source_url` (current behaviour).

_Status: ✅ Implemented._ Admins can choose the transport mode via Configure → Media Sync; the value is stored/read by `DBVC_Media_Sync::get_transport_mode()` and surfaced in manifests._

Importer behaviour _(✅ `DBVC_Sync_Posts::import_backup()` + resolver pipeline)_:
- Verify hash when using bundled media; on mismatch, log and fall back (if allowed).
- When remote mode succeeds, update `dbvc_media_index` with file hash/path for future bundle generation.

Exporter behaviour _(✅ `DBVC_Backup_Manager` + `DBVC_Sync_Posts::export_post_to_json()`)_:
- If in bundled or auto mode, copy attachment files into `sync/media/yyyy/mm/`. Store hash/size in both manifest and `dbvc_media_index`.
- Provide chunking controls (`chunk_size`, `max_files_per_chunk`). Store chunk metadata in `dbvc_jobs` and manifest (`chunks`: array of file lists).
- Diff mode consults `dbvc_snapshot_items` to select only items whose `content_hash` changed since the chosen baseline snapshot.

## Lifecycle Cleanup
- **Attachment trash:** JSON is removed (if present) while the entity registry row remains with `object_status = trash` so restores can reconcile cleanly.
- **Attachment restore:** Entity status flips back to `publish` for consistent tracking.
- **Attachment delete:** JSON is removed, `dbvc_media_index` is cleaned, and the bundled file under `sync/media/...` is deleted (plus empty directory cleanup).

## Logging & Integrity Checks
- Continue writing to `dbvc-backup.log`, but insert structured rows into `dbvc_activity_log` when available.
- Before import/export, run integrity pass:
  - Ensure all manifest `relative_path` files exist when bundle mode is enforced.
  - Compare hashes; record discrepancies in log/table.
- On import completion, persist snapshot summary and mark job rows complete.

## Activation/Upgrade Flow
1. Define a schema version constant (e.g., `DBVC_SCHEMA_VERSION = 1`).
2. On activation or version bump:
   - Create new tables via `dbDelta()`.
   - Migrate existing manifest/hash info into tables (best-effort).
3. Ensure cleanup helpers can truncate or drop tables when uninstalling (optionally retaining history).
