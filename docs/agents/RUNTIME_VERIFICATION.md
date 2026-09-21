# DBVC Agent Reference Runtime Verification

Verification date: 2026-08-10  
Status: Partial same-checkout runtime verification complete; write operations and authenticated browser interaction were not invoked.

## Provenance

- Active WordPress site: `https://dbvc-codexchanges.local`
- Active plugin checkout: `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`
- Repository HEAD: `78a06ad6429574bc1ac88158ec7c037b257dd3a6`
- Active plugin: `db-version-control-main`, reported version `1.9.1`
- WordPress version: `7.0.3`
- Checkout state: dirty; unrelated user-owned changes were not modified or treated as verification evidence.

All runtime checks in this report loaded the checkout above. They were limited to command help/registration, REST registration, explicit non-secret option values, loaded class presence, manifest loading, permission evaluation, and HTML rendering. No import, export, proposal apply, hydration apply, package operation, or other mutating callback was invoked.

## WP-CLI Registration

Live `wp help dbvc` inspection found 13 callable leaf commands:

| Namespace | Callable leaves |
|---|---|
| `wp dbvc` | `export`, `import`, `snapshots` |
| `wp dbvc proposals` | `apply`, `list`, `upload` |
| `wp dbvc resolver-rules` | `add`, `delete`, `import`, `list_` |
| `wp dbvc media` | `hydrate`, `inventory`, `mirror_export` |

Two current naming gaps were confirmed:

- `wp dbvc resolver-rules list` is not registered; `wp dbvc resolver-rules list_` is callable.
- `wp dbvc media mirror-export` is not registered; `wp dbvc media mirror_export` is callable.

The discovery generator now honors an explicit `@subcommand` annotation and otherwise preserves the registered method name. The manifest and generated command index therefore represent callable commands rather than normalized guesses. This does not add compatibility aliases; those remain separate runtime enhancement opportunities.

## REST And Add-on Registration

Initializing the REST server without dispatching requests found 142 DBVC route paths:

| Namespace | Live paths |
|---|---:|
| `dbvc/v1` | 126 |
| `dbvc_cc/v2` | 16 |

The static inventory's 126 REST registrations are source call sites, so that number is not expected to equal the live unique-path total. Runtime evidence confirmed registered paths for Visual Editor, Bricks, media hydration, Entity Editor/core proposal workflows, and Content Migration v2.

The active site's explicit add-on gates were all enabled:

- `dbvc_addon_visual_editor_enabled=1`
- `dbvc_addon_bricks_enabled=1`
- `dbvc_cc_addon_enabled=1`

The Visual Editor, Bricks, Content Migration bootstrap, Configuration Portability admin, and AI submission-package detector classes were loaded. This establishes availability and registration only; it does not prove successful request execution or authorize an agent to invoke write routes.

## Capability Landscape

At the 2026-08-08 verification boundary, the active-checkout renderer loaded all 47 records then present in `manifest.json`.

- An anonymous/no-user context failed the `manage_options` gate.
- An existing administrator with `manage_options` passed the gate.
- The rendered HTML contained the Capability Landscape root and capability table rows.
- The loaded function came from this active checkout's `admin/capability-landscape.php`.

Interactive browser testing of filters, responsive layout, and the authenticated WordPress navigation remains outstanding. The focused PHPUnit contract remains the automated authority for the administrator/subscriber permission boundary.

## Release And Package Gate

- `.gitattributes` contains no `export-ignore` rules, and none of the agent-library/runtime-view files are ignored.
- The path-scoped workflow targets `master`, matching the available release branch naming in this repository.
- No repository release/archive workflow or `.distignore` currently defines a separate package assembly contract.
- The new agent library, runtime view, generator, workflow, and focused test are currently untracked in the active dirty checkout.

Consequently, source packaging policy is compatible with shipping the library, but a real `git archive` or release-package proof cannot include these files until they are intentionally committed. That is the remaining package gate; this verification did not stage or commit user work.

## Current Authority

This report upgrades the library from source-only evidence to partial same-checkout runtime evidence. The manifest baseline remains `live_runtime_verified=false` because mutating operations, every individual route callback, and authenticated browser behavior were deliberately not exercised.

## Opportunity Layer Follow-up — 2026-08-10

The active-checkout administrator renderer was rechecked after adding reviewed opportunity metadata. The read-only probe confirmed:

- the Opportunity filter and reviewed-opportunity summary render for an administrator;
- 3 records are reviewed candidates;
- 4 records require scope/safety review before implementation;
- 3 apparent parity gaps are explicitly covered by existing CLI records.

The remaining 37 records render as unreviewed. This follow-up did not invoke an opportunity, REST callback, CLI operation, or data mutation.

## Read-only Capability CLI Follow-up — 2026-08-10

The active checkout now registers and executes:

- `wp dbvc capabilities list`
- `wp dbvc capabilities show <id>`
- `wp dbvc capabilities doctor`

Same-checkout verification confirmed help registration, filtered list JSON, full canonical-record JSON, and doctor JSON. Doctor reported 48 records, 396/396 strict discovery ownership, 16 CLI commands in the snapshot, 126 `dbvc/v1` route paths, 16 `dbvc_cc/v2` route paths, the active DBVC 1.9.1 checkout, and all three explicit add-on gates enabled.

Doctor retains one advisory warning because the manifest baseline is intentionally not globally live-verified. The three new commands themselves are marked live-runtime verified: they read packaged artifacts and runtime registration state but do not dispatch listed DBVC capabilities or write site data.

After closing the completed opportunity, the current opportunity breakdown is 2 candidates, 4 needing review, 4 covered elsewhere, and 38 unreviewed records.

## Bounded Inspection Recipe Follow-up — 2026-08-10

Four read-only recipes in `RECIPES.md` were executed against the active checkout without invoking writer callbacks:

- checkout/capability doctor passed strict 396/396 ownership with the expected global-baseline advisory;
- one proposal readiness check completed successfully when scoped to a known proposal ID;
- media inventory returned a bounded 10-item page and summary;
- resolver-rule listing and 20-row snapshot history completed successfully.

The live pass also corrected two initial recipe bounds:

- an unscoped all-proposal readiness expansion exceeded 30 seconds and was terminated after approximately 47 seconds without output, so the recipe now requires a known proposal ID;
- a 100-item media inventory produced an unnecessarily large agent payload, so the recipe now starts at 10 items and requires an explicit pagination decision.

Recipe metadata is checked during `agent-docs:check`: all recipes must remain `read_only`, include the capability preflight record, and reference existing unique manifest IDs. These executions establish the inspection recipes only; they do not promote the underlying mixed/write records to globally live-verified or authorize follow-on changes.

