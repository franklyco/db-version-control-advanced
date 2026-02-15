# Bricks Add-on Strict Implementation Checklist

Date: 2026-02-14  
Status: Execution checklist (implementation planning)

## 0) Operating Rules

- No phase starts without all entry criteria complete.
- No phase is marked complete without all exit criteria + required tests passing.
- Any schema or behavior deviation discovered during implementation must be documented before proceeding.
- Use "Entity" terminology for post/term objects.
- Every completed task must update progress artifacts in section 2.1.

## 0.1 Mandatory Progress Tracking Rules

- Use these status values only:
  - `NOT_STARTED`, `IN_PROGRESS`, `BLOCKED`, `DONE`.
- Track progress at three levels:
  - phase,
  - task,
  - sub-task.
- Update progress immediately when any sub-task changes status.
- A task can be `DONE` only when all of its sub-tasks are `DONE`.
- A phase can be `DONE` only when:
  - all tasks are `DONE`,
  - required tests passed,
  - exit criteria met,
  - phase completion note written.
- If any required test fails:
  - set affected task/phase to `BLOCKED`,
  - log failure summary and next action.

## 0.2 Required Progress Artifacts

- Primary tracker file (must be updated throughout implementation):
  - `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/addons/bricks/docs/BRICKS_ADDON_PROGRESS_TRACKER.md`
- Phase completion notes:
  - append to `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/addons/bricks/docs/BRICKS_ADDON_PROGRESS_TRACKER.md`
- Test evidence references:
  - command/log summary links in phase notes (file paths or concise output summary).

## 1) Architecture Lock

### 1.1 Required activation model
- Add-ons are controlled under core Configure tab:
  - `Configure -> Add-ons` subtab.
- Each add-on has an enable/activate toggle.
- If Bricks add-on is disabled:
  - Bricks-specific submenu is not registered in wp-admin.
  - Bricks REST endpoints are not registered.
  - Bricks background jobs are not scheduled.
- If Bricks add-on is enabled:
  - register Bricks submenu under DBVC top-level menu (`dbvc-export`) via `add_submenu_page`.
  - load Bricks UI and actions from that submenu only.

### 1.2 Current DBVC files to extend
- Menu registration:  
  `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-menu.php`
- Configure tabs/save flow:  
  `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/admin-page.php`
- REST route pattern:  
  `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/admin/class-admin-app.php`

## 2) Phase Checklist (Strict WBS)

## 2.1 Progress Tracker Template (copy into tracker file)

```md
## Phase X - <name>
Status: NOT_STARTED|IN_PROGRESS|BLOCKED|DONE
Owner: <name>
Started: <date>
Completed: <date or n/a>

### Tasks
- [ ] P13-T1 <task name> (Status: ...)
  - [ ] P13-T1-S1 <sub-task> (Status: ...)
  - [ ] P13-T1-S2 <sub-task> (Status: ...)
- [ ] P13-T2 <task name> (Status: ...)

### Test Evidence
- <test id>: PASS|FAIL - <summary>

### Exit Criteria Check
- [ ] Criterion 1
- [ ] Criterion 2
```

## Phase 1: Add-ons framework and Bricks activation gate

### Entry criteria
- Field matrix approved:
  `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main/addons/bricks/docs/BRICKS_ADDON_FIELD_MATRIX.md`
- Activation model approved (section 1 above).

### Tasks / Sub-tasks
- `P1-T1` Configure add-ons subtab scaffolding
  - `P1-T1-S1` Add `Configure -> Add-ons` subtab registration in core configure tab map.
  - `P1-T1-S2` Render add-ons panel with Bricks toggle control.
  - `P1-T1-S3` Add nonce and section save routing for add-ons panel.
- `P1-T2` Add-on activation state persistence
  - `P1-T2-S1` Add option key `dbvc_addon_bricks_enabled`.
  - `P1-T2-S2` Add allowlist-based sanitization for add-on settings.
  - `P1-T2-S3` Add default bootstrap for missing add-on options.
- `P1-T3` Conditional menu + bootstrap gating
  - `P1-T3-S1` Register Bricks submenu under `dbvc-export` only when enabled.
  - `P1-T3-S2` Gate Bricks route registration by enable flag.
  - `P1-T3-S3` Gate Bricks scheduled jobs/hooks by enable flag.
  - `P1-T3-S4` Verify disabled mode performs no Bricks writes or background actions.
- `P1-T4` Documentation + tracker updates
  - `P1-T4-S1` Update progress tracker statuses.
  - `P1-T4-S2` Record phase 1 completion note with evidence.

