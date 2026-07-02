# Bricks Add-on Progress Tracker (Active)

Date created: 2026-02-16  
Status model: `NOT_STARTED`, `IN_PROGRESS`, `BLOCKED`, `DONE`

## Global Rules

- Update this file immediately when any task/sub-task status changes.
- Include test evidence summaries before marking any task/phase `DONE`.
- If a required test fails, set impacted task/phase to `BLOCKED` with cause and next action.

## Backlog Candidates (Future Phase / Not Yet Scheduled)

- `BL-PKG-TABLE-01` Packages tab table enhancement:
  - Add `Site Domain` and `Site UID` headers/columns under the package table currently using `Select | Package | Version | Channel | Audience`.
- `BL-SMARTMODE-01` Simple Smart Mode workflow:
  - Add conditional toggle visible only after mothership configured + first client configured + valid handshake confirmed.
  - On enable, auto-apply planned settings, track client Bricks artifact changes incrementally, build a running fluid package, periodically send to mothership, and mark submissions for review/merge into Golden artifacts.

## Archive References

Completed phases and historical notes were moved to archive files to reduce active-context size:
- `addons/bricks/docs/archive/BRICKS_ADDON_PROGRESS_TRACKER_ARCHIVE_P1_P18.md`
- `addons/bricks/docs/archive/BRICKS_ADDON_PROGRESS_TRACKER_SNAPSHOT_20260216T040755Z.md`
- `addons/bricks/docs/archive/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST_ARCHIVE_P1_P18.md`
- `addons/bricks/docs/archive/BRICKS_ADDON_IMPLEMENTATION_CHECKLIST_SNAPSHOT_20260216T040755Z.md`

Archived completion scope summary:
- `P1` through `P18`: `DONE` (including Phase 14 manual gate and Phase 18 live evidence closure).

## Phase 19A - Shared Rules Distribution Foundation
Status: DONE
Owner: Codex
Started: 2026-02-16
Completed: 2026-03-08

### Tasks
- [x] P19A-T0 Contract freeze + guardrails (Status: DONE)
  - [x] P19A-T0-S1 Freeze shared rules profile schema + explicit non-goals for 19A (Status: DONE)
  - [x] P19A-T0-S2 Define idempotency, error-code, and per-site receipt contracts (Status: DONE)
  - [x] P19A-T0-S3 Add backward-compatible defaults for missing/new rule fields (Status: DONE)
- [x] P19A-T1 Mothership shared profile persistence (Status: DONE)
  - [x] P19A-T1-S1 Add canonical shared rules storage + version metadata (Status: DONE)
  - [x] P19A-T1-S2 Add strict validation + normalized serialization for all five rule maps (Status: DONE)
  - [x] P19A-T1-S3 Add mothership read/write REST endpoints for shared profile (Status: DONE)
- [x] P19A-T2 Distribution transport and signed apply (Status: DONE)
  - [x] P19A-T2-S1 Add mothership distribute endpoint (`all` and `selected`) with idempotency (Status: DONE)
  - [x] P19A-T2-S2 Add client receive/apply endpoint with signed-command verification (Status: DONE)
  - [x] P19A-T2-S3 Add distribution diagnostics timeline (`queued|sent|applied|failed`) with correlation IDs (Status: DONE)
  - [x] P19A-T2-S4 Add retry/backoff + dead-letter behavior for site-level failures (Status: DONE)
- [x] P19A-T3 Validation + staging proof (Status: DONE)
  - [x] P19A-T3-S1 Add automated tests for schema validation, idempotency replay, and signed-apply checks (Status: DONE)
- [x] P19A-T3-S2 Execute live drill: mothership -> clientA/clientB (`all` + `selected`) and capture receipts (Status: DONE - deferred rerun `timestamp=20260308T022902Z` in `client_pull_envelope` queued both targets in `selected`/`all` and post-wait status shows all envelopes `applied` for `test_site_a` + `test_site_b`)