## Bricks Drift CLI Follow-up — 2026-08-10

The active checkout now registers and executes `wp dbvc bricks drift` as a read-only command requiring exactly one local source: a stored package ID or readable local JSON manifest. Same-checkout verification covered command help, package selection, compact JSON, status and exact-artifact filters, pagination, changed-path bounds, and `--fail-on-drift` exit behavior.

One existing local package was inspected without changing package or site state. The bounded full-package result analyzed 240 artifacts, classified 175 clean and 65 diverged, and returned only the requested five rows. Exact-artifact checks then confirmed exit `0` for a clean artifact and exit `1` for a diverged artifact when `--fail-on-drift` was present.

The live result exposed a distinction already present in the drift service: 171 fingerprint-clean artifacts also had informational path differences, primarily volatile fields excluded or normalized by canonicalization. The CLI output contract now calls these `path_differences`, reports `clean_with_path_differences`, and keeps fingerprint status authoritative for clean/non-clean classification. It does not return raw local or golden payload values.

The command-generated JSON envelope was valid, but this site's PHP 8.4 plus older bundled WP-CLI emitted an upstream WP-CLI deprecation notice before JSON stdout, even when stderr was suppressed. That bootstrap-level notice also affects other JSON commands and occurs before the DBVC command callback can control output. Automated consumers need a compatible clean WP-CLI/PHP pairing or explicit notice handling; DBVC vendor/runtime upgrades were outside this phase.

The capability authority is now 49 curated records, 397 strictly owned discovered surfaces, 17 CLI commands, and zero unmapped surfaces. `cli.bricks.drift.inspect` is live-runtime verified; the broader Bricks apply, package promotion, publish, pull, restore, and remote workflows were not invoked or authorized.

## Entity Editor Inspection CLI Follow-up — 2026-08-10

The active checkout now registers and executes two cache-only, read-only commands:

- `wp dbvc entity-editor list`
- `wp dbvc entity-editor inspect <relative-path>`

Same-checkout verification covered command help, a bounded two-row list from the existing disk index, one exact indexed-file inspection, and a deliberate `--max-age=900` failure. The existing index was approximately 13.9 days old, so the freshness-bound command stopped with exit `1` and explicitly reported that no rebuild was performed.

The inspection result was limited to indexed identity/match metadata plus file size, SHA-256, modification time, and top-level/meta/taxonomy counts. It did not return raw JSON values or create a download. Hash-only preflight and postflight checks matched exactly: the disk cache remained `b699cc8ca3dcb62ddca4766085955a0c2a7b38315c1328ec8c558966af25f15b`, and the transient remained absent. No cache rebuild, transient population, lock, save, merge, import, delete, or transfer operation occurred.

The PHP 8.4/bundled WP-CLI deprecation notices described above also precede these commands' JSON output. That output-cleanliness issue remains an environment/vendor compatibility boundary rather than a reason to broaden this read-only command.

The capability authority is now 50 curated records, 399 strictly owned discovered surfaces, 19 CLI commands, and zero unmapped surfaces. `cli.entity_editor.inspect` is live-runtime verified; index rebuilding, raw artifact download, and all Entity Editor writers remain excluded and require separate authorization.

## Opportunity Boundary Refresh Follow-up — 2026-08-11

Phase 14 performed a source-level callback/storage audit of the four remaining `needs_review` records and added required machine-readable candidate boundaries. The resulting reviewed queue contains 3 candidates, 1 deferred workflow, 6 records covered elsewhere, no remaining `needs_review` records, and 40 unreviewed records.

The active-checkout capability CLI returned exactly these candidates with their scope and exclusions:

- high/small `addon.bricks.control_plane` for status, UI contract, schema verification, deprecations, and runtime health only;
- medium/medium `addon.content_migration.runtime_guard` for bounded V2 run list/show/overview only;
- medium/medium `proposal.core.inspect` for exact-proposal readiness and bounded summary metadata only.

`configuration.core.portability` was the sole deferred record. Its provider registry has a side-effect-free status method, but its current guide still says the workflow is unimplemented while active export, upload, apply, and rollback handlers exist; supported-contract reconciliation precedes parity work.

The audit found two important false-read-only hazards. Content Migration's current readiness GET invokes QA generation with `write_reports=true`, and proposal single-entity detail can prune stored decisions while assembling a GET response. Both callbacks are explicitly excluded from their candidate contracts, along with raw values/downloads and all writer, telemetry, remote, package, apply, recovery, cleanup, and settings operations.

Same-checkout administrator rendering, with the panel helper explicitly loaded because WP-CLI does not bootstrap that admin-only include, reported 3 candidate rows and 1 deferred row and contained both `Candidate boundary` and `Explicitly excluded` guidance. No candidate callback or data-changing operation was invoked. The existing PHP 8.4/bundled WP-CLI deprecation notices still precede JSON output.

## Bricks Doctor CLI Follow-up — 2026-08-11

The active checkout now registers and executes `wp dbvc bricks doctor`. Same-checkout help confirmed that its only supported options are table/JSON format, table fields, and `--fail-on-warnings`.

The live JSON result reported the Bricks add-on enabled in client role, `configure_and_submenu` visibility, UI contract `1.0.0`, four bounded feature flags, present theme-style/component payloads, zero health/schema warnings, and one legacy-path deprecation notice. It did not return site UID/name/URLs, raw Bricks values, stored UI diagnostic events, or package-delivery history. `--apply` was rejected with exit `1` before command execution.

A read-only pre/post hash covered all `dbvc_bricks_%` options plus the add-on enable/visibility and `bricks_theme_styles`/`bricks_components` options. Both hashes were `97b9b55021cd685be51c85feb021518c8ea908d70dcf30378250f775da7c01e8`, confirming no option mutation across the doctor and rejected-flag probes.

The PHP 8.4/bundled WP-CLI deprecation notices still precede JSON stdout. The capability authority is now 51 curated records, 400 strictly owned discovered surfaces, 20 CLI commands, seven read-only recipes, and zero unmapped surfaces. `cli.bricks.doctor` is live-runtime verified; stored diagnostics, telemetry, settings, package, fleet, remote, proposal, apply, restore, and rollback operations were not invoked or authorized.

## Content Migration Run Inspection CLI Follow-up — 2026-08-11

The active checkout now registers and executes `wp dbvc content-migration runs list` and `wp dbvc content-migration runs show <run-id>`. Same-checkout help confirmed bounded list filters/pagination and exact-run show arguments with optional bounded activity and issue-based process exit behavior.

