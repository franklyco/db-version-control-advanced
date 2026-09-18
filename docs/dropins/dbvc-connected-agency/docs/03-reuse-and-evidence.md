# Reuse map and evidence boundaries

Source prefix for links: `https://github.com/franklyco/db-version-control-main/blob/977888535a306989e48c5a34104fb9d101298cf3/`.

Source presence is not live verification. Several manifest records remain `live_runtime_verified=false` with evidence pinned to an older commit. The tracker includes historical tests and shared-rule live drills; those do not prove the proposed platform. See `evidence/source-lock.json` and rerun only evidence affected by the selected slice.

| Existing asset | Observed evidence | Decision / owner | Verify before adoption |
|---|---|---|---|
| Core portable identity | `docs/reference/import-identity-matching.md`; `vf_object_uid`; fallback disabled by default | Reuse contract through DBVC identity adapter | UID collisions, registry/meta disagreement, target subtype, creation vs update |
| Core import/export | `includes/class-sync-posts.php`, `includes/class-sync-taxonomies.php`; capability facets | Adapt public services for later release payloads | Exact local APIs, partial-write shape, media and UID semantics |
| Proposal review | `proposal.core.intake`, `proposal.core.decisions`, `proposal.core.apply`; proposals/media facet | Reuse review concepts/services where a precise contract exists | An inspect command is structural, not authoritative readiness; audit side effects per callback |
| Core lifecycle hooks | `includes/hooks.php` | Adapt signals into a separate local dirty-object queue | Export-related gates can suppress hooks; missing create/delete paths and save timing |
| Bricks artifact hashes | `DBVC_Bricks_Artifacts::canonicalize()`, `fingerprint()` | Adapt after domain-specific comparison review | Recursive list sorting and broad volatile-key stripping can change meaningful content semantics |
| Bricks portability | `DBVC_Bricks_Portability_Apply_Service::apply_session()`, `rollback_backup()` | Preferred candidate for supported Bricks domain writes | Current storage verification, dependency coverage, stale-target checks and recovery |
| Older Bricks apply | `DBVC_Bricks_Apply::apply_entity_artifact()` uses payload `ID` | Do not expose directly to connected content updates | Resolve portable identity and consistent verification first |
| Protected variants | `bricks-protected-variants.php`, `build_payload_annotations()`, `build_mothership_fleet_visibility()` | Adapt override records and projection ideas | Current scope is not proven equivalent to per-object framework subscriptions; visibility helper calls registry-sync helpers |
| Packages | `bricks-packages.php`, remote publish and `rest_pull_latest()` | Adapt package transport/provenance | Pull latest runs dry run; package transport is not content application |
| Connected sites/onboarding | `bricks-connected-sites.php`, `bricks-onboarding.php` | Adapt identity/enrollment primitives | Clone handling, credential lifecycle, client membership and role restrictions |
| Command queue | `run_client_pull_tick()`, `execute_client_envelope()` | Adapt leases/retries/receipts behind narrow compatibility seam | Executor currently accepts only `shared_rules_apply`; new commands need explicit validators/handlers |
| Idempotency helper | `bricks-idempotency.php` | Reuse ideas, not a fleet transaction guarantee | Option array read-modify-write; 200 responses per scope; add payload binding, durable uniqueness and concurrency semantics |
| Visual Editor journal | `ChangeJournalRecorder::recordSuccess()`, `recordBatchItem()`, `finishBatch()` and store | Enrich change reports; keep addon optional | No generic completed-change emission found in inspected recorder; batch item completion is not whole-batch success |
| Backup/snapshot stores | `storage.core.snapshots` and relevant facet | Adapt exact recoverable services | A snapshot/ZIP is not automatically complete DB + uploads recovery |
| Capability manifest | `docs/agents/manifest.json` and maintenance workflow | Reuse discovery, ownership and evidence discipline | Per-record evidence can be older; do not mechanically promote status |
| CLI diagnostics | `wp dbvc capabilities ...`; `wp dbvc bricks doctor/drift` | Direct reuse for local discovery and diagnostics | Verify active checkout and supported flags; doctor does not authorize writers |
| Bricks Stats handoff | `docs/bricks-stats-portability-handoff/dbvc-adapter-checklist.md` (prior review) | Future adaptation for consumer/dependency graph | Checklist is proposed; no full scanner readiness claim |
| Content Migration / configuration providers | capability facets and provider tree | Discovery only for this pilot | Different jobs and authority; do not integrate merely because they exist |

## Exact hook implications

`dbvc_after_option_update($option, $old_value, $new_value)` is emitted after `export_options_to_json()`. In the inspected code it is skipped during `DBVC_PHPUNIT`, early runtime checks, configured skip patterns and a false `dbvc_should_export_on_option_update` result. A reporter solely listening here can miss changes when export is disabled or gated.

`dbvc_after_post_meta_update($meta_id, $object_id, $meta_key, $meta_value)` is emitted by the added/updated/deleted metadata handlers. Ignore keys are gated before emission; deleted-meta hook arguments need type normalization rather than assuming one scalar ID. Use these events as invalidation hints and reread persisted state after the save settles.

`dbvc_after_post_status_transition($new_status, $old_status, $post)` is available, but the trash branch can return early; deletion/trash needs its own path. `dbvc_after_post_deletion` is emitted from a pre-deletion handler and cannot prove deletion completed.

`dbvc_bricks_audit_event($event, $context)` can provide operation correlation. It is not a complete record of every edit made inside Bricks Builder.

The inspected Visual Editor recorder writes directly to its store. Do not subscribe to an invented `dbvc_ve_change_completed` hook. If needed, propose a new committed-mutation event at a reviewed service boundary, distinguish new hooks from existing ones, and update capability docs. A completed batch should emit after whole-batch success; a rolled-back batch must not generate a successful content release.

## Evidence-sensitive adoption

1. Enumerate current consumers before extracting a service.
2. Record public method/hook arguments, gates, side effects, scope and errors.
3. Add characterization coverage only where missing behavior matters to the slice.
4. Wrap and adapt the existing implementation, then retain old entrypoints for compatibility.
5. If a primitive fails the contract, repair it in its owner or leave the capability unsupported. Do not build a second hidden importer in the hub.

Useful source links: [hooks](https://github.com/franklyco/db-version-control-main/blob/977888535a306989e48c5a34104fb9d101298cf3/includes/hooks.php), [artifact canonicalization](https://github.com/franklyco/db-version-control-main/blob/977888535a306989e48c5a34104fb9d101298cf3/addons/bricks/bricks-artifacts.php), [protected variants](https://github.com/franklyco/db-version-control-main/blob/977888535a306989e48c5a34104fb9d101298cf3/addons/bricks/bricks-protected-variants.php), [identity](https://github.com/franklyco/db-version-control-main/blob/977888535a306989e48c5a34104fb9d101298cf3/docs/reference/import-identity-matching.md).
