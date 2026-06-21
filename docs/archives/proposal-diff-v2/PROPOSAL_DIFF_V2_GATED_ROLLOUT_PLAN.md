# Proposal Diff v2 Gated Rollout Plan

Date: 2026-05-23

Related docs:

- `docs/PROPOSAL_DIFF_SYSTEM_AUDIT_2026_05.md`
- `docs/PROPOSAL_DIFF_SYSTEM_MINOR_UPDATE_IMPLEMENTATION_GUIDE.md`
- `docs/PROPOSAL_DIFF_V2_DEPENDENCY_MANIFEST_2026_05.md`

Status: planning only. No production code was changed for this rollout plan.

## Objective

Build Proposal Diff v2 without breaking the current Proposal/diff v1 workflow.

The rollout strategy is:

1. Preserve v1 as the default and authoritative system.
2. Create a frozen v1 baseline copy or facade before refactoring.
3. Build v2 beside v1, not inside v1.
4. Run v2 in read-only shadow mode first.
5. Compare v1 and v2 outputs with explicit compatibility reports.
6. Enable v2 only by gate, scope, and proposal allowlist.
7. Keep rollback to v1 immediate until v2 is proven.

## Rollout Principles

### 1. V1 Remains Authoritative Until Explicitly Switched

Default behavior must remain:

- `/dbvc/v1/proposals...` uses v1.
- `wp dbvc proposals ...` uses v1.
- admin Proposal Review uses v1.
- Entity Editor transfer packets upload into v1 Proposal Review.
- v1 decision stores remain unchanged by v2 shadow reads.

### 2. V2 Is A Separate System

V2 should not be hidden inside `DBVC_Admin_App` as a partial refactor.

Recommended v2 location:

- `includes/Dbvc/ProposalDiff/V2/`

Possible v2 services:

- `Repository`
- `UploadValidator`
- `SnapshotStateService`
- `DiffService`
- `DecisionStore`
- `ResolverSummaryService`
- `PreflightService`
- `ApplyPlanService`
- `ApplyReceiptStore`
- `CompatibilityReporter`

Recommended v1 baseline location if a code copy is made later:

- `includes/Dbvc/ProposalDiff/V1/`

Important: do not make that copy in a mixed behavior-change commit. First create the v1 baseline/facade and prove no behavior change. Then build v2 separately.

### 3. V2 Has Separate Routes

Keep v1 routes unchanged.

Recommended v2 namespace:

- `/dbvc/v2/proposals`
- `/dbvc/v2/proposals/{proposal_id}/preflight`
- `/dbvc/v2/proposals/{proposal_id}/entities`
- `/dbvc/v2/proposals/{proposal_id}/entities/{vf_object_uid}`
- `/dbvc/v2/proposals/{proposal_id}/compatibility`
- `/dbvc/v2/proposals/{proposal_id}/receipts`

Do not repurpose `/dbvc/v1/proposals` for v2 until after v2 is stable and deliberately promoted.

### 4. V2 Has Separate Storage

V2 shadow and preflight must not write to v1 stores.

V1 stores to preserve:

- `dbvc_proposal_decisions`
- `dbvc_resolver_decisions`
- `dbvc_masked_field_suppressions`
- `dbvc_mask_overrides`
- v1 proposal folders under `DBVC_Backup_Manager::get_base_path()`
- v1 snapshots under `DBVC_Snapshot_Manager::get_base_path()`

Suggested v2 stores:

- `dbvc_proposal_diff_v2_settings`
- `dbvc_proposal_diff_v2_sessions`
- `dbvc_proposal_diff_v2_shadow_reports`
- `dbvc_proposal_diff_v2_receipts`
- optional per-proposal JSON under a v2-only receipt/session directory

Shadow mode may read v1 proposals, snapshots, decisions, resolver decisions, and masking stores. It must not mutate them.

## Feature Gates

Recommended gates:

