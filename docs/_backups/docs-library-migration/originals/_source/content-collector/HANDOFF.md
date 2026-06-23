# Project Handoff (Source Plugin Reference)

## Status
This plugin is now primarily a source reference for DBVC addon migration work.

- Standalone legacy compatibility is not required.
- Multisite support is out of scope.
- WP-CLI support is out of scope.

## Canonical Docs for Active Work
Use these first when implementing in the DBVC plugin codebase:

1. `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/CONTENT_COLLECTOR_ADDON_MANIFEST.json`
2. `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/PHASE_PLAN.md`
3. `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/GUARDRAILS.md`
4. `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/HANDOFF.md`
5. `docs/CONTENT_COLLECTOR_ADDON_PLAYBOOK/KICKOFF_PROMPT.md`

## Source Plugin Capability Snapshot
Current source plugin behavior includes:

- Deterministic crawl storage and provenance artifacts.
- Explorer visualization (Cytoscape) with node inspector, search, diff, and node audit.
- AI rerun pipeline with fallback mode and status polling.
- Structured export bundles (`json`, `yaml`, `md`) with manifest, redirects, and logs.

## Supporting Specs (Still Useful as Source Contracts)
- `docs/CONTENT_SECTION_SCHEMA.md`
- `docs/EXPLORER_API_SCHEMA.md`
- `docs/EXPORT_MANIFEST_SCHEMA.md`
- `docs/WORDPRESS_CLASS_FILE_MAP.md`
- `tests/fixtures/README.md`

## Archived Historical Planning
Older standalone-phase planning docs were moved to:

- `docs/ARCHIVE/`
