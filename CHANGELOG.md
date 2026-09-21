# CHANGELOG

## Unreleased
- Connected Environments page, slice A4: hub Framework section — Status (framework-status report with drift/version badges, Adopt desired, Approve override with rationale, Detach override, subscribe-an-object form), Definitions (grouped versions, Set desired, publish form from hash / environment object / copied version) and Reviews (state chips, Classify now, Resolve with note).
- Connected Environments page, slice A3: hub Compare section (same-client pair picker, domain filter, freshness/coverage banner, state chips as filters, hash-prefix rows with Confirm baseline / Accept absent / Link instance… actions) and an object drawer (identity, both sides' projections, baselines, recent events); `wp dbvc agency events|projections|baselines` accept `--instance=<uid>`.
- Connected Environments page, slice A2: hub Environments section (registry table with freshness, Hold with an operator note, Release hold, Revoke with inline confirmation), invitation form whose token is shown once in a modal with the enroll command, and an invitations list; new `wp dbvc agency hold --environment [--reason]` and `wp dbvc agency invitations [--open]` plus the matching `agency/hold` and `agency/invitations` admin routes.
- Added a dedicated `DBVC Export → Connected Environments` admin page (slice A1), present only while the connector or hub gate is ready: role-adaptive overview (hub stat cards and attention list; connector connection, coverage and pending-work cards with runner buttons), a Settings section (gates, apply-gate confirmation, enrollment and resume) and a "Run now" menu, backed by cookie + nonce `manage_options` admin REST routes under `dbvc/v1` (`connected-admin/{overview,settings}`, `connected/*`, `agency/*`) that wrap the WP-CLI inspectors and answer `no-store`.
- Added the Connected Environments connector add-on (off by default): save-side dirty markers for Bricks global classes and variables, a generation-aware leased background worker, versioned canonical snapshots, a durable local outbox of `agency-control.observation.v0.2` events, an instance identity sidecar, and `wp dbvc connected status|jobs|objects|outbox|inventory|reconcile|process`.
- Added the Agency Control hub add-on (off by default): enrollment invitations, one capability-less service user + WordPress application password per enrolled environment, `dbvc-agency/v1` enrollment exchange / observation receipt / capabilities routes, sequence-guarded projections, site-URL holds, and `wp dbvc agency status|environments|events|projections|invite|revoke|release`.
- The connector can enroll with a hub (`wp dbvc connected enroll`), stores the credential sealed with the installation salts, delivers outbox batches outbound-only with per-event settlement (`wp dbvc connected deliver|resume`), and polls its hub inbox storing deliveries before acknowledging (`wp dbvc connected poll|inbox`); received observations are never applied.
- Hub routing: explicit client/framework subscriptions, a pure routing policy that ignores sender-supplied recipients, per-target deliveries with frozen policy revisions, framework review items, and `GET /inbox` + `POST /inbox/ack` (`wp dbvc agency subscribe|subscribe-framework|unsubscribe|enable-subscription|subscriptions|route|deliveries|reviews`).
- Hub comparison: pair/object baselines advanced only by explicit `wp dbvc agency baseline-confirm`, operator instance links, and a read-only three-way `wp dbvc agency compare` (synchronized/outgoing/incoming/converged/conflict/baseline_required/unknown with reasons).
- Hub framework review: immutable framework definition versions with studio-supplied ordering and per-channel desired versions (`wp dbvc agency definition-publish|definition-desire|definitions`), explicit adoption on framework subscriptions (`subscribe-framework --adopted-version --channel`, `adopt-version`), approved overrides with the exact hash, frozen policy revision and rationale (`override-approve|override-detach|overrides`; `needs_rebase_review` when the desired version changes), and the read-only `framework-status` report (clean/local_drift/approved_override/override_changed and current/behind_version/ahead_version/channel_mismatch, unknown when stale or unobserved).
- One guarded round trip (M5 step 1): the hub can approve a ready prepare receipt (`wp dbvc agency approve|revoke-approval|approvals`), and a connector whose new apply gate is on (`dbvc_addon_connected_environments_apply_enabled`, off by default) executes it on its next poll: Bricks global classes/variables only, one conditional SQL write per option container guarded by the receipt's storage fingerprint, journalled before image (`wp dbvc connected operations`), after-state verified through the read-only observer, compensation on failed verification, `stale` instead of overwrite when the container changed, the change reported with `origin=apply`, and the pair baseline advanced for verified objects. Generated Bricks CSS is not rebuilt.
- Release preparation (M4 step 2): prepare receipts carry a dependency ledger per object (Bricks categories and `var(--name)` references, service parent/terms/media as present/selected/unresolved/unsupported; unresolved or unsupported entries block), releases can carry deletions of verified absences (`wp dbvc agency release-create --delete=…`, prepared as `remove_object` or no-op), and both Add-ons panels list releases and receipts.
- Release preparation (M4 step 1): the hub fixes immutable release manifests from a source environment's current projections (`wp dbvc agency release-create|releases|release-withdraw`), the source connector supplies hash-verified canonical payloads on its own outbound poll (`wp dbvc connected release`), sealed releases can be dry-run on a target (`wp dbvc agency prepare-request|preparations`; `wp dbvc connected preparations`) producing an expiring receipt with an exact whole-object patch or an explicit blocker per object — nothing is written on any environment.
- Hub review resolution and administrator panels: review items are classified through the framework status report (`wp dbvc agency review-classify`; superseded, clean and approved-and-current items resolve automatically, `review-resolve --note` for the rest); DBVC → Configure → Add-ons → Connected Environments shows the hub's environments, an invitation form (token shown once), open invitations, framework status and open review items, and the connector's received-observations inbox. The `wp.service` domain now reports an inventory projection (`wp-service-inventory-v1`) so the hub can verify absence for service posts.
- New `wp.service` observation domain: service posts identified by DBVC's `vf_object_uid` with meta projected through DBVC's masking rules (masked fields mark the projection incomplete).
- Added the shared `Dbvc\ConnectedProtocol` contract layer (canonicalizer, observation event validator, role gate, comparison classification).
- Added the `dbvc_import_options_data` filter so operational state can be excluded from `options.json` import; connector/hub gate and schema keys are excluded from options export and import.
- Agent-docs hook discovery IDs now hash by occurrence instead of byte offset (adopted from the Visual Editor branch); manifest IDs were remapped mechanically.
- Added a "View All" mode in the entity drawer to list every meta field (including unchanged values).
- Added a "UID mismatch" filter badge for entities whose local `vf_object_uid` differs from the proposal.
- Added an entities totals summary (proposed/current breakdown with posts/terms/media plus filtered count).
- Added an admin app error boundary with client-side logging to DBVC’s core log + activity table.
- Added a REST endpoint (`/logs/client`) for capturing React render crashes and surfacing them in logs.
- Added a temp file fallback to avoid `wp_tempnam()` fatals when WordPress helpers are unavailable.

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
- Enabled “Require DBVC proposal review” to disable the core Run Import form when teams want to enforce the new workflow.

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
