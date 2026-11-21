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

   **If `wp` is not on PATH** (e.g. a LocalWP hub, which ships its own wp-cli phar and a per-site MySQL socket), the bare `wp …` line will not run from cron. Point the crontab at a small wrapper that pins the php socket, phar, `--path`, and `--url`, then runs `dbvc agency scheduler-run`:
   ```bash
   #!/bin/zsh
   SOCK="$HOME/Library/Application Support/Local/run/<SITE_ID>/mysql/mysqld.sock"
   PHAR="/Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar"
   php -d mysqli.default_socket="$SOCK" -d error_reporting=0 -d display_errors=0 \
     "$PHAR" --path="/path/to/hub/app/public" --url="https://<hub-host>" \
     dbvc agency scheduler-run >/dev/null 2>&1
   ```
   ```bash
   # crontab points at the wrapper; the socket exists only while Local is running (the tick no-ops otherwise)
   * * * * * "$HOME/bin/dbvc-hub-scheduler.sh"
   ```
4. **Delegate roles (optional).** To let a non-admin publish or approve without full admin access: Connected Environments → **Roles** → grant **publisher** (authoring/distribution) or **approver** (review/approve/rollback) to a user. CLI: `wp dbvc agency role-grant --user=<id|login|email> --role=publisher|approver`. Granting is admin-only (privilege delegation); administrators already hold both.
5. **Notifications (optional).** `wp dbvc agency notification-channel-set --type=webhook|email --target=<url|email> [--agency=<id>] [--events=<list>]` registers a channel; an empty `--events` takes every kind. Event kinds: `rollout.completed`, `rollout.paused`, `framework_rollout.*` terminal states, `override.needs_rebase`, `site.drifted`, and the change-report kinds `report.generated`, `report.stale`, `report.unstable_source` (Verb 3). Events are queued and flushed on the scheduler tick (or `wp dbvc agency notification-flush`); under plain WP-Cron a report event leaves on the next framework tick, up to an hour later, so install the scheduler (step 3).
6. **Clone pair for the change report (optional; Verb 3).** For a LocalWP build and its hosted staging copy that must never be written to by the hub:
   1. Enroll **staging** and **LocalWP** as two environments of the same client (step 2). Staging is the *source* (where Design Review edits land), LocalWP the *target* (what you keep up to date).
   2. Confirm the starting agreement: `wp dbvc agency baseline-confirm --source=<staging> --target=<localwp> --note="clone baseline"` (add `--accept-absent` so one-sided objects are reported as created/removed rather than unknown).
   3. Set the report-only policy: `wp dbvc agency sync-policy-set --source=<staging> --target=<localwp> --mode=report --cadence=hourly` (or Sync → mode *report only (tell me what changed, never apply)*). It proposes → seals → asks LocalWP for a dry run and **stops there**; nothing is approved or applied.
   4. On the **LocalWP** site, if you want the report to show the actual before/after values (not just which fields changed): `wp option update dbvc_connected_receipt_values 1` (filter `dbvc_connected_receipt_values`). Values are rendered on that site through DBVC's mask list + the key-pattern rule and truncated to 200 characters before they leave it; off by default.
   5. Optionally a channel for the three report kinds (step 5) so the weekly report arrives in a webhook or mailbox instead of being polled.

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

## Verb 3 — Read the Site Changes report

Know what changed on staging since you last looked, object by object, and decide what to do about it — without the hub ever writing to either site.