### Required tests
- `P1-TEST-01` Toggle persistence: save on/off and verify reads.
- `P1-TEST-02` Menu visibility: disabled absent, enabled present.
- `P1-TEST-03` Endpoint gating: disabled unavailable, enabled available.
- `P1-TEST-04` Hook/job gating: disabled path registers none.

### Exit criteria
- All required tests pass.
- No Bricks add-on code path runs while disabled.

## Phase 2: Configuration contract implementation

### Entry criteria
- Phase 1 complete.
- Full key list from field matrix locked.

### Tasks / Sub-tasks
- `P2-T1` Implement settings model by tab
  - `P2-T1-S1` Connection fields.
  - `P2-T1-S2` Golden Source fields.
  - `P2-T1-S3` Policies fields.
  - `P2-T1-S4` Operations fields.
  - `P2-T1-S5` Proposals fields.
  - `P2-T1-S6` Render field-level help text beneath each Bricks input in Configure -> Add-ons.
- `P2-T2` Validation + sanitization
  - `P2-T2-S1` Enum validators.
  - `P2-T2-S2` Range validators.
  - `P2-T2-S3` URL and secret validators.
  - `P2-T2-S4` Conditional required-field validators.
- `P2-T3` Defaults + migration
  - `P2-T3-S1` Seed missing defaults on first load.
  - `P2-T3-S2` Add migration logic for option-key versioning.
- `P2-T4` Settings access abstraction
  - `P2-T4-S1` Add read helper for all Bricks add-on options.
  - `P2-T4-S2` Add typed getters for booleans/enums/ints.
- `P2-T5` Documentation + tracker updates
  - `P2-T5-S1` Update progress tracker statuses.
  - `P2-T5-S2` Record phase 2 completion note.

### Required tests
- `P2-TEST-01` Field validation coverage (all constrained fields).
- `P2-TEST-02` Defaults load coverage.
- `P2-TEST-03` Invalid input rejection coverage.
- `P2-TEST-04` Settings read helper type coverage.

### Exit criteria
- Every field in matrix has:
  - storage key,
  - validator,
  - default,
  - UI control,
  - help text/usage instruction.

Phase 2 field help text requirements (must appear beneath inputs):
- `Add-on Visibility Mode` (`dbvc_addon_bricks_visibility`):
  - "`configure_and_submenu` (recommended): show Bricks settings in Configure and submenu when enabled."
  - "`submenu_only`: hide Bricks settings from Configure and use submenu only."
- `Mothership Base URL` (`dbvc_bricks_mothership_url`):
  - "Enter the mothership base origin only (no trailing slash, no `/wp-json`)."
  - "Example LocalWP URL: `https://dbvc-mothership.local`."
  - "Required when role is `client`; leave empty when role is `mothership`."

## Phase 3: Artifact registry + canonicalization + fingerprint

### Entry criteria
- Phase 2 complete.
- Artifact scope for MVP approved.

### Tasks / Sub-tasks
- `P3-T1` Artifact registry
  - `P3-T1-S1` Register Entity artifact `bricks_template`.
  - `P3-T1-S2` Register option artifacts from matrix.
  - `P3-T1-S3` Add include/exclude policy mapping per artifact.
- `P3-T2` Canonicalization
  - `P3-T2-S1` Entity canonicalization rules.
  - `P3-T2-S2` Option canonicalization rules.
  - `P3-T2-S3` Volatile/noisy field stripping rules.
  - `P3-T2-S4` Stable sort for nested objects/arrays.
- `P3-T3` Fingerprint engine
  - `P3-T3-S1` Implement `sha256:<hex>` formatter.
  - `P3-T3-S2` Add hash mismatch diagnostics helper.
- `P3-T4` Fixtures + schema validation
  - `P3-T4-S1` Build fixtures for each artifact type.
  - `P3-T4-S2` Validate fixtures against canonical schema assumptions.
- `P3-T5` Documentation + tracker updates
  - `P3-T5-S1` Update progress tracker statuses.
  - `P3-T5-S2` Record phase 3 completion note.

### Required tests
- `P3-TEST-01` Determinism tests.
- `P3-TEST-02` Volatile field stripping tests.
- `P3-TEST-03` Fixture schema tests by artifact.
- `P3-TEST-04` Hash format and collision smoke tests.

### Exit criteria
- Canonical + hash outputs stable and reproducible.

## Phase 4: Drift scan (read-only)

### Entry criteria
- Phase 3 complete.

