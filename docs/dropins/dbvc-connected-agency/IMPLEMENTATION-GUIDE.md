# Implementation guide

## Objective

Build an optional connected-environment and agency-management feature family inside DBVC. First make changes observable and correctly scoped. Then establish reliable comparisons. Only then add bounded, reviewed transfers using DBVC's existing apply and recovery owners.

The successful first pilot records edits to individual Bricks classes and variables, plus service CPT content, without mutating that content. A subscribed production framework edit enters studio review and the same client's enabled staging subscriptions. A service change enters only that client's environment inbox. Another client's site receives neither. Offline staging catches up without being marked clean while disconnected.

## Milestones and delivery gates

| Milestone | Build | Exit evidence | Remains deferred |
|---|---|---|---|
| M0: local reconciliation | Baseline, reuse map, module gates, documented integration decisions, fixture contract | Actual checkout and dirty state recorded; active WP path known or explicitly unavailable; no unrelated changes | Network and content application |
| M1: local observation | Bricks class/variable adapters, dirty generation tracking, canonical snapshots, durable outbox, inspection | Real save produces one settled observation; export-disabled and Visual-Editor-disabled cases work; restart loses no pending work | Hub transmission |
| M2: authenticated reporting | Hub enrollment, scoped receipt/delivery, client outbound batch/poll worker, service CPT adapter, minimal status UI | A/B isolation, duplicates, revocation, retries, ordering, offline catch-up, exact runtime storage evidence | Application and automatic promotion |
| M3: comparison | Pair/object baselines, framework subscriptions/adopted versions, approved overrides, freshness and coverage | Three-way matrix and independent framework status against actual fixtures | Deployment |
| M4: preparation | Immutable release manifest, references, per-target prepare receipt, compatibility and storage fingerprints | Exact proposed patch or explicit blocker; prepare leaves target content unchanged | Execution |
| M5: one round trip | One supported domain, reviewed execution, independent verification, compensation | One target succeeds; stale target blocks; lost response is recoverable; rollback conflict preserved | Broad rollout |
| M6: fleet rollout | Canary/cohort scheduling, pause and outcome review, rendering evidence, retention | Measured load and canary recovery; no false fleet-wide atomicity claim | Product extraction until justified |

The kickoff covers M0 and the first coherent M1 slice. Continue within that scope without routine confirmation. If PHP/WordPress is unavailable, finish useful code and contract work, state the blocker, and do not mark live gates complete. The package itself completes none of M0–M6 in the user's checkout.

## M0 checklist

- Read repository guidance and narrowly query current capability inventory.
- Identify the DBVC checkout, branch, HEAD, dirty changes, plugin updater origin, loaded plugin path, PHP support range, WP/Bricks/ACF versions, test harness, cron/job helpers, and source packaging rules.
- Inspect current bootstrap and add-on settings rather than assuming a registry. Verify existing Bricks connection functions before reuse.
- Resolve whether proposed destination folders/options/namespaces already exist. Extend compatible local work rather than overwrite it.
- Record exact hooks, storage shapes, ordering semantics, source gates, side effects, and public service signatures for the first two domains.
- Keep stable identity initialization separate from read-only inventory. Missing identity is an explicit condition, not permission to backfill during a read.
- Select one PHP-facing composition seam and map package templates onto local conventions.
- Establish before measurements for save latency, idle requests, memory, DB queries, and queue throughput in the disposable lab.

## M1 vertical slice

