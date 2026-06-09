# DBVC Media Sync Hydration Implementation Guide

Date: 2026-06-04
Status: Initial service and WP-CLI implementation in progress

## Objective

Add a first-class DBVC media sync/hydration workflow that can mirror a site's registered WordPress Media Library assets to a duplicate site whose database already contains the attachment posts, but whose physical upload files are missing.

The immediate target case is a duplicate site created from an All-in-One WP Migration `.wpress` restore where media files were excluded. The target database contains the canonical attachment posts with matching IDs. DBVC should be able to hydrate the missing files into `wp-content/uploads`, preserve the existing attachment IDs, regenerate or validate WordPress attachment metadata, and report a clear mapping/receipt without disrupting proposal review, entity diffs, Visual Editor, Content Collector, Bricks portability, AI package flows, or existing resolver decisions.

## Current Implementation Status

Implemented initial foundations:

- Read-only attachment inventory and file-state inspection.
- Full-library media mirror manifest/package export under a dedicated `dbvc_media_mirror` shape.
- Dry-run hydration planning for cloned targets, with same-ID confirmation, hash checks, missing-file detection, metadata-repair detection, and conflict reporting.
- Guarded WP-CLI apply for local mirror packages: `--apply` requires `--confirm=hydrate-existing-media`, hydrates existing attachment rows only, verifies package hashes, avoids overwrites by default, and runs WordPress attachment metadata repair when enabled.
- Configure > Media Handling settings for enabling the workflow, source mode, matching policy, metadata policy, MIME group scope, batch size, receipts, strict hashes, clone confirmation, and apply lock timeout.
- Basic Configure > Media Handling workflow controls for inventory, package export, preflight, and apply.
- Import/Upload tab media hydration package upload for ZIP packages, with safe extraction into `sync/media-mirrors/<package-id>/`.
- Secure source-side media mirror ZIP download links after package export. Downloads are served through an admin-post action with `manage_options`, nonce verification, package-ID validation, and containment under the DBVC media mirror root.
- Permission-aware REST endpoints for inventory, preflight planning, package export, and guarded apply. Preflight/package/apply are blocked until media hydration is enabled in DBVC settings.
- File-backed JSON receipts and a global apply lock for write runs.
- Saved dry-run plan IDs for REST preflight/apply acknowledgement, with manifest checksum verification.

Not implemented yet:

- Rich admin workflow UI for selecting staged packages, reviewing plan rows, launching apply, and downloading receipts.
- Asynchronous job orchestration.
- Remote-source hydration.
- New attachment creation for non-cloned targets.
- Destructive mirror cleanup or deletion of target extras.

## Current Behavior Summary

Current DBVC media behavior is proposal/reference driven:

- `DBVC_Backup_Manager::generate_manifest()` builds `media_index` from attachment IDs found in exported post/term meta and known content URL references.
- `Dbvc\Media\BundleManager` can copy those referenced files into proposal-specific media bundles when media bundling is enabled.
- `Dbvc\Media\Resolver` resolves manifest entries by `vf_asset_uid`, `vf_file_hash`, `_wp_attached_file` path, then filename.
- `Dbvc\Media\Reconciler` creates attachments from bundle files only when the resolver does not already find a target attachment.
- `DBVC_Media_Sync` can sideload bundled or remote files for unresolved references when media retrieval is enabled.

Important gap:

- If the target database already contains the attachment rows, the resolver can mark media as `reused` even when the physical file is missing on disk. In the All-in-One no-media restore case, this prevents DBVC from hydrating missing files because attachment records already exist.

## Product Boundary

### In Scope

- Registered WordPress Media Library assets represented by `post_type = attachment`.
- Existing target attachment rows whose IDs match the canonical source rows.
- Missing original upload files referenced by `_wp_attached_file`.
- Missing or stale WordPress-generated attachment metadata.
- Existing DBVC media identity metadata:
  - `vf_asset_uid`
  - `vf_file_hash`
  - `_dbvc_original_attachment_id`
- Source transport from:
  - DBVC media mirror bundle/package
  - canonical source URLs when explicitly allowed
  - existing proposal bundles where appropriate
- Dry-run planning before writes.
- Job progress and receipts.
- CLI and admin UI entry points.

