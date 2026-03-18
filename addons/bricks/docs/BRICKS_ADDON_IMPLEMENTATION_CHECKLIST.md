# Bricks Add-on Strict Implementation Checklist (Active)

Date: 2026-02-16  
Status: Execution checklist (active phases only)

## 0) Operating Rules

- No phase starts without all entry criteria complete.
- No phase is marked complete without all exit criteria + required tests passing.
- Any schema or behavior deviation discovered during implementation must be documented before proceeding.
- Use status values only: `NOT_STARTED`, `IN_PROGRESS`, `BLOCKED`, `DONE`.
- Update `BRICKS_ADDON_PROGRESS_TRACKER.md` immediately on any task/sub-task status change.

## 0.1 Active/Archive Layout

Active files:
- `addons/bricks/docs/BRICKS_ADDON_PROGRESS_TRACKER.md`
- `addons/bricks/docs/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST.md`

Archive files (completed phases/history):
- `addons/bricks/docs/archive/BRICKS_ADDON_PROGRESS_TRACKER_ARCHIVE_P1_P18.md`
- `addons/bricks/docs/archive/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST_ARCHIVE_P1_P18.md`
- `addons/bricks/docs/archive/BRICKS_ADDON_PROGRESS_TRACKER_SNAPSHOT_20260216T040755Z.md`
- `addons/bricks/docs/archive/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST_SNAPSHOT_20260216T040755Z.md`

## 1) Phase 19A: Shared Rules Distribution Foundation

### Entry criteria
- Phase 18 complete and verified in tracker archive.
- Connected-site registry and onboarding/signed-command scaffolding available.

### Tasks / Sub-tasks
- `P19A-T0` Contract freeze + guardrails
  - `P19A-T0-S1` Freeze shared rules profile schema: `profile_version`, `updated_at`, `updated_by`, five rule maps, optional notes.
  - `P19A-T0-S2` Freeze distribution receipt schema: `request_id`, `site_uid`, `state`, `applied_profile_version`, `error_code`, `error_message`, `correlation_id`, timestamps.
  - `P19A-T0-S3` Define explicit non-goals for 19A: no automatic artifact apply, no package mutation, no protected-variant behavior changes.
- `P19A-T1` Mothership shared profile persistence + API
  - `P19A-T1-S1` Add canonical storage model and version migration/default handling.
  - `P19A-T1-S2` Add strict validation + normalization for all five rule maps.
  - `P19A-T1-S3` Add mothership REST endpoints for profile read/write.
- `P19A-T2` Distribution transport + client signed apply
  - `P19A-T2-S1` Add mothership distribute endpoint (`all`/`selected`) with idempotency-key enforcement.
  - `P19A-T2-S2` Add client receive/apply endpoint requiring signed command verification.
  - `P19A-T2-S3` Add per-site diagnostics timeline (`queued|sent|applied|failed`) and correlation IDs.
  - `P19A-T2-S4` Add retry/backoff + dead-letter behavior for per-site failures.
- `P19A-T3` Validation + live evidence
  - `P19A-T3-S1` Add automated tests for schema, idempotency replay, signed verification, and diagnostics timeline.
  - `P19A-T3-S2` Run live drill on mothership + clientA/clientB for both target modes and capture receipts.

### Current Status (2026-03-08)
- `P19A-T3`: `DONE`
- `P19A-T3-S2`: `DONE` (deferred rerun `timestamp=20260308T022902Z` in `transport_mode=client_pull_envelope` queued `selected` + `all` for `test_site_a`/`test_site_b`; post-wait status snapshots show all envelopes `state=applied`; see tracker `P19A-TEST-05`)

### Required tests
- `P19A-TEST-01` Shared profile schema/persistence validation tests.
- `P19A-TEST-02` Distribution transport tests (`all` + `selected`) with idempotency replay.
- `P19A-TEST-03` Client signed verification tests for receive/apply endpoint.
- `P19A-TEST-04` Diagnostics timeline and dead-letter tests.
- `P19A-TEST-05` Live staging drill evidence (`mothership -> clientA/clientB`).

### Exit criteria
- One shared rules profile can be authored on mothership and distributed to all/selected clients.
- Client apply requires valid signature and returns per-site receipts.
- Failure handling is isolated per site with auditable diagnostics.
- Required tests pass and are logged in tracker.

## 2) Phase 19D: Signed Envelope Transport (Client Pull)

### Entry criteria
- Phase 19A implementation complete (`P19A-T0..T2 DONE`) and live drill currently blocked by mothership->client reachability constraints.

