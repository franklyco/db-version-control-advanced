# Core Proposal Diff Resolution Sprint

Last updated: 2026-07-20

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
| CDF-001 | Entity Editor REST + UI | `DBVC_Entity_Editor_Indexer`, `/dbvc/v1/entity-editor/*`, `src/admin-entity-editor/index.js`, `build/admin-entity-editor.js` | CPD-003, CPD-006, CPD-007, CPD-010, CPD-012, CPD-013 | Entity Editor uses the same entity identity, sync JSON, post/term meta, taxonomy import, and shared CSS conventions, but it is not a proposal-review flow. Proposal gates, decision stores, and snapshot-missing states must not block save-only, partial import, or full replace actions. | Run `EntityEditorEndpointsTest`; manually verify index, file load, save-only, partial import, full replace snapshot, lock takeover, and bulk download. |
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
| CDF-018 | Public hook/filter contracts | `dbvc_import_backup_override`, `dbvc_enable_snapshot_capture`, `dbvc_media_use_legacy_sync`, `dbvc_masking_chunk_size`, `dbvc_bricks_*` hooks, Entity Editor protected-meta filters | CPD-001, CPD-002, CPD-003, CPD-006, CPD-007, CPD-010, CPD-013 | Several tests and possible site customizations rely on these hooks. Changing signatures, timing, or defaults can break downstream custom tools even when core behavior passes. | Preserve hook signatures or document migrations; add regression tests for filters/actions that are part of shipped docs or PHPUnit coverage. |
| CDF-019 | Bricks connected-site transport and onboarding | `DBVC_Bricks_Connected_Sites`, `DBVC_Bricks_Command_Auth`, `DBVC_Bricks_Command_Queue`, `DBVC_Bricks_Onboarding`, `dbvc_bricks_clients`, `dbvc_bricks_onboarding_transport`, remote publish/pull/ack settings | CPD-004, CPD-005, CPD-010, CPD-015 | Package schema, artifact diff, ZIP/payload validation, auth settings, and admin asset changes can affect client/mothership transport even when local Bricks apply still passes. | QA connection test, publish remote, pull latest, pull ack, intro packet, handshake, command ping, registry mode, TLS/timeout/auth settings, and diagnostics. |
| CDF-020 | Non-post proposal domains | `DBVC_Sync_Taxonomies`, `DBVC_Options_Groups`, `DBVC_Menu_Importer`, FSE theme data import/export, term/options/menu/FSE filters | CPD-001, CPD-003, CPD-006, CPD-007, CPD-008, CPD-010, CPD-011, CPD-012, CPD-014, CPD-015 | Proposal Diff fixes can drift toward post/meta assumptions. Options groups, menus, FSE data, and taxonomy entities need stable manifest refs, diff sections, apply units, masking behavior, and duplicate/new-entity states. | Build proposals for terms, options groups, menus, and FSE theme data; verify review counts, decisions, apply, rollback/snapshot behavior, and export filters. |
| CDF-021 | Live auto-export and change hooks | `includes/hooks.php`, `dbvc_after_post_meta_update`, `dbvc_after_term_changes`, `dbvc_after_option_update`, `dbvc_after_menu_updates`, `dbvc_after_fse_changes`, hook-triggered export helpers | CPD-003, CPD-006, CPD-007, CPD-010, CPD-015 | Snapshot capture, masking, export shape, and entity hash changes can change the live baselines that later Proposal Diff compares against. Hook timing regressions are easy to miss in proposal-only tests. | Trigger post save, meta update, term edit, option update, menu save/delete, and FSE changes; verify exported JSON, entity registry updates, and later proposal diff baselines. |
| CDF-022 | Media Hydration inventory/planner/apply | `includes/Dbvc/Media/Hydration/*`, `_wp_attached_file`, `vf_asset_uid`, `vf_file_hash`, `_dbvc_original_attachment_id`, hydration plans/receipts/jobs | CPD-002, CPD-012 | Resolver `download`, `map`, and `reuse` can create or select attachments using the same identity and file metadata that hydration scans and updates. Duplicate creation, missing attached-file metadata, or overwritten paths can change hydration matching and inventory results. | Run `MediaHydrationInventoryTest`; dry-run a hydration plan after each resolver action; verify inventory identity, file state, hashes, overwrite policy, receipts, and job summaries. |
| CDF-023 | Configuration Portability and transfer/Bricks media packages | `MediaHandlingProvider`, `EntityPacketBuilder`, `DBVC_Bricks_Portability_Media_Apply_Service`, media options, `media_bundle`, transfer package metadata | CPD-002, CPD-012, CPD-015 | Resolver work shares media transport settings and bundled attachment metadata with configuration exports, transfer packets, and Bricks Portability. Option-key, manifest, or bundle-path drift could make a core fix alter package contents or downstream media apply behavior. | Run `ConfigurationPortabilityRegistryTest`, `TransferPacketWorkflowTest`, `BricksPortabilityManagerTest`, and `BricksReferenceMappingTest`; compare media option and bundle metadata before/after. |

