# DBVC Agent Reference

Status: Current-checkout source reconciliation and bounded same-checkout registration verification complete; operation-level runtime verification remains scoped per capability.

## Loading Policy

Use this opt-in reference when a task requires DBVC capability discovery, automation planning, CLI or API work, add-on analysis, data-handling decisions, or gap assessment.

Do not treat this folder as required startup context for unrelated DBVC work. Existing implementation guides, handoffs, and user documentation remain the long-form authorities linked by future capability records.

## Authority Layers

1. `generated/discovery-snapshot.json` records mechanically observed source surfaces. Observation does not establish support, safety, or production readiness.
2. `manifest.json` contains reviewed repository capability records with status, risk, evidence, and relationships.
3. Generated Markdown indexes route agents into the manifest by category, tag, surface, operation, and risk.

## Current Boundary

- The generated Current inventory summary below is the canonical count of curated records, enforced discovery surfaces, WP-CLI leaves, REST registrations, and reviewed opportunity dispositions for this checkout.
- Strict coverage is enabled: a new CLI command, REST route, admin surface, setting, extension point, database table, or scheduled hook must be mapped or explicitly ignored.
- A discovered command, route, hook, setting, or service must not be treated as agent-safe merely because it appears in the discovery snapshot.
- Records marked `active` are source-loaded and reviewed for this checkout; they are not proof that every surface is activated or safe to invoke in a particular WordPress runtime.
- A future opportunity candidate must declare a machine-readable boundary and excluded operations before it becomes an implementation queue item.
- Phase 18 makes media resolver `dry_run=true` and existing-bundle lookup side-effect-free. The broader proposal inspection record remains mixed-risk because snapshot, backup-path, identity, and detail readers still have write-capable behavior.
- The manifest is aligned with the active LocalWP plugin source. Same-checkout WP-CLI help, REST registration, add-on gates, and administrator renderer evidence are recorded in [`RUNTIME_VERIFICATION.md`](RUNTIME_VERIFICATION.md); write operations and authenticated browser interaction remain separate evidence layers.
- The original cross-checkout comparison and static QA provenance are retained in [`PHASE6_VERIFICATION.md`](PHASE6_VERIFICATION.md).
- Path-scoped CI now enforces discovery, manifest ownership, and generated-index drift; maintenance remains opt-in and is defined in [`MAINTENANCE.md`](MAINTENANCE.md).

## Status Legend

- `active`: loaded and currently supported in the verified scope.
- `experimental`: callable but not a stable supported contract.
- `planned`: documented future work without a current callable surface.
- `source_reference`: retained for research or migration and deliberately excluded from runtime.
- `deprecated`: retained for compatibility but not recommended for new work.
- `absent_current_checkout`: known elsewhere but absent from this checkout.
- `unknown_requires_verification`: observed but not yet classified.

## Discovery Examples

Common compound lookups include:

- import CLI commands: `operation:import` + `surface:cli`
- safe inspection paths: `operation:inspect` + `risk:read_only`
- Bricks package operations: `scope:addon:bricks` + `object:package`
- client onboarding surfaces: `workflow:client_onboarding`

Use the generated [command signature](generated/index-by-command.md), [opportunity](generated/index-by-opportunity.md), [category](generated/index-by-category.md), [tag](generated/index-by-tag.md), [surface](generated/index-by-surface.md), [operation](generated/index-by-operation.md), [risk](generated/index-by-risk.md), and [alias](generated/index-by-alias.md) views for quick routing. Every generated result links to the canonical manifest, with task facets linked where applicable.

For compound lookup from the repository root:

```bash
composer agent-docs:query -- operation:import surface:cli
composer agent-docs:query -- operation:inspect safety:read_only status:active
composer agent-docs:query -- scope:addon:bricks object:package
composer agent-docs:query -- opportunity:candidate recommended:cli
```

Queries accept exact manifest tags plus `status:`, `category:`, `safety:`, `id:`, `opportunity:`, `priority:`, `effort:`, and `recommended:` filters; unprefixed terms search record IDs and aliases. Use `safety:read_only` when every returned record must be classified read-only, while `risk:read_only` may also find mixed records that contain a read-only sub-operation.

## Task Facets

Load one facet first, then follow its “Load Next” section only as the task requires:

- [CLI and automation](facets/cli-and-automation.md)
- [Core import and export](facets/core-import-export.md)
- [Proposals and media](facets/proposals-and-media.md)
- [Identity, storage, and observability](facets/identity-storage-and-observability.md)
- [Entity Editor](facets/entity-editor.md)
- [Settings, hooks, and extensions](facets/settings-hooks-and-extensions.md)
- [Bricks add-on](facets/bricks-addon.md)
- [Content Migration add-on](facets/content-migration-addon.md)
- [Staged, planned, and absent](facets/staged-planned-and-absent.md)

## Bounded Inspection Recipes

Use [`RECIPES.md`](RECIPES.md) only after selecting a matching manifest record. It contains eight read-only workflows for checkout preflight, bounded proposal structural inspection, media inventory, resolver/snapshot context, Bricks control-plane health, Bricks drift, cached Entity Editor inspection, and bounded Content Migration run inspection. Each recipe has explicit stop rules and record references validated by the existing strict agent-docs check.

## Administrator View

When this manifest ships with the plugin, administrators can review the same curated landscape at `DBVC Export → Docs & Workflows → Capability Landscape` (direct hash: `admin.php?page=dbvc-export#docs-capabilities`). The table is read-only and provides category, status, interface, verification, safety, storage, workflow, CLI-readiness, and reviewed-opportunity filters. Verification details expose the recorded date, evidence types, test references, and notes while distinguishing full live confirmation from scoped runtime evidence, repository tests, and source review; the table does not itself probe or modify the live runtime.

## Existing Long-Form Sources

- `../architecture/README.md` — current architecture router.
- `../implementation/completed/progress-summary.md` — admin application progress summary.
- `../reference/entity-editor-usage.md` — Entity Editor usage.
- `../architecture/media-sync-design.md` — media transport and resolver design.
- `../reference/meta-masking.md` — masking behavior reference.
- `../../addons/visual-editor/docs/README.md` — Visual Editor implementation and QA router.
- `../../addons/content-migration/docs/MIGRATION_MAPPER_V2_DOC_INDEX.md` — Content Migration V2 router.
- `../../addons/bricks/docs/` — Bricks add-on implementation and planning references.
- `../../_source/content-collector/docs/` — Content Collector source-reference material; not runtime authority.

## Generated Capability Summary

<!-- BEGIN GENERATED AGENT INDEX -->
### Current inventory

- **54** curated records cover **417** enforced discovery surfaces; **0** are unmapped.
- Source discovery identifies **26** WP-CLI leaf commands and **136** REST registrations.
- Opportunity dispositions: **0** candidate, **0** needs review, **10** covered elsewhere, **0** deferred, **4** not recommended for further parity, and **40** unreviewed.

### Records by category

| Category | Records |
|---|---:|
| `addon_bricks` | 8 |
| `addon_content_migration` | 4 |
| `api_extensions` | 2 |
| `cli_automation` | 15 |
| `entity_editor` | 3 |
| `identity_entities` | 2 |
| `import_export` | 4 |
| `internal_foundation` | 1 |
| `media_resolver` | 2 |
| `observability` | 2 |
| `proposal_review` | 6 |
| `settings_configuration` | 3 |
| `snapshots_backups` | 2 |

Total curated records: **54**.
<!-- END GENERATED AGENT INDEX -->

## Maintenance Commands

```bash
composer agent-docs:discover
composer agent-docs:build
composer agent-docs:refresh
composer agent-docs:check
composer agent-docs:query -- operation:import surface:cli
```

Use [`MAINTENANCE.md`](MAINTENANCE.md) for change triggers, the capability-impact contract, CI behavior, release checks, and the non-stable-record review cadence.

For remaining QA, shape the evidence to the capability: source-only, CLI/API, write-capable, administrator UI, conditional add-on, and generated-document checks have different minimum evidence. No universal browser, fixture, database, or writer step applies. Batch related cases only when their applicable checkout, owner, authorization, prerequisites, and safety boundary remain shared; then update the owning manifest record once, add one compact `RUNTIME_VERIFICATION.md` result only when runtime was exercised, and run validation proportional to the files or runtime claims that changed. See [`MAINTENANCE.md`](MAINTENANCE.md) for the evidence-selection matrix. Do not create a separate run ledger or duplicate routine evidence across maintenance and implementation documents unless recurring multi-capability matrices make the existing authority ambiguous.

See `../implementation/completed/dbvc-agent-reference-library.md` for phase authority, evidence requirements, completion gates, and the LocalWP merge addendum.