### Tasks / Sub-tasks
- `P4-T1` Drift engine compare path
  - `P4-T1-S1` Resolve target package manifest.
  - `P4-T1-S2` Compute local canonical/hash set.
  - `P4-T1-S3` Compare and classify status.
- `P4-T2` Diff summary contract
  - `P4-T2-S1` Build structured diff summaries.
  - `P4-T2-S2` Add truncation metadata and raw-available flag.
- `P4-T3` UI surface
  - `P4-T3-S1` Add aggregate counters by status.
  - `P4-T3-S2` Add per-artifact drill-down view.
- `P4-T4` Read-only enforcement
  - `P4-T4-S1` Verify no write code paths in scan endpoint.
  - `P4-T4-S2` Add guard that rejects write attempts in scan mode.
- `P4-T5` Documentation + tracker updates
  - `P4-T5-S1` Update progress tracker statuses.
  - `P4-T5-S2` Record phase 4 completion note.

### Required tests
- `P4-TEST-01` Status classification tests.
- `P4-TEST-02` Truncation and raw fallback tests.
- `P4-TEST-03` Read-only guarantee tests.
- `P4-TEST-04` Large payload memory/use tests.

### Exit criteria
- Drift scan accurate and non-mutating.

## Phase 5: Apply + restore safety

### Entry criteria
- Phase 4 complete.
- Restore point strategy approved.

### Tasks / Sub-tasks
- `P5-T1` Preflight + dry-run apply planner
  - `P5-T1-S1` Preflight validation checklist.
  - `P5-T1-S2` Dry-run execution and report shape.
- `P5-T2` Restore points
  - `P5-T2-S1` Create restore point before apply.
  - `P5-T2-S2` Persist restore metadata and retention handling.
- `P5-T3` Ordered apply pipeline
  - `P5-T3-S1` Apply option artifacts first.
  - `P5-T3-S2` Apply Entity artifacts second.
  - `P5-T3-S3` Apply post-processing and relation consistency checks.
- `P5-T4` Verification + rollback
  - `P5-T4-S1` Post-apply hash verification pass.
  - `P5-T4-S2` Trigger rollback on verification failure.
  - `P5-T4-S3` Record rollback audit events.
- `P5-T5` Policy and destructive gates
  - `P5-T5-S1` Enforce policy resolver decisions.
  - `P5-T5-S2` Require explicit approval for destructive operations.
- `P5-T6` Documentation + tracker updates
  - `P5-T6-S1` Update progress tracker statuses.
  - `P5-T6-S2` Record phase 5 completion note.

### Required tests
- `P5-TEST-01` Apply success integration test.
- `P5-TEST-02` Mid-apply failure rollback test.
- `P5-TEST-03` Policy gate behavior tests.
- `P5-TEST-04` Destructive-change gate tests.

### Exit criteria
- Apply flow proven recoverable and auditable.

## Phase 6: Proposal pipeline

### Entry criteria
- Phase 5 complete.

### Tasks / Sub-tasks
- `P6-T1` Proposal data model + status machine
  - `P6-T1-S1` Implement statuses and allowed transitions.
  - `P6-T1-S2` Validate transition authorization and business rules.
- `P6-T2` Proposal endpoints
  - `P6-T2-S1` Submit endpoint.
  - `P6-T2-S2` List endpoint.
  - `P6-T2-S3` Status update endpoint.
- `P6-T3` Queue controls
  - `P6-T3-S1` De-duplication key enforcement.
  - `P6-T3-S2` Pagination/filter semantics.
- `P6-T4` Governance logging
  - `P6-T4-S1` Reviewer attribution.
  - `P6-T4-S2` Structured audit event emission.
- `P6-T5` Documentation + tracker updates
  - `P6-T5-S1` Update progress tracker statuses.
  - `P6-T5-S2` Record phase 6 completion note.

### Required tests
- `P6-TEST-01` Status transition tests.
- `P6-TEST-02` De-duplication tests.
- `P6-TEST-03` End-to-end proposal flow test.
- `P6-TEST-04` Audit attribution tests.

### Exit criteria
- Proposal governance works end-to-end with audit trail.

## Phase 7: Hardening and release readiness

### Entry criteria
- Phase 6 complete.

### Tasks / Sub-tasks
- `P7-T1` Performance hardening
  - `P7-T1-S1` Benchmark large payload compare/apply.
  - `P7-T1-S2` Tune limits/chunking.
- `P7-T2` Compatibility hardening
  - `P7-T2-S1` Add regression fixtures for Bricks schema variants.
  - `P7-T2-S2` Validate older DBVC snapshot/manifest compatibility.
