# BRICKS Add-on Plan (DBVC)

Date: 2026-02-12  
Scope: Discovery/update planning only (no implementation)

## 1) Proposed Folder Structure (aligned to DBVC conventions)

```text
addons/
  bricks/
    bricks-addon.php
    admin/
      class-bricks-settings-page.php
      class-bricks-ui-status.php
      class-bricks-ui-diff.php
      class-bricks-ui-proposals.php
    engine/
      class-bricks-artifact-adapter.php
      class-bricks-canonicalizer.php
      class-bricks-drift-scanner.php
      class-bricks-policy.php
      class-bricks-package-source.php
      class-bricks-proposal-client.php
      class-bricks-proposal-server.php
    storage/
      class-bricks-db.php
    docs/
      BRICKS_ADDON_PLAN.md
      BRICKS_ADDON_OVERVIEW.md
      BRICKS_ADDON_OPERATIONS.md
```

## 2) Current DBVC Patterns to Reuse

- Admin tab/subtab rendering pattern: `admin/admin-page.php` (`$config_subtabs`, nested subtabs).
- Section save/sanitize pattern: unified configure save handler in `admin/admin-page.php:355`.
- REST route style and permission callbacks: `admin/class-admin-app.php`.
- Logging:
  - `DBVC_Sync_Logger` (`includes/class-sync-logger.php`)
  - `DBVC_Database::log_activity` (`includes/class-database.php:846`).
- Entity UID mapping:
  - `ensure_post_uid` / `ensure_term_uid`
  - `dbvc_entities` upsert/get (`includes/class-database.php:447`).
- Manifest/snapshot/import package primitives:
  - `DBVC_Backup_Manager`, `DBVC_Snapshot_Manager`, `DBVC_Sync_Posts::import_backup`.

## 3) UI Plan: Configure > General Settings > Add-ons > Bricks

### 3.1 Planned tabs
1. Connection
- `dbvc_bricks_role` (`mothership|client`)
- `dbvc_bricks_mothership_url`
- `dbvc_bricks_auth_method` (`hmac|api_key`)
- `dbvc_bricks_auth_secret` (stored securely as option for MVP)
- `dbvc_bricks_read_only` (`0|1`)
- test connection action/button

2. Golden Source
- `dbvc_bricks_source_mode` (`mothership_api|pinned|bundled`)
- `dbvc_bricks_pinned_version`
- `dbvc_bricks_storage_mode` (`db_uploads` for MVP)
- `dbvc_bricks_retention_count`
- `dbvc_bricks_verify_signature` (`0|1`)

3. Policies
- Default policy per artifact type:
  - `AUTO_ACCEPT`
  - `REQUIRE_MANUAL_ACCEPT`
  - `ALWAYS_OVERRIDE`
  - `REQUEST_REVIEW`
  - `IGNORE`
- Per-artifact override editor by `artifact_uid`

4. Operations
- Manual drift scan trigger
- Drift summary counters: CLEAN / DIVERGED / OVERRIDDEN / PENDING_REVIEW
- Package version selector
- Diff view entrypoint
- Apply selected + create restore point + rollback selector

5. Proposals
- Client view: diverged artifacts and submit proposal action
- Mothership view: proposal queue with approve/reject/needs changes actions

### 3.2 UI integration strategy with current code
- Add new configure subtab ID (e.g., `dbvc-config-addons`) in `$config_subtabs` (`admin/admin-page.php:1382`).
- Inside it, use existing nested subtab pattern used by Import Defaults (`admin/admin-page.php:2417`).
- Reuse nonce/capability and section save pattern (`admin/admin-page.php:355`).

## 4) MVP Artifact Types

### Included
- `bricks_template` (Entity-backed; CPT based)
- `bricks_global_classes` (options-based)
- `bricks_global_variables` (options-based)

### Deferred
- global-colors
- components
- theme-styles
- template_tag
- template_bundle

## 5) Core Flows (MVP skeleton)

### Flow 1: Publish Golden (Mothership)
1. Export Bricks artifacts via add-on adapter.
2. Canonicalize + fingerprint (`sha256`).
3. Build package manifest and persist package.
4. Mark package as latest approved.

### Flow 2: Drift Scan (Client)
1. Resolve source package (mothership/pinned/bundled).
2. Export local artifacts and compute canonical hashes.
3. Compare local hash vs last applied and incoming golden hash.
4. Persist status and summary.

