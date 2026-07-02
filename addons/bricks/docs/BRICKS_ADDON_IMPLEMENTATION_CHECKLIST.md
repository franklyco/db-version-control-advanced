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
- Phase 19A implementation complete (`P19A-T0..T2 DONE`); historical reachability constraints were resolved by Phase 19D transport, and the rerun gate passed on 2026-03-08.

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
- `P19C-T1` Mothership protected-variant visibility: `DONE`
  - `P19C-T1-S1` Add connected-client summary table with protected counts by artifact class: `DONE`
  - `P19C-T1-S2` Add drill-down list for each client’s protected variants: `DONE`
  - `P19C-T1-S3` Add deep-link and copy-link helper to client `DBVC -> Bricks -> Protected Artifacts`: `DONE`
  - `P19C-T1-S4` Add freshness indicators (`last_seen`, `last_sync`) per client: `DONE`
- `P19C-T2` Final validation and closure
  - `P19C-T2-S1` Run full network drill (shared rules distribution + protected variant visibility) across mothership/clientA/clientB.
  - `P19C-T2-S2` Capture commands, timestamps, receipts, diagnostics traces, and UI evidence.
    - Repo helper available for non-mutating runtime snapshots on each participating site: `php scripts/check-bricks-phase19-evidence.php --limit=10 --output=/tmp/p19c/<timestamp>_<site>.json` (mothership accepts optional `--distribution_id=...`, `--site_uid=...`, `--state=...`, `--include_hidden=1` filters).
  - `P19C-T2-S3` Update tracker statuses to `DONE` and write Phase 19 completion note.

### Required tests
- `P19C-TEST-01` Mothership aggregation visibility tests: `PASS` on 2026-04-15 via `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19CTest.php`.
- `P19C-TEST-02` Deep-link/copy-link rendering tests: `PASS` on 2026-04-15 via `vendor/bin/phpunit tests/phpunit/BricksAddonPhase19CTest.php`.
- `P19C-TEST-03` Full live cross-site drill evidence test.

### Exit criteria
- Mothership can identify and inspect clients with protected variants.
- Operators can navigate (or copy-link navigate) to client protected tabs reliably.
- Live cross-site evidence is complete and auditable.
- Required tests pass and are logged in tracker.

## 4.1) Phase 20: Bricks Font and Icon Asset Portability

Status: `IN_PROGRESS`
Owner: Codex
Created: 2026-06-24
Scope: Extend the standalone Bricks Settings Portability tool beyond option-only domains so it can safely export, import, compare, apply, and roll back Bricks Font Manager and Icon Manager assets.

Implementation note (2026-06-24): implementation slices now add media-backed `custom_fonts` and `icon_collections` registry domains, normalize Bricks font posts/meta and icon-manager options, export referenced font/SVG attachments into checksummed package media, import package payloads into review rows, and support add-only apply for new incoming font families and icon collections with attachment creation/reuse, source-to-target font ID remapping in selected option domains, and rollback cleanup for created posts/attachments. Replacement, delete-sync, richer collision handling, and live two-site evidence remain open.

### Discovery baseline

- Current official Bricks release checked on 2026-06-24: Bricks `2.3.8` (released 2026-06-23). The LocalWP site at `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/themes/bricks` is Bricks `2.3.7`.
- Bricks Font Manager custom fonts are media-backed entity records, not a simple option domain:
  - custom font families are posts with post type `bricks_fonts`;
  - font variation metadata is stored in post meta key `bricks_font_faces`;
  - generated CSS is option `bricks_font_face_rules` and should be treated as derived/regenerable;
  - font files are WordPress media attachments referenced by attachment ID from `bricks_font_faces`;
  - current local evidence: 8 `bricks_fonts` posts, with `.ttf` and `.woff2` attachments under uploads.
- Bricks Icon Manager storage is option-backed but media-dependent:
  - icon sets are option `bricks_icon_sets`;
  - custom icon records are option `bricks_custom_icons`;
  - disabled set state is option `bricks_disabled_icon_sets`;
  - custom icons reference SVG media by `attachment_id` and `url`;
  - current local evidence: 1 custom icon set, 4 custom SVG icons, and SVG attachment IDs under uploads.
- Font and icon domains must support media transfer, checksum verification, ID remapping, and dependency warnings. They must not be added as raw option-only domains.
- Bricks typography values can reference custom fonts by `custom_font_{post_id}`. Import must remap source font IDs to target font IDs anywhere selected Bricks domains contain typography references.
- Bricks icon controls can reference custom icon set IDs and icon IDs. Import should preserve Bricks icon `id` and `setId` when safe; collisions require review/skip/replace decisions.

### Entry criteria

- Phase 19C live drill is closed or explicitly deferred by the user.
- Existing Settings Portability export/import/apply/rollback flow is green via `BricksPortabilityManagerTest`.
- DBVC media package/hydration primitives are reviewed for reuse before adding new asset-copy code.
- A disposable local site is available for live apply/rollback verification with custom fonts and custom SVG icon sets.

### Non-goals

- No blind transport of arbitrary uploads; only referenced font/SVG attachments for selected Bricks font/icon domains are in scope.
- No destructive delete-sync of target-only font families, icon sets, custom icons, or media files.
- No remote Google Fonts download orchestration in DBVC. If Bricks has already converted a Google font into a local custom font, DBVC treats it as a normal custom font record plus media.
- No support for Bricks built-in icon set binary assets. DBVC only transports custom icon sets and disabled/enabled state metadata.

### Tasks / Sub-tasks

