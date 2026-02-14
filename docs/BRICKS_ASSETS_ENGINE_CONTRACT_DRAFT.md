# Bricks Assets Engine Contract Draft (DBVC Add-on)

Date: 2026-02-12  
Status: Discovery draft (planning only)

## 1) Contract Intent (Updated)

This contract is now DBVC-native and Add-on driven:
- Bricks Add-on is the caller and orchestration layer.
- DBVC core remains the artifact engine foundation.
- External integrations (VF or others) become optional clients of DBVC REST.

Terminology: use **Entity** for post/term-backed records.

## 2) Proposed Add-on Service Surface

```php
<?php

interface DBVC_Bricks_Addon_Engine_Interface
{
    // Artifact extraction
    public function export_artifact(string $artifact_type, array $locator, array $context = []): array;

    // Canonicalization + hashing
    public function canonicalize(array $payload, array $rules = []): array;
    public function fingerprint(array $canonical_payload, array $options = []): string; // returns sha256:<hex>

    // Diff + drift
    public function diff_summary(array $current_canonical, array $target_canonical, array $options = []): array;
    public function scan_drift(array $package_manifest, array $scan_scope = [], array $options = []): array;

    // Package lifecycle
    public function build_golden_package(array $artifacts, array $meta = []): array;
    public function list_golden_packages(array $filters = []): array;
    public function get_golden_package(string $package_id): array;

    // Apply + rollback safety
    public function create_restore_point(array $scope = [], array $options = []): array;
    public function apply_golden_package(string $package_id, array $selection = [], array $options = []): array;

    // Proposal pipeline
    public function build_proposal(array $artifact_change, array $meta = []): array;
    public function submit_proposal(array $proposal, array $connection = []): array;
}
```

## 3) Mapping to Existing DBVC Capabilities

### Reuse directly
- Export primitives:
  - `DBVC_Sync_Posts::prepare_post_export(...)` (`includes/class-sync-posts.php:2995`)
  - `DBVC_Sync_Taxonomies::export_term(...)` (`includes/class-sync-taxonomies.php:293`)
  - `DBVC_Sync_Posts::export_options_to_json(...)` baseline (`includes/class-sync-posts.php:3326`)
- Canonical helpers:
  - `dbvc_normalize_for_json(...)` (`includes/functions.php:794`)
  - `dbvc_sort_array_recursive(...)` (`includes/functions.php:830`)
- Package/history:
  - `DBVC_Backup_Manager::generate_manifest/read_manifest` (`includes/class-backup-manager.php`)
  - `DBVC_Sync_Posts::import_backup(...)` (`includes/class-sync-posts.php:1020`)
  - `DBVC_Snapshot_Manager` and `DBVC_Database` snapshots (`includes/class-snapshot-manager.php`, `includes/class-database.php`)
  - `Dbvc\Official\Collections::mark_official(...)` (`includes/Dbvc/Official/Collections.php:37`)
- Entity UID:
  - `ensure_post_uid`, `ensure_term_uid`, `dbvc_entities` registry.

### Add-on-specific additions needed
- Bricks artifact adapter for:
  - `bricks_template` (Entity-backed)
  - `bricks_global_classes`, `bricks_global_variables` (options-backed).
- Bricks-specific canonicalization rules and deterministic set-order handling.
- Drift status model and governance policy evaluation.
- Proposal lifecycle persistence and mothership REST endpoints.

## 4) Contract Data Shapes

### Artifact object

```php
[
  'artifact_uid'  => 'vf_object_uid or option:<key>',
  'artifact_type' => 'bricks_template|bricks_global_classes|bricks_global_variables',
  'entity_type'   => 'post|option',
  'entity_ref'    => ['post_id' => 123] | ['option_key' => 'bricks_global_classes'],
  'label'         => 'Header Template',
  'payload'       => [...],
  'canonical'     => [...],
  'hash'          => 'sha256:...',
  'meta'          => ['exported_at' => 'UTC', 'dbvc_version' => '...'],
]
```

### Golden package manifest (Bricks add-on)

```php
[
  'schema'       => 1,
  'domain'       => 'dbvc.bricks',
  'package_id'   => 'uuid',
  'version'      => 'x.y.z',
  'channel'      => 'stable',
  'generated_at' => 'UTC',
  'site'         => [...],
  'artifacts'    => [
    [
      'artifact_uid'  => '...',
      'artifact_type' => '...',
      'hash'          => 'sha256:...',
      'payload_path'  => 'artifacts/<uid>.json',
      'entity_ref'    => [...],
    ],
  ],
  'totals'       => ['artifacts' => 0, 'bytes' => 0],
  'signature'    => ['alg' => 'hmac-sha256', 'value' => '...'],
  'checksum'     => 'sha256:...'
]
```

### Drift scan result

