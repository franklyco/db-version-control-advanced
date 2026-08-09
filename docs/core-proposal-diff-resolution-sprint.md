# Core Proposal Diff Resolution Sprint

Last updated: 2026-07-24

This guide tracks the remediation sprint for DBVC's core Proposal Diff workflow. It turns the Proposal Diff review findings into implementation-ready work, ordered from P0 through P5, with explicit dependent surfaces to QA after each change.

Progress log: `docs/core-proposal-diff-resolution-sprint-progress-log.md`

## Scope

In scope:
- Proposal ZIP intake, staging, snapshot capture, entity diff payloads, Accept/Keep/new-entity decisions, duplicate cleanup, masking, media resolver decisions, apply gating, apply summaries, admin UI status surfaces, WP-CLI parity, and tests/docs that assert those behaviors.

Out of scope:
- New Bricks add-on feature development, Golden Master governance, option-level import/export redesign, and broad admin app refactors unless they are required to stabilize Proposal Diff.

## Priority Legend

| Priority | Meaning | Release posture |
| --- | --- | --- |
| P0 | Data safety, security, or apply correctness can fail silently or bypass review. | Must fix before trusting Proposal Diff as a required workflow. |
| P1 | Core review behavior is incorrect, inconsistent, or can mislead reviewers into wrong decisions. | Fix in the main sprint before widening QA. |
| P2 | UX, diff fidelity, or status reporting is incomplete compared with the intended Proposal Diff contract. | Fix after P0/P1 or in parallel when low-risk. |
| P3 | Performance, automation, and maintainability gaps that increase regression risk. | Fix before large-proposal rollout. |
| P4 | Hardening and dev/prod hygiene issues adjacent to the core workflow. | Fix before production packaging. |
| P5 | Documentation cleanup and claim alignment after behavior is corrected. | Final stabilization pass. |

## Dependency Tags

- `core-rest`: `admin/class-admin-app.php`
- `core-importer`: `includes/class-sync-posts.php`
- `snapshot-manager`: `includes/class-snapshot-manager.php`
- `manifest-engine`: `includes/class-backup-manager.php`, proposal/backup `manifest.json`, `entities.jsonl`
- `media-resolver`: `includes/Dbvc/Media/Resolver.php`, `includes/Dbvc/Media/Reconciler.php`, `includes/class-media-sync.php`
- `media-hydration`: `includes/Dbvc/Media/Hydration/*`, `commands/class-media-hydration-cli.php`
- `configuration-portability`: `includes/Dbvc/ConfigurationPortability/Providers/MediaHandlingProvider.php`
- `transfer-media`: `includes/Dbvc/Transfer/EntityPacketBuilder.php`, Bricks Portability media/package services
- `admin-ui`: `src/admin-app/index.js`, `build/admin-app.js`, `src/admin-app/style.css`, `build/style-admin-app.css`
- `entity-editor`: `admin/class-entity-editor-app.php`, `includes/class-entity-editor-indexer.php`, `src/admin-entity-editor/index.js`, `build/admin-entity-editor.js`
- `duplicate-cleanup`: proposal `/duplicates` and `/duplicates/cleanup` REST flows
- `masking`: `docs/meta-masking.md`, `dbvc_mask_*`, `dbvc_masked_field_suppressions`, `dbvc_mask_overrides`
- `terms`: `docs/terms.md`, taxonomy entity import/export, `dbvc_force_reapply_new_posts`
- `wp-cli`: `wp dbvc proposals ...`, `wp dbvc resolver-rules ...`
- `settings`: Configure UI options, especially `dbvc_import_require_review`, `dbvc_auto_clear_decisions`, `dbvc_force_reapply_new_posts`, media options, logging options
- `classic-admin`: `admin/admin-page.php` restore/import/export forms and Configure subtabs
- `import-router`: `includes/class-import-router.php`, `includes/import-scenarios/*`
- `bricks-addon`: `addons/bricks/*`, `docs/BRICKS_ASSETS_DISCOVERY_REPORT.md`, Bricks artifacts that reuse DBVC primitives
- `bricks-transport`: `addons/bricks/bricks-connected-sites.php`, `addons/bricks/bricks-packages.php`, `addons/bricks/bricks-command-auth.php`, `addons/bricks/bricks-command-queue.php`, `addons/bricks/bricks-onboarding.php`
- `non-post-domains`: `includes/class-sync-taxonomies.php`, `includes/class-options-groups.php`, `includes/class-menu-importer.php`, FSE import/export paths in `includes/class-sync-posts.php`
- `auto-export-hooks`: `includes/hooks.php`, live post/meta/term/option/menu/FSE change hooks
- `official-collections`: `includes/Dbvc/Official/Collections.php`
- `logging`: `includes/class-sync-logger.php`, `wp_dbvc_activity_log`
- `content-migration-guard`: `addons/content-migration/bootstrap/dbvc-cc-runtime-guard.php`
- `tests`: `tests/phpunit`, WP PHPUnit scaffold, future browser/REST smoke tests

## Flagged Dependency / Conflict Register

These flags identify code paths that are not the Proposal Diff UI itself but can be affected by the planned CPD changes. Attach the relevant flag IDs to implementation tickets and QA notes when changing shared helpers, option stores, manifests, REST routes, import behavior, or generated assets.

