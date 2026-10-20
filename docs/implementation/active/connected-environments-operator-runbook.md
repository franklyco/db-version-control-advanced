# Connected Environments — operator runbook

**Audience: the agency operator** running the hub, not developers. This is the day-to-day how-to for the two weekly verbs — **distribute** framework changes to the fleet, and **govern** client drift — plus one-time setup and health checks. The build record and architecture live in `connected-environments-agency-control.md`; the admin-page reference is `connected-environments-admin-page.md`. Everything here is already shipped (M0–M9 + post-M9 Phases A–B).

Each task lists both the **admin page** path (`DBVC Export → Connected Environments`) and the **WP-CLI** equivalent (`wp dbvc agency …` on the hub, `wp dbvc connected …` on a client site). The page requires `manage_options`; the CLI is operator-run.

## The model (read once)

- The **hub** (your agency site) orchestrates; it never holds client content, only hash projections and governance records.
- Each **client site** runs a **connector** that observes its own Bricks framework + content and applies approved changes locally. The connector's **apply gate is always authoritative** — the hub can never write to a site whose apply gate is off.
- Every object is one of three kinds:
  1. **Framework-owned, client unchanged** → your version wins; a push converges it.
  2. **Framework-owned, client-customized + approved ("locked")** → the client's value wins on their site; a push skips it. A *new* framework version of a locked object raises `needs_rebase_review` (keep / re-approve / unlock) — never silently overwritten.
  3. **Client-owned original** → always theirs, never touched.
- Nothing applies without the full **release → prepare → approve → guarded apply → verify** pipeline; every write is fingerprint-guarded, journalled and rollbackable. **Masked/privacy fields never sync; deletions trash, never hard-delete.**

## One-time setup

1. **Enable the hub.** Connected Environments page → Settings → Hub → enable. (CLI: the add-on enable toggle; schema installs automatically.)
2. **Enroll each client site.** Hub: Environments → *Invite* (prints a single-use token once). Client site: Settings → Enrollment → paste hub URL + token → **Enroll** (CLI: `wp dbvc connected enroll`). The hub stores only the token hash; the connector authenticates thereafter with a bound application password.
3. **Install a reliable scheduler (important).** WP-Cron only fires on a page visit, so on a quiet hub the hourly ticks stall. On the hub site:
   ```php
   // wp-config.php
   define('DISABLE_WP_CRON', true);
   ```
   ```bash
   # system crontab — one entry drives every hub tick (parity + framework rollouts, drift scan, notifications)
   * * * * * cd /path/to/hub/wordpress && wp dbvc agency scheduler-run >/dev/null 2>&1
   ```
   Check it is firing: `wp dbvc agency scheduler-status` (an `overdue` flag turns true after two missed hours).
4. **Delegate roles (optional).** To let a non-admin publish or approve without full admin access: Connected Environments → **Roles** → grant **publisher** (authoring/distribution) or **approver** (review/approve/rollback) to a user. CLI: `wp dbvc agency role-grant --user=<id|login|email> --role=publisher|approver`. Granting is admin-only (privilege delegation); administrators already hold both.
5. **Notifications (optional).** `wp dbvc agency notification-channel-set …` registers a webhook or email channel; terminal rollout states, drift, and needs-rebase events are queued and flushed on the scheduler tick (or `wp dbvc agency notification-flush`).

## Verb 1 — Distribute framework changes

Push official Bricks-framework changes (updated *and* brand-new global classes, variables, components, palettes, theme styles, templates) to the whole fleet in one action.

1. **Preview what will change** (read-only): Framework tab, or `wp dbvc agency framework-plan` (shows what the desired framework version would change across the fleet; run with `--help` for its targeting flags).
2. **Publish the version** from a golden source environment: Framework → Definitions → *Publish*, or `wp dbvc agency framework-publish --source=<golden-env> --version=<label> --items=…`. This stamps an immutable per-object version and marks it desired; it applies nothing yet.
3. **Roll out across the fleet**: Publish/Rollouts tab wizard, or `wp dbvc agency framework-rollout-start` with optional `--cohorts=<json>` (ordered, canary-first) or `--targets=<list>` (a subset of sites); to target by vertical/tier across clients, tag environments first (`environment-tag`) and the rollout/wizard can select by segment. Each target rides its own override-aware release through prepare → approve → guarded apply; **a locked target is skipped, never overwritten**; a failed cohort **pauses** the fleet.
4. **It advances hands-off** on the scheduler. To accelerate, run `wp dbvc agency framework-rollout-advance --campaign=<uid>` (the connectors still pace delivery/apply by their own polls).
5. **Monitor**: the Fleet dashboard and Rollouts tab show per-cohort / per-target progress and rendering evidence; or `wp dbvc agency framework-rollouts` / `framework-status`. Controls: **Pause / Resume / Retry / Withdraw** (`framework-rollout-resume|retry|withdraw`). Withdraw ends in-flight work; landed changes are undone only by a reviewed rollback (below).