- `P7-T3` Operational readiness
  - `P7-T3-S1` Write restore/rollback runbook.
  - `P7-T3-S2` Write proposal operations runbook.
- `P7-T4` Final QA gate
  - `P7-T4-S1` Complete QA matrix.
  - `P7-T4-S2` Sign-off checklist completion.
- `P7-T5` Documentation + tracker updates
  - `P7-T5-S1` Update progress tracker statuses.
  - `P7-T5-S2` Record phase 7 completion note.

### Required tests
- `P7-TEST-01` Performance baseline tests.
- `P7-TEST-02` Multisite option table behavior tests.
- `P7-TEST-03` Full manual E2E drill.
- `P7-TEST-04` Disabled-mode regression (no submenu/routes/jobs).

### Exit criteria
- Release checklist signed off.

## Phase 8: Bricks submenu UI foundation + role gating

### Entry criteria
- Phase 7 complete.
- Submenu slug and activation gate stable.

### Tasks / Sub-tasks
- `P8-T1` Submenu admin page shell
  - `P8-T1-S1` Render Bricks admin page shell for `admin.php?page=addon-dbvc-bricks-addon`.
  - `P8-T1-S2` Add notices/loading/error containers using existing DBVC admin patterns.
  - `P8-T1-S3` Add tabbed IA shell (`Overview`, `Differences`, `Apply & Restore`, `Proposals`, `Packages`).
- `P8-T2` Role-aware page composition
  - `P8-T2-S1` Detect role mode from `dbvc_bricks_role` (`client|mothership`).
  - `P8-T2-S2` Show/hide tabs and actions based on role mode.
  - `P8-T2-S3` Add read-only banner/disable actions when `dbvc_bricks_read_only=1`.
- `P8-T3` Data wiring baseline
  - `P8-T3-S1` Wire `GET /dbvc/v1/bricks/status` into Overview.
  - `P8-T3-S2` Add page-level refresh controls and last-updated state.
  - `P8-T3-S3` Add route/state guards for disabled add-on mode.
- `P8-T4` Documentation + tracker updates
  - `P8-T4-S1` Update progress tracker statuses.
  - `P8-T4-S2` Record phase 8 completion note.

### Required tests
- `P8-TEST-01` Submenu page render + capability guard test.
- `P8-TEST-02` Role-based tab visibility test (`client` vs `mothership`).
- `P8-TEST-03` Read-only state disables mutating controls.
- `P8-TEST-04` Disabled add-on mode blocks submenu runtime UI actions.

### Exit criteria
- Bricks submenu page is operational and role-aware.

## Phase 9: Differences UX + simple diff viewer (Entity + option artifacts)

### Entry criteria
- Phase 8 complete.
- Drift endpoint contract stable (`/dbvc/v1/bricks/drift-scan`).

### Tasks / Sub-tasks
- `P9-T1` Differences panel controls
  - `P9-T1-S1` Add drift scan trigger + package selector controls.
  - `P9-T1-S2` Add filters (`artifact class`, `status`, `search`).
  - `P9-T1-S3` Add counts summary cards (`CLEAN`, `DIVERGED`, `OVERRIDDEN`, `PENDING_REVIEW`).
- `P9-T2` Simple diff list and detail
  - `P9-T2-S1` Render artifact list with status chips and artifact metadata.
  - `P9-T2-S2` Implement detail pane with `local` vs `golden` hash and changed paths.
  - `P9-T2-S3` Add truncation/raw indicators based on diff summary metadata.
- `P9-T3` Artifact-type UX distinctions
  - `P9-T3-S1` Label Template artifacts as `Entity` artifacts.
  - `P9-T3-S2` Label option artifacts by option key/group.
  - `P9-T3-S3` Add empty/unsupported-state messaging for missing artifact payloads.
- `P9-T4` Documentation + tracker updates
  - `P9-T4-S1` Update progress tracker statuses.
  - `P9-T4-S2` Record phase 9 completion note.

### Required tests
- `P9-TEST-01` Drift response-to-UI mapping test coverage.
- `P9-TEST-02` Filters/search behavior test coverage.
- `P9-TEST-03` Diff detail pane render + truncation metadata test.
- `P9-TEST-04` Entity/option labeling and status chip consistency test.

### Exit criteria
- Users can review incoming differences for template Entity and option artifacts from submenu UI.

## Phase 10: Role-specific action workflows (apply, proposals, packages)

### Entry criteria
- Phase 9 complete.