| Flag | Dependent surface | Shared touchpoints | CPD items most likely to affect it | Conflict risk | Required QA / observation |
| --- | --- | --- | --- | --- | --- |
| CDF-001 | Entity Editor REST + UI | `DBVC_Entity_Editor_Indexer`, `/dbvc/v1/entity-editor/*`, `src/admin-entity-editor/index.js`, `build/admin-entity-editor.js` | CPD-003, CPD-006, CPD-007, CPD-010, CPD-012, CPD-013 | Entity Editor uses the same entity identity, sync JSON, post/term meta, taxonomy import, and shared CSS conventions, but it is not a proposal-review flow. Proposal gates, decision stores, and snapshot-missing states must not block save-only, partial import, or full replace actions. | Run `EntityEditorEndpointsTest`; manually verify index, file load, save-only, partial import, full replace snapshot, lock takeover, bulk download, and deletion of a theme-backed raw-intake sync path. |
| CDF-002 | Entity Editor partial/full import semantics | `import_tax_input_for_post`, `export_tax_input_portable`, `export_post_to_json`, `dbvc_run_auto_export_with_mask`, protected meta filters | CPD-006, CPD-007, CPD-010 | Changing decision/apply granularity or masking helpers can accidentally alter Entity Editor's contract: partial import must not delete absent meta, full replace must delete only unprotected absent meta, and save-only must not touch the database. | Re-test post + term partial/full import with protected meta filters `dbvc_entity_editor_protected_post_meta_keys` and `dbvc_entity_editor_protected_term_meta_keys`. |
| CDF-003 | Classic admin backup restore/import | `admin/admin-page.php`, `DBVC_Sync_Posts::import_backup`, `DBVC_Backup_Manager::read_manifest`, `dbvc_import_backup_override` | CPD-001, CPD-002, CPD-003, CPD-006, CPD-008, CPD-009 | If apply gates are added inside `import_backup()` without context, legacy backup restore and classic import can be blocked by proposal-only requirements. Keep Proposal Diff gates scoped to proposal apply wrappers or add an explicit `context => proposal` option. | Restore a non-proposal backup in full, partial, and copy modes. Confirm `dbvc_import_backup_override` still works. |
| CDF-004 | WP-CLI proposal and legacy commands | `commands/class-wp-cli-commands.php`, `wp dbvc proposals list|upload|apply`, `--fail-on-pending`, `--recapture-snapshots`, `--cleanup-duplicates`, legacy `wp dbvc import/export` | CPD-001, CPD-002, CPD-003, CPD-004, CPD-008, CPD-009 | REST and CLI must share blocker semantics, but legacy import/export should keep working. Output fields and exit codes are automation contracts. | Run CLI proposal list/upload/apply smoke tests, including blocked apply exit code, duplicate cleanup, snapshot recapture, and legacy import media sync. |
| CDF-005 | Media resolver decision bridge | `DBVC_Media_Sync::sync_manifest_media`, `apply_resolver_result`, `load_resolver_decisions`, `Dbvc\Media\Resolver`, `Dbvc\Media\Reconciler` | CPD-001, CPD-002, CPD-012 | `DBVC_Media_Sync` already consumes `dbvc_resolver_decisions` keyed by original attachment ID after resolver output. Moving decision logic into `Reconciler` or `Resolver` can double-apply decisions or drop existing proposal/global rule behavior. | Test proposal-specific and global `reuse`, `map`, `download`, and `skip`; verify queue handling, `id_map`, stats, logs, and `dbvc_media_use_legacy_sync` behavior. |
| CDF-006 | Manifest/schema consumers | `DBVC_Backup_Manager::generate_manifest`, `manifest.json`, `entities.jsonl`, `content_hash`, `media_index`, `resolver_decisions`, `media_bundle` | CPD-002, CPD-003, CPD-004, CPD-006, CPD-008, CPD-010 | Proposal Diff fixes may tempt manifest shape changes. Bricks packages, CLI, Entity Editor indexing, media sync, official collections, and docs rely on stable entity refs, hashes, item types, and resolver/media metadata. | Generate manifests before/after changes; compare schema v3 compatibility; verify posts, terms, options groups, menus, media bundle metadata, and `entities.jsonl`. |
| CDF-007 | Import router + upload scenarios | `DBVC_Import_Router`, `includes/import-scenarios/post.php`, `term.php`, `options.php`, `menus.php`, `generate_manifest` context | CPD-004, CPD-008, CPD-010 | ZIP hardening and duplicate detection should not break flat JSON upload routing or scenario writes. Manifest regeneration from routed JSON must still work. | Upload single JSON, multi-JSON batch, post, term, options, and menu payloads with overwrite on/off and dry-run where available. |
| CDF-008 | Bricks add-on runtime registration and settings | `DBVC_Bricks_Addon::bootstrap`, `refresh_runtime_registration`, `dbvc_addon_bricks_enabled`, `/dbvc/v1/bricks/*`, `dbvc_bricks_*` settings | CPD-001, CPD-010, CPD-012, CPD-013, CPD-015 | Core route, settings, and asset changes can collide with Bricks route registration, Configure -> Add-ons fields, read-only mode, diagnostics, and UI contract tests. | Run Bricks status/settings route tests; toggle add-on enabled/disabled; verify routes/jobs/submenu are suppressed when disabled. |
| CDF-009 | Bricks drift/diff compare | `DBVC_Bricks_Drift`, `dbvc_bricks_diff_path_rules`, `dbvc_bricks_artifact_ignore_rules`, `dbvc_bricks_artifact_mask_rules`, `dbvc_bricks_meta_ignore_rules`, `dbvc_bricks_meta_mask_rules`, `dbvc_bricks_diff_row_data` | CPD-006, CPD-010, CPD-011, CPD-012, CPD-013 | Bricks already has classified `added/removed/modified` drift summaries, raw compare payload limits, and mask/ignore rules. If core diff utilities are shared or renamed, preserve Bricks rule semantics and response shapes. | Run `BricksAddonPhase16Test` and drift compare API tests with ignore/mask path rules and raw compare enabled. |
| CDF-010 | Bricks apply/restore/proposal queue | `DBVC_Bricks_Apply`, `DBVC_Bricks_Proposals`, `DBVC_Bricks_Packages`, idempotency keys, `dbvc_bricks_read_only`, `dbvc_bricks_policy_*` | CPD-001, CPD-006, CPD-008, CPD-009, CPD-010 | Core Proposal Diff apply gates must not accidentally govern Bricks package apply unless Bricks intentionally opts in. Bricks has separate policies, restore points, proposal statuses, and idempotency contracts. | Run Bricks apply dry-run/live/rollback tests, proposal submit/transition tests, idempotency replay tests, and read-only mode checks. |
| CDF-011 | Bricks portability / artifact identity | `DBVC_Bricks_Artifacts`, `vf_object_uid`, `option:*` artifact UIDs, canonicalization, `sha256:` fingerprints | CPD-003, CPD-006, CPD-008, CPD-010 | Duplicate detection, snapshot state, and decision scope changes must preserve entity-backed `bricks_template` identity and option-backed `option:<key>` identity. Do not force post/term-only assumptions into artifact-level tooling. | Verify artifact registry, live schema verifier, package bootstrap, `option:*` identities, and `bricks_template` UID handling. |
| CDF-012 | Official Collections promotion | `Dbvc\Official\Collections::mark_official`, collection/item tables, proposal IDs, resolved entity payloads, manifest/archive copies | CPD-001, CPD-003, CPD-006, CPD-009, CPD-015 | Apply readiness, auto-clear decisions, declined-new state, and snapshot availability determine which entities are safe to promote as official. Collection promotion must not receive unresolved/skipped entities as accepted payloads. | Promote a reviewed proposal to a collection and verify entity decisions, snapshot files, manifest copy, archive copy, checksum, and media count. |
| CDF-013 | Core entity registry and identity resolution | `DBVC_Database`, `dbvc_entities`, `get_entity_by_uid`, `upsert_entity`, `delete_entity_by_uid`, post/term UID helpers | CPD-003, CPD-006, CPD-008, CPD-009, CPD-010 | Duplicate cleanup, new-entity decisions, term parity, Entity Editor matching, Bricks entity artifacts, and snapshot capture all rely on stable UID resolution. Registry mutations can cascade widely. | QA UID resolution by post UID, term UID, slug fallback, `entity_refs`, deleted/trash entities, and duplicate UID cleanup. |
| CDF-014 | Export hooks and live masking | `dbvc_export_post_data`, `dbvc_mask_apply_to_meta`, `dbvc_run_auto_export_with_mask`, `dbvc_maskable_post_fields`, `dbvc_auto_export_mask_*`, `dbvc_diff_ignore_paths` | CPD-006, CPD-007, CPD-010, CPD-012 | Live proposal masking, export-time masking, diff ignores, Bricks mask rules, and Entity Editor auto-export masking overlap but are not the same store. Avoid merging semantics without migration. | Test export-time mask remove/redact, live proposal masking apply/revert, post field masks, term meta masks, and diff-ignore paths independently. |
| CDF-015 | Shared build assets and CSS | `build/style-admin-app.css`, `build/style-admin-app-rtl.css`, `build/admin-app.js`, `build/admin-entity-editor.js`, asset PHP manifests | CPD-011, CPD-012, CPD-013, CPD-014 | Proposal UI and Entity Editor currently share the admin app stylesheet. Label/layout/virtualization changes can regress Entity Editor modals, table paging, or Bricks submenu styling if global selectors are broadened. | Browser-check Proposal Review, Entity Editor, and Bricks submenu after any build/style change. |
| CDF-016 | Logging, activity, and diagnostics | `DBVC_Sync_Logger`, `DBVC_Database::log_activity`, `/logs/client`, Bricks diagnostics/UI events, media/import/upload logging toggles | CPD-001, CPD-002, CPD-003, CPD-004, CPD-007, CPD-009, CPD-015 | New gate failures and blocker states need observability, but log payload shape changes can affect Bricks diagnostics, CLI summaries, and activity-table consumers. | Enable `dbvc_logging_*`; verify blocked apply, media resolver, term import, upload rejection, client log, and Bricks UI event records. |
| CDF-017 | Content migration runtime guard | `dbvc_cc_guard_no_source_runtime_imports`, `_source/content-collector/*` | CPD-005, CPD-015 | New tests, fixtures, or helper includes must not load `_source/content-collector` runtime files. The guard exits hard under WP-CLI. | Run bootstrap/tests with content-migration guard active and confirm no `_source/content-collector` files are included at runtime. |
| CDF-018 | Public hook/filter contracts | `dbvc_import_backup_override`, `dbvc_enable_snapshot_capture`, `dbvc_media_use_legacy_sync`, `dbvc_masking_chunk_size`, `dbvc_proposal_zip_resource_limits`, `dbvc_bricks_*` hooks, Entity Editor protected-meta filters | CPD-001, CPD-002, CPD-003, CPD-006, CPD-007, CPD-010, CPD-013, CPD-019 | Several tests and possible site customizations rely on these hooks. Changing signatures, timing, or defaults can break downstream custom tools even when core behavior passes. Proposal ZIP ceilings must remain mandatory when filtered. | Preserve hook signatures or document migrations; add regression tests for filters/actions that are part of shipped docs or PHPUnit coverage, including invalid ZIP-limit values falling back to safe defaults. |
| CDF-019 | Bricks connected-site transport and onboarding | `DBVC_Bricks_Connected_Sites`, `DBVC_Bricks_Command_Auth`, `DBVC_Bricks_Command_Queue`, `DBVC_Bricks_Onboarding`, `dbvc_bricks_clients`, `dbvc_bricks_onboarding_transport`, remote publish/pull/ack settings | CPD-004, CPD-005, CPD-010, CPD-015 | Package schema, artifact diff, ZIP/payload validation, auth settings, and admin asset changes can affect client/mothership transport even when local Bricks apply still passes. | QA connection test, publish remote, pull latest, pull ack, intro packet, handshake, command ping, registry mode, TLS/timeout/auth settings, and diagnostics. |
| CDF-020 | Non-post proposal domains | `DBVC_Sync_Taxonomies`, `DBVC_Options_Groups`, `DBVC_Menu_Importer`, FSE theme data import/export, term/options/menu/FSE filters | CPD-001, CPD-003, CPD-006, CPD-007, CPD-008, CPD-010, CPD-011, CPD-012, CPD-014, CPD-015 | Proposal Diff fixes can drift toward post/meta assumptions. Options groups, menus, FSE data, and taxonomy entities need stable manifest refs, diff sections, apply units, masking behavior, and duplicate/new-entity states. | Build proposals for terms, options groups, menus, and FSE theme data; verify review counts, decisions, apply, rollback/snapshot behavior, and export filters. |
| CDF-021 | Live auto-export and change hooks | `includes/hooks.php`, `dbvc_after_post_meta_update`, `dbvc_after_term_changes`, `dbvc_after_option_update`, `dbvc_after_menu_updates`, `dbvc_after_fse_changes`, hook-triggered export helpers | CPD-003, CPD-006, CPD-007, CPD-010, CPD-015 | Snapshot capture, masking, export shape, and entity hash changes can change the live baselines that later Proposal Diff compares against. Hook timing regressions are easy to miss in proposal-only tests. | Trigger post save, meta update, term edit, option update, menu save/delete, and FSE changes; verify exported JSON, entity registry updates, and later proposal diff baselines. |
| CDF-022 | Media Hydration inventory/planner/apply | `includes/Dbvc/Media/Hydration/*`, `_wp_attached_file`, `vf_asset_uid`, `vf_file_hash`, `_dbvc_original_attachment_id`, hydration plans/receipts/jobs | CPD-002, CPD-012 | Resolver `download`, `map`, and `reuse` can create or select attachments using the same identity and file metadata that hydration scans and updates. Duplicate creation, missing attached-file metadata, or overwritten paths can change hydration matching and inventory results. | Run `MediaHydrationInventoryTest`; dry-run a hydration plan after each resolver action; verify inventory identity, file state, hashes, overwrite policy, receipts, and job summaries. |
| CDF-023 | Configuration Portability and transfer/Bricks media packages | `MediaHandlingProvider`, `EntityPacketBuilder`, `DBVC_Bricks_Portability_Media_Apply_Service`, media options, `media_bundle`, transfer package metadata | CPD-002, CPD-012, CPD-015 | Resolver work shares media transport settings and bundled attachment metadata with configuration exports, transfer packets, and Bricks Portability. Option-key, manifest, or bundle-path drift could make a core fix alter package contents or downstream media apply behavior. | Run `ConfigurationPortabilityRegistryTest`, `TransferPacketWorkflowTest`, `BricksPortabilityManagerTest`, and `BricksReferenceMappingTest`; compare media option and bundle metadata before/after. |
| CDF-024 | Proposal/AI shared upload routing | `Dbvc\AiPackage\SubmissionPackageDetector`, `DBVC_Admin_App::maybe_stage_ai_package_upload`, `DBVC_Sync_Posts` single-ZIP upload routing, `manifest.json`, `dbvc-ai-manifest.json` | CPD-004, CPD-019, CPD-021 | Core proposals and legacy AI packages can both contain `manifest.json`. A broad detector can divert a valid proposal or transfer packet into AI intake before Proposal Review validation, registration, and cleanup run. | Upload a core proposal through Proposal Review and the classic sync uploader; verify canonical and legacy AI fixtures still enter AI intake; check REST/CLI results, retained reports, workspace cleanup, and upload logs. |
| CDF-025 | Proposal deletion and media-bundle lifecycle | `DBVC_Admin_App::delete_proposal`, `DBVC_Backup_Manager::delete_backup`, `Dbvc\Media\BundleManager`, `sync/media-bundles`, decision/snapshot/mask stores | CPD-017, CPD-022 | Proposal deletion can remove the proposal record and review stores while leaving its deterministic media-bundle directory behind. This can grow storage and allow stale bundle data to survive if a proposal ID is reused. | Ingest two proposal bundles, delete one proposal through REST/UI/CLI, and confirm only its proposal directory, review stores, and media bundle are removed while the neighboring bundle remains. |
| CDF-026 | LocalWP ACF page REST serialization | Bricks Advanced Themer bundled ACF Pro, `acf_format_value_for_rest()`, `/wp/v2/pages`, page read/delete QA | CPD-006, CPD-007 | The current LocalWP stack can throw an external ACF formatter fatal after DBVC creates or updates a disposable page. DBVC apply may succeed while a follow-up core page REST read or delete returns HTTP 500, so that response cannot be treated as importer outcome evidence. | Until the ACF/Bricks conflict is fixed, verify Proposal Diff applies through DBVC snapshot/detail data or direct WordPress state; separately retest normal page REST read/delete after the external plugin is updated. |
| CDF-027 | Admin notice DOM mutation | Site-wide Admin Notices settings/tooling, DBVC-owned `.dbvc-inline-notice` states, `/logs/client` | CPD-009, CPD-012, CPD-013, CPD-015 | Resolved in Phase 5A.1 by removing WordPress's generic `.notice` classes from every React-owned DBVC status block. The enabled LocalWP notice manager no longer discovers or mutates those nodes; browser QA found no generic notice inside the app root, no injected controls, and no `removeChild` or console error. | Keep a forced Proposal REST error/retry as a manual regression check when either DBVC notice rendering or the site-wide notice manager changes; browser-check Proposal Review, shared transfer/Bricks states, and any new inline status component. |

## CPD Conflict Flag Quick Map

| CPD item | Conflict flags to keep attached during implementation |
| --- | --- |
| CPD-001 Apply gate contract | CDF-003, CDF-004, CDF-005, CDF-008, CDF-010, CDF-012, CDF-016, CDF-018, CDF-020 |
| CPD-002 Resolver decisions | CDF-003, CDF-004, CDF-005, CDF-006, CDF-016, CDF-018, CDF-022, CDF-023 |
| CPD-003 Snapshot state | CDF-001, CDF-006, CDF-011, CDF-012, CDF-013, CDF-018, CDF-020, CDF-021 |
| CPD-004 ZIP intake hardening | CDF-006, CDF-007, CDF-016, CDF-017, CDF-019 |
| CPD-005 Test harness | CDF-001, CDF-008, CDF-009, CDF-010, CDF-017, CDF-018, CDF-019, CDF-020, CDF-021 |
| CPD-006 Decision granularity | CDF-001, CDF-002, CDF-010, CDF-011, CDF-012, CDF-013, CDF-014, CDF-018, CDF-020, CDF-021, CDF-026 |
| CPD-007 Term masking overrides | CDF-002, CDF-014, CDF-016, CDF-018, CDF-020, CDF-021 |
| CPD-008 Duplicate detection | CDF-003, CDF-004, CDF-006, CDF-011, CDF-013, CDF-020 |
| CPD-009 New entity decline | CDF-004, CDF-010, CDF-012, CDF-013, CDF-016, CDF-027 |
| CPD-010 Classified diff payloads | CDF-006, CDF-009, CDF-011, CDF-014, CDF-015, CDF-018, CDF-019, CDF-020, CDF-021 |
| CPD-011 Raw diff / view modes | CDF-009, CDF-015, CDF-020 |
| CPD-012 Status labels and counters | CDF-001, CDF-005, CDF-008, CDF-015, CDF-020, CDF-027 |
| CPD-013 Proposal/entity list scaling | CDF-001, CDF-004, CDF-005, CDF-009, CDF-015, CDF-018, CDF-027 |
| CPD-014 Dev fixture upload | CDF-007, CDF-015, CDF-020 |
| CPD-015 Documentation reconciliation | CDF-006, CDF-008, CDF-010, CDF-012, CDF-016, CDF-017, CDF-018, CDF-019, CDF-020, CDF-021, CDF-027 |
| CPD-016 Resolver decision authority | CDF-003, CDF-004, CDF-005, CDF-006, CDF-016, CDF-018, CDF-022, CDF-023 |
| CPD-017 Apply outcome truth | CDF-003, CDF-004, CDF-005, CDF-012, CDF-015, CDF-016, CDF-018, CDF-022, CDF-023 |
| CPD-018 Non-post apply coverage | CDF-006, CDF-007, CDF-012, CDF-013, CDF-016, CDF-018, CDF-020, CDF-021 |
| CPD-019 ZIP extraction resource limits | CDF-004, CDF-006, CDF-007, CDF-016, CDF-017, CDF-018, CDF-019, CDF-023, CDF-024 |
| CPD-020 Resolved-phase regression lane | CDF-001, CDF-003, CDF-004, CDF-005, CDF-008, CDF-009, CDF-010, CDF-017, CDF-018, CDF-020, CDF-022, CDF-023 |
| CPD-021 Proposal/AI upload routing | CDF-004, CDF-006, CDF-007, CDF-016, CDF-019, CDF-024 |
| CPD-022 Proposal media-bundle cleanup | CDF-003, CDF-004, CDF-005, CDF-006, CDF-016, CDF-023, CDF-025 |

## Sprint Board

