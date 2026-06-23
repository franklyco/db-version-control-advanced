# WordPress Class and File Map (Source Plugin)

This file maps classes/files that exist in the current source plugin codebase.

For DBVC addon implementation sequencing and rules, use:

- `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/CONTENT_COLLECTOR_ADDON_MANIFEST.json`
- `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/PHASE_PLAN.md`
- `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/GUARDRAILS.md`

## Bootstrap
- `content-collector.php`
  - Defines plugin constants.
  - Includes all source classes.
  - Boots singleton runtime.

## Runtime Classes (Existing)
- `includes/class-cc-settings.php`
  - Settings registration, defaults, sanitization.
- `includes/class-cc-admin.php`
  - Admin menu/pages and asset enqueue.
- `includes/class-cc-ajax.php`
  - Sitemap URL fetch and single-page crawl AJAX actions.
- `includes/class-cc-crawler.php`
  - Crawl fetch/extract logic, section grouping, PII flags.
- `includes/class-cc-artifact-manager.php`
  - Deterministic paths, index/redirect/log artifacts, dev mode copies.
- `includes/class-cc-explorer-service.php`
  - Explorer tree, node, content preview, and node audit payloads.
- `includes/class-cc-rest-explorer.php`
  - Explorer REST route registration.
- `includes/class-cc-ai-service.php`
  - AI queue/process/status with deterministic fallback mode.
- `includes/class-cc-rest-ai.php`
  - AI REST route registration.
- `includes/class-cc-export-service.php`
  - Export bundle and manifest generation.
- `includes/class-cc-rest-export.php`
  - Export REST route registration.
- `includes/functions.php`
  - Shared helper functions.

## Admin Assets (Existing)
- `admin/views/main-page.php`
- `admin/views/explorer-page.php`
- `admin/js/cc-admin-script.js`
- `admin/js/cc-explorer.js`
- `admin/css/cc-admin-styles.css`
- `admin/css/cc-explorer.css`

## Notes
- Historical phase-planning docs from standalone work are in `docs/ARCHIVE/`.
- This file intentionally lists only classes/files that currently exist in this source plugin.
