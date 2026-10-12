# Connected Environments — dedicated admin page (static mockup)

Non-production mockup for the design in `docs/implementation/active/connected-environments-admin-page.md` (the plan is authoritative when the two disagree). Purely static HTML/CSS plus a 30-line helper for section switching, the Object drawer and a dark-token preview: **no state, no network, no build, no persistence**.

## Files

| File | Purpose |
|---|---|
| `index.html` | One page, both roles enabled (hub `studio` + connector `studio-site`), 1440×900: Overview (Fleet stat cards + Attention list + framework summary), Environments (+ Invite panel), Compare (state chips, selection, release composer), Framework (status, reviews, definitions), Releases (list + four-step pipeline with an expanded prepare receipt: patch, dependency ledger, hub notes, blockers), Connector (connection/coverage/pending + inbox), Settings (gates, enrollment), and the 480 px Object drawer. |
| `styles.css` | Token mirror (`:root` copied verbatim from `src/admin-app/style.css`, 2026-09-20), the scoped `--dbvc-ce-*` aliases, component parity for `dbvc-badge` / `dbvc-status-badge` / `dbvc-inline-notice` / `dbvc-tools-panel` / `dbvc-section-nav`, WP `widefat` parity, the drawer (VE drawer-family measurements) and a dark-token block. |

## How to view

Serve the folder with any static server (relative stylesheet), e.g. `python3 -m http.server 8111` inside it, then open `http://127.0.0.1:8111/index.html` at ≥ 1280 px wide. Section buttons switch panels; every "Details" opens the drawer (Escape/× closes, focus returns); "Preview dark tokens" (bottom-left) flips the scoped tokens only.

## Fixture strategy

Every value is invented: site "Example Landscaping", clients `client-a` / `client-b`, environments `a-prod` / `a-stage` / `b-prod` / `b-stage`, definitions `btn-primary` / `text-muted` / `card`. Hash prefixes, release/operation ids and timings mirror the shapes the CLI inspectors return (`wp dbvc agency …`, `wp dbvc connected …`) so the DOM holds when real data arrives; no real client content.

## Boundary held by the mockup

Present: role-adaptive header, honest "as of"/coverage copy, the six state tones (dashed = uncertain), one primary action per Attention card, the release stepper with the approve dialog's binding summary named in the step copy, the apply-gate banner on the connector side, invitation token "shown once" semantics.

Deliberately absent: mobile/tablet layouts (D-058), rollback controls (M5 step 2), fleet/cohort scheduling (M6), any content editing on the hub, any Publish/Apply button on the hub (execution only ever happens on the client's poll behind its own gate).