```php
[
  'package_id' => '...',
  'counts'     => [
    'clean'          => 0,
    'diverged'       => 0,
    'overridden'     => 0,
    'pending_review' => 0,
  ],
  'artifacts'  => [
    [
      'artifact_uid'  => '...',
      'artifact_type' => '...',
      'status'        => 'CLEAN|DIVERGED|OVERRIDDEN|PENDING_REVIEW',
      'local_hash'    => 'sha256:...',
      'golden_hash'   => 'sha256:...',
      'diff_summary'  => ['total' => 0, 'changes' => []],
      'policy'        => 'AUTO_ACCEPT|REQUIRE_MANUAL_ACCEPT|ALWAYS_OVERRIDE|REQUEST_REVIEW|IGNORE',
    ],
  ],
]
```

### Proposal object

```php
[
  'proposal_id'          => 'uuid',
  'artifact_uid'         => '...',
  'artifact_type'        => '...',
  'base_golden_version'  => 'x.y.z',
  'base_hash'            => 'sha256:...',
  'proposed_hash'        => 'sha256:...',
  'canonical_payload'    => [...],
  'diff_summary'         => [...],
  'notes'                => '...',
  'tags'                 => ['...'],
  'status'               => 'DRAFT|SUBMITTED|RECEIVED|APPROVED|REJECTED|NEEDS_CHANGES',
]
```

## 5) Add-on REST Endpoint Plan (DBVC-owned)

Namespace: `dbvc/v1/bricks`

### Golden packages
1. `GET /dbvc/v1/bricks/packages`
- Purpose: list available golden packages.
- Query: `channel`, `limit`, `cursor`, `version` (optional).
- Response:
```json
{ "items": [{"package_id":"...","version":"1.2.0","generated_at":"...","channel":"stable","artifacts":42}], "next_cursor": null }
```

2. `GET /dbvc/v1/bricks/packages/{package_id}`
- Purpose: fetch package manifest (+ optionally payload index).
- Query: `include_payload_index=true|false`.
- Response:
```json
{ "manifest": {"package_id":"...","version":"...","artifacts":[...]}, "payload_index": [{"path":"artifacts/...json","hash":"sha256:..."}] }
```

### Proposal pipeline (mothership)
3. `POST /dbvc/v1/bricks/proposals`
- Purpose: submit proposal from ClientSite.
- Body:
```json
{ "proposal_id":"...","artifact_uid":"...","artifact_type":"...","base_golden_version":"...","base_hash":"sha256:...","proposed_hash":"sha256:...","canonical_payload":{},"diff_summary":{},"notes":"...","tags":["..."] }
```
- Response:
```json
{ "ok": true, "proposal_id": "...", "status": "RECEIVED" }
```

4. `GET /dbvc/v1/bricks/proposals`
- Purpose: mothership review queue listing.
- Query: `status`, `limit`, `cursor`.
- Response:
```json
{ "items": [{"proposal_id":"...","artifact_uid":"...","status":"RECEIVED","submitted_at":"..."}], "next_cursor": null }
```

5. `PATCH /dbvc/v1/bricks/proposals/{proposal_id}`
- Purpose: review decision update.
- Body:
```json
{ "status":"APPROVED|REJECTED|NEEDS_CHANGES", "review_notes":"...", "publish_version":"1.3.0" }
```
- Response:
```json
{ "ok": true, "proposal_id":"...", "status":"APPROVED", "published_package_id":"..." }
```

## 6) UI Contract for Configure > Add-ons > Bricks (Planning)

Planned nested structure under existing Configure patterns:
- General Settings
  - Add-ons
    - Bricks
      - Connection
      - Golden Source
      - Policies
      - Operations
      - Proposals

Implementation note:
- Current DBVC has no add-on registry and no Add-ons subtab yet (`admin/admin-page.php` currently defines fixed `$config_subtabs`).
- Recommended path: add a new Configure subtab (e.g., `dbvc-config-addons`) and nested subtab panel for Bricks first.

## 7) UID and Entity Strategy

- Entity-backed Bricks artifacts (`bricks_template`) use existing `vf_object_uid` and `dbvc_entities` mapping.
- Option-backed artifacts use deterministic UID scheme (`option:<option_key>`) unless/until DBVC introduces an explicit artifact UID registry for non-Entity storage.

## 8) Storage Notes (Planning)

Prefer reuse:
- `dbvc_snapshots`, `dbvc_snapshot_items`
- `dbvc_collections`, `dbvc_collection_items`
- file manifests under existing backup/package paths

Add-on-specific storage only if needed:
- proposal review lifecycle queue
- per-artifact governance policy/state registry

## 9) Recommended Next Implementation Slice (Post-Discovery)

1. Add add-on UI shell + settings storage under Configure.
2. Implement MVP artifact adapter for:
   - `bricks_template`
   - `bricks_global_classes`
   - `bricks_global_variables`
3. Wire drift scan using canonical + fingerprint.
4. Add package list/fetch + proposal submit/list/status REST endpoints.
5. Keep apply/restore flows on top of existing DBVC backup/manifest/import primitives.
