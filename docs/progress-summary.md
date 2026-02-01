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
11. **Term Snapshot & Diff Parity**
    - Snapshot manager now captures taxonomy entities (UIDs, parent chains, sanitized termmeta) alongside posts.
    - React diffs and Accept/Keep gating compare term snapshots against proposal payloads, so term decisions behave exactly like post decisions when reopening proposals.
12. **WP-CLI Proposals Namespace**
    - Added `wp dbvc proposals list|upload|apply` commands that reuse the React workflow’s ingestion/apply helpers so CI/staging can manage proposals headlessly.
13. **CLI Parity for Resolver Rules & Duplicates**
    - Added `wp dbvc resolver-rules list|add|delete|import` plus `wp dbvc proposals list --cleanup-duplicates` so automation can manage global resolver rules and manifest cleanup without the React UI.
14. **Meta Masking Drawer & REST**
    - Added `/masking` + `/masking/apply` endpoints plus option stores for per-proposal suppressions/overrides and importer hooks that honor those directives.
    - React admin now includes status badges, a Tools drawer housing masking controls, and inline tooltips linked to `docs/meta-masking.md`. Remember to run `npm run build` whenever `src/admin-app/` changes.
## Remaining / Next Steps
1. **Term & Taxonomy Entity Polish**
   - QA drawer UX, filters, and resolver badges now that real term snapshots feed the diff engine; optimize any slow comparisons discovered with large vocabularies.
   - Refresh docs/help text so reviewers know the taxonomy filters, parent resolution behaviour, and “Accept all new terms” affordances that now ship with the feature.
   - Backfill existing proposals by rerunning `DBVC_Snapshot_Manager::capture_for_proposal()` (or `wp dbvc proposals list --recapture-snapshots`) so every reopen flow benefits from the new term snapshots.
   - 🗂️ `docs/term-entity-polish.md` now tracks the QA/backfill checklist so each environment can confirm parity before rollout.
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
   - Keep README/handoff updated as new workflows (taxonomy entities, official collections) become available.
6. **Meta Field Masking Workflow**
   - ✅ Ship an “Apply masking rules” button above the All Entities table that auto-applies configured post/term meta masking directives (Tools panel, batching, undo).
   - ✅ Allow reviewers to pick ignore, auto-accept & suppress, or override behaviors via a bulk selector, with override inputs and help tooltips pointing into `docs/meta-masking.md`.
   - ✅ Ensure the action runs against live proposals so posts/terms/media flagged as Needs Review or Unresolved meta are relabeled once matching masked fields are processed, keeping entity badges and counts accurate after auto-masking.
   - ✅ Leave existing export-time masking logic untouched so deployments relying on masked exports keep their current behavior.
   - ✅ Surface the new behaviors through tooltips anchored to the bulk action + help text in docs, plus a progress indicator while masking loads/applies.
   - ✅ Tightened backend pagination (10-field default with a guarded `per_page` param) so each `/masking` fetch stays within memory budgets exposed by telemetry.
   - ✅ Added PHPUnit coverage for `/masking` pagination + apply/undo flows (`tests/phpunit/MaskingEndpointsTest.php`) to lock in the behaviours above.
   - ✅ Tools panel now ships with a “Revert masking decisions” control backed by `/masking/revert`, clearing stored suppressions/overrides so proposals can be re-reviewed after rule changes.
7. **Admin App Refactor**
   - The compiled UI currently lives in `src/admin-app/index.js` as a single ~3,300 line bundle, which makes day-to-day edits nearly impossible.
   - Before touching the bundle, capture a backup copy (tagged commit + `build/` artifact) and document the baseline so a reliable reference exists during the refactor; stage work in a separate branch or staging file to keep master stable.
   - Recover or recreate the original modular React source (components, hooks, api helpers) and treat the generated bundle as a build artifact under `build/`.
   - Break the work into smaller steps: first extract shared utilities/API calls, then UI primitives, then feature panels (diff table, masking drawer, resolver screens) so each PR stays reviewable and easy to roll back.
   - Update build/docs to clarify the source-of-truth paths so future contributors can work in smaller files and keep reviews manageable.
   - 📘 `docs/admin-app-refactor-plan.md` captures the staged architecture (data layer, hooks, components) so contributors can chip away at the refactor without editing the compiled bundle directly.
