# DBVC Admin App Progress Summary

## Completed Phases / Tasks
1. **React Admin Shell**
   - Proposal list + entity table connected to REST endpoints.
   - Diff view with conflict defaults, inline Accept/Keep radios, bulk Accept/Keep per visible section, section navigator.
2. **Media Resolver Integration**
   - REST surface for resolver metrics, conflicts, attachment rows.
   - Per-resolver decision controls (reuse/download/map/skip), notes, and “remember for future proposals” toggle.
   - Resolver decisions persisted per proposal and optionally as global rules (`dbvc_resolver_decisions`).
   - Apply pipeline honors field selections and resolver decisions (reuse/map, skip, force download).
3. **Notifications & History**
   - Toast stack + recent apply history, including resolver decision counts and remaining conflicts.
4. **Auto-clear & Hash Override**
   - Admin setting to clear decisions post-apply; apply modal includes partial-mode override for legacy manifests.
5. **Global Resolver Rule Management**
   - REST endpoints for listing/deleting rules, plus bulk-delete and CSV export UI.
   - Resolver decisions included in manifests so exports/imports carry reviewer intent.
6. **Entity Detail UX**
   - Entity diff lives inside a modal/drawer with overlay, focus trapping, and keyboard/overlay dismissal.
   - Resolver attachments, filters, and bulk accept/keep controls fully compatible with the drawer.
7. **Bulk Resolver Enhancements**
   - Global rules panel now supports add/edit flows plus CSV import/export (with validation feedback).
   - Resolver attachments include an advanced bulk-apply tool that can target conflicts by reason, asset UID, or manifest path.
   - Batch operations respect “remember for future proposals” so global rules can be seeded rapidly.
8. **WP PHPUnit Scaffold**
   - Added `bin/install-wp-tests.sh`, updated bootstrap wiring, and seeded REST tests for apply/resolver rule endpoints.
   - Documented how to install/run the suite so backend changes ship with automated coverage.
9. **Performance & UX Polish**
   - Entity list table virtualizes rows automatically for large proposals and caps viewport height for smoother scrolling.
   - Resolver attachments and global rule panels now include search inputs plus filtered empty states for quick triage.
   - Attachments search feeds the new bulk controls, keeping workflows responsive even with hundreds of conflicts.
   - Resolver rule form remembers the last target ID and surfaces inline duplicate warnings before you hit Save.
   - Configure → Import Defaults now has “Require DBVC Proposal review,” which hides the legacy Run Import form and forces the React workflow.
10. **Duplicate + New-Entity Enforcement**
    - Backend surface for `/duplicates` + `/duplicates/cleanup`, manifest rewrite, and modal flow shipped (blocking overlays + canonical keep selection).
    - Proposal load now queries duplicate count + report, shows flashing overlay, and prevents entity review until all duplicates are resolved.
    - Added explicit “New post” detection pipeline (UID/ID/slug heuristics, DBVC entity registry) with UI badges, forced filter, and accept/decline gating that the importer honours.
    - Cleanup API rewrites manifest + deletes stray JSONs so reviewers always see a canonical source of truth.
## Remaining / Next Steps
1. **Term & Taxonomy Entity Parity**
   - Promote the plan in `docs/terms.md` into engineering tasks: emit term entities in `entities.jsonl`, surface them in the React grid/drawer, and gate applies on Accept/Keep just like posts.
   - Extend importer/preflight helpers (`DBVC_Sync_Taxonomies`, proposal apply endpoint) to respect reviewer selections, parent remaps, and media references coming from termmeta.
   - Refresh docs/help text so reviewers know the taxonomy filters and “Accept all new terms” affordances that will ship with the feature.
2. **Testing & Automation**
   - Expand coverage (resolver bulk actions, CSV parsing, importer hooks, duplicate cleanup) now that the PHPUnit scaffold exists.
   - Integrate the suite with CI once infrastructure is available so regressions (like the new-entity gating bug) are caught automatically.
3. **Performance / UX Polish**
   - Extend virtualization/search patterns to resolver attachments + global rule drawers as telemetry demands.
   - Profile apply drawer rendering when thousands of diff sections are present and consider chunked rendering or skeleton states.
4. **Media Preview Iteration**
   - Finalize manifest/local preview URLs so thumbnails render consistently across environments or fall back gracefully when sync paths differ.
   - Decide whether large assets should lazy-load to avoid blocking entity review.
5. **Documentation & CLI**
   - Extend CLI commands to manage resolver rules (list/add/delete) and eventually trigger proposal applies once parity work lands.
   - Keep README/handoff updated as new workflows (taxonomy entities, official collections) become available.
