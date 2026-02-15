# Bricks Add-on Progress Tracker

Date created: 2026-02-14  
Status model: `NOT_STARTED`, `IN_PROGRESS`, `BLOCKED`, `DONE`

## Global Rules

- Update this file immediately when any task/sub-task status changes.
- Include test evidence summaries before marking any task/phase `DONE`.
- If a required test fails, set impacted task/phase to `BLOCKED` with cause and next action.

## Phase 1 - Add-ons framework and Bricks activation gate
Status: DONE  
Owner: Codex  
Started: 2026-02-14  
Completed: 2026-02-14

### Tasks
- [x] P1-T1 Configure add-ons subtab scaffolding (Status: DONE)
  - [x] P1-T1-S1 (Status: DONE)
  - [x] P1-T1-S2 (Status: DONE)
  - [x] P1-T1-S3 (Status: DONE)
- [x] P1-T2 Add-on activation state persistence (Status: DONE)
  - [x] P1-T2-S1 (Status: DONE)
  - [x] P1-T2-S2 (Status: DONE)
  - [x] P1-T2-S3 (Status: DONE)
- [x] P1-T3 Conditional menu + bootstrap gating (Status: DONE)
  - [x] P1-T3-S1 (Status: DONE)
  - [x] P1-T3-S2 (Status: DONE)
  - [x] P1-T3-S3 (Status: DONE)
  - [x] P1-T3-S4 (Status: DONE)
- [x] P1-T4 Documentation + tracker updates (Status: DONE)
  - [x] P1-T4-S1 (Status: DONE)
  - [x] P1-T4-S2 (Status: DONE)

### Test Evidence
- P1-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase1Test.php` (`test_toggle_persistence_via_configure_addons_save`)
- P1-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase1Test.php` (`test_bricks_submenu_visibility_is_gated_by_enable_flag`)
- P1-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase1Test.php` (`test_bricks_endpoint_registration_is_gated_by_enable_flag`)
- P1-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase1Test.php` (`test_bricks_hook_and_job_registration_is_gated_by_enable_flag`)
- P1-TEST-05: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase1Test.php` (`test_bricks_admin_page_url_is_canonical_under_admin_php_page_param`)

### Exit Criteria Check
- [x] All required tests pass.
- [x] No Bricks add-on code path runs while disabled.

### Phase 1 Completion Note (2026-02-14)
- Implemented Configure -> Add-ons subtab with Bricks toggle and visibility mode controls in core configure flow.
- Added option persistence with allowlist sanitization and default bootstrap for `dbvc_addon_bricks_enabled` and `dbvc_addon_bricks_visibility`.
- Implemented Bricks add-on activation gate in `addons/bricks/bricks-addon.php`:
  - submenu registration only when enabled,
  - canonical Bricks admin URL under `admin.php?page=addon-dbvc-bricks-addon`,
  - legacy direct `/wp-admin/dbvc-bricks-addon` and `/wp-admin/addon-dbvc-bricks-addon` requests redirect to canonical submenu URL,
  - REST endpoint registration only when enabled,
  - scheduled hook registration only when enabled,
  - disabled state clears scheduled hook and avoids Bricks runtime hook registration.
- Test execution evidence:
  - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase1Test.php`
  - Result: `OK (5 tests, 15 assertions)`.

## Phase 2 - Configuration contract implementation
Status: DONE

### Tasks
- [x] P2-T1 Implement settings model by tab (Status: DONE)
  - [x] P2-T1-S1 Connection fields (Status: DONE)
  - [x] P2-T1-S2 Golden Source fields (Status: DONE)
  - [x] P2-T1-S3 Policies fields (Status: DONE)
  - [x] P2-T1-S4 Operations fields (Status: DONE)
  - [x] P2-T1-S5 Proposals fields (Status: DONE)
- [x] P2-T2 Validation + sanitization (Status: DONE)
  - [x] P2-T2-S1 Enum validators (Status: DONE)
  - [x] P2-T2-S2 Range validators (Status: DONE)
  - [x] P2-T2-S3 URL and secret validators (Status: DONE)
  - [x] P2-T2-S4 Conditional required-field validators (Status: DONE)
- [x] P2-T3 Defaults + migration (Status: DONE)
  - [x] P2-T3-S1 Seed missing defaults on first load (Status: DONE)
  - [x] P2-T3-S2 Add migration logic for option-key versioning (Status: DONE)
- [x] P2-T4 Settings access abstraction (Status: DONE)
  - [x] P2-T4-S1 Add read helper for all Bricks add-on options (Status: DONE)
  - [x] P2-T4-S2 Add typed getters for booleans/enums/ints (Status: DONE)
- [x] P2-T5 Documentation + tracker updates (Status: DONE)
  - [x] P2-T5-S1 Update progress tracker statuses (Status: DONE)
  - [x] P2-T5-S2 Record phase 2 completion note (Status: DONE)

### Test Evidence
- P2-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase2Test.php` (`test_field_validation_covers_enums_ranges_url_and_json_rules`)
- P2-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase2Test.php` (`test_defaults_are_seeded_for_all_bricks_settings`)
- P2-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase2Test.php` (`test_invalid_conditional_input_is_rejected_and_keeps_previous_values`)
- P2-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase2Test.php` (`test_typed_settings_getters_return_expected_types`)

### Exit Criteria Check
- [x] Every field in matrix has storage key.
- [x] Every field in matrix has validator.
- [x] Every field in matrix has default.
- [x] Every field in matrix has UI control.

### Phase 2 Completion Note (2026-02-14)
- Added Bricks add-on settings schema in `addons/bricks/bricks-addon.php` covering Connection, Golden Source, Policies, Operations, and Proposals fields from the matrix.
- Implemented centralized allowlist sanitization, enum/range validators, URL/key/secret handling, JSON-map validation, and conditional required-field validation.
- Added default seeding and settings version migration option (`dbvc_bricks_settings_version`) for missing options.
- Added settings access abstraction:
  - `get_all_settings`
  - `get_setting`
  - `get_bool_setting`
  - `get_int_setting`
  - `get_enum_setting`
