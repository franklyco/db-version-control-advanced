# Temporary UI Tab Mapping (Current -> Target)

Purpose: short-lived implementation map to safely refactor admin UX into parent tabs (`Collect`, `Explore`, `Configure`) without breaking existing module behavior.

## Current Runtime Mapping

| Concern | Current Location | Current Contract |
|---|---|---|
| Parent addon menu page | `collector/dbvc-cc-admin-controller.php` -> `add_admin_menu()` | Submenu slug: `dbvc_cc` |
| Tab host renderer | `collector/dbvc-cc-admin-controller.php` -> `render_admin_page()` | Renders `collector/views/dbvc-cc-admin-page.php` and tab partials |
| Explorer compatibility route | `collector/dbvc-cc-admin-controller.php` -> `redirect_legacy_explorer_page()` | Legacy slug `dbvc_cc_explorer` redirects to `dbvc_cc&tab=explore` |
| Workbench page renderer | `collector/dbvc-cc-admin-controller.php` -> `render_workbench_page()` | Separate submenu slug: `dbvc_cc_workbench` |
| Collect scripts/styles | `enqueue_scripts()` branch `hook === main_page_hook` and active tab `collect` | Handles: `dbvc_cc_admin_styles`, `dbvc_cc_admin_script`, localized `dbvc_cc_ajax_object` |
| Explorer scripts/styles | `enqueue_scripts()` branch `hook === main_page_hook` and active tab `explore` | Handles: `dbvc_cc_explorer_styles`, `dbvc_cc_cytoscape`, `dbvc_cc_explorer_script`, localized `dbvc_cc_explorer_object` |
| Workbench scripts/styles | `enqueue_scripts()` branch `hook === workbench_page_hook` | Handles: `dbvc_cc_workbench_styles`, `dbvc_cc_workbench_script`, localized `dbvc_cc_workbench_object` |
| Collector form IDs/hooks | `collector/views/dbvc-cc-admin-page.php` + `collector/assets/dbvc-cc-crawler-admin.js` | Uses `#cc-form`, `#sitemap_url`, `#cc-status-log`, AJAX actions `dbvc_cc_get_urls_from_sitemap`/`dbvc_cc_process_single_url` |
| Explorer root IDs/hooks | `explorer/views/dbvc-cc-explorer-page.php` + `explorer/assets/dbvc-cc-explorer.js` | Uses `#cc-explorer-*`, `#cc-rerun-ai`, `#cc-export-*` and `dbvc_cc/v1/explorer/*`, `dbvc_cc/v1/ai/*` |
| Settings storage | `settings/dbvc-cc-settings-service.php` | Option key: `dbvc_cc_settings`, group: `dbvc_cc_options` |

## Target Tab Mapping (Planned)

| Target Tab | Planned Content Source | Required Contracts to Preserve |
|---|---|---|
| `Collect` | Crawl form + crawl-centric options currently in `dbvc-cc-admin-page.php` | Existing AJAX actions, nonce, crawler JS IDs, validated option writes, per-crawl override payload |
| `Explore` | Existing explorer page functionality currently in `dbvc-cc-explorer-page.php` | Existing DOM IDs for explorer JS, existing REST endpoint contracts |
| `Configure` | Subtabs: `General` + `Advanced Collection Controls`; `General` keeps exact order: `Storage Folder`, `Dev Mode`, `OpenAI Model`, `OpenAI API Key`, `Prompt Version` | Option key/group unchanged; each input inside an actual `<section>`; advanced controls define deep/context/scrub defaults consumed by `Collect` |

## Configure Subtab Structure (Phase 3.6 Planned)

1. `General`
- Existing core addon settings and default crawl settings.

2. `Advanced Collection Controls`
- Deep capture controls and limits.
- Context packaging toggles.
- AI section typing threshold controls.
- Media collection defaults:
  - media discovery mode (`metadata-first` vs `selective-download`)
  - media mime allowlist
  - media max bytes per asset
  - media preview thumbnail generation toggle
  - media source domain allow/deny controls
- Attribute scrub policy controls:
  - action selectors for `class`, `id`, `data-*`, `style`, `aria-*`
  - allowlist/denylist inputs
  - AI suggestion toggle and approval status
  - scrub preview sample controls

## Default/Override Data Flow (Planned)

1. `Configure` saves canonical defaults in `dbvc_cc_settings`.
2. `Configure > Advanced Collection Controls` saves deep/context/scrub defaults in `dbvc_cc_settings`.
3. `Collect` pre-fills crawl-centric and advanced controls from those defaults.
4. User may override these values in `Collect` before starting a crawl.
5. `Collect` sends overrides in AJAX payload for that crawl run only.
6. Override values do not mutate `dbvc_cc_settings` unless user explicitly saves `Configure`.

## Known Conflict Hotspots

1. Hook-based script loading currently assumes separate page hooks. A unified parent tab page needs tab-aware enqueue logic.
2. Explorer JS expects explorer DOM IDs to exist; rendering it in a shared page must avoid missing-element runtime errors.
3. Collector view currently mixes settings + crawl controls. Extracting settings must avoid breaking existing form field names.
4. Separate submenu slugs (`dbvc_cc_explorer`) may be referenced directly/bookmarked. We need back-compat redirects or retained entry points.
5. Workbench currently has its own page; not part of requested tabs. Decide explicitly whether to keep as separate submenu for this phase.
6. Settings moved to `Configure` must preserve `register_setting` behavior and nonce/action contract via `options.php`.
7. Per-crawl override values must not accidentally persist as new global defaults.
8. Configure subtab routing (`tab=configure&subtab=...`) must be canonical and bookmark-safe.
9. Advanced scrub controls must not over-scrub context needed by section typing and mapping.

## Recommended Refactor Direction

- Use one canonical page slug (`dbvc_cc`) with query param tab state (`tab=collect|explore|configure`).
- For Configure, add deterministic subtab state (`tab=configure&subtab=general|advanced-collection-controls`).
- Keep legacy explorer slug as compatibility entry and redirect to `dbvc_cc&tab=explore`.
- Keep workbench submenu unchanged in this phase to reduce coupling risk.
- Render tab body as modular partials to avoid one monolith template.

## Phase 3.7 Mapping Bridge (Planned)

1. Add `Map Collection for Imports` CTA in `Explore` node actions and `Workbench` queue rows.
2. Launch mapping workflow surface with steps:
- `Catalog` (auto-build/refresh `dbvc_cc_target_field_catalog`).
- `Section Mapping` (archetype + candidate targets).
- `Field Mapping` (drag/drop override board).
- `Media Mapping` (candidate media inventory + image previews + role/target mapping).
- `Review` (unresolved/conflict summary for text and media).
- `Generate Dry-Run Plan` (Phase 4 handoff).
3. Keep import execution controls disabled in this phase; only dry-run handoff is enabled.
4. Preserve existing explorer/workbench IDs/hooks while adding mapping-specific IDs under `dbvc_cc_*` naming.

## Media Mapping UX Notes (Phase 3.7 Planned)

1. Image candidates must render a preview thumbnail in the mapping board and in auto-mapping review rows.
2. Non-image media candidates render typed cards with key metadata (`kind`, `url`, `mime`, `size` when known).
3. Each media row includes:
- source context (`section`, nearby heading/text)
- deterministic role suggestion
- target field suggestions
- reviewer controls (`approve`, `override`, `ignore`)
4. Auto-mapped media requires explicit reviewer action before dry-run handoff is considered complete.