### Tasks / Sub-tasks
- `P10-T1` Client workflows
  - `P10-T1-S1` Add dry-run/apply actions in submenu using `/dbvc/v1/bricks/apply`.
  - `P10-T1-S2` Add restore-point creation + rollback controls.
  - `P10-T1-S3` Add destructive-operation confirmation UX and policy-gate messaging.
- `P10-T2` Proposal workflows
  - `P10-T2-S1` Add proposal submit UI from selected diff artifacts.
  - `P10-T2-S2` Add proposal list/review actions with status transition controls.
  - `P10-T2-S3` Add actor-attribution and transition history display.
- `P10-T3` Mothership package workflows
  - `P10-T3-S1` Add package list UI with channel/version filters.
  - `P10-T3-S2` Add package detail drill-down for artifact inspection.
  - `P10-T3-S3` Add package-action guardrails for incompatible states.
- `P10-T4` Documentation + tracker updates
  - `P10-T4-S1` Update progress tracker statuses.
  - `P10-T4-S2` Record phase 10 completion note.

### Required tests
- `P10-TEST-01` Client apply/restore UI integration tests.
- `P10-TEST-02` Proposal submit/review UI integration tests.
- `P10-TEST-03` Mothership package list/detail UI integration tests.
- `P10-TEST-04` Permission and read-only guard regression tests for all UI-triggered mutations.

### Exit criteria
- Role-specific actions are executable from submenu UI with policy-safe guardrails.

## Phase 11: UX hardening, observability, and operational readiness

### Entry criteria
- Phase 10 complete.

### Tasks / Sub-tasks
- `P11-T1` UX resilience
  - `P11-T1-S1` Add robust loading/empty/error/retry states for every panel.
  - `P11-T1-S2` Add consistent toasts/notices and progressive disclosure for destructive actions.
  - `P11-T1-S3` Add keyboard focus management for tab and detail panes.
- `P11-T2` Accessibility + internationalization
  - `P11-T2-S1` Add ARIA semantics and labels for tablist/panels/table regions.
  - `P11-T2-S2` Ensure text strings are translation-ready.
  - `P11-T2-S3` Validate color/status indicators are not color-only signals.
- `P11-T3` Observability + audit depth
  - `P11-T3-S1` Add structured UI action telemetry hooks (scan/apply/proposal/package).
  - `P11-T3-S2` Add correlation IDs in UI requests surfaced in audit/log messages.
  - `P11-T3-S3` Add operator-facing diagnostics panel for recent failures.
- `P11-T4` Documentation + tracker updates
  - `P11-T4-S1` Update progress tracker statuses.
  - `P11-T4-S2` Record phase 11 completion note.

### Required tests
- `P11-TEST-01` Accessibility smoke tests for keyboard and ARIA wiring.
- `P11-TEST-02` Error/retry state tests across all tabs.
- `P11-TEST-03` i18n string extraction/coverage checks.
- `P11-TEST-04` Observability hook emission tests.

### Exit criteria
- Submenu UI is operationally supportable, accessible, and diagnosable.

## Phase 12: Extensibility + forward compatibility roadmap implementation

### Entry criteria
- Phase 11 complete.

### Tasks / Sub-tasks
- `P12-T1` Plugin integration hooks
  - `P12-T1-S1` Add filter/action extension points for diff row rendering.
  - `P12-T1-S2` Add extension points for additional artifact-type panels.
  - `P12-T1-S3` Add extension points for custom governance/policy overlays.
- `P12-T2` Schema/version compatibility
  - `P12-T2-S1` Add UI feature/version negotiation for future endpoint changes.
  - `P12-T2-S2` Add backward-compatible parsing strategy for legacy payload variants.
  - `P12-T2-S3` Add explicit deprecation notices/path for retired fields/actions.
- `P12-T3` Future operations readiness
  - `P12-T3-S1` Add optional bulk operation mode with chunked execution UX.
  - `P12-T3-S2` Add offline/exportable review artifact format for approvals.
  - `P12-T3-S3` Add multisite fleet-mode planning hooks (future disabled by default).
- `P12-T4` Documentation + tracker updates
  - `P12-T4-S1` Update progress tracker statuses.
  - `P12-T4-S2` Record phase 12 completion note.

### Required tests
- `P12-TEST-01` Extension hook contract tests.
- `P12-TEST-02` Backward/forward payload compatibility tests.
- `P12-TEST-03` Bulk mode chunk safety/idempotency tests.
- `P12-TEST-04` Deprecated path warning and fallback behavior tests.

### Exit criteria
- Bricks submenu implementation supports safe evolution without breaking current operators.

