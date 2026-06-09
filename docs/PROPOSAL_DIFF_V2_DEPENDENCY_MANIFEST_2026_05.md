# Proposal Diff v2 Dependency Manifest

Date: 2026-05-23

Scope: audit of add-ons, tools, tests, admin UI code, and adjacent core services that rely on the existing core DBVC Proposal/diff v1 system.

Status: planning only. No production code was changed for this manifest.

## Purpose

The proposed Proposal Diff v2 work must not break existing add-ons or tools that rely on current proposal upload, review, diff, resolver, masking, transfer packet, or apply behavior.

This document is the dependency library for those v1 surfaces. Treat it as the compatibility checklist before any v2 implementation starts.

## Summary

The direct runtime dependency picture is narrower than the names suggest:

- Visual Editor: no direct dependency found on core Proposal/diff v1.
- Content Migration add-on: no direct dependency found on core Proposal/diff v1.
- Bricks add-on: has a separate proposal system and naming overlap, but no direct runtime dependency found on core Proposal/diff v1 routes or decision stores.
- Entity Editor transfer packets: strong dependency on core proposal bundle generation, proposal upload/review, transfer metadata in proposal payloads, and proposal apply.
- WP-CLI proposal commands: strong direct dependency on `DBVC_Admin_App` v1 public methods and proposal storage helpers.
- Admin Proposal Review app: strongest direct dependency on v1 REST response shapes and route behavior.
- Media resolver/bundle/reconciler: strong dependency on proposal ID, manifest shape, bundle metadata, resolver decisions, and apply flow.
- Masking tests and masking review UI: strong dependency on v1 masking routes and `dbvc_proposal_decisions`.
- Configuration Portability: low runtime dependency, but it exports/imports proposal-related settings and must preserve option names.
- AI Package intake: medium coupling because AI package detection currently happens through the core proposal upload route before normal proposal registration.

## Dependency Classes

### Hard Dependency

A hard dependency means Proposal Diff v2 must preserve the v1 surface or provide a compatibility adapter before changing behavior.

### Soft Dependency

A soft dependency means naming, docs, settings, or shared helper behavior could be affected, but the add-on/tool does not directly execute the core v1 proposal review path.

### No Direct Dependency Found

No direct dependency found means search and spot inspection did not find a runtime dependency on the core v1 proposal diff system. This does not mean the code is unaffected by shared DBVC import/export helpers.

## Hard Dependencies

### 1. Admin Proposal Review App

Dependency level: hard.

Primary files:

- `src/admin-app/index.js`
- `admin/class-admin-app.php`

V1 REST routes consumed by the UI:

| Route | Current purpose | v2 compatibility requirement |
| --- | --- | --- |
| `GET /dbvc/v1/proposals` | Proposal list, status, resolver metrics, decisions, transfer metadata. | Keep response shape stable. Add v2 fields only additively. |
| `POST /dbvc/v1/proposals/upload` | Proposal and transfer packet upload. Also routes AI packages before normal proposal registration. | Keep v1 route behavior. Do not break AI package detection. |
| `DELETE /dbvc/v1/proposals/{proposal_id}` | Delete unlocked proposal folder and related state. | Keep v1 route until all UI/CLI paths are migrated. |
| `GET /dbvc/v1/proposals/{proposal_id}/entities` | Entity rows, diff counts, resolver summaries, transfer metadata. | Keep current fields. Add v2 preflight fields side-by-side only. |
| `GET /dbvc/v1/proposals/{proposal_id}/entities/{vf_object_uid}` | Entity drawer, current/proposed payloads, diff, decisions. | Do not change v1 diff shape. Fix unsafe pruning under v1 before v2 rollout. |
| `POST /dbvc/v1/proposals/{proposal_id}/entities/{vf_object_uid}/selections` | Single path decision writes. | Keep current action names and decision summary shape. |
| `POST /dbvc/v1/proposals/{proposal_id}/entities/{vf_object_uid}/selections/bulk` | Bulk path decision writes. | Keep current path/action payload shape. |
| `POST /dbvc/v1/proposals/{proposal_id}/entities/accept` | Bulk accept existing/new entities. | Keep current response fields. |
| `POST /dbvc/v1/proposals/{proposal_id}/entities/unaccept` | Bulk clear accept decisions. | Keep current response fields. |
| `POST /dbvc/v1/proposals/{proposal_id}/entities/unkeep` | Bulk clear keep decisions. | Keep current response fields. |
| `POST /dbvc/v1/proposals/{proposal_id}/entities/{vf_object_uid}/snapshot` | Capture one entity snapshot. | Preserve route and response shape. |
| `POST /dbvc/v1/proposals/{proposal_id}/snapshot` | Capture proposal snapshots. | Preserve route and response shape. |
| `POST /dbvc/v1/proposals/{proposal_id}/entities/{vf_object_uid}/hash-sync` | Store entity import hash. | Preserve route and response shape. |
| `POST /dbvc/v1/proposals/{proposal_id}/entities/hash-sync` | Bulk import hash sync. | Preserve route and response shape. |
| `GET /dbvc/v1/proposals/{proposal_id}/resolver` | Full resolver report. | Keep metrics, attachments, decisions, bundle previews. |
| `POST /dbvc/v1/proposals/{proposal_id}/resolver/{original_id}` | Save resolver decision. | Preserve original-ID keyed behavior until v2 media identity is introduced. |
| `DELETE /dbvc/v1/proposals/{proposal_id}/resolver/{original_id}` | Delete resolver decision. | Preserve proposal/global scope semantics. |
| `GET /dbvc/v1/resolver-rules` | List global resolver rules. | Preserve CSV and UI workflows. |
| `POST /dbvc/v1/resolver-rules` | Upsert global resolver rule. | Preserve original-ID keyed behavior. |
| `POST /dbvc/v1/resolver-rules/import` | Import resolver rules. | Preserve CSV parser expectations. |
| `POST /dbvc/v1/resolver-rules/bulk-delete` | Delete selected global resolver rules. | Preserve payload shape. |
| `DELETE /dbvc/v1/resolver-rules/{original_id}` | Delete global resolver rule. | Preserve route. |
| `GET /dbvc/v1/proposals/{proposal_id}/masking` | Masking candidates. | Preserve pagination and field payloads. |
| `POST /dbvc/v1/proposals/{proposal_id}/masking/apply` | Stamp masking decisions and directives. | Preserve decision side effects until v2 has compatibility. |
| `POST /dbvc/v1/proposals/{proposal_id}/masking/revert` | Revert masking decisions/directives. | Preserve route and counters. |
| `POST /dbvc/v1/proposals/{proposal_id}/duplicates/cleanup` | Remove duplicate manifest payload entries. | Preserve confirmation contract. Fix CLI private constant dependency. |
| `GET /dbvc/v1/proposals/{proposal_id}/duplicates` | Duplicate report. | Preserve route. |
| `POST /dbvc/v1/proposals/{proposal_id}/apply` | Apply proposal through v1 importer. | Keep v1 available. Add v2 apply route separately. |
| `POST /dbvc/v1/proposals/{proposal_id}/status` | Reopen/close proposal. | Preserve route. |

UI-specific response fields that must remain stable:

- proposal: `id`, `title`, `generated_at`, `status`, `locked`, `resolver`, `media_bundle`, `decisions`, `duplicate_count`, `new_entities`
- transfer packet: `origin`, `selection`, `requirements`, `preflight`, `warnings`
- entity row: `vf_object_uid`, `entity_type`, `post_id`, `post_type`, `post_title`, `path`, `hash`, `content_hash`, `diff_state`, `diff_total`, `meta_diff_count`, `tax_diff_count`, `resolver`, `overall_status`, `is_new_entity`, `new_entity_decision`, `decision_summary`
- entity detail: `current`, `current_source`, `proposed`, `diff`, `decisions`, `decision_summary`, `identity_match`, `new_entity_decision`
- apply response: `result`, `decisions_before`, `decisions`, `resolver_decisions`, `auto_clear_enabled`, `decisions_cleared`, `status`

v2 rule:

- Do not repoint the current admin app to v2 until v2 has passed read-only shadow comparison against these fields.

### 2. WP-CLI Proposal Commands

Dependency level: hard.

Primary file:

- `commands/class-wp-cli-commands.php`

Direct v1 dependencies:

| CLI behavior | v1 dependency | Compatibility requirement |
| --- | --- | --- |
| `wp dbvc proposals list` | Calls `DBVC_Admin_App::get_proposals()` through a REST request object. | Keep method callable or add a v1 facade. |
| `wp dbvc proposals upload` | Calls `DBVC_Admin_App::import_proposal_from_zip()`. | Keep method callable and v1 bundle registration behavior. |
| `wp dbvc proposals apply` | Builds REST request and calls `DBVC_Admin_App::apply_proposal()`. | Keep method callable and v1 response shape. |
| Duplicate cleanup | Calls `DBVC_Admin_App::cleanup_proposal_duplicates()` and references `DBVC_Admin_App::DUPLICATE_BULK_CONFIRM_PHRASE`. | Fix private constant issue before rollout. |
| Snapshot recapture | Reads `DBVC_Backup_Manager::MANIFEST_FILENAME` under `DBVC_Backup_Manager::get_base_path()` and calls `DBVC_Snapshot_Manager::capture_for_proposal()`. | Preserve storage path or add repository helper while keeping CLI behavior. |
| Resolver rules | Calls `DBVC_Admin_App` resolver rule methods. | Preserve v1 resolver rule API until v2 media identity migration is complete. |

v2 rule:

- Keep `wp dbvc proposals ...` on v1 by default.
- Add `--engine=v2` or a new `wp dbvc proposal-diff-v2 ...` command only after v2 shadow mode is stable.

### 3. Entity Editor Transfer Packets

Dependency level: hard.

Primary files:

- `admin/class-entity-editor-app.php`
- `src/admin-entity-editor/index.js`
- `includes/Dbvc/Transfer/EntityPacketBuilder.php`
- `admin/class-admin-app.php`
- `tests/phpunit/TransferPacketWorkflowTest.php`

Source-side dependencies:

| Component | v1 dependency | Compatibility requirement |
| --- | --- | --- |
| `DBVC_Entity_Editor_App::handle_transfer_packet()` | Calls `Dbvc\Transfer\EntityPacketBuilder::build_from_entity_paths()`. | Preserve packet builder behavior. |
| `DBVC_Admin_App::preview_entity_editor_transfer_packet()` | Calls `EntityPacketBuilder::preview_from_entity_paths()`. | Preserve preview response shape. |
| Entity Editor JS | Calls `entity-editor/transfer-preview` and links to `admin.php?page=dbvc-export#proposal-review`. | Preserve destination review entry point. |
| `EntityPacketBuilder::generate_transfer_manifest()` | Calls `DBVC_Backup_Manager::generate_manifest()` and `read_manifest()`. | Keep v1-compatible manifest generation or fork a v2 packet builder separately. |
| `EntityPacketBuilder::stage_live_post()` | Calls `DBVC_Sync_Posts::stage_post_export()`. | Preserve staged export helper. |
| `EntityPacketBuilder::stage_live_term()` | Calls `DBVC_Sync_Taxonomies::stage_term_export()`. | Preserve staged term export helper. |
| Transfer metadata | Adds `origin`, `selection`, `requirements`, `warnings`, and strips absolute media bundle paths. | v2 generic preflight must preserve and wrap these fields, not replace them. |

Destination-side dependencies:

- Transfer packet ZIPs are uploaded through `POST /dbvc/v1/proposals/upload`.
- Transfer packet metadata is surfaced by `DBVC_Admin_App::build_transfer_packet_context()`.
- Destination warnings are calculated by `build_transfer_preflight()`.
- Apply goes through `DBVC_Admin_App::apply_proposal()` and `DBVC_Sync_Posts::import_backup()`.

Tests that define compatibility:

- `TransferPacketWorkflowTest::test_transfer_preview_endpoint_surfaces_unsupported_post_reference_warning()`
- `TransferPacketWorkflowTest::test_import_proposal_from_zip_rejects_missing_payload_files()`
- `TransferPacketWorkflowTest::test_import_proposal_from_zip_rejects_missing_media_bundle_assets()`

v2 rule:

- Do not route transfer packets into v2 apply by default.
- In v2 shadow mode, transfer packets should run v2 preflight read-only while v1 remains authoritative.
- Transfer metadata must remain additive and stable.

### 4. Proposal Masking Workflow

Dependency level: hard.

Primary files:

- `admin/class-admin-app.php`
- `includes/class-sync-posts.php`
- `src/admin-app/index.js`
- `tests/phpunit/MaskingEndpointsTest.php`
- `docs/meta-masking.md`