- Updated `Configure -> Add-ons` in `admin/admin-page.php` to render grouped Bricks settings controls and persist through `DBVC_Bricks_Addon::save_settings`.
- Test execution evidence:
  - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase2Test.php`
  - Result: `OK (4 tests, 54 assertions)`.

## Phase 3 - Artifact registry + canonicalization + fingerprint
Status: DONE

### Tasks
- [x] P3-T1 Artifact registry (Status: DONE)
  - [x] P3-T1-S1 Register Entity artifact `bricks_template` (Status: DONE)
  - [x] P3-T1-S2 Register option artifacts from matrix (Status: DONE)
  - [x] P3-T1-S3 Add include/exclude policy mapping per artifact (Status: DONE)
- [x] P3-T2 Canonicalization (Status: DONE)
  - [x] P3-T2-S1 Entity canonicalization rules (Status: DONE)
  - [x] P3-T2-S2 Option canonicalization rules (Status: DONE)
  - [x] P3-T2-S3 Volatile/noisy field stripping rules (Status: DONE)
  - [x] P3-T2-S4 Stable sort for nested objects/arrays (Status: DONE)
- [x] P3-T3 Fingerprint engine (Status: DONE)
  - [x] P3-T3-S1 Implement `sha256:<hex>` formatter (Status: DONE)
  - [x] P3-T3-S2 Add hash mismatch diagnostics helper (Status: DONE)
- [x] P3-T4 Fixtures + schema validation (Status: DONE)
  - [x] P3-T4-S1 Build fixtures for each artifact type (Status: DONE)
  - [x] P3-T4-S2 Validate fixtures against canonical schema assumptions (Status: DONE)
- [x] P3-T5 Documentation + tracker updates (Status: DONE)
  - [x] P3-T5-S1 Update progress tracker statuses (Status: DONE)
  - [x] P3-T5-S2 Record phase 3 completion note (Status: DONE)

### Test Evidence
- P3-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase3Test.php` (`test_canonicalization_is_deterministic_for_entity_and_option_payloads`)
- P3-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase3Test.php` (`test_volatile_fields_are_stripped_from_entity_and_option_payloads`)
- P3-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase3Test.php` (`test_fixture_schema_validation_passes_for_each_artifact_type`)
- P3-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase3Test.php` (`test_fingerprint_format_and_collision_smoke`)

### Exit Criteria Check
- [x] Canonical + hash outputs stable and reproducible.

### Phase 3 Completion Note (2026-02-14)
- Added Bricks artifact registry and include/exclude mapping in `addons/bricks/bricks-artifacts.php`:
  - Entity artifact: `bricks_template`,
  - Option artifacts from matrix,
  - excluded keys list (`bricks_license_key`, `bricks_license_status`, `bricks_remote_templates`).
- Implemented canonicalization rules:
  - Entity volatile stripping (`post_date`, `post_date_gmt`, `post_modified`, `post_modified_gmt`, editor lock meta),
  - Option volatile stripping (`time`, `timestamp`, `updated_at`, `modified_at`, `generated_at`),
  - stable recursive sorting for nested objects/arrays,
  - script/css newline + trailing whitespace normalization.
- Implemented fingerprint and diagnostics:
  - `sha256:<hex>` formatter via `fingerprint(...)`,
  - `hash_diagnostics(...)` mismatch helper.
- Added fixtures and validation:
  - fixture source file: `addons/bricks/fixtures/bricks-artifact-fixtures.php`,
  - per-artifact fixture builder + schema validation helper.
- Added Phase 3 automated tests in `tests/phpunit/BricksAddonPhase3Test.php`.

## Phase 4 - Drift scan (read-only)
Status: DONE

### Tasks
- [x] P4-T1 Drift engine compare path (Status: DONE)
  - [x] P4-T1-S1 Resolve target package manifest (Status: DONE)
  - [x] P4-T1-S2 Compute local canonical/hash set (Status: DONE)
  - [x] P4-T1-S3 Compare and classify status (Status: DONE)
- [x] P4-T2 Diff summary contract (Status: DONE)
  - [x] P4-T2-S1 Build structured diff summaries (Status: DONE)
  - [x] P4-T2-S2 Add truncation metadata and raw-available flag (Status: DONE)
- [x] P4-T3 UI surface (Status: DONE)
  - [x] P4-T3-S1 Add aggregate counters by status (Status: DONE)
  - [x] P4-T3-S2 Add per-artifact drill-down view (Status: DONE)
- [x] P4-T4 Read-only enforcement (Status: DONE)
  - [x] P4-T4-S1 Verify no write code paths in scan endpoint (Status: DONE)
  - [x] P4-T4-S2 Add guard that rejects write attempts in scan mode (Status: DONE)
- [x] P4-T5 Documentation + tracker updates (Status: DONE)
  - [x] P4-T5-S1 Update progress tracker statuses (Status: DONE)
  - [x] P4-T5-S2 Record phase 4 completion note (Status: DONE)

### Test Evidence
- P4-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase4Test.php` (`test_status_classification_and_compare_path`)
- P4-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase4Test.php` (`test_diff_summary_truncation_and_raw_flag`)
- P4-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase4Test.php` (`test_read_only_guard_rejects_write_attempts`)
- P4-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase4Test.php` (`test_large_payload_scan_completes_and_returns_counts`)

### Exit Criteria Check
- [x] Drift scan accurate and non-mutating.

### Phase 4 Completion Note (2026-02-14)
- Added read-only drift scan engine in `addons/bricks/bricks-drift.php`:
  - target manifest resolution path,
  - local canonical/hash compare path,
  - status classification (`CLEAN|DIVERGED|OVERRIDDEN|PENDING_REVIEW`),
  - aggregate status counters and per-artifact drill-down list.
- Added structured diff summary contract with truncation metadata:
  - `total`, `changes`, `truncated`, `raw_available`.
- Enforced read-only behavior by rejecting write/mutate/apply flags with `400` `dbvc_bricks_read_only`.
- Registered Bricks endpoint `POST /dbvc/v1/bricks/drift-scan` (activation-gated).
- Added Phase 4 tests in `tests/phpunit/BricksAddonPhase4Test.php`.

## Phase 5 - Apply + restore safety
Status: DONE

### Tasks
- [x] P5-T1 Preflight + dry-run apply planner (Status: DONE)
  - [x] P5-T1-S1 Preflight validation checklist (Status: DONE)
  - [x] P5-T1-S2 Dry-run execution and report shape (Status: DONE)
- [x] P5-T2 Restore points (Status: DONE)
  - [x] P5-T2-S1 Create restore point before apply (Status: DONE)
  - [x] P5-T2-S2 Persist restore metadata and retention handling (Status: DONE)
- [x] P5-T3 Ordered apply pipeline (Status: DONE)
  - [x] P5-T3-S1 Apply option artifacts first (Status: DONE)
  - [x] P5-T3-S2 Apply Entity artifacts second (Status: DONE)
  - [x] P5-T3-S3 Apply post-processing and relation consistency checks (Status: DONE)
- [x] P5-T4 Verification + rollback (Status: DONE)
  - [x] P5-T4-S1 Post-apply hash verification pass (Status: DONE)
  - [x] P5-T4-S2 Trigger rollback on verification failure (Status: DONE)
  - [x] P5-T4-S3 Record rollback audit events (Status: DONE)
- [x] P5-T5 Policy and destructive gates (Status: DONE)
  - [x] P5-T5-S1 Enforce policy resolver decisions (Status: DONE)
  - [x] P5-T5-S2 Require explicit approval for destructive operations (Status: DONE)
- [x] P5-T6 Documentation + tracker updates (Status: DONE)
  - [x] P5-T6-S1 Update progress tracker statuses (Status: DONE)
  - [x] P5-T6-S2 Record phase 5 completion note (Status: DONE)

### Test Evidence
- P5-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase5Test.php` (`test_dry_run_plan_reports_ordered_apply_without_writes`)
- P5-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase5Test.php` (`test_restore_point_create_and_rollback_restores_option_state`)
- P5-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase5Test.php` (`test_policy_gate_ignore_skips_artifact_apply`)
- P5-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase5Test.php` (`test_destructive_gate_blocks_without_explicit_approval`)

### Exit Criteria Check
- [x] Apply supports dry-run, restore point creation, verification, and rollback path.
- [x] Policy and destructive safety gates enforced before writes.

### Phase 5 Completion Note (2026-02-14)
- Added apply/restore safety engine in `addons/bricks/bricks-apply.php`:
  - preflight + dry-run planner (`build_apply_plan`, `apply_package` with `dry_run`),
  - restore point create + retention (`create_restore_point`),
  - ordered apply pipeline (option artifacts first, Entity artifacts second),
  - post-apply verification via canonical hash checks,
  - rollback trigger on verification failure,
  - audit logging hook (`dbvc_bricks_audit_event`) + DB log integration.
- Added policy/destructive gates:
  - policy resolver using default + per-artifact overrides,
  - destructive block unless explicit approval.
- Registered Bricks endpoints:
  - `POST /dbvc/v1/bricks/apply`
  - `POST /dbvc/v1/bricks/restore-points`
  - `POST /dbvc/v1/bricks/restore-points/{restore_id}/rollback`
- Added Phase 5 tests in `tests/phpunit/BricksAddonPhase5Test.php`.

## Phase 6 - Proposal pipeline
Status: DONE

### Tasks
- [x] P6-T1 Proposal state machine (Status: DONE)
  - [x] P6-T1-S1 Implement statuses and allowed transitions (Status: DONE)
  - [x] P6-T1-S2 Transition validator and error paths (Status: DONE)
- [x] P6-T2 Queue + de-duplication (Status: DONE)
  - [x] P6-T2-S1 Persist proposal queue entries (Status: DONE)
  - [x] P6-T2-S2 De-duplicate by `(artifact_uid, base_hash, proposed_hash)` (Status: DONE)
- [x] P6-T3 REST proposal endpoints (Status: DONE)
  - [x] P6-T3-S1 Submit proposal endpoint (Status: DONE)
  - [x] P6-T3-S2 List proposal queue endpoint (Status: DONE)
  - [x] P6-T3-S3 Review decision endpoint (Status: DONE)
- [x] P6-T4 Audit + attribution (Status: DONE)
  - [x] P6-T4-S1 Record actor on every transition (Status: DONE)
  - [x] P6-T4-S2 Emit audit events on state changes (Status: DONE)
- [x] P6-T5 Documentation + tracker updates (Status: DONE)
  - [x] P6-T5-S1 Update progress tracker statuses (Status: DONE)
  - [x] P6-T5-S2 Record phase 6 completion note (Status: DONE)

### Test Evidence
- P6-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase6Test.php` (`test_status_transition_rules_allow_only_valid_paths`)
- P6-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase6Test.php` (`test_deduplication_by_artifact_uid_base_hash_and_proposed_hash`)
- P6-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase6Test.php` (`test_submission_list_and_review_endpoints_flow`)
- P6-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase6Test.php` (`test_audit_attribution_records_actor_on_transitions`)

### Exit Criteria Check
- [x] Proposal state transitions, queue de-duplication, review endpoints, and audit attribution implemented.

### Phase 6 Completion Note (2026-02-14)
- Added proposal pipeline service `addons/bricks/bricks-proposals.php`:
  - status machine (`DRAFT -> SUBMITTED -> RECEIVED -> APPROVED|REJECTED|NEEDS_CHANGES` + resubmission),
  - queue persistence in `dbvc_bricks_proposals_queue`,
  - de-duplication by `(artifact_uid, base_hash, proposed_hash)`,
  - actor-attributed transition history.
- Registered Bricks proposal routes:
  - `POST /dbvc/v1/bricks/proposals`
  - `GET /dbvc/v1/bricks/proposals`
  - `PATCH /dbvc/v1/bricks/proposals/{proposal_id}`
- Added transition audit event hook: `dbvc_bricks_proposal_transition`.
- Added Phase 6 tests in `tests/phpunit/BricksAddonPhase6Test.php`.

## Phase 7 - Hardening and release readiness
Status: DONE

### Tasks
- [x] P7-T1 Performance baseline checks (Status: DONE)
  - [x] P7-T1-S1 Baseline drift scan runtime on large payload set (Status: DONE)
  - [x] P7-T1-S2 Record baseline evidence (Status: DONE)
- [x] P7-T2 Multisite behavior validation (Status: DONE)
  - [x] P7-T2-S1 Validate option-key behavior against blog context (Status: DONE)
  - [x] P7-T2-S2 Confirm no hard-coded single-site table assumptions (Status: DONE)
- [x] P7-T3 Security + permissions hardening (Status: DONE)
  - [x] P7-T3-S1 Verify REST permission callbacks for Bricks endpoints (Status: DONE)
  - [x] P7-T3-S2 Validate restricted access for non-admin users (Status: DONE)
- [x] P7-T4 Disabled-mode regression hardening (Status: DONE)
  - [x] P7-T4-S1 Verify disabled mode suppresses submenu/routes/jobs (Status: DONE)
  - [x] P7-T4-S2 Add regression tests for disabled mode guarantees (Status: DONE)
- [x] P7-T5 Documentation + release notes updates (Status: DONE)
  - [x] P7-T5-S1 Update progress tracker statuses (Status: DONE)
  - [x] P7-T5-S2 Record phase 7 completion note (Status: DONE)

### Test Evidence
- P7-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase7Test.php` (`test_performance_baseline_for_large_drift_scan_payloads`)
- P7-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase7Test.php` (`test_multisite_option_behavior_uses_standard_option_api`)
- P7-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase7Test.php` (`test_security_permissions_block_non_admin_access_to_bricks_endpoints`)
- P7-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase7Test.php` (`test_disabled_mode_regression_suppresses_submenu_routes_and_jobs`)

### Exit Criteria Check
- [x] Hardening suite verifies performance baseline, permissions, and disabled-mode guarantees.

### Phase 7 Completion Note (2026-02-14)
- Added hardening/regression suite in `tests/phpunit/BricksAddonPhase7Test.php` covering:
  - large payload drift performance baseline,
  - multisite/single-site option behavior assumptions,
  - endpoint permission enforcement for non-admin users,
  - disabled-mode regression checks (submenu/routes/jobs not active).
- Maintained activation gate behavior while adding full Bricks REST surface and engines.
- Added missing package retrieval service + endpoints:
  - `GET /dbvc/v1/bricks/packages`
  - `GET /dbvc/v1/bricks/packages/{package_id}`
  implemented in `addons/bricks/bricks-packages.php` with tests in `tests/phpunit/BricksAddonPackagesTest.php`.
- Added idempotency-key support for mutating calls:
  - `POST /dbvc/v1/bricks/apply`
  - `POST /dbvc/v1/bricks/proposals`
  via `addons/bricks/bricks-idempotency.php`, with replay tests in `tests/phpunit/BricksAddonIdempotencyTest.php`.
- Hardened policy overrides validation:
  - `dbvc_bricks_policy_overrides` now enforces `artifact_uid => valid_policy_enum` map values.
  - Covered by `tests/phpunit/BricksAddonPhase2Test.php::test_policy_overrides_map_rejects_invalid_policy_values`.
- Bricks regression sweep: `vendor/bin/phpunit --filter BricksAddon tests/phpunit` -> `OK (56 tests, 266 assertions)`.

## Phase 8 - Bricks submenu UI foundation + role gating
Status: DONE
Owner: Codex
Started: 2026-02-14
Completed: 2026-02-14

### Tasks
- [x] P8-T1 Submenu admin page shell (Status: DONE)
  - [x] P8-T1-S1 Render Bricks admin page shell for `admin.php?page=addon-dbvc-bricks-addon` (Status: DONE)
  - [x] P8-T1-S2 Add notices/loading/error containers using existing DBVC admin patterns (Status: DONE)
  - [x] P8-T1-S3 Add tabbed IA shell (`Overview`, `Differences`, `Apply & Restore`, `Proposals`, `Packages`) (Status: DONE)
- [x] P8-T2 Role-aware page composition (Status: DONE)
  - [x] P8-T2-S1 Detect role mode from `dbvc_bricks_role` (`client|mothership`) (Status: DONE)
  - [x] P8-T2-S2 Show/hide tabs and actions based on role mode (Status: DONE)
  - [x] P8-T2-S3 Add read-only banner/disable actions when `dbvc_bricks_read_only=1` (Status: DONE)
- [x] P8-T3 Data wiring baseline (Status: DONE)
  - [x] P8-T3-S1 Wire `GET /dbvc/v1/bricks/status` into Overview (Status: DONE)
  - [x] P8-T3-S2 Add page-level refresh controls and last-updated state (Status: DONE)
  - [x] P8-T3-S3 Add route/state guards for disabled add-on mode (Status: DONE)
- [x] P8-T4 Documentation + tracker updates (Status: DONE)
  - [x] P8-T4-S1 Update progress tracker statuses (Status: DONE)
  - [x] P8-T4-S2 Record phase 8 completion note (Status: DONE)

### Test Evidence
- P8-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase8Test.php` (`test_submenu_page_render_and_capability_guard`)
- P8-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase8Test.php` (`test_role_based_tab_visibility_for_client_and_mothership`)
- P8-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase8Test.php` (`test_read_only_state_disables_mutating_controls`)
- P8-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase8Test.php` (`test_disabled_mode_blocks_submenu_runtime_actions`)

### Exit Criteria Check
- [x] Bricks submenu page is operational and role-aware.

### Phase 8 Completion Note (2026-02-14)
- Implemented Bricks submenu UI foundation in `addons/bricks/bricks-addon.php` with:
  - role-aware tab shell (`client` shows `Apply & Restore`, `mothership` shows `Packages`),
  - loading/success/error notice containers following DBVC admin notice patterns,
  - disabled-mode guard in `render_admin_page` that blocks submenu runtime actions when add-on is off,
  - read-only banner and disabled mutating controls when `dbvc_bricks_read_only=1`.
- Wired Overview status panel to `GET /dbvc/v1/bricks/status` via inline fetch with refresh button and last-updated timestamp.
- Expanded status endpoint payload (`role`, `read_only`, `visibility`, `timestamp_gmt`) to support submenu Overview diagnostics.
- Added Phase 8 tests in `tests/phpunit/BricksAddonPhase8Test.php`.
- Test execution evidence:
  - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase8Test.php`
  - `vendor/bin/phpunit --filter BricksAddon tests/phpunit`
  - Result: `OK (40 tests, 191 assertions)`.