### Test Evidence
- P19A-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19ATest.php` (`test_shared_rules_profile_get_returns_contract_fields`, `test_shared_rules_profile_post_rejects_invalid_diff_rules_shape`).
- P19A-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19ATest.php` (`test_shared_rules_profile_post_requires_idempotency_key`, `test_shared_rules_profile_post_is_idempotent_and_persists_normalized_profile`).
- P19A-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19ATest.php` (`test_shared_rules_profile_endpoints_require_mothership_role`, `test_shared_rules_profile_apply_endpoint_requires_valid_signature_and_applies_rules`).
- P19A-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19ATest.php` (`test_shared_rules_profile_distribution_selected_target_succeeds_and_tracks_transport`, `test_shared_rules_profile_distribution_failures_mark_dead_letter_after_threshold`).
- P19A-TEST-05: FAIL - live drill rerun executed 2026-02-16 (`timestamp=20260216T051034Z`) reached shared-rules endpoints successfully but distribution failed with `command_secret_missing` for `test_site_a`, `test_site_b`, and `pluginmediaimporter`. Evidence: `/tmp/p19a/20260216T051034Z_dist_all.json`, `/tmp/p19a/20260216T051034Z_dist_selected.json`, diagnostics `/tmp/p19a/20260216T051034Z_m_diagnostics_after.json`.
- P19A-TEST-05: FAIL - post-handshake retry (`timestamp=20260216T052644Z`) accepted `test_site_a` handshake token, but distribution still failed: `remote_http_error` -> `cURL error 6: Could not resolve host: dbvc-codexchanges.local`; `test_site_b` handshake returned `dbvc_bricks_intro_client_not_found` (no onboarding record to accept). Evidence: `/tmp/p19a/20260216T052644Z_handshake_test_site_a.json`, `/tmp/p19a/20260216T052644Z_handshake_test_site_b.json`, `/tmp/p19a/20260216T052644Z_dist_selected_retry.json`.
- P19A-TEST-05: FAIL - full rerun after latest-code confirmation (`timestamp=20260216T055552Z`): `dist_all` failed with `remote_http_error` for `test_site_a` (`Could not resolve host: dbvc-codexchanges.local`) and `command_secret_missing` for `test_site_b`/`pluginmediaimporter`; `dist_selected` to `test_site_a` failed with same DNS error. Evidence: `/tmp/p19a/20260216T055552Z_dist_all.json`, `/tmp/p19a/20260216T055552Z_dist_selected.json`, `/tmp/p19a/20260216T055552Z_handshake_test_site_a.json`, `/tmp/p19a/20260216T055552Z_handshake_test_site_b.json`, diagnostics `/tmp/p19a/20260216T055552Z_m_diag_after.json`.
- P19A-TEST-05: FAIL - final rerun after confirmed latest deploy (`timestamp=20260216T055953Z`): intro+handshake succeeded for `test_site_a` and `test_site_b`, but transport still failed with `remote_http_error` DNS from mothership to client local domains (`dbvc-codexchanges.local`, `vf-pluginmediaimporter.local`). `pluginmediaimporter` remained `command_secret_missing` (separate pending intro record). Evidence: `/tmp/p19a/20260216T055953Z_manual_intro_test_site_b.json`, `/tmp/p19a/20260216T055953Z_handshake_test_site_a.json`, `/tmp/p19a/20260216T055953Z_handshake_test_site_b.json`, `/tmp/p19a/20260216T055953Z_dist_all.json`, `/tmp/p19a/20260216T055953Z_dist_selected.json`, diagnostics `/tmp/p19a/20260216T055953Z_m_diag_after.json`.
- P19A-TEST-05: FAIL - deferred rerun executed 2026-02-16 (`timestamp=20260216T073130Z`) but mothership distribution still ran in `transport_mode=direct_push`; `all` failed with `remote_http_error` for `test_site_a`/`test_site_b` and `command_secret_missing` for `pluginmediaimporter`; `selected` failed with `remote_http_error` for `test_site_a`/`test_site_b`. Evidence: command bodies `/tmp/p19a/20260216T073130Z_dist_all_body.json`, `/tmp/p19a/20260216T073130Z_dist_selected_body.json`; responses `/tmp/p19a/20260216T073130Z_dist_all.json`, `/tmp/p19a/20260216T073130Z_dist_selected.json`; diagnostics `/tmp/p19a/20260216T073130Z_m_diag_after.json`, `/tmp/p19a/20260216T073130Z_clientA_diag_after.json`, `/tmp/p19a/20260216T073130Z_clientB_diag_after.json`.
- P19A-TEST-05: FAIL - rerun after mothership transport switch (`timestamp=20260216T074241Z`) now executes with `transport_mode=client_pull_envelope` for both `all` and `selected`; enqueue succeeds for target clients but `applied=0` (envelopes remain queued) and `pluginmediaimporter` still fails with `command_secret_missing` under `all`. Evidence: `/tmp/p19a/20260216T074241Z_dist_all_body.json`, `/tmp/p19a/20260216T074241Z_dist_selected_body.json`, `/tmp/p19a/20260216T074241Z_dist_all.json`, `/tmp/p19a/20260216T074241Z_dist_selected.json`, diagnostics `/tmp/p19a/20260216T074241Z_m_diag_after.json`, `/tmp/p19a/20260216T074241Z_clientA_diag_after.json`, `/tmp/p19a/20260216T074241Z_clientB_diag_after.json`.
- P19A-TEST-05: FAIL - deferred rerun executed 2026-02-17 (`timestamp=20260217T051541Z`) in `transport_mode=client_pull_envelope`: `selected` queued 2 (`env_14900761c523cffb`,`env_49aab8b94c59ecdf`) and `all` queued 2 (`env_bacd9d6c00fa0f58`,`env_519923eb41c23233`) + `pluginmediaimporter` `command_secret_missing`; after six client-trigger polls, `test_site_a` envelopes moved to `failed` with `dbvc_bricks_client_envelope_secret_missing`, `test_site_b` envelopes remained `queued`, and `applied=0`. Evidence: `/tmp/p19a/20260217T051541Z_dist_selected_body.json`, `/tmp/p19a/20260217T051541Z_dist_all_body.json`, `/tmp/p19a/20260217T051541Z_dist_selected.json`, `/tmp/p19a/20260217T051541Z_dist_all.json`, `/tmp/p19a/20260217T051541Z_status_selected_poll_6.json`, `/tmp/p19a/20260217T051541Z_status_all_poll_6.json`, `/tmp/p19a/20260217T051541Z_m_diag_after.json`, `/tmp/p19a/20260217T051541Z_clientA_diag_after.json`, `/tmp/p19a/20260217T051541Z_clientB_diag_after.json`.
- P19A-TEST-05: FAIL - deferred rerun executed 2026-02-17 (`timestamp=20260217T065829Z`) in `transport_mode=client_pull_envelope`: `selected` queued 2 and `all` queued 2 + `pluginmediaimporter` `command_secret_missing`; after six polls, `test_site_a` envelopes transitioned `queued -> leased -> failed` with `dbvc_bricks_client_envelope_secret_missing`, `test_site_b` envelopes remained `queued` (no lease/apply), and `applied=0`. Evidence: `/tmp/p19a/20260217T065829Z_dist_selected_body.json`, `/tmp/p19a/20260217T065829Z_dist_all_body.json`, `/tmp/p19a/20260217T065829Z_dist_selected.json`, `/tmp/p19a/20260217T065829Z_dist_all.json`, `/tmp/p19a/20260217T065829Z_status_selected_poll_6.json`, `/tmp/p19a/20260217T065829Z_status_all_poll_6.json`, `/tmp/p19a/20260217T065829Z_m_diag_after.json`, `/tmp/p19a/20260217T065829Z_clientA_diag_after.json`, `/tmp/p19a/20260217T065829Z_clientB_diag_after.json`.
- P19A-TEST-05: FAIL - deferred rerun executed 2026-02-17 (`timestamp=20260217T071905Z`) in `transport_mode=client_pull_envelope`: `selected` queued 2 and `all` queued 2 + `pluginmediaimporter` `command_secret_missing`; after six polls, `test_site_a` envelopes transitioned `queued -> leased -> failed` with `dbvc_bricks_client_envelope_secret_missing`, `test_site_b` envelopes remained `queued` (no lease/apply), and `applied=0`. Evidence: `/tmp/p19a/20260217T071905Z_dist_selected_body.json`, `/tmp/p19a/20260217T071905Z_dist_all_body.json`, `/tmp/p19a/20260217T071905Z_dist_selected.json`, `/tmp/p19a/20260217T071905Z_dist_all.json`, `/tmp/p19a/20260217T071905Z_status_selected_poll_6.json`, `/tmp/p19a/20260217T071905Z_status_all_poll_6.json`, `/tmp/p19a/20260217T071905Z_m_diag_after.json`, `/tmp/p19a/20260217T071905Z_clientA_diag_after.json`, `/tmp/p19a/20260217T071905Z_clientB_diag_after.json`.
- P19A-TEST-05: FAIL - deferred rerun executed 2026-02-17 (`timestamp=20260217T081531Z`) in `transport_mode=client_pull_envelope`: `selected` and `all` each queued 2 target sites (`test_site_a`,`test_site_b`) with no immediate enqueue failures; after six polls and post-wait snapshot, `test_site_a` envelopes remained `leased` (attempt_count incrementing) and `test_site_b` remained `queued` (`applied=0`). ClientA diagnostics repeatedly logged `dbvc_bricks_client_envelope_timestamp_invalid` during this run. Evidence: `/tmp/p19a/20260217T081531Z_dist_selected_body.json`, `/tmp/p19a/20260217T081531Z_dist_all_body.json`, `/tmp/p19a/20260217T081531Z_dist_selected.json`, `/tmp/p19a/20260217T081531Z_dist_all.json`, `/tmp/p19a/20260217T081531Z_status_selected_poll_6.json`, `/tmp/p19a/20260217T081531Z_status_all_poll_6.json`, `/tmp/p19a/20260217T081531Z_status_selected_after_wait.json`, `/tmp/p19a/20260217T081531Z_status_all_after_wait.json`, `/tmp/p19a/20260217T081531Z_m_diag_after.json`, `/tmp/p19a/20260217T081531Z_clientA_diag_after.json`, `/tmp/p19a/20260217T081531Z_clientB_diag_after.json`.
- P19A-TEST-05: FAIL - deferred rerun executed 2026-02-17 (`timestamp=20260217T091900Z`) in `transport_mode=client_pull_envelope`: `selected` and `all` each queued 2 target sites (`test_site_a`,`test_site_b`) with no immediate enqueue failures; after six polls and post-wait snapshot, `test_site_a` remained `leased` with `last_error_code=dbvc_bricks_client_envelope_timestamp_invalid` (`attempt_count=6`) while `test_site_b` remained `queued` (`attempt_count=0`), and `applied=0`. Evidence: `/tmp/p19a/20260217T091900Z_dist_selected_body.json`, `/tmp/p19a/20260217T091900Z_dist_all_body.json`, `/tmp/p19a/20260217T091900Z_dist_selected.json`, `/tmp/p19a/20260217T091900Z_dist_all.json`, `/tmp/p19a/20260217T091900Z_status_selected_poll_6.json`, `/tmp/p19a/20260217T091900Z_status_all_poll_6.json`, `/tmp/p19a/20260217T091900Z_status_selected_after_wait.json`, `/tmp/p19a/20260217T091900Z_status_all_after_wait.json`, `/tmp/p19a/20260217T091900Z_m_diag_after.json`, `/tmp/p19a/20260217T091900Z_clientA_diag_after.json`, `/tmp/p19a/20260217T091900Z_clientB_diag_after.json`.
- P19A-TEST-05: FAIL - deferred rerun executed 2026-03-06 (`timestamp=20260306T121455Z`) in `transport_mode=client_pull_envelope`: `selected` and `all` each queued `test_site_a` (`env_b2547003332f30b7`, `env_fff2b9a282ea61e9`) and failed canonical `pluginmediaimporter` with `command_secret_missing`; post-poll snapshots show both queued envelopes reached `state=applied` (`attempt_count=1`) for `test_site_a`, but overall gate remains FAIL because second target linkage/secret is still unresolved. Evidence: `/tmp/p19a/20260306T121455Z_dist_selected_body.json`, `/tmp/p19a/20260306T121455Z_dist_all_body.json`, `/tmp/p19a/20260306T121455Z_dist_selected.json`, `/tmp/p19a/20260306T121455Z_dist_all.json`, `/tmp/p19a/20260306T121455Z_status_selected_poll_6.json`, `/tmp/p19a/20260306T121455Z_status_all_poll_6.json`, `/tmp/p19a/20260306T121455Z_status_selected_after_wait.json`, `/tmp/p19a/20260306T121455Z_status_all_after_wait.json`, `/tmp/p19a/20260306T121455Z_m_diag_after.json`, `/tmp/p19a/20260306T121455Z_clientA_diag_after.json`, `/tmp/p19a/20260306T121455Z_clientB_diag_after.json`.
- P19A-TEST-05: FAIL - deferred rerun executed 2026-03-06 (`timestamp=20260306T133224Z`) in `transport_mode=client_pull_envelope`: `selected` queued `test_site_a` (`env_2900b8fec68eded9`) and failed canonical `pluginmediaimporter` with `command_secret_missing`; `all` queued `test_site_a` (`env_d22952f23de88c77`) and failed canonical `pluginmediaimporter` with `command_secret_missing`; poll-6 + post-wait snapshots show both envelopes reached `state=applied` (`attempt_count=1`) for `test_site_a`, but the gate remains FAIL because the alias target linkage/secret is still unresolved. Evidence: `/tmp/p19a/20260306T133224Z_dist_selected_body.json`, `/tmp/p19a/20260306T133224Z_dist_all_body.json`, `/tmp/p19a/20260306T133224Z_dist_selected.json`, `/tmp/p19a/20260306T133224Z_dist_all.json`, `/tmp/p19a/20260306T133224Z_status_selected_poll_6.json`, `/tmp/p19a/20260306T133224Z_status_all_poll_6.json`, `/tmp/p19a/20260306T133224Z_status_selected_after_wait.json`, `/tmp/p19a/20260306T133224Z_status_all_after_wait.json`, `/tmp/p19a/20260306T133224Z_m_diag_after.json`, `/tmp/p19a/20260306T133224Z_clientA_diag_after.json`, `/tmp/p19a/20260306T133224Z_clientB_diag_after.json`.
- P19A-TEST-05: PASS - deferred rerun executed 2026-03-08 (`timestamp=20260308T022902Z`) in `transport_mode=client_pull_envelope`: `selected` queued 2 (`env_01bb90144267d866`, `env_cfb56fb2e7303c3d`) and `all` queued 2 (`env_7a8e6c08426be7fa`, `env_e3d4aacff2c5d32d`) with no enqueue failures; post-wait status snapshots show all four envelopes `state=applied` (`attempt_count=1`) for `test_site_a` and `test_site_b`, closing the deferred live gate. Evidence: `/tmp/p19a/20260308T022902Z_dist_selected_body.json`, `/tmp/p19a/20260308T022902Z_dist_all_body.json`, `/tmp/p19a/20260308T022902Z_dist_selected.json`, `/tmp/p19a/20260308T022902Z_dist_all.json`, `/tmp/p19a/20260308T022902Z_status_selected_poll_6.json`, `/tmp/p19a/20260308T022902Z_status_all_poll_6.json`, `/tmp/p19a/20260308T022902Z_status_selected_after_wait.json`, `/tmp/p19a/20260308T022902Z_status_all_after_wait.json`, `/tmp/p19a/20260308T022902Z_m_diag_after.json`.
- P19A-REG-03: PASS - sequential rerun on 2026-03-06 to avoid wp-tests DB contention noise: `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php`, `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19ATest.php`, `vendor/bin/phpunit tests/phpunit/BricksAddonPhase15Test.php`.
- P19A-REG-01: PASS - `php -l addons/bricks/bricks-addon.php`, `php -l addons/bricks/bricks-onboarding.php`, `php -l tests/phpunit/BricksAddonPhase19ATest.php`, `vendor/bin/phpunit tests/phpunit/BricksAddonPhase15Test.php`, `vendor/bin/phpunit tests/phpunit/BricksAddonPhase16Test.php`, `vendor/bin/phpunit tests/phpunit/BricksAddonPhase8Test.php`.
- P19A-REG-02: PASS - onboarding recovery patch validation: `php -l addons/bricks/bricks-onboarding.php`, `php -l tests/phpunit/BricksAddonPhase15Test.php`, `vendor/bin/phpunit tests/phpunit/BricksAddonPhase15Test.php`, `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19ATest.php`.

### Exit Criteria Check
- [x] One shared rules profile can be managed on mothership with strict validation.
- [x] Mothership can distribute shared rules to all or selected connected clients.
- [x] Client apply requires valid signed command and produces auditable receipts.
- [x] Required tests pass with tracker evidence.

## Phase 19D - Signed Envelope Transport (Client Pull)
Status: DONE
Owner: Codex
Started: 2026-02-16
Completed: 2026-03-08

### Tasks
- [x] P19D-T1 Envelope contract + storage (Status: DONE)
  - [x] P19D-T1-S1 Freeze envelope schema (id, type, site, payload/signature metadata, state/attempt/timestamp fields) (Status: DONE)
  - [x] P19D-T1-S2 Add queue persistence model with migration/default handling (Status: DONE)
  - [x] P19D-T1-S3 Add query helpers for site/distribution/state filtering (Status: DONE)
- [x] P19D-T2 Mothership enqueue flow (Status: DONE)
  - [x] P19D-T2-S1 Add enqueue endpoint with idempotency + fan-out by targeting mode (Status: DONE)
  - [x] P19D-T2-S2 Wire shared-rules distribution to enqueue envelopes in client-pull mode (Status: DONE)
  - [x] P19D-T2-S3 Preserve correlation/distribution audit context in envelope metadata (Status: DONE)
- [x] P19D-T3 Client pull + lease semantics (Status: DONE)
  - [x] P19D-T3-S1 Add pull endpoint scoped to authenticated site UID (Status: DONE)
  - [x] P19D-T3-S2 Add lease grant/timeout recovery to prevent duplicate workers (Status: DONE)
  - [x] P19D-T3-S3 Enforce strict site-bound envelope visibility (Status: DONE)
- [x] P19D-T4 Client apply runner + ack (Status: DONE)
  - [x] P19D-T4-S1 Verify signed envelope and route command type handler (`shared_rules_apply` initial) (Status: DONE)
  - [x] P19D-T4-S2 Add ack endpoint with idempotent `applied|failed` transitions (Status: DONE)
  - [x] P19D-T4-S3 Persist receipt metadata and error details (Status: DONE)