## CPD Conflict Flag Quick Map

| CPD item | Conflict flags to keep attached during implementation |
| --- | --- |
| CPD-001 Apply gate contract | CDF-003, CDF-004, CDF-005, CDF-008, CDF-010, CDF-012, CDF-016, CDF-018, CDF-020 |
| CPD-002 Resolver decisions | CDF-003, CDF-004, CDF-005, CDF-006, CDF-016, CDF-018, CDF-022, CDF-023 |
| CPD-003 Snapshot state | CDF-001, CDF-006, CDF-011, CDF-012, CDF-013, CDF-018, CDF-020, CDF-021 |
| CPD-004 ZIP intake hardening | CDF-006, CDF-007, CDF-016, CDF-017, CDF-019 |
| CPD-005 Test harness | CDF-001, CDF-008, CDF-009, CDF-010, CDF-017, CDF-018, CDF-019, CDF-020, CDF-021 |
| CPD-006 Decision granularity | CDF-001, CDF-002, CDF-010, CDF-011, CDF-012, CDF-013, CDF-014, CDF-018, CDF-020, CDF-021 |
| CPD-007 Term masking overrides | CDF-002, CDF-014, CDF-016, CDF-018, CDF-020, CDF-021 |
| CPD-008 Duplicate detection | CDF-003, CDF-004, CDF-006, CDF-011, CDF-013, CDF-020 |
| CPD-009 New entity decline | CDF-004, CDF-010, CDF-012, CDF-013, CDF-016 |
| CPD-010 Classified diff payloads | CDF-006, CDF-009, CDF-011, CDF-014, CDF-015, CDF-018, CDF-019, CDF-020, CDF-021 |
| CPD-011 Raw diff / view modes | CDF-009, CDF-015, CDF-020 |
| CPD-012 Status labels and counters | CDF-001, CDF-005, CDF-008, CDF-015, CDF-020 |
| CPD-013 Proposal/entity list scaling | CDF-001, CDF-004, CDF-005, CDF-009, CDF-015, CDF-018 |
| CPD-014 Dev fixture upload | CDF-007, CDF-015, CDF-020 |
| CPD-015 Documentation reconciliation | CDF-006, CDF-008, CDF-010, CDF-012, CDF-016, CDF-017, CDF-018, CDF-019, CDF-020, CDF-021 |
| CPD-016 Resolver decision authority | CDF-003, CDF-004, CDF-005, CDF-006, CDF-016, CDF-018, CDF-022, CDF-023 |
| CPD-017 Apply outcome truth | CDF-003, CDF-004, CDF-005, CDF-012, CDF-015, CDF-016, CDF-018, CDF-022, CDF-023 |
| CPD-018 Non-post apply coverage | CDF-006, CDF-007, CDF-012, CDF-013, CDF-016, CDF-018, CDF-020, CDF-021 |
| CPD-019 ZIP extraction resource limits | CDF-004, CDF-006, CDF-007, CDF-016, CDF-017, CDF-019, CDF-023 |
| CPD-020 Resolved-phase regression lane | CDF-001, CDF-003, CDF-004, CDF-005, CDF-008, CDF-009, CDF-010, CDF-017, CDF-018, CDF-020, CDF-022, CDF-023 |