Live list execution reported the V2 add-on enabled and selected, with 6 matching visible domain-latest runs. After correcting list materialization to use each exact latest run's existing journey events, Butler Automation list and show output agreed at 7 discovered, 7 finalized, zero failed, and zero blocked URLs. The exact show returned 124 events, 16 bounded stage rows, 3 requested recent-activity rows, and no raw values, event messages, source URLs, or artifact paths.

`--readiness` was rejected as an unknown parameter with exit `1`, before command execution. The command never invoked the readiness callback, which remains excluded because its current GET path can write QA reports. Run creation, visibility changes, reruns, fixtures, packaging, execution, import, recovery, rollback, remote operations, AI queues, downloads, raw output, and apply operations were also not invoked or authorized.

An exact pre/post hash covered the complete configured Content Migration storage tree, including relative path, size, modification time, and SHA-256 for every file. Both snapshots contained 12,133 files and hash `2c77f4b522095a010dedc594d8369a0bf591d8bfc23130e9e5a73094a571db2e`, confirming no artifact mutation across the live inspection and rejected-flag probes.

The PHP 8.4/bundled WP-CLI deprecation notices still precede JSON stdout. The capability authority is now 52 curated records, 402 strictly owned discovered surfaces, 22 CLI commands, eight read-only recipes, and zero unmapped surfaces. `cli.content_migration.runs.inspect` is live-runtime verified; the sole remaining reviewed candidate is bounded exact-proposal summary inspection, with raw/detail and every decision or writer path still excluded.

## Bounded Proposal Structural Inspection CLI Follow-up — 2026-08-11

The active checkout now registers and executes `wp dbvc proposals show <proposal-id>` and `wp dbvc proposals entities <proposal-id>`. Same-checkout help confirmed one exact ID, bounded table/JSON fields, a blocker-based exit option for `show`, and exact type/snapshot filters with a maximum 100 returned rows for `entities`.

Live execution against existing proposal `04-07-2026-221011` reported 755 manifest items, 140 declared media items, one missing import hash, 18 conservative duplicate groups, zero stored field decisions, one stored resolver-decision match, and 727 eligible but absent snapshot artifacts. The readiness envelope reported 19 known structural blocker groups and deliberately left `authoritative_apply_ready` null. A three-row entity page contained identifiers, types, source IDs, stored hashes, media-reference counts, decision counts, snapshot state, and duplicate metadata without titles, paths, URLs, raw values, or media-reference payloads.

`--raw` was rejected as an unknown parameter with exit `1`, before command execution. The implementation does not call proposal REST callbacks, live resolver matching, backup/snapshot path managers, stable-identity assignment, decision writers, masking, cleanup, recapture, upload, delete, or apply operations.

An exact pre/post fingerprint covered the proposal and snapshot trees using relative path, size, modification time, and SHA-256, plus serialized hashes of `dbvc_proposal_decisions`, `dbvc_resolver_decisions`, and `dbvc_proposal_snapshot_states`. Both snapshots retained 900 files, tree hash `1c7864d44906d5e7180c67790c360e735c577d8f61d47e58816cb5556fe3c7fc`, and option hash `0700544fa6ba99c82ae118ea61474781b51f90fbbe6941901e386b3e2889b5ce`.

The audit also corrected the broader `proposal.core.inspect` classification from read-only to mixed-risk. Its nominal GET stack can prune decisions, create or harden storage, assign stable identity metadata, or write attachment UID/hash metadata through current resolver dry-run. The earlier proposal-readiness recipe is superseded by this bounded structural recipe; authoritative readiness requires a separate callback-level safety-remediation boundary.

The administrator-only Capability Landscape rendered 53 records and 24 CLI commands with zero reviewed candidates, and included the new bounded proposal command record. The panel and record were verified through the same checkout's administrator render contract; interactive browser filtering remains a separate UI evidence layer.

The PHP 8.4/bundled WP-CLI and plugin-update-checker deprecation notices still precede JSON stdout. The capability authority is now 53 curated records, 404 strictly owned discovered surfaces, 24 CLI commands, eight read-only recipes, and zero unmapped surfaces. `cli.proposals.inspect` is live-runtime verified, no reviewed implementation candidate remains, and broader proposal-reader remediation was not performed in this phase.

## Resolver Dry-run Mutation Barrier Follow-up — 2026-08-11

Phase 18 remediates the media resolver portion of the proposal reader audit. `Resolver::resolve_descriptor()` now suppresses `vf_asset_uid` and `vf_file_hash` backfill whenever `dry_run=true`, while operational calls without dry-run retain their existing identity-backfill behavior. Existing media-bundle lookup also resolves an already-present root only; it no longer creates or hardens sync/media-bundles storage. Bundle build and ingest paths still explicitly create and harden their write targets.

Focused tests proved hash-match and relative-path reuse results remain stable across dry-run and operational modes, dry-run leaves identity metadata absent, operational resolution performs the expected backfill, and an isolated missing bundle root remains absent with no `.htaccess` or `index.php`. The resolver operational plus proposal-inspection selection passed with 17 tests and 192 assertions; the final resolver, proposal-diff, proposal-inspection, capability, and landscape regression selection passed with 80 tests and 968 assertions.

Same-checkout execution used existing proposal `04-07-2026-221011`. Resolver dry-run inspected 140 media entries, reused all 140 by asset UID, found zero conflicts, and reported 140 existing bundle hits. Pre/post database fingerprints retained 522 `vf_asset_uid`/`vf_file_hash` rows and hash `6b2597630766e792fbd568d8841d265a4219b66caa3fc7abaf34b063a7e91b8e`. The media-bundle tree retained 2,359 files and hash `986f7e055f738966c3adc208fa388d5e5f761a97e0d494cb566d8c8ac3d256bc`.

This is operation-level evidence, so the grouped `media.core.resolver_rules` record remains globally runtime unverified: decisions, global rules, reconciliation, downloads, and non-dry-run identity binding are intentionally write-capable and were not invoked. The grouped proposal inspection record also remains mixed-risk because snapshot/base-path creation, stable-identity assignment during current-state reads, and single-entity decision pruning are still separate unresolved mutation classes.

## Capability Landscape Verification UI Follow-up — 2026-08-11

Phase 19 rendered the administrator Capability Landscape through the active LocalWP checkout after adding its conservative verification ledger. At that phase boundary, the ledger contained 53 classified capability rows: 6 `live_verified`, 4 `scoped_evidence`, 16 `tested`, and 27 `source_reviewed`. It also confirmed the live/scoped summary counts, verification filter and row data contract, expandable verification details, JavaScript filter predicate, and administrator capability gate.