- [x] P19D-T5 Retry/backoff/dead-letter (Status: DONE)
  - [x] P19D-T5-S1 Add exponential backoff with cap for failed envelopes (Status: DONE)
  - [x] P19D-T5-S2 Add dead-letter on max attempts/expiry (Status: DONE)
  - [x] P19D-T5-S3 Add remediation hints and replay-safe retry controls (Status: DONE - implementation complete; pending live validation in `P19D-TEST-06/07`)
    - [x] P19D-T5-S3-S1 Add duplicate identity detector for connected sites (`base_url` conflict with different `site_uid`) and mark conflicts non-targetable (Status: DONE)
    - [x] P19D-T5-S3-S2 Add mothership Connected Sites row action: `Merge/Deactivate Duplicate Alias` with confirmation and canonical UID selection (Status: DONE)
    - [x] P19D-T5-S3-S3 Add mothership Connected Sites row action: `Reset Linkage` (clear stored command secret/hash + mark `PENDING_INTRO`; no remote execution) (Status: DONE)
    - [x] P19D-T5-S3-S4 Add client-side action: `Reset + Re-run Intro Handshake` to clear local handshake token/state and initiate fresh intro flow (Status: DONE)
    - [x] P19D-T5-S3-S5 Add enqueue/targeting guardrails to skip unhealthy/conflicted sites with explicit diagnostics/error codes (Status: DONE)
    - [x] P19D-T5-S3-S6 Add mothership Connected Sites row action: `Forget Linkage` (soft reset + default-hide from Connected Sites table + typed-UID confirmation + auto-unhide on fresh intro packet re-introduction, then remain `PENDING_INTRO` until verified handshake recovery) (Status: DONE)
    - [x] P19D-T5-S3-S7 Add mothership Connected Sites row action: `Confirm Handshake` for `PENDING_INTRO` rows (calls intro handshake `accept` with idempotency key and refreshes onboarding status/diagnostics) (Status: DONE)
  - [x] P19D-T5-S4 Add identity continuity and alias bridge handling for UID drift (Status: DONE - deterministic alias bridge + assisted merge controls implemented; pending live validation)
    - [x] P19D-T5-S4-S1 Add identity evidence fields (`local_instance_uuid`, `first_seen_at`, `site_sequence_id`, `site_title_host_snapshot`) on mothership/client records (Status: DONE)
    - [x] P19D-T5-S4-S2 Add deterministic `known_alias` mapping (`alias_site_uid -> canonical_site_uid`) in request resolution paths (Status: DONE)
    - [x] P19D-T5-S4-S3 Add Connected Sites row input/action for manual `known_alias` mapping with confirmation (Status: DONE)
    - [x] P19D-T5-S4-S4 Add duplicate reconciliation mode: manual merge default + assisted auto-merge only for deterministic matches and explicit operator confirmation (Status: DONE)
    - [x] P19D-T5-S4-S5 Preserve historical continuity by recording `incoming_site_uid` + `resolved_site_uid` on transport/audit rows (Status: DONE)
  - [x] P19D-T5-S5 Add production self-heal + deterministic preflight guards for handshake/auth drift (Status: DONE)
    - [x] P19D-T5-S5-S1 Auto-downgrade site health to `PENDING_INTRO` when client ack reports `dbvc_bricks_client_envelope_secret_missing`; clear linkage secrets and persist diagnostics (Status: DONE)
    - [x] P19D-T5-S5-S2 Add enqueue/distribute preflight classification (`ready`, `blocked_pending_intro`, `blocked_secret_missing`, `blocked_duplicate_conflict`, `blocked_allow_receive_disabled`) with remediation hints (Status: DONE - `commands/enqueue` now returns classification counts + per-site remediation hints and blocked diagnostics payloads)
    - [x] P19D-T5-S5-S3 Add deterministic canonical reroute for alias targets only when canonical is healthy; otherwise return canonical remediation payload (Status: DONE - duplicate conflict targets now auto-reroute only when recent canonical pull activity + deterministic identity evidence match, and duplicate-group canonical selection now prefers verified/linkage-ready UIDs over pending-intro records)
    - [x] P19D-T5-S5-S4 Add idempotency/header-missing diagnostics and operator payload hints for blocked queue states (Status: DONE - extended structured operator payload hints + diagnostics for non-enqueue command endpoints (`commands/pull` and `commands/ack`) including missing `site_uid`, missing `Idempotency-Key`, and ack validation/auth-context failures)
    - [x] P19D-T5-S5-S5 Streamline onboarding recovery UX by moving client `Reset + Re-run Intro Handshake` action from `First-Time Checklist` to `Configure > Basic settings` and leaving checklist content as guidance-only (Status: DONE - action now renders under Configure -> Basic Settings; checklist remains guidance-only)
- [x] P19D-T6 Operations + diagnostics (Status: DONE)
  - [x] P19D-T6-S1 Add envelope lifecycle diagnostics (`queued|leased|applied|failed|dead_letter`) (Status: DONE)
  - [x] P19D-T6-S2 Add queue status endpoint + operator summary (Status: DONE)
  - [x] P19D-T6-S3 Add transport mode setting (`direct_push|client_pull_envelope`) with migration safeguards (Status: DONE)
- [x] P19D-T7 Validation + gate closure (Status: DONE)
  - [x] P19D-T7-S1 Add automated tests for enqueue/pull/ack/lease/retry/signature paths (Status: DONE)
  - [x] P19D-T7-S2 Run live client-pull drill on mothership/clientA/clientB (`all` + `selected`) (Status: DONE - rerun `timestamp=20260308T021952Z` queued target envelopes for `test_site_a` + `test_site_b` and post-wait status confirms `queued -> applied` in both target modes; non-target forgotten rows were blocked with `site_linkage_forgotten`)
  - [x] P19D-T7-S3 Re-run `P19A-TEST-05` and close Phase 19A live gate (Status: DONE - deferred rerun `timestamp=20260308T022902Z` shows `selected` + `all` envelopes both `applied` for `test_site_a` + `test_site_b`)