### Tasks / Sub-tasks
- `P19D-T1` Envelope contract + storage
  - `P19D-T1-S1` Freeze envelope schema (`envelope_id`, `command_type`, `site_uid`, `payload_hash`, signature metadata, state/attempt fields, timestamps).
  - `P19D-T1-S2` Add queue storage model with versioned defaults and migration handling.
  - `P19D-T1-S3` Add status index helpers for filtering by `site_uid`, `distribution_id`, and `state`.
- `P19D-T2` Mothership enqueue flow
  - `P19D-T2-S1` Add `commands/enqueue` endpoint with idempotency and per-target envelope fan-out.
  - `P19D-T2-S2` Wire shared-rules distribution to enqueue envelopes when transport mode is `client_pull_envelope`.
  - `P19D-T2-S3` Preserve correlation/distribution IDs and audit context in envelope metadata.
- `P19D-T3` Client pull + lease semantics
  - `P19D-T3-S1` Add `commands/pull` endpoint scoped to authenticated client site UID.
  - `P19D-T3-S2` Add lease grant/renew/expiry handling to prevent duplicate workers.
  - `P19D-T3-S3` Enforce pull filters so clients only receive their own envelopes.
- `P19D-T4` Client apply runner + ack
  - `P19D-T4-S1` Add client command runner that verifies signed envelope and routes command type (`shared_rules_apply` initial).
  - `P19D-T4-S2` Add `commands/ack` endpoint with idempotent `applied|failed` state transitions.
  - `P19D-T4-S3` Persist receipt details (`applied_profile_version`, error fields, actor/site/timestamps).
- `P19D-T5` Retry/backoff/dead-letter
  - `P19D-T5-S1` Add exponential backoff + cap for failed envelopes.
  - `P19D-T5-S2` Add dead-letter transition on max attempts or expiry.
  - `P19D-T5-S3` Add remediation hints and replay-safe retry action.
    - `P19D-T5-S3-S1` Add duplicate identity detection (`same normalized base_url`, different `site_uid`) and mark conflicts non-targetable.
    - `P19D-T5-S3-S2` Add mothership Connected Sites action `Merge/Deactivate Duplicate Alias` (confirmation + canonical UID choice; no auto-merge by default).
    - `P19D-T5-S3-S3` Add mothership Connected Sites action `Reset Linkage` (clear command secret/hash + set `PENDING_INTRO`; do not trigger remote execution).
    - `P19D-T5-S3-S4` Add client action `Reset + Re-run Intro Handshake` (client-initiated secure retry only).
    - `P19D-T5-S3-S5` Add targeting/queue guardrails to block conflicted/unhealthy identities with explicit diagnostics codes.
    - `P19D-T5-S3-S6` Add mothership Connected Sites action `Forget Linkage` (soft reset + hide from default table; preserve history; require typed UID confirmation; auto-unhide on fresh intro packet re-introduction, then remain `PENDING_INTRO` until verified handshake recovery).
    - `P19D-T5-S3-S7` Add mothership Connected Sites action `Confirm Handshake` for `PENDING_INTRO` rows (calls intro handshake `accept` with idempotency key; transitions onboarding to `VERIFIED` when accepted).
  - `P19D-T5-S4` Add site identity continuity + alias bridge model (for UID drift and broken handshake recovery).
    - `P19D-T5-S4-S1` Add identity evidence fields per site record: `local_instance_uuid`, `first_seen_at`, `site_sequence_id`, `site_title_host_snapshot`.
    - `P19D-T5-S4-S2` Add deterministic alias resolver (`alias_site_uid -> canonical_site_uid`) used on intro/enqueue/pull/ack/status paths.
    - `P19D-T5-S4-S3` Add Connected Sites row control for manual `known_alias` text input and confirmation flow (no implicit remap).
    - `P19D-T5-S4-S4` Add duplicate reconciliation policy: default manual merge/deactivate; optional assisted auto-merge mode only for deterministic matches and explicit operator confirmation.
    - `P19D-T5-S4-S5` Add migration/backfill + audit continuity rules so historical transport/package/apply records remain queryable under canonical identity.
  - `P19D-T5-S5` Production self-heal for handshake/auth drift + deterministic preflight guards.
    - `P19D-T5-S5-S1` Auto-downgrade site health to `PENDING_INTRO` when client ack reports `dbvc_bricks_client_envelope_secret_missing`; clear stored linkage secrets and record diagnostics.
    - `P19D-T5-S5-S2` Add enqueue/distribute preflight classification (`ready`, `blocked_pending_intro`, `blocked_secret_missing`, `blocked_duplicate_conflict`, `blocked_allow_receive_disabled`) and expose remediation hints in API payloads.
    - `P19D-T5-S5-S3` Add deterministic canonical reroute behavior for alias targets only when canonical is healthy and deterministic identity evidence confirms continuity; otherwise fail with explicit canonical remediation payload. Also prefer verified + linkage-ready canonical selection for duplicate URL groups over stale/pending intro records.
    - `P19D-T5-S5-S4` Add enqueue/distribute diagnostics + operator-facing payload hints for idempotency/header omissions and blocked states.
    - `P19D-T5-S5-S5` Streamline onboarding recovery UX by moving client `Reset + Re-run Intro Handshake` from `First-Time Checklist` into `Configure > Basic settings` (and keeping checklist copy as guidance-only).
