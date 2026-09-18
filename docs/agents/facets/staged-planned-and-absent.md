# DBVC Staged, Planned, And Absent Facet

Load this facet for retained Content Collector source references, Official Collections scaffolding, canonical authority, universal intake, granular option controls, or any capability whose status is not active. Its purpose is to prevent source presence or roadmap language from being mistaken for active capability.

## Non-Active Boundaries

| Record | Status boundary |
|---|---|
| `source.content_collector.explorer` | Source reference; explorer/admin/AJAX routes are not runtime-loaded |
| `source.content_collector.ai` | Source reference; no callable AI provider workflow is established |
| `source.content_collector.export` | Source reference; export-job routes are not runtime-loaded |
| `storage.core.official_collections` | Experimental persistence scaffold; no public workflow |
| `planned.core.universal_upload_intake` | Planned only |
| `planned.core.canonical_authority` | Planned only |
| `planned.core.granular_options` | Planned only |
The Visual Editor and Content Migration add-ons are active source-loaded capability families in this checkout. Use their current facets; do not use the retained source-reference records as substitutes for their runtime contracts.

## Promotion Test

Before treating any item here as usable:

1. Locate an implementation in the target checkout.
2. Confirm the plugin bootstrap loads it.
3. Confirm live command/route/admin registration where operational use is intended.
4. Review authentication, safety, storage, idempotency, backup, and rollback.
5. Add or update tests and long-form authority docs.
6. Update the manifest status and discovery mappings through human review.

Do not promote a source-reference or planned record merely because its PHP file, route registration, table, or roadmap exists.

## Gap Questions

- Is this feature absent, source-only, scaffolded, planned, or active in another checkout?
- What exact runtime loader or registration evidence is missing?
- Does a current core/Bricks capability already satisfy part of the request?
- Would implementing it duplicate an existing import, proposal, package, or Entity Editor path?
- Does the target checkout have a newer implementation that needs its own discovery baseline?

## Load Next

- Current classification evidence: [`docs/agents/DISCOVERY_REVIEW.md`](../DISCOVERY_REVIEW.md)
- Roadmap items: [`docs/roadmap.md`](../../roadmap.md)
- Retained Content Collector docs: [`_source/content-collector/docs/`](../../../_source/content-collector/docs/)
- Official Collections helper: [`includes/Dbvc/Official/Collections.php`](../../../includes/Dbvc/Official/Collections.php)
- Active Content Migration facet: [`content-migration-addon.md`](content-migration-addon.md)
- If the target is another checkout, rerun discovery there rather than merging its claims into this manifest.