Focused landscape and capability tests passed with 10 tests and 179 assertions. The rendered evidence remains scoped rather than full-record live verification: the available in-app browser reached the WordPress login screen without an authenticated administrator session, so interactive selection, reset, and visual-layout behavior were not claimed as browser-verified. No capability safety classification or write authorization changed.

## Trusted Decision-Pruning Boundary Follow-up — 2026-08-11

Phase 20 makes the single-entity detail cleanup fail closed. Decision pruning now requires a trusted snapshot that produced an available authoritative diff. The response records whether pruning ran, its source and reason, before/after counts, and the number removed. Missing or untrusted baselines preserve decisions and return `dbvc_decisions_preserved_untrusted_baseline`; new entities mark pruning not applicable.

The focused missing/trusted snapshot pair passed with 2 tests and 21 assertions, including exact preservation on a missing baseline and removal of only one stale path from a trusted snapshot. The full proposal-diff contract passed with 55 tests and 642 assertions.

Same-checkout execution used existing proposal `04-07-2026-221011` and missing-snapshot entity `a7b8eb8e-58e5-420c-b86f-283dd53c2433`. It reported `performed=false`, source `missing`, reason `untrusted_snapshot`, and the preservation warning. The serialized `dbvc_proposal_decisions` hash was `5af0fb763c5d062978866889b3df3bf99b4dbfce80166aa325daecca38620a8b` before and after. This is operation-level evidence only: trusted cleanup remains a declared mutation, and snapshot/base-path creation plus stable-identity assignment remain outside this phase, so `proposal.core.inspect` stays mixed-risk and globally runtime unverified.

After recording that scoped evidence, the administrator ledger rendered 53 records: 6 `live_verified`, 5 `scoped_evidence`, 15 `tested`, and 27 `source_reviewed`. The `proposal.core.inspect` row displayed its scoped-evidence badge, fingerprint evidence, and trusted-diff warning.

## Non-Creating Snapshot Lookup Follow-up — 2026-08-11

Phase 21 separates snapshot lookup from capture storage creation. `read_snapshot()`, metadata lookup, inspection, and missing-entity deletion use non-creating base-path resolution. Post and term capture explicitly request base creation and retain proposal-directory creation, hardening, and snapshot writes.

The focused snapshot plus decision-safety selection passed with 3 tests and 30 assertions. Its isolated fixture proved read and metadata calls leave an absent base untouched, then proved capture creates the base, `.htaccess`, `index.php`, and a readable snapshot.

Same-checkout execution used isolated root `/private/tmp/dbvc-snapshot-reader-1c0dfdbc-48b4-4703-93bd-9fef8bbb59b0`. Before and after snapshot read/metadata lookup, the root and `uploads/sync/db-version-control-snapshots` base did not exist; neither security file existed. The read returned null and metadata reported `exists=false`, `readable=false`, with no timestamps.

After recording the results, the administrator ledger contains 53 records: 6 `live_verified`, 6 `scoped_evidence`, 16 `tested`, and 25 `source_reviewed`. This is scoped evidence only. Capture, database history, backup manifests/base paths, cleanup, and stable-identity assignment remain write-capable or unresolved, so neither `storage.core.snapshots` nor `proposal.core.inspect` is promoted to full live verification.

## Non-Creating Backup Lookup Follow-up — 2026-08-12

The active checkout executed backup list and manifest lookup beneath an isolated, absent temporary uploads root. `DBVC_Backup_Manager::get_base_path(false)`, `list_backups()`, and `read_manifest()` returned an absent base, an empty list, and null respectively. Before and after the reads, the root and backup base were absent and neither `.htaccess` nor `index.php` existed. The explicit writer base call then created and hardened that same path.

The focused backup/snapshot/decision selection passed with 3 tests and 28 assertions. The `proposal.core.inspect` and `storage.core.snapshots` records now carry `same_checkout_absent_storage_backup_read` as scoped evidence; their overall classification and global live-runtime state remain unchanged. Stable-identity assignment, capture, cleanup, retention, and apply behavior were not invoked or authorized.

## Stable-Identity Inspection Follow-up — 2026-08-12

The active checkout inspected an existing post snapshot and compared an exact fingerprint of its `vf_object_uid` and `dbvc_post_history` metadata before and after. Both hashes were `c975a5113c5fedf3f91a1905b792bafd4eb685ee19a9069fad5b92cbfae746a9`; the stored snapshot existed and was valid (but stale), and the fingerprint was unchanged. This verifies the deployed snapshot inspection path reads existing identity metadata without assigning or synchronizing it; the focused post/term fixture separately proves the missing-UID case remains absent through inspection and is assigned only by explicit capture.

## Decision-Pruning Write-Path Separation Follow-up — 2026-08-12

Same-checkout execution loaded proposal `04-07-2026-221011`, issued a bounded entity-detail GET for every manifest item until a trusted-snapshot response would be found, and compared the serialized `dbvc_proposal_decisions` option before and after. No trusted snapshot was currently available in that proposal; the unchanged decision-store fingerprint was `35786c7117b4e38d0f169239752ce71158266ae2f6e4aa230fbbb87bd699c0e3`. A direct entity GET returned HTTP 200 with `performed=false`, `reason=not_applicable_new_entity`, and the same fingerprint. This is scoped read-path evidence only: the explicit pruning writer was deliberately not called against the live site because it can remove stale decisions. Focused contracts cover trusted pruning and fail-closed untrusted behavior.

The focused snapshot selection passed with 3 tests and 31 assertions. `proposal.core.inspect` and `storage.core.snapshots` now carry `same_checkout_identity_metadata_fingerprint` as scoped evidence. Their grouped classification and global live-runtime state remain unchanged because trusted single-entity decision cleanup, capture, database history, cleanup, retention, and apply behavior were not invoked or authorized.

## Decision-Pruning Write-Path Separation Follow-up — 2026-08-12

The active checkout records `proposal.core.inspect` as read-only after Phase 24. Entity detail preserves stored decisions and reports eligible trusted cleanup without mutating the option. The separate administrator writer is intentionally not invoked in same-checkout verification because it deletes stale decisions; focused contracts cover its trusted success and untrusted 409 rejection with option-state preservation.

The focused decision-pruning selection passed with 2 tests and 33 assertions. The explicit route is represented by `proposal.core.decisions`; broader decision, hash, status, snapshot, capture, cleanup, retention, and apply writers were not invoked or authorized.

## Explicit Decision-Pruning Operator Surface Follow-up — 2026-08-12