- `P20-T0` Contract freeze + live schema verification
  - `P20-T0-S1` Add a focused runtime probe that reports Bricks version, `bricks_fonts` post/meta shape, `bricks_font_face_rules` presence, icon option shape, attachment IDs, mime types, file paths, and multisite main-site constants.
  - `P20-T0-S2` Freeze canonical font domain shape: family label, source post ID, deterministic family key, status, `bricks_font_faces` variants, normalized asset references, and derived-rule metadata.
  - `P20-T0-S3` Freeze canonical icon domain shape: icon sets, custom icons, disabled sets, set/icon collision keys, and normalized SVG asset references.
  - `P20-T0-S4` Define matching rules and collision policy:
    - fonts match by normalized family name first, then variant asset checksums;
    - icon sets match by Bricks set ID when identical, then normalized set name;
    - icons match by set identity + icon ID/name + SVG checksum;
    - conflicting IDs with different payload/checksum require explicit review.
- `P20-T1` Package schema + media manifest
  - `P20-T1-S1` Add `custom_fonts` and `icon_collections` as media-backed portability domains in `DBVC_Bricks_Portability_Registry`.
  - `P20-T1-S2` Extend export packages with a `media/` directory and manifest entries containing checksum, mime type, original filename, source attachment ID, source URL, and consumer references.
  - `P20-T1-S3` Enforce allowed mime/extensions: fonts `.woff2`, `.woff`, `.ttf` initially; icons `.svg` only, with SVG sanitization required on import.
  - `P20-T1-S4` Mark `bricks_font_face_rules` as derived/backup-only in the package contract and regenerate it after font apply instead of transporting it as canonical state.
- `P20-T2` Export implementation
  - `P20-T2-S1` Export `bricks_fonts` posts and `bricks_font_faces` meta with referenced media files and checksums.
  - `P20-T2-S2` Export icon manager options (`bricks_icon_sets`, `bricks_custom_icons`, `bricks_disabled_icon_sets`) plus referenced SVG media files and checksums.
  - `P20-T2-S3` Add dependency metadata showing which selected domains reference custom fonts or custom icons, and warn when referenced font/icon assets are not selected for export.
  - `P20-T2-S4` Add package validation that rejects missing, unchecksummed, oversized, or disallowed media files.
- `P20-T3` Import, diff, and dependency review
  - `P20-T3-S1` Normalize uploaded font/icon domains into review rows with media previews/metadata and current-site freshness fingerprints.
  - `P20-T3-S2` Build an apply-time source-to-target font ID map and rewrite `custom_font_{source_post_id}` references in selected settings domains after the target font record is created/resolved.
  - `P20-T3-S3` Preserve custom icon `id` and `setId` when no conflict exists; block or require explicit replace when the target already has the same ID with different SVG checksum or set metadata.
  - `P20-T3-S4` Add review warnings for missing media files, unresolved attachment IDs, unsupported mime types, SVG sanitize failures, unresolved typography references, and icon references whose set is not selected or present on target.
  - `P20-T3-S5` Keep target-only fonts/icons by default and expose `keep_current`, `add_incoming`, `replace_with_incoming`, and `skip` decisions only where the operation is deterministic.
- `P20-T4` Apply, rollback, and asset lifecycle safety
  - `P20-T4-S1` Create or update target font posts and media attachments before applying selected domains that reference them.
  - `P20-T4-S2` Create or update SVG attachments before writing icon manager options.
  - `P20-T4-S3` Regenerate or clear `bricks_font_face_rules` after font apply using Bricks-compatible generation rules.
  - `P20-T4-S4` Extend rollback snapshots to include affected options, font posts/meta, attachment IDs, and file paths. Rollback should restore DB state and avoid deleting media unless the file was created by the failed apply and is still unreferenced.
  - `P20-T4-S5` Add idempotent apply behavior so repeated imports do not duplicate identical media attachments or font posts.
- `P20-T5` Admin UI integration
  - `P20-T5-S1` Add `Custom Fonts` and `Icon Collections` domain cards with media counts, high-risk labels, and missing-dependency warnings.
  - `P20-T5-S2` Add review row detail views for font variants and custom icons, including source/current checksums, filenames, mime types, and mapped target IDs.
  - `P20-T5-S3` Add an import preflight summary for media files to be created/reused/replaced/skipped.
  - `P20-T5-S4` Add post-apply receipts that report created/reused attachments, rewritten font references, regenerated font CSS status, and icon collision decisions.
- `P20-T6` Validation + live evidence
  - `P20-T6-S1` Add PHPUnit fixtures for font posts/meta, media-backed font files, icon sets, custom icons, disabled sets, collisions, and missing-media blockers.
  - `P20-T6-S2` Add regression coverage for font ID remapping inside global classes, theme styles, components, and settings domains.
  - `P20-T6-S3` Add regression coverage for SVG sanitize rejection, media checksum mismatch, duplicate attachment reuse, and rollback of partially failed applies.
  - `P20-T6-S4` Run a live two-site drill: export custom fonts + icons from source, import into target, apply selected changes, verify Bricks builder load data, frontend `@font-face` output, icon picker data, rendered SVG icons, and rollback.

### Required tests

- `P20-TEST-01` Runtime schema probe fixture test for Bricks `2.3.x` font/icon storage.
- `P20-TEST-02` Export package test for `custom_fonts` with media manifest and checksums.
- `P20-TEST-03` Export package test for `icon_collections` with SVG media manifest and checksums.
- `P20-TEST-04` Import validation test rejecting missing/unchecksummed/disallowed media.
- `P20-TEST-05` Diff/review tests for font family, variant, icon set, icon, disabled-set, and collision rows.
- `P20-TEST-06` Apply test for creating/reusing font attachments and regenerating `bricks_font_face_rules`.
- `P20-TEST-07` Apply test for creating/reusing SVG attachments and writing icon manager options.
- `P20-TEST-08` Cross-domain font reference remapping tests for `custom_font_{source_id}` values.
- `P20-TEST-09` Rollback test covering options, font posts/meta, attachments, and derived CSS.
- `P20-TEST-10` Live LocalWP two-site drill evidence for export/import/apply/rollback.