### Out of Scope for Initial Release

- Deleting target media not present on source.
- Mirroring arbitrary non-library files in `wp-content/uploads`.
- Theme/plugin asset migration.
- Preserving numeric attachment IDs when the target database was not cloned from the source.
- Rewriting all entity content references beyond existing DBVC resolver/map contracts.
- Replacing the existing proposal resolver workflow.
- Importing non-media upload directories created by cache, optimization, backup, or form plugins unless they are registered as attachments.

## Core Design Rule

Hydrating an existing attachment row is not the same operation as importing a new attachment.

For cloned databases with matching attachment IDs:

1. Do not call `wp_insert_attachment()` for attachments that already exist.
2. Do not create duplicate attachment posts.
3. Copy/download the source file into the exact target path described by the target row's `_wp_attached_file`.
4. Verify hash and file type.
5. Run WordPress metadata generation/repair for that existing attachment ID.
6. Record an identity mapping of `source_id -> target_id`, which will usually be the same ID.

For non-cloned targets:

1. Use normal DBVC resolver behavior and WordPress attachment creation APIs.
2. Do not promise numeric ID preservation.
3. Produce an ID map and remap references only through explicit DBVC contracts.

## Proposed Architecture

Place new code under `includes/Dbvc/Media/` unless an existing boundary is a better fit.

### Implementation Boundaries

Keep this feature out of existing monoliths except for narrow registration hooks.

Recommended file layout:

```text
includes/Dbvc/Media/Hydration/
  LibraryInventoryService.php
  FileStateService.php
  MirrorManifestBuilder.php
  HydrationPlanner.php
  Hydrator.php
  MetadataRepairService.php
  SourceLocator.php
  HydrationReceiptStore.php
  MediaMapStore.php
  RestController.php
  Settings.php
commands/
  class-media-hydration-cli.php
```

Registration rules:

- Add `require_once` lines in `db-version-control.php` only for the new focused classes.
- Prefer a dedicated `Dbvc\Media\Hydration\RestController` over adding large handlers to `DBVC_Admin_App`.
- Serve media mirror ZIP downloads through a focused admin-post controller rather than exposing raw sync filesystem URLs.
- If existing admin or REST classes must be touched, add thin delegation only.
- Keep `DBVC_Sync_Posts`, `DBVC_Backup_Manager`, `DBVC_Media_Sync`, and `admin/admin-page.php` changes small and explicit.
- Add settings metadata through `MediaHandlingProvider` or a small hydration settings provider; do not scatter raw option names across runtime services.
- Do not place hydration logic in add-ons.
- Do not make Visual Editor, Content Collector, Bricks, AI package, or third-party portability code depend on hydration classes.

Service rules:

- Each service owns one stage: inventory, planning, source resolution, hydration, metadata, receipt storage, or presentation.
- Services should accept arrays/value objects and return structured arrays/results, not write global state except through the receipt/job store.
- File-writing services must be callable from CLI and REST without duplicating logic.
- Long-running work must be chunkable and resumable.

### New Services

| Service | Responsibility |
|---|---|
| `LibraryInventoryService` | Enumerate attachment posts and produce normalized media descriptors for all registered library items, not just referenced media. |
| `FileStateService` | Check target file existence, size, hash, readability, metadata presence, generated sub-sizes, MIME, and upload-base safety. |
| `MirrorManifestBuilder` | Build a dedicated full-library media manifest/package without changing proposal `media_index` semantics. |
| `HydrationPlanner` | Compare source descriptors against target attachment rows and produce a dry-run plan. |
| `Hydrator` | Execute in-place file hydration for existing attachments and optional new attachment creation for non-cloned targets. |
| `MetadataRepairService` | Regenerate, update, or verify attachment metadata after file hydration. |
| `SourceLocator` | Resolve source bytes from a bundle, exact source upload path, or allowed remote URL. |
| `HydrationReceiptStore` | Persist run plans, results, errors, and downloadable receipts using `dbvc_jobs`, `dbvc_activity_log`, and JSON receipt files. |
| `MediaMapStore` | Normalize source-to-target attachment mappings for use by existing resolver/reporting flows. |

### Existing Services To Preserve