## Verb 2 — Govern client drift

Keep approved client-specific work and stop it being overwritten, while still seeing where sites diverge.

1. **See drift**: the Fleet dashboard matrix (environments × domains/version) marks each cell `synchronized` / `local_drift` / `approved_override` / pending / stale. Per-pair detail is in Compare; `wp dbvc agency fleet` / `framework-status` are the CLI reads.
2. **Lock an approved customization** so a push skips it: Compare → open the object → **Lock** (records an approved override with your rationale), or `wp dbvc agency override-approve --environment=<id> --domain=<d> --instance=<uid> --definition=<uid> --rationale="…"`. **Unlock** (`override-detach`) rejoins the object to parity and it reconverges on the next push.
3. **Rebase review**: when a new framework version touches a locked object, it is flagged `needs_rebase_review` — resolve from Reviews / Framework (keep, re-approve against the new version, or unlock). Review items: `wp dbvc agency review-classify` / `review-resolve`.
4. **Review the actual values at approval**: the prepare/approve step renders a before/after diff of the sealed payload (redaction-aware), so you approve the real CSS/values, not just a hash.
5. **Undo a bad apply** (net-zero): `wp dbvc agency rollback --operation=<id>` creates a reviewed rollback; the target restores its journalled before-image on its next poll with a fingerprint-guarded conditional write (a container that moved on yields a conflict, never an overwrite), and the hub regresses the pair baseline.
6. **Freeze a site** during a client's maintenance window: `wp dbvc agency freeze-set --environment=<id> --from=<utc> --until=<utc>` refuses new prepares/approves for that target until it clears (advisory; the connector apply gate stays authoritative). `freeze-clear` ends it.

## Daily health & audit

- **Scheduler**: `wp dbvc agency scheduler-status` — confirm neither tick is `overdue`.
- **Notifications**: `wp dbvc agency notification-log` — recent sends/failures.
- **Audit**: `wp dbvc agency audit-export` — one JSON bundle of environments/definitions/releases/approvals/rollouts/reviews/overrides for the record (the event-sourced `dbvc_ac_*` stores are the trail).

## Safety invariants (always true)

- The connector **apply gate is authoritative** — `auto` mode never writes to a target whose apply gate is off, and never bypasses prepare/approve/verify.
- **Masked/privacy fields never sync**; **deletions trash, never hard-delete**; new-object creation and deletion propagation are opt-in.
- **Conflicts never auto-overwrite** unless `source_wins` is explicitly chosen (default is hold); an **approved override is never overwritten, even under `source_wins`**.
- **Granting a capability is admin-only** — a publisher or approver delegate can never hand out capabilities.
- Every apply is **fingerprint-guarded, journalled, and reversible** by a reviewed rollback to net-zero.

## Quick reference

| Task | Admin page | CLI (`wp dbvc agency …`) |
|---|---|---|
| Install reliable scheduler | — | `scheduler-run` (crontab) + `scheduler-status` |
| Enroll a client site | Environments → Invite / client Settings → Enroll | client: `wp dbvc connected enroll` |
| Delegate publisher/approver | Roles | `role-grant` / `role-revoke` / `roles` |
| Preview a framework change | Framework | `framework-plan` |
| Publish a framework version | Framework → Definitions → Publish | `framework-publish` |
| Roll out to the fleet | Publish / Rollouts | `framework-rollout-start` (`--cohorts` / `--targets`; segment via `environment-tag`) |
| Advance / pause / resume / retry / withdraw | Rollouts | `framework-rollout-advance|resume|retry|withdraw` |
| See drift | Fleet / Compare | `fleet` / `framework-status` |
| Lock / unlock a client override | Compare → object → Lock/Unlock | `override-approve` / `override-detach` |
| Rebase review | Reviews / Framework | `review-classify` / `review-resolve` |
| Undo an apply (net-zero) | — | `rollback --operation=<id>` |
| Freeze a site | — | `freeze-set` / `freeze-clear` |
| Notifications | — | `notification-channel-set` / `notification-log` / `notification-flush` |
| Audit export | — | `audit-export` |