The built Proposal Review drawer now exposes the pruning action only when the read-only detail response returns `eligible=true`, `reason=explicit_action_required`, and at least one stored decision. The control requires browser confirmation and uses the existing administrator/nonce-protected POST route; its returned entity/proposal summaries and exact before/after counts update the active review state. Focused server/UI-source coverage passed with 3 tests and 76 assertions, and `npx wp-scripts build admin-app` completed successfully.

No authenticated browser session with a trusted-snapshot fixture was available, so confirmation behavior and a live POST were not claimed as runtime verified. The writer was deliberately not invoked against the active proposal because it can remove stored decisions.

## Proposal Decision Operator REST Authorization Follow-up — 2026-08-12

The WordPress REST fixture now exercises the registered pruning route rather than only its callback. A subscriber receives `403` with `rest_forbidden` and leaves the decision option intact; an administrator then receives `200`, prunes only the stale path, and receives exact entity/proposal summaries. The focused authorization/untrusted/UI-source selection passed with 3 tests and 84 assertions; the full proposal-diff contract passed with 58 tests and 702 assertions. This remains repository test evidence: no authenticated browser session or active client proposal was used.

## Proposal Decision Operator Browser Gate — 2026-08-12

A same-checkout in-app browser recheck of the Proposal Review URL redirects to the local administrator login page. No authenticated administrator session or trusted-snapshot fixture is available, so visible confirmation, success/no-op refresh, and 409 copy cannot be verified without user authentication. No credentials were entered and no live pruning request was sent.

## Proposal Decision Operator Authenticated Fixture Follow-up — 2026-08-12

With a user-authenticated local administrator session, Proposal Review displayed the `Prune stale decisions` drawer action and its exact confirmation copy only for a disposable trusted-snapshot fixture. The fixture used three isolated page entities and no client proposal, post, snapshot, or decision data. The registered writer then removed two stale fixture choices (`before_count=2`, `after_count=0`), preserved one current choice on the no-op path (`before_count=1`, `after_count=1`, `pruned_count=0`), and returned `409 dbvc_decision_pruning_unavailable` after the third fixture snapshot was removed while preserving both of that entity's choices.

The in-app browser-control bridge stalled at the native JavaScript confirmation boundary, so its success-toast and 409 error-copy refresh cannot be claimed as browser-verified. A later drawer reload also retained stale fixture decision state despite the writer's authoritative response. The registered REST authorization test continues to cover administrator/subscriber behavior, exact summaries, and the UI-source test covers the returned error message. All namespaced fixture pages, proposal/snapshot directories, and option state were removed after verification.

## Proposal Decision Operator Browser UI Refresh Follow-up — 2026-08-12

The authenticated browser initially still rendered the removed fixture proposal `dbvc-browser-qa-20260812-054029-iv2KuJ` and its `Prune stale decisions` action. The active LocalWP database check found no fixture state, stored decisions, pages, backup directory, or snapshot directory. Phase 27 added `cache: "no-store"` to the shared Proposal Review GET helper; after reloading the same page, browser queries found zero matching fixture rows and zero prune actions. This is same-checkout evidence that the stale display was client/browser state, not persisted DBVC data.

The native confirmation bridge remains unable to complete the successful-toast and 409-error presentation checks. Those UI states remain deferred; the REST authorization fixture and proposal-diff contract continue to cover the writer result and failure semantics.

## Proposal Decision Operator In-App Confirmation Follow-up — 2026-08-12

Phase 28 replaces only stale-decision pruning's native confirmation with an in-app accessible modal. Authenticated fixture QA opened the modal with the exact warning and Cancel/Confirm controls; Cancel preserved the eligible action, while Confirm exercised the no-op and stale-prune paths against isolated records. The writer removed the stale choices and the action became ineligible. The implementation adds a persistent in-drawer success status rather than relying only on a transient toast; its targeted browser presentation check remains pending.

The temporary pages, decisions, proposal and snapshot directories, and fixture option state were removed completely. The browser UI has not yet performed the 409 response presentation through the new modal; server authorization/untrusted contracts still cover that fail-closed result.

## Proposal Decision Operator Fail-Closed Error UI Follow-up — 2026-08-12

With the in-app modal open for an eligible disposable fixture, its trusted snapshot was removed immediately before `Confirm prune`. The authenticated local administrator browser visibly rendered the exact `Stale decisions can be pruned only after a trusted current-state snapshot is available.` response and continued to display `1 accepted · 1 kept · 0 declined`. Fixture inspection confirmed both choices were still stored. The temporary fixture was then completely removed, including pages, decisions, proposal/snapshot directories, and backup directory.

This verifies only the modal's returned fail-closed response presentation and decision preservation. Persistent successful-prune status presentation, other writers, readiness, and apply behavior remain outside this browser QA boundary.

## Proposal Decision Operator Persistent Success Status Follow-up — 2026-08-12

An authenticated administrator browser opened the in-app confirmation for a new disposable stale fixture and confirmed it. Once the authoritative refresh completed, the same drawer visibly showed `Stale decisions pruned` and `2 stale choices were removed; 0 current choices remain.` It also showed no selections and no longer exposed the prune action. Fixture inspection confirmed the selected entity's two decisions were removed; the fixture was then completely removed.

This verifies the persistent successful-prune result UI and its returned counts. Modal keyboard focus/escape behavior, other writers, readiness, and apply behavior remain outside this browser QA boundary.

## Proposal Decision Operator Modal Keyboard Accessibility Follow-up — 2026-08-12

With an isolated stale fixture, the authenticated browser opened the in-app confirmation and sent Escape without confirming the writer. Escape safely closed the modal, retained the prune action, and fixture inspection verified its two target decisions remained. Focus evidence found an accessibility defect: opening the modal left focus on the active entity-list control outside the modal, while Escape returned focus to the drawer `Close` control instead of `Prune stale decisions`.

The fixture was fully removed. This is a browser-verified failure finding; no modal or writer code was changed. A bounded focus-management fix is required before keyboard accessibility can be marked verified.

## Proposal Decision Operator Modal Focus Restoration Fix — 2026-08-13

Phase 32 adds a dedicated prune-opener ref, focuses `Cancel` once the confirmation is rendered, and restores the opener after the modal closes without a prune. The focused contract test passed, and authenticated fixture QA verified that opening focus is `Cancel`; Escape, Cancel, and the WordPress modal Close control each close the modal and return focus to `Prune stale decisions`. The writer was not invoked and fixture inspection confirmed its two decisions remained. All fixture artifacts were removed afterward.