## Sprint Board

| ID | Priority | Status | Finding | Primary resolution | Dependent surfaces to QA |
| --- | --- | --- | --- | --- | --- |
| CPD-001 | P0 | Done | Apply can run while blockers remain. | Build and enforce a single server-side apply gate contract. | `core-rest`, `admin-ui`, `wp-cli`, `duplicate-cleanup`, `media-resolver`, `masking`, `terms`, `settings`, `logging` |
| CPD-002 | P0 | In review | Resolver decisions were stored/displayed but were not authoritative during reconciliation. | Use one normalized proposal/global decision map across reconciliation and media sync, with actual outcome reporting. | `media-resolver`, `core-importer`, `admin-ui`, `wp-cli`, `settings`, `media-hydration`, `configuration-portability`, `transfer-media`, `bricks-addon`, `logging` |
| CPD-003 | P0 | Done | Missing snapshots can compare proposed payloads against themselves. | Replace silent fallback with explicit snapshot states, recapture path, and apply gating. | `snapshot-manager`, `core-rest`, `admin-ui`, `terms`, `wp-cli`, `bricks-addon`, `tests` |
| CPD-004 | P0 | Done | Proposal ZIP extraction is not visibly hardened before `extractTo`. | Validate ZIP entries before extraction and reject unsafe paths. | `core-rest`, `wp-cli`, `logging`, `tests` |
| CPD-005 | P0 | In review | Automated test harness is not runnable in this checkout. | Restore/verify WP PHPUnit installation path and make Proposal Diff tests required. | `tests`, CI/dev tooling, `README.md`, `docs/progress-summary.md` |
| CPD-006 | P1 | Not started | Review decisions can be more granular than importer apply units. | Align diff decision paths with actual apply units or implement safe nested merge semantics. | `core-importer`, `core-rest`, `admin-ui`, `masking`, `terms`, `bricks-addon`, `official-collections` |
| CPD-007 | P1 | Not started | Term masking overrides are not wired into term import. | Pass mask overrides/suppressions through term apply and term meta apply. | `core-importer`, `masking`, `terms`, `settings`, `logging`, `tests` |
| CPD-008 | P1 | Not started | Duplicate detection is inconsistent between upload, report, UI, and apply. | Unify duplicate detector and enforce it at upload, list, UI gate, REST apply, and CLI apply. | `duplicate-cleanup`, `core-rest`, `core-importer`, `admin-ui`, `terms`, `bricks-addon`, `wp-cli` |
| CPD-009 | P1 | Not started | Declined new entities still count as pending in proposal summaries. | Treat decline decisions as resolved-skip states across summaries, gates, apply, and history. | `terms`, `admin-ui`, `core-importer`, `core-rest`, `wp-cli`, `settings` |
| CPD-010 | P2 | Not started | Diff engine is shallow compared with the intended review contract. | Introduce classified diff payloads with stable change IDs, change types, limits, and section metadata. | `core-rest`, `admin-ui`, `snapshot-manager`, `masking`, `terms`, `bricks-addon`, `tests` |
| CPD-011 | P2 | Not started | `View All` is meta-focused and raw JSON is not a true raw diff view. | Add first-class `RawDiffView` and mode-specific payloads. | `admin-ui`, `core-rest`, `docs/UI-ARCHITECTURE.md`, browser QA |
| CPD-012 | P2 | Not started | UI labels conflate unresolved media with unresolved meta. | Normalize status labels and count names across proposal list, entity table, drawer, and apply modal. | `admin-ui`, `media-resolver`, `masking`, `docs/meta-masking.md`, `README.md` |
| CPD-013 | P3 | Not started | Proposal listing performs expensive live resolution and entity table virtualization is documented but not implemented. | Bound proposal-summary work and add practical list filtering/pagination, then implement real entity windowing or correct the docs. | `admin-ui`, `wp-cli`, `media-resolver`, large proposals, `terms`, `bricks-addon`, `tests` |
| CPD-014 | P4 | Not started | Dev fixture upload is exposed in the core admin UI. | Hide behind an explicit dev capability/constant or remove from production bundle. | `admin-ui`, `core-rest`, `docs/fixtures/README.md`, packaging QA |
| CPD-015 | P5 | Not started | Docs claim completed behavior that current code does not fully provide. | Update docs after fixes land and mark old claims as corrected. | `README.md`, `docs/progress-summary.md`, `docs/UI-ARCHITECTURE.md`, `docs/terms.md`, `docs/media-sync-design.md` |
| CPD-016 | P0 | Not started | Manifest resolver snapshots can replace newer proposal-specific and global reviewer choices during upload or apply. | Separate imported seed/snapshot data from the live resolver decision store and never re-import over reviewed choices. | `media-resolver`, `core-importer`, `core-rest`, `admin-ui`, `wp-cli`, `classic-restore`, `settings`, `media-hydration`, `configuration-portability`, `transfer-media`, `bricks-addon`, `logging` |
| CPD-017 | P0 | Not started | Required resolver/media actions can fail after readiness passes, while apply still closes the proposal and reports success. | Make apply success and closure depend on both entity and media outcomes; retain draft state and decisions on failure. | `media-resolver`, `core-importer`, `core-rest`, `admin-ui`, `wp-cli`, `classic-restore`, `settings`, `media-hydration`, `configuration-portability`, `transfer-media`, `bricks-addon`, `official-collections`, `logging` |
| CPD-018 | P1 | Not started | Options, option groups, and menus can bypass field-decision review and trusted-snapshot gates. | Add domain-specific review and baseline gates, or explicitly block unsupported domains until those gates exist. | `core-importer`, `core-rest`, `admin-ui`, `wp-cli`, `options-groups`, `menus`, `configuration-portability`, `auto-export-hooks`, `official-collections`, `logging` |
| CPD-019 | P0 | Not started | ZIP path/type validation has no entry-count, expanded-size, per-entry-size, or compression-ratio ceilings before extraction. | Enforce filterable extraction budgets before `extractTo` and reject archives with unsafe or unknowable resource use. | `core-rest`, `wp-cli`, `logging`, `configuration-portability`, `transfer-media`, `bricks-addon`, connected-site transport, packaging QA |
| CPD-020 | P2 | Not started | Implemented Phase 0-4 behavior has no deterministic green regression target separate from expected Phase 5 failures. | Split resolved and pending contract tests, add a required green command, and isolate order-dependent test state. | PHPUnit bootstrap, CI/dev tooling, `core-rest`, `core-importer`, `admin-ui`, `wp-cli`, `media-hydration`, `configuration-portability`, `transfer-media`, `bricks-addon` |

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
- Change Summary counts match rendered rows.
- Large nested payloads do not freeze the drawer.
- UI can render additions/deletions/modifications without inferring types.

