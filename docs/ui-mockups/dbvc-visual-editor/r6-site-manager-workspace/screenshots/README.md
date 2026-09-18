# Screenshots

PNG captures rendered headlessly with the repository's existing Playwright + Chromium (no new install), `deviceScaleFactor: 1`, light colour scheme unless stated. Composites are captured with the gallery's scale transform removed so they are true 1440×900 frames.

| File | Viewport | Shows |
|---|---|---|
| `01-happy-path-1440x900.png` | 1440×900 (primary) | S-02 — open drawer, Navigate, populated fixture, live-style toolbar with Workspace active |
| `02-happy-path-1280x720.png` | 1280×720 (secondary) | Same at the smallest supported desktop viewport |
| `03-drawer-scrolled-no-route-rows-1440x900.png` | drawer clip | Sticky search/type strip while scrolled; no-route rows (Edit-only + honesty notes); Load more |
| `04-states-gallery-overview.png` | full page | The whole `states.html` gallery (cells + scaled composites) |
| `05-s-11-composite-1440x900.png` | 1440×900 | S-11 — Brand Control Center drawer over the inert workspace; both toolbar buttons expanded |
| `06-s-12-composite-1440x900.png` | 1440×900 | S-12 — Media Manager modal over the inert workspace |
| `07-s-13-composite-1440x900.png` | 1440×900 | S-13 — main editor panel clamped right of the drawer |
| `08-s10-no-route-rows.png` | cell | S-10 — Pending / Scheduled rows with Edit only |
| `09-s17-dark-mode.png` | cell | S-17 — dark token cascade |
| `10-s15-keyboard-focus.png` | cell | S-15 — emulated focus rings on a type tab and a row primary action |
| `11-s18-tools.png` | cell | S-18a — Tools section, all surfaces enabled |
| `12-s08-error-retry.png` | cell | S-08 — error alert with Retry, previous rows retained |
| `13-qa-production-bcc-over-workspace-1440x900.png` | 1440×900 | **Production** (R6-D-3 QA, real `overlay-app.js` + `brand-control-center-app.js` + `workspace-app.js`, stubbed API): BCC drawer over the inert workspace — compare with the S-11 composite |
| `14-qa-production-review-fields-from-workspace-1440x900.png` | 1440×900 | **Production**: Review Fields popover opened from the workspace Tools tab; both toolbar buttons expanded |
| `15-s20-kind-sort.png` | cell | S-20 — R6.1-b kind segmented control (Taxonomies checked, strip narrowed) + sort select (Title A → Z) with the status suffix; axe 0 violations |