| ID | Priority | Status | Finding | Primary resolution | Dependent surfaces to QA |
| --- | --- | --- | --- | --- | --- |
| CPD-001 | P0 | Done | Apply can run while blockers remain. | Build and enforce a single server-side apply gate contract. | `core-rest`, `admin-ui`, `wp-cli`, `duplicate-cleanup`, `media-resolver`, `masking`, `terms`, `settings`, `logging` |
| CPD-002 | P0 | In review | Resolver decisions were stored/displayed but were not authoritative during reconciliation. | Use one normalized proposal/global decision map across reconciliation and media sync, with actual outcome reporting. | `media-resolver`, `core-importer`, `admin-ui`, `wp-cli`, `settings`, `media-hydration`, `configuration-portability`, `transfer-media`, `bricks-addon`, `logging` |
| CPD-003 | P0 | Done | Missing snapshots can compare proposed payloads against themselves. | Replace silent fallback with explicit snapshot states, recapture path, and apply gating. | `snapshot-manager`, `core-rest`, `admin-ui`, `terms`, `wp-cli`, `bricks-addon`, `tests` |
| CPD-004 | P0 | Done | Proposal ZIP extraction is not visibly hardened before `extractTo`. | Validate ZIP entries before extraction and reject unsafe paths. | `core-rest`, `wp-cli`, `logging`, `tests` |
| CPD-005 | P0 | Done | The automated test harness was not runnable and had no required green Proposal Diff target. | WP PHPUnit is reproducible, Proposal Diff coverage is grouped, and the resolved Composer command is suitable for local or CI enforcement. | `tests`, CI/dev tooling, `README.md`, `docs/progress-summary.md` |
| CPD-006 | P1 | Done | Review decisions can be more granular than importer apply units. | Diff rows now declare canonical apply units, read-only boundaries, and safe nested merge/removal behavior that the importer validates and applies. | `core-importer`, `core-rest`, `admin-ui`, `masking`, `terms`, `bricks-addon`, `official-collections` |
| CPD-007 | P1 | Done | Term masking overrides were not wired into term import. | Term apply now consumes canonical suppressions/overrides, returns counters, and emits term-scoped logs. | `core-importer`, `masking`, `terms`, `settings`, `logging`, `tests` |
| CPD-008 | P1 | Done | Duplicate detection is inconsistent between upload, report, UI, and apply. | Unify duplicate detector and enforce it at upload, list, UI gate, REST apply, and CLI apply. | `duplicate-cleanup`, `core-rest`, `core-importer`, `admin-ui`, `terms`, `bricks-addon`, `wp-cli` |
| CPD-009 | P1 | Done | Declined new entities still count as pending in proposal summaries. | Decline decisions are resolved-skip states across summaries, gates, masking, apply, archived state, and history. | `terms`, `admin-ui`, `core-importer`, `core-rest`, `wp-cli`, `settings`, `masking`, `official-collections`, `logging` |
| CPD-010 | P2 | Done | Diff engine was shallow compared with the intended review contract. | Diff rows now expose stable IDs, explicit change types, sections, decisions, bounded values/rows, complete apply paths, and raw-side download fallbacks. | `core-rest`, `admin-ui`, `snapshot-manager`, `masking`, `terms`, `bricks-addon`, `tests` |
| CPD-011 | P2 | Done | `View All` was meta-focused and raw JSON was not a true raw diff view. | The entity drawer now has server-owned Changed, All Fields, and bounded Raw JSON modes with stable selectors and full-download fallbacks. | `admin-ui`, `core-rest`, `snapshot-manager`, `masking`, shared build assets, `docs/UI-ARCHITECTURE.md`, browser QA |
| CPD-012 | P2 | Done | UI labels conflate unresolved media with unresolved meta. | Expose one canonical seven-count contract and matching labels across REST, proposal list, entity table, drawer, Tools, Apply, WP-CLI, and docs while retaining legacy response/count fields. | `admin-ui`, `media-resolver`, `masking`, `wp-cli`, `entity-editor`, `content-collector-v2`, `docs/meta-masking.md`, `README.md` |
| CPD-013 | P3 | In progress | Proposal listing performed expensive live readiness work, and entity table virtualization is documented but not implemented. | The inventory now defers readiness and supports REST pagination/filtering plus bounded CLI lookup; UI pagination controls and large-entity server pagination/windowing remain open. | `admin-ui`, `wp-cli`, `media-resolver`, large proposals, `terms`, `bricks-addon`, `tests` |
| CPD-014 | P4 | Not started | Dev fixture upload is exposed in the core admin UI. | Hide behind an explicit dev capability/constant or remove from production bundle. | `admin-ui`, `core-rest`, `docs/fixtures/README.md`, packaging QA |
| CPD-015 | P5 | Not started | Docs claim completed behavior that current code does not fully provide. | Update docs after fixes land and mark old claims as corrected. | `README.md`, `docs/progress-summary.md`, `docs/UI-ARCHITECTURE.md`, `docs/terms.md`, `docs/media-sync-design.md` |
| CPD-016 | P0 | Done | Manifest resolver snapshots can replace newer proposal-specific and global reviewer choices during upload or apply. | Separate imported seed/snapshot data from the live resolver decision store and never re-import over reviewed choices. | `media-resolver`, `core-importer`, `core-rest`, `admin-ui`, `wp-cli`, `classic-restore`, `settings`, `media-hydration`, `configuration-portability`, `transfer-media`, `bricks-addon`, `logging` |
| CPD-017 | P0 | Done | Required resolver/media actions can fail after readiness passes, while apply still closes the proposal and reports success. | Apply now returns one entity/media outcome, preserves decisions and draft status on failure, and closes only after all required work succeeds. | `media-resolver`, `core-importer`, `core-rest`, `admin-ui`, `wp-cli`, `classic-restore`, `settings`, `media-hydration`, `configuration-portability`, `transfer-media`, `bricks-addon`, `official-collections`, `logging` |
| CPD-018 | P1 | Done | Options, option groups, and menus could bypass field-decision review and trusted-snapshot gates. | Proposal Review now blocks these writable domains explicitly until domain-specific review and trusted baselines exist; dedicated import tools remain available. | `core-importer`, `core-rest`, `admin-ui`, `wp-cli`, `options-groups`, `menus`, `configuration-portability`, `auto-export-hooks`, `official-collections`, `logging` |
| CPD-019 | P0 | Done | ZIP path/type validation had no entry-count, expanded-size, per-entry-size, or compression-ratio ceilings before extraction. | Proposal intake now enforces mandatory filterable budgets and rejects unsafe or unknowable resource use before `extractTo`. | `core-rest`, `wp-cli`, `logging`, public filters, AI intake, classic ZIP import, `configuration-portability`, `transfer-media`, `bricks-addon`, connected-site transport, packaging QA |
| CPD-020 | P2 | Done | Implemented Phase 0-4 behavior had no deterministic green regression target separate from expected Phase 5 failures. | Resolved contracts have a green Composer command, while the pending lane remains executable and is currently empty. | PHPUnit bootstrap, CI/dev tooling, `core-rest`, `core-importer`, `admin-ui`, `wp-cli`, `media-hydration`, `configuration-portability`, `transfer-media`, `bricks-addon` |
| CPD-021 | P0 | Done | The active LocalWP AI detector claimed ordinary Proposal Diff ZIPs because both package types can use `manifest.json`. | Keep canonical AI manifests authoritative, but require an AI-specific signature before treating legacy `manifest.json` as an AI package. | `core-rest`, `admin-ui`, classic sync upload, AI intake/validation, WP-CLI upload, transfer packets, retained reports/workspaces, `logging` |
| CPD-022 | P2 | Done | Deleting a proposal left its ingested `sync/media-bundles/<proposal-id>` cache behind. | Add proposal-scoped media-bundle deletion and report/log its result as part of proposal deletion. | `core-rest`, `admin-ui`, `wp-cli`, `media-resolver`, `backup-manager`, `transfer-media`, `bricks-addon`, `logging` |

## P0 Implementation Plans

### CPD-001: Server-Side Apply Gate Contract

Problem:
- The Apply CTA and REST endpoint do not share a complete blocker contract. Reviewers can apply while duplicates, resolver conflicts, masking candidates, missing snapshots, new-entity decisions, or unresolved field decisions remain.

Resolution plan:
1. Add a single gate builder in `DBVC_Admin_App`, for example `build_proposal_apply_gates($proposal_id, $manifest)`.
2. Gate categories should include `duplicates`, `resolver`, `masking`, `new_entities`, `field_decisions`, `snapshots`, `hashes`, and `permissions`.
3. Return a stable response shape:
   - `ready: bool`
   - `blocking: array`
   - `warnings: array`
   - `counts: object`
   - `override_tokens: array` only for explicitly allowed non-blocking overrides.
4. Use the gate builder in proposal list, proposal detail, entity list, apply modal payload, REST apply, and WP-CLI apply.
5. REST apply must fail with a structured `WP_Error` when blocking gates exist.
6. Log blocked apply attempts through file logging and activity log when logging is enabled.
7. Keep `--ignore-missing-hash` scoped to hash mismatch only. It must not bypass duplicate, resolver, masking, snapshot, or new-entity gates.

Acceptance criteria:
- UI Apply button is disabled with exact blocker reasons.
- Direct REST apply fails when any blocking gate exists.
- WP-CLI apply exits non-zero and prints the same blocker categories.
- Apply succeeds only after blockers are resolved or a narrowly scoped documented override is supplied.

Dependent QA:
- Configure -> Import Defaults: `dbvc_import_require_review`, `dbvc_auto_clear_decisions`, `dbvc_force_reapply_new_posts`.
- Duplicate modal and cleanup.
- Tools drawer masking apply/revert.
- Resolver workspace single and bulk decisions.
- New post and new term accept/decline.
- Activity/logging output with `dbvc_logging_*` enabled.

Implementation state (2026-07-19):
- `DBVC_Admin_App::build_proposal_apply_gates()` now owns the stable readiness shape and blocks duplicate groups, unresolved resolver items, unreviewed masking fields, pending new entities, unreviewed snapshot-backed field changes, missing hashes, and permission failures.
- Proposal list/entity responses and the dedicated read-only readiness route return the same contract; REST apply, the React apply controls, WP-CLI list/apply, and blocked-apply logs consume it.
- `ignore_missing_hash` removes only the `hashes` blocker. A direct classic `DBVC_Sync_Posts::import_backup()` regression test confirms that proposal gates do not govern classic restore, Entity Editor, or Bricks/add-on apply paths.
- Snapshot counts now use `counts.snapshots.enforced: true`; CPD-003 / Phase 3 supplies the explicit trust states, recapture outcomes, and snapshot blocker consumed by this gate.

### CPD-002: Resolver Decisions Must Drive Reconciliation

Problem:
- Resolver decisions are persisted and shown in the UI, but reconciliation appears to call the resolver without consulting stored proposal/global decisions.

Resolution plan:
1. Define resolver decision precedence:
   - proposal-specific decision
   - global resolver rule from `dbvc_resolver_decisions`
   - resolver auto-match
   - unresolved conflict
2. Pass the effective decision map into `Dbvc\Media\Reconciler`.
3. Implement action behavior:
   - `reuse`: attach existing attachment ID and update media maps.
   - `map`: attach reviewer-selected target ID and update media maps.
   - `download`: force bundle/remote download according to media transport settings.
   - `skip`: leave reference untouched or remove pending media mutation according to current importer contract.
4. Include decision source in resolver/apply result summaries.
5. Add REST and importer tests for every decision action.

Acceptance criteria:
- A proposal-specific decision changes the media result during apply.
- A global rule seeds new proposal resolution but can be overridden per proposal.
- Apply summaries distinguish reused, mapped, downloaded, skipped, unresolved, and failed assets.

Implementation state (2026-07-20):
- `DBVC_Media_Sync::get_effective_resolver_decisions()` now normalizes proposal/global rules and makes proposal scope authoritative. The same map is passed to `Dbvc\Media\Reconciler` before any bundled attachment can be registered.
- Reconciliation applies `skip`, `map`, and `reuse` without creating media, validates manual targets as real attachments, and makes `download` create at most one uniquely named attachment with `_wp_attached_file` and DBVC identity metadata. It returns both asset-key and original-ID maps plus action/source outcomes.
- Media sync consumes the reconciliation original-ID map, clears stale ID/URL mappings for `skip` and `download`, prevents forced download from falling through to auto-reuse, and keeps a local bundle eligible when its remote fallback host is blocked.
- REST, Proposal Review, WP-CLI, media logs, and resolver metrics now distinguish saved choices from actual applied/failed outcomes. Existing response keys and the `dbvc_media_use_legacy_sync` filter signature remain unchanged.
- Seven focused tests cover precedence, proposal/global source, valid/invalid targets, stale mapping cleanup, no-create actions, one-time forced download, and direct local-bundle fallback. LocalWP dependent coverage also passed for Media Hydration, Configuration Portability, transfer packets, Entity Editor, Bricks Portability, and Bricks reference mapping.

Dependent QA:
- Media settings: `dbvc_media_retrieve_enabled`, `dbvc_media_transport_mode`, `dbvc_media_bundle_enabled`, `dbvc_media_allow_external`, `dbvc_media_preview_enabled`.
- Resolver rules CSV import/export.
- Offline bundle apply.
- Classic backup restore and legacy `wp dbvc import/export`, because they share `DBVC_Sync_Posts::import_backup()` and direct media sync.
- Media Hydration inventory/planning/apply, because it reads and writes the same attachment file and identity metadata.
- Configuration Portability, transfer packets, and Bricks Portability media packages, because they share transport options and bundle metadata.
- Entity Editor is an indirect observer through shared importer helpers; it does not call `Reconciler` or consume resolver decisions directly.
- Bricks future media refs, because Bricks artifacts may reuse resolver decisions.

