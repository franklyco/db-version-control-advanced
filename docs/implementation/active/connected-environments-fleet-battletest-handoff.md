# Connected Environments — open work + full-fleet battle-test handoff

**Purpose.** A single launch point for a fresh Claude Code session to (a) see exactly what is still open / WIP / deferred on the Connected Environments + Agency Control add-on, and (b) run a staged battle-test of the shipped system against a real agency fleet. The feature set is **done** (M0–M9 + post-M9 Phases A/B shipped and runtime-verified); what remains is operationalization and fleet-scale validation, not new capability.

**Status as of 2026-11-01.** `master` @ `363be6ea`; all three live checkouts fast-forwarded (hub `dbvc-codexchanges`, connectors `dbvc-connectedsite-a`, `dbvc-connectedsite-butler`). **BT-0 ran — core PASSED** (see BT-0 below): first fix shipped (PR #201, fleet liveness vs state-recency); pipeline live-verified net-zero; findings F1–F5 logged. **BT-1 ran — scheduler-cutover mechanism PASSED net-zero** (see BT-1 below): under `DISABLE_WP_CRON`, page requests stop ticking while a bare-shell `scheduler-run` keeps both hub ticks fresh (`overdue: false`); hub `wp-config.php` restored byte-for-byte; the LocalWP-correct install (wrapper + crontab) was handed to the operator and captured in the runbook. No code change. **BT-2 ran — single-object canary distribution PASSED net-zero** (see BT-2 below): a low-risk global variable was distributed A→B over the **scheduler-driven framework path** (4 `scheduler-run` ticks, zero page requests) through build→seal→rollout→prepare→approve→guarded-create→verify→COMPLETED, convergence verified on B, reviewed rollback restored net-zero, and a second campaign demonstrated the **pause-on-failure gate** (a failed cohort halts the campaign; target untouched); hub + both connectors restored to baseline. No code change. The literal 2-cohort canary of distinct targets needs a ≥3-connector fleet (source is always excluded from targets) — the primary remaining validation. Authoritative milestone ledger: `docs/dropins/dbvc-connected-agency/tracking/tasks.json` (M0–M9 all `completed` + `runtime_verified`). Roadmap rows: `docs/ROADMAP.md` → "Connected Environments and Agency Control" (M0–M9) and "Connected Environments — post-M9 productionization" (Phases A+B complete, Phase C in progress).

---

## First actions for a fresh session

1. Read `docs/README.md` → `docs/agent-entrypoints.md` (repo rules; do **not** read the whole docs tree).
2. Read the current-state guide: `docs/implementation/active/connected-environments-agency-control.md` (lean, ~85 lines — the ownership model, the one write path, the safety invariants).
3. Read the operator how-to: `docs/implementation/active/connected-environments-operator-runbook.md` (the two weekly verbs + CLI↔admin quick-reference).
4. Read the cold-start + **live-verify recipe**: `docs/implementation/active/connected-environments-m9-handoff.md` (scratchpad `hubwp`/`awp`/`bwp` wrappers, sockets, insecure-SSL mu-plugins, hub-write allow-rules, teardown).
5. Discover the **exact** CLI/REST surface live — do not trust a hard-coded flag list: `wp dbvc agency --help`, `wp dbvc connected --help`, and `docs/agents/manifest.json` (gated by `composer agent-docs:check`). The battle-test steps below name verbs, not every flag, on purpose.
6. Full historical build record (M0–M9, if you need per-milestone detail): `docs/archives/connected-environments-build-record.md` (archived; not current guidance).

**Code owners (runtime; never load code from `docs/`):**
- Connector: `addons/connected-environments/` (`Dbvc\Connected\`) — observes, applies approved changes locally, owns the authoritative apply gate.
- Hub: `addons/agency-control/` (`Dbvc\AgencyControl\`) — orchestration, governance records, hash projections; holds no client content. Scheduler in `addons/agency-control/src/Scheduler/`.
- Shared protocol: `includes/Dbvc/ConnectedProtocol/`.
- Admin page: `admin/class-connected-admin-page.php` (REST) + `src/connected-app/` (React, single-file `index.js`, built with `npx wp-scripts build connected-app`).

---

## What is shipped & verified (do not redo)

- **M0–M9** feature-complete + runtime-verified. The M9 exit gate (**slice 6e**, a continuous hub-orchestrated cross-site framework push with cross-object class-reference remap) passed **net-zero** on the real fleet.
- **Phase A — productionize:** reliable hub scheduler (`wp dbvc agency scheduler-run` / `scheduler-status` + heartbeat, PR #168); media capacity — A2-1 content-addressed blob offload (`dbvc_ac_media_blobs`, schema v20, PR #171) + A2-2 fetch-by-hash pull channel (25 MiB ceiling, PR #173/#174/#175), **live-verified net-zero** (3.3 MiB image streamed A→hub→B byte-identical; found+fixed the pull response-size defect, PR #177).
- **Phase B — coverage/ergonomics:** roles REST + hub admin-UI (`connected-admin/roles` + Roles panel, PR #179/#180). Bricks `locked`/`readonly` governance **closed as a non-goal** (no such artifact flag exists on the fleet; DBVC "locked" = the hub `OverrideStore`).
- **Phase C — validate/document (partial):** operator runbook shipped (PR #182); guide trimmed to current-state + M0–M9 build record archived (PR #196). Admin-page **dark-mode readability** fully fixed (PR #197 badges/stepper/buttons/semantic tokens, #198 headings, #199 `.description`/inline-code) — live-verified in dark mode from the deployed CSS.

---

## Still open / WIP / pending / planned

### 1. Full-fleet / larger-cohort live-verify — **OPEN (constrained)** ← the primary remaining validation
The whole pipeline is proven on **2 disposable connectors**, both currently **stale** (not reporting inside the freshness window). A genuine fleet battle-test needs more connectors reporting live, multi-cohort rollouts driven by the **production scheduler** (not request-gated WP-Cron), and the failure/recovery rails exercised at scale. This is the bulk of the **battle-test plan** below.

### 2. Production operationalization on the real hub — **PENDING (operator actions)**
These are shipped but not yet *turned on* in production:
- **Scheduler cutover:** `define('DISABLE_WP_CRON', true)` on the hub + a system crontab line running `wp dbvc agency scheduler-run`. Verify with `scheduler-status` (overdue after two missed hours). Until then, hub ticks only fire on a page request.
- **Notification channels:** the backbone (webhook `wp_remote_post` + SSRF guard + HMAC, or `wp_mail`) and the drift notifier are shipped (PR #134/#136) but channels must be **configured and delivery-verified end-to-end** against a real endpoint. No live delivery has been confirmed.

### 3. A2-3 media driver — **OPTIONAL (scale-gated)**
A filesystem/object-store blob driver behind the existing `MediaBlobStore` driver interface + chunked upload. Only implement if blob volume / hub DB size / the 25 MiB per-file ceiling becomes a real constraint. The DB-backed `DbMediaBlobStore` (deduped, GC'd) is the default and is sufficient for current scale.

### 4. Minor / housekeeping
- **Dark-mode contrast re-scan with live data.** The WCAG sweep (this session) only covered rendered DOM; data-driven rows in empty tabs (Fleet, Releases, Rollouts, Subscriptions) weren't exercised. Re-run the scan once the fleet has live rows (table cells, status chips). Styling gotcha: any CE heading/text rule must set `color` explicitly to a `--dbvc-ce-*` token, or WP-admin's element-level grays override it and go invisible in dark mode.
- **`.claude/settings.local.json` cleanup.** The live-verify work added temporary hub-write Bash allow-rules (scratchpad `hubwp`/`scratchwp.sh`/eval-file/Read rules). BT-0 additionally added an **Edit allow-rule for the two connector `mu-plugins/` paths** (to write the lab TLS shim). Remove all of these — and the shims themselves (`zzz-dbvc-lab-insecure-hub.php` on A and B) — when the battle-test concludes; the auto-mode classifier otherwise blocks hub-DB writes / TLS-weakening by design, and a future verify re-adds its own. The shim + allow-rule are **kept for the duration of the battle-test** (every BT slice needs connector→hub `.local` HTTPS). The assistant cannot edit this file (`[Self-Modification]`) — it is an operator action. Details in the active guide's "⚠ Cleanup" section.
- **Local-only stale branches** (ROADMAP "Two branches exist only on this machine") — predate this work; review/retire separately, not part of CE.

### Explicitly OUT OF SCOPE (non-goals — do not chase)
Bidirectional / merge parity; multisite; non-InnoDB durability; cross-language golden hashes; Bricks component static media (components carry dynamic data, 0 static media on the fleet); a `global_settings` field-policy domain (operational/secret/site-local — API keys, reCAPTCHA secret, auth-page ids, `executeCodeEnabled`, editor prefs); artifact-level `locked`/`readonly` governance (no such flag on the fleet).

---

## Recommended battle-test plan (staged slices)

Run in order; each slice has an explicit exit gate. **Golden rules apply throughout** (see Safety invariants). Destructive and failure drills run on **disposable connector clones** only; graduate to real client sites only through the normal guarded pipeline with rollback rehearsed first.

### BT-0 — Fleet onboarding & readiness
**Goal:** get N real/representative connectors enrolled and **reporting green** (not "stale/unknown").
**Steps:** inventory the target sites; on each connector `wp dbvc connected enroll` with an application-password principal; confirm observation (`connected status`/`inventory`), the durable outbox drains, and the hub inbox routes the events; on the hub Fleet tab each site shows `clean`/known inside the freshness window (`dbvc_agency_control_freshness_seconds`).
**Exit:** every onboarded connector is green on the Fleet dashboard; `wp dbvc agency status` shows them enrolled + routed.

**Status — ran 2026-10-29/30 on the real 2-connector fleet; core PASSED.** Both connectors (`connectedsite-a`, `local-8ed34654ecf574c6`/butler) were already enrolled, healthy and routed (hub net-zero: 2 environments, 2979 projections, all events routed) — just **stale**. Getting them "green" surfaced a design gap that became the first BT-0 fix, and the full observe→deliver→route pipeline was re-proven live over `.local` HTTPS.

- **Fix shipped — PR #201 (master `bde4108e`): fleet liveness vs state-recency.** The hub Fleet rollup keyed site state solely on `last_contact_at` (last observation *delivery*), so a healthy connector whose Bricks content simply hadn't changed within the freshness window showed as `not_reporting` (looked offline) and was refused as a framework-publish source — even while it polled the hub every cycle. `FleetService` now derives a dashboard-only `live` signal from `last_inbox_poll_at` (already recorded, previously unused) and splits the stale case into **`awaiting_state`** (alive · observation stale) vs **`not_reporting`** (no recent contact at all). `is_fresh` is unchanged and still gates `in_sync` + publish trust (a stale report is never rolled up as in sync). React Fleet view adds an "Awaiting report" tile + a "Live · awaiting report" badge + an "alive · checked <poll>" note. 243 tests green; 0 unmapped.
- **Pipeline live-verified net-zero.** `connectedsite-a` was driven to a genuine `in_sync` via a real change→observe→process→deliver→route cycle (one existing Bricks variable perturbed then restored byte-exact); butler left as the `awaiting_state` (live-but-quiet) exemplar. Hub projections back to **2979** (= baseline), connector content byte-identical. Fleet summary ended `in_sync: 1, awaiting_state: 1, not_reporting: 0`.

**BT-0 findings / follow-ups:**
- **F1 (fixed): liveness≠state-recency** — above (PR #201). *Optional Scope B follow-up:* let a live-but-quiet source still publish (relax the `FrameworkService`/`FrameworkPublishService` `dbvc_agency_environment_stale` refusal when `is_live` but not `is_fresh`). Not done — touches publish-trust semantics; decide deliberately.
- **F2 (open): withdrawn-domain poison job.** Each connector carries one permanently-errored job (`wp.bricks:components`/`collection`, `unsupported_domain`, ~10 attempts) orphaned from the 6e component opt-in teardown. Withdrawing a domain opt-in should cancel/tombstone its pending jobs instead of leaving an undrainable job that keeps `errored: 1` on `connected status` forever. Harmless (future-dated backoff; does not block supported domains) but real. Code fix lives in the connector job/dirty-capture layer.
- **F3 (open): disposable-lab TLS is manual.** connector→hub over self-signed `.local` HTTPS needs an `sslverify=false` shim (mu-plugin) + an Edit allow-rule each verify (the auto-mode classifier blocks it as `[TLS/Auth Weaken]`, correctly). Worth a first-class, clearly-fenced disposable-lab TLS affordance so every verify doesn't re-add a hand-written shim. The shim (`wp-content/mu-plugins/zzz-dbvc-lab-insecure-hub.php` on A and B, scoped to the hub host) is **left in place for the duration of the battle-test** — remove it, and the allow-rule, when the battle-test concludes.
- **F4 (→ BT-1): quiet-host cron.** On a quiet LocalWP site WP-Cron fires only on a page request, so even inbox polling stalls without traffic → sustained liveness needs the BT-1 scheduler cutover (`DISABLE_WP_CRON` + crontab). During BT-0 the connectors were driven by CLI.
- **F5 (by design): `reconcile` on unchanged content emits nothing** — the connector `ObservationWorker` emits only for changed/new/removed members (same epoch), so a stable site cannot self-refresh its *state report* via reconcile; it refreshes liveness via polling (F1) or a real content change. Not a bug; documents why a quiet site ages to `awaiting_state`.

### BT-1 — Production scheduler cutover
**Goal:** replace request-gated WP-Cron on the hub with an external scheduler.
**Steps:** set `DISABLE_WP_CRON` + the crontab line (see active guide → Operations); let the hub sit idle > 2 h.
**Exit:** `wp dbvc agency scheduler-status` reports fresh (not overdue) across an idle window; a rollout advances with no page request.

**Status — ran 2026-10-30 on the real hub; mechanism PASSED, net-zero (prove-and-hand-over; no standing cron left on the dev Mac).** Entering BT-1 both hub ticks were **overdue** (parity age 7268s, framework 7300s — past the 7200s threshold; `cron_scheduled: true` with `next_cron_run` already in the past) — the quiet-host stall F4 names, reproduced live. Proof sequence: (1) added `define('DISABLE_WP_CRON', true)` to the hub `wp-config.php`; (2) three front-end page requests (HTTP 200) did **not** advance the heartbeat — `last_run_at` stayed frozen while wall-clock climbed, i.e. page requests no longer tick; (3) `wp dbvc agency scheduler-run` from a **bare shell (zero HTTP)** advanced **both** heartbeats to age 1s, `overdue: false`, running both ticks in full (parity + framework rollout-advance + drift scan + notification flush); (4) `scheduler-status` read fresh. Then `wp-config.php` was **restored byte-for-byte** (`DISABLE_WP_CRON=off`, grep-clean) and every `dbvc_ac_*` store confirmed back to baseline (environments 2, events 2990, projections 2979, invitations 2, all else 0; `dbvc_activity_log` 907). **No code change** (config + verification + a docs example only).

- **Rollout-advance clause → carried to BT-2.** No rollouts exist on the hub (`framework_rollouts=0`, `rollouts=0`), so the framework tick ran with nothing to advance (`ran: 0`). BT-1 proved the rollout-advance **tick executes off-request** (the code path runs from the bare-shell scheduler context); the visible "a campaign advances cohort→cohort under the external scheduler" is BT-2's own exit gate (scheduler-driven canary distribution) and is proven there rather than fabricated on the real hub now.
- **Operator hand-over (not installed).** Per the chosen scope, no standing crontab was installed on the dev Mac. The LocalWP-correct install (the generic `wp dbvc agency scheduler-run` crontab line cannot run on LocalWP — no global `wp`, needs the mysqld socket + phar + `--path`/`--url`) is now a worked example in the operator runbook (`connected-environments-operator-runbook.md` → One-time setup → step 3): a small wrapper script pinning socket/phar/path/url, pointed at by a one-minute crontab line, plus the `DISABLE_WP_CRON` define and the uninstall steps. The validated wrapper was handed to the operator.
- **BT-1 findings:** none new. F4 (quiet-host cron) is **addressed by the cutover** — the external scheduler keeps the hub ticking regardless of page traffic; the operator installs the crontab from the runbook example to turn it on. The `>2 h idle` soak is just the `overdue` threshold (7200s); with a one-minute cron the age never approaches it, so the invariant is a property of the cron cadence, not of hub code (which BT-1 proved resets the age from a non-web context).

### BT-2 — Single-object canary distribution (full fleet)
**Goal:** prove ordered, scheduler-driven distribution with canary + pause-on-failure.
**Steps:** pick one low-risk framework object (e.g., a single global class); `release-create` → seal → `rollout-create` with a **canary cohort first** then a main cohort; let the scheduler drive `rollout-advance`; verify convergence on each connector; rehearse a reviewed rollback to net-zero on one target.
**Exit:** object converged fleet-wide via the real scheduler; canary gated the main cohort; rollback net-zero verified.

**Status — ran 2026-11-01 on the real fleet; achievable criteria PASSED, NET-ZERO (docs-only, no code change).** Run via the **framework path** (`framework-publish` → `subscribe-framework` → `framework-rollout-start`), which is the only scheduler-driven one — `FrameworkRolloutScheduler::tick` advances campaigns; the plain M6 `rollout-*` path is manual-advance only. Per the chosen **single-target** shape (a literal 2-cohort canary needs ≥3 environments — both the framework and plain paths exclude the golden source from the target set, so on a 2-connector fleet one connector is always the source and only one can be a target):

- **Scheduler-driven distribution A→B — PASSED.** Seeded a low-risk global variable `--dbvc-bt2-canary` (`#b72222`) on source `connectedsite-a`, published definition `bt2canvar`/`bt2-v1`, subscribed target `local-8ed34654ecf574c6`, started a campaign (cohort `[[B]]`). Four `wp dbvc agency scheduler-run` ticks (zero page requests) drove the campaign end-to-end — build release → **A sealed the payload** (source serves it; the hub never seals) → per-target rollout → prepare → approve → **B applied the guarded create** → verify → **COMPLETED**. The connector worker cycle (A seals, B prepares/applies) was driven by the `wp dbvc connected release` worker (payloads + prepare receipts + applies) plus `poll`/`process`/`deliver`.
- **Convergence — verified directly.** B's `bricks_global_variables` gained `--dbvc-bt2-canary = #b72222` (byte-matching A's source; 307→308). B then observed its created var under the **same name-derived identity** `bricks-variable-cac2a4edc679ce38` as A, so the hub registered convergence (projections 2979→2981) — cross-site identity for a name-identified Bricks variable aligns automatically; **no instance link was needed**.
- **Reviewed rollback to net-zero — PASSED.** `wp dbvc agency rollback --operation=<apply-op>` → B applied the reversed before-image (var removed, outcome `restored`); B back to 307.
- **Pause-on-failure gate — demonstrated.** A second campaign hit a failed cohort (a real target-divergence: after rollback B's content no longer matched the hub's projection, so the `replace` prepared `blocked`) → the campaign **PAUSED** (`paused_reason=cohort_0_failed`, target `prepare_blocked:blocked`); a later cohort never starts and **B was never written** (clean fail at prepare — the guard held). A `framework_rollout.paused` notification enqueued (best-effort; feeds BT-3/BT-7). This is the same cohort-completion gate a canary→main transition rides.
- **Net-zero teardown — PASSED.** Removed the canary from A (299 restored), re-observed both connectors, then surgically cleared every hub governance store (all were empty at baseline: definitions/releases/release_items/rollouts/rollout_targets/framework_rollouts/framework_rollout_targets/subscriptions/preparations/approvals/overrides/instance_links back to 0) and the two canary projections → **projections back to 2979**, environments 2. `dbvc_ac_events` is append-only (grew; not cleaned). Both connectors' live content byte-restored (A=299, B=307).

**BT-2 findings / follow-ups:**
- **F-BT2-1 (fleet-size constraint):** a literal **2-cohort canary of distinct real targets is unreachable on 2 connectors** (source is always excluded from targets). Carried to a ≥3-connector fleet (the "larger-cohort live-verify", still the primary open validation). The gate itself is proven (success completion + the pause-on-failure above).
- **F-BT2-2 (identity, resolved understanding):** Bricks **variable** identity is name-derived and **cross-site-stable**, so a created object converges in the hub's view once the target observes it — no instance link required for name-identified domains. (Objects without a stable natural key would still need the M9 instance-linker.) Caveat: a rolled-back target leaves a **stale projection** (hub `exists=1`) until the target re-observes the removal.
- **F-BT2-3 (worker claim timing):** the connector `reconcile`→`process`→`deliver` cycle frequently needs a **second `process` pass** before it claims the generation (debounce/lease), on both A and B. Harmless under a real cron cadence (the next tick claims it); only a hand-driving nuisance. Same class as BT-0 F5.
- **F-BT2-4 (deferred to BT-5):** the full failure matrix — a **fingerprint mismatch mid-flight** (edit the container between prepare and apply), **retry/resume recovery** of a paused campaign, offline-mid-cohort — belongs to BT-5's dedicated drills; BT-2 exercised one natural pause path (target divergence) only.

### BT-3 — Drift governance loop
**Goal:** prove the ownership model + notifications under real drift.
**Steps:** introduce an **approved client override** ("locked") on one connector for an object the framework also owns; push a new framework version of that object; confirm the push **skips** the locked object and flags `needs_rebase_review`; confirm the drift notifier enqueues `site.drifted` and a configured channel **delivers** (webhook/email).
**Exit:** locked client value preserved; review flag raised; notification delivered end-to-end.

### BT-4 — Large, multi-domain release
**Goal:** exercise topological ordering + the media pull channel at fleet scale.
**Steps:** build a release spanning categories→variables→classes→collections/singletons→components→templates→posts, including media **over the 2 MiB inline cap** (exercises fetch-by-hash, up to 25 MiB); roll it out; verify byte-identical apply on each connector and that the pull channel (not inline base64) carried the large files.
**Exit:** byte-identical convergence fleet-wide; pull channel used; net-zero teardown; blob GC reclaims orphans.

### BT-5 — Failure & recovery drills (disposable clones)
**Goal:** demonstrate every safety rail under induced failure.
**Steps:** force a fingerprint mismatch (edit a target container mid-flight) → conditional write refuses; take a connector offline mid-cohort → pause-on-failure halts the cohort; set a **freeze window** on a target → prepare/approve refuse; set `DBVC_CONNECTED_EMERGENCY_DISABLE` / `DBVC_AGENCY_EMERGENCY_DISABLE` → module stops regardless of settings; reviewed rollback → net-zero.
**Exit:** each invariant observed and recovered; no partial/forgotten state.

### BT-6 — Observability & audit
**Goal:** confirm the trail is complete for a full fleet cycle.
**Steps:** run `wp dbvc agency audit-export` over a real multi-site run (the trail is the event-sourced `dbvc_ac_*` stores, **not** `dbvc_activity_log`); review the notification log and `scheduler-status` heartbeat.
**Exit:** one audit bundle captures an end-to-end fleet cycle (release→rollout→apply→verify, overrides, notifications).

### BT-7 — (Optional) capacity & A2-3
**Goal:** only if BT-4/BT-6 reveal strain. Measure hub DB growth (`dbvc_ac_media_blobs`, projections, events) and tick duration vs. connector/object count; if needed, implement the A2-3 fs/object-store driver + chunked upload behind `MediaBlobStore`.
**Exit:** documented capacity envelope; A2-3 only if a real ceiling is hit.

---

## Safety invariants (hold for every slice)

- **One write path:** release → prepare → approve → fingerprint-guarded apply → verify → rollback (staged by rollouts). There is no other way to change a target.
- **Connector apply gate is authoritative** — `auto` mode never writes to a target whose gate is off and never bypasses prepare/approve/verify.
- **Net-zero on the real hub** — the hub is orchestrate-only and holds no client content; additive schema only; never leave test rows; capture a baseline first and verify net-zero at teardown.
- **Never treat real client content as a throwaway** — destructive/failure drills use the disposable connector clones; real sites change only via the guarded pipeline with rollback rehearsed.
- Masked/privacy fields never sync; deletions **trash, never hard-delete**; new-object creation + deletion propagation are opt-in; conflicts never auto-overwrite unless `source_wins` (and an approved override is never overwritten even then); granting a capability is admin-only (`manage_options`).

## Environment & commands

- **Checkouts:** hub `…/dbvc-codexchanges/…/plugins/db-version-control-main`; connectors `…/dbvc-connectedsite-a/…` and `…/dbvc-connectedsite-butler/…`. Keep all three on `master`; after a merge, ff each (`git -C <dir> fetch origin && git -C <dir> merge --ff-only origin/master`).
- **Tests (connected group):** `php -d mysqli.default_socket="$HOME/Library/Application Support/Local/run/4gScrLykQ/mysql/mysqld.sock" vendor/bin/phpunit --group connected-environments`.
- **After any PHP change:** `composer agent-docs:refresh && composer agent-docs:check` must report **0 unmapped**; if a public CLI/REST/admin/hook/setting/table/scheduled-hook/add-on/safety contract changes, follow `docs/agents/MAINTENANCE.md`.
- **Admin CSS/JS:** edit `src/connected-app/style.css` / `index.js`, then `npx wp-scripts build connected-app`, then restore the sibling bundles the single-entry build deletes (`git ls-files -d -- build/ | xargs git checkout --`); commit only the connected-app artifacts.
- **Live-verify mechanics** (sockets, wrappers, insecure-SSL mu-plugins, hub-write allow-rules, teardown): `connected-environments-m9-handoff.md`. The real-fleet 6e + A2-2 verifies are the proven templates.

## Working agreement (match the existing cadence)

One small reviewable PR per slice off `origin/master` (`git fetch` first) → tests/docs green → `gh pr merge <n> --merge --delete-branch=false` → ff all three checkouts → a separate docs-only tracking PR (ROADMAP + `tasks.json`) + a memory update. Commits end `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`; PR descriptions end `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