## Phase 15: Staging validation + release go/no-go execution

### Entry criteria
- Phase 14 complete.
- Staging environment prepared with Bricks add-on enabled and representative data.
- Release candidate branch/build frozen for validation window.

Progress update (2026-02-15):
- `P15-T5-S1` implemented: intro packet endpoint contract added (`POST /dbvc/v1/bricks/intro/packet`).
- `P15-T5-S2` implemented: handshake accept/reject endpoint with signed acknowledgement added (`POST /dbvc/v1/bricks/intro/handshake`).
- connected-sites now supports registry-first sourcing mode from onboarding records (`dbvc_bricks_clients` option-backed) with onboarding lifecycle visibility in Packages -> Connected Sites.
- signed command verification scaffolding added (`DBVC_Bricks_Command_Auth`, `POST /dbvc/v1/bricks/commands/ping`) with timestamp/nonce HMAC verification and replay protection.

### Tasks / Sub-tasks
- `P15-T1` Staging workflow drill execution
  - `P15-T1-S1` Lock validation dataset + package manifest fixtures used for staging drills.
  - `P15-T1-S2` Execute apply/restore/rollback drill and capture timestamps + operator IDs.
  - `P15-T1-S3` Execute proposal submit/review/transition drill and capture full audit trail.
- `P15-T2` Security and contract validation closure
  - `P15-T2-S1` Verify idempotency behavior for all mutating Bricks endpoints under retry/replay.
  - `P15-T2-S2` Verify capability and nonce protections for Bricks submenu/admin-post and REST calls.
  - `P15-T2-S3` Validate compatibility with older DBVC manifest/snapshot payload variants in staging.
- `P15-T3` Live Bricks schema verification
  - `P15-T3-S1` Validate `bricks_theme_styles` payload shape against canonicalization assumptions.
  - `P15-T3-S2` Validate component label/slug path stability for drift/proposal/apply flows.
  - `P15-T3-S3` Document any schema deltas and required migration/backfill notes.
- `P15-T4` Go/no-go decision package
  - `P15-T4-S1` Update progress tracker statuses and attach command/output evidence.
  - `P15-T4-S2` Produce release decision summary (`GO` or `NO_GO`) with explicit blocker list.
  - `P15-T4-S3` Open follow-on phases/tasks for non-blocking enhancements discovered in validation.
- `P15-T5` Connected-network onboarding enhancement (Introduction Packet + handshake registry)
  - `P15-T5-S1` Add client introduction packet endpoint and payload (`site_uid`, `site_label`, `base_url`, capabilities, environment marker).
  - `P15-T5-S2` Add mothership accept/reject handshake endpoint with signed acknowledgement (`accepted`, `mothership_uid`, `registered_at`, `handshake_token`).
  - `P15-T5-S3` Add dedicated DBVC registry table (`dbvc_bricks_clients`) and migrate connected-sites table source to registry-first.
  - `P15-T5-S4` Add onboarding state machine (`PENDING_INTRO`, `VERIFIED`, `REJECTED`, `DISABLED`) and UI status badges.
  - `P15-T5-S5` Add idempotent auto-intro trigger once valid mothership credentials are configured on client.
  - `P15-T5-S6` Persist per-site onboarding transport state (`ping_sent`, `intro_sent`, `handshake_state`, `approved_at`) on activation/configure save.
  - `P15-T5-S7` Add bounded retry cron until `ping + intro + handshake` reach terminal success/failure state (idempotent retries, capped attempts, diagnostics).

### Required tests
- `P15-TEST-01` Staging apply + restore + rollback drill evidence.
- `P15-TEST-02` Staging proposal workflow drill evidence.
- `P15-TEST-03` Idempotency replay tests for mutating endpoints.
- `P15-TEST-04` Capability + nonce enforcement verification.
- `P15-TEST-05` Legacy manifest/snapshot compatibility verification.
- `P15-TEST-06` Live `bricks_theme_styles` + component slug/label schema verification.
- `P15-TEST-07` Force-channel policy tests (default, override, stable confirmation, audit fields).
- `P15-TEST-08` Introduction packet + handshake tests (client submit, mothership accept/reject, registry persistence, idempotency).

### Exit criteria
- All required tests pass with evidence captured in tracker.
- Final go/no-go gate section is fully satisfied.
- Any unresolved blocker is explicitly marked and phase status set to `BLOCKED` (no silent pass-through).

## Phase 13: True push/pull transport foundation (client -> mothership publish, mothership -> client pull)

