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
## Remaining / Next Steps
1. **Testing & Automation**
   - Expand coverage (resolver bulk actions, CSV parsing edge cases, importer hooks) now that the scaffold exists.
   - Integrate the suite with CI once available.
2. **Performance / UX polish**
   - Extend virtualization/search patterns to additional lists (e.g., resolver attachments drawer) as telemetry demands.
   - Profile apply drawer rendering when thousands of diff sections are present and consider chunked rendering.
3. **Media Preview Iteration**
   - Finalize manifest/local preview URLs so thumbnails render consistently across environments or fall back gracefully when sync paths differ.
3. **Documentation & CLI**
   - Extend CLI commands to manage resolver rules (list, add, delete) for scripted environments.
   - Update handoff to describe modal UX once implemented.