### CPD-003: Explicit Snapshot State Instead of Silent Fallback

Problem:
- Existing-entity diffs can show no changes when snapshot capture fails or legacy proposals lack current snapshots.

Resolution plan:
1. Replace `current = proposed` fallback for existing entities with a `snapshot_missing` state.
2. Keep new entities separate: new entities should show `new_entity` rather than `snapshot_missing`.
3. Add `snapshot_state` to entity list/detail payloads: `available`, `missing`, `stale`, `recapturing`, `failed`, `not_required`.
4. Add recapture endpoints and CLI parity where missing.
5. Gate apply when required snapshots are missing for existing entities.
6. Make upload-time capture failures visible in proposal status and logs.
7. Add legacy proposal migration docs and QA scripts.

Acceptance criteria:
- Existing entity with no snapshot cannot produce a false zero-diff state.
- Reviewer sees a clear recapture action and failure reason.
- Term snapshots and post snapshots behave the same way.

Dependent QA:
- `DBVC_Snapshot_Manager::capture_for_proposal()`.
- `wp dbvc proposals list --recapture-snapshots`.
- Term reopen flows in `docs/terms.md`.
- Future Bricks deep artifact snapshots.

Implementation state (2026-07-19):
- Entity list/detail, masking, and field-decision readers now share `available`, `missing`, `stale`, `recapturing`, `failed`, and `not_required`; an untrusted existing baseline returns no field comparison instead of comparing the proposal to itself.
- `stale` means the stored baseline no longer matches the current local post or term payload. New entities and unsupported non-post/term domains remain `not_required` and do not receive a snapshot blocker.
- Single and bulk REST recapture now support posts and terms through result-returning snapshot methods. The original public `capture_post_snapshot()`, `capture_term_snapshot()`, and `dbvc_enable_snapshot_capture` signatures remain available for existing integrations.
- Upload stores a small `snapshot_capture` summary in the proposal manifest and logs completed/failed activity. Proposal Review badges, notices, actions, Apply controls, and `wp dbvc proposals list --recapture-snapshots` use the same outcome counts.
- Existing proposals require no file migration: they initially report `missing` and block apply until the reviewer uses Proposal Review -> Snapshots & maintenance -> Recapture snapshots, an entity-level Recapture snapshot action, or `wp dbvc proposals list --recapture-snapshots=<proposal-id>`.

Legacy proposal QA:
1. Open an older proposal and confirm existing entities show `Snapshot missing` without a false clean diff.
2. Recapture from Proposal Review or WP-CLI and confirm post/term entities move to `Snapshot ready` or expose a short failure reason.
3. Change one local post or term after capture and confirm the proposal reports `Snapshot stale` and blocks apply until recaptured.
4. Confirm new entities remain `Snapshot not required`, and verify Entity Editor, classic restore, and Bricks apply do not inherit the proposal-only blocker.

### CPD-004: Proposal ZIP Intake Hardening

Problem:
- Proposal upload extracts ZIPs directly. The intake path should validate archive entries before extraction.

Resolution plan:
1. Inspect every ZIP entry before extraction.
2. Reject absolute paths, `..` traversal, backslash traversal variants, empty names, symlinks, and unexpected top-level layouts.
3. Extract only after all entries pass validation.
4. Validate required manifest files before copying into the proposal folder.
5. Log rejected uploads with sanitized entry metadata.
6. Add regression fixtures for safe and unsafe archives.

Acceptance criteria:
- Malicious ZIP path entries are rejected before extraction.
- Valid proposal archives still upload successfully.
- Error messages are actionable but do not echo unsafe paths blindly.

Dependent QA:
- React proposal uploader.
- WP-CLI proposal upload if it uses the same helper.
- `docs/fixtures` dev upload flow.

### CPD-005: Test Harness Restoration and Proposal Diff Coverage

Problem:
- The expected PHPUnit binary is missing in this checkout, and coverage does not currently lock the main Proposal Diff contract.

Resolution plan:
1. Make the local test bootstrap reproducible through Composer or documented setup.
2. Confirm `vendor/bin/phpunit` exists after setup.
3. Add a focused Proposal Diff test group covering:
   - apply gates
   - snapshot missing/stale states
   - resolver decision application
   - duplicate detection and cleanup
   - nested meta decision semantics
   - term masking overrides
   - new entity accept/decline summary states
4. Add minimal JS/browser smoke coverage for Apply CTA gating and status labels.
5. Update docs only after tests are runnable.

Acceptance criteria:
- `vendor/bin/phpunit --group proposal-diff` runs locally.
- CI or documented command fails on regressions for every P0/P1 contract.

Dependent QA:
- WP PHPUnit scaffold.
- GitHub Actions or local CI once available.
- Admin browser smoke tooling.

Implementation checkpoint (2026-07-21):
- `composer test:proposal-diff-resolved` is the required green local/CI-ready command, and `composer test:proposal-diff-pending` keeps planned Phase 5 contracts executable and visible.
- The existing broad `composer test:proposal-diff` command remains unchanged and intentionally reports the complete resolved-plus-pending state.
- Authenticated Proposal Review browser smoke was completed during the connected implementation phases; this test-only slice does not change browser behavior.

## P1 Implementation Plans

### CPD-006: Align Decision Granularity With Apply Granularity

Problem:
- UI decisions can be stored at nested paths that the importer cannot apply independently.

Resolution plan:
1. Define canonical apply units per section:
   - post scalar fields
   - complete meta key
   - nested meta leaf, only when safe merge support exists
   - complete taxonomy assignment
   - future option/Bricks artifact subkey
2. Add `apply_scope` to each diff row.
3. Disable or roll up decisions that cannot be safely applied at the displayed leaf path.
4. If nested meta support is required, implement deterministic read-modify-write merging that preserves untouched local leaves.
5. Make conflict states explicit when accepted and kept child paths overlap.

Acceptance criteria:
- Accepting a child diff cannot silently apply the entire parent meta value unless the row clearly says the apply unit is the parent.
- Mixed accept/keep decisions within one meta key produce deterministic output or a validation error.

Dependent QA:
- Meta masking suppress/override.
- Bricks templates and options with deeply nested payloads.
- Official collection snapshots after apply.

Implementation checkpoint (2026-07-22):
- Diff rows now expose `apply_scope`, `apply_path`, `apply_label`, and `can_apply`. Post fields, nested meta leaves, complete meta keys, and complete taxonomy assignments use canonical paths; identity and unsupported rows remain reference-only.
- Removed nested meta children roll up to one complete-key decision, while supported nested leaves use deterministic read-modify-write merge and deletion. Mixed Accept/Keep choices preserve untouched siblings, repeated meta rows, local identity, and explicit timestamp behavior.
- The importer validates decision paths before mutation, rejects writable identity/unsupported paths, applies post-field masking, and uses complete taxonomy assignment semantics. Term masking remains isolated to CPD-007 rather than being partially claimed here.
- Proposal Review saves, clears, bulk-selects, counts, and renders canonical apply paths. Rows sharing one parent unit display that unit and share one saved decision without double-counting readiness.
- Live QA exposed one connected masking regression: opening entity detail pruned valid masking decisions that were not ordinary diff paths. Detail refresh and snapshot-driven rebuild now preserve current masking review paths while still pruning genuinely stale decisions.
- Source and LocalWP resolved Proposal Diff lanes pass 87 tests and 548 assertions; the active randomized run with seed `20260722` matches. The source full suite excluding only pending contracts passes 222 tests and 1,313 assertions, and the active Entity Editor/Bricks Portability/configuration/UID/migration matrix passes 173 tests and 2,301 assertions.
- The pending lane now contains exactly two expected failures and five assertions: term masking override (Phase 5.2) and declined-new summary state (Phase 5.4). The active full diagnostic runs 602 tests and 5,855 assertions with five unchanged failures in Bricks language/disabled mode and Content Collector/settings work; the focused shared-dependency matrix is green, so those are recorded as external conflicts rather than CPD-006 regressions.
- Authenticated LocalWP browser/REST QA confirmed 19 display rows map to 17 apply units, identity is read-only, two removed children share one parent unit, 11 masking reviews survive detail refresh, and no browser console errors occur. A disposable apply then preserved the local ID and kept nested sibling, applied the accepted title/color, removed the accepted complete meta key, closed successfully, and left no proposal, post, sync file, or ZIP fixture.
- Official Collections promotion still has no direct automated case in this checkout. The LocalWP ACF REST fatal is tracked as CDF-026, and the missed theme-backed raw-intake file cleanup is retained under CDF-001 for focused Entity Editor QA.

### CPD-007: Wire Term Masking Overrides Into Term Import

Problem:
- Term import references proposal mask overrides without receiving the override map.

Resolution plan:
1. Pass proposal masking suppressions and overrides into `apply_term_entity()`.
2. Pass the same data into term meta application.
3. Add term-specific tests for ignore, auto-accept + suppress, override, and revert.
4. Add logging for masked term fields when term import logging is enabled.

Acceptance criteria:
- Masked term meta behaves the same as masked post meta.
- Reverting masking decisions returns term fields to review.

Dependent QA:
- `dbvc_mask_defaults_meta_keys`, `dbvc_mask_defaults_subkeys`, `dbvc_mask_post_fields`.
- Term import logging toggle.
- `docs/meta-masking.md` and `docs/terms.md`.

Implementation checkpoint (2026-07-24):
- Proposal import now passes each term's normalized masking overrides and suppressions into `apply_term_entity()` and the shared safe meta merge planner. Root and nested directives preserve suppressed local values and apply exact reviewed replacements without introducing a term-only storage format.
- Import results expose additive `term_masking` entity, override, and suppression counters. When import and term logging are enabled, a term-scoped entry records the exact overridden and suppressed paths.
- Tests cover masking inventory, ignore, auto-accept with suppression, root and nested override, revert-to-review behavior, counters, and logging. Source and LocalWP resolved Proposal Diff lanes pass 91 tests and 578 assertions, and the source suite excluding the one remaining pending contract passes 226 tests and 1,343 assertions.
- The randomized LocalWP dependent matrix passes 173 tests and 2,301 assertions across Entity Editor, Bricks portability/reference mapping, configuration portability, third-party portability, UID preservation, and Content Migration Phase 4.
- Connected LocalWP QA reviewed two nested term fields, confirmed revert restored both reviews, reapplied the choices, preserved `local-token`, stored `REDACTED`, verified 1/1/1 entity/override/suppression counters and the term log, then removed the disposable proposal and term. The prior `CodexDev` account was absent, so QA used a current administrator without changing authentication settings.
- Only the Phase 5.4 declined-new summary contract remains in the pending lane. Phase 5.3 duplicate unification is the next active implementation boundary.

### CPD-008: Unified Duplicate Detection and Enforcement

Problem:
- Upload duplicate checks, duplicate reports, UI overlays, and apply gates do not use the same detector.

Resolution plan:
1. Create one duplicate detector that supports posts, terms, and future registered entity types.
2. Use it in upload validation, proposal list counts, duplicate report, cleanup, apply gate, and CLI.
3. Return canonical duplicate IDs so UI cleanup and CLI cleanup mutate the same manifest entries.
4. Ensure cleanup rewrites manifest and removes stray JSONs atomically.

Acceptance criteria:
- Post and term duplicate counts match everywhere.
- Apply is blocked until duplicate report is clear.
- Cleanup result is immediately reflected in proposal list, entity table, and CLI.

Dependent QA:
- Term slug collisions.
- Future Bricks artifact UID collisions.
- `wp dbvc proposals list --cleanup-duplicates`.

Implementation checkpoint (2026-07-24):
- One detector now identifies post, term, and future typed manifest entities by UID, domain-specific ID, generic entity ID, or scoped slug fallback. Every group and entry has a stable canonical ID, while post and term entries sharing the same UID remain separate groups.
- Upload validation, proposal list counts, duplicate REST reports, apply readiness, admin cleanup, and WP-CLI cleanup use that detector. The UI sends canonical IDs with legacy UID/path fallbacks, and ambiguous legacy identities return a conflict instead of cleaning the wrong entity type.
- Cleanup selects exact manifest indexes, preserves payloads still referenced by remaining entries, updates manifest totals, stages removals in a quarantine directory, atomically replaces the manifest, and restores staged files if the commit fails. WP-CLI body parameters now retain the proposal ID, cleanup runs before `--fail-on-pending`, and readiness is refreshed afterward.
- Source and LocalWP resolved Proposal Diff lanes pass 94 tests and 626 assertions. The active shared-dependent matrix passes 173 tests and 2,301 assertions in normal order; a randomized run exposed one pre-existing Content Migration order leak, and that test passes alone.
- Connected LocalWP QA verified three canonical groups across list/report/readiness, post/term shared-UID separation, term-ID fallback, ambiguous legacy rejection, specific and CLI-style bulk cleanup, manifest/file consistency, no transaction residue, duplicate ZIP rejection, and disposable cleanup. A read-only live WP-CLI list also loaded canonical counts; destructive CLI cleanup was not run because several real proposals currently report 10-18 duplicate groups.
- Direct failure injection for the manifest rollback branch and a future non-post/non-term artifact duplicate fixture remain useful automated follow-ups. Existing Bricks portability/reference, Entity Editor, configuration portability, third-party portability, UID, and migration contracts remain green in normal order.

