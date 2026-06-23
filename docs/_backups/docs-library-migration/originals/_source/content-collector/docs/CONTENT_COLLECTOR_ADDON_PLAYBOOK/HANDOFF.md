# Handoff: Content Collector -> DBVC Addon Transition

## Objective
Absorb Content Collector into DBVC as an addon/extension.

- Source plugin support: not required.
- Legacy compatibility wrappers: not required.
- Internal/testing use only: yes.

## Mandatory First Steps (Before Any Implementation)
1. Place source in a non-runtime reference folder inside DBVC (recommended: `./_source/content-collector`) or keep at `./content-collector` but do not bootstrap it in runtime.
2. Remove nested VCS metadata from dropped source:
   - `rm -rf ./content-collector/.git`
   - Remove `.github/` in the dropped source if not needed.
3. Remove local-only noise from dropped source:
   - `rm -rf ./content-collector/dev-data`
   - `find ./content-collector -name '.DS_Store' -delete`
4. Keep playbook + fixtures as primary reference and treat archived docs as historical context only.
5. Add a DBVC guard check that fails if runtime code imports/requires from the source reference folder.
6. Add ignore rules for runtime artifacts (`uploads/contentcollector*`, export work dirs, zip outputs).
7. Create a baseline commit in DBVC containing only source drop + docs/guard scripts before implementation.
8. Begin implementation at Phase 0 with addon module skeleton and contracts, not direct source runtime wiring.

## Drop-In Assumption
When working in DBVC project root, the Content Collector source is available at:

- `./content-collector`

## Primary Playbook Files
1. `./content-collector/docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/CONTENT_COLLECTOR_ADDON_MANIFEST.json`
2. `./content-collector/docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/KICKOFF_PROMPT.md`
3. `./content-collector/docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/PHASE_PLAN.md`
4. `./content-collector/docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/GUARDRAILS.md`
5. `./content-collector/docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/HANDOFF.md`

## Required Source Paths to Read First
- `./content-collector/content-collector.php`
- `./content-collector/includes/class-cc-settings.php`
- `./content-collector/includes/class-cc-artifact-manager.php`
- `./content-collector/includes/class-cc-crawler.php`
- `./content-collector/includes/class-cc-explorer-service.php`
- `./content-collector/includes/class-cc-rest-explorer.php`
- `./content-collector/includes/class-cc-ai-service.php`
- `./content-collector/includes/class-cc-rest-ai.php`
- `./content-collector/includes/class-cc-export-service.php`
- `./content-collector/includes/class-cc-rest-export.php`
- `./content-collector/includes/class-cc-ajax.php`
- `./content-collector/includes/class-cc-admin.php`
- `./content-collector/admin/views/main-page.php`
- `./content-collector/admin/views/explorer-page.php`
- `./content-collector/admin/js/cc-admin-script.js`
- `./content-collector/admin/js/cc-explorer.js`

## Gameplan for Codex Inside DBVC
1. Read the addon manifest JSON and generate source->target migration map for DBVC files.
2. Enforce guardrails from `GUARDRAILS.md` for every implementation slice.
3. Execute phase order from `PHASE_PLAN.md`.
4. Wire settings and storage foundation first (option keys, storage path, directory hardening, index/redirect/log files).
5. Port crawler and AJAX flow (sitemap fetch + per-url crawl processing).
6. Port Explorer service/routes/UI (tree, node detail, content preview, search/diff/audit modules).
7. Port AI pipeline/routes (queue, branch queue, status polling, fallback mode).
8. Port export pipeline/routes (zip creation, manifest, redirects/logs inclusion).
9. Run fixture parity checks from `./content-collector/tests/fixtures`.
10. Remove dependence on the drop-in folder once DBVC-native module is complete.

## Behavior Contracts That Must Stay Intact
- Deterministic artifact storage under uploads path.
- Provenance and compliance fields on artifacts.
- Idempotent domain index and redirect map updates.
- AI fallback mode with deterministic output path.
- Explorer route payload shapes (including comparison and node audit blocks).
- Export manifest schema and status flags.

## Data Stores to Preserve
- WordPress option: `content_collector_settings`.
- WordPress transients with prefixes:
  - `cc_ai_job_`
  - `cc_ai_batch_`
  - `cc_export_job_`
  - `cc_tree_`
- Filesystem:
  - `uploads/contentcollector/*`
  - `uploads/contentcollector-exports/*`

## Out of Scope
- Backward-compatible standalone plugin bootstrap.
- Legacy URL/slug migration support beyond current deterministic contracts.
- Multisite and WP-CLI support.

## Completion Criteria
- DBVC addon can run crawl -> explorer -> AI -> export end-to-end.
- REST and AJAX contracts match source behavior where intentionally preserved.
- Fixture outputs match expected baseline contracts.