### Test Evidence
- P19D-TEST-01: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php` (`test_commands_enqueue_creates_site_bound_envelope`).
- P19D-TEST-02: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php` (`test_shared_rules_distribution_client_pull_mode_enqueues_instead_of_direct_push`).
- P19D-TEST-03: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php` (`test_commands_pull_leases_envelopes_for_site`).
- P19D-TEST-04: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php` (`test_commands_ack_failed_moves_to_dead_letter_at_max_attempts`).
- P19D-TEST-05: PASS - `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php` (2026-02-16) including `test_client_pull_tick_rejects_stale_signature_timestamp_and_acks_failed` and `test_client_pull_tick_rejects_nonce_replay_for_different_envelope_ids`.
- P19D-TEST-05: PASS - remediation/guardrail regression suite rerun on 2026-02-17 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php`) including `test_commands_enqueue_blocks_duplicate_base_url_alias`, `test_connected_sites_reset_linkage_and_merge_alias_actions`, and `test_client_reset_rerun_intro_endpoint_clears_local_state`.
- P19D-TEST-08: PASS - alias continuity regression on 2026-02-17 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php`) including `test_known_alias_mapping_resolves_enqueue_to_canonical_site_uid`.
- P19D-TEST-06: FAIL - live drill executed `timestamp=20260216T065548Z`. `commands/enqueue` succeeded for `selected` (`test_site_a`,`test_site_b`) and queued 2 envelopes; `all` queued 2 envelopes and failed `pluginmediaimporter` with `command_secret_missing`. After triggering both clients, mothership `commands/status` remained `queued` for all four envelopes (no `leased/applied` transitions). Evidence: `/tmp/p19d/20260216T065548Z_enqueue_selected_response.json`, `/tmp/p19d/20260216T065548Z_enqueue_all_response.json`, `/tmp/p19d/20260216T065548Z_status_selected_after_pull.json`, `/tmp/p19d/20260216T065548Z_status_all_after_pull.json`, `/tmp/p19d/20260216T065548Z_clientA_diagnostics.json`, `/tmp/p19d/20260216T065548Z_clientB_diagnostics.json`.
- P19D-TEST-06: FAIL - rerun executed `timestamp=20260216T072320Z`. `commands/enqueue` succeeded for `selected` (queued 2) and partial-failed for `all` (queued 2 + `pluginmediaimporter` `command_secret_missing`), but all four queued envelopes remained `state=queued` across 5 status polls (no `leased/applied` transitions). Evidence: `/tmp/p19d/20260216T072320Z_enqueue_selected_body.json`, `/tmp/p19d/20260216T072320Z_enqueue_selected_response.json`, `/tmp/p19d/20260216T072320Z_enqueue_all_body.json`, `/tmp/p19d/20260216T072320Z_enqueue_all_response.json`, `/tmp/p19d/20260216T072320Z_status_selected_poll_1.json`, `/tmp/p19d/20260216T072320Z_status_selected_poll_5.json`, `/tmp/p19d/20260216T072320Z_status_all_poll_1.json`, `/tmp/p19d/20260216T072320Z_status_all_poll_5.json`, `/tmp/p19d/20260216T072320Z_clientA_diagnostics_after.json`, `/tmp/p19d/20260216T072320Z_clientB_diagnostics_after.json`.
- P19D-TEST-06: FAIL - rerun after mothership transport switch executed `timestamp=20260216T074123Z`. `commands/enqueue` still queued only (`selected`: 2 queued, `all`: 2 queued + `pluginmediaimporter` `command_secret_missing`), with no `leased/applied` transitions across 6 status polls; mothership diagnostics show `command_envelope_queued` events only and client diagnostics still show no `command_*` pull/apply events. Evidence: `/tmp/p19d/20260216T074123Z_enqueue_selected_body.json`, `/tmp/p19d/20260216T074123Z_enqueue_selected_response.json`, `/tmp/p19d/20260216T074123Z_enqueue_all_body.json`, `/tmp/p19d/20260216T074123Z_enqueue_all_response.json`, `/tmp/p19d/20260216T074123Z_status_selected_poll_6.json`, `/tmp/p19d/20260216T074123Z_status_all_poll_6.json`, `/tmp/p19d/20260216T074123Z_m_diagnostics_after.json`, `/tmp/p19d/20260216T074123Z_clientA_diagnostics_after.json`, `/tmp/p19d/20260216T074123Z_clientB_diagnostics_after.json`.
- P19D-TEST-06: FAIL - rerun executed 2026-02-17 (`timestamp=20260217T050955Z`): `selected` queued 2 (`env_bc49e3cc68722bb0`,`env_e2ebe0d3d9c8e4d2`), `all` queued 2 (`env_7befb88991d1e940`,`env_325864f949cc0cea`) + `pluginmediaimporter` `command_secret_missing`; after six polls, `test_site_a` envelopes transitioned `queued -> leased -> failed` with `dbvc_bricks_client_envelope_secret_missing`, while `test_site_b` envelopes stayed `queued` (no lease/apply). Evidence: `/tmp/p19d/20260217T050955Z_enqueue_selected_body.json`, `/tmp/p19d/20260217T050955Z_enqueue_all_body.json`, `/tmp/p19d/20260217T050955Z_enqueue_selected_response.json`, `/tmp/p19d/20260217T050955Z_enqueue_all_response.json`, `/tmp/p19d/20260217T050955Z_status_selected_poll_1.json`, `/tmp/p19d/20260217T050955Z_status_selected_poll_6.json`, `/tmp/p19d/20260217T050955Z_status_all_poll_6.json`, `/tmp/p19d/20260217T050955Z_m_diagnostics_after.json`, `/tmp/p19d/20260217T050955Z_clientA_diagnostics_after.json`, `/tmp/p19d/20260217T050955Z_clientB_diagnostics_after.json`.
- P19D-TEST-06: FAIL - rerun executed 2026-02-17 (`timestamp=20260217T065829Z`): `selected` queued 2 and `all` queued 2 + `pluginmediaimporter` `command_secret_missing`; after six polls, `test_site_a` envelopes transitioned `queued -> leased -> failed` with `dbvc_bricks_client_envelope_secret_missing` while `test_site_b` envelopes remained `queued` (no lease/apply), and no envelopes reached `applied`. Evidence: `/tmp/p19d/20260217T065829Z_enqueue_selected_body.json`, `/tmp/p19d/20260217T065829Z_enqueue_all_body.json`, `/tmp/p19d/20260217T065829Z_enqueue_selected_response.json`, `/tmp/p19d/20260217T065829Z_enqueue_all_response.json`, `/tmp/p19d/20260217T065829Z_status_selected_poll_1.json`, `/tmp/p19d/20260217T065829Z_status_selected_poll_6.json`, `/tmp/p19d/20260217T065829Z_status_all_poll_6.json`, `/tmp/p19d/20260217T065829Z_m_diagnostics_after.json`, `/tmp/p19d/20260217T065829Z_clientA_diagnostics_after.json`, `/tmp/p19d/20260217T065829Z_clientB_diagnostics_after.json`.
- P19D-TEST-06: FAIL - rerun executed 2026-02-17 (`timestamp=20260217T074815Z`): `selected` summary `queued=1 failed=1` (`test_site_a` queued envelope `env_6f724873d3bd8571`; `test_site_b` blocked with `site_uid_conflict_duplicate_base_url` -> canonical `pluginmediaimporter`), and `all` summary `queued=1 failed=4` (`test_site_a` queued envelope `env_ff5a2768cf765b0c`; failures: `test_site_b` duplicate-base-url conflict, `pluginmediaimporter` `site_onboarding_pending_intro`, `1`/`flo_local` `site_allow_receive_disabled`). After six polls, only `test_site_a` envelopes existed and both transitioned to `failed` with `dbvc_bricks_client_envelope_secret_missing`; no `applied` receipts. Evidence: `/tmp/p19d/20260217T074815Z_enqueue_selected_body.json`, `/tmp/p19d/20260217T074815Z_enqueue_all_body.json`, `/tmp/p19d/20260217T074815Z_enqueue_selected_response.json`, `/tmp/p19d/20260217T074815Z_enqueue_all_response.json`, `/tmp/p19d/20260217T074815Z_status_selected_poll_6.json`, `/tmp/p19d/20260217T074815Z_status_all_poll_6.json`, `/tmp/p19d/20260217T074815Z_m_diagnostics_after.json`, `/tmp/p19d/20260217T074815Z_clientA_diagnostics_after.json`, `/tmp/p19d/20260217T074815Z_clientB_diagnostics_after.json`.
- P19D-TEST-06: FAIL - rerun executed 2026-02-17 (`timestamp=20260217T081531Z`): `selected` queued both targets (`test_site_a`,`test_site_b`), and `all` queued both targets while non-target rows failed `site_allow_receive_disabled`. After six polls, `test_site_a` envelopes transitioned to `failed` with `dbvc_bricks_shared_rules_distribution_required` (missing distribution payload context for command apply path), while `test_site_b` envelopes remained `queued` (no lease/apply). Evidence: `/tmp/p19d/20260217T081531Z_enqueue_selected_body.json`, `/tmp/p19d/20260217T081531Z_enqueue_all_body.json`, `/tmp/p19d/20260217T081531Z_enqueue_selected_response.json`, `/tmp/p19d/20260217T081531Z_enqueue_all_response.json`, `/tmp/p19d/20260217T081531Z_status_selected_poll_6.json`, `/tmp/p19d/20260217T081531Z_status_all_poll_6.json`, `/tmp/p19d/20260217T081531Z_m_diagnostics_after.json`, `/tmp/p19d/20260217T081531Z_clientA_diagnostics_after.json`, `/tmp/p19d/20260217T081531Z_clientB_diagnostics_after.json`.
- P19D-TEST-06: FAIL - rerun executed 2026-02-17 (`timestamp=20260217T091900Z`): `selected` queued both targets and `all` queued both targets while non-target rows failed `site_allow_receive_disabled`; by poll-6, `test_site_a` was `leased` and `test_site_b` remained `queued`, then post-wait snapshots showed `test_site_a` reached `dead_letter` (`attempt_count=3`) with `dbvc_bricks_client_envelope_timestamp_invalid` while `test_site_b` remained `queued` (`attempt_count=0`). Evidence: `/tmp/p19d/20260217T091900Z_enqueue_selected_body.json`, `/tmp/p19d/20260217T091900Z_enqueue_all_body.json`, `/tmp/p19d/20260217T091900Z_enqueue_selected_response.json`, `/tmp/p19d/20260217T091900Z_enqueue_all_response.json`, `/tmp/p19d/20260217T091900Z_status_selected_poll_6.json`, `/tmp/p19d/20260217T091900Z_status_all_poll_6.json`, `/tmp/p19d/20260217T091900Z_status_selected_after_wait.json`, `/tmp/p19d/20260217T091900Z_status_all_after_wait.json`, `/tmp/p19d/20260217T091900Z_m_diagnostics_after.json`, `/tmp/p19d/20260217T091900Z_clientA_diagnostics_after.json`, `/tmp/p19d/20260217T091900Z_clientB_diagnostics_after.json`.
- P19D-TEST-06: FAIL - rerun executed 2026-03-06 (`timestamp=20260306T122039Z`): `selected` queued `test_site_a` (`env_62f7dfc847332dc1`) and blocked `test_site_b` as canonical `pluginmediaimporter` `site_allow_receive_disabled`; `all` queued `test_site_a` (`env_6c315c9339b92cb9`) while `test_site_b`, `1`, and `flo_local` were blocked with `site_allow_receive_disabled`. Poll-6 + post-wait snapshots show both queued envelopes reached `state=applied` (`attempt_count=1`) for `test_site_a`. Remaining failure is unresolved canonical-site readiness for the alias target. Evidence: `/tmp/p19d/20260306T122039Z_enqueue_selected_body.json`, `/tmp/p19d/20260306T122039Z_enqueue_all_body.json`, `/tmp/p19d/20260306T122039Z_enqueue_selected_response.json`, `/tmp/p19d/20260306T122039Z_enqueue_all_response.json`, `/tmp/p19d/20260306T122039Z_status_selected_poll_6.json`, `/tmp/p19d/20260306T122039Z_status_all_poll_6.json`, `/tmp/p19d/20260306T122039Z_status_selected_after_wait.json`, `/tmp/p19d/20260306T122039Z_status_all_after_wait.json`, `/tmp/p19d/20260306T122039Z_m_diagnostics_after.json`, `/tmp/p19d/20260306T122039Z_clientA_status_auth_check.json`, `/tmp/p19d/20260306T122039Z_clientB_status_auth_check.json`.
- P19D-TEST-06: FAIL - rerun executed 2026-03-06 (`timestamp=20260306T132634Z`): `selected` queued `test_site_a` (`env_8e5829b361da72c3`) and blocked `test_site_b` by canonical `pluginmediaimporter` `site_onboarding_pending_intro`; `all` queued `test_site_a` (`env_bdca9d4ba62b94ee`) while `test_site_b` was blocked by canonical pending-intro and `1`/`flo_local` was blocked `site_allow_receive_disabled`. Poll-6 + post-wait snapshots show both queued envelopes reached `state=applied` (`attempt_count=1`) for `test_site_a`. Remaining failure is unresolved canonical onboarding/linkage readiness for the alias target. Evidence: `/tmp/p19d/20260306T132634Z_enqueue_selected_body.json`, `/tmp/p19d/20260306T132634Z_enqueue_all_body.json`, `/tmp/p19d/20260306T132634Z_enqueue_selected_response.json`, `/tmp/p19d/20260306T132634Z_enqueue_all_response.json`, `/tmp/p19d/20260306T132634Z_status_selected_poll_6.json`, `/tmp/p19d/20260306T132634Z_status_all_poll_6.json`, `/tmp/p19d/20260306T132634Z_status_selected_after_wait.json`, `/tmp/p19d/20260306T132634Z_status_all_after_wait.json`, `/tmp/p19d/20260306T132634Z_m_diagnostics_after.json`, `/tmp/p19d/20260306T132634Z_clientA_diagnostics_after.json`, `/tmp/p19d/20260306T132634Z_clientB_diagnostics_after.json`.
- P19D-TEST-06: PASS - rerun executed 2026-03-08 (`timestamp=20260308T021952Z`): `selected` queued 2 (`env_39cae09a93847c87`, `env_7a355f95f0b32ea5`) under `dist_cmdq_8c1e3d09e047f47e`; `all` queued 2 (`env_66db7a3fe2274d35`, `env_2f4360e7b1c3869d`) under `dist_cmdq_db29582047d870e1` and blocked forgotten non-target rows (`1`,`flo_local`) with `site_linkage_forgotten`; poll + post-wait snapshots show all target envelopes reached `state=applied` (`attempt_count=1`). Evidence: `/tmp/p19d/20260308T021952Z_enqueue_selected_body.json`, `/tmp/p19d/20260308T021952Z_enqueue_all_body.json`, `/tmp/p19d/20260308T021952Z_enqueue_selected_response.json`, `/tmp/p19d/20260308T021952Z_enqueue_all_response.json`, `/tmp/p19d/20260308T021952Z_distribution_ids.txt`, `/tmp/p19d/20260308T021952Z_status_selected_poll_6.json`, `/tmp/p19d/20260308T021952Z_status_all_poll_6.json`, `/tmp/p19d/20260308T021952Z_status_selected_after_wait.json`, `/tmp/p19d/20260308T021952Z_status_all_after_wait.json`, `/tmp/p19d/20260308T021952Z_m_diagnostics_after.json`.
- P19D-TEST-07: FAIL - deferred `P19A-TEST-05` rerun under corrected transport (`timestamp=20260216T074241Z`) still does not pass: responses are queued-only (`applied=0`) and `pluginmediaimporter` fails `command_secret_missing` under `all`. Evidence: `/tmp/p19a/20260216T074241Z_dist_all.json`, `/tmp/p19a/20260216T074241Z_dist_selected.json`, `/tmp/p19a/20260216T074241Z_m_diag_after.json`.
- P19D-TEST-07: FAIL - deferred `P19A-TEST-05` rerun on 2026-02-17 (`timestamp=20260217T051541Z`) still does not pass: `selected`/`all` run in `client_pull_envelope`, `pluginmediaimporter` fails `command_secret_missing`, `test_site_a` envelopes transition to `failed` with `dbvc_bricks_client_envelope_secret_missing`, `test_site_b` envelopes remain `queued`, and `applied=0`. Evidence: `/tmp/p19a/20260217T051541Z_dist_selected.json`, `/tmp/p19a/20260217T051541Z_dist_all.json`, `/tmp/p19a/20260217T051541Z_status_selected_poll_6.json`, `/tmp/p19a/20260217T051541Z_status_all_poll_6.json`, `/tmp/p19a/20260217T051541Z_m_diag_after.json`, `/tmp/p19a/20260217T051541Z_clientA_diag_after.json`, `/tmp/p19a/20260217T051541Z_clientB_diag_after.json`.
- P19D-TEST-07: FAIL - deferred `P19A-TEST-05` rerun on 2026-02-17 (`timestamp=20260217T065829Z`) still does not pass: `selected`/`all` run in `client_pull_envelope`, `pluginmediaimporter` fails `command_secret_missing`, `test_site_a` envelopes transition to `failed` with `dbvc_bricks_client_envelope_secret_missing`, `test_site_b` envelopes remain `queued`, and `applied=0`. Evidence: `/tmp/p19a/20260217T065829Z_dist_selected.json`, `/tmp/p19a/20260217T065829Z_dist_all.json`, `/tmp/p19a/20260217T065829Z_status_selected_poll_6.json`, `/tmp/p19a/20260217T065829Z_status_all_poll_6.json`, `/tmp/p19a/20260217T065829Z_m_diag_after.json`, `/tmp/p19a/20260217T065829Z_clientA_diag_after.json`, `/tmp/p19a/20260217T065829Z_clientB_diag_after.json`.
- P19D-TEST-07: FAIL - deferred `P19A-TEST-05` rerun on 2026-02-17 (`timestamp=20260217T071905Z`) still does not pass: `selected`/`all` run in `client_pull_envelope`, `pluginmediaimporter` fails `command_secret_missing`, `test_site_a` envelopes transition to `failed` with `dbvc_bricks_client_envelope_secret_missing`, `test_site_b` envelopes remain `queued`, and `applied=0`. Evidence: `/tmp/p19a/20260217T071905Z_dist_selected.json`, `/tmp/p19a/20260217T071905Z_dist_all.json`, `/tmp/p19a/20260217T071905Z_status_selected_poll_6.json`, `/tmp/p19a/20260217T071905Z_status_all_poll_6.json`, `/tmp/p19a/20260217T071905Z_m_diag_after.json`, `/tmp/p19a/20260217T071905Z_clientA_diag_after.json`, `/tmp/p19a/20260217T071905Z_clientB_diag_after.json`.
- P19D-TEST-07: FAIL - deferred `P19A-TEST-05` rerun on 2026-02-17 (`timestamp=20260217T081531Z`) still does not pass: `selected`/`all` run in `client_pull_envelope` and queue both targets, but `applied=0`; post-wait status keeps `test_site_a` in `leased` (attempt_count incrementing) and `test_site_b` in `queued`, while client diagnostics repeatedly show `dbvc_bricks_client_envelope_timestamp_invalid` for `test_site_a`. Evidence: `/tmp/p19a/20260217T081531Z_dist_selected.json`, `/tmp/p19a/20260217T081531Z_dist_all.json`, `/tmp/p19a/20260217T081531Z_status_selected_poll_6.json`, `/tmp/p19a/20260217T081531Z_status_all_poll_6.json`, `/tmp/p19a/20260217T081531Z_status_selected_after_wait.json`, `/tmp/p19a/20260217T081531Z_status_all_after_wait.json`, `/tmp/p19a/20260217T081531Z_m_diag_after.json`, `/tmp/p19a/20260217T081531Z_clientA_diag_after.json`, `/tmp/p19a/20260217T081531Z_clientB_diag_after.json`.
- P19D-TEST-07: FAIL - deferred `P19A-TEST-05` rerun on 2026-02-17 (`timestamp=20260217T091900Z`) still does not pass: `selected`/`all` run in `client_pull_envelope` and queue both targets, but `applied=0`; post-wait status keeps `test_site_a` in `leased` with `last_error_code=dbvc_bricks_client_envelope_timestamp_invalid` (`attempt_count=6`) and `test_site_b` in `queued` (`attempt_count=0`). Evidence: `/tmp/p19a/20260217T091900Z_dist_selected.json`, `/tmp/p19a/20260217T091900Z_dist_all.json`, `/tmp/p19a/20260217T091900Z_status_selected_poll_6.json`, `/tmp/p19a/20260217T091900Z_status_all_poll_6.json`, `/tmp/p19a/20260217T091900Z_status_selected_after_wait.json`, `/tmp/p19a/20260217T091900Z_status_all_after_wait.json`, `/tmp/p19a/20260217T091900Z_m_diag_after.json`, `/tmp/p19a/20260217T091900Z_clientA_diag_after.json`, `/tmp/p19a/20260217T091900Z_clientB_diag_after.json`.
- P19D-TEST-07: FAIL - deferred `P19A-TEST-05` rerun on 2026-03-06 (`timestamp=20260306T121455Z`) improves queue execution (`test_site_a` envelopes `env_b2547003332f30b7` and `env_fff2b9a282ea61e9` reach `state=applied`), but selected/all still fail overall because canonical `pluginmediaimporter` returns `command_secret_missing` for the alias target path. Evidence: `/tmp/p19a/20260306T121455Z_dist_selected.json`, `/tmp/p19a/20260306T121455Z_dist_all.json`, `/tmp/p19a/20260306T121455Z_status_selected_poll_6.json`, `/tmp/p19a/20260306T121455Z_status_all_poll_6.json`, `/tmp/p19a/20260306T121455Z_status_selected_after_wait.json`, `/tmp/p19a/20260306T121455Z_status_all_after_wait.json`, `/tmp/p19a/20260306T121455Z_m_diag_after.json`, `/tmp/p19a/20260306T121455Z_clientA_diag_after.json`, `/tmp/p19a/20260306T121455Z_clientB_diag_after.json`.
- P19D-TEST-07: FAIL - deferred `P19A-TEST-05` rerun on 2026-03-06 (`timestamp=20260306T133224Z`) keeps improved queue execution (`test_site_a` envelopes `env_2900b8fec68eded9` and `env_d22952f23de88c77` reach `state=applied`), but selected/all still fail overall because canonical `pluginmediaimporter` returns `command_secret_missing` for the alias target path. Evidence: `/tmp/p19a/20260306T133224Z_dist_selected.json`, `/tmp/p19a/20260306T133224Z_dist_all.json`, `/tmp/p19a/20260306T133224Z_status_selected_poll_6.json`, `/tmp/p19a/20260306T133224Z_status_all_poll_6.json`, `/tmp/p19a/20260306T133224Z_status_selected_after_wait.json`, `/tmp/p19a/20260306T133224Z_status_all_after_wait.json`, `/tmp/p19a/20260306T133224Z_m_diag_after.json`, `/tmp/p19a/20260306T133224Z_clientA_diag_after.json`, `/tmp/p19a/20260306T133224Z_clientB_diag_after.json`.
- P19D-TEST-07: PASS - deferred `P19A-TEST-05` rerun on 2026-03-08 (`timestamp=20260308T022902Z`) passes under `client_pull_envelope`: `selected` queued 2 (`env_01bb90144267d866`, `env_cfb56fb2e7303c3d`) and `all` queued 2 (`env_7a8e6c08426be7fa`, `env_e3d4aacff2c5d32d`) with no enqueue failures; poll + post-wait status snapshots show all envelopes `state=applied` (`attempt_count=1`) for `test_site_a` and `test_site_b`. Evidence: `/tmp/p19a/20260308T022902Z_dist_selected_body.json`, `/tmp/p19a/20260308T022902Z_dist_all_body.json`, `/tmp/p19a/20260308T022902Z_dist_selected.json`, `/tmp/p19a/20260308T022902Z_dist_all.json`, `/tmp/p19a/20260308T022902Z_distribution_ids.txt`, `/tmp/p19a/20260308T022902Z_status_selected_poll_6.json`, `/tmp/p19a/20260308T022902Z_status_all_poll_6.json`, `/tmp/p19a/20260308T022902Z_status_selected_after_wait.json`, `/tmp/p19a/20260308T022902Z_status_all_after_wait.json`, `/tmp/p19a/20260308T022902Z_m_diag_after.json`.
- P19D-TEST-09: PASS - assisted merge deterministic safety regression on 2026-02-17 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php`) including `test_assisted_merge_uses_deterministic_candidate_token`.
- P19D-TEST-10: PASS - secret-missing recovery regression on 2026-02-17 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php`) including `test_commands_ack_secret_missing_marks_site_pending_intro_for_recovery`.
- P19D-TEST-11: PASS - enqueue payload normalization regression on 2026-02-17 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php`) including `test_commands_enqueue_refresh_shared_rules_builds_distribution_payload`.
- P19D-TEST-12: PASS - lease signature refresh + pull activity regression on 2026-02-17 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php`) including `test_commands_pull_refreshes_signature_for_lease_and_tracks_pull_activity`.
- P19D-TEST-13: PASS - deterministic duplicate conflict reroute + preflight classification regression on 2026-02-17 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php`) including `test_commands_enqueue_auto_reroutes_duplicate_conflict_to_recent_pull_canonical` and updated `test_commands_enqueue_blocks_duplicate_base_url_alias`.
- P19D-TEST-14: PASS - duplicate canonical preference regression on 2026-03-06 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php`) including `test_duplicate_conflict_prefers_verified_linked_canonical_over_pending_intro`.
- P19D-TEST-15: PASS - forget-linkage recovery regression on 2026-03-07 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php`) including `test_connected_sites_forget_linkage_hides_row_and_allows_recovery_after_verified_handshake` (covers hidden-row recovery on fresh intro packet and final verified-handshake recovery).
- P19D-TEST-16: PASS - non-enqueue operator diagnostics regression on 2026-03-08 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19DTest.php`) including `test_commands_pull_missing_site_uid_returns_operator_hints_and_diagnostic`, `test_commands_ack_missing_idempotency_returns_operator_hints_and_diagnostic`, and `test_commands_ack_invalid_payload_returns_operator_hints_and_diagnostic`.
- P19D-TEST-16: PASS - live mothership smoke validation on 2026-03-08 (`timestamp=20260308T030946Z`) confirms `commands/pull`/`commands/ack` rejection payloads include `classification` + `remediation_hint` + `endpoint` + `reason`, and diagnostics stream records `command_pull_rejected`/`command_ack_rejected` with matching operator hints. Evidence: `/tmp/p19d/20260308T030946Z_s54_smoke/pull_missing_site_uid.json`, `/tmp/p19d/20260308T030946Z_s54_smoke/ack_missing_idempotency.json`, `/tmp/p19d/20260308T030946Z_s54_smoke/ack_invalid_payload.json`, `/tmp/p19d/20260308T030946Z_s54_smoke/mothership_diagnostics_120.json`.