### Flow 3: Apply Golden (Client)
1. Preflight signature check (if enabled).
2. Create restore point.
3. Filter artifacts by policy and selection.
4. Apply deterministic order:
   - options artifacts first
   - Entity-backed templates next.
5. Verify hashes; update status; rollback on failure.

### Flow 4: Submit Proposal (Client)
1. Capture base version/hash + proposed canonical payload/hash.
2. Build diff summary.
3. Submit to mothership endpoint.
4. Mark local artifact `PENDING_REVIEW`.

### Flow 5: Review/Approve (Mothership)
1. Queue listing and diff review.
2. Decision: APPROVED / REJECTED / NEEDS_CHANGES.
3. On approval, merge into next package and publish.

## 6) Proposed REST Endpoints (planning only)

Namespace: `dbvc/v1/bricks`

### Golden packages
1. `GET /packages`
- Request query: `channel`, `limit`, `cursor`.
- Response:
```json
{
  "items": [{"package_id":"pkg_123","version":"1.2.0","channel":"stable","generated_at":"2026-02-12T10:00:00Z","artifacts":12}],
  "next_cursor": null
}
```

2. `GET /packages/{package_id}`
- Request query: `include_payload_index=true|false`.
- Response:
```json
{
  "manifest": {"package_id":"pkg_123","version":"1.2.0","artifacts":[{"artifact_uid":"...","artifact_type":"bricks_template","hash":"sha256:...","payload_path":"artifacts/...json"}]},
  "payload_index": [{"path":"artifacts/...json","hash":"sha256:..."}]
}
```

### Proposal pipeline
3. `POST /proposals`
- Request body:
```json
{
  "proposal_id":"prop_123",
  "artifact_uid":"option:bricks_global_classes",
  "artifact_type":"bricks_global_classes",
  "base_golden_version":"1.2.0",
  "base_hash":"sha256:...",
  "proposed_hash":"sha256:...",
  "canonical_payload":{},
  "diff_summary":{},
  "notes":"Updated utility class spacing",
  "tags":["spacing"]
}
```
- Response:
```json
{"ok":true,"proposal_id":"prop_123","status":"RECEIVED"}
```

4. `GET /proposals`
- Request query: `status`, `limit`, `cursor`.
- Response:
```json
{
  "items": [{"proposal_id":"prop_123","artifact_uid":"...","artifact_type":"...","status":"RECEIVED","submitted_at":"..."}],
  "next_cursor": null
}
```

5. `PATCH /proposals/{proposal_id}`
- Request body:
```json
{"status":"APPROVED","review_notes":"Looks good","publish_version":"1.3.0"}
```
- Response:
```json
{"ok":true,"proposal_id":"prop_123","status":"APPROVED","published_package_id":"pkg_130"}
```

## 7) Minimal Storage Plan

### Reuse existing DBVC tables/files first
- `dbvc_snapshots`, `dbvc_snapshot_items`
- `dbvc_collections`, `dbvc_collection_items`
- existing manifest/package files under DBVC backup/official storage
- `dbvc_entities` for Entity UID mapping

### Add-on-specific storage candidates (only if needed)
1. `dbvc_bricks_artifact_state`
- `artifact_uid`, `artifact_type`
- `current_hash`
- `last_golden_hash_applied`
- `last_golden_version_applied`
- `status`
- `policy`
- timestamps

2. `dbvc_bricks_proposals`
- `proposal_id`, `artifact_uid`, `artifact_type`
- `base_golden_version`, `base_hash`, `proposed_hash`
- payload ref / diff summary
- `status`, reviewer metadata, timestamps

## 8) Risks / Unknowns for Live Validation

1. Exact Bricks option keys by installed Bricks version.
2. Non-deterministic/noisy fields in Bricks payloads.
3. Bricks internal ID churn causing noisy diffs.
4. Performance/memory for large template trees in diff/apply.
5. Backward compatibility with legacy DBVC manifests and proposal folders.
6. UX fit for adding “General Settings > Add-ons > Bricks” into current Configure IA.

## 9) Discovery Exit Criteria (met by this plan)

- DBVC-centric architecture documented.
- Reuse vs new work identified with file-level references.
- UI tabs/settings and endpoint skeletons defined.
- MVP artifact scope and flows documented.