- `Dbvc\Media\Resolver`
- `Dbvc\Media\Reconciler`
- `Dbvc\Media\BundleManager`
- `DBVC_Media_Sync`
- proposal review REST endpoints
- resolver decision option store `dbvc_resolver_decisions`

Do not make full-library media hydration automatically flood proposal resolver attachments. Keep proposal `media_index` focused on proposal-referenced media unless a later phase deliberately adds a filtered, UI-aware integration.

## Security Controls

Treat media mirror manifests and packages as untrusted input, even when generated by DBVC.

### Permissions And Requests

- Admin UI writes require `manage_options`.
- REST routes require `permission_callback` equivalent to existing DBVC admin routes.
- Classic admin actions require nonce verification.
- CLI commands should log the initiating user/context when available.
- Apply/hydrate actions require an explicit dry-run plan ID unless `dbvc_media_hydration_require_dry_run` is disabled by an administrator.

### Package And Path Safety

- Extract uploaded ZIP packages only through a safe extractor that rejects absolute paths, `..`, empty names, symlinks, hard links, and nested archive tricks.
- Accept only expected package entries:
  - mirror manifest JSON
  - media files under a known package directory
  - optional receipt/checksum files
- Resolve every target path with `realpath()` where possible and verify containment under `wp_upload_dir()['basedir']`.
- Never write to paths derived directly from `source_url`.
- Do not follow symlinks when reading source files or writing target files.
- Stage writes to a temporary file under the target upload tree, then rename into place.
- Preserve existing target files unless overwrite policy permits replacement and a backup copy was created.

### Remote Source Safety

- Remote hydration is disabled unless the configured source mode allows it.
- Reuse the existing mirror-domain/external-host policy, but add hydration-specific host validation for `dbvc_media_hydration_remote_base_url`.
- Reject private, loopback, link-local, and malformed remote hosts to avoid SSRF.
- Enforce `dbvc_media_hydration_max_file_size_mb` before and during download when response headers are available.
- Set download timeouts and fail closed on redirects to unapproved hosts.
- Validate MIME type and extension after download using WordPress file type checks before writing into uploads.

### Manifest Integrity

- Require `kind = dbvc_media_mirror` for full-library packages.
- Verify manifest schema version before planning.
- Verify file hashes after source read and after target write when hashes are present.
- Treat missing hashes as warnings in dry run and blocked for apply unless an explicit legacy/unsafe mode is added later.
- Record package checksum and manifest checksum in the receipt.

### Logging Safety

- Do not log credentials, signed URLs, auth headers, or full local filesystem roots unless debug mode explicitly permits it.
- Logs should include attachment IDs, relative paths, status codes, and failure reasons.
- Receipts can include relative upload paths and hashes, but should avoid secrets.

## Manifest Strategy

Do not overload the existing proposal `media_index` for full-library sync by default. It is already consumed by proposal review and resolver UI.

Add a dedicated media mirror manifest shape:

```json
{
  "schema": 1,
  "kind": "dbvc_media_mirror",
  "generated_at": "2026-06-04T00:00:00Z",
  "source_site": {
    "home_url": "https://canonical.example",
    "uploads_baseurl": "https://canonical.example/wp-content/uploads"
  },
  "scope": {
    "mode": "all_registered_attachments",
    "include_unattached": true,
    "mime_types": ["image", "video", "audio", "font", "document", "other"]
  },
  "attachments": [
    {
      "source_id": 123,
      "asset_uid": "uuid-or-empty",
      "relative_path": "2025/01/example.jpg",
      "source_url": "https://canonical.example/wp-content/uploads/2025/01/example.jpg",
      "filename": "example.jpg",
      "mime_type": "image/jpeg",
      "file_hash": "sha256:...",
      "file_size": 123456,
      "metadata": {
        "width": 1200,
        "height": 800,
        "sizes": ["thumbnail", "medium", "large"]
      }
    }
  ]
}
```

Compatibility rule:

- Existing proposal manifests keep `media_index`.
- Full-library media mirror packages use `attachments`.
- A future bridge can import `attachments` into resolver views only behind an explicit UI filter such as "Library hydration".

## Target Matching And Mapping

Planner matching order:

1. Exact target attachment ID when `source_id` exists and `get_post(source_id)` is an attachment.
2. `vf_asset_uid`.
3. `_dbvc_original_attachment_id`.
4. `vf_file_hash`.
5. `_wp_attached_file` relative path.
6. Filename only as a conflict signal, never as an automatic writable match.

When the target DB is a clone and IDs match:

- Mark mapping as `source_id == target_id`.
- If target file exists and hash matches, status is `ok`.
- If target file is missing, status is `needs_hydration`.
- If target file exists but hash differs, status is `hash_mismatch` and requires overwrite policy.
- If target metadata is missing/stale, status is `needs_metadata_repair`.

Mapping output should include:

```json
{
  "source_id": 123,
  "target_id": 123,
  "matched_via": "same_id",
  "file_status": "missing",
  "planned_action": "hydrate_existing_attachment",
  "relative_path": "2025/01/example.jpg"
}
```

## Hydration Execution

### Existing Attachment Hydration

For target attachment IDs that already exist:

1. Resolve target relative path from target `_wp_attached_file`.
2. Reject paths outside `wp_upload_dir()['basedir']`.
3. Locate source file from bundle or allowed remote source.
4. Validate expected file size/hash when known.
5. Create the target upload subdirectory if needed.
6. Copy or stream the source file to a temporary path in the target upload tree.
7. Atomically move into place where possible.
8. Update `vf_asset_uid`, `vf_file_hash`, and `_dbvc_original_attachment_id` if missing or explicitly allowed.
9. Regenerate metadata for the existing attachment ID.
10. Verify `get_attached_file($target_id)` exists and hash matches.
11. Record receipt row.

Idempotency requirements:

- A second run after successful hydration should classify the item as `ok` or `needs_metadata_repair`, not copy the file again.
- Failed items must be retryable without rerunning the entire library.
- Each item receipt must record `planned_action`, `actual_action`, `status`, `source_hash`, `target_hash`, and any backup path.
- Job state must checkpoint after each batch.
- Concurrent hydration jobs must be blocked or serialized by a lock stored in `dbvc_jobs`/options.

### New Attachment Creation

Only use this path when the target does not contain the attachment row and the run is explicitly configured to create missing attachment records.

- Use WordPress media APIs.
- Store DBVC mapping metadata.
- Do not promise source ID preservation.
- Keep this path separate in receipts and UI.

## WordPress Media Handling

The in-place hydration path must still let WordPress process media metadata.

For images:

- call `wp_generate_attachment_metadata($attachment_id, $file_path)`
- call `wp_update_attachment_metadata($attachment_id, $metadata)`
- verify `_wp_attachment_metadata.file` aligns with `_wp_attached_file`

For audio/video:

- use the same metadata-generation path WordPress supports through `wp_generate_attachment_metadata`
- preserve MIME type from attachment post unless validation proves it is wrong

For fonts/documents/other files:

- copy and verify the original file
- update `_wp_attached_file` only if missing and source manifest is trusted
- metadata may be empty; this is acceptable when WordPress itself would not generate sizes

Generated derivative strategy should be configurable:

- `regenerate` default: copy original and let WordPress regenerate sizes.
- `copy_bundle_derivatives`: copy source derivative files when bundled.
- `regenerate_then_copy_missing`: regenerate locally, then fill gaps from bundle.

Initial release should implement `regenerate`; derivative copy can follow after validation.

## Data Integrity Rules

### Clone Confirmation

Before applying same-ID hydration, the planner must confirm clone assumptions:

- Target attachment IDs from the manifest exist as attachment posts.
- Target `_wp_attached_file` values either match manifest relative paths or are explicitly accepted as target-authoritative.
- Target database has not already diverged into conflicting attachment rows for the same `vf_asset_uid` or file hash.
- A dry run reports the count of source attachments, target matching rows, missing target rows, missing files, existing files, and conflicts.

Default policy for cloned targets:

- Target attachment row is authoritative for ID and `_wp_attached_file`.
- Source manifest is authoritative for file bytes, hash, MIME, and optional DBVC identity metadata.
- Target URL/domain is never overwritten from source.

### File And Metadata Consistency

