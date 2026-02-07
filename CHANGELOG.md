# CHANGELOG

## Unreleased
- Added a "View All" mode in the entity drawer to list every meta field (including unchanged values).
- Added a "UID mismatch" filter badge for entities whose local `vf_object_uid` differs from the proposal.
- Added an entities totals summary (proposed/current breakdown with posts/terms/media plus filtered count).

## 1.3.4
- Fixed the new-entity gating regression that caused `ReferenceError: Cannot access '…' before initialization` when the admin app loaded.
- Restored pending-new-entity filtering so the bulk Accept tools and drawer hints stay in sync with proposal metadata.
- Hardened duplicate cleanup + resolver refresh requests to avoid leaving empty proposal shells on disk.
- Updated documentation to reflect the proposal-first workflow and the React admin requirements.
- Added full term snapshot capture/diff parity so taxonomy entities behave exactly like posts in reopened proposals; re-upload older proposal zips (or run `DBVC_Snapshot_Manager::capture_for_proposal()`) to backfill term snapshots.
- Introduced `wp dbvc proposals list|upload|apply` so CI/staging workflows can inspect, ingest, and apply reviewed bundles without visiting WP Admin.

## 1.3.0
- Introduced the React-based proposal reviewer: proposal list, entity grid with virtualization/search, Accept/Keep drawer, toast notifications, and apply history.
- Added duplicate overlays, canonical-entry cleanup APIs, and the new-entity acceptance gate so reviewers explicitly approve inserts before apply.
- Expanded the media resolver UI with attachment previews, conflict filters, bulk actions, remember-globally toggles, and CSV-backed rule management.
- Added REST endpoints for proposals, selections, bulk accept/unaccept actions, resolver inspection, apply executions, and maintenance helpers.
- Enabled “Require DBVC proposal review” to disable the legacy Run Import form when teams want to enforce the new workflow.

## 1.2.0
- Landed the identity layer (`vf_object_uid`, `vf_asset_uid`, `vf_file_hash`) plus registry tables that keep site A/B entities aligned.
- Manifest schema bumped to v3 with resolver decisions, media bundle metadata, and entity snapshots for diff-aware imports.
- Added deterministic media bundles, resolver services (`Dbvc\Media\Resolver`, `BundleManager`, `Logger`), and snapshot/job/activity tables for observability.
- Extended WP-CLI export with chunking, diff baselines, and automatic menu/option sync; import gained smart batching and option/menu bootstrap.

## 1.1.0

- **Added**: Full Site Editing (FSE) integration with theme data export/import functionality in `includes/class-sync-posts.php`
- **Added**: Safe FSE hook registration system to prevent WordPress admin conflicts in `includes/hooks.php`
- **Added**: Comprehensive error handling and safety checks for theme JSON operations in `includes/class-sync-posts.php`
- **Added**: Security validation functions for file paths and JSON data sanitization in `includes/functions.php`
- **Added**: FSE options to the select field in the admin settings in `admin/admin-page.php`
- **Updated**: Text strings for localization in `languages/dbvc.pot`

## 1.0.0

- Initial release