### Exit Criteria Check
- [x] Shared-rules transport succeeds without mothership direct DNS reachability to client hosts.
- [x] Envelope delivery lifecycle is auditable and retry-safe.
- [x] Dead-letter handling and operator diagnostics are available.
- [x] `P19A-TEST-05` passes after transport switch.
- [x] UID drift recovery preserves package/apply/transport history continuity via canonical + alias mapping.

## Phase 19B - Client Protected Artifact Variants
Status: DONE
Owner: Codex
Started: 2026-03-08
Completed: 2026-03-08

### Tasks
- [x] P19B-T1 Protected variant data model + API (Status: DONE)
  - [x] P19B-T1-S1 Define protected variant schema (`artifact_uid`, `artifact_type`, `label`, `reason`, actor, timestamps, scope) (Status: DONE)
  - [x] P19B-T1-S2 Add client CRUD endpoints with capability checks + nonce-safe UI actions (Status: DONE)
  - [x] P19B-T1-S3 Add uniqueness/dedupe rule (`artifact_uid` + `scope`) and audit events (`created|updated_reason|removed`) (Status: DONE)
- [x] P19B-T2 Client Protected Artifacts tab (Status: DONE)
  - [x] P19B-T2-S1 Add new `Protected Artifacts` tab for `client` role only (Status: DONE)
  - [x] P19B-T2-S2 Add list/create/remove UI with required reason and confirmation controls (Status: DONE)
  - [x] P19B-T2-S3 Add Differences integration to mark/unmark selected artifact as protected (Status: DONE)
  - [x] P19B-T2-S4 Ensure read-only mode blocks mutating protected-variant actions (Status: DONE)
- [x] P19B-T3 Payload visibility annotations (Status: DONE)
  - [x] P19B-T3-S1 Add read-only protected-variant indicators in drift/apply/package payloads (Status: DONE)
  - [x] P19B-T3-S2 Add tests confirming annotation presence without apply-behavior changes (Status: DONE)

### Test Evidence
- P19B-TEST-01: PASS - protected variant CRUD + authorization tests on 2026-03-08 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19BTest.php`) including dedupe (`artifact_uid+scope`), client-role enforcement, read-only mutation blocks, delete confirmation guard, idempotent replay, and audit-event emission coverage.
- P19B-TEST-02: PASS - Protected Artifacts tab rendering/interaction wiring coverage on 2026-03-08 via `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19BTest.php` and `vendor/bin/phpunit tests/phpunit/BricksAddonPhase8Test.php`; includes assertions for client-only tab visibility plus create/remove control IDs, JS handlers, and protected-variant UI event keys.
- P19B-TEST-03: PASS - Differences mark/unmark protected-control integration coverage on 2026-03-08 via `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19BTest.php`, including client-only render checks for mark/unmark controls and JS handler wiring (`test_differences_panel_protected_mark_unmark_controls_render_for_client_only`).
- P19B-TEST-04: PASS - read-only mode UI gating coverage on 2026-03-08 via `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19BTest.php`, including disabled mutating controls in Protected Artifacts and Differences panels plus read-only mutation-guard messaging (`test_read_only_mode_disables_protected_variant_mutating_controls`).
- P19B-TEST-05: PASS - protected-variant payload annotation coverage on 2026-03-08 via `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19BTest.php`, including drift scan/compare, apply (dry-run + live apply behavior unchanged), and packages list/get/pull-latest response assertions (`test_drift_apply_and_package_payloads_include_protected_variant_annotations_without_behavior_change`).

### Exit Criteria Check
- [x] Client can create/list/remove protected variants from a dedicated tab.
- [x] Protected variant records are auditable and deduplicated.
- [x] Drift/apply/package contexts show protected annotations without changing apply semantics.
- [x] Required tests pass with tracker evidence.

## Phase 19C - Mothership Visibility + Cross-Site Operations Drill
Status: IN_PROGRESS
Owner: Codex
Started: 2026-04-15
Completed: n/a

### Tasks
- [x] P19C-T1 Mothership visibility for protected variants (Status: DONE)
  - [x] P19C-T1-S1 Add connected-client summary with protected counts by artifact class (Status: DONE)
  - [x] P19C-T1-S2 Add client drill-down list of protected variants (`bricks_template`, `global_classes`, etc) (Status: DONE)
  - [x] P19C-T1-S3 Add deep-link + copy-link helpers to client `DBVC -> Bricks -> Protected Artifacts` tab (Status: DONE)
  - [x] P19C-T1-S4 Add data-freshness indicator (last synced/seen timestamp) per client (Status: DONE)
- [ ] P19C-T2 End-to-end governance and evidence closure (Status: NOT_STARTED)
  - [ ] P19C-T2-S1 Run full drill: shared rules distribution + protected variant visibility across mothership/clientA/clientB (Status: NOT_STARTED)
  - [ ] P19C-T2-S2 Capture timestamps, command logs, receipts, diagnostics traces, and UI evidence (Status: NOT_STARTED)
  - [ ] P19C-T2-S3 Write completion note and close all Phase 19* statuses (Status: NOT_STARTED)

### Test Evidence
- P19C-TEST-01: PASS - mothership aggregation visibility tests on 2026-04-15 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19CTest.php`) including latest-package-per-site aggregation, protected counts by artifact type/scope, freshness fields, and zero-protected connected-client rows.
- P19C-TEST-02: PASS - deep-link/copy-link rendering tests on 2026-04-15 (`vendor/bin/phpunit tests/phpunit/BricksAddonPhase19CTest.php`) including mothership Packages panel controls, fleet endpoint config, `Open Protected Artifacts`, and `Copy Link` wiring; adjacent regression coverage also passed via `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19BTest.php tests/phpunit/BricksAddonPhase13Test.php`.
- P19C-TEST-03: NOT_RUN - full live cross-site drill evidence.

