# Agency Control + DBVC Connected Environments

Status: **proposed; documentation reconciliation only.** This guide promotes a preserved Agency Control handoff into DBVC's documentation library. It does not add an add-on, REST route, table, schedule, credential, network request, or content writer.

The source package is retained unchanged in [`source/`](source/SOURCE-MANIFEST.md). Its historical source review is useful evidence, not proof that the current checkout, runtime, or client sites have the same capabilities.

## Product decision under evaluation

Agency Control is a proposed agency-facing coordination layer. DBVC remains the site-side engine for portable identity, snapshots, comparison, proposal preparation, application, verification, and recovery. A separate hub would later own client/environment enrollment, explicit subscriptions, event intake, routing, review projections, and rollout coordination.

The first useful scene is deliberately small:

1. A managed Bricks class changed on Client A production appears in the appropriate framework review and Client A's subscribed staging view.
2. A `service` post changed on Client A production appears only in Client A's connected-environment view.
3. Client B receives neither item.
4. Reporting changes no site content and never promotes a client edit into a framework release automatically.

## Read order for Codex or Claude Code

1. [`AGENTS.md`](../../../../AGENTS.md)
2. [`docs/README.md`](../../../README.md) and [`docs/agent-entrypoints.md`](../../../agent-entrypoints.md)
3. [M0 discovery and contract guide](m0-discovery.md)
4. [Package-to-current-state reconciliation](reconciliation.md)
5. `addons/bricks/docs/BRICKS_ADDON_PLAN.md` and `addons/bricks/docs/BRICKS_ADDON_PROGRESS_TRACKER.md`
6. `docs/implementation/proposed/bricks-portability-drift-manager/README.md` and `docs/implementation/proposed/cross-site-entity-packet-guide.md`

Do not begin implementation from the preserved archive alone. Reconcile the active branch, dirty boundary, runtime versions, existing contracts, and relevant tests first.

## Scope and non-goals

In scope for planning:

- An optional, default-off DBVC connected-environments seam.
- Observation of one Bricks class, one Bricks variable, and one `service` post.
- Explicit ownership/subscription routing, offline delivery semantics, pair-specific baselines, and reviewed future application.
- A separate hub repository only after the DBVC-side observation contract is stable.

Out of scope until later milestones:

- Direct site-to-site peer transport alongside hub-mediated transport.
- Automatic content application, conflict merging, or save-to-production mirroring.
- Fleet-wide automatic rollout, customer portals, AI approval, secret transport, and database cloning.
- Treating Visual Editor journals, export hooks, or a successful HTTP response as complete change authority.

## Reconciled delivery sequence

| Milestone | Deliverable | Must be true before advancing |
|---|---|---|
| M0 | Current-state evidence and a frozen first-domain contract | Local checkout/runtime, add-on loading, storage shapes, hooks, and reusable services are evidenced; no hidden rewrite |
| M1 | Default-off local observation | Order-aware snapshots, dirty-object reconciliation, and a durable outbox report changes without content writes or network work during saves |
| M2 | Connected reporting pilot | Authenticated enrollment, event receipts, explicit subscriptions, inbox cursors, duplicate handling, and two-client isolation work in disposable environments |
| M3 | Comparison and decisions | Environment baselines, framework versions, and approved overrides remain distinct and explainable |
| M4 | Prepare only | Destination identity resolution and dependency ledgers return an exact patch or an explicit blocker; nothing applies |
| M5 | One reviewed round trip | A selected domain survives divergent IDs, conflicts, lost responses, retries, verification, and recovery in disposable environments |
| M6 | Controlled rollout | Immutable releases move through canaries/cohorts with per-target receipts and pause-on-failure behavior |

M0 through M4 are planning and evidence gates, not permission to deploy or change a live site. M5 and M6 each require separately scoped writer, runtime, and recovery authority.

## Architecture boundaries

| Boundary | Proposed owner | Rule |
|---|---|---|
| Client/environment registry, subscriptions, event routing | Agency Control hub | The hub never gains blanket authority to write client content |
| Enrollment, local outbox/inbox, capability reporting | Optional DBVC module | Disabled by default; reporting and execution are separate advertised capabilities |
| Identity, snapshots, comparison, apply, verification, recovery | DBVC core and domain adapters | Reuse proven services through adapters; do not copy a hidden importer into the hub |
| Bricks classes, variables, templates, references | Bricks adapter | Preserve individual managed objects without overwriting unrelated option entries |
| Visual Editor context | Visual Editor add-on | Optional enrichment only, never the sole capture source |

See [the reconciliation map](reconciliation.md) for current DBVC touchpoints and proof requirements.

## Operator experience contract

The proposed experience removes the burden of translating raw transport and diff state. It should provide three views:

- **Fleet:** adopted framework objects, freshness, version lag, local drift, and approved overrides.
- **Client:** production/staging comparisons, incoming/outgoing changes, conflicts, and offline state.
- **Object:** lineage, known consumers, subscriptions, override decision, and action history.

`behind_version`, `local_drift`, `approved_override`, `conflict`, `unknown`, `offline`, and `unsupported` must remain distinct. Zero visible events never proves a site is clean.

## Documentation maintenance

Update this guide and [`docs/roadmap.md`](../../../roadmap.md) when the proposal's status changes. Update `docs/agents/` records and run `composer agent-docs:check` only when a public DBVC surface actually changes. Record unresolved compatibility questions in [`docs/requests.md`](../../../requests.md) instead of treating this proposed guide as source authority.