- `P19D-T6` Operations + diagnostics
  - `P19D-T6-S1` Add mothership diagnostics timeline for envelope lifecycle (`queued|leased|applied|failed|dead_letter`).
  - `P19D-T6-S2` Add queue status endpoint and operator-facing summary fields.
  - `P19D-T6-S3` Add transport mode setting (`direct_push|client_pull_envelope`) and migration safeguards.
- `P19D-T7` Validation + gating
  - `P19D-T7-S1` Add automated tests for enqueue/pull/ack/lease/retry/dead-letter and signature validation.
  - `P19D-T7-S2` Run live drill using client-pull transport across mothership/clientA/clientB (`all` + `selected`).
  - `P19D-T7-S3` Re-run `P19A-TEST-05` and close P19A gate if transport evidence passes.

### Current Status (2026-03-08)
- `P19D-T7`: `DONE`
- `P19D-T7-S2`: `DONE` (rerun `timestamp=20260308T021952Z` queued target envelopes in both `selected` + `all`, then post-wait status snapshots show `test_site_a` + `test_site_b` envelopes `state=applied`; non-target forgotten rows remained blocked `site_linkage_forgotten`)
- `P19D-T7-S3`: `DONE` (deferred `P19A-TEST-05` rerun `timestamp=20260308T022902Z` passed in `client_pull_envelope` mode with both target sites `applied`)
- `P19D-T5-S3`: `DONE` (duplicate UID conflict detection + non-targetable guardrails, mothership merge/deactivate/reset-linkage/forget-linkage/confirm-handshake actions, client-only `Reset + Re-run Intro Handshake`; automated coverage added in `BricksAddonPhase19DTest`)
- `P19D-T5-S4`: `DONE` (identity continuity metadata + deterministic `known_alias` resolver wired across intro/queue/auth paths; Connected Sites manual alias input/action added; assisted merge policy controls enforce deterministic candidate token + explicit confirmation)
- `P19D-T5-S5`: `DONE` (self-heal now includes lease-time signature refresh, bootstrap-safe random seed fallback for pre-pluggable contexts, enqueue preflight classification/remediation payloads, deterministic duplicate reroute using recent pull identity evidence, canonical selection preference for verified/linkage-ready duplicates, and endpoint-wide idempotency/header diagnostics for non-enqueue command endpoints; live mothership smoke evidence captured at `timestamp=20260308T030946Z`)

### Required tests
- `P19D-TEST-01` Envelope schema/queue persistence tests.
- `P19D-TEST-02` Enqueue idempotency and fan-out targeting tests (`all` + `selected`).
- `P19D-TEST-03` Pull endpoint auth/site-scope/lease behavior tests.
- `P19D-TEST-04` Ack transitions + retry/backoff/dead-letter tests.
- `P19D-TEST-05` Signature/nonce/timestamp validation tests.
- `P19D-TEST-06` Live staging drill evidence with client-pull transport.
- `P19D-TEST-07` Re-validation of `P19A-TEST-05` after enabling client-pull transport.
- `P19D-TEST-08` Identity continuity tests for UID drift: alias resolution, canonical remap logging, and history continuity assertions.
- `P19D-TEST-09` Connected Sites UI tests for manual `known_alias` input + merge confirmation and safety checks.
- `P19D-TEST-10` Secret-missing recovery test: ack failure auto-marks site/linkage back to `PENDING_INTRO` and writes diagnostics.
- `P19D-TEST-11` Enqueue payload normalization test for `refresh_shared_rules` alias + distribution/profile injection.
- `P19D-TEST-12` Pull lease-signature refresh + recent pull activity tracking test.
- `P19D-TEST-13` Deterministic duplicate conflict reroute and preflight classification output test.
- `P19D-TEST-14` Duplicate canonical preference regression test for verified/linkage-ready UID selection over pending-intro duplicates.
- `P19D-TEST-15` Forget-linkage regression test: mothership soft reset + default-hidden row behavior + visibility recovery on fresh intro packet (pending-intro) and verified handshake completion.
- `P19D-TEST-16` Non-enqueue operator diagnostics regression test (`commands/pull` and `commands/ack` missing header/idempotency + invalid ack payload hints/diagnostics).