## Phase 9 - Differences UX + simple diff viewer (Entity + option artifacts)
Status: DONE
Owner: Codex
Started: 2026-02-14
Completed: 2026-02-14

### Tasks
- [x] P9-T1 Differences panel controls (Status: DONE)
  - [x] P9-T1-S1 Add drift scan trigger + package selector controls (Status: DONE)
  - [x] P9-T1-S2 Add filters (`artifact class`, `status`, `search`) (Status: DONE)
  - [x] P9-T1-S3 Add counts summary cards (`CLEAN`, `DIVERGED`, `OVERRIDDEN`, `PENDING_REVIEW`) (Status: DONE)
- [x] P9-T2 Simple diff list and detail (Status: DONE)
  - [x] P9-T2-S1 Render artifact list with status chips and artifact metadata (Status: DONE)
  - [x] P9-T2-S2 Implement detail pane with `local` vs `golden` hash and changed paths (Status: DONE)
  - [x] P9-T2-S3 Add truncation/raw indicators based on diff summary metadata (Status: DONE)
- [x] P9-T3 Artifact-type UX distinctions (Status: DONE)
  - [x] P9-T3-S1 Label Template artifacts as `Entity` artifacts (Status: DONE)
  - [x] P9-T3-S2 Label option artifacts by option key/group (Status: DONE)
  - [x] P9-T3-S3 Add empty/unsupported-state messaging for missing artifact payloads (Status: DONE)