Dependent QA:
- `RawDiffView`.
- Bricks deeply nested payloads.
- Masking candidates and ignored paths.
- `dbvc_diff_ignore_paths` if retained as a configurable ignore list.

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

## P3 Implementation Plans

### CPD-013: Proposal And Entity List Scaling

Problem:
- Virtualization is documented as complete, but the current entity table appears to render all rows inside a scroll container.
- On the connected LocalWP dataset, `wp dbvc proposals list` took about 92 seconds for 11 proposals with roughly 1,238 media records because proposal summaries perform synchronous live resolver work before returning any rows.

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
- Remediation status: `Not started`

This checkpoint reviewed the implemented Phase 0 through Phase 4 code paths before beginning Phase 5.1. The audit found five evidence-backed follow-up items: three P0 issues, one P1 gap, and one P2 test-control gap. No production code was changed during this audit.

### Audit Findings

| ID | Priority | Status | Verified finding | Evidence and failure mode | Flagged dependents |
| --- | --- | --- | --- | --- | --- |
| CPD-016 | P0 | Not started | Manifest resolver data can overwrite newer live reviewer decisions. | `DBVC_Admin_App::import_proposal_from_zip()` imports manifest resolver choices, and `DBVC_Sync_Posts::import_backup()` imports them again immediately before apply. `DBVC_Sync_Posts::import_resolver_decisions_from_manifest()` replaces the proposal map and writes bundled global entries into `__global`; choices later saved through `DBVC_Admin_App::set_resolver_decision()` are not written back to the manifest. A saved proposal or site-wide choice can therefore be silently replaced by an older archive snapshot at apply time. | Proposal Review, REST apply, admin UI, WP-CLI, classic admin restore, resolver CSV/settings, future proposals using global rules, Media Hydration, Configuration Portability, transfer/Bricks media packages, logs and metrics. |
| CPD-017 | P0 | Not started | Resolver/media failures can pass readiness and still end as a closed, successful apply. | `resolver_decision_is_actionable()` treats `download` and `skip` as actionable without proving that a required download can complete. `DBVC_Sync_Posts::import_backup()` catches reconciliation failures without promoting them into the importer error list, while `DBVC_Admin_App::apply_proposal()` derives closure from remaining entity decisions and does not make closure or the success response depend on failed resolver outcomes. A forced download with neither a bundled file nor an allowed reachable source was reproduced as `ready=true`, followed by `download_not_completed`. | Proposal Review, REST response contract, success/error notices, WP-CLI exit code, classic admin restore, activity logging, Media Hydration, Configuration Portability, transfer/Bricks packages, Official Collections promotion flows. |
| CPD-019 | P0 | Not started | Proposal ZIP validation does not bound decompression work. | `validate_proposal_zip()` validates paths, duplicates, link/special types, encryption, extensions, layout, and required files, but it does not cap archive entries, per-entry expanded bytes, total expanded bytes, or compression ratio before `ZipArchive::extractTo()`. A small compressed upload can therefore consume excessive disk or processing resources after passing the current boundary checks. | REST upload, WP-CLI upload, temp storage and cleanup, logging, package producers, Configuration Portability, transfer/Bricks packages, connected-site transport observation. |
| CPD-018 | P1 | Not started | Non-post proposal domains bypass implemented review and snapshot protections. | `summarize_field_decision_apply_readiness()` only evaluates post and term items, while `get_entity_snapshot_status()` marks all other item types as `not_required` and trusted. An options item was reproduced as `ready=true` with zero field decisions, even though options, option groups, and menus are written directly by importer paths. FSE entities are post-backed and are not included in this finding. | Options and option groups, menu import, Configuration Portability, live auto-export hooks, proposal counters and labels, WP-CLI, rollback expectations, Official Collections. |
| CPD-020 | P2 | Not started | There is no green cumulative regression lane for the phases already marked implemented. | `composer test:proposal-diff` intentionally includes four still-failing Phase 5 contracts, so it cannot act as a required green gate for Phase 0-4. No checkout CI workflow supplies a separate gate, and a previously observed Content Migration media rollback failure is order-dependent: its complete class passes alone and after the Phase 4 resolver tests. | PHPUnit bootstrap, local/CI commands, Phase 0 completion evidence, core Proposal Diff tests, Content Migration test isolation, dependent add-on regression checks. |