### CPD-009: New Entity Decline Is a Resolved State

Problem:
- Declining a new entity can still count as pending in proposal summaries.

Resolution plan:
1. Add explicit decision states: `accepted_new`, `declined_new`, `pending_new`.
2. Treat `declined_new` as resolved for gates and summaries.
3. Ensure importer skips declined entities and records skip reason.
4. Ensure auto-reapply does not resurrect declined entities.

Acceptance criteria:
- Proposal can become apply-ready after all new entities are accepted or declined.
- Apply history lists declined new entities as skipped by reviewer.

Dependent QA:
- New posts and new terms.
- `dbvc_force_reapply_new_posts`.
- WP-CLI apply summaries.

Implementation checkpoint (2026-07-24):
- Proposal summaries, entity rows, entity detail, readiness, and bulk actions now use explicit `accepted_new`, `declined_new`, and `pending_new` states. Declined posts and terms are resolved, pending-only bulk accept cannot reverse them, and term identity can fall back to a manifest term ID when no UID is present.
- The importer merges archived declines back into proposal decisions after auto-clear, records structured `declined_by_reviewer` skips for posts and terms, and reports `reviewer_declined` through REST, the admin apply result/history, logs, and WP-CLI. `dbvc_force_reapply_new_posts` cannot resurrect a declined entity.
- Declined entities are excluded from masking candidates and masking readiness counts, including after decisions auto-clear. The archive option `dbvc_proposal_declined_new_entities` is removed when its decision is explicitly cleared and when a proposal is deleted, overwritten, or cleared with all backups.
- Source and LocalWP resolved lanes pass 97 tests and 697 assertions; both pending-lane commands execute no tests. The active dependent matrix completed 173 tests with the previously documented order-dependent Content Migration rollback failure, while that method passes alone with 21 assertions and its complete class passes 32 tests and 568 assertions.
- Connected LocalWP runtime QA declined one new post and one new term, confirmed `ready=true`, zero pending/masking blockers, two structured skips, a closed proposal, cleared live decisions, preserved archived declines, and safe forced reapply. The authenticated list endpoint returned HTTP 200 with the same state but required 193.22 seconds, keeping list performance under CPD-013 rather than CPD-009.
- Browser QA found and fixed a formatter-name collision that turned a normal Proposal fetch error into `u is not a function`; both admin bundles compile and the active UI now displays the underlying error. Retrying that notice exposed the separate site-wide Admin Notices DOM ownership conflict tracked as CDF-027.
- `node --check`, PHP syntax checks, both production builds, and `git diff --check` pass. The repository-wide JavaScript lint commands were stopped after eight minutes of sustained processing without a result because they include the large generated/transpiled bundles.
- The disposable LocalWP proposal, its post/term targets, decisions, archived declines, and related files were removed without residue. Official Collections still has no direct caller/test in this checkout, so no promotion behavior was invented or marked verified.

## Phase 5A Verified Runtime Follow-Ups

### 5A.1: Preserve React Ownership of DBVC Notices

Implementation checkpoint (2026-07-24):
- Every status block rendered by the shared Proposal Review app now uses `dbvc-inline-notice` severity classes instead of WordPress's generic `.notice`, `.notice-error`, `.notice-warning`, `.notice-success`, or `.notice-info` classes. This covers Proposal Review plus the transfer and Bricks states compiled from the same source app.
- The LocalWP Admin Notices manager remained enabled during browser QA. The DBVC React root contained zero generic notice nodes, its warning contained no foreign controls, and the complete load/select/drawer/close/reload workflow produced no browser warning, error, or DOM ownership exception.
- Source and active builds, JavaScript syntax, and the expanded dependent matrix passed. An exact forced REST-error/retry remains a useful manual regression check when the external notice manager or DBVC notice components change, but the verified selector collision is structurally removed.

### 5A.2: Bound Proposal Inventory Readiness Work

Implementation checkpoint (2026-07-24):
- Profiling the connected 11-proposal inventory confirmed the list was constructing every proposal's full apply gates before returning. Aggregated work included about 83 seconds in full gates, 33 seconds in masking, 23 seconds in snapshots, 23 seconds in fields, and additional new-entity, duplicate, resolver, and Bricks summaries.
- `GET /dbvc/v1/proposals` now supports `proposal_id`, `page`, `per_page`, and `include_readiness`. The default response is paginated and marks readiness `deferred`; authoritative gates load only for the selected proposal or when `include_readiness=1` is requested.
- The admin table shows `Select to check` for unselected proposals, `Checking...` while selected readiness loads, and keeps Apply disabled until a real gate response arrives. `wp dbvc proposals list` explicitly requests complete readiness and supports `--id=<proposal-id>` for bounded operator checks.
- The default authenticated inventory improved from 193.22 seconds to 11.18 seconds for the same 11 proposals. A filtered full-readiness request took 14.85 seconds, the proposal `/readiness` endpoint took 13.64 seconds, and their complete gate payloads and blocker categories were identical.
- Phase 7.2 remains `In progress`: the current admin request defaults to 20 rows but has no pagination controls, the unfiltered CLI command intentionally computes all readiness pages, and a 755-entity proposal still returned a 1.5 MB entity payload in 15.75 seconds. UI pagination and server-side entity pagination/windowing are separate remaining slices.

## P2 Implementation Plans

### CPD-010: Classified Diff Payloads

Problem:
- Current diffs are scalar flatten comparisons without change classification or rendering limits.

Resolution plan:
1. Introduce a `FieldDiffItem` contract matching `docs/UI-ARCHITECTURE.md`:
   - `id`
   - `path`
   - `label`
   - `section`
   - `changeType`
   - `source`
   - `destination`
   - `decision`
   - `apply_scope`
   - `render_hint`
2. Classify additions, deletions, modifications, and unchanged rows.
3. Add payload limits for large values and a raw-download fallback when values exceed limits.
4. Preserve existing response shape during transition or version the endpoint payload.
5. Add tests for arrays, deleted keys, added keys, post fields, meta, taxonomies, and termmeta.

Acceptance criteria:
- Change Summary counts match the complete classified diff, while `displayed_total` and `omitted_total` explain any bounded rows.
- Large nested payloads do not freeze the drawer.
- UI can render additions/deletions/modifications without inferring types.

Dependent QA:
- `RawDiffView`.
- Bricks deeply nested payloads.
- Masking candidates and ignored paths.
- `dbvc_diff_ignore_paths` if retained as a configurable ignore list.

Implementation checkpoint (2026-07-24):
- Diff rows retain the transition-safe `from`/`to` and canonical apply-unit keys while adding `id`, `path`, `label`, `section`, `changeType`, `source`, `destination`, presence flags, `decision`, `render_hint`, and `is_equal`. IDs remain stable when values change, and missing keys remain distinct from explicit `null`.
- The classifier reports `added`, `deleted`, `modified`, and optional `unchanged` rows in deterministic path order. It bounds each inline string at 5,000 bytes and each response at 1,000 rows, while separately retaining every actionable apply path so truncation cannot bypass readiness or decision pruning.
- Top-level metadata now exposes displayed, omitted, actionable, section, change-count, limit, and truncation details. Entity detail attaches saved decisions and authenticated raw-current/raw-proposed links when a value or row set is bounded.
- The drawer consumes server-owned sections and classifications and renders Added, Removed, Changed, and Unchanged states without inferring missing values. A disposable trusted-snapshot LocalWP fixture showed one added, one removed, four changed fields, stable row IDs, bounded 6.2/6.3 KB values, and working full-current/full-proposed attachment responses.
- Source and active resolved lanes pass 100 tests and 829 assertions. The active dependent matrix passes 191 tests and 2,388 assertions across Entity Editor, Bricks portability/reference/drift/truncation, Configuration Portability, third-party portability, UID preservation, and Content Migration; both production builds and static checks pass.
- The existing full Raw Current and Raw Proposed panels still render complete payloads inline. That verified limit belongs to Phase 6.2 / CPD-011 and is not marked resolved by this classified-row work.

### CPD-011: First-Class Raw Diff and View Modes

Problem:
- `View All` is not a full raw diff view, and raw JSON side-by-side is not enough for reviewer audit.

Resolution plan:
1. Add explicit drawer modes: `changed`, `all`, `raw`.
2. Make `all` show every supported field, not only meta.
3. Make `raw` render structured current/proposed payloads plus raw diff metadata and truncation notices.
4. Add stable selectors from `docs/UI-ARCHITECTURE.md`.

Acceptance criteria:
- Reviewer can inspect unchanged post fields, meta, taxonomy, term, and media sections.
- Raw view is bounded and does not render unbounded payloads inline.

Dependent QA:
- Entity drawer keyboard/focus behavior.
- Browser smoke tests across desktop and narrow widths.

Implementation checkpoint (2026-07-26):
- The entity detail route accepts explicit `changed`, `all`, and `raw` views. Requests without `view` retain the legacy full `current` and `proposed` payloads, while explicit modes return bounded identity context so existing integrations are not silently broken.
- Changed remains the canonical review payload. All Fields uses the same server classifier to include every supported unchanged post, meta, taxonomy, term, and media field, while readiness totals, decision pruning, and importer apply paths continue to use only the canonical changed apply units.
- Raw JSON returns 20,000-byte current/proposed previews, hashes, truncation details, authenticated full-download links, and a value-free change index capped at 1,000 rows. The large LocalWP fixture reported 1,105 changes, 1,000 displayed rows, and 105 omitted rows without losing the complete actionable total.
- The drawer no longer mounts complete raw payloads in every view. Raw mode hides field decisions, resolver tools, and masking review, while stable `data-*` contracts identify the drawer, modes, sections, rows, change types, decisions, raw panels, and change index.
- Live browser QA verified Changed, all 27 supported fields with 23 unchanged fields, both bounded raw previews, desktop and 390 px stacking, no horizontal drawer overflow, clean console output, initial focus, mode-refetch focus recovery, Escape close, and focus return to the entity row.
- Source and LocalWP resolved lanes each pass 102 tests and 904 assertions; both pending lanes are empty. The active dependent matrix remains green at 191 tests and 2,388 assertions across Entity Editor, Bricks portability/reference/drift/truncation, Configuration Portability, third-party portability, UID preservation, and Content Migration, and both production builds pass.
- The disposable proposal, posts, category term, snapshots, option entries, and proposal directories were removed with zero residue. Narrow QA separately confirmed that the existing proposal table's sticky header can cover rows at 390 px; that table issue is retained under Phase 7.2 and CDF-015 rather than expanding CPD-011.

### CPD-012: Normalize Status Labels and Counters

Problem:
- Media unresolved counts are surfaced with meta-oriented labels.

Resolution plan:
1. Define canonical count names:
   - `field_needs_review`
   - `meta_needs_review`
   - `media_needs_review`
   - `resolver_conflicts`
   - `masking_candidates`
   - `duplicates`
   - `new_entities_pending`
2. Update UI labels, REST payload names, tooltips, filters, and docs.
3. Add tests/smoke checks that media counts do not appear as meta counts.

Acceptance criteria:
- Proposal list, entity table, drawer, Tools panel, and Apply modal use the same labels.
- Masking docs no longer imply media conflicts are unresolved meta.

Dependent QA:
- Media resolver badges.
- Masking badges.
- Apply warnings.

Implementation checkpoint (2026-07-27):
- REST proposal, readiness, entity-list, and entity-detail payloads now expose the same ordered `status_counts`: `field_needs_review`, `meta_needs_review`, `media_needs_review`, `resolver_conflicts`, `masking_candidates`, `duplicates`, and `new_entities_pending`. Existing nested and legacy count fields remain available so connected consumers do not break.
- Proposal list rows, entity rows, the drawer, Tools, Apply confirmation, and status filters use the same human labels. WP-CLI accepts the seven canonical custom fields while its default output and legacy fields remain unchanged.
- Connected REST QA found and fixed a missing server branch for the `duplicates` filter, which had returned every entity instead of only duplicate-group rows. A focused parity fixture now requires duplicate rows, group totals, and neighboring unique entities to agree.
- Browser QA found and fixed a missing Duplicate groups option and then a blank option label caused by an omitted UI label-map entry. Desktop review confirmed all seven labels in the proposal list, entity table, populated drawer, Tools, and Apply confirmation.
- Dependent browser QA exposed a build-process regression rather than a shared-helper regression: running a single-entry admin build had removed the compiled Entity Editor bundle and, in the active checkout, Content Collector V2 assets. Full repository builds restored all declared entry points in each checkout, Entity Editor mounts again, Bricks Portability renders, and future Proposal Diff rounds must use the full `npm run build`.
- Source and LocalWP resolved lanes each pass 103 tests and 963 assertions, and both pending lanes are empty. The active Entity Editor, Bricks, Configuration Portability, third-party portability, UID, and Content Migration matrix passes 197 tests and 2,452 assertions; the masking, media, transfer, and configuration-export matrix passes 68 tests and 545 assertions.
- Authenticated read-only QA returned HTTP 200 for every canonical filter, exact row/count parity, and matching seven-column WP-CLI output without changing proposal decisions or site data. Large filters still take roughly 14-16 seconds, and a real 390 px drawer renders a 716 px diff table inside a 312 px panel; both scaling issues remain assigned to Phase 7.2 rather than expanding CPD-012.