- [x] P9-T4 Documentation + tracker updates (Status: DONE)
  - [x] P9-T4-S1 Update progress tracker statuses (Status: DONE)
  - [x] P9-T4-S2 Record phase 9 completion note (Status: DONE)

### Test Evidence
- P9-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase9Test.php` (`test_drift_response_to_ui_mapping_contains_required_containers`)
- P9-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase9Test.php` (`test_filters_search_and_summary_controls_render`)
- P9-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase9Test.php` (`test_diff_detail_truncation_metadata_present_in_scan_response`)
- P9-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase9Test.php` (`test_entity_and_option_artifact_labels_are_distinguishable`)

### Exit Criteria Check
- [x] Users can review incoming differences for template Entity and option artifacts from submenu UI.

### Phase 9 Completion Note (2026-02-14)
- Implemented Differences panel UI in `addons/bricks/bricks-addon.php` with:
  - package selector + refresh + drift-scan trigger controls,
  - filter controls for artifact class, status, and search query,
  - summary counters for `CLEAN`, `DIVERGED`, `OVERRIDDEN`, and `PENDING_REVIEW`,
  - artifact list table and detail pane with hash/diff path rendering.
- Added detail rendering for truncation metadata (`truncated`, `raw_available`) from drift summary payload.
- Added artifact class labeling helper (`Entity` for `bricks_template`, otherwise `Option`) and wired it to UI rows/detail.
- Added automatic local artifact resolution during drift scan when `local_artifacts` is omitted:
  - options resolve via `get_option`,
  - template Entity payload resolves from `get_post` + `_bricks_page_content_2` meta.