- Verify source hash before writing when available.
- Verify target hash after writing.
- Update `vf_file_hash` only after target verification succeeds.
- Preserve `_wp_attached_file` unless it is missing and the manifest path passes upload containment checks.
- Preserve attachment post title, author, parent, date, excerpt, and content in cloned-DB mode.
- Metadata repair should not change attachment IDs, post parents, or entity relationships.
- If metadata repair fails after file hydration, leave the hydrated original file in place and mark status `metadata_failed` unless strict mode is added later.

### Rollback And Backup

- Missing-file hydration does not need a backup because no target file is replaced.
- Mismatch repair requires a backup copy before overwrite.
- Backup copies should live under a DBVC-controlled, access-hardened folder in uploads/sync.
- Receipts must include enough information to manually restore a replaced file.
- Do not attempt automatic rollback across the whole job unless a later transactional design is added.

### Storage

Prefer existing tables and file receipts first:

- `dbvc_jobs` for active job state and progress.
- `dbvc_activity_log` for structured events.
- `dbvc_media_index` for stable attachment/file metadata.
- JSON receipt files under an access-hardened DBVC uploads/sync subdirectory.

Add a new table only if receipts become too large or need frequent indexed queries that existing tables cannot support.

## Performance Requirements

Full media libraries can be large. The implementation must avoid eager, all-in-memory processing.

Inventory:

- Query attachment IDs in pages.
- Do not use unbounded `posts_per_page = -1` in production hydration services.
- Load only required post/meta fields for each stage.
- Cache `wp_upload_dir()` and derived base paths per run.

Hashing:

- Default dry run should hash only files needed to make a decision.
- Existing target files can be size/path checked first, then hashed only when required by mode.
- Source package export may hash all files, but must do it in batches and record progress.
- Large-file hash reads should stream from disk.

Hydration:

- Process in batches using `dbvc_media_hydration_batch_size`.
- Check memory/time budget between batches.
- Resume from the last checkpoint instead of restarting.
- Limit concurrent writes.
- Regenerate metadata only for hydrated files in the first release.

UI:

- Show aggregate counts first, then lazy-load item rows.
- Keep receipt downloads as files, not huge REST payloads.
- Poll job progress with compact payloads.

CLI:

- Stream progress lines for long runs.
- Support `--limit`, `--offset`, `--only-failed`, and `--attachment-id=` filters in later phases.

## Configure Tab Settings

Use the existing Configure > Media Handling subtab, but organize settings into clear groups. If the current UI becomes too dense, add a sibling Configure subtab named Media Hydration that writes to the same provider domain.

### Existing Settings To Preserve

- `dbvc_media_retrieve_enabled`
- `dbvc_media_preserve_filenames`
- `dbvc_media_preview_enabled`
- `dbvc_media_allow_external`
- `dbvc_media_transport_mode`
- `dbvc_media_bundle_enabled`
- `dbvc_media_bundle_chunk`

### Proposed New Settings

| Option | Type | Default | Purpose |
|---|---:|---:|---|
| `dbvc_media_hydration_enabled` | bool | `0` | Enables the dedicated full-library hydration workflow. |
| `dbvc_media_hydration_scope` | select | `missing_files_only` | `missing_files_only`, `missing_and_metadata`, `verify_all`, `repair_mismatches`. |
| `dbvc_media_hydration_source` | select | `bundle_first` | `bundle_first`, `bundle_only`, `remote_only`, `filesystem_path`. |
| `dbvc_media_hydration_match_policy` | select | `same_id_then_uid` | Matching strategy for cloned vs non-cloned targets. |
| `dbvc_media_hydration_create_missing_attachments` | bool | `0` | Allow creation of attachment posts when target rows are absent. Off for cloned DB recovery. |
| `dbvc_media_hydration_overwrite_policy` | select | `never` | `never`, `if_hash_mismatch_with_backup`, `always_with_backup`. |
| `dbvc_media_hydration_metadata_policy` | select | `regenerate_missing` | `skip`, `regenerate_missing`, `regenerate_all_hydrated`. |
| `dbvc_media_hydration_derivative_policy` | select | `regenerate` | `regenerate`, future `copy_bundle_derivatives`, future `regenerate_then_copy_missing`. |
| `dbvc_media_hydration_allowed_mime_groups` | string list | all registered | Restrict hydration to images, video, audio, fonts, documents, or all. |
| `dbvc_media_hydration_max_file_size_mb` | int | `512` | Safety limit for a single file. |
| `dbvc_media_hydration_batch_size` | int | `50` | Number of attachments per job batch. |
| `dbvc_media_hydration_remote_base_url` | url | empty | Optional canonical uploads base URL override. |
| `dbvc_media_hydration_filesystem_base_path` | path | empty | Optional trusted local/source uploads path for server-side copy. |
| `dbvc_media_hydration_require_dry_run` | bool | `1` | Require a saved dry-run plan before execution. |
| `dbvc_media_hydration_receipts_enabled` | bool | `1` | Persist machine-readable run receipts. |
| `dbvc_media_hydration_delete_extras_enabled` | bool | `0` | Reserved for future destructive mirror cleanup; must remain off initially. |
| `dbvc_media_hydration_strict_hashes` | bool | `1` | Block apply for files without a source hash or with a source/target mismatch. |
| `dbvc_media_hydration_clone_confirmation` | bool | `1` | Require same-ID clone checks before in-place hydration. |
| `dbvc_media_hydration_lock_timeout_minutes` | int | `30` | Expire abandoned hydration job locks after a bounded interval. |