### Resolution Order

#### 4A.1 - Preserve Live Resolver Authority (`CPD-016`, P0)

1. Treat manifest resolver content as an import-time seed or historical snapshot, not the live decision authority.
2. Seed proposal choices only when no live proposal choice exists; do not overwrite `__global` without a separate, explicit global-rule import action.
3. Remove or guard the second manifest import in `import_backup()`, then take one immutable effective-decision snapshot after readiness passes.
4. Add tests where older manifest proposal/global choices conflict with newer local choices, including classic restore and global-rule reuse by another proposal.

Acceptance checkpoint: the exact proposal and global choices shown immediately before apply are the choices consumed by reconciliation and media sync.

#### 4A.2 - Make Apply Outcomes Truthful (`CPD-017`, P0)

1. Define one post-apply result contract covering entity errors, reconciliation failures, resolver failures, and remaining required decisions.
2. Move automatic decision clearing and the `closed` transition after successful entity and media completion.
3. Keep the proposal in draft and preserve its decisions when a required media action fails; return a structured non-success from REST and a non-zero WP-CLI result.
4. Add tests for an unavailable forced download, bundle hash/import failure, reconciliation exception, and mixed entity-success/media-failure execution.

Acceptance checkpoint: REST, UI, WP-CLI, proposal status, logs, and outcome counters agree on success versus failure.