### Entry criteria
- Phase 12 complete.
- Security baseline approved for remote writes (least privilege + TLS).
- Package schema version contract approved.

### Tasks / Sub-tasks
- `P13-T1` Package contract + lifecycle
  - `P13-T1-S1` Define immutable package schema (`package_id`, `version`, `channel`, `source_site`, `artifacts`, `digest`, `created_at`).
  - `P13-T1-S2` Define package status machine (`DRAFT`, `PUBLISHED`, `SUPERSEDED`, `REVOKED`).
  - `P13-T1-S3` Define compatibility strategy (`schema_version`, deprecation path, strict/lenient parse mode).
- `P13-T2` Mothership write endpoints (new)
  - `P13-T2-S1` Add `POST /dbvc/v1/bricks/packages` for client publish submission.
  - `P13-T2-S2` Add `POST /dbvc/v1/bricks/packages/{package_id}/promote` for channel promotion.
  - `P13-T2-S3` Add `POST /dbvc/v1/bricks/packages/{package_id}/revoke` for emergency stop.
  - `P13-T2-S4` Add idempotency-key enforcement on all mutating package endpoints.
- `P13-T3` Client publish pipeline
  - `P13-T3-S1` Build local package from selected artifacts + canonical hashes.
  - `P13-T3-S2` Add publish preflight (`dry-run`, policy checks, payload-size checks).
  - `P13-T3-S3` Submit package to mothership with correlation ID and actor/site attribution.
  - `P13-T3-S4` Persist publish receipt + remote package ID mapping locally.
- `P13-T4` Connected sites registry + selectable rollout controls
  - `P13-T4-S1` Add connected-site registry model (`site_uid`, `label`, `base_url`, `status`, `last_seen`, `auth_mode`).
  - `P13-T4-S2` Add mothership UI table listing connected sites with search/filter/sort.
  - `P13-T4-S3` Add selection controls:
    - `all sites`,
    - `selected sites` (row checkbox allowlist),
    - `exclude list` (optional future flag).
  - `P13-T4-S4` Persist publish targeting fields on package metadata (`target_mode`, `target_sites[]`).
  - `P13-T4-S5` Enforce server-side target validation (no publish to unregistered/disabled site).
- `P13-T5` Pull contract and delivery semantics
  - `P13-T5-S1` Extend package list/get responses with site-target visibility rules.
  - `P13-T5-S2` Add client pull filter by audience membership.
  - `P13-T5-S3` Add pull acknowledgment endpoint (`POST /dbvc/v1/bricks/packages/{package_id}/ack`) with applied/skipped states.
- `P13-T6` Documentation + tracker updates
  - `P13-T6-S1` Update progress tracker statuses.
  - `P13-T6-S2` Record phase 13 completion note with endpoint and UI evidence.

### Required tests
- `P13-TEST-01` Package publish endpoint contract + schema validation tests.
- `P13-TEST-02` Idempotency replay tests for package publish/promote/revoke.
- `P13-TEST-03` Connected sites table render + selection model tests (`all` vs `selected`).
- `P13-TEST-04` Server-side target allowlist enforcement tests.
- `P13-TEST-05` Pull visibility tests (targeted packages visible only to allowed client sites).
- `P13-TEST-06` End-to-end client publish -> mothership receive -> package listed flow.

### Exit criteria
- Client can publish a package to mothership with auditable receipt.
- Mothership can restrict package availability to all or selected connected sites.
- Pull visibility and acknowledgements enforce targeting contract.

## Phase 14: Push/pull operations + governance hardening

Phase status: DONE (2026-02-15)
Manual gate: `P14-TEST-01` PASS (clientA -> mothership -> clientA/clientB, `all` + `selected` targeting verified)

### Entry criteria
- Phase 13 complete.
- Connected-site registry populated with at least one local/staging client.

### Tasks / Sub-tasks
- `P14-T1` Mothership publish operations UI
  - `P14-T1-S1` Add incoming package review queue and package diff inspection.
  - `P14-T1-S2` Add approve/promote/revoke actions with role/capability guards.
  - `P14-T1-S3` Add channel workflow (`canary -> beta -> stable`) with explicit confirmations.
- `P14-T2` Client pull/apply UX
  - `P14-T2-S1` Show package audience metadata + targeting reason in client UI.
  - `P14-T2-S2` Add one-click pull latest allowed package and apply dry-run.
  - `P14-T2-S3` Add apply + restore point + rollback workflow tied to publish receipt IDs.