- Added Phase 9 tests in `tests/phpunit/BricksAddonPhase9Test.php`.
- Test execution evidence:
  - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase9Test.php`
  - `vendor/bin/phpunit --filter BricksAddon tests/phpunit`
  - Result: `OK (44 tests, 208 assertions)`.

## Phase 10 - Role-specific action workflows (apply, proposals, packages)
Status: DONE
Owner: Codex
Started: 2026-02-14
Completed: 2026-02-14

### Tasks
- [x] P10-T1 Client workflows (Status: DONE)
  - [x] P10-T1-S1 Add dry-run/apply actions in submenu using `/dbvc/v1/bricks/apply` (Status: DONE)
  - [x] P10-T1-S2 Add restore-point creation + rollback controls (Status: DONE)
  - [x] P10-T1-S3 Add destructive-operation confirmation UX and policy-gate messaging (Status: DONE)
- [x] P10-T2 Proposal workflows (Status: DONE)
  - [x] P10-T2-S1 Add proposal submit UI from selected diff artifacts (Status: DONE)
  - [x] P10-T2-S2 Add proposal list/review actions with status transition controls (Status: DONE)
  - [x] P10-T2-S3 Add actor-attribution and transition history display (Status: DONE)
- [x] P10-T3 Mothership package workflows (Status: DONE)
  - [x] P10-T3-S1 Add package list UI with channel/version filters (Status: DONE)
  - [x] P10-T3-S2 Add package detail drill-down for artifact inspection (Status: DONE)
  - [x] P10-T3-S3 Add package-action guardrails for incompatible states (Status: DONE)
- [x] P10-T4 Documentation + tracker updates (Status: DONE)
  - [x] P10-T4-S1 Update progress tracker statuses (Status: DONE)
  - [x] P10-T4-S2 Record phase 10 completion note (Status: DONE)

### Test Evidence
- P10-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase10Test.php` (`test_client_apply_and_restore_controls_and_endpoint_flow`)
- P10-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase10Test.php` (`test_proposal_submit_review_controls_and_flow`)
- P10-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase10Test.php` (`test_mothership_package_list_detail_controls_and_flow`)
- P10-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase10Test.php` (`test_permission_and_read_only_guards_for_mutating_actions`)

### Exit Criteria Check
- [x] Role-specific actions are executable from submenu UI with policy-safe guardrails.

### Phase 10 Completion Note (2026-02-14)
- Implemented role-specific submenu action workflows in `addons/bricks/bricks-addon.php`:
  - client apply controls (`dry_run`, `allow_destructive`, selected artifact apply),
  - restore operations (create restore point from dry-run plan, rollback by restore ID),
  - proposal workflow (submit from selected diff artifact, list/filter proposals, approve/reject/needs_changes transitions),
  - mothership package workflow (channel filtering, package list, detail drill-down panel).
- Added operator guardrails:
  - destructive apply explicit confirmation prompt,
  - read-only disabled controls retained and endpoint guard behavior verified.
- Added Phase 10 tests in `tests/phpunit/BricksAddonPhase10Test.php`.
- Test execution evidence:
  - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase10Test.php`
  - `vendor/bin/phpunit --filter BricksAddon tests/phpunit`
  - Result: `OK (48 tests, 233 assertions)`.

## Phase 11 - UX hardening, observability, and operational readiness
Status: DONE
Owner: Codex
Started: 2026-02-14
Completed: 2026-02-14

