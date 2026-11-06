# Connected Environments and Agency Control — implementation guide

**Status (2026-10-27): feature-complete, runtime-verified, and documented.** M0–M9 are shipped + runtime-verified on `master` (fleet framework **distribution** + client-**drift governance**; the M9 exit-gate cross-site live-verify, slice 6e, passed net-zero on the real fleet). Post-M9 **productionization** is done: **Phase A** (reliable scheduler + media capacity) and **Phase B** (roles REST + admin-UI) complete; **Phase C** document half complete (operator runbook). Authoritative milestone status: `docs/dropins/dbvc-connected-agency/tracking/tasks.json`.

This is the **current-state** guide. The full per-milestone (M0–M9) build-and-verification log now lives in `docs/archives/connected-environments-build-record.md` (historical — not current guidance).

**Where to go next**
- **Open work + full-fleet battle-test** (what's still open/WIP/deferred + a staged validation plan; the launch point for a fresh session): `connected-environments-fleet-battletest-handoff.md`.
- **Operator how-to** (the two weekly verbs + setup + CLI↔admin quick-reference): `connected-environments-operator-runbook.md`.
- **Agent / API discovery** (every CLI, REST, hook, setting, table surface; gated by `composer agent-docs:check`): `docs/agents/manifest.json`.
- **Media-capacity design** (A2 offload + pull channel): `connected-environments-media-capacity-design.md`.
- **Admin-page build record**: `connected-environments-admin-page.md`.
- **Cold-start + live-verify recipe**: `connected-environments-m9-handoff.md`.
- **Full build record (archived)**: `docs/archives/connected-environments-build-record.md`.
- **Intake package (design baseline, inert templates)**: `docs/dropins/dbvc-connected-agency/START-HERE.md`.

**Code owners (runtime; never load code from `docs/`)**
- Connector: `addons/connected-environments/` — observes its own Bricks framework + content, applies approved changes locally, owns the authoritative apply gate.
- Hub: `addons/agency-control/` — orchestration, governance records, hash projections; holds no client content.
- Shared protocol: `includes/Dbvc/ConnectedProtocol/`.
- Admin page: `admin/class-connected-admin-page.php` (REST) + `src/connected-app/` (React).

## What it does

The agency runs a **hub**; each client site runs a **connector**. Two verbs, done weekly from the hub without logging into each site:
- **Distribute** — push official Bricks-framework changes (updated *and* brand-new global classes, variables, components, palettes, theme styles, templates) across the whole fleet in one action.
- **Govern drift** — lock/unlock per-client customizations so approved client work takes precedence on that site and is never silently overwritten.

## The ownership model (the governing mental model)

1. **Framework-owned, client unchanged** → the official version wins; a push converges the object. (Most objects.)
2. **Framework-owned, client-customized + approved ("locked")** → the client's value wins on their site; a fleet push *skips* it. A *new framework version of the same object* flags the lock `needs_rebase_review` (keep / re-approve against the new version / unlock) — precedence, never forgotten.
3. **Client-owned original (never part of the framework)** → always theirs; never touched, never flagged.

These map onto states the protocol emits (`Comparison::framework()` → `clean`/`local_drift`/`approved_override`/`version_differs`; `::environment()` → `synchronized`/`outgoing`/`incoming`/`converged`/`conflict`).

## Pipeline & safety invariants

Every change rides one path: **release → prepare → approve → fingerprint-guarded apply → verify → rollback** (M5), staged fleet-wide by rollouts (M6). There is **no other write path.**

- The connector **apply gate is authoritative** — `auto` mode never writes to a target whose apply gate is off, and never bypasses prepare/approve/verify.
- **Masked/privacy fields never sync**; **deletions trash, never hard-delete**; new-object creation and deletion propagation are opt-in.
- **Conflicts never auto-overwrite** unless `source_wins` is explicitly chosen (default hold); an **approved override is never overwritten, even under `source_wins`**.
- Every apply is **fingerprint-guarded, journalled, and reversible** by a reviewed rollback to net-zero.
- **Granting a capability is admin-only** (`manage_options`); a publisher/approver delegate can never hand out capabilities.

## Scope shipped

- **M1** connector observation (Bricks global class/variable + `wp.service`), dirty markers, leased worker, durable outbox. **M2** application-password enrollment, receipt, routing, deliveries, inbox. **M3** baselines + three-way comparison, immutable framework definition versions, adoption, approved overrides, framework status, review resolution, admin panels. **M4** release manifests + hash-verified payload transfer + prepare receipts (dependency ledger, deletion items). **M5** guarded apply + reviewed rollback (+ Bricks CSS regen). **M6** hub fleet rollout (ordered cohorts, canary, pause-on-failure, `rollout-*`, Rollouts page, prune). **M7** attachment/media transfer (reference-remap `MediaReferences` + byte channel). **M8** universal `wp.post:<type>` coverage + assisted/auto parity engine + the per-policy cron. **M9** opt-in Bricks net-new domains (collections, singletons, components, templates — identity-independent tokenization) + topological release ordering + governance (audit export, publisher/approver roles, freeze windows, outbound notifications + drift notifier); exit gate 6e passed net-zero.
- **Post-M9 Phase A** — reliable scheduler (`scheduler-run`/`scheduler-status` + heartbeat; PR #168/#169) and media capacity: A2-1 storage offload into `dbvc_ac_media_blobs` (#171) + A2-2 fetch-by-hash pull channel for files over the 2 MiB inline cap, up to 25 MiB (#173/#174/#175), **live-verified net-zero on the real fleet** (which found + fixed the pull response-size bug, #177).
- **Post-M9 Phase B** — roles REST + admin-UI (`connected-admin/roles` + a hub Roles panel; #179/#180). Bricks locked/`readonly` governance **closed as a non-goal** (no such flag exists on the fleet; DBVC "locked" is the hub `OverrideStore`).
- **Post-M9 Phase C** — operator runbook (#182).

## Operations

### Reliable scheduler (production)
WP-Cron only fires on a page request, so on a quiet/asleep hub the hourly ticks stall. On the hub:

```php
// wp-config.php
define('DISABLE_WP_CRON', true);
```

```bash
# crontab — every minute; the ticks are idempotent and self-pace by per-policy cadence
* * * * * cd /path/to/wordpress && wp dbvc agency scheduler-run >/dev/null 2>&1
```

`wp dbvc agency scheduler-run` runs every hub tick once (parity + framework rollouts, drift scan, notification flush); `wp dbvc agency scheduler-status` reports each tick's last-run/age/`overdue` (true after two missed hours). Implemented in `addons/agency-control/src/Scheduler/`.

### Live-verify on the real fleet (disposable connectors only)
The recipe (scratchpad `hubwp`/`awp`/`bwp` wp-cli wrappers keyed to the LocalWP mysqld sockets, temporary insecure-SSL mu-plugins on the connectors for `.local` cross-site HTTPS, and temporary hub-write Bash allow-rules) is in `connected-environments-m9-handoff.md` and the session memory. **Invariants:** the real hub `dbvc-codexchanges` is orchestrate-only (additive schema, **net-zero**, never leave test rows, restore the gate as found); never run an apply against real client content — use the disposable connector clones; always capture the hub baseline first and verify net-zero at teardown.

### ⚠ Cleanup — remove live-verify hub-write allow-rules when the verify is done
A live-verify adds temporary rules to `.claude/settings.local.json` that grant **hub-DB writes** through the scratchpad wrappers. These are needed only during the verify — **remove them once it completes and has torn down net-zero.** Delete every allow-rule whose path points at a session scratchpad (`/private/tmp/claude-501/…/scratchpad/…`):

- `Bash(.../scratchpad/hubwp db|dbvc|option|eval *)` — the hub-write wrappers (currently ~5).
- `Bash(.../scratchpad/scratchwp.sh eval|dbvc|option *)` — the older hub wrappers (currently ~3).
- `Bash(php … wp-cli.phar … /dbvc-connectedsite-a/… eval-file …/scratchpad/*.php)` — per-script connector seeds (currently ~2).
- `Read(//private/tmp/…/scratchpad/**)` — scratchpad read rules (currently ~2).

These were added only for the 6e and the A2-2 media live-verifies, both complete and torn down net-zero. **No permanent hub-write rule is required for normal operation** (the auto-mode classifier otherwise blocks hub-DB writes by design); a future live-verify re-adds its own rules at its own scratchpad path. The assistant cannot edit `.claude/settings.local.json` itself (`[Self-Modification]`), so this is an operator action.

## Out of scope / deferred

Optional, only when scale or the fleet demands it: **A2-3** (a filesystem/object-store media blob driver + chunked upload, behind the existing `MediaBlobStore` driver interface) and a **larger-cohort live-verify** under the new scheduler (needs more than the current two disposable connectors). Deferred by design: bidirectional/merge parity, multisite, non-InnoDB durability, cross-language golden hashes.