1. Add optional connector gate and module-local loading, disabled by default. Add the hub gate only as an inert boundary.
2. Connect a read-only Bricks observer to verified current storage. It must enumerate and snapshot individual logical objects while preserving meaningful collection order.
3. Build the minimal identity sidecar and explicitly initialize it in the disposable fixture or enrollment step.
4. Register complete, bounded capture hints for verified Bricks option create/update/delete operations. Exclude the connector's own stores. Keep hooks independent of automatic exports and Visual Editor.
5. Persist dirty generation, schedule work, and reread after writes settle. If generation changed during processing, leave work pending.
6. Commit snapshot revision, event sequence, outbox record, and conditional dirty acknowledgement consistently. Use a durable DB transaction where supported; otherwise design and test crash reconciliation before claiming durability.
7. Provide read-only developer inspection of pending/error/freshness state. Name new CLI/API surfaces as proposed until actually registered and tested.
8. Add focused integration tests for signal suppression, multi-save coalescing, ordered changes, concurrent generations, restart, duplicate processing, and no source-content mutation.

## M2 reporting slice

Implement one chosen authentication scheme and hub-mediated transport. Do not implement both direct peer push and hub relay. The connector initiates every connection, including LocalWP inbox retrieval. Hub processing stores observations and pending deliveries before acknowledging durable receipt. Routing uses authenticated membership and enrolled epoch. Transmitted object data cannot assign agency/client membership or recipients.

Add service CPT capture using existing DBVC identity and scoped ACF/meta semantics. Determine allowlisted fields and masked-data handling from current code. Missing dependencies create partial coverage, never a clean comparison.

Enrollment, registry maintenance, worker execution and report persistence are writes to management data. Reporting-only means no domain content application; it does not mean every request is read-only. Record this distinction in capability metadata.

## Implementation workflow

Deliver small slices whose code, tests, capability changes, and documentation can be reviewed together. Use current repository tools; do not add a second Composer project under `docs/dropins` or a new JS build system. Package Python utilities are developer tooling, not plugin dependencies.

For every slice report: actual source owner, behavior added, evidence run, limitations, and next gate. Follow `docs/agents/MAINTENANCE.md` for changed CLI/REST/admin/hooks/settings/tables/scheduled hooks/add-on contracts. Verify how its scanner handles `.stub` files before moving templates. Do not manually edit generated capability indexes or claim `live_runtime_verified` from source inspection.

## Definition of done for the observation pilot

- Both add-ons disabled: no new transport, routes, workers, domain scanning, tables or runtime assets from this feature.
- Connector only: no hub administration, hub routes, or hub storage.
- Hub only: no connector enrollment of itself, Bricks requirement, Visual Editor requirement, or client save listeners.
- Reporting is correct across two client ecosystems, including disabled/revoked/offline states.
- Crashes, concurrent workers, sequence gaps, redelivery, and stale snapshots remain visible and recoverable.
- Credentials, connection identities and queue state are excluded from content/configuration transfer.
- Hosting and LocalWP freshness limitations are presented accurately.
- Actual data and performance evidence is recorded. No unattended content writes exist.

## Later product extraction

Keep this implementation in DBVC until independent release cadence, external distribution, non-DBVC consumers, or measured hub infrastructure needs justify extraction. First package the hub separately in the same repository if useful; move repositories only when that solves a real workflow problem. Preserve table/identity ownership and protocol compatibility through an explicit migration, not a folder rename.

## Delivery status and follow-ups (2026-09-22)

M0–M6 are all implemented on stacked, merged branches; the live board is `tracking/tasks.json` and `tracking/SESSION-HANDOFF.md`. **M6 (fleet rollout) is complete and live-verified** (2026-09-21, master `94fade2`; schema migrated v8→v10 on the live site, the `agency/rollout-*` CLI/routes exercised, the Rollouts admin page rendered). M1–M5 remain `implemented_pending_live_site_gate`.

Open follow-up — **admin-page colour readability (light + dark)**: some text is low-contrast (the active section-nav tab is white-on-near-white in dark mode; `--dbvc-ce-color-text-subtle` `#8c8f94` fails WCAG AA on white and is thin on the dark surfaces). A concrete, measured remediation plan (findings table + token/rule fixes + verification) is in `docs/implementation/active/connected-environments-admin-page.md` §5.4. It is a tokens-and-one-nav-rule change with no behaviour impact; ship it as its own small slice.