#### 4A.3 - Bound ZIP Extraction Resources (`CPD-019`, P0)

1. Add filterable limits for entry count, per-entry expanded bytes, total expanded bytes, and compression ratio before extraction.
2. Reject missing, negative, inconsistent, or otherwise unusable ZIP stat values where a safe budget cannot be established; account for directory entries separately.
3. Add boundary tests for too many entries, oversized entries, excessive total expansion, high ratios, and valid archives immediately below each limit.
4. Re-run REST and WP-CLI upload QA and observe package-producing add-ons for archives near the supported limits.

Acceptance checkpoint: no archive reaches `extractTo()` unless its complete extraction budget has been validated.

#### 4A.4 - Gate Non-Post Apply Domains (`CPD-018`, P1)

1. Inventory the actual apply units and current-state baselines for options, option groups, and menus.
2. Add domain-specific decisions, readiness counts, and trusted baseline states; if a domain cannot yet meet that contract, block it explicitly instead of treating it as reviewed.
3. Verify accepted, kept, declined, missing-baseline, apply, and rollback behavior through REST, UI, WP-CLI, and direct importer tests.
4. Re-test Configuration Portability, auto-export hooks, menu import, and any Official Collections path that promotes these domains.

Acceptance checkpoint: every writable proposal domain is either review-gated with a trusted baseline or visibly blocked as unsupported.

#### 4A.5 - Add a Green Resolved-Phase Lane (`CPD-020`, P2)

1. Add a `proposal-diff-resolved` test group/command for implemented Phase 0-4 contracts and keep pending Phase 5+ contracts in a separate lane.
2. Make the resolved command deterministic and suitable as a local and CI requirement.
3. Isolate global/static WordPress state that causes test-order failures, retaining isolated dependent suites as diagnostic commands.
4. Keep authenticated browser apply and direct live WP-CLI apply as explicit connected-site QA rather than silently treating unit coverage as equivalent.

Acceptance checkpoint: implemented Proposal Diff contracts have a repeatable green command, while planned failures remain visible in their own lane.

### Confirmed Stable Or Not Misclassified

- The complete Proposal Diff group ran 57 tests and 360 assertions. Its only failures were the four already-planned Phase 5 contracts for nested post-meta decisions, post-field masking, term masking, and declined-new-entity summaries; these are not new audit findings.
- The source checkout's non-Proposal-Diff suite ran 135 tests and 765 assertions without failures.
- The active LocalWP dependent matrix for Entity Editor, Media Hydration, Configuration Portability, transfer packets, Bricks Portability, and Bricks reference mapping ran 163 tests and 1,892 assertions without failures. No new regression was demonstrated in those dependents.
- The active LocalWP Content Migration Phase 4 importer class ran 32 tests and 568 assertions without failures. Its earlier full-suite-only media rollback failure remains a test-isolation concern under CPD-020, not a confirmed runtime regression from Phase 4.
- Classic restore uses the affected shared importer and is therefore flagged under CPD-016 and CPD-017, but its existing gate-boundary test still passes.
- `Dbvc\Official\Collections::mark_official()` has no caller or direct test in the current source checkout. Promotion QA remains unverified, but this audit did not invent or demonstrate a runtime failure there.
- Authenticated visual apply QA, direct live WP-CLI apply/recapture, and the already-recorded proposal-list latency remain open connected-site checks. They are not additional defects discovered by this audit.

