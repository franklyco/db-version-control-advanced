# Connected Environments — dedicated admin page (design + implementation plan)

Status: **proposed design, not implemented** (2026-09-20). Companion to `connected-environments-agency-control.md` (the runtime truth for M0–M5 step 1). Static mockup: `docs/ui-mockups/dbvc-connected-environments/admin-page/` (`index.html`, no build, no network).

## 1. Why a dedicated page

Today every Connected Environments surface lives under **DBVC Export → Configure → Add-ons → Connected Environments**: the enable checkboxes, the connector status rows, the inbox/receipt tables, and on a hub the environment/invitation/framework/review/release/receipt tables with one form (create invitation). That was the right first step (read-only, no new menu, panels only when a gate is ready) but it no longer fits the work: a studio operator now runs a **pipeline** (observe → compare → baseline → framework review → release → prepare → approve → apply) across several environments, and a client-site administrator needs to answer "is this site connected, what did it report, what is waiting, what was applied here?" without reading nine tables stacked in a settings tab.

The page keeps every runtime boundary the add-ons already enforce. It is a **binding of the existing services** — `DBVC_Connected_CLI_Inspector` and `DBVC_Agency_CLI_Inspector` already return the arrays (or `WP_Error`) the page needs — behind cookie-authenticated, capability-gated REST routes. No new business logic, no new authority: reporting never applies content, approvals stay explicit, apply stays behind its own gate, tokens are shown once.

## 2. Design principles (from the package and from DBVC's own conventions)

1. **Honest states before pretty states.** Every table and card shows *as of when* (last contact / last scan), whether coverage is complete, and never renders "clean" from an absence of data. Unknown, stale, offline and unsupported are first-class states with their own visual treatment (dashed, muted), never blank cells.
2. **One question per view.** Fleet ("what changed, what lags, what is intentional"), Client ("what differs between production and staging, what is waiting"), Object ("where does this come from, who uses it, what would change") — the three views from the intake package's experience note, mapped onto the existing services.
3. **Actions are explicit, bounded and reversible-looking only when they are.** Buttons name the exact operation the CLI performs (`Confirm baseline`, `Request prepare`, `Approve for execution`); anything that writes on a client site is behind the apply gate and shows the fingerprint/digest binding it depends on. Nothing auto-runs on page load; "Run now" buttons call the same explicit runners the CLI exposes.
4. **Backstage mechanisms stay backstage.** Epochs, sequences, digests and policy revisions are visible on demand (details, tooltips, the Object drawer), not in the first row of every table.
5. **Reuse the house style.** Global `--dbvc-color-*` tokens and the `dbvc-badge` / `dbvc-status-badge` / `dbvc-inline-notice` / `dbvc-tools-panel` / `dbvc-section-nav` components from `src/admin-app/style.css`; the 480 px right drawer family from Visual Editor (D-061) for the Object view; WordPress admin type scale and spacing. Component-scoped `--dbvc-ce-*` tokens alias the global ones (with a `prefers-color-scheme: dark` block like `overlay.css`), so the page can be themed without touching other screens. Desktop-only, per D-058.
6. **Small, server-driven tables.** Bounded limits (the inspectors already cap at 50/500), server sorting/filtering, no client-side "load everything".

## 3. Placement, roles and gating

- **Menu**: `DBVC Export → Connected Environments` (`admin.php?page=dbvc-connected`), capability `manage_options`, registered by a new `Dbvc\Connected\Admin\Page` **only when the connector gate or the hub gate is `ready`** (both gates off ⇒ no menu, no assets, no routes — the module boundary the package requires). The Add-ons subtab keeps the enable checkboxes and gains one line: "Open the Connected Environments page".
- **Role-adaptive shell**: one page, one React app, two role chips in the header — `Connector: enrolled as a-stage → https://hub…` and/or `Hub: studio`. Navigation shows the union of the sections the enabled roles need. A single install can be both (the tests and labs run that way).
- **Kill switch**: with `DBVC_CONNECTED_EMERGENCY_DISABLE` / `DBVC_AGENCY_EMERGENCY_DISABLE` defined the page renders only the emergency notice (gate state `emergency_disabled`).

## 4. Information architecture

```
DBVC Export → Connected Environments
  Header: title · role chips · gate state · "as of" last contact · [Run now ▾]
  Hub sections        : Overview · Environments · Compare · Framework · Releases · Rollouts · Settings
  Connector sections  : Overview · Activity · Inbox · Releases · Settings
  (both roles enabled : Overview · Environments · Compare · Framework · Releases · Rollouts · Activity · Inbox · Settings)
  Object drawer (right, 480 px) opens from any object row in any section.
```

### 4.1 Hub — Overview (Fleet view)