### Tasks
- [x] P11-T1 UX resilience (Status: DONE)
  - [x] P11-T1-S1 Add robust loading/empty/error/retry states for every panel (Status: DONE)
  - [x] P11-T1-S2 Add consistent toasts/notices and progressive disclosure for destructive actions (Status: DONE)
  - [x] P11-T1-S3 Add keyboard focus management for tab and detail panes (Status: DONE)
- [x] P11-T2 Accessibility + internationalization (Status: DONE)
  - [x] P11-T2-S1 Add ARIA semantics and labels for tablist/panels/table regions (Status: DONE)
  - [x] P11-T2-S2 Ensure text strings are translation-ready (Status: DONE)
  - [x] P11-T2-S3 Validate color/status indicators are not color-only signals (Status: DONE)
- [x] P11-T3 Observability + audit depth (Status: DONE)
  - [x] P11-T3-S1 Add structured UI action telemetry hooks (scan/apply/proposal/package) (Status: DONE)
  - [x] P11-T3-S2 Add correlation IDs in UI requests surfaced in audit/log messages (Status: DONE)
  - [x] P11-T3-S3 Add operator-facing diagnostics panel for recent failures (Status: DONE)
- [x] P11-T4 Documentation + tracker updates (Status: DONE)
  - [x] P11-T4-S1 Update progress tracker statuses (Status: DONE)
  - [x] P11-T4-S2 Record phase 11 completion note (Status: DONE)

### Test Evidence
- P11-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase11Test.php` (`test_accessibility_smoke_for_tab_and_detail_markup`)
- P11-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase11Test.php` (`test_error_and_retry_controls_render_across_ui`)
- P11-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase11Test.php` (`test_i18n_strings_are_rendered_for_phase11_additions`)
- P11-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase11Test.php` (`test_observability_ui_event_endpoint_persists_and_emits_hook`)

### Exit Criteria Check
- [x] Submenu UI is operationally supportable, accessible, and diagnosable.

### Phase 11 Completion Note (2026-02-14)
- Hardened submenu UX states in `addons/bricks/bricks-addon.php`:
  - added retry affordance (`Retry Last Action`) in error notices,
  - added success notices for status/scan/apply/restore/proposal workflows,
  - added focus management when selecting diff/proposal/package detail rows.
- Added accessibility and semantics:
  - tablist/tab/tabpanel ARIA wiring for admin tabs,
  - table ARIA labels and keyboard-focusable detail panes (`tabindex="0"`),
  - non-color-only status presentation retained with explicit text labels.
- Added observability and diagnostics:
  - correlation ID headers on UI REST requests (`X-DBVC-Correlation-ID`),
  - UI event ingestion endpoint `POST /dbvc/v1/bricks/ui-event`,
  - diagnostics endpoint `GET /dbvc/v1/bricks/diagnostics`,
  - recent diagnostics panel in Overview and `dbvc_bricks_ui_event` hook emission.
- Added Phase 11 tests in `tests/phpunit/BricksAddonPhase11Test.php`.
- Test execution evidence:
  - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase11Test.php`
  - `vendor/bin/phpunit --filter BricksAddon tests/phpunit`
  - Result: `OK (52 tests, 252 assertions)`.

## Phase 12 - Extensibility + forward compatibility roadmap implementation
Status: DONE
Owner: Codex
Started: 2026-02-14
Completed: 2026-02-14

### Tasks
- [x] P12-T1 Plugin integration hooks (Status: DONE)
  - [x] P12-T1-S1 Add filter/action extension points for diff row rendering (Status: DONE)
  - [x] P12-T1-S2 Add extension points for additional artifact-type panels (Status: DONE)
  - [x] P12-T1-S3 Add extension points for custom governance/policy overlays (Status: DONE)
- [x] P12-T2 Schema/version compatibility (Status: DONE)
  - [x] P12-T2-S1 Add UI feature/version negotiation for future endpoint changes (Status: DONE)
  - [x] P12-T2-S2 Add backward-compatible parsing strategy for legacy payload variants (Status: DONE)
  - [x] P12-T2-S3 Add explicit deprecation notices/path for retired fields/actions (Status: DONE)
- [x] P12-T3 Future operations readiness (Status: DONE)
  - [x] P12-T3-S1 Add optional bulk operation mode with chunked execution UX (Status: DONE)
  - [x] P12-T3-S2 Add offline/exportable review artifact format for approvals (Status: DONE)
  - [x] P12-T3-S3 Add multisite fleet-mode planning hooks (future disabled by default) (Status: DONE)
- [x] P12-T4 Documentation + tracker updates (Status: DONE)
  - [x] P12-T4-S1 Update progress tracker statuses (Status: DONE)
  - [x] P12-T4-S2 Record phase 12 completion note (Status: DONE)

### Test Evidence
- P12-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase12Test.php` (`test_extension_hook_contracts_for_diff_tabs_and_panels`)
- P12-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase12Test.php` (`test_manifest_compatibility_normalization_for_legacy_payloads`)
- P12-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase12Test.php` (`test_bulk_chunk_builder_is_deterministic_and_deduplicated`)
- P12-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase12Test.php` (`test_deprecation_warning_and_contract_endpoint_available`)
- P12-TEST-05: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase12Test.php` (`test_diagnostics_limit_is_bounded_and_returns_latest_items`)
- P12-TEST-06: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase12Test.php` (`test_ui_event_sanitizes_payload_and_normalizes_unknown_event_type`)

### Exit Criteria Check
- [x] Bricks submenu implementation supports safe evolution without breaking current operators.

### Phase 12 Completion Note (2026-02-14)
- Implemented extension contracts and forward-compatible hooks:
  - `dbvc_bricks_diff_row_data` filter in drift rows,
  - `dbvc_bricks_admin_tabs` filter for custom tab panels,
  - `dbvc_bricks_render_extra_panels` action hook,
  - `dbvc_bricks_governance_overlay` filter for policy overlays.
- Implemented compatibility/deprecation contract:
  - manifest normalization helper `normalize_manifest_payload` supporting legacy wrappers/items,
  - UI contract endpoint `GET /dbvc/v1/bricks/ui-contract`,
  - deprecation notices surfaced in status payload and admin page notice area.
- Implemented future operations scaffolding:
  - optional bulk chunked apply mode in submenu UI,
  - offline review export (`Export Review JSON`) from current scan context,
  - multisite fleet planning hook gate in scheduled job using `dbvc_bricks_fleet_mode_enabled`.
- Added diagnostics/eventing enhancements:
  - `POST /dbvc/v1/bricks/ui-event`,
  - `GET /dbvc/v1/bricks/diagnostics`.
- Added Phase 12 tests in `tests/phpunit/BricksAddonPhase12Test.php`.
- Test execution evidence:
  - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase12Test.php`
  - `vendor/bin/phpunit --filter BricksAddon tests/phpunit`
  - Result: `OK (58 tests, 282 assertions)`.