### Exit criteria
- Shared-rules distribution no longer depends on mothership direct network reachability to client hostnames.
- Per-site command delivery is auditable, idempotent, and retry-safe.
- Dead-letter handling and operator diagnostics are available.
- `P19A-TEST-05` passes under client-pull transport and tracker is updated.
- UID drift and handshake recovery preserve historical record continuity via canonical/alias mapping without destructive data loss.

## 3) Phase 19B: Client Protected Artifact Variants

### Entry criteria
- Phase 19A complete.
- Phase 19D complete.

### Tasks / Sub-tasks
- `P19B-T1` Protected variant contract + storage
  - `P19B-T1-S1` Define protected variant schema (`artifact_uid`, `artifact_type`, `label`, `reason`, `scope`, actor/timestamps).
  - `P19B-T1-S2` Add dedupe/uniqueness rule (`artifact_uid` + `scope`) and migration defaults.
  - `P19B-T1-S3` Add CRUD API with authorization/capability enforcement.
- `P19B-T2` Client UI tab + workflow
  - `P19B-T2-S1` Add `Protected Artifacts` tab for client role.
  - `P19B-T2-S2` Add create/list/remove controls (reason required, confirm remove).
  - `P19B-T2-S3` Add Differences panel integration to mark/unmark selected artifact.
  - `P19B-T2-S4` Enforce read-only mode disables all mutating actions.
- `P19B-T3` Audit + context annotations
  - `P19B-T3-S1` Emit protected-variant audit events (`created|updated_reason|removed`).
  - `P19B-T3-S2` Add read-only annotations in drift/apply/package payloads indicating protected state.

### Current Status (2026-03-08)
- `P19B-T1`: `DONE` (added protected-variant registry module + storage normalization + deterministic dedupe keying on `artifact_uid+scope`).
- `P19B-T1-S1`: `DONE` (schema now persists `variant_id`, `artifact_uid`, `artifact_type`, `label`, `reason`, `scope`, `created_at`, `created_by`, `updated_at`, `updated_by`).
- `P19B-T1-S2`: `DONE` (migration/default guard added via `ensure_defaults()` with runtime normalization/backfill of legacy rows and bounded store retention).
- `P19B-T1-S3`: `DONE` (client-only CRUD REST surface: `GET/POST /protected-variants`, `PATCH/DELETE /protected-variants/{variant_id}` with `manage_options` permission callback, read-only mutation block, and idempotency enforcement on mutating actions).
- `P19B-T2`: `DONE`
- `P19B-T2-S1`: `DONE` (client-only `Protected Artifacts` admin tab/panel wired in Bricks UI, with API-backed list rendering and role-gated visibility).
- `P19B-T2-S2`: `DONE` (added client create/list/remove controls with required reason validation on save, row-level and selected-item remove actions, and confirmation gating prior to delete request dispatch).
- `P19B-T2-S3`: `DONE` (Differences panel now supports mark/unmark for selected artifact via protected-variant create/delete flows, with client-only controls and live protected-state indicator wiring).
- `P19B-T2-S4`: `DONE` (read-only mode now disables all protected-variant mutating controls in Protected Artifacts and Differences panels, including row-level remove actions; JS mutation helpers now short-circuit with read-only guard errors).
- `P19B-T3`: `DONE` (drift/apply/package payloads now include read-only protected-variant annotations while preserving existing apply execution semantics).
- `P19B-T3-S1`: `DONE` (protected-variant audit hooks remain emitted on create/update/remove via `dbvc_bricks_audit_event` and dedicated hook variants).
- `P19B-T3-S2`: `DONE` (drift scan/compare rows, apply plan/results, and package list/get/pull-latest payloads now carry `protected_variant` entries and top-level summary metadata).

### Required tests
- `P19B-TEST-01` Protected variant CRUD + auth tests.
- `P19B-TEST-02` Protected Artifacts tab rendering/interaction tests.
- `P19B-TEST-03` Differences mark/unmark integration tests.
- `P19B-TEST-04` Read-only mode gating tests.
- `P19B-TEST-05` Payload annotation and audit event tests.