### Exit criteria

- Operators can export and import Bricks custom fonts and custom icon collections through the existing Settings Portability workflow.
- Packages include only explicitly referenced and checksummed media assets.
- Font and icon apply is reviewable, idempotent, rollback-safe, and does not delete target-only assets.
- Custom font IDs are remapped in selected Bricks settings domains so imported typography references resolve on the target site.
- Required automated tests and live LocalWP drill evidence are logged in the progress tracker.

## 4.2) Phase 21: Bricks Template Entity Portability

Status: `IN_PROGRESS`
Owner: Codex
Created: 2026-06-24
Scope: Add `bricks_templates` as an entity-backed domain in the standalone Bricks Settings Portability tool so Bricks template posts can be exported, imported, reviewed, applied, and rolled back alongside option-backed and media-backed domains.

Implementation note (2026-06-24): initial implementation now registers `bricks_templates`, normalizes `bricks_template` posts into review rows, packages template domain JSON, imports it through the existing package/session model, and supports add/replace apply with rollback for template posts, `_bricks_template_type`, `_bricks_template_settings`, Bricks area meta (`_bricks_page_header_2`, `_bricks_page_content_2`, `_bricks_page_footer_2`), and `template_tag`/`template_bundle` terms by slug. Embedded media IDs, arbitrary post IDs, nested template IDs, and live builder/front-end verification remain open.

### Discovery baseline

- Current local Bricks install confirms template storage constants:
  - post type: `bricks_template`;
  - taxonomies: `template_tag`, `template_bundle`;
  - template type meta: `_bricks_template_type`;
  - template settings meta: `_bricks_template_settings`;
  - Bricks element data meta: `_bricks_page_header_2`, `_bricks_page_content_2`, `_bricks_page_footer_2`.
- Bricks templates are entity-backed WordPress records, not option-only settings. They must use row-level matching, backup, and rollback behavior rather than raw option replacement.
- Bricks element payloads can contain media IDs, nested template IDs, query references, dynamic data references, and custom font values. Phase 21 transports the template record and warns on likely unresolved references; deep reference remapping is a follow-up.

### Tasks / Sub-tasks

- `P21-T0` Contract and storage shape
  - `P21-T0-S1` Freeze canonical template payload shape for post fields, template type/settings meta, Bricks area meta, and template taxonomies. (Status: DONE)
  - `P21-T0-S2` Define match policy: template type + slug first, then template type + title; never match by source post ID across sites. (Status: DONE)
  - `P21-T0-S3` Add warnings for embedded media/post/template references that are not remapped in the initial slice. (Status: DONE)
- `P21-T1` Package/import/diff
  - `P21-T1-S1` Add `bricks_templates` to `DBVC_Bricks_Portability_Registry` as an entity-backed high-risk domain. (Status: DONE)
  - `P21-T1-S2` Export template rows into the existing domains package contract. (Status: DONE)
  - `P21-T1-S3` Import entity-backed domain JSON through the existing package/session comparison flow. (Status: DONE)
  - `P21-T1-S4` Surface template rows with add/replace/keep/skip review actions and target-only keep-current defaults. (Status: DONE)
- `P21-T2` Apply and rollback
  - `P21-T2-S1` Create incoming templates with Bricks meta and template tags/bundles. (Status: DONE)
  - `P21-T2-S2` Replace matched templates by preserving target post ID and writing incoming Bricks template payload. (Status: DONE)
  - `P21-T2-S3` Extend backups with entity state for created/replaced template posts and rollback restore/delete behavior. (Status: DONE)
  - `P21-T2-S4` Remap imported custom font values inside template payloads when `custom_fonts` is applied in the same session. (Status: DONE)
- `P21-T3` Hardening and dependency mapping
  - `P21-T3-S1` Remap embedded media/attachment references in template element payloads using package media hydration. (Status: NOT_STARTED)
  - `P21-T3-S2` Remap nested Bricks template references and warn/block unresolved dependencies. (Status: NOT_STARTED)
  - `P21-T3-S3` Define collision policy for same slug/title across different template types and for WordPress-generated unique slugs. (Status: IN_PROGRESS - initial type+slug/type+title match implemented)
  - `P21-T3-S4` Add partial-failure rollback coverage across mixed option/media/template applies. (Status: NOT_STARTED)
- `P21-T4` Admin UI and live evidence
  - `P21-T4-S1` Add template-specific review detail summaries for type, tags, bundles, element counts, and unresolved references. (Status: NOT_STARTED)
  - `P21-T4-S2` Add apply receipts for created/replaced templates and taxonomy assignments. (Status: NOT_STARTED)
  - `P21-T4-S3` Run live LocalWP two-site template export/import/apply/rollback drill and verify Bricks builder load plus frontend render. (Status: NOT_STARTED)

### Required tests

- `P21-TEST-01` Registry/support test for `bricks_templates` domain availability and high-risk entity-backed metadata.
- `P21-TEST-02` Export/import review test for template posts, type/settings meta, Bricks area meta, and tags/bundles.
- `P21-TEST-03` Apply test for matched template replacement plus new template creation.
- `P21-TEST-04` Rollback test restoring replaced templates and deleting created templates.
- `P21-TEST-05` Cross-domain font reference remapping test for template payloads when custom fonts are imported in the same session.
- `P21-TEST-06` Future media/nested-template dependency tests once reference hydration is implemented.
- `P21-TEST-07` Live two-site drill evidence for builder and frontend behavior.

### Exit criteria

- Operators can select `Bricks Templates` in Settings Portability exports and imports.
- Template rows are reviewed at object granularity and target-only templates are kept by default.
- Add and replace apply paths are rollback-safe for template posts, Bricks template meta, and template tag/bundle assignments.
- Packages do not silently claim to hydrate embedded media, arbitrary post IDs, or nested template IDs until those remappers exist.
- Required automated tests and live LocalWP drill evidence are logged in the progress tracker.