1. **Open Site Changes** on the hub (Connected Environments → **Site Changes**; the same page is linked from both sites). The status line says what the pair is doing in plain words: *Watching · checked n min ago*, *Checking…*, *staging/target hasn't checked in since …*, *trouble reading staging* (unstable source), *maintenance freeze*, *paused*. CLI: `wp dbvc agency change-report --source=<staging> --target=<localwp> [--format=markdown]`.
2. **Work the *Needs a look* cards** (changed / removed / new on staging, conflicts, LocalWP-only edits). Expand a card to see each changed field by friendly name with the Target | Staging values side by side (values appear only when the LocalWP site has `receipt_values` on; *Show technical names* reveals the dot-paths).
3. **Decide in words** — each decision is recorded and verified on the next hourly check:
   - **Done** (`reconciled`): you have dealt with it; the item moves to *Handled* and is marked `verified` once both sides agree, or `still_differs` if they do not.
   - **Leave it / Keep it / Don't need it** (`ignored`): hide it until the object changes again (`changed_again` brings it back).
   - **Sorted — they match now** (conflicts only): `reconciled` plus a silent re-baseline of that object, landed at verification when the sides agree.
   - **Remind me tomorrow** (`snoozed`, 24 h by default); **Reopen** clears any decision.
   CLI: `wp dbvc agency change-report-decide --source --target --items=<domain:uid,…> --decision=reconciled|ignored|snoozed|clear [--rebaseline=1] [--note]`.