- `P14-T3` Reliability + failure handling
  - `P14-T3-S1` Add retry/backoff and dead-letter markers for failed push/pull operations.
  - `P14-T3-S2` Add partial-failure diagnostics with operator remediation hints.
  - `P14-T3-S3` Add delivery state timeline (`sent`, `received`, `eligible`, `pulled`, `applied`, `failed`).
- `P14-T4` Security/governance hardening
  - `P14-T4-S1` Enforce least-privilege integration accounts per connected site.
  - `P14-T4-S2` Add key rotation workflow and expiration warnings.
  - `P14-T4-S3` Enforce channel protection rules (stable promotion requires approval gate).
  - `P14-T4-S4` Add client publish force-channel policy (`none|canary|beta|stable`) with audit metadata (`channel_forced`, `forced_from`, `forced_to`, `forced_by`).
  - `P14-T4-S5` Require explicit confirmation when force-channel is `stable` and show warning banner in client packages UI.
- `P14-T5` Documentation + tracker updates
  - `P14-T5-S1` Update progress tracker statuses.
  - `P14-T5-S2` Record phase 14 completion note with operator runbook links.

### Required tests
- `P14-TEST-01` End-to-end publish/pull/apply drill (`clientA -> mothership -> clientA/clientB`) with allowlist targeting.
- `P14-TEST-02` Promote/revoke governance and approval gate tests.
- `P14-TEST-03` Failure recovery tests (retry, dead-letter, resume) for push/pull transport.
- `P14-TEST-04` Delivery timeline and audit attribution tests.
- `P14-TEST-05` Key rotation and expired-credential behavior tests.

### Exit criteria
- True push/pull workflow is production-safe with selective site targeting.
- Governance, reliability, and audit requirements are verifiably met.

## 3) Missing Items / Sub-tasks Tracker

- Live Bricks schema verification (required before Phase 3 close):
  - owner: `P15-T3`
  - `bricks_theme_styles` data shape validation.
  - component label/slug path stability validation.
- Security hardening:
  - owner: `P14-T4`
  - idempotency key enforcement on mutating endpoints.
  - strict capability and nonce checks for add-on admin actions.
- Compatibility:
  - owner: `P13-T1`
  - Backward compatibility with older DBVC manifests/snapshots.
- Push/pull selective delivery:
  - owner: `P13-T4` and `P13-T5`
  - connected-sites registry availability and health tracking.
  - mothership targeting mode (`all` vs `selected`) and server-side allowlist enforcement.
- UX alignment:
  - owner: `P15-T4-S3` (if non-blocking) or new blocking phase if release critical.
  - Confirm whether Bricks artifact review uses existing Entity Drawer + option drawer variant.
- Bricks submenu UX expansion:
  - owner: completed in phases `P8-P10`; carry forward only if new gaps are discovered in `P15-T4-S3`.
  - role-based page composition and role action controls.
  - differences panel + simple diff viewer for Entity/option artifacts.
  - apply/proposals/packages panel wiring inside submenu.
- User documentation library rollout (`DBVC_USER_DOCUMENTATION_LIBRARY`):
  - owner: `P15-T4-S3` follow-on enhancement (or new blocking phase if release-critical).
  - seed file: `docs/DBVC_USER_DOCUMENTATION_LIBRARY.md`.
  - scope: add a dedicated user-facing docs/library area in plugin UI and keep drift/package transport behavior documentation synchronized with shipped behavior.
- Drift-noise masking and ignore rules:
  - owner: `P15-T4-S3` follow-on enhancement (or new blocking phase if release-critical).
  - add artifact/meta masking + ignore-rule support so known noisy values can be excluded from drift/proposal/apply comparisons.
  - include option-level and nested option-object ignore paths (example: `bricks_color_palette` values that intentionally vary by site).
- Differences + Packages metadata clarity:
  - owner: `P15-T4-S3` follow-on enhancement.
  - add template title column/metadata in Differences table rows for `bricks_template` artifacts.
  - show site-domain/source-site metadata with package rows/detail so operators can quickly map package -> site and site -> package.

Tracking rule:
- Every open missing item must be linked to an owning phase/task/sub-task ID above.
- Missing items unresolved at phase exit force `BLOCKED` status.

## 4) Final Go/No-Go Gate

Ship only if all are true:
- All in-scope phase exit criteria for the target release milestone complete.
- No open blocker in section 3.
- Restore/rollback drill passes on staging.
- Proposal workflow drill passes on staging.
- Add-on disable switch fully deactivates Bricks routes/UI/jobs.
- Progress tracker shows all in-scope phases and tasks as `DONE` with test evidence.