## 4.3) Phase 22: Bricks Template Reference Hydration and Dependency Safety

Status: `IN_PROGRESS`
Owner: Codex
Created: 2026-06-24
Scope: Close the Phase 21 template portability boundaries by detecting, packaging, remapping, reviewing, applying, and rolling back embedded Bricks template dependencies. This phase handles embedded media/attachment references, nested Bricks template references, deterministic post/entity references, slug collisions, and mixed option/media/template rollback safety.

Implementation note (updated 2026-06-25): initial implementation slice adds typed template dependency descriptors for attachment-backed media, nested template IDs, and Bricks template preview post/term refs, exports template-embedded media through the existing checksummed media manifest, imports/reuses target attachments during template apply, rewrites known image/gallery/background/video ID/URL payload paths in Bricks area meta and `_bricks_template_settings`, applies selected templates in nested-template dependency order, rewrites nested template IDs, remaps `templatePreviewPostId`/`templatePreviewTerm` by DBVC UID then exact type/taxonomy slug, preserves unresolved preview refs, blocks unselected/cyclic nested-template applies, blocks stale slug-collision applies via freshness checks plus insert-time slug guards, validates imported dependency descriptor shape/path/package-media/entity metadata, and covers media/nested/post-term/rollback/import-validation behavior in PHPUnit. Broader Bricks query/post picker/dynamic-data refs, richer admin receipts, and live LocalWP evidence remain open.

### Entry criteria

- Phase 21 `bricks_templates` export/import/add/replace apply is merged and `BricksPortabilityManagerTest` is green.
- Phase 20 media package primitives for checksummed media creation/reuse are available for reuse by template-embedded media references.
- A disposable LocalWP source/target pair exists with templates containing at least one image/media control, one nested template reference, one custom font value, one dynamic/query reference, and one intentional slug collision fixture.
- Current Bricks local source is rechecked for element control key names before extractor implementation begins.

### Non-goals

- No blind remapping of arbitrary numeric IDs. References are remapped only when DBVC can prove identity through package media checksums, selected template mappings, DBVC entity UID metadata, or an explicit safe same-type slug match.
- No automatic creation of arbitrary content posts, terms, products, forms, or third-party entities referenced by a template.
- No destructive delete-sync of target-only templates, target-only media, or target-only referenced content.
- No replacement/merge policy for custom font/icon domains beyond the Phase 20 supported behavior unless that work is explicitly pulled into the phase.

### Reference handling policy

- `media`: package the referenced attachment file when available, verify checksum/mime/path on import, create or reuse the target attachment, and rewrite known Bricks element ID/URL fields.
- `nested_template`: resolve through the selected/imported template graph first, then matched current target templates. Block apply when a required nested template cannot be resolved; warn when the reference appears optional.
- `post_or_term`: remap only by DBVC UID or exact same object type + stable slug/path when present on target. Otherwise keep the source value and surface an unresolved-reference warning or blocker based on control criticality.
- `dynamic_data`: preserve tokens unless the token contains a recognized entity ID; for recognized IDs, apply the `post_or_term` policy.
- `unknown_numeric_id`: do not remap. Record path, value, and confidence for review.

### Tasks / Sub-tasks

- `P22-T0` Contract freeze + Bricks control discovery (Status: IN_PROGRESS)
  - `P22-T0-S1` Re-scan local Bricks source and fixture template payloads for known media, gallery, video, icon, nested template, query, dynamic data, and post picker control keys. (Status: IN_PROGRESS - fixture-driven image/gallery/background/video media and nested keys implemented; broader live Bricks source audit still open)
  - `P22-T0-S2` Freeze reference descriptor schema: `ref_type`, `source_id`, `source_url`, `payload_path`, `consumer_template_key`, `control_name`, `required`, `confidence`, `resolution_strategy`, `target_id`, and `status`. (Status: DONE - initial descriptor includes typed path/media/template fields; consumer template key can be derived from object context)
  - `P22-T0-S3` Define severity rules: `block_apply`, `warn_unresolved`, `safe_preserve`, and `remapped`. (Status: IN_PROGRESS - unresolved required nested templates block; typed media imports block on missing media; broader severity table remains open)
- `P22-T1` Dependency extraction and package metadata (Status: IN_PROGRESS)
  - `P22-T1-S1` Add a template reference extractor that walks Bricks template raw payloads recursively and emits typed descriptors without treating every `id` key as remappable. (Status: DONE for attachment-backed media, nested template IDs, and template preview post/term refs; broader query/dynamic refs open)
  - `P22-T1-S2` Store extracted descriptors in template domain objects and domain-level dependency metadata. (Status: DONE - object `dependency_refs` and media refs added; domain-level media refs aggregate for packaging)
  - `P22-T1-S3` Add dependency fingerprints so review sessions can detect when the target has changed after import.
  - `P22-T1-S4` Add package validation for dependency descriptor shape and reject malformed or unsafe reference paths. (Status: DONE - imported template dependency descriptors now validate ref type, source IDs, path arrays, payload-path consistency, strategy, required/confidence/target fields, and checksummed template media package paths)
- `P22-T2` Embedded media hydration (Status: IN_PROGRESS)
  - `P22-T2-S1` Package template-embedded media attachments into the existing checksummed media manifest when the attachment file is available and allowed. (Status: DONE for attachment-backed template media refs)
  - `P22-T2-S2` Create/reuse target attachments by checksum during apply before template posts are written. (Status: DONE)
  - `P22-T2-S3` Rewrite known template media ID and URL fields in `_bricks_page_header_2`, `_bricks_page_content_2`, `_bricks_page_footer_2`, and `_bricks_template_settings`. (Status: DONE - image/gallery/background/video ID/URL rewrites are covered for Bricks area meta, and thumbnail-style `_bricks_template_settings` media is covered)
  - `P22-T2-S4` Extend rollback state for template-created attachments and verify unreferenced cleanup only deletes DBVC-created media. (Status: DONE for template-created attachment rollback)