### CPD-022: Proposal Deletion Owns Its Media Bundle

Problem:
- Proposal deletion removed the proposal backup and review stores but did not remove the proposal-scoped media bundle copied into `sync/media-bundles/<proposal-id>` during intake.

Resolution plan:
1. Add a strict `BundleManager` deletion method that accepts only an already-sanitized proposal ID.
2. Remove only that proposal's bundle directory after its proposal backup is deleted.
3. Return the media cleanup result from proposal deletion and log a warning if the directory cannot be removed.
4. Add a regression test with neighboring proposal bundles so broad or parent-directory deletion cannot pass unnoticed.

Acceptance criteria:
- Deleting a proposal removes its ingested media bundle and leaves neighboring proposal bundles untouched.
- Proposal cleanup continues to remove its decisions, snapshots, masks, and resolver choices.
- A filesystem cleanup failure is observable in the deletion response and logs.

Implementation state:
- Done in Phase 4A.2a, with source and active LocalWP regression coverage and cleanup of the five disposable bundle directories created during authenticated Phase 4A QA.

Dependent QA:
- Proposal Review delete action and REST/CLI callers.
- Media Resolver, transfer packets, and Bricks media workflows that create or read proposal-scoped bundles.
- Backup manager, activity logging, and proposal decision/snapshot/mask cleanup.

## P3 Implementation Plans

### CPD-013: Proposal And Entity List Scaling

Problem:
- Virtualization is documented as complete, but the current entity table appears to render all rows inside a scroll container.
- Before the bounded-inventory slice, the authenticated connected list required 193.22 seconds for 11 proposals because every summary synchronously constructed full readiness, masking, snapshot, field, resolver, and add-on state before returning any rows.

Resolution plan:
1. Add timing/query-count baselines for proposal inventory, readiness summaries, and large entity tables before changing behavior.
2. Keep readiness semantics authoritative while replacing repeated per-proposal media lookups with bounded batching, cached summaries, or an explicit detail refresh path.
3. Add proposal list filters or pagination for REST, admin UI, and WP-CLI so operators can inspect one proposal without resolving every stored proposal.
4. Decide between true entity-table virtualization and server pagination; if virtualization is selected, use a proven library with stable row dimensions.
5. Update UI/CLI contracts and docs to match the selected behavior, then add large-manifest fixtures and performance smoke tests.

Acceptance criteria:
- Proposal inventory returns useful rows within an agreed performance budget and supports a bounded single-proposal path.
- Large entity proposals remain responsive.
- Readiness counts and blocker categories remain identical between bounded list output and proposal detail.
- Docs match implemented behavior.
- Selection, filters, status counts, and keyboard navigation still work.

Dependent QA:
- WP-CLI `proposals list`, `--fail-on-pending`, and any new ID/limit filters.
- Proposal REST/UI loading, media resolver decisions, and cached readiness invalidation after review actions.
- Large taxonomy proposals.
- Bricks payload proposals.
- Entity selection and bulk decisions.

Implementation state (2026-07-24):
- Steps 1-3 are partially implemented. Default REST inventory is paginated, returns deferred readiness, supports a proposal-ID filter, and avoids building full gates for every stored proposal; explicit full readiness and selected-proposal detail remain authoritative.
- The default connected inventory returned 11 rows in 11.18 seconds and 214 KB instead of 193.22 seconds. A heavy proposal's filtered full-readiness result and `/readiness` result matched exactly, and bounded WP-CLI lookup returned the same blocker set with `--id`.
- The admin UI correctly distinguishes deferred, checking, and loaded readiness and never enables Apply from a summary-only response.
- Steps 4-5 remain open. The UI does not yet expose proposal pagination controls, and the large entity endpoint/table still transfers and renders the complete entity set; the measured 755-entity response was 1.5 MB and took 15.75 seconds.

## P4 Implementation Plans

### CPD-014: Hide Dev Fixture Upload From Production Review

Problem:
- The core admin UI exposes a dev fixture upload path.

Resolution plan:
1. Gate fixture upload behind a constant such as `DBVC_ENABLE_DEV_FIXTURES` and `WP_DEBUG`.
2. Keep the REST route inaccessible unless the same gate is enabled.
3. Remove fixture controls from production bundles or hide them at localization time.
4. Keep `docs/fixtures/README.md` as the developer entry point.

Acceptance criteria:
- Production reviewers never see dev fixture controls.
- Devs can still seed fixtures intentionally.

Dependent QA:
- Proposal uploader.
- Packaging/release build.
- Fixture docs.

## P5 Implementation Plans

### CPD-015: Documentation Claim Reconciliation

Problem:
- Several docs claim behavior is complete even though the code path still has gaps.

Resolution plan:
1. Update docs only after the corresponding CPD item is fixed and tested.
2. For each corrected claim, link to the test or QA checklist that proves it.
3. Add a short "Known constraints" section where behavior remains intentionally limited.
4. Keep this sprint guide open until all claims are either implemented or explicitly scoped out.

Acceptance criteria:
- README, progress summary, UI architecture, term docs, media docs, and masking docs describe actual behavior.
- No doc states apply gating, resolver decisions, virtualization, or term parity as complete unless verified.

Dependent QA:
- User documentation library.
- In-plugin help text under DBVC -> Export.
- Release notes.

## Phase 4A - Cumulative Audit Remediation

- Audit date: 2026-07-20
- Audit status: `Done`
- Remediation status: `Done`

This checkpoint reviewed the implemented Phase 0 through Phase 4 code paths before beginning Phase 5.1. The initial audit found five evidence-backed follow-up items: three P0 issues, one P1 gap, and one P2 test-control gap. Authenticated implementation QA later exposed one additional P0 conflict in the active LocalWP AI upload router, tracked as CPD-021, and final cleanup review exposed one P2 proposal media-lifecycle gap, tracked as CPD-022; both were fixed during Phase 4A.

### Audit Findings

| ID | Priority | Status | Verified finding | Evidence and failure mode | Flagged dependents |
| --- | --- | --- | --- | --- | --- |
| CPD-016 | P0 | Done | Manifest resolver data can overwrite newer live reviewer decisions. | `DBVC_Admin_App::import_proposal_from_zip()` imported manifest resolver choices, and `DBVC_Sync_Posts::import_backup()` imported them again immediately before apply. The resolved flow now seeds only missing local choices, never imports archived global rules, and does not replay the manifest during apply. Authenticated upload/edit/apply QA confirmed that newer proposal and explicit global choices remain authoritative. | Proposal Review, REST apply, admin UI, WP-CLI, classic admin restore, resolver CSV/settings, future proposals using global rules, Media Hydration, Configuration Portability, transfer/Bricks media packages, logs and metrics. |
| CPD-021 | P0 | Done | The active LocalWP AI package detector intercepted ordinary Proposal Diff ZIPs. | `SubmissionPackageDetector::inspect_uploaded_zip()` treated any ZIP containing the supported legacy alias `manifest.json` as an AI package. Because the Proposal Review REST uploader and classic single-ZIP router call that detector before normal routing, a disposable proposal was staged as a blocked AI intake instead of being registered. The detector now requires AI-specific fields for the legacy alias while continuing to accept canonical `dbvc-ai-manifest.json`. | Proposal Review REST upload, classic sync upload, AI package validation/import, transfer packet intake, WP-CLI upload observation, retained AI reports/workspaces, upload logs, and package compatibility. |
| CPD-017 | P0 | Done | Resolver/media failures could pass readiness and still end as a closed, successful apply. | The importer now combines entity, reconciliation, resolver, and media-sync results before clearing decisions. The REST apply endpoint restores this proposal's prior decisions, persists `draft`, logs a failure, and returns HTTP 409 when that outcome is not successful; bundled downloads also verify their declared SHA-256 hash before attachment creation. Authenticated LocalWP QA reproduced `ready=true` followed by a required download failure and confirmed the corrected failure contract and cleanup. | Proposal Review, REST response contract, success/error notices, WP-CLI exit code, classic admin restore, activity logging, Media Hydration, Configuration Portability, transfer/Bricks packages, Official Collections promotion flows. |
| CPD-019 | P0 | Done | Proposal ZIP validation did not bound decompression work. | `validate_proposal_zip()` now checks all central-directory records before `ZipArchive::extractTo()`: no more than 10,000 entries, 256 MiB per expanded file, 1 GiB total expansion, and a 200:1 per-file compression ratio by default. Missing, negative, zero-when-nonempty, inconsistent, or directory-with-data size metadata is rejected; the mandatory positive ceilings are filterable through `dbvc_proposal_zip_resource_limits`. Authenticated REST and live WP-CLI QA both rejected a 1,014:1 fixture before proposal registration. | REST upload, WP-CLI upload, temp storage and cleanup, logging, public filter consumers, package producers, AI intake, classic ZIP import, Configuration Portability, transfer/Bricks packages, connected-site transport observation. |
| CPD-018 | P1 | Done | Non-post proposal domains bypassed implemented review and snapshot protections. | The apply contract now reports one non-overridable `unsupported_domains` blocker with stable counts for `options`, `options_group`, and `menus`. REST, the admin UI, WP-CLI, and blocked-apply logs consume the same result; post-backed FSE items remain on the normal post path, and classic/dedicated import tools are not proposal-gated. | Options and option groups, menu import, Configuration Portability, live auto-export hooks, proposal counters and labels, WP-CLI, rollback expectations, Official Collections. |
| CPD-020 | P2 | Done | There was no green cumulative regression lane for the phases already marked implemented. | Four Phase 5 methods now carry the `proposal-diff-pending` group. `composer test:proposal-diff-resolved` runs the broad Proposal Diff group while excluding only those methods, and `composer test:proposal-diff-pending` executes them directly; no test is skipped or deleted. Default and randomized runs were green in source and LocalWP, including the previously flagged Content Migration ordering boundary. | PHPUnit bootstrap, local/CI commands, Phase 0 completion evidence, core Proposal Diff tests, Content Migration test isolation, dependent add-on regression checks. |
| CPD-022 | P2 | Done | Proposal deletion left the ingested media-bundle directory behind. | Authenticated cleanup removed the proposal record and review state but a filesystem audit found five disposable Phase 4A bundle directories still under `sync/media-bundles`. Proposal deletion now invokes a proposal-scoped `BundleManager` cleanup, returns its result, and preserves neighboring bundles; all five Codex-created leftovers were then removed. | Proposal Review delete action, REST/CLI deletion callers, backup manager, decision/snapshot/mask stores, Media Resolver, transfer/Bricks media packages, logging, and disk lifecycle checks. |

### Resolution Order

#### 4A.1 - Preserve Live Resolver Authority (`CPD-016`, P0)

1. Treat manifest resolver content as an import-time seed or historical snapshot, not the live decision authority.
2. Seed proposal choices only when no live proposal or global choice exists; do not overwrite `__global` without a separate, explicit global-rule import action.
3. Remove or guard the second manifest import in `import_backup()`, then take one immutable effective-decision snapshot after readiness passes.
4. Add tests where older manifest proposal/global choices conflict with newer local choices, including classic restore and global-rule reuse by another proposal.

Acceptance checkpoint: the exact proposal and global choices shown immediately before apply are the choices consumed by reconciliation and media sync.

Implementation checkpoint (2026-07-20):
- Manifest proposal choices now seed only attachment IDs that have neither a local proposal choice nor a local global rule; archived global rules are not imported into the live site-wide store.
- `import_backup()` no longer replays manifest resolver data and instead takes one effective snapshot from the live local store before reconciliation and media sync.
- Source and LocalWP intake/apply regression coverage is green, as are the connected Entity Editor, Media Hydration, Configuration Portability, transfer, Bricks, and Content Migration checks.
- Authenticated LocalWP QA uploaded a disposable proposal carrying archived choices, saved a newer local `skip`, applied it, and confirmed that the local choice remained authoritative before and after apply. Archived global data was not imported, an explicit global-rule round trip succeeded, and the proposal folder and temporary rule were removed; the later Phase 4A.2 cleanup audit found and resolved its separate media-bundle cache under CPD-022.

#### 4A.1a - Preserve Proposal/AI Upload Routing (`CPD-021`, P0)

1. Treat canonical `dbvc-ai-manifest.json` as an AI package without weakening its existing validation path.
2. For the legacy `manifest.json` alias, require an AI package type or schema plus AI-specific marker fields before routing to AI intake.
3. Keep ordinary Proposal Diff and transfer ZIPs on the normal proposal/classic upload paths.
4. Cover both sides of the boundary: a core proposal must not be claimed, while a legacy AI package must remain compatible.

Acceptance checkpoint: an ordinary Proposal Diff ZIP reaches Proposal Review, canonical and legacy AI packages reach AI intake, and neither path leaves the other path's report or workspace behind.

Implementation checkpoint (2026-07-20):
- The active LocalWP-only `SubmissionPackageDetector` now applies the AI signature check to legacy `manifest.json`; the source worktree does not contain the AI Package subsystem and required no equivalent code change.
- Direct detector coverage excludes a core proposal manifest and preserves the existing legacy AI fixture contract.
- Authenticated REST and browser QA confirmed that a disposable proposal registers in Proposal Review, the AI workbench stays hidden after cleanup, 11 existing proposal rows load without console errors, and the disposable AI report/workspace are removed.

#### 4A.2 - Make Apply Outcomes Truthful (`CPD-017`, P0)