## Phase 15 - Staging validation + release go/no-go execution
Status: IN_PROGRESS
Owner: Codex
Started: 2026-02-14
Completed: n/a

### Tasks
- [ ] P15-T1 Staging workflow drill execution (Status: NOT_STARTED)
  - [ ] P15-T1-S1 Lock validation dataset + package manifest fixtures used for staging drills (Status: NOT_STARTED)
  - [ ] P15-T1-S2 Execute apply/restore/rollback drill and capture timestamps + operator IDs (Status: NOT_STARTED)
  - [ ] P15-T1-S3 Execute proposal submit/review/transition drill and capture full audit trail (Status: NOT_STARTED)
- [ ] P15-T2 Security and contract validation closure (Status: NOT_STARTED)
  - [ ] P15-T2-S1 Verify idempotency behavior for all mutating Bricks endpoints under retry/replay (Status: NOT_STARTED)
  - [ ] P15-T2-S2 Verify capability and nonce protections for Bricks submenu/admin-post and REST calls (Status: NOT_STARTED)
  - [ ] P15-T2-S3 Validate compatibility with older DBVC manifest/snapshot payload variants in staging (Status: NOT_STARTED)
- [ ] P15-T3 Live Bricks schema verification (Status: NOT_STARTED)
  - [ ] P15-T3-S1 Validate `bricks_theme_styles` payload shape against canonicalization assumptions (Status: NOT_STARTED)
  - [ ] P15-T3-S2 Validate component label/slug path stability for drift/proposal/apply flows (Status: NOT_STARTED)
  - [ ] P15-T3-S3 Document schema deltas and required migration/backfill notes (Status: NOT_STARTED)
- [ ] P15-T4 Go/no-go decision package (Status: IN_PROGRESS)
  - [ ] P15-T4-S1 Update progress tracker statuses and attach command/output evidence (Status: NOT_STARTED)
  - [ ] P15-T4-S2 Produce release decision summary (`GO` or `NO_GO`) with explicit blocker list (Status: NOT_STARTED)
  - [ ] P15-T4-S3 Open follow-on phases/tasks for non-blocking enhancements discovered in validation (Status: IN_PROGRESS)

### Test Evidence
- P15-TEST-01: NOT_RUN - staging apply + restore + rollback drill evidence pending.
- P15-TEST-02: NOT_RUN - staging proposal workflow drill evidence pending.
- P15-TEST-03: NOT_RUN - idempotency replay verification pending.
- P15-TEST-04: NOT_RUN - capability + nonce enforcement verification pending.
- P15-TEST-05: NOT_RUN - legacy manifest/snapshot compatibility verification pending.
- P15-TEST-06: NOT_RUN - live `bricks_theme_styles` + component slug/label schema verification pending.
- P15-ENH-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase8Test.php` (`test_role_based_tab_visibility_for_client_and_mothership`) verifies Documentation panel renders for both `client` and `mothership` roles.
- P15-ENH-02: PASS - `vendor/bin/phpunit --filter BricksAddon tests/phpunit` -> `OK (58 tests, 282 assertions)`.
- P15-ENH-03: PASS - Added first-time guided checklist panel at top of Bricks submenu page (`id="dbvc-bricks-onboarding"`) with toggleable UI, persisted checkbox progress, and role-specific step guidance.
- P15-ENH-04: PASS - Added operator input instructions for `dbvc_addon_bricks_visibility` and `dbvc_bricks_mothership_url` in planning/checklist docs:
  - `addons/bricks/docs/BRICKS_ADDON_FIELD_MATRIX.md`
  - `addons/bricks/docs/BRICKS_ADDON_PLAN.md`
  - `addons/bricks/docs/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST.md`
- P15-ENH-05: PASS - Added help descriptions for all Bricks Configure settings via `DBVC_Bricks_Addon::get_field_help_texts()` and rendered beneath each input in Configure -> Add-ons (includes explicit `wp_app_password` instructions for `Auth Method`, `API Key ID`, and `API Secret`).
- P15-ENH-06: PASS - Drafted detailed true push/pull roadmap with connected-sites selective targeting in:
  - `addons/bricks/docs/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST.md` (Phase 13 + Phase 14)
  - `addons/bricks/docs/BRICKS_ADDON_PROGRESS_TRACKER.md` (Phase 13 + Phase 14 status scaffolding)
  - `addons/bricks/docs/BRICKS_ADDON_PLAN.md` (push/pull endpoints + connected-sites table contract)
  - `addons/bricks/docs/BRICKS_ADDON_FIELD_MATRIX.md` (target-mode and connected-sites field contract)

### Exit Criteria Check
- [ ] All required tests pass with evidence captured in tracker.
- [ ] Final go/no-go gate conditions satisfied.
- [ ] Any unresolved blocker explicitly tracked with `BLOCKED` status.

## Phase 13 - True push/pull transport foundation (client publish + mothership selective pull distribution)
Status: IN_PROGRESS
Owner: Codex
Started: 2026-02-14
Completed: n/a

### Tasks
- [x] P13-T1 Package contract + lifecycle (Status: DONE)
  - [x] P13-T1-S1 Define immutable package schema and versioning fields (Status: DONE)
  - [x] P13-T1-S2 Define package status machine (`DRAFT|PUBLISHED|SUPERSEDED|REVOKED`) (Status: DONE)
  - [x] P13-T1-S3 Define compatibility strategy (`schema_version`, deprecation path) (Status: DONE)
- [x] P13-T2 Mothership write endpoints (Status: DONE)
  - [x] P13-T2-S1 Add `POST /dbvc/v1/bricks/packages` publish endpoint (Status: DONE)
  - [x] P13-T2-S2 Add promote endpoint for channel advancement (Status: DONE)
  - [x] P13-T2-S3 Add revoke endpoint for emergency stop (Status: DONE)
  - [x] P13-T2-S4 Enforce idempotency keys on all mutating package endpoints (Status: DONE)
- [x] P13-T3 Client publish pipeline (Status: DONE)
  - [x] P13-T3-S1 Build local package payload from selected artifacts (Status: DONE)
  - [x] P13-T3-S2 Add publish preflight validation + dry-run (Status: DONE)
  - [x] P13-T3-S3 Submit package with correlation/actor/site attribution (Status: DONE)
  - [x] P13-T3-S4 Persist publish receipt and remote package mapping (Status: DONE)
- [x] P13-T4 Connected sites registry + selectable rollout controls (Status: DONE)
  - [x] P13-T4-S1 Add connected-site registry model and storage (Status: DONE)
  - [x] P13-T4-S2 Add mothership connected-sites table UI (filter/search/sort) (Status: DONE)
  - [x] P13-T4-S3 Add target mode controls (`all` vs `selected`) + row selection (Status: DONE)
  - [x] P13-T4-S4 Persist package target metadata (`target_mode`, `target_sites[]`) (Status: DONE)
  - [x] P13-T4-S5 Enforce server-side allowlist targeting rules (Status: DONE)
- [x] P13-T5 Pull contract + acknowledgements (Status: DONE)
  - [x] P13-T5-S1 Add target-aware package visibility rules for client pulls (Status: DONE)
  - [x] P13-T5-S2 Add pull acknowledgement endpoint and state model (Status: DONE)
  - [x] P13-T5-S3 Surface ack/delivery states in mothership diagnostics (Status: DONE)
- [ ] P13-T6 Documentation + tracker updates (Status: IN_PROGRESS)
  - [x] P13-T6-S1 Update progress tracker statuses (Status: DONE)
  - [ ] P13-T6-S2 Record phase 13 completion note with evidence (Status: NOT_STARTED)

### Test Evidence
- P13-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase13Test.php` (`test_package_publish_requires_idempotency_key`)
- P13-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase13Test.php` (`test_package_publish_idempotency_and_site_filtered_visibility`)
- P13-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase13Test.php` (`test_connected_site_upsert_and_list_routes`)
- P13-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase13Test.php` (`test_selected_targeting_requires_allowed_connected_sites`)
- P13-TEST-05: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase13Test.php` (`test_diagnostics_surface_package_delivery_ack_summary`, `test_package_publish_idempotency_and_site_filtered_visibility`)
- P13-TEST-06: PASS (SIMULATED) - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase13Test.php` (`test_remote_publish_uses_basic_auth_and_returns_remote_response`, `test_remote_publish_preflight_dry_run_returns_without_http_call`)
- P13-TEST-07: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase13Test.php` (`test_remote_connection_test_endpoint_uses_basic_auth_probe`, `test_mothership_packages_panel_renders_connected_sites_controls`)