- `P22-T3` Nested template graph remapping (Status: DONE)
  - `P22-T3-S1` Build a source template ID/key to target post ID map from selected add/replace rows, existing matched target templates, and current target-only templates. (Status: DONE for source post ID to target post ID mapping from selected/matched rows)
  - `P22-T3-S2` Topologically order selected template applies when nested dependencies exist; detect cycles and report deterministic blockers. (Status: DONE - selected nested templates wait for dependency creation, unselected required dependencies block, and selected cycles report deterministic blockers)
  - `P22-T3-S3` Rewrite known nested template ID fields after target IDs are known. (Status: DONE for typed `templateId`-style numeric refs)
  - `P22-T3-S4` Block apply when a required nested template is neither selected nor resolvable on target. (Status: DONE for typed required nested refs)
- `P22-T4` Deterministic post/entity reference policy (Status: IN_PROGRESS)
  - `P22-T4-S1` Add reference handlers for recognized Bricks query/post picker/dynamic-data fields that can point at WordPress posts, terms, authors, or archives. (Status: IN_PROGRESS - `templatePreviewPostId` and `templatePreviewTerm` are handled; broader query/post picker/dynamic-data fields remain open)
  - `P22-T4-S2` Resolve post/term references by DBVC UID first, then exact same type + slug/path where safe. (Status: IN_PROGRESS - preview post/term refs resolve by UID, then exact post type/taxonomy slug)
  - `P22-T4-S3` Surface unresolved entity references in review rows with enough path/value context for manual remediation. (Status: IN_PROGRESS - preview refs emit `post_or_term` dependency descriptors with path, source value, UID, subtype/taxonomy, slug, and URL context; richer admin summaries remain open)
  - `P22-T4-S4` Keep unresolved arbitrary references unchanged unless severity rules classify the control as apply-blocking. (Status: IN_PROGRESS - unresolved preview post/term refs are preserved during apply)
- `P22-T5` Template collision and slug integrity hardening (Status: IN_PROGRESS)
  - `P22-T5-S1` Preflight target slug/title collisions before add or replace, including WordPress-generated unique-slug behavior after `wp_insert_post`. (Status: IN_PROGRESS - live target freshness blocks stale collisions; insert-time slug guard added)
  - `P22-T5-S2` Block add when the intended slug would be silently changed and no explicit collision decision exists. (Status: DONE - add path rejects pre-existing slug and post-insert slug mutation)
  - `P22-T5-S3` Preserve type+slug matching for replacements and add an explicit warning when title-only matching is used.
  - `P22-T5-S4` Record final target post IDs/slugs in apply receipts.
- `P22-T6` Mixed-domain apply ordering and rollback (Status: IN_PROGRESS)
  - `P22-T6-S1` Enforce apply order: package media validation, media/font/icon creation or reuse, template dependency graph resolution, template writes, then option writes. (Status: IN_PROGRESS - template media import + nested graph resolution now occur before template writes; existing option writes remain after template writes)
  - `P22-T6-S2` Extend backup records with dependency maps and attachment/template/post reference remap state. (Status: IN_PROGRESS - template-created media now recorded in media state; explicit dependency map backup still open)
  - `P22-T6-S3` Add partial-failure rollback for mixed option/media/font/icon/template applies. (Status: IN_PROGRESS - template media rollback covered; broader mixed failure fixture still open)
  - `P22-T6-S4` Make repeated template reference hydration idempotent and avoid duplicate identical attachments or duplicate nested-template remaps. (Status: IN_PROGRESS - checksum attachment reuse inherited; repeated template graph fixture still open)
- `P22-T7` Admin review details and receipts
  - `P22-T7-S1` Add template reference detail summaries for media, nested templates, post/entity refs, dynamic-data refs, unknown IDs, and blocker/warning counts.
  - `P22-T7-S2` Add apply receipts showing source-to-target attachment IDs, nested template IDs, preserved unresolved refs, and final template post IDs/slugs.
  - `P22-T7-S3` Add filters for `Has blockers`, `Has unresolved refs`, and `Media will be created/reused`.
- `P22-T8` Validation + live evidence (Status: IN_PROGRESS)
  - `P22-T8-S1` Add fixture builders for templates with media controls, galleries, background images, nested templates, dynamic data, post picker refs, custom fonts, and slug collisions. (Status: IN_PROGRESS - image/gallery/background/video media, template-settings media, preview post/term refs, nested template, nested dependency blocker, nested cycle, and stale slug collision fixtures added)
  - `P22-T8-S2` Add PHPUnit coverage for dependency extraction, package validation, media remapping, nested template remapping, unresolved reference blockers, slug collision blockers, idempotency, and mixed rollback. (Status: IN_PROGRESS - media remap/rollback, gallery/background/video/template-settings remap, preview post/term remap/preserve, nested remap/blockers, stale collision, and unsafe dependency descriptor import validation coverage added; remaining cases open)
  - `P22-T8-S3` Run live two-site LocalWP export/import/apply/rollback drill and verify builder load, frontend media render, nested template render, font render, and rollback restoration.

### Required tests