1. Define one post-apply result contract covering entity errors, reconciliation failures, resolver failures, and remaining required decisions.
2. Move automatic decision clearing and the `closed` transition after successful entity and media completion.
3. Keep the proposal in draft and preserve its decisions when a required media action fails; return a structured non-success from REST and a non-zero WP-CLI result.
4. Add tests for an unavailable forced download, bundle hash/import failure, reconciliation exception, and mixed entity-success/media-failure execution.

Acceptance checkpoint: REST, UI, WP-CLI, proposal status, logs, and outcome counters agree on success versus failure.

Implementation checkpoint (2026-07-20):
- `DBVC_Sync_Posts::import_backup()` now returns a structured outcome across entity writes, reconciliation, resolver decisions, and media sync; partial mode also checks required media when no entity rows need work.
- Automatic entity-decision clearing runs only after a successful combined outcome. `DBVC_Admin_App::apply_proposal()` restores this proposal's prior decision entry, keeps the manifest in `draft`, logs the structured failure, and returns `dbvc_proposal_apply_failed` with HTTP 409 when required work fails.
- Bundled resolver downloads validate the declared SHA-256 hash before copying or registering an attachment, and failed copy/registration results remain visible as failed resolver outcomes. The source importer also now counts a successfully created post as applied, matching the already-correct active LocalWP path.
- Focused source and LocalWP failure contracts pass. After the cleanup follow-up test was added, the source Proposal Diff group ran 63 tests and 411 assertions and the active group ran 63 tests and 410 assertions, both with only the same four planned Phase 5 failures; the connected package/import matrix passed 48 tests and 435 assertions, and Content Migration passed 32 tests and 568 assertions.
- Authenticated LocalWP QA confirmed a media-only proposal was ready before apply, then returned HTTP 409 with one media failure, preserved its required download choice, persisted `draft`, and removed its proposal record. The follow-up filesystem check exposed the separate media-bundle cleanup gap now fixed in Phase 4A.2a; the proposal-list status read again showed the already-tracked CPD-013 latency.
- The existing React request catch renders the REST failure as an Apply failed notice without clearing local decisions. The existing WP-CLI proposal command passes the same REST result to `WP_CLI::error`, preserving a non-zero CLI exit without a separate behavior fork.

#### 4A.2a - Close Proposal Media-Bundle Lifecycle (`CPD-022`, P2)

1. Make proposal deletion remove the exact media bundle copied for that proposal during intake.
2. Keep deletion proposal-scoped and prove that a neighboring proposal bundle is retained.
3. Return and log the media cleanup result so a filesystem failure is visible without hiding the proposal deletion result.
4. Remove the disposable bundle directories left by prior authenticated Phase 4A checks.

Acceptance checkpoint: deleting a proposal removes its proposal record, review stores, and owned media bundle without deleting another proposal's media.

Implementation checkpoint (2026-07-20):
- `Dbvc\Media\BundleManager::delete_bundle()` now validates an already-sanitized proposal ID and removes only that proposal's bundle directory.
- `DBVC_Admin_App::delete_proposal()` invokes the cleanup, returns `media_bundle_deleted`, and records warning logs when filesystem cleanup fails while retaining the existing proposal-state cleanup.
- The new source and active regression passed with 1 test and 8 assertions in each checkout, including the neighboring-bundle guard. The cumulative Proposal Diff totals are now 63 tests and 411 assertions in source and 63 tests and 410 assertions in LocalWP, with only the same four planned Phase 5 failures.
- A live cleanup probe removed all five Codex-created Phase 4A bundle directories and confirmed none remained.

#### 4A.3 - Bound ZIP Extraction Resources (`CPD-019`, P0)

1. Add filterable limits for entry count, per-entry expanded bytes, total expanded bytes, and compression ratio before extraction.
2. Reject missing, negative, inconsistent, or otherwise unusable ZIP stat values where a safe budget cannot be established; account for directory entries separately.
3. Add boundary tests for too many entries, oversized entries, excessive total expansion, high ratios, and valid archives immediately below each limit.
4. Re-run REST and WP-CLI upload QA and observe package-producing add-ons for archives near the supported limits.

Acceptance checkpoint: no archive reaches `extractTo()` unless its complete extraction budget has been validated.

Implementation checkpoint (2026-07-20):
- Proposal intake now validates entry count, per-file expanded bytes, total expanded bytes, compression ratio, and central-directory size consistency before any archive entry is written.
- Defaults are 10,000 archive entries, 256 MiB per expanded file, 1 GiB total expansion, and a 200:1 per-file ratio. `dbvc_proposal_zip_resource_limits` can lower or raise positive ceilings, while invalid and non-positive values fall back to the mandatory defaults.
- Rejections return `dbvc_zip_resource_limit` for exceeded budgets or `dbvc_zip_stats_invalid` for unusable metadata, include safe numeric limit details, and continue through the existing sanitized upload/activity logging path.
- Focused source and LocalWP intake coverage passed 32 tests and 206 assertions each, including mandatory-default fallback for invalid filter values. The cumulative Proposal Diff groups ran 75 tests with 472 source assertions and 471 LocalWP assertions, with only the same four planned Phase 5 failures.
- The connected Proposal/AI/import/transfer matrix passed 60 tests and 496 assertions. Separate core, Configuration Portability, and Bricks package/extraction consumers passed 72 tests and 1,162 assertions; Bricks connected-site transport passed 40 tests and 231 assertions; Content Migration passed 32 tests and 568 assertions.
- Authenticated LocalWP REST QA rejected a 1,014:1 ZIP with HTTP 400 and `compression_ratio_exceeded` before registration, accepted a normal proposal with HTTP 200, and deleted it cleanly. Live WP-CLI rejected the same unsafe fixture with exit code 1.
- AI intake, classic upload extraction, Configuration Portability, and Bricks Portability retain separate extraction implementations. This phase did not silently apply Proposal Diff limits to those tools; their regression checks are green, and their independent extraction-security boundaries remain flagged for tool-specific review.

#### 4A.4 - Gate Non-Post Apply Domains (`CPD-018`, P1)

1. Inventory the actual apply units and current-state baselines for options, option groups, and menus.
2. Add domain-specific decisions, readiness counts, and trusted baseline states; if a domain cannot yet meet that contract, block it explicitly instead of treating it as reviewed.
3. Verify accepted, kept, declined, missing-baseline, apply, and rollback behavior through REST, UI, WP-CLI, and direct importer tests.
4. Re-test Configuration Portability, auto-export hooks, menu import, and any Official Collections path that promotes these domains.

Acceptance checkpoint: every writable proposal domain is either review-gated with a trusted baseline or visibly blocked as unsupported.

Implementation checkpoint (2026-07-20):
- The writable non-post inventory is the three manifest item types consumed directly by the shared importer: `options`, `options_group`, and `menus`. Post-backed FSE entities remain `post` items and are not included.
- `build_proposal_apply_gates()` now adds a mandatory `unsupported_domains` blocker and stable per-type counts. No hash override or other apply option can remove it, and the existing list, detail, apply-modal, REST, WP-CLI, and logging consumers display the same message without a frontend schema change.
- The gate is scoped to the Proposal Review wrapper. Classic restore, flat JSON routing, ACF option-group tools, menu import, Configuration Portability, Entity Editor, and Bricks option artifacts retain their existing dedicated contracts.
- Source and LocalWP focused contracts passed 3 tests and 21 assertions each. The complete Proposal Diff groups ran 77 tests with 489 source assertions and 488 LocalWP assertions, with only the same four planned Phase 5 failures.
- LocalWP router coverage passed 2 tests and 29 assertions, and the combined Configuration Portability, UID, Entity Editor, third-party portability, Bricks Portability, and Bricks reference set passed 99 tests and 1,350 assertions.
- Authenticated LocalWP QA uploaded one three-domain proposal, confirmed `ready=false` and exact per-type counts, received HTTP 409 from REST apply and exit code 1 from WP-CLI apply, saw `Blocked (1)` plus the same reason and a disabled Apply button in Proposal Review, and confirmed two activity-log records. A controlled administrator classic import still processed the options/menu payload outside Proposal Review; its sentinel option and the disposable proposal were then removed.
- This phase resolves the bypass by blocking unsupported domains. It does not claim field-level option/menu review, non-post snapshots, or non-post rollback; those capabilities would require a separate domain-specific design before this blocker can be removed.

#### 4A.5 - Add a Green Resolved-Phase Lane (`CPD-020`, P2)

1. Add a `proposal-diff-resolved` test group/command for implemented Phase 0-4 contracts and keep pending Phase 5+ contracts in a separate lane.
2. Make the resolved command deterministic and suitable as a local and CI requirement.
3. Isolate global/static WordPress state that causes test-order failures, retaining isolated dependent suites as diagnostic commands.
4. Keep authenticated browser apply and direct live WP-CLI apply as explicit connected-site QA rather than silently treating unit coverage as equivalent.

Acceptance checkpoint: implemented Proposal Diff contracts have a repeatable green command, while planned failures remain visible in their own lane.

Implementation checkpoint (2026-07-21):
- Added `test:proposal-diff-resolved` and `test:proposal-diff-pending` Composer scripts in source and LocalWP. Both disable PHPUnit result-cache writes so required runs do not churn `.phpunit.result.cache`.
- Tagged only the four existing Phase 5 contracts: post-field masking and nested-meta apply units for Phase 5.1, term masking for Phase 5.2, and declined-new summary state for Phase 5.4. Fixing one requires removing its pending tag so it immediately joins the required lane.
- The resolved command passed 73 tests and 481 assertions in both checkouts in default order and randomized order with seed `20260721`. The source full suite excluding only pending contracts passed 208 tests and 1,246 assertions.
- The pending command ran exactly four tests and reproduced exactly four failures: 8 source assertions and 7 LocalWP assertions, reflecting the known checkout-specific importer boundary without changing the failing contract identities.
- The active resolved-plus-Content-Migration diagnostic passed 105 tests and 1,049 assertions in default and randomized order. The previously observed media rollback ordering failure did not reproduce, so no dependent production or test code needed modification.
- The connected Configuration Portability, UID, Entity Editor, third-party portability, Bricks Portability, and Bricks reference matrix remained green at 99 tests and 1,350 assertions.
- This phase changes test metadata and Composer commands only. It does not replace authenticated browser, REST, or WP-CLI apply QA for behavior-changing phases.

### Confirmed Stable Or Not Misclassified

- The resolved Proposal Diff lane passes 103 tests and 963 assertions in source and LocalWP. The pending lane remains empty after Phase 7.1.
- The expanded active LocalWP dependent matrix for Entity Editor, Bricks Portability, Bricks reference mapping/drift/truncation, Configuration Portability, third-party portability, UID preservation, and Content Migration passes 197 tests and 2,452 assertions. The additional masking, media, transfer, and configuration-export matrix passes 68 tests and 545 assertions.
- The active full diagnostic was not rerun in Phase 5.4. Its previously recorded five failures remain outside Proposal Diff: two Bricks language/disabled-mode checks and three Content Collector/settings checks.
- Classic restore uses the affected shared importer and remains flagged under CPD-016 and CPD-017, but its proposal-gate boundary coverage passes.
- `Dbvc\Official\Collections::mark_official()` still has no caller or direct test in the current source checkout. Promotion QA remains unverified, but no runtime failure was invented or demonstrated there.
- Connected readiness, apply, forced-reapply, log, REST, and cleanup QA for Phase 5.4 is complete. Phase 7.1 canonical REST, UI, and WP-CLI counts are also verified; the external ACF page REST fatal remains under CDF-026, CDF-027 is structurally resolved, and remaining proposal/entity scaling stays assigned to Phase 7.2.

Sequencing decision: Phase 7.1 / CPD-012 is complete. Proceed to Phase 7.2 / CPD-013 for admin pagination controls, large-entity server pagination/windowing, 14-16 second large-filter latency, and the verified 390 px table/drawer overflow while keeping CPD-002's resolver-specific connected QA in its existing review lane.

## Implementation Phase / Sub-Phase Tracker

Status values: `Not started`, `In progress`, `Blocked`, `In review`, `Done`.