### Exit Criteria Check
- [ ] Client can publish packages to mothership with auditable receipts.
- [ ] Mothership can choose `all` or `selected` connected sites for package availability.
- [ ] Pull visibility + acknowledgements respect targeting and policy contract.

## Phase 14 - Push/pull operations + governance hardening
Status: NOT_STARTED
Owner: Codex
Started: n/a
Completed: n/a

### Tasks
- [ ] P14-T1 Mothership publish operations UI (Status: NOT_STARTED)
  - [ ] P14-T1-S1 Add incoming package review queue + diff inspection (Status: NOT_STARTED)
  - [ ] P14-T1-S2 Add approve/promote/revoke action controls with guardrails (Status: NOT_STARTED)
  - [ ] P14-T1-S3 Add channel progression workflow (`canary -> beta -> stable`) (Status: NOT_STARTED)
- [ ] P14-T2 Client pull/apply UX (Status: NOT_STARTED)
  - [ ] P14-T2-S1 Show target audience metadata in client package view (Status: NOT_STARTED)
  - [ ] P14-T2-S2 Add pull latest allowed package + dry-run apply action (Status: NOT_STARTED)
  - [ ] P14-T2-S3 Link apply/restore/rollback events to publish receipt IDs (Status: NOT_STARTED)
- [ ] P14-T3 Reliability + failure handling (Status: NOT_STARTED)
  - [ ] P14-T3-S1 Add retry/backoff/dead-letter markers for push/pull failures (Status: NOT_STARTED)
  - [ ] P14-T3-S2 Add operator diagnostics and remediation hints (Status: NOT_STARTED)
  - [ ] P14-T3-S3 Add delivery timeline states (`sent|received|eligible|pulled|applied|failed`) (Status: NOT_STARTED)
- [ ] P14-T4 Security/governance hardening (Status: NOT_STARTED)
  - [ ] P14-T4-S1 Enforce least-privilege integration accounts per connected site (Status: NOT_STARTED)
  - [ ] P14-T4-S2 Add credential rotation and expiration warnings (Status: NOT_STARTED)
  - [ ] P14-T4-S3 Enforce stable-channel promotion approval gate (Status: NOT_STARTED)
- [ ] P14-T5 Documentation + tracker updates (Status: NOT_STARTED)
  - [ ] P14-T5-S1 Update progress tracker statuses (Status: NOT_STARTED)
  - [ ] P14-T5-S2 Record phase 14 completion note with evidence (Status: NOT_STARTED)

### Test Evidence
- P14-TEST-01: NOT_RUN - end-to-end publish/pull/apply drill across multiple connected sites pending.
- P14-TEST-02: NOT_RUN - channel promote/revoke governance gate tests pending.
- P14-TEST-03: NOT_RUN - push/pull retry/dead-letter recovery tests pending.
- P14-TEST-04: NOT_RUN - delivery timeline and audit attribution tests pending.
- P14-TEST-05: NOT_RUN - key rotation and expired credential behavior tests pending.

### Exit Criteria Check
- [ ] End-to-end push/pull workflow is operational with selective site targeting.
- [ ] Governance, security, and reliability controls meet release requirements.