### Exit Criteria Check
- [x] Mothership can identify clients with protected variants and inspect details.
- [x] Operators have usable deep-link/copy-link navigation to client protected tab paths.
- [ ] Cross-site drill evidence is complete and auditable.
- [ ] Required tests pass with tracker evidence.

## Phase 20 - Bricks Font and Icon Asset Portability
Status: IN_PROGRESS
Owner: Codex
Created: 2026-06-24
Completed: n/a

### Discovery Baseline
- Current official Bricks release checked 2026-06-24: Bricks `2.3.8` (released 2026-06-23). LocalWP Bricks install at `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/themes/bricks` is `2.3.7`.
- Local code confirms Bricks `2.0+` Font Manager/Icon Manager storage names:
  - fonts: `bricks_fonts` posts, `bricks_font_faces` post meta, derived option `bricks_font_face_rules`, media attachment IDs in face variants.
  - icons: `bricks_icon_sets`, `bricks_custom_icons`, `bricks_disabled_icon_sets`; custom icons reference SVG attachment IDs and URLs.
- Local DB probe evidence:
  - `bricks_fonts_posts=8`.
  - `bricks_icon_sets=1 array`.
  - `bricks_custom_icons=4 array`.
  - `bricks_disabled_icon_sets=NULL`.
  - `bricks_font_face_rules=5615 string`.
  - matching font/SVG attachments were found in uploads.

### Tasks
- [ ] P20-T0 Contract freeze + live schema verification (Status: IN_PROGRESS)
  - [ ] P20-T0-S1 Add runtime probe for Bricks version, font/icon storage shapes, media refs, mime types, and multisite constants. (Status: NOT_STARTED)
  - [x] P20-T0-S2 Freeze canonical font domain shape for `bricks_fonts`, `bricks_font_faces`, media refs, and derived CSS metadata. (Status: DONE - implemented in normalizer/package payload)
  - [x] P20-T0-S3 Freeze canonical icon domain shape for icon sets, custom icons, disabled sets, and SVG media refs. (Status: DONE - implemented in normalizer/package payload)
  - [ ] P20-T0-S4 Define match/collision policies for family names, set IDs, icon IDs, and asset checksums. (Status: IN_PROGRESS - family/set matching implemented; add-only apply blocks existing font family/set IDs; explicit replacement policy still open)
- [ ] P20-T1 Package schema + media manifest (Status: IN_PROGRESS)
  - [x] P20-T1-S1 Add media-backed `custom_fonts` and `icon_collections` domains to the portability registry. (Status: DONE)
  - [x] P20-T1-S2 Extend packages with checksummed media files and attachment reference metadata. (Status: DONE)
  - [ ] P20-T1-S3 Enforce allowed font/SVG mime types and import sanitization. (Status: IN_PROGRESS - extension allowlist, package path validation, checksum verification, SVG scriptable-content rejection, and filterable size limits implemented; broader MIME policy still open)
  - [ ] P20-T1-S4 Treat `bricks_font_face_rules` as derived/backup-only and regenerate it after apply. (Status: IN_PROGRESS - registry marks derived/backup-only and add-only font apply clears generated CSS; Bricks-compatible regeneration/live verification still open)
- [ ] P20-T2 Export implementation (Status: IN_PROGRESS)
  - [x] P20-T2-S1 Export custom font posts/meta plus referenced font media. (Status: DONE)
  - [x] P20-T2-S2 Export icon options plus referenced SVG media. (Status: DONE)
  - [ ] P20-T2-S3 Emit dependency metadata for selected domains that reference custom fonts/icons. (Status: NOT_STARTED)
  - [ ] P20-T2-S4 Reject missing, unchecksummed, oversized, or disallowed media files. (Status: IN_PROGRESS - checksums/path/extension validation and filterable size limits implemented; broader export-side rejection coverage still open)
- [ ] P20-T3 Import, diff, and dependency review (Status: IN_PROGRESS)
  - [x] P20-T3-S1 Normalize font/icon package payloads into review rows with media fingerprints and current-site freshness. (Status: DONE)
  - [x] P20-T3-S2 Remap `custom_font_{source_post_id}` typography references to target font IDs. (Status: DONE - add-only apply builds a source-to-target font value map and rewrites selected mutated option payloads; coverage includes global classes, settings, theme styles, and components)
  - [ ] P20-T3-S3 Preserve custom icon IDs and set IDs when safe, and block conflicting IDs without explicit review. (Status: IN_PROGRESS - add-only apply preserves incoming icon/set IDs and blocks existing set IDs; replacement and icon-level collision policy still open)
  - [ ] P20-T3-S4 Add review warnings for missing media, unsupported mime types, SVG sanitize failures, and unresolved references. (Status: IN_PROGRESS - media-backed add-only warnings and apply-time validation implemented; richer pre-apply row warnings still open)
  - [x] P20-T3-S5 Keep target-only fonts/icons by default. (Status: DONE - media-backed domains expose add-only actions for new incoming objects and keep/skip for existing or target-only rows)
- [ ] P20-T4 Apply, rollback, and asset lifecycle safety (Status: IN_PROGRESS)
  - [ ] P20-T4-S1 Create or update target font posts/media before applying dependent settings domains. (Status: IN_PROGRESS - add-only target font post/media creation implemented; update/replacement remains open)
  - [ ] P20-T4-S2 Create or update SVG attachments before writing icon manager options. (Status: IN_PROGRESS - add-only SVG attachment create/reuse and icon option writes implemented; update/replacement remains open)
  - [x] P20-T4-S3 Regenerate or clear Bricks custom font CSS after font apply. (Status: DONE - add-only apply clears `bricks_font_face_rules` so Bricks can regenerate)
  - [ ] P20-T4-S4 Extend rollback snapshots across options, font posts/meta, attachments, and created files. (Status: IN_PROGRESS - backup records created font posts/attachments and rollback removes them; corrupt media apply blockers verify no partial posts/options are left behind; partial-failure and live file-reference hardening remain open)
  - [ ] P20-T4-S5 Make repeated applies idempotent and avoid duplicate identical attachments. (Status: IN_PROGRESS - checksum-based attachment reuse is implemented and covered; repeated font/icon object applies still block on collisions until merge/reuse policy exists)
- [ ] P20-T5 Admin UI integration (Status: NOT_STARTED)
  - [ ] P20-T5-S1 Add `Custom Fonts` and `Icon Collections` domain cards with media counts and high-risk warnings. (Status: NOT_STARTED)
  - [ ] P20-T5-S2 Add detail views for font variants and custom icons with checksums, filenames, mime types, and mapped target IDs. (Status: NOT_STARTED)
  - [ ] P20-T5-S3 Add media preflight summary for create/reuse/replace/skip actions. (Status: NOT_STARTED)
  - [ ] P20-T5-S4 Add apply receipts for attachments, font reference rewrites, regenerated CSS, and icon collision decisions. (Status: NOT_STARTED)
- [ ] P20-T6 Validation + live evidence (Status: IN_PROGRESS)
  - [ ] P20-T6-S1 Add PHPUnit fixtures for fonts, media-backed font variants, icon sets, SVG icons, disabled sets, collisions, and missing-media blockers. (Status: IN_PROGRESS - font/icon media apply fixture and missing-media blockers added; collision cases still open)
  - [x] P20-T6-S2 Add font ID remapping coverage across global classes, theme styles, components, and settings domains. (Status: DONE)
  - [ ] P20-T6-S3 Add SVG sanitize, checksum mismatch, duplicate media reuse, and rollback regression coverage. (Status: IN_PROGRESS - SVG sanitize, checksum mismatch, duplicate attachment reuse, size-limit, invalid-ref, and rollback regression coverage added; partial-failure rollback cases still open)
  - [ ] P20-T6-S4 Run live two-site export/import/apply/rollback drill with frontend and builder data checks. (Status: NOT_STARTED)

### Test Evidence
- P20-TEST-02/P20-TEST-03/P20-TEST-04/P20-TEST-05/P20-TEST-06/P20-TEST-07/P20-TEST-08/P20-TEST-09 partial: PASS - `vendor/bin/phpunit --filter BricksPortabilityManagerTest` on 2026-06-24 (`20 tests, 403 assertions`). Coverage includes media-backed font and icon export package files, `media.json`, package media paths, import review rows, add-only apply actions, font attachment creation, SVG attachment creation, icon option writes, custom font remapping across global classes/settings/theme styles/components, generated font CSS clearing, rollback cleanup for created posts/attachments, missing packaged media, checksum mismatch, invalid package refs, unsafe SVG rejection, filterable size limits, and checksum-based duplicate attachment reuse.
- P20-TEST-01, P20-TEST-04 remaining import-stage package rejection cases, P20-TEST-06/P20-TEST-07/P20-TEST-09 remaining replacement/partial-failure cases, and P20-TEST-10: NOT_RUN/OPEN - runtime probe fixture, replacement/collision paths, partial-failure rollback, broader export-side media rejection proof, and live two-site drill are still pending.

### Exit Criteria Check
- [x] Operators can export and import Bricks custom fonts and custom icon collections through Settings Portability.
- [x] Packages include only explicitly referenced and checksummed media assets.
- [ ] Font and icon apply is reviewable, idempotent, rollback-safe, and non-destructive toward target-only assets. (Partial: add-only new incoming objects, corrupt media blockers, rollback cleanup, and checksum attachment reuse covered by PHPUnit; replacement/collision/live hardening remains open.)
- [x] Custom font IDs are remapped in selected Bricks settings domains so typography references resolve on the target site.
- [ ] Required automated tests and live LocalWP drill evidence are complete.

## Phase 21 - Bricks Template Entity Portability
Status: IN_PROGRESS
Owner: Codex
Created: 2026-06-24
Completed: n/a

### Discovery Baseline
- Local Bricks `2.3.7` code confirms templates are stored as `bricks_template` posts with:
  - template type meta `_bricks_template_type`;
  - template settings meta `_bricks_template_settings`;
  - Bricks element data meta `_bricks_page_header_2`, `_bricks_page_content_2`, `_bricks_page_footer_2`;
  - template taxonomies `template_tag` and `template_bundle`.
- `bricks_templates` is entity-backed, not option-backed. It must use post/meta/taxonomy snapshots and rollback state, not raw option replacement.
- Embedded template payload references to media IDs, arbitrary post IDs, and nested template IDs are still unresolved in the initial slice and are surfaced as review warnings/follow-up work.

### Tasks
- [x] P21-T0 Contract and storage shape (Status: DONE)
  - [x] P21-T0-S1 Freeze canonical template payload shape for post fields, Bricks meta, area meta, and template taxonomies. (Status: DONE)
  - [x] P21-T0-S2 Define match policy as template type + slug, then template type + title; source post IDs are excluded from fingerprints. (Status: DONE)
  - [x] P21-T0-S3 Add warnings for embedded references not remapped in the first slice. (Status: DONE)
- [x] P21-T1 Package/import/diff (Status: DONE)
  - [x] P21-T1-S1 Add `bricks_templates` as an entity-backed high-risk registry domain. (Status: DONE)
  - [x] P21-T1-S2 Export template rows into the domains package contract. (Status: DONE)
  - [x] P21-T1-S3 Import entity-backed package domain JSON through the session model. (Status: DONE)
  - [x] P21-T1-S4 Surface template add/replace/keep/skip review actions while keeping target-only templates by default. (Status: DONE)