| Gate | Type | Default | Purpose |
| --- | --- | --- | --- |
| `DBVC_PROPOSAL_DIFF_V2_ENABLED` | PHP constant | false | Master kill switch. |
| `dbvc_proposal_diff_engine` | option | `v1` | Selected engine: `v1`, `v2_shadow`, `v2_preview`, `v2_apply_allowlist`, `v2_default`. |
| `dbvc_proposal_diff_v2_shadow_enabled` | option | false | Enable read-only v2 comparison. |
| `dbvc_proposal_diff_v2_ui_enabled` | option | false | Show v2 preflight/compatibility UI. |
| `dbvc_proposal_diff_v2_cli_enabled` | option | false | Allow CLI v2 commands/flags. |
| `dbvc_proposal_diff_v2_apply_enabled` | option | false | Allow v2 apply for allowlisted proposals. |
| `dbvc_proposal_diff_v2_apply_allowlist` | option | empty | Proposal IDs allowed to use v2 apply. |
| `dbvc_proposal_diff_v2_transfer_shadow` | option | true | Run transfer packets through v2 shadow, read-only. |
| `dbvc_proposal_diff_v2_write_receipts` | option | false in shadow | Enable v2 receipt writes only when not shadowing. |

Master rule:

- If the PHP constant is false, v2 code must not serve write paths even if options are accidentally enabled.

## Rollout Phases

### Phase R0: Dependency Freeze

Goal:

- Lock the v1 dependency manifest and baseline tests before any v2 code exists.

Tasks:

- Confirm `docs/PROPOSAL_DIFF_V2_DEPENDENCY_MANIFEST_2026_05.md`.
- Add or identify tests for all hard dependency surfaces.
- Record current REST response samples for:
  - proposal list
  - entity list
  - entity detail
  - resolver report
  - masking report
  - transfer packet proposal
  - apply response
- Record current CLI behavior for:
  - proposal list
  - upload
  - apply
  - recapture snapshots
  - duplicate cleanup

Exit criteria:

- A failing compatibility test clearly tells which v1 surface changed.
- No v2 code is required yet.

### Phase R1: V1 Baseline Copy Or Facade

Goal:

- Preserve v1 behavior in an isolated baseline before refactoring.

Two acceptable paths:

1. Facade-first:
   - Keep `DBVC_Admin_App` routes.
   - Add v1 service facades that call current methods without behavior change.
   - Tests prove route output is identical.

2. Copy-first:
   - Copy current v1 proposal logic into `includes/Dbvc/ProposalDiff/V1/`.
   - Route existing v1 calls through the copy only after behavior parity tests pass.
   - Do not mix the copy with fixes or v2 behavior.

Recommendation:

- Use facade-first unless a true copy is required for safe refactoring. A blind large copy can make future fixes harder to track.

Exit criteria:

- V1 behavior is still default.
- V1 tests pass.
- No v2 behavior is visible to users.

### Phase R2: V2 Read-Only Services

Goal:

- Build v2 read services with no writes.

Allowed services:

- repository reads
- upload validation simulation
- snapshot state reads
- diff generation
- decision read-only summary
- resolver summary read-only wrapper
- preflight read-only plan
- compatibility report

Forbidden in this phase:

- decision writes
- resolver decision writes
- masking writes
- proposal status writes
- apply writes
- receipt writes
- snapshot capture
- duplicate cleanup

Exit criteria:

- V2 can inspect a v1 proposal read-only.
- V2 can produce a preflight plan.
- V2 can produce a compatibility report comparing v1 output and v2 output.

### Phase R3: Shadow Mode

Goal:

- Run v2 read-only beside v1 and compare outputs.

Behavior:

- V1 remains authoritative.
- V2 computes read-only preflight/diff/summary after or beside v1 reads.
- Store shadow reports only in v2 shadow storage.
- Do not block user workflows based on v2 output.

Compare:

- entity counts
- new entity counts
- diff counts
- decision summaries
- resolver metrics
- transfer packet warnings
- snapshot state
- preflight warnings
- skipped reason classification

Exit criteria:

- Shadow reports are stable.
- Differences are explained and either accepted or fixed.
- No v1 store changes are caused by shadow reads.

### Phase R4: V2 Preview UI And CLI

Goal:

- Expose v2 preflight without making v2 apply.

UI:

- Add optional v2 preflight panel.
- Label clearly as preview.
- Keep apply button on v1.

CLI:

- Add optional command or flag:
  - `wp dbvc proposal-diff-v2 preflight <proposal_id> --format=json`
  - or `wp dbvc proposals preflight <proposal_id> --engine=v2`

Exit criteria:

- Operators and agents can inspect v2 preflight.
- V1 apply remains the only write path.

### Phase R5: V2 Receipts For Dry Runs

Goal:

- Prove v2 receipt shape without mutating WordPress content.

Behavior:

- V2 can write dry-run receipts only.
- Receipt must state `dry_run: true`.
- Receipt must not imply content was applied.

Exit criteria:

- Receipt format is stable.
- Agents can retrieve receipts.
- No content writes occur.

### Phase R6: Allowlisted V2 Apply