Sequencing decision: complete the Phase 4A P0 items before starting Phase 5.1. Phase 5 remains planned, but advancing apply semantics while resolver authority, result truth, or ZIP resource controls are unresolved would make later QA unreliable.

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
| Phase 3 - Snapshot Truth | 3.3 Merge completed slices into LocalWP and run connected/dependent QA. | In review | CPD-001, CPD-003, CPD-004, CPD-005, CPD-013 | Active plugin, build, REST, WP-CLI, and dependent tests are verified; authenticated browser QA remains and proposal-list latency is tracked for Phase 7.2. | CDF-001, CDF-004, CDF-005, CDF-006, CDF-008, CDF-010, CDF-011, CDF-015, CDF-017, CDF-019, CDF-020 |
| Phase 4 - Media Resolution | 4.1 Preserve and formalize existing resolver decision bridge. | In review | CPD-002 | One normalized proposal/global map governs reconciliation and media sync; actual source and outcomes are returned without changing existing keys or filter signatures. | CDF-003, CDF-004, CDF-005, CDF-006, CDF-016, CDF-018, CDF-022, CDF-023 |
| Phase 4 - Media Resolution | 4.2 Verify all resolver actions during proposal apply. | In review | CPD-002, CPD-012 | Automated LocalWP coverage proves `reuse`, `map`, `download`, and `skip` produce consistent maps, creation behavior, metrics, and failures; authenticated visual apply QA remains. | CDF-003, CDF-004, CDF-005, CDF-014, CDF-015, CDF-016, CDF-018, CDF-022, CDF-023 |
| Phase 4A - Cumulative Audit Remediation | 4A.0 Audit implemented Phase 0-4 behavior and connected dependents. | Done | CPD-001 through CPD-005, CPD-016 through CPD-020 | Code-path tracing, focused runtime probes, cumulative tests, and LocalWP dependent suites identify only evidence-backed findings before Phase 5.1. | CDF-001, CDF-003, CDF-004, CDF-005, CDF-006, CDF-007, CDF-008, CDF-009, CDF-010, CDF-012, CDF-013, CDF-015, CDF-016, CDF-017, CDF-018, CDF-019, CDF-020, CDF-021, CDF-022, CDF-023 |
| Phase 4A - Cumulative Audit Remediation | 4A.1 Preserve live resolver authority over manifest snapshots. | Not started | CPD-016 | Upload and apply cannot replace newer proposal/global choices with archived manifest values. | CDF-003, CDF-004, CDF-005, CDF-006, CDF-016, CDF-018, CDF-022, CDF-023 |
| Phase 4A - Cumulative Audit Remediation | 4A.2 Make proposal closure and success depend on entity and media outcomes. | Not started | CPD-017 | Required media failures preserve draft/review state and produce matching REST, UI, CLI, and log results. | CDF-003, CDF-004, CDF-005, CDF-012, CDF-015, CDF-016, CDF-018, CDF-022, CDF-023 |
| Phase 4A - Cumulative Audit Remediation | 4A.3 Enforce ZIP extraction resource ceilings. | Not started | CPD-019 | Entry, expanded-size, and compression-ratio budgets are validated before extraction. | CDF-004, CDF-006, CDF-007, CDF-016, CDF-017, CDF-019, CDF-023 |
| Phase 4A - Cumulative Audit Remediation | 4A.4 Add or enforce review gates for non-post domains. | Not started | CPD-018 | Options, option groups, and menus cannot bypass trusted baseline and decision requirements. | CDF-006, CDF-007, CDF-012, CDF-013, CDF-016, CDF-018, CDF-020, CDF-021 |
| Phase 4A - Cumulative Audit Remediation | 4A.5 Add a deterministic green regression lane for resolved phases. | Not started | CPD-020 | Phase 0-4 contracts have a required green command separate from planned Phase 5+ failures. | CDF-001, CDF-003, CDF-004, CDF-005, CDF-008, CDF-009, CDF-010, CDF-017, CDF-018, CDF-020, CDF-022, CDF-023 |
| Phase 5 - Apply Semantics | 5.1 Align review decision paths with importer apply units. | Not started | CPD-006 | Review choices cannot imply nested behavior the importer cannot safely perform. | CDF-001, CDF-002, CDF-010, CDF-011, CDF-012, CDF-013, CDF-014, CDF-018, CDF-020, CDF-021 |
| Phase 5 - Apply Semantics | 5.2 Wire term masking overrides into term and term-meta import. | Not started | CPD-007 | Term masking decisions are honored during import and reflected in logs/counters. | CDF-002, CDF-014, CDF-016, CDF-018, CDF-020, CDF-021 |
| Phase 5 - Apply Semantics | 5.3 Unify duplicate detection across upload, list, UI gate, REST, CLI, and cleanup. | Not started | CPD-008 | Duplicate findings use one detector and one resolved/blocked definition. | CDF-003, CDF-004, CDF-006, CDF-011, CDF-013, CDF-020 |
| Phase 5 - Apply Semantics | 5.4 Treat declined new entities as resolved skip states. | Not started | CPD-009 | Declined new entities stop counting as pending and are skipped in apply/history/promotion. | CDF-004, CDF-010, CDF-012, CDF-013, CDF-016 |
| Phase 6 - Diff Review Depth | 6.1 Introduce classified diff payloads and stable change IDs. | Not started | CPD-010 | Diff responses include change type, path, section, limits, and stable IDs for review decisions. | CDF-006, CDF-009, CDF-011, CDF-014, CDF-015, CDF-018, CDF-019, CDF-020, CDF-021 |
| Phase 6 - Diff Review Depth | 6.2 Add first-class raw diff and mode-specific drawer views. | Not started | CPD-011 | Raw mode is a true before/after diff, not only a meta-focused `View All` panel. | CDF-009, CDF-015, CDF-020 |
| Phase 7 - UI Clarity | 7.1 Normalize status labels and counters. | Not started | CPD-012 | Media, meta, masking, duplicate, snapshot, and new-entity states are named consistently. | CDF-001, CDF-005, CDF-008, CDF-015, CDF-020 |
| Phase 7 - UI Clarity | 7.2 Address proposal and entity list scaling. | Not started | CPD-013 | Bound proposal summary/resolver work and add list filters/pagination, then implement real entity windowing or correct the docs. | CDF-001, CDF-004, CDF-005, CDF-009, CDF-015, CDF-018 |
| Phase 8 - Production Hygiene | 8.1 Hide or gate dev fixture upload in production review UI. | Not started | CPD-014 | Fixture tooling requires an explicit dev gate and is absent from normal production review. | CDF-007, CDF-015, CDF-020 |
| Phase 9 - Documentation Closeout | 9.1 Reconcile docs and in-plugin help against implemented behavior. | Not started | CPD-015 | Docs describe only verified behavior and include remaining limits/known operational caveats. | CDF-006, CDF-008, CDF-010, CDF-012, CDF-016, CDF-017, CDF-018, CDF-019, CDF-020, CDF-021 |

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
| Settings | `dbvc_import_require_review`, `dbvc_auto_clear_decisions`, `dbvc_force_reapply_new_posts`, media transport, masking presets, logging toggles. |
| Add-ons/future features | Bricks entity-backed artifacts, Bricks options-backed artifacts, Official Collections marking, user docs library. |

## Definition of Done For The Sprint

- All CPD P0 and P1 items are implemented and covered by automated tests.
- REST, UI, and WP-CLI share the same apply readiness contract.
- Existing proposals cannot falsely show clean diffs because of missing snapshots.
- Resolver and masking decisions affect actual importer behavior.
- Duplicate, new entity, media, masking, and snapshot blockers are visible and enforced.
- Imported manifest resolver snapshots cannot overwrite newer reviewed proposal or global choices.
- A proposal cannot close or report success while a required entity or media action has failed.
- Every writable proposal domain is review-gated with a trusted baseline or explicitly blocked as unsupported.
- Proposal ZIP extraction is bounded by validated entry and expanded-size budgets.
- Implemented phases have a deterministic green regression command separate from planned contract failures.
- Docs and in-plugin help text describe verified behavior only.