- [ ] P21-T2 Apply and rollback (Status: IN_PROGRESS)
  - [x] P21-T2-S1 Create incoming templates with Bricks meta and template tags/bundles. (Status: DONE)
  - [x] P21-T2-S2 Replace matched templates while preserving target post IDs. (Status: DONE)
  - [x] P21-T2-S3 Extend backups with entity state for created/replaced template posts and rollback behavior. (Status: DONE)
  - [x] P21-T2-S4 Remap imported custom font values inside template payloads when `custom_fonts` is applied in the same session. (Status: DONE - implemented; dedicated template+font PHPUnit still open)
- [ ] P21-T3 Hardening and dependency mapping (Status: NOT_STARTED)
  - [ ] P21-T3-S1 Remap embedded media/attachment references in template element payloads. (Status: NOT_STARTED)
  - [ ] P21-T3-S2 Remap nested Bricks template references and warn/block unresolved dependencies. (Status: NOT_STARTED)
  - [ ] P21-T3-S3 Define collision policy for same slug/title across different template types and WordPress-generated unique slugs. (Status: IN_PROGRESS - initial type+slug/type+title matching implemented)
  - [ ] P21-T3-S4 Add partial-failure rollback coverage across mixed option/media/template applies. (Status: NOT_STARTED)
- [ ] P21-T4 Admin UI and live evidence (Status: NOT_STARTED)
  - [ ] P21-T4-S1 Add template-specific review detail summaries for type, tags, bundles, element counts, and unresolved references. (Status: NOT_STARTED)
  - [ ] P21-T4-S2 Add apply receipts for created/replaced templates and taxonomy assignments. (Status: NOT_STARTED)
  - [ ] P21-T4-S3 Run live two-site template export/import/apply/rollback drill with builder and frontend checks. (Status: NOT_STARTED)

### Test Evidence
- P21-TEST-02/P21-TEST-03/P21-TEST-04 partial: PASS - `vendor/bin/phpunit --filter test_bricks_templates_export_import_apply_and_rollback` on 2026-06-24 (`1 test, 44 assertions`). Coverage includes template domain package export/import, review rows for changed/new/target-only templates, add/replace apply, Bricks template type/settings/area meta writes, tag/bundle assignments, rollback restore for replaced templates, and rollback delete for created templates.
- P21 regression suite: PASS - `vendor/bin/phpunit --filter BricksPortabilityManagerTest` on 2026-06-24 (`21 tests, 447 assertions`).
- P21-TEST-01, P21-TEST-05, P21-TEST-06, P21-TEST-07: OPEN - registry metadata assertion, dedicated template custom-font remap proof, embedded media/nested-template dependency tests, and live LocalWP two-site evidence remain pending.

### Exit Criteria Check
- [x] Operators can select `Bricks Templates` in Settings Portability exports and imports.
- [x] Template rows are reviewed at object granularity and target-only templates are kept by default.
- [x] Add and replace apply paths are rollback-safe for template posts, Bricks template meta, and template tag/bundle assignments in PHPUnit.
- [ ] Embedded media, arbitrary post IDs, and nested template IDs are not silently hydrated; remapping remains a planned hardening item.
- [ ] Required automated tests and live LocalWP drill evidence are complete.

## Phase 22 - Bricks Template Reference Hydration and Dependency Safety
Status: IN_PROGRESS
Owner: Codex
Created: 2026-06-24
Completed: n/a

### Scope
Close the known Phase 21 template boundaries: embedded media/attachment references, nested Bricks template references, deterministic post/entity references, slug collision safety, mixed-domain rollback, and live builder/frontend evidence.

### Reference Handling Policy
- Media references must be backed by package media checksums, target attachment create/reuse, payload ID/URL rewrite, and rollback state.
- Nested template references must resolve through selected incoming templates or existing matched target templates; required unresolved references block apply.
- Post/term/entity references may be remapped only through DBVC UID or exact same object type plus stable slug/path. Otherwise they are preserved with warning or blocked by severity.
- Unknown numeric IDs are never remapped automatically; they are recorded with payload path and confidence for review.

### Tasks
- [ ] P22-T0 Contract freeze + Bricks control discovery (Status: IN_PROGRESS)
  - [ ] P22-T0-S1 Re-scan Bricks source and fixture payloads for media, gallery, video, icon, nested template, query, dynamic data, and post picker control keys. (Status: IN_PROGRESS - fixture-driven image/gallery/background/video media and nested template keys implemented; broader live Bricks source audit remains open)
  - [x] P22-T0-S2 Freeze dependency descriptor schema with ref type, payload path, consumer template key, confidence, required flag, strategy, target ID, and status. (Status: DONE - initial descriptor schema implemented for media, nested template, and preview post/term refs)
  - [ ] P22-T0-S3 Define severity rules for `block_apply`, `warn_unresolved`, `safe_preserve`, and `remapped`. (Status: IN_PROGRESS - required nested refs and missing media now block; full policy table remains open)
- [ ] P22-T1 Dependency extraction and package metadata (Status: IN_PROGRESS)
  - [x] P22-T1-S1 Add recursive template reference extractor with typed descriptors and controlled key matching. (Status: DONE for attachment-backed media, nested template IDs, and template preview post/term refs)
  - [x] P22-T1-S2 Persist extracted descriptors in template domain objects and domain-level dependency metadata. (Status: DONE - object dependency refs plus domain media refs for package export)
  - [ ] P22-T1-S3 Add dependency fingerprints for freshness/stale-target detection. (Status: NOT_STARTED)
  - [x] P22-T1-S4 Validate dependency descriptor shape on import and reject malformed/unsafe reference paths. (Status: DONE - import validation rejects unsupported ref types, unsafe path arrays, payload-path mismatches, invalid strategies/IDs/flags, and media refs without checksummed `media/bricks_templates/*` package paths)
- [ ] P22-T2 Embedded media hydration (Status: IN_PROGRESS)
  - [x] P22-T2-S1 Package template-embedded media attachments into the existing checksummed media manifest. (Status: DONE for attachment-backed template media refs)
  - [x] P22-T2-S2 Create/reuse target attachments by checksum before template writes. (Status: DONE)
  - [x] P22-T2-S3 Rewrite known template media ID/URL fields in template settings and Bricks area meta. (Status: DONE - image/gallery/background/video ID/URL rewrites are covered for Bricks area meta, and thumbnail-style `_bricks_template_settings` media is covered)
  - [x] P22-T2-S4 Extend rollback state for template-created attachments and unreferenced cleanup. (Status: DONE for template-created attachment rollback)
- [x] P22-T3 Nested template graph remapping (Status: DONE)
  - [x] P22-T3-S1 Build source-template to target-template ID maps from selected rows and matched current target templates. (Status: DONE)
  - [x] P22-T3-S2 Topologically order selected template applies and detect dependency cycles. (Status: DONE - selected nested templates wait for dependency creation, unselected required dependencies block, and selected cycles report deterministic blockers)
  - [x] P22-T3-S3 Rewrite known nested-template ID fields after target IDs are known. (Status: DONE for typed `templateId`-style refs)
  - [x] P22-T3-S4 Block required nested references that are neither selected nor resolvable on target. (Status: DONE for typed required nested refs)
- [ ] P22-T4 Deterministic post/entity reference policy (Status: IN_PROGRESS)
  - [ ] P22-T4-S1 Add handlers for recognized query, post picker, archive, and dynamic-data references. (Status: IN_PROGRESS - `templatePreviewPostId` and `templatePreviewTerm` are handled; broader query/post picker/dynamic-data fields remain open)
  - [ ] P22-T4-S2 Resolve post/term references by DBVC UID first, then exact same type plus slug/path where safe. (Status: IN_PROGRESS - preview post/term refs resolve by UID, then exact post type/taxonomy slug)
  - [ ] P22-T4-S3 Surface unresolved references in review rows with path and value context. (Status: IN_PROGRESS - preview refs emit `post_or_term` descriptors with path, source value, UID, subtype/taxonomy, slug, and URL context; richer admin summaries remain open)
  - [ ] P22-T4-S4 Preserve unresolved arbitrary references unless severity rules require blocking apply. (Status: IN_PROGRESS - unresolved preview post/term refs are preserved during apply)
- [ ] P22-T5 Template collision and slug integrity hardening (Status: IN_PROGRESS)
  - [ ] P22-T5-S1 Preflight target slug/title collisions before add or replace. (Status: IN_PROGRESS - target freshness blocks stale review collisions; insert-time slug guard added)
  - [x] P22-T5-S2 Block add when WordPress would silently alter the intended slug without an explicit collision decision. (Status: DONE)
  - [ ] P22-T5-S3 Preserve type+slug matching for replacement and warn when title-only matching is used. (Status: NOT_STARTED)
  - [ ] P22-T5-S4 Record final target post IDs/slugs in apply receipts. (Status: NOT_STARTED)
- [ ] P22-T6 Mixed-domain apply ordering and rollback (Status: IN_PROGRESS)
  - [ ] P22-T6-S1 Enforce apply order: validation, media/font/icon create/reuse, template graph resolution, template writes, then option writes. (Status: IN_PROGRESS - template media import and graph resolution now precede template writes; broader mixed ordering proof open)
  - [ ] P22-T6-S2 Extend backup records with dependency maps and remap state. (Status: IN_PROGRESS - template-created media now recorded in media state; explicit dependency map backup open)
  - [ ] P22-T6-S3 Add partial-failure rollback for mixed option/media/font/icon/template applies. (Status: IN_PROGRESS - template media rollback covered; broader mixed failure fixture open)
  - [ ] P22-T6-S4 Make repeated reference hydration idempotent. (Status: IN_PROGRESS - checksum attachment reuse inherited; repeated template graph fixture open)
- [ ] P22-T7 Admin review details and receipts (Status: NOT_STARTED)
  - [ ] P22-T7-S1 Add template reference detail summaries and blocker/warning counts. (Status: NOT_STARTED)
  - [ ] P22-T7-S2 Add apply receipts for attachment, nested-template, unresolved-reference, and final template ID/slug mappings. (Status: NOT_STARTED)
  - [ ] P22-T7-S3 Add review filters for blockers, unresolved refs, and media create/reuse actions. (Status: NOT_STARTED)
- [ ] P22-T8 Validation + live evidence (Status: IN_PROGRESS)
  - [ ] P22-T8-S1 Add fixtures for templates with media controls, galleries, background images, nested templates, dynamic data, post refs, custom fonts, and slug collisions. (Status: IN_PROGRESS - image/gallery/background/video media, template-settings media, preview post/term refs, nested template, nested dependency blocker, nested cycle, and stale slug collision fixtures added)
  - [ ] P22-T8-S2 Add PHPUnit coverage for extraction, package validation, remapping, unresolved blockers, slug collisions, idempotency, and mixed rollback. (Status: IN_PROGRESS - media remap/rollback, gallery/background/video/template-settings remap, preview post/term remap/preserve, nested remap/blockers, stale collision, and unsafe dependency descriptor import validation coverage added)
  - [ ] P22-T8-S3 Run live two-site LocalWP drill with builder load, frontend media/nested-template/font render, and rollback verification. (Status: NOT_STARTED)