- **Status strip** (four stat cards): Environments (`n enabled · n held · n stale`), Framework (`n clean · n drift · n behind`), Releases (`n open · n sealed`), Awaiting you (`reviews classified + receipts ready + approvals open + expiring`).
- **Attention list** (the operator's inbox): cards built from existing data, newest first, each with one primary action —
  - review item `classified` (`local_drift`, `behind_version`, …) → *Open in Framework*;
  - prepare receipt `ready`/`noop` → *Approve*; `partial`/`blocked` → *See blockers*;
  - approval `approved` and expiring within 15 min → *Revoke / Prepare again*;
  - payload `mismatch` on an open release → *Withdraw / Recreate*;
  - environment `held` (`site_url_changed`, `credentials_unreadable` reported by the client) → *Release hold*;
  - environment stale (no contact inside the freshness window) → *Ping / Invite again*.
- **Framework status summary**: one row per definition (channel, desired version, adopted spread, drift counts). Click → Framework section filtered to it.

### 4.2 Hub — Environments

Table: environment · client · label · status (+ hold reason) · epoch (truncated, full on hover) · last contact · fresh · received. Row actions: *Compare with…*, *Hold*, *Release*, *Revoke* (destructive, confirm). Side panel **Invite an environment** (client, label, fixed id, TTL) → on success a modal shows the token **once** with the exact `wp dbvc connected enroll --hub=… --token=…` command and a copy button; the open-invitations list below shows id/client/expiry (never the token). This replaces the `admin-post` + transient flow with a REST call, keeping the same "shown once" semantic (the response is the only place the token exists).

### 4.3 Hub — Compare (Client view)

- **Pair picker**: source → target (same client only; the service refuses cross-client), domain filter, freshness/coverage banner (`target coverage: bricks.global_class complete · wp.service complete · variables unknown`).
- **State chips as filters**: synchronized · outgoing · incoming · converged · conflict · baseline_required · unknown — each with its count; zero counts stay visible but muted ("0 conflicts (coverage complete)" vs "0 conflicts (coverage unknown)").
- **Rows**: object (display name when the environment reported one, else UID), pairing (uid / link), state badge, reasons, source/target hash prefixes, complete/fresh flags. Row actions: *Confirm baseline* (only when the service would accept it), *Accept absent*, *Link instance…*, *Add to release* (outgoing rows only).
- **Release composer** (sticky footer when ≥ 1 row selected): `3 objects selected from a-prod · [Create release]` → note field → creates the manifest and shows the payload-collection state.

### 4.4 Hub — Framework

Three panels in one section, switchable by a `dbvc-section-nav`:
- **Definitions**: per definition, versions by channel with order, desired marker, hash prefix, source; actions *Publish version…* (hash, from environment object, or copy from version), *Set desired*.
- **Status** (the `framework-status` report): environment · instance · definition/channel · adopted · desired · drift badge · version badge · override state · reasons; actions *Adopt version…*, *Approve override…* (rationale required), *Detach override*.
- **Reviews**: items with state, drift/version, sequence; *Classify now* (runs `review-classify`), *Resolve…* (note required).

### 4.5 Hub — Releases (the pipeline)

A list of releases (uid, source, state, items, digest, note) and, for the selected release, a **four-step stepper**:

1. **Manifest** — items with operation (replace/delete), profile, after-hash prefix, payload state (`requested` / `received` / `mismatch` + reason). Sealed releases show the digest and the seal time. *Withdraw*.
2. **Prepare** — per target: request state, receipt outcome (`ready / noop / partial / blocked`), counts, expiry countdown; expanding a receipt shows each item's outcome, identity resolution (`sidecar`, `sidecar+link`, `vf_object_uid`), container + fingerprint, the exact patch (changed paths), the dependency ledger (present / selected / unresolved / unsupported) and blockers. Hub notes (`target_projection_agrees`, baseline) render beside the target's claims. *Request prepare on…*.
3. **Approve** — only enabled for `ready`/`noop` receipts that agree with the hub's projection and are unexpired; the confirm dialog prints what the approval binds (release digest, receipt digest, target + epoch, policy revision, expiry). *Revoke* while unconsumed.
4. **Execute** — the execution receipt when the target has run it: outcome (`applied / noop / stale / partial / compensated / failed / unsupported`), per-item verified flags, fingerprints before/after, journal steps, the CSS-not-rebuilt warning, and "baseline advanced for n objects". Nothing here is a button: execution happens on the client's poll, behind its own gate; the page says so.

A sealed release also carries a **Roll out…** shortcut that opens the Rollouts composer with the release preset.

### 4.5b Hub — Rollouts (fleet, M6)

A fleet rollout stages one sealed release across an ordered sequence of **cohorts** of targets — the canary first. The page never claims fleet-wide atomicity: each target is the ordinary single-environment operation and the page drives, gates and reviews it.

- **Composer** — pick a sealed release, then assign each enabled target (other than the release source) to a cohort via a per-row select (Excluded / Canary / Cohort 2…). A live summary shows the cohort/target counts; Create is disabled until the canary is assigned. Posts the grouped, ordered `cohorts` array to `agency/rollout-create`.
- **List** — rollout uid + release, state badge (`running` / `paused` / `completed` / `failed` / `withdrawn`), cohort progress (`Cohort n of m`), target count.
- **Detail** — state badge + facts (release, source, note, pause reason) and state-appropriate controls: **Advance** (idempotent — requests prepares, approves received receipts, reads outcomes; re-run as targets report), **Pause** / **Resume**, **Withdraw…** (binding modal; applied targets are not reverted). Targets are grouped per cohort (canary labelled, current cohort marked) with state + outcome tone badges; a failed target offers **Retry** (`agency/rollout-retry`, back to pending), and each target expands to **rendering evidence** — the execution receipt's outcome and warnings, including the Bricks `generated_css_rebuilt` note (ADR-042). The next cohort opens only once every current-cohort target has verified; a single failure pauses the rest.
- **Retention (M6 step 3)** — a **Prune finished…** dialog (days input + a dry-run preview of the count) removes finished rollouts (completed / withdrawn / failed) older than the window and their target rows, over `agency/rollout-prune`; running and paused rollouts and the approvals/preparations trail are never touched.

### 4.6 Connector — Overview

- **Connection card**: enrollment state, hub URL, principal (username only), epoch, connection state + hold reason, last delivery / last poll / next scheduled run, "WP-Cron disabled — runs need an external runner" when applicable.
- **Coverage card** per domain: available / unavailable (+ reason), present / absent / incomplete counts, identity source.
- **Pending work**: dirty markers (due / leased / errored), outbox pending, inbox unacked, unreported receipts/operations. *Observe now*, *Deliver now*, *Poll now*, *Run release work* — the explicit runners; results land in a polite live region and a toast.

### 4.7 Connector — Activity, Inbox, Releases

- **Activity**: outbox events (sequence, domain, object, origin `human/apply/rollback/reconciliation`, delivery state, receipt outcome), filter by domain/state; *Reconcile domain…*.
- **Inbox**: received observations grouped by source environment (exists/complete/hash, acked), with the standing note that received observations are never applied.
- **Releases**: prepare receipts this site produced (outcome, counts, expiry, reported) with the same expandable item detail as the hub; the execution journal (state, outcome, applied/stale/failed, steps) — the apply gate state is a banner at the top of this section (`Apply is off — approved releases are not executed here`).

### 4.8 Settings

Connector: enable, **apply gate** (checkbox with its own explanatory copy and a confirm), enroll (hub URL + token; token field is `type=password`, never echoed), *Resume* / *Re-enroll* (revoke-aware), processing delay (filter value shown read-only), excluded option keys (read-only list). Hub: enable, freshness window (read-only filter value), emergency state, schema version. Both: "Emergency stop" copy pointing at the constants.

### 4.9 Object drawer (Object view)

Right-anchored 480 px drawer (`role="complementary"`, no backdrop, Escape closes, focus returns to the row), reusing the VE drawer shell measurements. Sections: **Identity** (UID, domain, profile, storage key/display name, lineage links), **Now** (hash prefix, exists/complete, observed sequence, as-of), **Framework** (definition/version/override, drift + version badges, rationale), **Baselines** (per pair: hash, confirmed by/at), **Releases** (items that include it; receipts; execution outcomes), **History** (recent events for the UID). Actions mirror the sections' actions but scoped to the object.

## 5. Visual system

### 5.1 Tokens (scoped aliases of the global set)

```css
.dbvc-ce {
  --dbvc-ce-color-text:        var(--dbvc-color-text-primary);   /* #1d2327 */
  --dbvc-ce-color-text-muted:  var(--dbvc-color-text-muted);     /* #50575e */
  --dbvc-ce-color-text-subtle: var(--dbvc-color-text-subtle);    /* #8c8f94 */
  --dbvc-ce-color-border:      var(--dbvc-color-border-default); /* #dcdcde */
  --dbvc-ce-color-border-strong: var(--dbvc-color-border-muted); /* #c3c4c7 */
  --dbvc-ce-color-surface:     var(--dbvc-color-white);
  --dbvc-ce-color-surface-muted: var(--dbvc-color-surface-muted);/* #f6f7f7 */
  --dbvc-ce-color-surface-table: var(--dbvc-color-surface-table);/* #f9f9f9 */
  --dbvc-ce-color-accent:      var(--dbvc-color-accent-blue);    /* #2271b1 */
  --dbvc-ce-color-accent-soft: var(--dbvc-color-surface-highlight); /* #f0f6ff */
  --dbvc-ce-color-success:     var(--dbvc-color-success);        /* #2c9f45 */
  --dbvc-ce-color-warning:     var(--dbvc-color-warning-amber);  /* #d97706 */
  --dbvc-ce-color-danger:      var(--dbvc-color-danger);         /* #d63638 */
  --dbvc-ce-radius: 8px;  --dbvc-ce-radius-sm: 4px;
  --dbvc-ce-shadow: 0 2px 8px rgba(0,0,0,.05);
  --dbvc-ce-focus-ring: 0 0 0 2px var(--dbvc-ce-color-surface), 0 0 0 4px var(--dbvc-ce-color-accent);
  --dbvc-ce-drawer-width: 480px;
}
@media (prefers-color-scheme: dark) { .dbvc-ce { /* flip surfaces/text like overlay.css; badges keep hue, lift lightness */ } }
```

### 5.2 State vocabulary → badge treatment

`dbvc-badge` already themes by hue (`--dbvc-badge-h/s/l`). One modifier per state, so a state reads the same in every section:

| Tone | Hue | States |
|---|---|---|
| Positive (green) | 140° | `synchronized`, `clean`, `current`, `applied`, `sealed`, `received`, `consumed`, `enabled`, `present` |
| Directional (blue) | 210° | `outgoing`, `incoming`, `ready`, `requested`, `open`, `selected` |
| Neutral (slate) | 215° / 6 % | `converged`, `noop`, `baseline_required`, `observed`, `classified` |
| Caution (amber) | 35° | `local_drift`, `behind_version`, `ahead_version`, `channel_mismatch`, `needs_rebase_review`, `stale`, `partial`, `mismatch`, `held`, `expiring` |
| Negative (red) | 358° | `conflict`, `override_changed`, `failed`, `compensated`, `revoked`, `unresolved`, `cancelled` |
| Uncertain (gray, **dashed** border) | 210° / 6 % | `unknown`, `offline`, `unsupported`, `not observed`, `incomplete` |

Rules: a badge is never the only carrier of meaning (text label always present); "uncertain" badges are dashed so an unknown never reads as neutral-good; counts of zero next to an uncertain coverage state render muted with the reason in parentheses.

### 5.3 Layout and components

- Page width: WP admin content width; two-column at ≥ 1280 px where a section has a side panel (Environments + Invite, Compare + Composer summary), single column otherwise. Nothing below 1024 px is a design target (desktop-only, D-058).
- Section nav: horizontal `dbvc-section-nav` buttons (`aria-current`), not WP `nav-tab`, so it matches the admin app.
- Cards: `dbvc-tools-panel` (8 px radius, 1 px border, soft shadow) for stat cards, connection/coverage cards and the release stepper; tables are WP `widefat striped` with a 12 px `code` column for UIDs/hashes and right-aligned counts.
- Stepper: four numbered nodes with state color; the active step is expanded, others collapsed with a one-line summary.
- Notices: `dbvc-inline-notice` (left 4 px border) for gate/coverage/warning copy; toasts (`dbvc-toast`) only for run results.
- Drawer: `--dbvc-ce-drawer-width`, sticky header with UID and close, sectioned body, footer actions.
- Type: WP admin defaults (13 px body, 20/23 px headings); numbers tabular; monospace only for UIDs, hashes, commands.
- Motion: 150 ms ease for panel/drawer; `prefers-reduced-motion` removes transitions.

### 5.4 Colour readability remediation plan (light + dark)

Reported issue: some text is hard to read in both schemes. **Implemented 2026-09-22** in `src/connected-app/style.css` (rebuild `connected-app`; light + dark verified in the built-in browser against the built stylesheet, and every pair below re-checked with an sRGB contrast script). Target is **WCAG AA**: ≥ 4.5:1 for body text, ≥ 3:1 for large text (≥ 24 px, or ≥ 18.66 px bold) and for UI component/state boundaries.

**Findings (current tokens):**

| # | Element / token | Mode | Foreground → background | Contrast | Verdict |
|---|---|---|---|---|---|
| 1 | Active section-nav pill `.dbvc-section-nav button[aria-current="page"]` | Dark | `#fff` text → `var(--dbvc-ce-color-text)` = `#f0f0f1` bg | ~1.1:1 | **Fail (critical)** — near-white on near-white; the active tab is invisible (seen live) |
| 1b | Out-of-panel text (`.dbvc-ce__title`, the role / `.dbvc-ce-asof` line, bare copy) — `.dbvc-ce` paints **no background** | Dark | near-white text → WP admin's light content chrome (the host does not follow `prefers-color-scheme`) | ~1.3:1 | **Fail (critical)** — for an OS-dark user on a light WP admin, the page title and "…· as of …" line vanish; found during implementation |
| 2 | `--dbvc-ce-color-text-subtle` (`#8c8f94`): `.dbvc-ce-asof`, `.widefat .subtle`, drawer/obj `code`, placeholders | Light | `#8c8f94` → `#fff` | 3.24:1 | **Fail** — the "…· as of …" header line and small timestamps read faint |
| 3 | `--dbvc-ce-color-text-subtle` (`#8c8f94`) on muted/table surfaces | Dark | `#8c8f94` → `#2c3338` / `#23282d` | 3.95 / ~4.5:1 | **Fail / borderline** — same subtle token is unchanged from light, too dark for dark surfaces |
| 4 | Accent-as-text `.dbvc-ce-linkbtn` / `.button-link` on tinted bg | Dark | `#4f94d4` → `--dbvc-ce-color-accent-soft` `#1b2a3d` | 4.52:1 | **Borderline** — passes body AA by a hair; thins out on the composer/evidence tint |
| 5 | Duplicate `.dbvc-ce--dark-preview .dbvc-badge` rules (`--dbvc-badge-text-darken: -34%` at L60/63 vs `-30%` at L123) | Dark preview | — | — | **Inconsistency** — the gallery preview and real `prefers-color-scheme: dark` compute badge text differently |
| — | `--dbvc-ce-color-text-muted` (`#50575e` / `#c3c4c7`), body text, accent on base surface | Both | — | 7.3 / ~9 / ~5:1 | Pass — leave unchanged |

**Fixes:**

1. **Active nav pill** — invert with the surface token instead of hard-coded white: `.dbvc-section-nav button[aria-current="page"] { color: var(--dbvc-ce-color-surface); }` (keep `background: var(--dbvc-ce-color-text)`). Light → dark pill + white label (unchanged); dark → near-white pill + dark label (fixed). One-line change, no new token.
2. **`text-subtle` in light** — darken the light-mode value from `#8c8f94` to `#6b7280` (the existing `--dbvc-color-gray-600`): 4.84:1 on white. Update `:root`/`.dbvc-ce` light mapping only.
3. **`text-subtle` in dark** — the dark blocks (`@media prefers-color-scheme: dark` and `.dbvc-ce--dark-preview`) currently repeat `#8c8f94`; raise to `#a7aaad`: 6.8:1 on `#1d2327`, 5.5:1 on `#2c3338`. This decouples the two schemes (the token is the single point of failure because it was shared).
4. **Accent-as-text on tint (optional, dark)** — add `--dbvc-ce-color-accent-text` (light `#2271b1`, dark `#79b0e6`) and use it for `.dbvc-ce-linkbtn` / `.button-link`; `#79b0e6` on `#1b2a3d` ≈ 6.2:1. Keeps the accent hue for fills/borders while lifting link text off tinted panels.
5. **Badge dedup** — deleted the stale `.dbvc-ce--dark-preview .dbvc-badge` rule so both dark-preview and real dark use one `--dbvc-badge-text-darken` (−34%); the `negative`/`directional` badge **text** on their 0.2-alpha fills already clears 4.5:1 in light, so `--dbvc-badge-text-darken` stayed at 18%.
6. **Dark-mode container background (found during implementation).** `.dbvc-ce` themed its text near-white in dark but painted no background, so out-of-panel text (title, role / as-of line, bare copy) sat on WP admin's light chrome — the biggest dark-mode readability hole. Both dark blocks now paint the app region as a self-contained dark canvas: `background: #16191d; padding: 12px 16px 20px; border-radius: 12px`. Panels (`#1d2327`) read above the `#16191d` canvas; light mode is unchanged (still no background — dark text on the light WP admin chrome).

**Verification (done, 2026-09-22):** rebuilt `connected-app`; a built-in-browser render of the built stylesheet in both `prefers-color-scheme` values confirmed the active nav tab, the "as of" header line, small `subtle` timestamps, the tinted-panel links and every badge tone read cleanly in both, and that the dark canvas keeps the header/nav/as-of readable on a light host. A one-off sRGB contrast script re-checked all token pairs at ≥ 4.5:1 (nav pill ~14:1 both modes; `subtle` 4.8:1 light / 5.5–6.8:1 dark; accent-text 5.2:1 light / 6.3–6.9:1 dark). Tokens + one nav rule + a dark-only container background; no behaviour changes.

## 6. Data and security plan

### 6.1 Admin REST namespace (new, cookie + nonce + `manage_options`)

`dbvc/v1/connected/*` (connector) and `dbvc/v1/agency/*` (hub), registered by the same gate checks the CLI uses (`require_ready`); each handler is a thin wrapper over an inspector/service method and returns its array or its `WP_Error` unchanged.

| Route | Wraps | Writes |
|---|---|---|
| `GET connected/status`, `objects`, `outbox`, `inbox`, `inventory`, `preparations`, `operations` | `DBVC_Connected_CLI_Inspector::*` | no |
| `POST connected/run/{process,deliver,poll,release}`, `reconcile` | runners / `request_reconciliation` | management state only |
| `POST connected/enroll`, `resume` | `EnrollmentService` | management state (token never echoed) |
| `POST connected/settings` | `save_settings` | gate options |
| `GET agency/status`, `environments`, `events`, `projections`, `subscriptions`, `deliveries`, `reviews`, `compare`, `baselines`, `links`, `definitions`, `overrides`, `framework-status`, `releases`, `preparations`, `approvals` | `DBVC_Agency_CLI_Inspector::*` | no |
| `POST agency/invite`, `revoke`, `release-hold`, `subscribe`, `subscribe-framework`, `unsubscribe`, `enable-subscription`, `route`, `baseline-confirm`, `link-instance`, `unlink-instance`, `definition-publish`, `definition-desire`, `adopt-version`, `override-approve`, `override-detach`, `review-classify`, `review-resolve`, `release-create`, `release-withdraw`, `prepare-request`, `approve`, `revoke-approval` | the matching inspector methods | hub management data |

Rules: routes exist only while the gate is ready; every write route re-checks `manage_options` and the REST nonce; the invitation token appears only in the `invite` response body (the page shows it once and discards it); no route accepts an app-password principal (those stay on `dbvc-agency/v1`); responses are `no-store`.

### 6.2 Frontend

- `src/connected-app/` built by the existing `wp-scripts` pipeline into `build/connected-app.js` / `.css` (same as `admin-app`), enqueued only on `page=dbvc-connected`, with `wp_localize_script` providing `root`, `nonce`, role/gate flags and the freshness window.
- React with `@wordpress/element`; a small fetch layer that surfaces `WP_Error` codes as inline notices; per-section state, server-driven tables (page/limit/filters), polling only while a run is in flight.
- The Object drawer is a shared component fed by the row's `{domain, instance_uid, environment_id}`; it calls `projections`, `framework-status`, `baselines`, `releases`, `events` filtered to that object.

### 6.3 Accessibility

Keyboard-complete (roving tabindex on section nav and chips, arrow keys in tables' action groups), one polite live region per page for run results, `aria-expanded` on stepper nodes and receipt rows, focus restoration on drawer/modal close, visible focus ring token, reduced-motion support, no color-only meaning.

## 7. Delivery slices (each: code + tests + capability record + docs)

| Slice | Scope | Evidence |
|---|---|---|
| A1 — **done** (2026-09-20) | Page shell, gating, role chips, Settings (gates, apply confirmation, enrollment/resume), role-neutral `connected-admin/{overview,settings}` routes plus every connector `connected/*` and hub `agency/*` route wrapper, Overview stat cards / attention list (hub) and connection / coverage / pending cards + runners (connector), Run now menu, toasts + live region | `tests/phpunit/ConnectedAdminPageTest.php` (menu/routes absent with both gates off, per-role registration, 401/403/200, `no-store`, overview shape, read routes == inspector output, runner acknowledgement, settings semantics, bootstrap config, built assets); lab: hub + two connectors exercised in the built-in browser, apply gate turned on through the confirm dialog, `Run release work` executed the approved release |
| A2 — **done** (2026-09-20) | Hub Environments table (status + hold reason, short epoch with full value on hover, last contact + fresh/stale against the hub's freshness window, received), row actions Hold (operator note → `operator:<note>`, new `wp dbvc agency hold` / `POST agency/hold`), Release hold, Revoke (inline confirmation); Invite form → token modal shown once with the enroll command + copy button (focus returns to the opener, Escape closes); invitations list (new `wp dbvc agency invitations` / `GET agency/invitations`, states open/consumed/expired, never tokens). Connector cards + runners already shipped in A1 | `ConnectedAdminPageTest::test_environment_actions_and_invitations_never_expose_tokens`; lab: hold refused the connector's batch (409), release re-enabled, modal + focus restoration, revoke |
| A3 — **done** (2026-09-20) | Compare: source/target pickers (same client only; Environments rows carry a Compare… shortcut that presets the source), domain filter, freshness + coverage banner with baseline/link counts, state chips as filters with counts, rows with pairing (uid / link → target UID), state + reasons, source/target/baseline hash prefixes, absent/unobserved flags; row actions Details, Confirm baseline (only when the service would accept: converged, or baseline_required with equal hashes, complete + fresh), Accept absent (baseline_required with exactly one verified-absent side), Link instance… (uid rows whose target is unobserved). Object drawer (480 px, no backdrop, Escape closes, focus returns to the row): identity, both sides' projections, baselines, recent events — via new `--instance` filters on `agency events|projections|baselines`. Release composer / Add to release deferred to A5 | `ConnectedAdminPageTest::test_compare_baselines_links_and_instance_filters`; lab: drawer + Escape/focus, Link instance (6 → 5 rows, banner 1 link), Accept absent (row → incoming), Confirm baseline (row → synchronized) |
| A4 — **done** (2026-09-20) | Framework section with three panels: **Status** (framework-status report: environment, instance + actual hash, definition/channel, adopted, desired, drift + version badges with reasons, override state; drift chips as filters + rebase-review count; actions *Adopt <desired>* when it differs, *Approve override…* (rationale required) on local_drift / override_changed, *Detach override*; a Subscribe-an-object form), **Definitions** (versions grouped per definition by channel + studio order, desired badge, hash, domain · profile, source, note; *Set desired*; Publish form with body from hash / environment object / copy from version, profile defaulted per domain), **Reviews** (state chips, table with drift · version, *Classify now*, *Resolve…* with a required note) | `ConnectedAdminPageTest::test_framework_definitions_status_overrides_and_reviews`; lab: approve override (→ approved_override, auto-resolved review), publish 1.1 by copy → Set desired (→ behind_version + needs_rebase_review) → Adopt 1.1 (→ current) → Detach (→ local_drift), subscribe form (→ unknown row with reasons) |
| A5 — **done** (2026-09-21) | Compare gains a per-row release checkbox on outgoing rows and a sticky **release composer** (create from selection, then jump to Releases). Releases section: list (uid, source, state, items, created) + a four-step **stepper** — **Manifest** (items with operation, after-hash, payload state; seal/collect note; Withdraw), **Prepare** (per target: outcome + ready/noop/blocked counts + expiry; expand receipt → per-item identity/container/fingerprint/patch changed-paths/dependency ledger/blockers + hub-notes disagreement count; Request prepare on a target), **Approve** (enabled only for ready/noop unexpired receipts; a binding **Modal** printing target·epoch, release + receipt digests and expiry; Revoke while approved), **Execute** (read-only: execution outcome, per-item verified, counts, CSS-not-rebuilt warning — no button, runs on the target's poll) | `ConnectedAdminPageTest::test_release_pipeline_create_list_withdraw_and_guards`; lab: composer → create → seal → prepare → receipt detail → approve dialog → apply gate on → executed (astage btn001 padding written, receipt `applied`/verified) |
| A6 — **done** (2026-09-21) | Connector-role **Activity** section with three panels: **Activity** (outbox events — sequence, object, origin badge human/reconciliation/apply, hash, delivery state; delivery-state + domain chips as filters; a Reconcile all / Reconcile <domain> runner), **Inbox** (received observations grouped by source environment with the standing never-applied notice, exists/hash, acked), **Releases** (an apply-gate banner — `Apply is off — approved releases are not executed here` / `Apply is on`; prepare receipts this site produced with the same expandable item detail as the hub; and the execution journal — state, outcome, applied/stale/failed, expandable step list) | `ConnectedAdminPageTest::test_connector_activity_reads_wrap_the_inspectors`; lab (astage): outbox with origins, inbox grouped by aprod, prepare receipt items, execution journal steps (`write → written`, `verify → verified`), Reconcile |
| Rollouts (M6 step 2) — **done** (2026-09-21) | Hub **Rollouts** section: a composer that assigns enabled targets to ordered cohorts of a sealed release (canary first) and posts `agency/rollout-create`; a list with state badge + cohort progress; a detail view with Advance / Pause / Resume / Withdraw (binding modal) over `agency/rollout-*`, per-cohort target rows with state + outcome tone badges, Retry on a failed target, and an expandable per-target rendering-evidence row (execution receipt outcome + warnings incl. `generated_css_rebuilt`); a **Roll out…** shortcut on a sealed release. New tone mappings `running`/`pending`/`preparing`/`succeeded`/`completed`/`paused`. Backend is M6 step 1 (`agency/rollout-*`, `agency/rollouts`) | Production build + `wp-scripts lint-js` (hooks + jsx-a11y); `ConnectedAdminPageTest` route wiring for `agency/rollout-*`/`agency/rollouts`; the 90-test `connected-environments` group; built-in-browser render of the built bundle markup + stylesheet in light and dark |
| A7 — **done** (2026-09-21) | Dark-mode tokens applied to `.dbvc-ce` under `@media (prefers-color-scheme: dark)` (badge tones lighten instead of darken; form controls themed to the surface/border/text tokens); a **states gallery** at `docs/ui-mockups/dbvc-connected-environments/admin-page/states.html` (every state string grouped by tone, light/dark toggle); the capability record stands; the Add-ons tab **tables are retired** — the connector status table + inbox panel and the hub panel are replaced by a compact one-line summary and an **Open the Connected Environments page →** button, with the enable / apply-gate checkboxes kept | `ConnectedAdminSurfacesTest` (panel classes unchanged, still cover the legacy invite handler); lab: app in emulated dark, Add-ons tab shows no tables (checkbox + link) on both a connector and the hub |

Out of scope for this page: mobile/touch, a second field editor, any content edit on the hub, rollback controls (M5 step 2 adds the operation first), fleet/cohort scheduling (M6).

## 7.1 Implementation notes (A1)

- Files: `admin/class-connected-admin-page.php` (menu, assets, `connected-admin/*` routes), `addons/connected-environments/src/Admin/RestController.php`, `addons/agency-control/src/Admin/RestController.php` (registered by each runtime on `rest_api_init`, removed on `unregister()`), `connected-app.js` → `src/connected-app/{index.js,style.css}` → `build/connected-app.*` (`npm run build`; rebuilding a single entry with `wp-scripts build connected-app` deletes the other build outputs — restore them with `git checkout -- build/` before committing).
- Bootstrap config: `root` is the site REST root (`rest_url()`), `namespace` is `dbvc/v1`; the app qualifies relative paths itself so `?rest_route=` permalinks work. `wp_localize_script` also passes `roles`, `gates`, `pluginVersion`, `addonsUrl`.
- `render()` prints `<hr class="wp-header-end">` before the mount so core/plugin admin notices land above the app header.
- Route wrappers whitelist request args (`limit, domain, hub, token, budget, fields, operation` on the connector; the method's own keys on the hub) and pass them to the CLI inspector methods; `WP_Error` results are returned unchanged with a 400 default status.
- Capability record: `addon.connected_environments.admin_page` (strict agent-docs check maps the discovered `admin.menu.*` surface; role routes are built from method maps and are documented as undiscoverable by static discovery).
- Deviation from the table above: A1 already ships the connector runner buttons and the hub `route`/`review-classify` runners (planned for A2) because the Overview cards needed them to be useful.
- M5 step 2: the Releases Execute step gained a **Roll back…** button on applied/partial/compensated operations (a binding confirm modal → `POST agency/rollback`); rollback approvals render in the Execute list with a rollback badge, and an operation already rolled back no longer offers the button. The connector-role Activity Releases journal shows `roll-…` operations restored on this site.
- A7: dark mode follows the viewer's OS/browser preference (`@media (prefers-color-scheme: dark)` on `.dbvc-ce`); the mockup's `.dbvc-ce--dark-preview` class forces it for the states gallery. The Add-ons tab keeps the add-on enable/apply checkboxes and shows a compact summary + a link to the page; the big connector status table and the `InboxPanel` / `HubPanel` tables are no longer rendered there (the panel classes stay because `HubPanel` still backs the legacy `admin_post_dbvc_agency_invite` handler and both are covered by `ConnectedAdminSurfacesTest`). Open decision 1 is resolved as proposed (keep the checkboxes + one link; remove the tables).
- A6: no new backend surfaces — the connector `connected/{outbox,inbox,preparations,operations}` reads and the `connected/reconcile` runner already existed. The shared runner dispatcher (`run()`) now honours a runner's explicit `path`, `data` and an `after` callback so the Activity Reconcile button reuses it (toast + in-flight state) while reloading only its own section. The connector `preparations?operation=` / `operations?operation=` reads return the full receipt / journal row, so the same `ReceiptItem` component renders both hub and connector prepare receipts.
- A5: no new backend surfaces (all existing `agency/*` routes). The composer selects only `outgoing`, complete, fresh rows (the releasable state) and posts `domain:instance_uid` specs to `release-create`. Prepare-receipt patch `changed_paths` are objects (`{path, operation}`), rendered as `path (operation)`. Sealing (source connector payload transfer) and execution (target apply gate) happen on the connectors' polls, not from the page — the Execute step is read-only and says so. The Releases component reloads its own state on mount and after its actions; a seal/receipt produced out-of-band on the connector shows after a page reload.
- A4: no new backend surfaces; `definition-publish` requires a profile, so the page defaults it per domain (`DOMAIN_PROFILES`: bricks-global-class-v1 / bricks-variable-v1 / wp-service-v1) and shows the default as the placeholder. Drift chips filter client-side (the report is small: one row per enabled framework subscription). Review items only exist for events routed *after* a framework subscription was created; the Reviews empty state says so.
- A3: no new service methods; `EventStore::all()`, `ProjectionStore::all()` and `BaselineStore::all()` gained an optional instance-UID filter (baselines match source or target UID) so the drawer loads one object without paging. The hub never receives display names (privacy boundary, open decision 3), so Compare rows and the drawer show UIDs and say so. Compare filtering by state chip is client-side over the full result (`agency/compare` accepts `state` for CLI parity but the page needs all counts).
- A2: `EnrollmentService::hold()` is the only new service method (status `held`, `hold_reason = operator:<note>`, refused for revoked or already-held environments); `release()` now keeps the enrolled URL when no site URL was reported since the hold. Table row actions use `.dbvc-ce-actions`, not core's `.row-actions` (core hides that class until row hover). The invite form's submit button stays focusable while the request runs so the modal can return focus to it.

## 8. Open decisions (record as ADRs when taken)

1. ~~Retire the Add-ons subtab panels once A2/A6 land, or keep a compact read-only summary there?~~ **Resolved (A7):** the Add-ons tab keeps the enable/apply checkboxes and a link to the page; the tables are removed. The `InboxPanel` / `HubPanel` classes remain only to back the legacy `admin_post` invite handler and their tests.
2. Should the hub Overview poll for changes while open (30 s) or stay manual-refresh? (Proposal: manual + "as of" stamp; polling only during an explicit run.)
3. Environment display names: the hub never receives object names (privacy boundary) — the page shows UIDs with the environment's own `display_name` only where the connector included it in its projection. Confirm this is acceptable for the Fleet view.
4. Whether `review-resolve` and `approve` need a second-person confirmation (two administrators) before M6.