Goal:

- Enable v2 apply only for explicitly allowlisted disposable proposals.

Requirements:

- PHP constant enabled.
- `dbvc_proposal_diff_v2_apply_enabled` true.
- Proposal ID in allowlist.
- Fresh v2 preflight token.
- Explicit confirm field.
- Apply receipt enabled.
- Backup or pre-apply evidence captured.

Allowed initial scope:

- existing post/CPT path-level decisions
- new post/CPT with `accept_new`

Blocked initial scope:

- options
- option groups
- menus
- third-party entities
- term apply until term masking and parent/meta tests pass
- unresolved media conflicts

Exit criteria:

- Disposable proposal applies correctly.
- Receipt accurately records writes/skips.
- Rollback to v1 is immediate by disabling the gate.

### Phase R7: Transfer Packet Shadow And Allowlist

Goal:

- Validate transfer packet compatibility before any transfer packet can use v2 apply.

Behavior:

- Transfer packets remain v1 by default.
- V2 shadow compares transfer metadata and preflight warnings.
- V2 apply for transfer packets requires separate allowlist.

Checks:

- `origin` preserved.
- `selection` preserved.
- `requirements` preserved.
- `warnings` preserved.
- missing post type warnings match.
- missing taxonomy warnings match.
- unsupported post reference warnings match.
- new entity gating still applies.

Exit criteria:

- Transfer packet v2 shadow reports are clean.
- Transfer packet v2 apply remains off unless explicitly allowed.

### Phase R8: V2 Default For Reads

Goal:

- Make v2 the default read/preflight engine while v1 remains available.

Behavior:

- Proposal list/detail may include v2-derived preflight fields.
- V1 diff fields remain available for compatibility.
- Apply stays v1 unless v2 apply is separately enabled.

Exit criteria:

- Admin UI and CLI continue to work.
- Add-on dependency tests pass.
- No increase in support-risk issues.

### Phase R9: V2 Default For Apply

Goal:

- Make v2 apply default only after all entity contracts are proven.

Requirements:

- posts/CPTs tested
- terms tested
- media tested
- masking tested
- transfer packets tested
- CLI tested
- apply receipts tested
- rollback/recovery story accepted

Exit criteria:

- V2 apply can be switched back to v1 immediately.
- V1 code remains present for at least one minor release after v2 default.

## V1 To V2 Boundary

V1 remains responsible for:

- current admin Proposal Review until switch
- current WP-CLI proposal commands until switch
- current transfer packet destination apply until switch
- current decision store
- current resolver decision store
- current masking directives
- current proposal folder storage

V2 may read:

- v1 manifests
- v1 proposal payloads
- v1 snapshots
- v1 decision summaries
- v1 resolver decisions
- v1 masking directives

V2 may not write in shadow mode:

- v1 decisions
- v1 resolver decisions
- v1 masking stores
- v1 proposal status
- v1 manifests
- v1 snapshots
- WordPress posts/terms/options/media

## Proposed V2 Route Contract

Read-only routes first:

| Route | Purpose |
| --- | --- |
| `GET /dbvc/v2/proposals` | v2 proposal list summary. |
| `GET /dbvc/v2/proposals/{proposal_id}` | v2 proposal header, status, manifest facts. |
| `GET /dbvc/v2/proposals/{proposal_id}/entities` | v2 entity summaries. |
| `GET /dbvc/v2/proposals/{proposal_id}/entities/{vf_object_uid}` | v2 entity detail and trusted diff state. |
| `GET /dbvc/v2/proposals/{proposal_id}/preflight` | v2 read-only apply plan. |
| `GET /dbvc/v2/proposals/{proposal_id}/compatibility` | v1-v2 comparison report. |
| `GET /dbvc/v2/proposals/{proposal_id}/receipts` | v2 dry-run/apply receipts. |

Write routes later:

| Route | Purpose | Gate |
| --- | --- | --- |
| `POST /dbvc/v2/proposals/{proposal_id}/receipts/dry-run` | Write dry-run receipt. | v2 receipts gate. |
| `POST /dbvc/v2/proposals/{proposal_id}/apply` | v2 apply. | master gate, apply gate, allowlist, preflight token, confirm. |

## Proposed CLI Contract

Keep existing:

- `wp dbvc proposals ...` remains v1.

Add optional:

- `wp dbvc proposal-diff-v2 inspect <proposal_id> --format=json`
- `wp dbvc proposal-diff-v2 preflight <proposal_id> --format=json`
- `wp dbvc proposal-diff-v2 compatibility <proposal_id> --format=json`
- `wp dbvc proposal-diff-v2 receipts <proposal_id> --format=json`
- `wp dbvc proposal-diff-v2 apply <proposal_id> --confirm --preflight-token=<token>`

Do not overload existing commands until v2 is the accepted default.

## Compatibility Report Shape

V2 shadow should emit a report like:

```json
{
  "proposal_id": "example",
  "mode": "shadow",
  "generated_at": "2026-05-23T00:00:00Z",
  "v1": {
    "entity_count": 10,
    "decision_total": 4,
    "resolver_unresolved": 1
  },
  "v2": {
    "entity_count": 10,
    "decision_total": 4,
    "resolver_unresolved": 1
  },
  "matches": true,
  "differences": [],
  "warnings": [],
  "writes_performed": false
}
```

Compatibility differences should be classified:

- `expected_improvement`
- `v2_missing_v1_field`
- `v2_extra_warning`
- `v2_count_mismatch`
- `v2_resolver_mismatch`
- `v2_transfer_metadata_mismatch`
- `v2_snapshot_state_mismatch`
- `v2_decision_summary_mismatch`

## Rollback Plan

Rollback must be instant during every phase:

1. Set `DBVC_PROPOSAL_DIFF_V2_ENABLED` false.
2. Set `dbvc_proposal_diff_engine` to `v1`.
3. Disable v2 UI, CLI, shadow, and apply gates.
4. Keep v1 routes and methods untouched.
5. Do not migrate v1 stores in-place.

If v2 apply has been used:

- Keep v2 receipt.
- Use the receipt and pre-apply evidence to manually review writes.
- Do not delete v1 proposal folder or decisions as part of rollback.

## Add-On Safety Matrix

| Area | Default during rollout | When v2 can affect it |
| --- | --- | --- |
| Admin Proposal Review | v1 | R8 read default, R9 apply default. |
| Entity Editor transfer packets | v1 | R7 allowlist only. |
| WP-CLI proposals | v1 | Separate v2 command first; existing commands later. |
| Masking | v1 stores | Only after v2 masking compatibility tests pass. |
| Media resolver | v1 original-ID rules | V2 can add stable identity, not replace v1 rules. |
| Bricks proposals | separate Bricks routes | Never as part of core Proposal Diff v2. |
| Visual Editor | unaffected | Only if shared DBVC bootstrap/capability code is touched. |
| Content Migration | unaffected | Only if shared DBVC import helpers are touched. |
| Configuration Portability | existing option names | Add v2 options separately. |
| AI package upload | v1 proposal upload detector | If v2 upload route is added, explicitly delegate or reject AI packages. |

## Required Tests Before Each Gate

Before R3 shadow mode:

- v1 proposal list test
- v1 entity list/detail test
- v1 transfer packet upload validation tests
- v1 masking endpoint tests
- v1 resolver rule tests
- v1 CLI proposal command smoke

Before R6 allowlisted v2 apply:

- v2 preflight tests
- v2 receipt tests
- v2 no-write shadow tests
- v2 apply blocked without token
- v2 apply blocked without allowlist
- v2 apply blocked for unsupported entity types
- v2 post/CPT apply disposable fixture test

Before R7 transfer packet allowlist:

- transfer packet source preview test
- transfer packet upload test
- transfer metadata preservation test
- transfer destination warning compatibility test
- transfer v2 preflight shadow test

Before R9 v2 apply default:

- full proposal suite
- transfer packet suite
- masking suite
- media resolver suite
- CLI suite
- browser smoke for admin Proposal Review
- rollback/recovery drill

## Documentation Updates Required During Rollout

Before implementation:

- Dependency manifest finalized.
- Gated rollout plan accepted.
- Minor update implementation guide references v2 gate.

During shadow:

- Document how to enable shadow mode.
- Document how to read compatibility reports.
- Document known expected differences.

Before v2 apply:

- Document preflight token behavior.
- Document receipt storage.
- Document supported entity matrix.
- Document rollback steps.

Before v2 default:

- Update user docs.
- Update CLI docs.
- Update transfer packet docs.
- Clarify Bricks proposal naming separation.

## Bottom Line

Proposal Diff v2 should not be a refactor that silently changes v1 behavior. It should be a parallel engine with explicit gates, separate routes, separate storage, read-only shadow comparison, and allowlisted writes. V1 stays available until the add-on dependency manifest is green and v2 has proven it can explain exactly what it will do before it writes.