### Test Evidence
- P22-TEST-01/P22-TEST-02/P22-TEST-04/P22-TEST-05/P22-TEST-07/P22-TEST-08 partial: PASS - `vendor/bin/phpunit --filter 'test_bricks_templates_(hydrate_embedded_media_and_rollback|remap_nested_template_references)|test_bricks_template_add_blocks_stale_slug_collision_after_review'` on 2026-06-24 (`3 tests, 51 assertions`). Coverage includes typed media/nested dependency descriptors in review rows, template-embedded image media package/import, attachment ID+URL rewrite, rollback cleanup for template-created attachments, nested template apply ordering/remap, and stale target collision blocker behavior.
- P22-TEST-04: PASS - `vendor/bin/phpunit --filter test_bricks_templates_hydrate_gallery_background_and_video_media` on 2026-06-24 (`1 test, 60 assertions`). Coverage includes gallery image arrays, nested background image objects, video media objects, checksummed package media, target attachment creation, ID+URL rewrites, video extension allowlisting, and rollback cleanup for all template-created media.
- P22-TEST-04: PASS - `vendor/bin/phpunit --filter test_bricks_templates_hydrate_template_settings_media` on 2026-06-24 (`1 test, 26 assertions`). Coverage includes thumbnail-style `_bricks_template_settings` media descriptors, checksummed package media, target attachment creation, ID+URL rewrite in template settings, preservation of non-media template settings, and rollback cleanup.
- P22-TEST-03: PASS - `vendor/bin/phpunit --filter test_bricks_template_import_rejects_unsafe_dependency_descriptor_paths` on 2026-06-24 (`1 test, 21 assertions`). Coverage keeps package checksums valid while tampering a `bricks_templates` dependency descriptor path and confirms import rejects it with `dbvc_bricks_portability_template_dependency_invalid`.
- P22-TEST-05: PASS - `vendor/bin/phpunit --filter 'test_bricks_template_apply_blocks_(unselected_nested_template_dependency|nested_template_dependency_cycle)'` on 2026-06-25 (`2 tests, 28 assertions`). Coverage includes required nested-template dependency blockers when the child row is explicitly kept current, selected mutual dependency cycle detection, deterministic error codes, and no template creation on failure.
- P22-TEST-06 partial: PASS - `vendor/bin/phpunit --filter 'test_bricks_templates_(remap_preview_post_and_term_references_by_uid|remap_preview_post_and_term_references_by_slug_when_uid_missing|preserve_unresolved_preview_post_and_term_references)'` on 2026-06-25 (`3 tests, 66 assertions`). Coverage includes `post_or_term` descriptors for `templatePreviewPostId` and `templatePreviewTerm`, UID-first remap, exact type/taxonomy slug fallback, scoped term value rewrite, and unresolved preview reference preservation.
- Phase 22 regression suite: PASS - `vendor/bin/phpunit --filter BricksPortabilityManagerTest` on 2026-06-25 (`32 tests, 699 assertions`).
- P22-TEST-09, P22-TEST-10, P22-TEST-11 and remaining portions of P22-TEST-01/02/05/06/07/08: NOT_RUN/OPEN - broader query/post picker/dynamic-data refs, required post/entity blockers, nested replace-ordering fixture, repeated idempotency fixture, admin receipt payloads, broader mixed rollback, malformed media-path variants beyond the descriptor-path fixture, and live drill evidence remain pending.

### Exit Criteria Check
- [ ] Template imports hydrate embedded media references with checksum-backed attachment create/reuse and rollback. (Partial: image/gallery/background/video area-meta and template-settings ID/URL fixtures covered.)
- [ ] Nested template references are remapped in deterministic apply order or blocked with actionable errors. (Partial: selected nested template remap plus unselected dependency and cycle blockers covered; replace-ordering and admin review detail still open.)
- [ ] Arbitrary post/entity references follow a safe-remap policy and are never silently guessed.
- [ ] Template slug collisions and WordPress unique-slug mutations are detected before apply.
- [ ] Mixed option/media/template applies are rollback-safe under partial failure.
- [ ] Required automated tests and live LocalWP evidence are complete.

## Phase 23 - Broader Bricks Entity and Dynamic Reference Coverage
Status: IN_PROGRESS
Owner: Codex
Created: 2026-06-25
Completed: n/a

### Scope
Extend deterministic reference hydration beyond template preview refs into recognized Bricks query controls, post/term pickers, archive/author refs where deterministic, and dynamic-data tokens.

### UX Constraint
No new required import step or per-reference decision workflow. Safe remaps should happen automatically; optional unresolved refs should be preserved and summarized; required unresolved refs may block apply with row/path context.

### Tasks
- [ ] P23-T1 Bricks control/key discovery pass. (Status: IN_PROGRESS - local Bricks 2.3.x query controls and built-in link controls reviewed; first allowlist covers `settings.query.post__in`, `post__not_in`, `tax_query`, `tax_query_not`, `link.postId`, `link.term`, and safe login/logout dynamic tokens; CSV/dynamic query inputs are preserve-only warnings for now)
- [ ] P23-T2 Query and picker descriptor extraction. (Status: IN_PROGRESS - implemented `post_or_term` descriptors for first query include/exclude and built-in internal/taxonomy link slices)
- [ ] P23-T3 Dynamic-data token handling. (Status: IN_PROGRESS - first safe post-token slice remaps Bricks core `site_login:<post_id>` and `site_logout:<post_id>` redirect tokens while preserving unrelated token/text segments)
- [ ] P23-T4 Apply remapping and blocker policy. (Status: IN_PROGRESS - query refs reuse Phase 22 UID-first/slug fallback remappers and preserve unresolved optional refs)
- [ ] P23-T5 Validation and tests. (Status: IN_PROGRESS - first query remap, unresolved-preserve, skipped query/link warning, built-in link remap, compact reference summary, and dynamic post-token fixtures added)

### Test Evidence
- P23-TEST-01 partial: PASS - `vendor/bin/phpunit --filter 'test_bricks_templates_(remap_query_post_and_term_references_by_uid|preserve_unresolved_query_post_and_term_references)' tests/phpunit/BricksPortabilityManagerTest.php` on 2026-06-25 (`2 tests, 56 assertions`). Coverage includes descriptor extraction for `query.post__in`, `query.post__not_in`, `query.tax_query`, and `query.tax_query_not` scalar/array values.
- P23-TEST-02 partial: PASS - same run covers UID-first remap for recognized query post and term refs while preserving source string/integer value shape.
- P23-TEST-01/P23-TEST-02 partial: PASS - `vendor/bin/phpunit --filter test_bricks_templates_remap_link_post_and_term_controls_by_uid tests/phpunit/BricksPortabilityManagerTest.php` on 2026-07-01 (`1 test, 24 assertions`). Coverage includes UID-first remap for Bricks built-in `link.postId` internal links and `link.term` taxonomy links.
- P23-TEST-04 partial: PASS - same run covers optional unresolved query post/term refs preserving source values.
- Phase 23/24 regression suite: PASS - `vendor/bin/phpunit --filter BricksPortabilityManagerTest` on 2026-07-01 (`41 tests, 891 assertions`).
- P23-TEST-05 partial: PASS - `vendor/bin/phpunit --filter 'test_bricks_templates_(remap_query_post_and_term_references_by_uid|preserve_unresolved_query_post_and_term_references|warn_about_unmapped_query_reference_shapes)' tests/phpunit/BricksPortabilityManagerTest.php` on 2026-06-30 (`3 tests, 65 assertions`). Coverage includes review-visible warnings for skipped CSV post query strings, dynamic post query strings, and unresolved scoped term query values while preserving source values.
- P23-TEST-03/P23-TEST-05 partial: PASS - `vendor/bin/phpunit --filter 'test_bricks_template(s)?_(remap_dynamic_data_post_tokens_by_uid|import_rejects_malformed_dynamic_token_descriptors)' tests/phpunit/BricksPortabilityManagerTest.php` on 2026-07-01 (`2 tests, 45 assertions`). Coverage includes UID-first remap for `site_login:<post_id>` and `site_logout:<post_id>` token ID segments while preserving unrelated text, `{post_id}`, unknown non-numeric tokens, and token suffixes; malformed dynamic-token descriptor metadata is rejected during import.
- P23-TEST-05 partial: PASS - `vendor/bin/phpunit --filter test_bricks_templates_warn_about_unmapped_link_reference_shapes tests/phpunit/BricksPortabilityManagerTest.php` on 2026-07-01 (`1 test, 8 assertions`). Coverage includes review-visible warnings for skipped built-in `link.postId` CSV values and unresolved `link.term` scoped term values while preserving source values.
- Remaining portions of P23-TEST-01/02/03/04/05: NOT_RUN/OPEN - broader post/term picker controls, deterministic archive/author refs, dynamic tokens outside the safe login/logout post redirect subset, unknown numeric non-query controls, and required unresolved blockers remain open.

## Phase 24 - Low-Friction Review Summaries and Apply Receipts
Status: IN_PROGRESS
Owner: Codex
Created: 2026-06-25
Completed: n/a

### Scope
Surface compact reference summaries and apply receipts without adding an overwhelming reference-management UI.

### UX Constraint
Keep the existing workspace and row modal. Do not require individual remap approvals. Prefer a compact `Needs attention` summary and reuse existing filters unless one combined attention filter is necessary. Receipt UI should remain count-first and live in existing Applied/Backup/Rollback summaries; detailed maps are backend support data.

### Tasks
- [ ] P24-T1 Summary aggregation. (Status: IN_PROGRESS - row-level template reference summaries now count safe refs, media refs, nested refs, entity/query/link/dynamic refs, preserved refs, unknown refs, and blocked refs in the existing review payload)
- [ ] P24-T2 Row modal/reference details. (Status: IN_PROGRESS - existing row modal reference summary displays a compact `Template refs` count line, and Applied/Backup summaries now display one compact receipt line after apply; path/action/reason details remain open)
- [ ] P24-T3 Apply receipts. (Status: IN_PROGRESS - apply result, session approval, backup record, recent backup record, rollback result, and rollback session view carry compact receipts plus backend source-to-target maps)
- [ ] P24-T4 Minimal filtering. (Status: NOT_STARTED)

### Test Evidence
- P24-TEST-01 partial: PASS - `vendor/bin/phpunit --filter test_bricks_templates_review_payload_includes_compact_reference_summary_counts tests/phpunit/BricksPortabilityManagerTest.php` on 2026-07-01 (`1 test, 18 assertions`). Coverage includes compact row-level counts for query, built-in link, dynamic-data, entity, preserved, and blocked reference buckets in the existing review payload.
- P24-TEST-02 partial: PASS - `node --check addons/bricks/portability/assets/bricks-portability.js` on 2026-07-01. The existing row modal reference summary renderer parses after adding the compact `Template refs` count line; browser-level modal QA remains open.
- P24-TEST-03/P24-TEST-04 partial: PASS - `vendor/bin/phpunit --filter test_bricks_template_apply_receipt_records_compact_reference_summary_without_extra_decisions tests/phpunit/BricksPortabilityManagerTest.php` on 2026-07-01 (`1 test, 32 assertions`). Coverage includes compact apply receipts for remapped query/link/dynamic/entity refs, preserved refs, template post maps, session approval view, backup records, recent backup records, rollback result, and rollback session view without adding user decisions.

## Phase 25 - Idempotency and Mixed Rollback Hardening
Status: NOT_STARTED
Owner: Codex
Created: 2026-06-25
Completed: n/a

### Scope
Harden repeated applies, dependency backup state, mixed-domain rollback, stale session handling, and malformed package rejection across options, media, fonts/icons, and templates.

### UX Constraint
No new required UI controls. Recovery should use the existing History/Rollback workflow, and idempotency should be automatic.

### Tasks
- [ ] P25-T1 Repeated apply idempotency. (Status: NOT_STARTED)
- [ ] P25-T2 Dependency map backup state. (Status: NOT_STARTED)
- [ ] P25-T3 Mixed partial-failure rollback. (Status: NOT_STARTED)
- [ ] P25-T4 Stale session and package hardening. (Status: NOT_STARTED)

### Test Evidence
- P25-TEST-01 through P25-TEST-05: NOT_RUN/OPEN - planned coverage for repeated apply reuse, partial-failure rollback, stale-session refresh, and malformed package variants.

## Phase 26 - Live LocalWP Drill and Release Gate
Status: NOT_STARTED
Owner: Codex
Created: 2026-06-25
Completed: n/a

### Scope
Validate the full Bricks Settings Portability flow against real LocalWP source/target sites and record go/no-go evidence.

### UX Constraint
Use the same default export/import/apply/rollback flow an operator would use. Do not add setup-only UI.

### Tasks
- [ ] P26-T1 Source fixture build. (Status: NOT_STARTED)
- [ ] P26-T2 Target apply drill. (Status: NOT_STARTED)
- [ ] P26-T3 Rollback drill. (Status: NOT_STARTED)
- [ ] P26-T4 Release gate documentation. (Status: NOT_STARTED)

### Test Evidence
- P26-EVIDENCE-01 through P26-EVIDENCE-04: NOT_RUN/OPEN - planned live drill, builder/frontend render verification, rollback verification, and final go/no-go note.