- `P22-TEST-01` Reference extractor fixture coverage for known Bricks media, nested-template, post/query, dynamic-data, and unknown-ID shapes.
- `P22-TEST-02` Package export test proving template-embedded media files are checksummed and dependency descriptors are valid.
- `P22-TEST-03` Import validation test rejecting malformed dependency descriptors and unsafe media paths.
- `P22-TEST-04` Apply test remapping image/background/gallery/video/template-settings attachment IDs and URLs into template meta.
- `P22-TEST-05` Nested template apply test proving create/replace ordering, source-to-target ID remap, and unresolved dependency blockers.
- `P22-TEST-06` Deterministic post/term reference policy test for DBVC UID match, exact slug match, unresolved warning/preserve, and required-ref blocker.
- `P22-TEST-07` Slug collision test proving silent WordPress unique-slug changes are blocked or explicitly surfaced.
- `P22-TEST-08` Mixed option/media/font/icon/template partial-failure rollback test.
- `P22-TEST-09` Idempotency test proving repeated applies reuse attachments/templates instead of duplicating them.
- `P22-TEST-10` Admin review payload test for reference summaries, blockers, and apply receipts.
- `P22-TEST-11` Live LocalWP two-site drill evidence for template media, nested templates, custom fonts, frontend render, builder load, and rollback.

### Exit criteria

- Template imports can hydrate embedded media references with checksum-backed attachment creation/reuse and rollback.
- Nested template references are remapped in deterministic apply order or blocked with actionable review errors.
- Arbitrary post/entity references follow an explicit safe-remap policy and are never silently guessed. (Partial: Bricks template preview post/term refs remap by UID/type-slug and preserve unresolved values.)
- Template slug collisions and WordPress unique-slug mutations are detected before apply.
- Mixed option/media/template applies are rollback-safe under partial failure.
- Required automated tests and live LocalWP evidence are logged in the progress tracker.

## 4.4) Phase 23: Broader Bricks Entity and Dynamic Reference Coverage

Status: `IN_PROGRESS`
Owner: Codex
Created: 2026-06-25
Scope: Extend the Phase 22 deterministic reference policy beyond template preview settings into recognized Bricks query controls, post pickers, taxonomy pickers, archive controls, author references, and dynamic-data tokens that can embed WordPress entity IDs.

### UX and operator-effort constraints

- Do not add a new required import step or per-reference decision workflow.
- Safe refs are detected, remapped, and applied automatically using the existing import/apply flow.
- Unresolved optional refs are preserved and summarized; they should not require user input to continue.
- Required unresolved refs may block apply, but the blocker must point to the affected template row and control path instead of asking the user to understand internal descriptor data.
- No new global settings should be added unless a behavior cannot be made deterministic from the package data.

### Tasks / Sub-tasks

- `P23-T1` Bricks control/key discovery pass (Status: IN_PROGRESS - local Bricks 2.3.x query storage and built-in link control storage reviewed; first allowlist is `settings.query.post__in`, `post__not_in`, `tax_query`, `tax_query_not`, `link.postId`, `link.term`, and safe login/logout dynamic tokens)
  - `P23-T1-S1` Re-scan local Bricks 2.3.x source and exported fixture payloads for query, post picker, term picker, archive, author, and dynamic-data storage keys. (Status: IN_PROGRESS - query include/exclude, taxonomy query, built-in internal/taxonomy link storage, and login/logout dynamic post tokens confirmed; Bricks also accepts CSV/dynamic query values that remain preserve-only for now)
  - `P23-T1-S2` Build a narrow allowlist of remappable controls and explicitly document ignored numeric shapes. (Status: IN_PROGRESS - first slice remaps scalar/array IDs, warns for skipped CSV/dynamic/missing-source query refs and unmapped built-in link refs, and still defers query editor PHP, user refs, and unknown numeric controls)
  - `P23-T1-S3` Reuse the existing Phase 22 `dependency_refs` descriptor contract instead of introducing a second reference schema. (Status: DONE for first query slice)
- `P23-T2` Query and picker descriptor extraction (Status: IN_PROGRESS - first query include/exclude and built-in link control descriptor extraction implemented)
  - `P23-T2-S1` Emit `post_or_term` descriptors for recognized post picker and query include/exclude controls. (Status: IN_PROGRESS - implemented for query `post__in`/`post__not_in` scalar/array IDs and built-in `link.postId` controls)
  - `P23-T2-S2` Emit term descriptors for recognized taxonomy/query controls using DBVC term UID and taxonomy+slug context. (Status: IN_PROGRESS - implemented for query `tax_query`/`tax_query_not` scoped term values and built-in `link.term` controls)
  - `P23-T2-S3` Add author/archive descriptor handling only when identity can be resolved deterministically; otherwise preserve and warn.
- `P23-T3` Dynamic-data token handling (Status: IN_PROGRESS - first safe post-token slice implemented for Bricks core `site_login:<post_id>` and `site_logout:<post_id>` tokens)
  - `P23-T3-S1` Tokenize recognized Bricks dynamic-data strings without changing unrelated text. (Status: IN_PROGRESS - implemented for `site_login`/`site_logout` post redirect tokens)
  - `P23-T3-S2` Remap only token segments with a known entity kind and resolver; preserve unknown tokens unchanged. (Status: IN_PROGRESS - implemented for UID/slug-resolved post redirect tokens; runtime-context tokens like `{post_id}` are preserved)
  - `P23-T3-S3` Add descriptor context for token name, payload path, original token, and confidence. (Status: IN_PROGRESS - implemented `dynamic_data_token` metadata on existing `post_or_term` descriptors)
- `P23-T4` Apply remapping and blocker policy (Status: IN_PROGRESS - query refs reuse Phase 22 apply behavior)
  - `P23-T4-S1` Reuse Phase 22 UID-first, exact subtype/taxonomy slug fallback resolvers. (Status: DONE for first query slice)
  - `P23-T4-S2` Preserve unresolved optional refs and block only controls marked as required for valid template rendering. (Status: DONE for first query slice; query refs are optional and preserve source values when unresolved)
  - `P23-T4-S3` Keep source IDs out of fallback matching unless an existing DBVC identity policy explicitly allows it.