V1 dependencies:

| Surface | Dependency | Compatibility requirement |
| --- | --- | --- |
| Masking candidate route | `GET /proposals/{proposal_id}/masking` | Preserve pagination and field shape. |
| Masking apply route | `POST /proposals/{proposal_id}/masking/apply` | Preserve stamping into `dbvc_proposal_decisions`, suppressions, and overrides. |
| Masking revert route | `POST /proposals/{proposal_id}/masking/revert` | Preserve cleanup behavior. |
| Decision store | `dbvc_proposal_decisions` | v2 must not change v1 option shape in-place. |
| Import apply | `DBVC_Sync_Posts::import_post_from_json()` receives mask directives. | Keep post behavior stable. Fix term behavior before claiming v2 parity. |

Tests that define compatibility:

- `MaskingEndpointsTest::test_masking_endpoint_paginates_and_clamps_chunk_size()`
- `MaskingEndpointsTest::test_apply_masking_records_entries_by_path()`
- `MaskingEndpointsTest::test_masking_endpoint_includes_post_fields()`
- `MaskingEndpointsTest::test_apply_masking_accepts_post_fields()`
- `MaskingEndpointsTest::test_revert_masking_clears_applied_decisions()`
- `MaskingEndpointsTest::test_import_post_respects_post_field_masking()`

v2 rule:

- v2 decision storage must not overwrite v1 masking decisions during shadow mode.
- v2 preflight must report masking impact from v1 stores until v2 masking has a compatible store.

### 5. Media Resolver, Bundle, and Reconciler

Dependency level: hard.

Primary files:

- `includes/Dbvc/Media/Resolver.php`
- `includes/Dbvc/Media/Reconciler.php`
- `includes/Dbvc/Media/BundleManager.php`
- `includes/class-media-sync.php`
- `admin/class-admin-app.php`
- `includes/class-sync-posts.php`

V1 dependencies:

| Surface | Dependency | Compatibility requirement |
| --- | --- | --- |
| Resolver summary | `Dbvc\Media\Resolver::resolve_manifest($manifest, $options)` | Preserve options and output shape. |
| Bundle file lookup | `BundleManager::locate_bundle_file($proposal_id, ...)` | Proposal ID and bundle storage must remain v1-compatible. |
| Bundle ingest | `BundleManager::ingest_from_backup($proposal_id, $backup_path)` | Preserve upload/apply behavior. |
| Reconcile apply | `Reconciler::enqueue($proposal_id, $manifest, $args)` | Preserve apply-side media behavior. |
| Resolver decisions | `dbvc_resolver_decisions` option and original-ID keyed rules. | Keep v1 store stable. Introduce stable media identity in v2 only additively. |
| Legacy media sync | `DBVC_Media_Sync::sync_manifest_media()` after proposal import. | Do not remove until v2 defines one authoritative media plan. |

v2 rule:

- v2 may introduce `asset_uid` or file-hash based media identity, but v1 original-ID rules must keep working.
- v2 preflight should consume the v1 resolver report until the new media identity layer is proven.

### 6. Core Proposal Storage and Import Helpers

Dependency level: hard.

Primary files:

- `includes/class-backup-manager.php`
- `includes/class-snapshot-manager.php`
- `includes/class-sync-posts.php`
- `admin/class-admin-app.php`
- `commands/class-wp-cli-commands.php`
- `includes/Dbvc/Transfer/EntityPacketBuilder.php`

V1 helpers and storage contracts:

| Helper or contract | Current users | Compatibility requirement |
| --- | --- | --- |
| `DBVC_Backup_Manager::MANIFEST_FILENAME` | CLI, admin app, transfer packet builder, tests. | Keep as `manifest.json`. |
| `DBVC_Backup_Manager::generate_manifest()` | exports, transfer packets, CLI manifest generation. | Do not alter v1 manifest shape in-place. |
| `DBVC_Backup_Manager::read_manifest()` | admin app, CLI, transfer packet builder. | Keep readable for existing proposal folders. |
| `DBVC_Backup_Manager::list_backups()` | proposal list route. | Preserve v1 proposal list source. |
| `DBVC_Backup_Manager::get_base_path()` | admin app and CLI read proposal folders. | Keep v1 base path stable. |
| `DBVC_Snapshot_Manager::capture_for_proposal()` | upload, snapshot routes, CLI recapture. | Preserve signature and snapshot file layout. |
| `DBVC_Sync_Posts::import_backup()` | proposal apply route. | Keep v1 apply available. v2 apply must be separate. |
| `DBVC_Sync_Posts::import_post_from_json()` | proposal apply, masking tests, AI package importer. | Do not break signature or mask-directive behavior. |
| `DBVC_Sync_Posts::stage_post_export()` | transfer packet builder. | Preserve staged export behavior. |
| `DBVC_Sync_Taxonomies::stage_term_export()` | transfer packet builder. | Preserve staged term export behavior. |
| `DBVC_Sync_Posts::import_resolver_decisions_from_manifest()` | proposal upload, CLI media sync. | Preserve v1 resolver import. |

v2 rule:

- Do not modify v1 storage contracts directly.
- If v2 needs different metadata, add it under v2 keys or a separate v2 receipt/session area.

## Soft Dependencies

### 1. Bricks Add-on

Dependency level: soft.

Primary files:

- `addons/bricks/bricks-proposals.php`
- `addons/bricks/bricks-addon.php`
- `addons/bricks/portability/*`
- `tests/phpunit/BricksAddonPhase6Test.php`
- `tests/phpunit/BricksAddonPhase10Test.php`
- `tests/phpunit/BricksAddonIdempotencyTest.php`

Findings:

- Bricks has its own proposal system at `/dbvc/v1/bricks/proposals`.
- Bricks proposal records live in `dbvc_bricks_proposals_queue`.
- Bricks proposal lifecycle uses `DBVC_Bricks_Proposals::submit()` and `DBVC_Bricks_Proposals::transition()`.
- Bricks portability packages use `manifest.json`, but this is their own package manifest, not core proposal v1 storage.
- Bricks docs mention `DBVC_Backup_Manager`, `DBVC_Snapshot_Manager`, and `DBVC_Sync_Posts::import_backup()` as older planning/reuse concepts, but direct runtime coupling to the core Proposal/diff v1 review routes was not found.

Risk:

- Naming collision. Changing core "proposal" terminology could confuse Bricks proposal docs, tests, and operators.

v2 rule:

- Use "Core Proposal Diff v2" or "Content Proposal Diff v2" in docs and UI when needed.
- Do not change `/dbvc/v1/bricks/proposals`.

### 2. Configuration Portability

Dependency level: soft.

Primary file:

- `includes/Dbvc/ConfigurationPortability/Providers/CoreImportExportProvider.php`

Findings:

- The provider exports/imports proposal-related options:
  - `dbvc_auto_clear_decisions`
  - `dbvc_import_require_review`
- The configuration portability import session model is not dependent on core proposal v1 internals.

Risk:

- Renaming or replacing proposal options without compatibility would break configuration portability packages.

v2 rule:

- Preserve existing option names.
- Add v2 settings separately, for example `dbvc_proposal_diff_engine` or `dbvc_proposal_diff_v2_enabled`.

### 3. AI Package Intake

Dependency level: soft to medium.

Primary files:

- `admin/class-admin-app.php`
- `includes/Dbvc/AiPackage/SubmissionPackageDetector.php`
- `includes/Dbvc/AiPackage/SubmissionPackageValidator.php`
- `includes/Dbvc/AiPackage/SubmissionPackageImporter.php`

Findings:

- AI package uploads are currently detected inside the core proposal upload handler before normal proposal registration.
- AI package import later uses DBVC import helpers such as `DBVC_Sync_Posts::import_selected_post_files()`, not the core Proposal/diff review system.

Risk:

- If v2 replaces or bypasses `POST /proposals/upload`, AI package uploads could stop routing to AI intake.

v2 rule:

- Keep AI detection in v1 upload route.
- If v2 gets its own upload route, explicitly decide whether AI packages are rejected, delegated to v1, or routed to AI intake.

### 4. Legacy Upload and Universal Intake Planning

Dependency level: soft.

Primary files:

- `docs/legacy-upload-immediate-import-plan.md`
- `docs/ROADMAP.md`
- `admin/admin-page.php`
- `includes/class-sync-posts.php`

Findings:

- Legacy upload and targeted immediate import are separate workflows.
- Roadmap mentions future universal upload intake that may optionally emit a proposal bundle.

Risk:

- Proposal Diff v2 could be accidentally widened into universal intake work.

v2 rule:

- Do not merge these flows in the Proposal Diff v2 rollout.
- v2 can later become the review target for universal intake, but only after v2 is stable.

## No Direct Dependency Found

### Visual Editor

Dependency level: no direct dependency found.

Searched areas:

- `addons/visual-editor`

Findings:

- No references found to `DBVC_Admin_App`, core proposal routes, `dbvc_proposal_decisions`, `dbvc_resolver_decisions`, `DBVC_Backup_Manager`, or proposal apply helpers.
- Visual Editor uses its own descriptor, resolver, session, and REST architecture.

v2 rule:

- No direct migration work is needed for Visual Editor.
- Still run a basic plugin smoke check if shared DBVC bootstrap or capability code is touched.

### Content Migration Add-on

Dependency level: no direct dependency found.

Searched areas:

- `addons/content-migration`

Findings:

- No direct references found to core proposal routes, v1 decision stores, `DBVC_Admin_App`, or proposal apply helpers.
- Content Migration has its own run/package/preflight/import execution model.

v2 rule:

- No direct migration work is needed for Content Migration.
- Keep Proposal Diff v2 isolated from Content Migration v2 run/preflight terminology.

## Tests As Compatibility Contracts

These tests should be treated as dependency tests before v2 is enabled beyond shadow mode:

| Test file | Protects |
| --- | --- |
| `tests/phpunit/TransferPacketWorkflowTest.php` | Transfer preview, proposal ZIP validation, media bundle asset validation. |
| `tests/phpunit/MaskingEndpointsTest.php` | Proposal masking routes, masking decisions, post-field masking import behavior. |
| `tests/phpunit/BricksAddonPhase6Test.php` | Bricks proposal lifecycle independent of core proposals. |
| `tests/phpunit/BricksAddonPhase10Test.php` | Bricks proposal UI/action workflow independent of core proposals. |
| `tests/phpunit/BricksAddonIdempotencyTest.php` | Bricks proposal idempotency. |
| `tests/phpunit/ConfigurationPortabilityExportPackageTest.php` | Configuration portability package/session/preflight model that v2 should emulate but not break. |

Additional tests needed before v2 rollout:

- v1 proposal list remains stable with v2 disabled.
- v1 proposal upload still routes AI packages.
- v1 proposal upload still accepts transfer packets.
- v1 transfer metadata still appears on proposal list/entity payloads.
- v1 masking decisions survive v2 shadow reads.
- v1 resolver decisions are not rewritten by v2 shadow.
- v2 shadow does not write to `dbvc_proposal_decisions`.
- v2 shadow does not write to `dbvc_resolver_decisions`.
- v2 shadow does not change proposal status.
- v2 shadow does not prune decisions.

## Compatibility Rules For Proposal Diff v2

1. Keep v1 REST routes active and authoritative by default.
2. Keep `DBVC_Admin_App` v1 public methods callable until all CLI and tests migrate.
3. Keep v1 option stores readable and writable by v1 only:
   - `dbvc_proposal_decisions`
   - `dbvc_resolver_decisions`
   - `dbvc_masked_field_suppressions`
   - `dbvc_mask_overrides`
4. Keep `manifest.json` as the v1 bundle manifest filename.
5. Keep transfer packet additive metadata fields:
   - `origin`
   - `selection`
   - `requirements`
   - `warnings`
6. Preserve v1 media resolver behavior until v2 media identity is additive and tested.
7. Keep Bricks proposal routes separate and untouched.
8. Keep Visual Editor and Content Migration out of Proposal Diff v2 implementation scope.
9. Introduce v2 under separate routes, services, storage, and feature gates.
10. Do not switch any add-on/tool to v2 until its compatibility row is tested.

## Bottom Line

The add-ons are not broadly entangled with core Proposal/diff v1, but the few hard dependencies are important. Entity Editor transfer packets, WP-CLI proposal commands, the admin Proposal Review app, masking, and media resolver workflows all depend on v1 behavior. Proposal Diff v2 should be built beside v1, run in shadow first, and leave v1 routes and stores untouched until the dependency tests are green.
