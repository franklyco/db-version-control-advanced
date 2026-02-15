# BRICKS Add-on Plan (DBVC)

Date: 2026-02-12  
Scope: Discovery/update planning only (no implementation)

## 0) Field Matrix Source of Truth

Concrete add-on fields, validation rules, option keys, artifact registry, and missing task inventory are defined in:
- `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/addons/bricks/docs/BRICKS_ADDON_FIELD_MATRIX.md`
- `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/addons/bricks/docs/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST.md`
- `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/addons/bricks/docs/BRICKS_ADDON_PROGRESS_TRACKER.md`

Implementation should follow that matrix as the configuration contract.

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
- Admin menu wiring for conditional submenu registration:
  - `admin/admin-menu.php` (`add_submenu_page` under `dbvc-export`).

## 3) UI Plan: Configure > General Settings > Add-ons > Bricks

Activation model (locked):
- Add-ons are toggled in `Configure -> Add-ons` (core configure subtab).
- Bricks add-on submenu under DBVC appears only when `dbvc_addon_bricks_enabled=1`.
- When disabled, Bricks add-on routes/hooks/jobs are not registered.

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

### 3.3 Input help text contract (show beneath inputs)
- `Add-on Visibility Mode` (`dbvc_addon_bricks_visibility`)
  - Help text:
    - "`configure_and_submenu` (recommended): show Bricks settings in Configure and submenu when enabled."
    - "`submenu_only`: hide Bricks settings from Configure and use submenu only."
- `Mothership Base URL` (`dbvc_bricks_mothership_url`)
  - Help text:
    - "Use the mothership site base origin only (no trailing slash, no `/wp-json`)."
    - "Example: `https://dbvc-mothership.local` for LocalWP."
    - "Required when role is `client`; leave empty when role is `mothership`."

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

### Push/Pull transport (phase 14/15 target)
6. `POST /packages`
- Purpose: client publishes package to mothership.
- Required headers: idempotency key + correlation ID.
- Request body (shape):
```json
{
  "package": {
    "package_id": "pkg_client_001",
    "schema_version": "1.0.0",
    "version": "1.0.0",
    "channel": "canary",
    "source_site": {
      "site_uid": "client-site-1",
      "base_url": "https://client-site.local"
    },
    "artifacts": []
  },
  "targeting": {
    "mode": "all",
    "site_uids": []
  }
}
```
- Response:
```json
{"ok":true,"package_id":"pkg_client_001","status":"PUBLISHED","receipt_id":"pkg_rcpt_123"}
```

7. `POST /packages/{package_id}/promote`
- Purpose: mothership promotes package across channels (`canary -> beta -> stable`).

8. `POST /packages/{package_id}/revoke`
- Purpose: mothership emergency stop for package distribution.

9. `GET /connected-sites`
- Purpose: mothership lists connected client sites for selective rollout.

10. `POST /connected-sites`
- Purpose: register/update connected site metadata and auth profile.

11. `POST /packages/{package_id}/ack`
- Purpose: client acknowledges receipt/pull/apply outcome back to mothership.

### Connected-sites selective rollout UI contract
- Add mothership table panel:
  - columns: `Site`, `Site UID`, `Base URL`, `Status`, `Last Seen`, `Auth Mode`, `Allowed`.
  - controls: search, status filter, sort by last seen.
  - selection:
    - `All sites`,
    - `Selected sites` with row checkboxes and "select all visible".
- Package publish form includes:
  - target mode select (`all` vs `selected`),
  - target site selection table (shown when `selected`),
  - summary badge: `N selected / M connected`.

### Push/pull implementation notes
- Server must enforce targeting regardless of client UI state.
- Client only sees packages where:
  - `target_mode=all`, or
  - its `site_uid` is listed in `target_sites`.
- Every publish/pull/apply action logs:
  - actor,
  - source site,
  - target mode + selected sites,
  - correlation ID.

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

## 10) Execution Checklist (Phased, with Sub-tasks)

### Phase 1: Configuration + contracts
- Add Configure -> Add-ons -> Bricks UI shell using existing DBVC subtab patterns.
- Add all fields from the matrix with strict sanitization + allowlists.
- Add read/write tests for options defaults and invalid input handling.
- Sub-tasks:
  - add central option key map,
  - add validator helpers,
  - add migration defaults bootstrap.

### Phase 2: Artifact engine (read-only first)
- Implement Bricks artifact registry for Entity + option artifacts.
- Implement canonicalization + fingerprint (`sha256:<hex>`) utilities.
- Implement drift scan endpoint and read-only UI status output.
- Sub-tasks:
  - canonical fixture set for every artifact type,
  - deterministic ordering for nested arrays,
  - size guards for large payloads.

### Phase 3: Apply/restore safety
- Implement restore point creation before apply.
- Implement apply planner (dry-run default) + policy gates.
- Implement verification pass and rollback-on-failure path.
- Sub-tasks:
  - destructive change guardrails,
  - idempotency keys for apply calls,
  - post-apply hash audit logging.

### Phase 4: Proposal pipeline
- Implement proposal submit/list/status endpoints.
- Implement mothership queue and review actions.
- Implement status machine + actor attribution/audit trail.
- Sub-tasks:
  - dedupe by `(artifact_uid, base_hash, proposed_hash)`,
  - SLA/aging badges,
  - reject/needs-changes feedback loop.

### Phase 5: QA and hardening
- Add unit tests: canonicalization, hashes, policy resolver, state transitions.
- Add integration tests: satellite -> mothership -> approve -> apply.
- Add manual QA checklist and rollback drills.
- Sub-tasks:
  - schema drift tests across Bricks versions,
  - performance baseline for large option payloads,
  - regression fixture pack for known noisy fields.