| Phase | Sub-phase | Status | CPD scope | Implementation checkpoint | Conflict flags to observe |
| --- | --- | --- | --- | --- | --- |
| Phase 0 - Test Foundation | 0.1 Restore WP PHPUnit and bootstrap smoke path. | Done | CPD-005 | Tests can run locally/CI; Proposal Diff failure coverage exists before behavior changes. | CDF-001, CDF-008, CDF-009, CDF-010, CDF-017, CDF-018, CDF-019, CDF-020, CDF-021 |
| Phase 0 - Test Foundation | 0.2 Add failing coverage for P0/P1 Proposal Diff blockers. | Done | CPD-001, CPD-002, CPD-003, CPD-004, CPD-006, CPD-007, CPD-008, CPD-009 | Tests expose current unsafe apply, snapshot, ZIP, masking, duplicate, and decline-state behavior while locking the existing resolver bridge. | CDF-001, CDF-003, CDF-004, CDF-005, CDF-006, CDF-013, CDF-014, CDF-016, CDF-018, CDF-020 |
| Phase 1 - Intake Boundary | 1.1 Harden proposal ZIP entry validation before extraction. | Done | CPD-004 | Unsafe paths, symlinks, traversal, absolute paths, and unsupported entries are rejected before `extractTo`. | CDF-006, CDF-007, CDF-016, CDF-017, CDF-019 |
| Phase 1 - Intake Boundary | 1.2 Verify non-ZIP import router paths still work. | Done | CPD-004, CPD-008, CPD-014 | Flat JSON, post, term, options, menu, and fixture upload routes keep their intended behavior. | CDF-007, CDF-015, CDF-020 |
| Phase 2 - Apply Readiness | 2.1 Build the server-side proposal apply gate contract. | Done | CPD-001 | One shared readiness contract reports blockers, warnings, counts, and allowed overrides. | CDF-003, CDF-004, CDF-005, CDF-010, CDF-012, CDF-016, CDF-018, CDF-020 |
| Phase 2 - Apply Readiness | 2.2 Wire the gate to REST, admin UI, WP-CLI, and logs. | Done | CPD-001 | UI disabled reasons, REST errors, CLI exit codes, and activity logs match the same blocker categories. | CDF-003, CDF-004, CDF-008, CDF-010, CDF-016, CDF-018 |
| Phase 3 - Snapshot Truth | 3.1 Replace silent snapshot fallback with explicit snapshot states. | Done | CPD-003 | Missing, stale, captured, and recapturable snapshot states are visible and enforceable. | CDF-001, CDF-006, CDF-011, CDF-012, CDF-013, CDF-018, CDF-020, CDF-021 |
| Phase 3 - Snapshot Truth | 3.2 Add recapture path and apply gating for missing snapshots. | Done | CPD-003 | Recapture works from UI/CLI where allowed; apply blocks when a required baseline cannot be trusted. | CDF-004, CDF-006, CDF-012, CDF-013, CDF-016, CDF-021 |
| Phase 3 - Snapshot Truth | 3.3 Merge completed slices into LocalWP and run connected/dependent QA. | Done | CPD-001, CPD-003, CPD-004, CPD-005, CPD-013 | Active plugin, build, REST, WP-CLI, dependent tests, authenticated Proposal Review browser states, and console checks are verified; unfinished scaling is isolated under Phase 7.2. | CDF-001, CDF-004, CDF-005, CDF-006, CDF-008, CDF-010, CDF-011, CDF-015, CDF-017, CDF-019, CDF-020 |
| Phase 4 - Media Resolution | 4.1 Preserve and formalize existing resolver decision bridge. | In review | CPD-002 | One normalized proposal/global map governs reconciliation and media sync; actual source and outcomes are returned without changing existing keys or filter signatures. | CDF-003, CDF-004, CDF-005, CDF-006, CDF-016, CDF-018, CDF-022, CDF-023 |
| Phase 4 - Media Resolution | 4.2 Verify all resolver actions during proposal apply. | In review | CPD-002, CPD-012 | Automated LocalWP coverage proves `reuse`, `map`, `download`, and `skip` produce consistent maps, creation behavior, metrics, and failures; authenticated visual apply QA remains. | CDF-003, CDF-004, CDF-005, CDF-014, CDF-015, CDF-016, CDF-018, CDF-022, CDF-023 |
| Phase 4A - Cumulative Audit Remediation | 4A.0 Audit implemented Phase 0-4 behavior and connected dependents. | Done | CPD-001 through CPD-005, CPD-016 through CPD-020 | Code-path tracing, focused runtime probes, cumulative tests, and LocalWP dependent suites identify only evidence-backed findings before Phase 5.1. | CDF-001, CDF-003, CDF-004, CDF-005, CDF-006, CDF-007, CDF-008, CDF-009, CDF-010, CDF-012, CDF-013, CDF-015, CDF-016, CDF-017, CDF-018, CDF-019, CDF-020, CDF-021, CDF-022, CDF-023 |
| Phase 4A - Cumulative Audit Remediation | 4A.1 Preserve live resolver authority over manifest snapshots. | Done | CPD-016 | Manifest choices seed only when no local proposal/global authority exists; apply consumes the live store without replay. Authenticated disposable upload/edit/apply and cleanup passed. | CDF-003, CDF-004, CDF-005, CDF-006, CDF-016, CDF-018, CDF-022, CDF-023 |
| Phase 4A - Cumulative Audit Remediation | 4A.1a Preserve Proposal Diff routing at the shared AI upload boundary. | Done | CPD-021 | Legacy `manifest.json` enters AI intake only with an AI signature; ordinary proposals retain the normal REST/classic upload path. | CDF-004, CDF-006, CDF-007, CDF-016, CDF-019, CDF-024 |
| Phase 4A - Cumulative Audit Remediation | 4A.2 Make proposal closure and success depend on entity and media outcomes. | Done | CPD-017 | Required media failures preserve draft/review state and produce matching REST, UI, CLI, and log results. | CDF-003, CDF-004, CDF-005, CDF-012, CDF-015, CDF-016, CDF-018, CDF-022, CDF-023 |
| Phase 4A - Cumulative Audit Remediation | 4A.2a Remove proposal-owned media bundles during proposal deletion. | Done | CPD-022 | Proposal deletion removes only its media bundle, reports cleanup, and preserves neighboring bundles. | CDF-003, CDF-004, CDF-005, CDF-006, CDF-016, CDF-023, CDF-025 |
| Phase 4A - Cumulative Audit Remediation | 4A.3 Enforce ZIP extraction resource ceilings. | Done | CPD-019 | Mandatory entry, per-file, total expanded-size, compression-ratio, and stat-consistency budgets are validated before extraction. | CDF-004, CDF-006, CDF-007, CDF-016, CDF-017, CDF-018, CDF-019, CDF-023, CDF-024 |
| Phase 4A - Cumulative Audit Remediation | 4A.4 Add or enforce review gates for non-post domains. | Done | CPD-018 | Proposal Review blocks options, option groups, and menus with stable counts until trusted baseline and decision support exists; dedicated import paths remain available. | CDF-006, CDF-007, CDF-012, CDF-013, CDF-016, CDF-018, CDF-020, CDF-021 |
| Phase 4A - Cumulative Audit Remediation | 4A.5 Add a deterministic green regression lane for resolved phases. | Done | CPD-020 | `composer test:proposal-diff-resolved` is green and the remaining executable contracts stay isolated under `proposal-diff-pending`. | CDF-001, CDF-003, CDF-004, CDF-005, CDF-008, CDF-009, CDF-010, CDF-017, CDF-018, CDF-020, CDF-022, CDF-023 |
| Phase 5 - Apply Semantics | 5.1 Align review decision paths with importer apply units. | Done | CPD-006 | Canonical row metadata, read-only boundaries, safe nested merge/removal, masking decision preservation, and LocalWP apply QA now match reviewer choices to importer behavior. | CDF-001, CDF-002, CDF-010, CDF-011, CDF-012, CDF-013, CDF-014, CDF-018, CDF-020, CDF-021, CDF-026 |
| Phase 5 - Apply Semantics | 5.2 Wire term masking overrides into term and term-meta import. | Done | CPD-007 | Canonical term suppressions/overrides now flow through safe meta merge with counters, logs, revert coverage, and connected LocalWP QA. | CDF-002, CDF-014, CDF-016, CDF-018, CDF-020, CDF-021 |
| Phase 5 - Apply Semantics | 5.3 Unify duplicate detection across upload, list, UI gate, REST, CLI, and cleanup. | Done | CPD-008 | One typed detector now governs upload, list, report, readiness, UI/REST cleanup, and CLI; cleanup uses canonical IDs and a recoverable manifest transaction. | CDF-003, CDF-004, CDF-006, CDF-011, CDF-013, CDF-020 |
| Phase 5 - Apply Semantics | 5.4 Treat declined new entities as resolved skip states. | Done | CPD-009 | Explicit accepted/declined/pending states govern summaries, masking, readiness, import skips, archived state, REST, UI, logs, and WP-CLI without force-reapply resurrection. | CDF-004, CDF-010, CDF-012, CDF-013, CDF-016, CDF-027 |
| Phase 5A - Verified Runtime Follow-Ups | 5A.1 Preserve React ownership of DBVC inline notices. | Done | CDF-027; CPD-013 follow-up | Shared app notices use DBVC-only classes, so the enabled site notice manager cannot mutate React-owned status nodes; browser and console QA pass. | CDF-001, CDF-008, CDF-009, CDF-015, CDF-027 |
| Phase 5A - Verified Runtime Follow-Ups | 5A.2 Defer list readiness and add bounded inventory lookup. | Done | CPD-013 partial | Default REST inventory is paginated/deferred, selected readiness stays authoritative, UI Apply remains guarded, and WP-CLI supports `--id`; entity windowing remains Phase 7.2. | CDF-001, CDF-004, CDF-005, CDF-009, CDF-015, CDF-018, CDF-027 |
| Phase 6 - Diff Review Depth | 6.1 Introduce classified diff payloads and stable change IDs. | Done | CPD-010 | Diff responses include explicit change types, presence semantics, complete apply paths, bounded values/rows, sections, limits, and stable IDs; UI and connected raw fallbacks are verified. | CDF-006, CDF-009, CDF-011, CDF-014, CDF-015, CDF-018, CDF-019, CDF-020, CDF-021 |
| Phase 6 - Diff Review Depth | 6.2 Add first-class raw diff and mode-specific drawer views. | Done | CPD-011 | Changed remains canonical, All Fields includes supported unchanged fields, and Raw JSON provides bounded previews, a value-free change index, full downloads, stable selectors, and verified focus recovery. | CDF-006, CDF-009, CDF-014, CDF-015, CDF-018, CDF-020 |
| Phase 7 - UI Clarity | 7.1 Normalize status labels and counters. | Done | CPD-012 | Seven canonical counts and labels now agree across REST, list, table, drawer, Tools, Apply, filters, WP-CLI, and docs; legacy fields remain compatible and dependent build artifacts are restored. | CDF-001, CDF-005, CDF-008, CDF-015, CDF-020, CDF-027 |
| Phase 7 - UI Clarity | 7.2 Address proposal and entity list scaling. | In progress | CPD-013 | Proposal inventory and bounded single-ID lookup are implemented; admin pagination controls, large-entity server pagination/windowing, 14-16 second large-filter latency, matching docs/tests, and verified 390 px sticky-header/table/drawer overflow remain. | CDF-001, CDF-004, CDF-005, CDF-009, CDF-015, CDF-018, CDF-027 |
| Phase 8 - Production Hygiene | 8.1 Hide or gate dev fixture upload in production review UI. | Not started | CPD-014 | Fixture tooling requires an explicit dev gate and is absent from normal production review. | CDF-007, CDF-015, CDF-020 |
| Phase 9 - Documentation Closeout | 9.1 Reconcile docs and in-plugin help against implemented behavior. | Not started | CPD-015 | Docs describe only verified behavior and include remaining limits/known operational caveats. | CDF-006, CDF-008, CDF-010, CDF-012, CDF-016, CDF-017, CDF-018, CDF-019, CDF-020, CDF-021, CDF-027 |

## Cross-Surface QA Matrix

| Surface | Required QA after P0/P1 fixes |
| --- | --- |
| React proposal list | Counts, duplicate badges, resolver badges, snapshot states, Apply disabled reasons. |
| Entity table | Filters, search, new entity badges, media/meta labels, selection, bulk decisions, duplicate overlay. |
| Entity drawer | Diff row change types, decision persistence, nested meta behavior, term fields, raw/all modes. |
| Tools drawer | Masking load/apply/revert, term masks, badge count updates, hash/snapshot actions. |
| Resolver workspace | Proposal-specific decisions, global rules, bulk rules, bundle/remote transport modes, apply summaries. |
| REST API | Direct blocked apply, successful apply, duplicate report/cleanup, masking endpoints, resolver decisions, snapshot recapture. |
| WP-CLI | `proposals list`, `upload`, `apply`, `--fail-on-pending`, `--recapture-snapshots`, `resolver-rules`, list latency, and bounded proposal selection. |
| Importer | Post fields, post meta, nested meta, taxonomies, terms, termmeta, media maps, declined new entities. |
| Settings | `dbvc_import_require_review`, `dbvc_auto_clear_decisions`, `dbvc_force_reapply_new_posts`, `dbvc_proposal_zip_resource_limits`, media transport, masking presets, logging toggles. |
| Add-ons/future features | Bricks entity-backed artifacts, Bricks options-backed artifacts, Official Collections marking, user docs library. |

## Definition of Done For The Sprint

- All CPD P0 and P1 items are implemented and covered by automated tests.
- REST, UI, and WP-CLI share the same apply readiness contract.
- Existing proposals cannot falsely show clean diffs because of missing snapshots.
- Resolver and masking decisions affect actual importer behavior.
- Duplicate, new entity, media, masking, and snapshot blockers are visible and enforced.
- Imported manifest resolver snapshots cannot overwrite newer reviewed proposal or global choices.
- Shared upload routing cannot divert an ordinary Proposal Diff ZIP into AI package intake.
- A proposal cannot close or report success while a required entity or media action has failed.
- Deleting a proposal removes its owned media bundle without removing neighboring proposal bundles.
- Every writable proposal domain is review-gated with a trusted baseline or explicitly blocked as unsupported.
- Proposal ZIP extraction is bounded by validated entry and expanded-size budgets.
- Implemented phases have a deterministic green regression command separate from planned contract failures.
- Docs and in-plugin help text describe verified behavior only.