- `P23-T5` Validation and tests (Status: IN_PROGRESS - query fixtures, unmapped query/link warning fixtures, built-in link control fixture, compact reference summary fixture, and first dynamic-token fixture added)
  - `P23-T5-S1` Extend import descriptor validation for any new ref kinds or value types. (Status: IN_PROGRESS - first query slice required no new ref/value type; dynamic token metadata validation added for the safe login/logout post-token subset)
  - `P23-T5-S2` Add fixtures for query include/exclude, post picker, term picker, archive/author where deterministic, and dynamic-data token strings. (Status: IN_PROGRESS - query include/exclude remap/preserve, skipped-shape warnings, built-in internal/taxonomy link remap, and `site_login`/`site_logout` dynamic post-token remap added; broader picker/archive/author/dynamic fixtures open)

### Required tests

- `P23-TEST-01` Descriptor extraction for query/post picker/term picker controls. (Partial PASS for query post/term controls and built-in link post/term controls)
- `P23-TEST-02` UID-first and exact slug fallback remap for recognized post/term picker refs. (Partial PASS for UID-first query and built-in link post/term refs)
- `P23-TEST-03` Dynamic-data token remap preserves unrelated token/text segments. (Partial PASS for Bricks core login/logout post redirect tokens)
- `P23-TEST-04` Required unresolved refs block apply; optional unresolved refs preserve source values. (Partial PASS for optional unresolved query refs)
- `P23-TEST-05` Unknown numeric IDs remain untouched and appear only as review warnings. (Partial PASS for skipped Bricks query post/term shapes and unmapped built-in link post/term shapes)

### Exit criteria

- Recognized Bricks query, picker, archive/author, and dynamic-data references follow the same deterministic policy as preview post/term refs.
- No additional operator choices are required for safe remaps.
- Unknown and optional unresolved references are review-visible but preserved.
- Required unresolved references fail apply with a concise row-level blocker.

## 4.5) Phase 24: Low-Friction Review Summaries and Apply Receipts

Status: `IN_PROGRESS`
Owner: Codex
Created: 2026-06-25
Scope: Make reference hydration understandable without creating an overwhelming interface. This phase surfaces compact counts, row-level details, and apply receipts for media, nested template, entity, dynamic-data, preserved, and blocked references.

### UX and operator-effort constraints

- Keep the existing Settings Portability workspace and row modal; do not introduce a new reference-management screen.
- Default display should be a compact `Needs attention` summary, not a full dependency table.
- Do not require users to approve individual remaps. Safe remaps are automatic.
- Reuse existing warning/manual-decision filtering where possible. If one new filter is needed, prefer a single `Needs attention` filter over multiple specialized filters.
- Apply receipts should live in History/Rollback and should not interrupt the apply flow unless apply fails. Keep receipt UI to concise counts by default; detailed source-to-target maps are backend support/debug data, not a new operator workflow.

### Tasks / Sub-tasks

- `P24-T1` Summary aggregation (Status: IN_PROGRESS - review rows now include compact template reference counts for safe refs, media, nested templates, entity/query/link/dynamic refs, preserved refs, unknown refs, and blocked refs)
  - `P24-T1-S1` Add backend summary counts for remapped, preserved, blocked, media-created, media-reused, and unknown refs. (Status: IN_PROGRESS - review-time template counts implemented; apply-time media-created/reused and actual remap receipts remain in `P24-T3`)
  - `P24-T1-S2` Roll counts up to domain and row summaries using existing review session payloads. (Status: IN_PROGRESS - row-level `template_reference_summary` is passed through existing review payloads; domain/session rollups remain open)
  - `P24-T1-S3` Keep descriptor internals available for debugging but hidden by default. (Status: IN_PROGRESS - counts are exposed in the compact reference summary while existing descriptors remain in source payload/debug data)
- `P24-T2` Row modal/reference details (Status: IN_PROGRESS - existing row modal reference summary now shows one compact `Template refs` count line; Applied and Backup summaries show one compact receipt line after apply; detailed path/action/reason expansion remains open)
  - `P24-T2-S1` Add a compact reference section to the existing row modal. (Status: IN_PROGRESS - reused the existing reference summary area; no new screen or controls)
  - `P24-T2-S2` Show human-readable control path, action, and reason; avoid raw JSON unless the existing diff/debug view is expanded. (Status: NOT_STARTED for path/action/reason details)
  - `P24-T2-S3` Link blockers to the affected row and path without adding per-reference controls.
- `P24-T3` Apply receipts (Status: IN_PROGRESS - apply result, session approval, backup records, recent backup records, rollback result, and rollback session view now carry compact receipts without new operator decisions)
  - `P24-T3-S1` Store source-to-target attachment, nested template, post, term, and dynamic-data remap summaries in backup/history records. (Status: IN_PROGRESS - backend receipt stores compact counts plus source-to-target maps for template posts, template media, font/icon media, nested templates, post/term refs, query/link refs, and dynamic-data refs)
  - `P24-T3-S2` Store preserved unresolved refs and blocker reasons with enough context for troubleshooting. (Status: IN_PROGRESS - preserved and blocked reference counts/maps are recorded; richer failure-reason text remains open)
  - `P24-T3-S3` Surface final template post IDs/slugs and created/reused media counts in rollback history. (Status: IN_PROGRESS - final template/media counts are surfaced in compact Applied/Backup receipt lines; slug-level history detail remains open)
- `P24-T4` Minimal filtering (Status: NOT_STARTED)
  - `P24-T4-S1` Reuse existing warning-state filters for unresolved/blocker cases where possible.
  - `P24-T4-S2` Add only one combined `Needs attention` filter if existing filters cannot cover blockers plus preserved unresolved refs clearly.

### Required tests