This resolves the Phase 31 modal focus finding. Broader drawer-close focus regression, other writers, readiness, and apply behavior remain outside this browser QA boundary.

## Proposal Decision Operator Remaining QA Closeout Policy — 2026-08-13

The previously separate success, no-op, fail-closed 409, persistent-status, modal-dismissal, and modal-focus cases are already verified for the current source and should not be repeated as independent boundaries. The only remaining UI closeout case is drawer-close focus restoration after the modal focus refactor. It is non-writer QA: open a disposable fixture entity from its originating control, close the drawer without opening or confirming the prune modal, verify focus returns to that origin, confirm decisions are unchanged, and remove the fixture.

Record that check as one compact result here and update `proposal.core.decisions` once. Do not append parallel summaries to maintenance and implementation documents, rerun the full Proposal Diff suite for evidence-only work, or introduce a separate run ledger for this single remaining case. Existing cases should be rerun only when relevant drawer, modal, or pruning source changes invalidate their evidence.

## Proposal Decision Operator Drawer Close Focus Regression QA — 2026-08-14

In the authenticated local administrator browser, the namespaced disposable proposal `dbvc-browser-qa-20260814-041712-U7FGMg` opened its success entity drawer from the originating `tr[role="button"][tabindex="0"]` control. Clicking `Close entity detail` removed the drawer and returned `document.activeElement` to that exact origin. The prune confirmation and writer were not invoked; an independent fixture inspection confirmed all five stored choices remained unchanged. Cleanup then removed the fixture option state, three pages, proposal directory, and snapshot directory, and a final inspection found no namespaced posts, decisions, or fixture directories. This closes only the drawer-focus regression boundary; broader decision writers, readiness, and apply behavior remain out of scope.

## Configuration Portability Metadata CLI Follow-up — 2026-08-14

Phases 34-35 reconciled the implemented configuration portability workflow with active guide/roadmap authority and added two read-only metadata commands:

- `wp dbvc config domains`
- `wp dbvc config status`

Same-checkout leaf help passed for both commands. Exact-domain JSON returned the `visual_editor` provider's version plus group, field, policy, and sensitive-field counts; aggregate status reported 11 domains, 41 groups, and 235 fields with explicit `current_values_read=no` and `writer_services_invoked=no` markers. `--apply` was rejected as an unknown parameter before command execution.

A hash-only pre/post check covered all 235 unique option keys declared by registered provider field metadata. Both hashes were `cb40d0beb2b8c3905d62cf784010b81f04d0c3a3250aff798dfa2c80a98c2e97`, confirming the live inspection did not change registered configuration values. No export, download, upload, package/session read, diff, environment replacement, apply, backup, rollback, secret output, or other capability boundary was invoked.

## Connected Environments Observation Slice Lab — 2026-09-17

Branch `claude/dbvc-connected-agency-m0-ac4c5d` (worktree; **not** the checkout loaded by the live LocalWP site). A disposable lab loaded this checkout by symlinking it into `tmp/wordpress` (WordPress 6.9.4, PHP 8.4.3 CLI, LocalWP MySQL 8.0.16, prefix `wpcelab_`, Bricks 2.3.8 symlinked and activated, `WP_HTTP_BLOCK_EXTERNAL`). Same-checkout WP-CLI cases, all passed:

- Both modules off: `wp dbvc connected status` → `disabled`/`disabled`; no `wpcelab_dbvc_ce_*` tables; the gate option did not exist; a `bricks_global_classes` save created no marker and no cron event.
- Enable via `DBVC_Connected_Environments_Addon::save_settings()`: five InnoDB tables with the expected unique keys (`event_identity`, `event_sequence`, `dirty_object`, `object_profile`, identity keys); `transactional=true`; provisional identity; both domains `coverage=available` through real `BRICKS_VERSION` detection.
- `wp dbvc connected reconcile` + `wp cron event run dbvc_connected_process_observations` (after the 20 s debounce): 2 markers acknowledged, 3 identities assigned, 5 events (3 members + 2 order projections), sequences 1–5, all `pending`; `bricks_global_classes` unchanged.
- Burst of 3 distinct saves → one marker at generation 3 → one run emitting 3 events; 30 distinct saves → generation 30 and no additional cron events; save-side cost with listener on vs off: 4.13 vs 2.0 queries and 2.9 vs 1.62 ms per save (3-class fixture, `wp eval` loop).
- Remove one class → tombstone event (`exists=no`, hash `74234e98…` = canonical `null`) plus an order event; projection row `exists=no` retains `storage_key`.
- `DBVC_CONNECTED_EMERGENCY_DISABLE=true` in wp-config → `emergency_disabled`, runtime not registered, a save left the marker unchanged; removing the constant restored `ready`.
- Disable → cron event cleared, 10 outbox and 5 projection rows retained. Hub only → `scaffold_only`, 241 REST routes with zero agency/connected matches, no hub tables.
- `apply_filters('dbvc_excluded_option_keys')` contains the gate/schema keys; `dbvc_import_options_data` dropped `dbvc_addon_connected_environments_enabled` and `dbvc_ce_*` keys.

Cleanup: 27 `wpcelab_` tables dropped (0 remain), lab `wp-config.php`, symlinks, wrapper, `sync/` and uploads removed. Focused PHPUnit: 35 tests / 543 assertions OK; full suite 718 tests with the 6 pre-existing failures reproduced identically on base `c88764b`. `live_runtime_verified` stays `false` for the new records until the live site loads this checkout.

## Connected Environments M2 Step 1 Two-Server Lab — 2026-09-18

Branch `claude/dbvc-connected-agency-m2-receipt` (stacked on `claude/dbvc-connected-agency-m0-ac4c5d`; not the checkout loaded by the live LocalWP site). One codebase (`tmp/wordpress`, this checkout symlinked) served twice by PHP's built-in server — hub on 127.0.0.1:8098 (prefix `wpachub_`), client on 127.0.0.1:8099 (prefix `wpceclient_`, Bricks 2.3.8 activated) — with `WP_ENVIRONMENT_TYPE=local`, external HTTP blocked and WP-Cron disabled (explicit runners). All passed:

- Unauthenticated and administrator Basic-auth requests to `/index.php?rest_route=/dbvc-agency/v1/capabilities` → 401 `dbvc_agency_app_password_required`; `/wp-json/…` returned 301 on this permalink-less hub, which is why the connector uses the `rest_route` form.
- `wp dbvc agency invite --client=client-a` (token shown once) → client `wp dbvc connected enroll --hub=http://127.0.0.1:8098 --token=…` over HTTP: service user `dbvc-env-…` in role `dbvc_connected_environment`, environment `local-…` enabled with hub epoch `enrolled-20260918-…`, 5 provisional outbox rows superseded, reconciliation requested.
- `wp dbvc connected process` re-emitted 5 events under the enrolled epoch; `wp dbvc connected deliver` → sent 5 / delivered 5 / HTTP 200; hub `events` and `projections` show the 5 rows (`routing_state=pending`, origin `reconciliation`); `bricks_global_classes` serialized hash identical before and after.
- One class edit → 1 event → delivered; hub projection updated to the new sequence.
- curl batch with the enrolled credential but a foreign `environment_id`, plus an event carrying `recipients` → `environment_mismatch` and `invalid_event:unknown:recipients`, nothing stored.
- Same credential with `X-DBVC-Site-URL: http://clone.invalid/` → 409, environment `held` with `site_url_mismatch`.
- Sequence-restart fix applied after the first run showed hub gaps (1–5 consumed under the provisional epoch); PHPUnit now asserts 0 gaps after enrollment.

Cleanup: both servers stopped, 53 lab tables dropped (0 remain), lab config/symlinks/uploads removed. Focused PHPUnit: 44 tests / 781 assertions OK. `live_runtime_verified` remains `false` for every connected/agency record.

## Connected Environments M2 Step 2 Three-Server Lab — 2026-09-19

Branch `claude/dbvc-connected-agency-m2-routing` (stacked on the M2 step-1 branch; not the checkout loaded by the live LocalWP site). One codebase served three times by PHP's built-in server — hub :8098 (`wplabhub_`), Client A production :8099 (`wplabaprod_`, Bricks 2.3.8), Client A staging :8100 (`wplabastage_`) — with a disposable mu-plugin registering the `service` post type, `WP_ENVIRONMENT_TYPE=local`, external HTTP blocked, WP-Cron disabled (explicit runners). All passed:

- Both environments enrolled over HTTP with fixed invitation ids (`aprod`, `astage`); `wp dbvc agency subscribe --source=aprod --target=astage` created the three domain subscriptions.
- Before the CPT fixture existed, `wp.service` markers were held with `source_unavailable:post_type_not_registered` (no tombstones, no false clean); after adding it, the next run observed the service post and delivered it.
- A-prod delivered 2 classes + order, 1 variable + order, 1 service (6 events); the hub routed all six into pending A-stage deliveries (`routing_state=routed`).
- `subscribe-framework` for the primary button class, then a class edit and a service edit on A-prod → 2 events delivered; hub `reviews` shows one `observed` item for `framework-btn-primary`; deliveries pending for A-stage: 8.
- A-stage `wp dbvc connected poll` (first contact after being "offline"): 1 page, 8 retrieved, 8 stored, 8 acknowledged, cursor 0 → 8; A-stage still has zero service posts; hub deliveries 8 acked, `last_inbox_poll_at` recorded; idle poll retrieved 0.
- Disabling the service subscription: a further service edit produced no A-stage delivery while a class edit did (1 retrieved).
- curl ack from A-stage for `[424242, 1]` → `unknown`, `already_acked`; A-prod polling its own inbox → 0 items.

Cleanup: three servers stopped, 85 lab tables dropped (0 remain), lab config/symlinks/mu-plugin/uploads removed. Focused PHPUnit: 59 tests / 1060 assertions OK. `live_runtime_verified` remains `false` for every connected/agency record.

## Connected Environments M3 Step 1 Comparison Lab — 2026-09-19

Branch `claude/dbvc-connected-agency-m3-baselines` (stacked; not the checkout loaded by the live LocalWP site). Same three-server layout as the M2 step-2 lab (hub :8098, A-prod :8099, A-stage :8100, `service` CPT mu-plugin, processing delay 0). Both connectors enrolled over HTTP and reported the same Bricks class fixture plus a service post carrying one shared `vf_object_uid`:

- `wp dbvc agency compare --source=aprod --target=astage`: five rows all `baseline_required`; the Bricks class appeared twice (each environment's own sidecar UID) with `source_observed=no`/`target_observed=no` on the opposite side — independently enrolled lineages do not pair without a link.
- `link-instance` for the two class UIDs → one paired row (`pairing=link`); `baseline-confirm --note="initial agreement"` → confirmed 3 (class, two order projections), skipped 1 (`wp.service`: `no_agreement`, because `post_date_gmt` differs on independently created posts); counts then 3 synchronized / 1 baseline_required.
- Class edit on A-prod and service edit on A-stage, processed and delivered → class row `outgoing` (baseline hash = target hash ≠ source hash); service row still `baseline_required`; `baselines` lists the three confirmations with note and timestamp.

Cleanup: servers stopped, 87 lab tables dropped (0 remain), lab files removed. Focused PHPUnit: 62 tests / 1151 assertions OK. `live_runtime_verified` remains `false` for every connected/agency record.

## Connected Environments M3 Step 2 Framework Lab — 2026-09-19

Branch `claude/dbvc-connected-agency-m3-framework` (stacked; not the checkout loaded by the live LocalWP site). Two-server layout (hub :8098 `wplabhub_`, A-prod :8099 `wplabaprod_` with Bricks 2.3.8, processing delay 0, external HTTP blocked, WP-Cron disabled). A-prod enrolled over HTTP and reported one real Bricks class:

- `definition-publish --definition=btn-primary --version=1.0 --from-environment=aprod --from-instance=<sidecar uid> --desired` recorded the class's projection hash as version 1.0 / order 1 on `stable` with its source; republishing `1.0` with another hash was refused (`Definition versions are immutable`).
- `subscribe-framework … --adopted-version=1.0` then `framework-status` → `clean` / `current`. A real `bricks_global_classes` save, processed and delivered → `local_drift`; `override-approve --rationale="client brand colour"` recorded the observed hash with the policy revision → `approved_override`; a further save → `override_changed`; reverting the save → `approved_override`.
- `definition-publish 1.1 --from-version=1.0 --desired` → `rebase_reviews=1`; status `behind_version` with `override_state=needs_rebase_review`, the override's hash and rationale untouched. `1.10` published after `1.1` took order 3 (no label comparison). `adopt-version --id=1 --version=1.1` → `current`; `--version=1.10` → `ahead_version`; `override-detach` → `local_drift`, override `detached` and still listed.
- A duplicate `--order=3` on `stable` was refused (`dbvc_agency_version_order_taken`) after the schema's `definition_order` unique key was present (the lab hub had been installed before the key was added, so `Schema::install()` was re-run once); the same order on `--channel=beta` was accepted. `wp option get bricks_global_classes` on A-prod was byte-identical before and after every hub action.

Cleanup: servers stopped, 61 lab tables dropped (0 remain), lab config, symlinks, mu-plugin, uploads and wrapper removed. Focused PHPUnit: 68 tests / 1297 assertions OK. `live_runtime_verified` remains `false` for every connected/agency record.

## Connected Environments M3 Step 3 Coverage, Review and Admin Lab — 2026-09-19

Branch `claude/dbvc-connected-agency-m3-admin` (stacked; not the checkout loaded by the live LocalWP site). Three-server layout (hub :8098, A-prod :8099 with Bricks 2.3.8 and two `service` posts, A-stage :8100 with none; `service` CPT mu-plugin, processing delay 0, external HTTP blocked, WP-Cron disabled) served through a router script so `wp-admin` loads. A disposable mu-plugin provided a local-only auto-login for the lab administrator so no password was typed into the browser.

- Hub projections listed `wp.service collection.order wp-service-inventory-v1` complete for both connectors; `compare --domain=wp.service` classified both A-prod services `baseline_required` with `target_observed=no` (coverage from A-stage's empty inventory; before this step they were `unknown`); `baseline-confirm --accept-absent` confirmed 2 and both became `outgoing`. A-stage `poll` stored and acknowledged 6 deliveries.
- Framework subscription on the real class, then a real `bricks_global_classes` save: `reviews` showed one `observed` item with `event_sequence=7`; `review-classify` → `classified local_drift/current`; `review-resolve --id=1 --note=…` → `resolved operator` with the note.
- Built-in browser on the hub (DBVC → Configure → Add-ons → Connected Environments): the panel rendered both environments (status, epoch, last contact, fresh, received counts), the invitation form, "Open invitations: None", the framework status row (`local_drift`/`current`) and review counts. Submitting the form for `client-b` redirected to the DBVC page with a success notice carrying the token once and the exact `wp dbvc connected enroll` command; a reload showed no notice or token and listed invitation 3 under open invitations. The `form` attribute wiring worked: no nested form, the request went through `admin-post.php` with the nonce. On A-stage the Connected Environments panel listed the 6 received observations, inventory row first, with hash prefixes and ack times, and no hub panel or invitation form (hub disabled there). Observation: the redirect hash does not activate the nested Add-ons subtab; the notice is visible regardless.

Cleanup: three servers stopped (plus a stale :8100 server left by the M3 step 1 lab), 89 lab tables dropped (0 remain), lab config, router, symlinks, mu-plugin, uploads and wrapper removed. Focused PHPUnit: 73 tests / 1480 assertions OK. `live_runtime_verified` remains `false` for every connected/agency record.

## Connected Environments M4 Step 1 Release/Prepare Lab — 2026-09-19

Branch `claude/dbvc-connected-agency-m4-prepare` (stacked; not the checkout loaded by the live LocalWP site). Three-server layout (hub :8098 schema v6, A-prod :8099, A-stage :8100, both with Bricks 2.3.8, the same class, and a `service` post sharing one `vf_object_uid`; A-prod then edited both), processing delay 0, external HTTP blocked, WP-Cron disabled.

- `wp dbvc agency release-create --source=aprod --items=bricks.global_class:<uid>,wp.service:shared-service-uid-0001` → release `open` with two `requested` items; `wp dbvc connected release` on A-prod → `payload_requests 2, payloads_sent 2, mismatched 0`; `releases --release` → `sealed`, digest, both items `received`.
- `prepare-request --release --target=astage` → operation id; `wp dbvc connected release` on A-stage → one receipt stored and reported, outcome `partial`: the service `ready` (identity `vf_object_uid` → post 4, container `post_type:service#4`, patch `post_content`/`post_title` replace, storage fingerprint), the class `blocked identity_unmatched:creation_requires_decision` (independent sidecar lineage, identity source `sidecar`, `storage_key` null). Hub `preparations --operation` showed state `received`, the receipt, expiry one hour after preparation, and hub notes (`target_projection_agrees` true for the service, no hub hash for the unknown class). A-stage's `bricks_global_classes` option and post title were byte-identical before and after.
- `link-instance` between the two class UIDs and a fresh `prepare-request` → receipt `ready` 2/0/0, class identity `btn001` via `sidecar+link`, patch `settings` replace, hub notes agreeing for both; A-stage content again unchanged. `status` on the hub reported `releases {sealed: 1}` and `preparations {received: 2}`.

Cleanup: three servers stopped, 94 lab tables dropped (0 remain), lab files removed. Focused PHPUnit: 75 tests / 1654 assertions OK. `live_runtime_verified` remains `false` for every connected/agency record.

## Connected Environments M5 Step 1 Guarded Round Trip Lab — 2026-09-19

Branch `claude/dbvc-connected-agency-m5-apply` (stacked; not the checkout loaded by the live LocalWP site). Three-server layout (hub :8098 schema v8, A-prod :8099 and A-stage :8100 with Bricks 2.3.8 and the same two global classes plus one variable; both class lineages linked with `link-instance`), processing delay 0, external HTTP blocked, WP-Cron disabled.

- A-prod edited `btn001` (added `_padding`); `release-create` + A-prod `release` → sealed; `prepare-request` for A-stage → receipt `ready` 1/0/0 with `apply_enabled false`; `approve --operation` → `approved` with the receipt's expiry.
- Apply gate off: A-stage's `release` reported `apply_enabled false, apply_requests 0, executions 0` and its option was unchanged. Gate on (connector save with the apply checkbox): `apply_requests 1, executions 1, reported 1`, outcome `applied` 1/0/0; A-stage's `bricks_global_classes` now carried the padding on `btn001` with `txt002` untouched; `operations` showed the journal `write: written`, `verify: verified` and the `generated_css_not_rebuilt` warning, `reported_at` set.
- Hub: `approvals` → `consumed applied`; `baselines` → one row `applied:<operation>` for the linked class pair; A-stage `process` observed one event with `origin=apply` and delivered it; `compare --source=aprod --target=astage` → the linked class `synchronized` (the other class and the order projection `baseline_required`, never confirmed).
- Stale: A-prod edited `btn001` again, second release sealed, prepared and approved; A-stage then edited its own container (`txt002` colour) before polling; execution reported `stale` 0/0/1 with `container_changed_since_receipt`, the approval read `consumed stale`, and A-stage's concurrent edit survived byte-for-byte.

Cleanup: three servers stopped, 97 lab tables dropped (0 remain), lab files removed. Focused PHPUnit: 79 tests / 1969 assertions OK. `live_runtime_verified` remains `false` for every connected/agency record.
