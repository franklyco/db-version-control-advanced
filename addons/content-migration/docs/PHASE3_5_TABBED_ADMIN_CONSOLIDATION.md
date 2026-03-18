# Phase 3.5 Tabbed Admin Consolidation Plan

Goal: consolidate addon UX into a parent tab layout: `Collect`, `Explore`, `Configure`.

## Current Slice Status

- Implemented in current slice:
  - Canonical tab host for `dbvc_cc` page.
  - `Collect`/`Explore`/`Configure` tab views split into partials.
  - Legacy explorer slug compatibility redirect to `tab=explore`.
  - Default crawl settings + per-crawl override wiring (`Collect` -> AJAX -> crawler runtime).
- Remaining work:
  - Final visual polish and tab-specific style refinements.
  - Full manual QA sweep across tab deep-link and crawler/explorer parity scenarios.

Scope boundary for this phase:
- In scope: admin navigation/layout consolidation, settings field reorganization, compatibility routing.
- Out of scope: changing crawler/explorer/AI endpoint contracts, import/execution logic.

## Why This Phase Exists

Current setup uses separate submenu pages for collector/explorer/workbench. This adds UX friction and creates repeated page-level wrappers. A dedicated tab consolidation phase reduces later rework before Phase 4/5 surfaces are added.

## Inputs / Constraints

- Single-site only.
- No runtime dependency on `_source/content-collector`.
- Keep feature flags and module boundaries intact.
- Required `Configure` input order:
  1. `Storage Folder`
  2. `Dev Mode`
  3. `OpenAI Model`
  4. `OpenAI API Key`
  5. `Prompt Version`
- `Configure` must render fields inside a real `<section>` element.
- Crawl-centric settings model:
  - `Configure` owns default crawl settings.
  - `Collect` pre-fills from those defaults.
  - `Collect` allows per-crawl overrides that apply only to the current crawl execution.

## Task Breakdown

### T1: Add tabbed page shell on canonical addon slug
- T1.1 Create a tab host view (new shared/collector view partial) for `tab=collect|explore|configure`.
- T1.2 Update `DBVC_CC_Admin_Controller` to resolve active tab and render matching content partial.
- T1.3 Preserve capability checks and current `dbvc_cc` slug behavior.

### T2: Move crawl controls into `Collect`
- T2.1 Extract crawler form/progress UI from current `dbvc-cc-admin-page.php` into a collect partial.
- T2.2 Keep existing IDs used by `dbvc-cc-crawler-admin.js`.
- T2.3 Render crawl-centric controls prefilled from configured defaults (`dbvc_cc_settings`).
- T2.4 Add per-crawl override payload from `Collect` AJAX requests (`get_urls` + `process_url` flow).
- T2.5 Apply overrides at crawl runtime without mutating persisted defaults.
- T2.6 Keep supported crawl-centric inputs aligned with existing crawler options (`request_delay`, `request_timeout`, `user_agent`, `exclude_selectors`, `focus_selectors`).

### T3: Move Explorer into `Explore` tab
- T3.1 Render explorer markup under `Explore` tab using existing explorer view partial.
- T3.2 Ensure explorer assets/localized data load only when `tab=explore`.
- T3.3 Maintain existing REST contract behavior and no JS runtime errors when tab is inactive.

### T4: Build focused `Configure` section
- T4.1 Create `Configure` tab content with `<section class="dbvc-cc-configure-section">...</section>`.
- T4.2 Render only required inputs in exact order:
  1. `Storage Folder`
  2. `Dev Mode`
  3. `OpenAI Model`
  4. `OpenAI API Key`
  5. `Prompt Version`
- T4.3 Keep form posting through `options.php` with `dbvc_cc_options` group and `dbvc_cc_settings[...]` field names.
- T4.4 Label this section as default crawl presets for `Collect` prefill behavior.

### T5: Compatibility routing and submenu cleanup
- T5.1 Decide final menu strategy:
  - preferred: keep only canonical submenu for collect/explore/configure tabs
  - keep optional compatibility submenu entries with redirects.
- T5.2 If `dbvc_cc_explorer` remains reachable, redirect to `admin.php?page=dbvc_cc&tab=explore`.
- T5.3 Keep `dbvc_cc_workbench` separate for now (explicitly not absorbed into this 3-tab request).

### T6: Script/style enqueue hardening
- T6.1 Refactor enqueue logic to be tab-aware (avoid loading explorer/cytoscape on collect/configure).
- T6.2 Ensure no duplicate handle registration conflicts.
- T6.3 Verify localized objects are available only when corresponding script runs.

### T7: QA + regression checks
- T7.1 Validate tab switching, deep links (`?page=dbvc_cc&tab=...`), and reload persistence.
- T7.2 Validate crawl actions on `Collect`.
- T7.3 Validate explorer graph/search/actions on `Explore`.
- T7.4 Validate settings save/read on `Configure`.
- T7.5 Validate no fatal errors from missing DOM targets when non-active tab scripts are not loaded.
- T7.6 Validate `Collect` shows defaults from `Configure` on load.
- T7.7 Validate `Collect` override values affect crawl behavior for the current run.
- T7.8 Validate global defaults remain unchanged unless `Configure` is explicitly saved.

## Conflict Analysis / Mitigations

1. Script coupling to page hooks:
- Risk: explorer JS executes without required DOM.
- Mitigation: strict tab-aware enqueue + guard JS initialization on root element presence.

2. Existing bookmarked submenu links:
- Risk: user bookmarks break when submenu removed.
- Mitigation: retain compatibility submenu or redirect legacy slug to canonical tab URL.

3. Settings field redistribution:
- Risk: dropped fields from visible UI become inaccessible.
- Mitigation: explicitly place crawl-centric fields in `Collect` and only global core fields in `Configure`.

4. Default-vs-override state confusion:
- Risk: per-crawl overrides accidentally persisted as global settings.
- Mitigation: split persistence path (`options.php`) from crawl execution payload; sanitize and apply overrides in request scope only.

5. Workbench overlap:
- Risk: accidental scope creep by absorbing workbench into tabs now.
- Mitigation: keep workbench as separate submenu this phase; revisit in later UX phase.

6. CSS collisions between embedded explorer and collect views:
- Risk: layout/styles bleed across tabs.
- Mitigation: wrap each tab panel with dedicated container classes and isolate selectors.

## Deliverables

- Canonical tabbed addon page with `Collect`, `Explore`, `Configure`.
- Updated controller/menu/enqueue logic with compatibility behavior documented.
- Configure section with ordered fields in real `<section>` wrapper.
- Explicit default/override crawl settings data flow implementation.
- Updated docs and test checklist for tab navigation.

## Acceptance Criteria

- Visiting `admin.php?page=dbvc_cc` loads tab UI with all three tabs.
- `Collect` tab can crawl sitemap URLs as before.
- `Collect` crawl-centric inputs are prefilled from `Configure` defaults.
- Overriding crawl-centric inputs in `Collect` affects current crawl run without rewriting global defaults.
- `Explore` tab exposes all existing explorer functionality without runtime errors.
- `Configure` tab saves and reloads fields in required order within a `<section>` element.
- Legacy explorer URL either still works or deterministically redirects to the explore tab.
- No contract changes to AJAX/REST payloads for collector/explorer/ai.

## Entry / Exit

Entry:
- Phase 3 complete and stable.

Exit:
- Tabbed UX merged and validated; then proceed to Phase 3.6 deep capture/context/AI section typing before Phase 4 import planning/execution.
