# Phase 2 Collector + Explorer Status

## Implemented Components
- Collector service: `addons/content-migration/collector/dbvc-cc-crawler-service.php`
- Collector AJAX: `addons/content-migration/collector/dbvc-cc-ajax-controller.php`
- Collector admin wiring: `addons/content-migration/collector/dbvc-cc-admin-controller.php`
- Explorer service: `addons/content-migration/explorer/dbvc-cc-explorer-service.php`
- Explorer REST: `addons/content-migration/explorer/dbvc-cc-rest-controller.php`
- Explorer UI assets/views:
  - `addons/content-migration/explorer/assets/dbvc-cc-explorer.js`
  - `addons/content-migration/explorer/assets/dbvc-cc-explorer.css`
  - `addons/content-migration/explorer/views/dbvc-cc-explorer-page.php`

## AJAX Contract (Collector)
- Action: `dbvc_cc_get_urls_from_sitemap`
- Action: `dbvc_cc_process_single_url`
- Nonce action: `dbvc_cc_ajax_nonce`

## REST Contract (Explorer)
- Namespace: `dbvc_cc/v1`
- `GET /explorer/domains`
- `GET /explorer/tree`
- `GET /explorer/node/children`
- `GET /explorer/node`
- `GET /explorer/content`
- `GET /explorer/node/audit`

## Feature Flags Applied
- Collector runtime gated by: `dbvc_cc_flag_collector`
- Explorer runtime gated by: `dbvc_cc_flag_explorer`
- AI and export controls in Explorer UI are disabled unless their phase flags are enabled.

## Notes
- This phase does not implement AI routes or export routes.
- Explorer UI intentionally prevents AI/export actions when those modules are disabled.
- Explorer currently renders on a separate submenu page; planned consolidation into parent addon tabs is tracked in `PHASE3_5_TABBED_ADMIN_CONSOLIDATION.md`.
