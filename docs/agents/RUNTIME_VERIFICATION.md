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
