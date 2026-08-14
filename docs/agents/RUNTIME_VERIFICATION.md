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