- `P24-TEST-01` REST review payload includes compact reference summary counts. (Partial PASS for row-level template reference summary counts)
- `P24-TEST-02` Row modal payload includes path/action/reason details without requiring new decisions.
- `P24-TEST-03` Apply receipt records remapped, preserved, created/reused, and blocked reference summaries. (Partial PASS for compact template reference apply receipts)
- `P24-TEST-04` History/Rollback endpoint surfaces receipt summaries after apply and rollback. (Partial PASS for session approval, backup record, recent backup record, rollback result, and rollback session view receipts)

### Exit criteria

- Operators can understand what DBVC remapped or preserved from a compact summary.
- Safe imports still require no new user choices.
- Blocked imports give actionable row-level reasons.
- Apply receipts provide enough detail for support/debugging without expanding the default UI.

## 4.6) Phase 25: Idempotency and Mixed Rollback Hardening

Status: `NOT_STARTED`
Owner: Codex
Created: 2026-06-25
Scope: Harden repeated applies, mixed-domain rollback, and failure handling across options, media, custom fonts/icons, Bricks templates, nested template refs, and entity/dynamic references.

### UX and operator-effort constraints

- No new required UI controls.
- Recovery should use the existing rollback/history workflow.
- Idempotency should be automatic: reapplying the same safe package should reuse existing target objects rather than asking for manual conflict decisions.

### Tasks / Sub-tasks

- `P25-T1` Repeated apply idempotency (Status: NOT_STARTED)
  - `P25-T1-S1` Prove repeated template applies reuse attachments by checksum and do not duplicate equivalent media.
  - `P25-T1-S2` Prove repeated nested template remaps do not drift IDs or create duplicate templates.
  - `P25-T1-S3` Prove repeated font/icon applies reuse existing created posts/attachments where supported.
- `P25-T2` Dependency map backup state (Status: NOT_STARTED)
  - `P25-T2-S1` Store dependency maps needed to undo created media/templates and understand remap state.
  - `P25-T2-S2` Include final target IDs/slugs in backup records without exposing additional import controls.
- `P25-T3` Mixed partial-failure rollback (Status: NOT_STARTED)
  - `P25-T3-S1` Add controlled failure fixtures after media creation and before template writes.
  - `P25-T3-S2` Add controlled failure fixtures after template writes and before option writes.
  - `P25-T3-S3` Verify rollback restores options, replaced templates, created templates, and DBVC-created media while leaving pre-existing target objects alone.
- `P25-T4` Stale session and package hardening (Status: NOT_STARTED)
  - `P25-T4-S1` Verify stale review sessions block unsafe applies and can refresh through the existing session refresh action.
  - `P25-T4-S2` Add malformed package variants for bad media paths, bad descriptor paths, missing checksums, and unexpected files.

### Required tests

- `P25-TEST-01` Repeated apply reuses created/reused attachments and templates.
- `P25-TEST-02` Mixed-domain failure before template writes rolls back DBVC-created media.
- `P25-TEST-03` Mixed-domain failure after template writes restores posts/meta/terms/options.
- `P25-TEST-04` Stale review session blocks unsafe apply and refreshes cleanly.
- `P25-TEST-05` Malformed package variants are rejected before apply.

### Exit criteria

- Repeated safe applies are deterministic and do not create duplicate objects.
- Mixed option/media/font/icon/template failures roll back through the existing backup workflow.
- Package/session hardening prevents unsafe apply without adding new operator decisions.

## 4.7) Phase 26: Live LocalWP Drill and Release Gate

Status: `NOT_STARTED`
Owner: Codex
Created: 2026-06-25
Scope: Validate the full Bricks Settings Portability flow against real LocalWP source/target sites and close the Bricks template portability hardening cycle with evidence.

### UX and operator-effort constraints

- Use the same default export/import/apply/rollback flow an operator would use.
- Record required observations in docs; do not add setup-only UI.
- Prefer one package covering fonts, icons, templates, media refs, nested templates, preview refs, query/dynamic refs, and rollback rather than many manual micro-drills.

### Tasks / Sub-tasks

- `P26-T1` Source fixture build (Status: NOT_STARTED)
  - `P26-T1-S1` Create or identify a source LocalWP site with custom fonts/icons, templates with embedded media, nested templates, preview refs, and query/dynamic refs.
  - `P26-T1-S2` Record fixture object labels/slugs and expected render behavior.
- `P26-T2` Target apply drill (Status: NOT_STARTED)
  - `P26-T2-S1` Export selected domains from source and import into target.
  - `P26-T2-S2` Apply with default safe decisions and record remap/preserve/block summaries.
  - `P26-T2-S3` Verify Bricks builder load, frontend media render, nested template render, font render, and query/dynamic behavior.
- `P26-T3` Rollback drill (Status: NOT_STARTED)
  - `P26-T3-S1` Roll back the apply package from History/Rollback.
  - `P26-T3-S2` Verify target options, templates, terms, and DBVC-created media return to pre-apply state.
- `P26-T4` Release gate documentation (Status: NOT_STARTED)
  - `P26-T4-S1` Record commands, screenshots or notes, package IDs, backup IDs, and observed warnings/blockers in the tracker.
  - `P26-T4-S2` Decide whether remaining open cases are release blockers or post-MVP backlog.

### Required tests/evidence

- `P26-EVIDENCE-01` Live export/import/apply drill evidence.
- `P26-EVIDENCE-02` Builder and frontend render verification.
- `P26-EVIDENCE-03` Rollback verification.
- `P26-EVIDENCE-04` Final go/no-go note with known limitations.

### Exit criteria

- LocalWP source-to-target portability succeeds with default user actions.
- Frontend and builder behavior are verified after apply.
- Rollback restores target state.
- Remaining limitations are documented as explicit non-blockers or follow-up backlog.

## 4.8) Backlog Candidates (Future Phase / Not Yet Scheduled)

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
