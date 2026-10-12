# DBVC Agent Entry Points

Use this file to choose a narrow documentation path.

## AI Agent Site Content Or Import Package Authoring

Read:

1. `docs/reference/import-authoring/README.md`
2. `docs/reference/import-authoring/ai-agent-quickstart.md`
3. `docs/reference/import-authoring/package-layout.md`
4. `docs/reference/import-authoring/entity-shapes.md`
5. `docs/reference/import-identity-matching.md` when updating existing content

When ACF or Vertical context is involved, add:

1. `docs/reference/import-authoring/acf-authoring.md`
2. `docs/reference/import-authoring/vertical-context.md`

## Core DBVC Import, Proposal, or Media Work

Read:

1. `docs/architecture/README.md`
2. `docs/reference/import-identity-matching.md`
3. `docs/reference/meta-masking.md` when masking is relevant
4. `docs/development/README.md` for build and QA commands

## Visual Editor Add-on

Read:

1. `addons/visual-editor/AGENTS.md`
2. `addons/visual-editor/README.md`
3. `addons/visual-editor/docs/README.md`

Use the addon-local docs as the source of truth for Visual Editor implementation work.

## Content Migration Add-on

Read:

1. `addons/content-migration/README.md`
2. `addons/content-migration/docs/MIGRATION_MAPPER_V2_DOC_INDEX.md`
3. `addons/content-migration/docs/MIGRATION_MAPPER_V2_WORKING_STATE.md`

## Bricks Add-on or Bricks Portability

Read:

1. `addons/bricks/docs/BRICKS_ADDON_PLAN.md`
2. `addons/bricks/docs/BRICKS_ADDON_PROGRESS_TRACKER.md`
3. `docs/implementation/proposed/bricks-portability-drift-manager/README.md` only for the proposed drift-manager design

## Agency Control / Connected Environments (Proposed)

Read:

1. `docs/implementation/proposed/agency-control-connected-environments/README.md`
2. `docs/implementation/proposed/agency-control-connected-environments/m0-discovery.md`
3. `docs/implementation/proposed/agency-control-connected-environments/reconciliation.md`
4. `addons/bricks/docs/BRICKS_ADDON_PLAN.md` and `addons/bricks/docs/BRICKS_ADDON_PROGRESS_TRACKER.md`
5. `docs/implementation/proposed/bricks-portability-drift-manager/README.md` and `docs/implementation/proposed/cross-site-entity-packet-guide.md`

Treat this as a documentation-only proposal until M0 reconciles the active checkout and runtime. Do not create an add-on, enroll sites, create credentials, schedule workers, send network traffic, or apply content from this route alone.

## Admin App or Entity Editor UI Work

Read:

1. `docs/implementation/active/admin-app-refactor.md`
2. `docs/architecture/admin-app-ui-architecture.md`
3. `docs/reference/entity-editor-usage.md` when Entity Editor behavior is involved

## Capability Inventory, CLI/API Gap Analysis, or Automation Planning

Read:

1. `docs/agents/README.md`
2. The smallest matching facet under `docs/agents/facets/`
3. `docs/agents/manifest.json` or `composer agent-docs:query -- <tags>` for exact records and source references

This library is opt-in task context. Do not load the full manifest for unrelated implementation work.

## Connected Environments Connector or Agency Control Hub

Read:

1. `docs/implementation/active/connected-environments-agency-control.md`
2. `docs/dropins/dbvc-connected-agency/START-HERE.md` and `IMPLEMENTATION-GUIDE.md` (design baseline; `.php.stub` templates are inert)
3. `docs/dropins/dbvc-connected-agency/tracking/SESSION-HANDOFF.md` for the compact continuation state

Never load code from `docs/dropins`; runtime owners are `addons/connected-environments/`, `addons/agency-control/`, and `includes/Dbvc/ConnectedProtocol/`. The earlier docs-only proposal `docs/implementation/proposed/agency-control-connected-environments/` (separate hub repository, source package dated 2026-09-13) is superseded by the in-repository architecture above where the two differ; keep it as historical context only.

## Documentation Maintenance

Read:

1. `docs/_meta/doc-governance.md`
2. `docs/_meta/inventory.md`
3. `docs/requests.md`

## If Docs Conflict

Prefer current entry points and code over archived docs. If the conflict cannot be resolved quickly, add it to `docs/requests.md` instead of guessing.