4. **Bring a staging edit home only when you mean to**: *Full console* → select objects → **Create release from n objects** (`change-report-promote`). This creates an ordinary release that goes through prepare → approve → guarded apply with the LocalWP apply gate as the final say; the report itself never applies.
5. **What the notifications mean** (channel subscribed to the report kinds):

   | Event | Meaning | Do |
   |---|---|---|
   | `report.generated` | A check finished and a new report exists (counts in the payload) | Open Site Changes |
   | `report.stale` | One side has not been **heard from at all** (no poll, no observation) within the freshness window (24 h); announced once per outage (keyed on that side's last-seen time). A healthy site with nothing to report keeps polling, so it stays fresh | Open the silent site: its WP-Cron / scheduler or its connection to the hub is down. The next successful poll clears the state |
   | `report.unstable_source` | Staging kept changing mid-upload for three consecutive checks; the run was withdrawn | Let the editing session finish, then **Check now** |
6. **Retention** is automatic: report-purpose releases and their dry-run receipts are purged after 7 days once the report exists; the newest 20 reports per pair are kept, each with its object list exactly as that run recorded it (*Full console* → Runs → click a run). *Full console* → Retention → *Preview purge* / *Purge now* (`change-report-purge [--days=7] [--dry-run]`) runs it on demand.

### Verb 3b — No hub between the two sites? Use file mode

When a staging site cannot reach the hub (or a pair is not enrolled yet), the same object-by-object report runs from two read-only CLI commands and a file you move yourself:

1. On **staging**: `wp dbvc connected process` (settle pending observations), then `wp dbvc connected objects-export --out=/path/staging-objects.json` — this site's recorded canonical bodies as one JSON bundle (DBVC-masked fields are already absent).
2. Move the file to the LocalWP machine (scp, rsync, a shared folder).
3. On **LocalWP**: `wp dbvc connected process`, then `wp dbvc connected compare-file /path/staging-objects.json` (`--format=json` for the structure, `--out=report.md` to file it). Objects pair by portable UID, then by display name; each pair is diffed path by path with this site → the export values, redacted like receipt values.

What you get: **Differs / Only in the export / Only on this site / Both match**. There is no baseline, so file mode says *that* the sites differ, not *which one* changed; decisions, notifications and the Site Changes page stay on the hub. Nothing is written on either site.

### Verb 3c — Put the Design Review beside the report

If the client reviewed staging with the theme's Design Review, download the round's export (the editor's Export card, `vf-design-review/1` JSON) and pass it to either report command:

- Hub: `wp dbvc agency change-report --source=<staging> --target=<localwp> --design-review=/path/design-review.json --format=markdown`
- File mode, on LocalWP: `wp dbvc connected compare-file /path/staging-objects.json --design-review=/path/design-review.json`

Each page or template row the review covers gains *Design Review: 3 pins (1 open) · approved*, and a **Design Review** section at the end names the round and the reviewed pages the report does not mention. Pages match by this site's UID on LocalWP (the export's post ids are checked against the local title first) and by title on the hub; classes and variables are never annotated. Read-only: the theme and the review are not touched.

### Adopting a pair that already diverged

The clone-pair setup above assumes you enroll both sites the day you clone. For a pair that has been edited on both sides first (the usual case when the report arrives late), do this once, in this order — verified on a real build/staging pair on 2026-11-20:

1. **Enroll both sites** (setup step 2). If the staging connector was enabled on its own rather than cloned with the add-on already on, its classes, variables, categories, palette and components carry their own identities; posts always share `vf_object_uid`.
2. **Link identities by name**: `wp dbvc agency link-proposals --source=<staging> --target=<localwp>` lists the name matches; `wp dbvc agency link-accept --source=<staging> --target=<localwp> --all` accepts them. Skip this and the report calls every class *Changed on LocalWP only*.
3. **Confirm the baseline only now**: `wp dbvc agency baseline-confirm --source=<staging> --target=<localwp> --accept-absent`. It records the objects that already agree; whatever already differs stays *No agreed starting point* until you reconcile it (the hub never guesses which side is right). Confirming with `--accept-absent` **before** step 2 records absent baselines that flip every linked object to *Changed on LocalWP only*.
4. **Catch up with file mode** (Verb 3b): the hubless report lists exactly those pre-existing differences with values — the hub and file mode agree object for object — so work that list down by hand, then the hub report tracks only what changes from here.
5. If you fixed links or baselines by hand, the inbox settles on the next scheduler tick (idle verify), not on `sync-advance`.

Noise you can filter on the connector: theme meta that embeds the site URL always differs between two hosts. On both sites:
```php
add_filter('dbvc_connected_post_ignored_meta_keys', fn($keys) => array_merge($keys, ['_vf_qr_code_url', '_vf_qr_target_url']));
```
Bricks' query-loop cache stamp (`settings.query.signature`/`.time`) and WordPress's `_oembed_*` cache are already dropped from projections.

Attachment ids in meta are not noise but they are site-local: `_thumbnail_id`, ACF image/file/gallery fields (recognised through their `_<field>` reference when ACF is active) and Bricks image controls all travel as content-hash tokens, so a re-uploaded-but-identical image is not a change. A non-ACF key that stores an attachment id can be named the same way, on both sites:
```php
add_filter('dbvc_connected_post_media_meta_keys', fn($keys) => array_merge($keys, ['card_image']));
```
Upgrading an already-paired fleet to this behaviour changes the projection of every post carrying such a field once; deploy both connectors, let them re-observe, then re-confirm the baseline.

## Daily health & audit

- **Scheduler**: `wp dbvc agency scheduler-status` — confirm neither tick is `overdue`.
- **Notifications**: `wp dbvc agency notification-log` — recent sends/failures.
- **Change report pairs**: Site Changes status line, or `wp dbvc agency sync-policies` — a `report` policy whose `last_run_note` reads `stale_source`/`stale_target` or `unstable_source` needs a look (Verb 3 step 5).
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
| Change report without a hub | — | staging: `wp dbvc connected objects-export --out=<file>` → LocalWP: `wp dbvc connected compare-file <file>` |
| Design Review beside the report | — | `--design-review=<review.json>` on `wp dbvc agency change-report` or `wp dbvc connected compare-file` |
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
| Set up a report-only clone pair | Sync → mode *report only* | `baseline-confirm` + `sync-policy-set --mode=report --cadence=hourly`; target site `wp option update dbvc_connected_receipt_values 1` |
| Adopt a pair that already diverged | — | `link-proposals` → `link-accept --all` → `baseline-confirm --accept-absent` → file-mode catch-up (Verb 3b) |
| Read what changed on staging | Site Changes | `change-report` (`--format=markdown`) / `change-reports` |
| Decide Done / Leave it / Sorted / snooze | Site Changes → card | `change-report-decide` |
| Bring a staging edit home | Site Changes → Full console → Create release | `change-report-promote` (then approve from Releases) |
| Purge old report runs | Site Changes → Full console → Retention | `change-report-purge` |
| Audit export | — | `audit-export` |