### Test Log (2026-03-08)
- `P19B-TEST-01`: `PASS` via `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19BTest.php` (CRUD, dedupe, role/auth checks, read-only guard, idempotent replay, delete confirmation, audit hook emission).
- `P19B-TEST-02`: `PASS` via `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19BTest.php` and `vendor/bin/phpunit tests/phpunit/BricksAddonPhase8Test.php` (client-only tab rendering/role-gating plus create/remove control + handler wiring assertions in `test_protected_artifacts_tab_renders_for_client_role_only`).
- `P19B-TEST-03`: `PASS` via `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19BTest.php` (Differences panel mark/unmark protected control rendering and handler wiring assertions in `test_differences_panel_protected_mark_unmark_controls_render_for_client_only`).
- `P19B-TEST-04`: `PASS` via `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19BTest.php` (read-only gating assertions for disabled mutating controls in both Protected Artifacts and Differences panels, plus read-only mutation-guard messaging via `test_read_only_mode_disables_protected_variant_mutating_controls`).
- `P19B-TEST-05`: `PASS` via `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19BTest.php` (payload annotation coverage across drift scan/compare, apply plan/live apply response metadata, and packages list/get/pull-latest responses in `test_drift_apply_and_package_payloads_include_protected_variant_annotations_without_behavior_change`).

### Exit criteria
- Client operators can manage protected variants in a dedicated Bricks tab.
- Protected variants are auditable, deduplicated, and role/capability constrained.
- Payload annotations are visible without altering apply semantics.
- Required tests pass and are logged in tracker.

## 4) Phase 19C: Mothership Visibility + Cross-Site Drill

### Entry criteria
- Phase 19B complete.

### Tasks / Sub-tasks
- `P19C-T1` Mothership protected-variant visibility
  - `P19C-T1-S1` Add connected-client summary table with protected counts by artifact class.
  - `P19C-T1-S2` Add drill-down list for each client’s protected variants.
  - `P19C-T1-S3` Add deep-link and copy-link helper to client `DBVC -> Bricks -> Protected Artifacts`.
  - `P19C-T1-S4` Add freshness indicators (`last_seen`, `last_sync`) per client.
- `P19C-T2` Final validation and closure
  - `P19C-T2-S1` Run full network drill (shared rules distribution + protected variant visibility) across mothership/clientA/clientB.
  - `P19C-T2-S2` Capture commands, timestamps, receipts, diagnostics traces, and UI evidence.
  - `P19C-T2-S3` Update tracker statuses to `DONE` and write Phase 19 completion note.

### Required tests
- `P19C-TEST-01` Mothership aggregation visibility tests.
- `P19C-TEST-02` Deep-link/copy-link rendering tests.
- `P19C-TEST-03` Full live cross-site drill evidence test.

### Exit criteria
- Mothership can identify and inspect clients with protected variants.
- Operators can navigate (or copy-link navigate) to client protected tabs reliably.
- Live cross-site evidence is complete and auditable.
- Required tests pass and are logged in tracker.

## 4.1) Backlog Candidates (Next Implementation Phase)

### `BL-PKG-TABLE-01` Packages table site identity columns
- Add two table headers to the Packages tab package table (current header row: `Select | Package | Version | Channel | Audience`):
  - `Site Domain`
  - `Site UID`
- Ensure values render per package row using connected-site metadata and remain sortable/filter-safe with existing table behavior.

### `BL-SMARTMODE-01` Simple Smart Mode workflow
- Add a new `Simple Smart Mode` toggle flow that is only shown/enabled after all prerequisites are true:
  - mothership is configured,
  - first client site is configured,
  - intro/handshake is confirmed valid.
- When enabled, Smart Mode should:
  - automatically apply planned/default settings for the workflow,
  - incrementally record Bricks Builder artifact changes from client sites,
  - maintain a running fluid package of those changes,
  - send that package to mothership periodically,
  - flag incoming changes for mothership review and merge into Golden artifacts.
- Note: detailed trigger/decision map is pending and will be charted in a dedicated flow-spec task.

## 5) Go/No-Go Gate (Phase 19 Series)

Ship Phase 19 series only if all are true:
- `P19A`, `P19D`, `P19B`, and `P19C` are `DONE` in tracker.
- No open `BLOCKED` items.
- Live drill evidence captured for mothership + at least two clients.
- Add-on disable switch still fully deactivates Bricks routes/UI/jobs.