### Recommended UI Copy

Use wording that makes the cloned-DB mode explicit:

- "Hydrate missing files for existing attachment records"
- "Preserve target attachment IDs when the target database already contains matching attachment posts"
- "Create missing attachment posts" should be visibly disabled/off by default
- "Delete target files not present on source" should not ship in the first implementation

## Admin And CLI Entry Points

### Admin UI

Add a Media Hydration panel under Configure > Media Handling or a new Configure > Media Hydration subtab.

Required controls:

- Select source package or source mode.
- Run dry run.
- Review counts by status.
- Start hydration job.
- View progress.
- Download receipt.
- Filter failures by reason.
- Retry failed items only.

Suggested dry-run summary buckets:

- `ok`
- `needs_hydration`
- `needs_metadata_repair`
- `hash_mismatch`
- `target_attachment_missing`
- `source_file_missing`
- `blocked_remote`
- `unsafe_path`
- `mime_rejected`
- `oversize`
- `conflict`

### REST Routes

Add routes under `dbvc/v1`, all gated by `manage_options`:

- Implemented: `GET /media-hydration/inventory`
- Implemented: `POST /media-hydration/preflight`
- Implemented: `POST /media-hydration/package/export`
- Implemented: `POST /media-hydration/apply`
- Future: `GET /media-hydration/jobs/{job_id}`
- Future: `POST /media-hydration/jobs/{job_id}/run`
- Future: `POST /media-hydration/jobs/{job_id}/retry`
- Future: `GET /media-hydration/jobs/{job_id}/receipt`
- Implemented as admin-post form: target-side media hydration ZIP package upload under Import/Upload.

Keep these separate from `/proposals/{proposal_id}/resolver` until a later phase needs a shared screen.

### WP-CLI

Add commands:

```bash
wp dbvc media inventory --format=json
wp dbvc media mirror-export --with-files --zip --out=/path/to/media-mirror.zip
wp dbvc media hydrate --manifest=/path/to/dbvc-media-mirror.json --dry-run
wp dbvc media hydrate --manifest=/path/to/dbvc-media-mirror.json --apply --confirm=hydrate-existing-media
wp dbvc media verify --all
```

## Development Phases

### Phase 0: Planning And Guardrails

Tasks:

- Add this implementation guide.
- Confirm current proposal media behavior with code references.
- Define non-destructive defaults.
- Define manifest shape and receipts.
- Add fixture plan for a cloned DB with missing uploads.

Exit criteria:

- No runtime behavior changed.
- Guide accepted as source of truth for implementation.

### Phase 1: Read-Only Target Inventory

Tasks:

- Implement `LibraryInventoryService`.
- Implement `FileStateService`.
- Add CLI/admin read-only inventory output.
- Add paged query helpers and avoid unbounded attachment queries.
- Detect missing files for existing attachment rows.
- Detect metadata missing/stale.
- Detect unsafe `_wp_attached_file` paths.

Tests:

- Attachment row with missing file.
- Attachment row with existing matching file.
- Attachment row with outside-upload path rejected.
- Non-image attachment with empty metadata accepted.

Exit criteria:

- DBVC can report how many registered attachments need hydration without writing files.

### Phase 2: Source Media Mirror Package

Tasks:

- Implement `MirrorManifestBuilder`.
- Implement safe package writer and safe package reader.
- Export all registered attachments into a dedicated media mirror manifest.
- Optionally bundle original files.
- Keep full-library mirror package separate from proposal `media_index`.
- Include hashes, file sizes, MIME, source URL, relative path, and asset UID.
- Store package and manifest checksums.

Tests:

- Full library export includes unattached attachments.
- Proposal export behavior remains unchanged by default.
- Bundle rejects missing source files with explicit receipt rows.
- Package extraction rejects traversal, symlink, and unexpected entry paths.

Exit criteria:

- Canonical site can produce a media mirror package suitable for target hydration.

### Phase 3: Hydration Planner

Tasks:

- Implement `HydrationPlanner`.
- Consume source manifest and target inventory.
- Build source-to-target map.
- Support cloned-DB same-ID mode.
- Enforce clone-confirmation checks before same-ID apply.
- Produce dry-run status buckets and item-level planned actions.
- Persist dry-run plan in `dbvc_jobs` plus a receipt JSON file.

Tests:

- Same source/target attachment ID maps as `same_id`.
- Existing target row with missing file plans `hydrate_existing_attachment`.
- Existing target row with matching file plans `none`.
- Existing target row with mismatched file plans according to overwrite policy.
- Missing target row is blocked unless `create_missing_attachments` is enabled.
- Diverged same-ID rows are reported as conflicts.

Exit criteria:

- Admin can review exactly what would be copied, skipped, repaired, or blocked.

### Phase 4: In-Place Hydrator

Tasks:

- Implement `SourceLocator`.
- Implement `Hydrator` for existing attachment rows.
- Implement job lock/checkpoint handling.
- Copy source file to target upload path.
- Verify hash after copy.
- Update DBVC identity meta only when safe.
- Write per-item receipts.
- Back up existing mismatched target files before overwrite if overwrite is enabled.
- Ensure all writes are staged and path-contained.

Tests:

- Missing image file is copied to the expected `_wp_attached_file` path.
- Attachment ID remains unchanged.
- Hash mismatch refuses overwrite by default.
- Overwrite mode creates backup before replacement.
- Remote source obeys existing external host policy.
- Re-running hydration skips already-correct files.

Exit criteria:

- Target clone can hydrate missing original files without creating duplicate attachments.

### Phase 5: Metadata Repair

Tasks:

- Implement `MetadataRepairService`.
- Regenerate attachment metadata for hydrated files.
- Support images, audio/video, fonts/documents/other.
- Record metadata outcome in receipts.
- Add retry path for metadata failures.

Tests:

- Image attachment gets `_wp_attachment_metadata`.
- Existing metadata is preserved when policy is `skip`.
- Metadata regeneration failures do not roll back successful file hydration unless strict mode is enabled.

Exit criteria:

- Hydrated files are processed through WordPress media metadata handling.

### Phase 6: Admin UI And Receipts

Tasks:

- Add settings to Configure > Media Handling or new Configure > Media Hydration subtab through the settings provider.
- Add dry-run and run controls.
- Add job progress polling.
- Add receipt download.
- Add retry failed items.
- Add clear old media hydration receipts with nonce/capability checks.
- Keep REST/admin handlers as thin delegators to the hydration services.

Tests:

- Settings save does not affect proposal resolver settings.
- Dry run can be executed without writes.
- Apply requires a dry-run plan when configured.
- Failed rows are visible and retryable.

Exit criteria:

- Admins can run the workflow without CLI.

### Phase 7: Proposal Resolver Compatibility

Tasks:

- Teach resolver reporting to distinguish `reused_file_present` from `reused_file_missing` when requested.
- Do not change default proposal resolver status until UI is ready.
- Add optional `needs_hydration` detail in resolver payload.
- Ensure proposal apply does not run both hydration and legacy sync for the same item.

Tests:

- Existing proposal diff/entity review remains unchanged by default.
- Resolver detail can show file-missing state behind feature flag/config.
- Existing resolver decisions still apply.

Exit criteria:

- Proposal media diagnostics can benefit from hydration state without changing apply semantics.

### Phase 8: Optional Non-Cloned Target Support

Tasks:

- Add explicit new-attachment creation mode.
- Use WordPress media APIs.
- Produce ID map for non-matching IDs.
- Integrate mapping with existing DBVC media refs only where contracts already exist.

Tests:

- Missing target attachment rows are created only when enabled.
- New IDs are reported honestly.
- References are remapped only through existing resolver/media ref paths.

Exit criteria:

- DBVC can support non-cloned media import without claiming ID preservation.

## Conflict Avoidance Rules

- Do not change proposal `media_index` defaults.
- Do not make full-library media items appear in proposal diff review by default.
- Do not reuse `dbvc_resolver_decisions` for hydration overwrite choices.
- Do not run destructive delete/mirror cleanup in the first implementation.
- Do not alter Visual Editor media save contracts.
- Do not alter Content Collector, AI package, Bricks portability, or third-party portability media behavior.
- Do not modify attachment IDs on cloned targets.
- Do not create attachment posts when the target row already exists.
- Do not trust manifest paths without upload-base containment checks.
- Do not download from unapproved hosts.
- Do not add hydration logic to `DBVC_Sync_Posts`, `DBVC_Admin_App`, or `admin/admin-page.php` beyond registration/delegation.
- Do not use unbounded media queries in production paths.
- Do not allow two hydration apply jobs to write to the same site at the same time.
- Do not make remote downloads the default for cloned-DB recovery.

## Validation Plan

### Unit/Integration Tests

- Inventory finds all `post_type = attachment` rows.
- Missing physical files are detected.
- Existing matching files are skipped.
- Hash mismatches are blocked by default.
- Same-ID mapping is preferred for cloned DBs.
- UID/hash/path fallback mapping works only when unambiguous.
- Clone-confirmation failures block same-ID apply.
- Unsafe manifest paths and unsafe target paths are rejected.
- ZIP package traversal/symlink entries are rejected.
- Remote private/loopback hosts are rejected.
- Job lock prevents concurrent apply runs.
- Hydration is idempotent on rerun.
- Metadata regeneration updates existing attachment IDs.
- Full-library manifest does not alter proposal manifest `media_index`.
- Existing proposal resolver tests still pass.

### Runtime Smoke Tests

Use a disposable target clone:

1. Restore database without media files.
2. Confirm attachment posts exist and IDs match source.
3. Run read-only inventory.
4. Export media mirror package from canonical.
5. Dry-run hydrate on target.
6. Apply missing-file hydration.
7. Verify sample images, videos, documents, fonts, and PDFs load through `wp_get_attachment_url()`.
8. Verify attachment IDs did not change.
9. Verify `_wp_attachment_metadata` exists for images.
10. Verify proposal review still opens and existing resolver panels are unchanged.

### Safety Checks

- `php -l` for touched PHP files.
- Focused PHPUnit for new media services.
- `git diff --check`.
- Manual admin UI check for Configure tabs.
- Optional WP-CLI smoke command on disposable clone.

## Acceptance Criteria

- A cloned target with matching attachment posts and missing upload files can hydrate missing media in place.
- Hydration preserves existing target attachment IDs.
- DBVC records source-to-target mappings and a downloadable receipt.
- WordPress metadata is regenerated or verified for hydrated items.
- Existing proposal diffs, resolver decisions, entity imports, add-ons, and Visual Editor behavior are not disrupted.
- Full-library media mirror support is opt-in and off by default.
- Destructive mirror cleanup is not shipped in the initial release.

## Recommended First Implementation Slice

Build the smallest safe end-to-end path:

1. Read-only inventory on target.
2. Full-library mirror package export on source.
3. Dry-run planner on target using same-ID matching.
4. In-place hydration for missing original files only.
5. Metadata regeneration for hydrated images.
6. Receipt output.

Defer remote-only hydration, derivative-copy strategies, new attachment creation, proposal resolver UI changes, and destructive mirror cleanup until the cloned-DB missing-media case is stable.
