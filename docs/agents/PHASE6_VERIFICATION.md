# DBVC Agent Reference Phase 6 Verification — Historical Cross-Checkout Record

## Post-Merge Status — 2026-08-06

The newer LocalWP checkout described below is now the checkout containing the agent library. Its three media commands, expanded REST surface, Visual Editor, Content Migration runtime, configuration portability, and AI package intake have been reconciled into the current manifest.

Current source authority is 47 records and 393 strictly mapped surfaces, including 13 CLI commands and 126 REST registrations. The original comparison remains below to explain how the checkout divergence was discovered. Statements below that Visual Editor was absent or that live-only capabilities were not merged describe the earlier detached-worktree boundary, not current state.

No same-checkout runtime record was promoted to `live_runtime_verified: true`; authenticated WP-CLI, REST, and browser verification remains a separate boundary.

Date: 2026-08-05  
Result: Static agent-use QA passed; authorized cross-checkout runtime differences documented; same-checkout live verification remains outstanding.

## Authority And Safety Boundary

The canonical repository baseline is the detached documentation worktree at commit `0e8233c35df0485dec14adf33f0a064b71480c2d`.

The available LocalWP runtime uses a different plugin checkout:

- site: `https://dbvc-codexchanges.local`;
- active plugin version: `1.9.1`;
- plugin checkout: `/Users/rhettbutler/Documents/LocalWP/dbvc-codexchanges/app/public/wp-content/plugins/db-version-control-main`;
- branch: `codex/visual-editor-linked-posts-plan`;
- inspected HEAD: `78a06ad`;
- state: dirty with unrelated ongoing development.

That checkout was inspected read-only as a newer behavioral comparison. Its live-only capabilities were not merged into this worktree's manifest, and no record was changed to `live_runtime_verified: true`.

Verification loaded command metadata and REST registrations only. It did not invoke DBVC export, import, upload, apply, delete, cleanup, recapture, media hydration, package, onboarding, remote, or route-callback operations. No live files, settings, options, posts, terms, media, packages, proposals, or remote sites were changed.

## Static Agent-Use Scenarios

| # | Scenario | Retrieval path | Result |
|---:|---|---|---|
| 1 | Find every current WP-CLI command and its arguments | [`generated/index-by-command.md`](generated/index-by-command.md) | Pass after correcting nested optional and variadic synopsis discovery. The generated index exposes all 10 repository commands, arguments, owner, status, safety, and facet. |
| 2 | Find only import-related commands and functions | `composer agent-docs:query -- operation:import` and `operation:import surface:cli` | Pass. The CLI-only query returns core import and resolver-rule import; the broader query adds engine, Entity Editor, media, and Bricks package records. |
| 3 | Separate read-only diagnostics from write/apply operations | `safety:read_only status:active`, `operation:apply status:active`, and [risk index](generated/index-by-risk.md) | Pass. Mixed records are excluded from the strict read-only filter; every apply result carries a write classification. |
| 4 | Determine whether Bricks has CLI parity | `scope:addon:bricks surface:cli` and `scope:addon:bricks surface:rest` | Pass. No Bricks CLI record exists in this baseline; eight Bricks REST capability groups are discoverable. This is a parity gap, not an implied command surface. |
| 5 | Identify WordPress, filesystem, and remote effects | [risk index](generated/index-by-risk.md), record `storage_touched`, and safety metadata | Pass. Consequence types are independently filterable and mixed workflows retain operation-specific warnings. |
| 6 | Separate runtime add-ons from source-reference or absent work | [staged/planned/absent facet](facets/staged-planned-and-absent.md) and status filters | Pass. Content Collector remains `source_reference`, Visual Editor remains `absent_current_checkout`, and the Content Migration runtime guard is not misrepresented as the retained application's runtime. |
| 7 | Find code, tests, and docs for adding a CLI command | [CLI facet](facets/cli-and-automation.md), manifest references, and `commands/class-wp-cli-commands.php` | Pass with a documented gap. The implementation, registration, discovery, manifest, facet, and check paths are explicit; this baseline has no command-level automated test references, so a new command should add focused tests rather than claim existing CLI coverage. |
| 8 | Build a safe new-client-site automation plan | `workflow:client_onboarding`, safety filters, Bricks/core facets, and related records | Pass. The only direct onboarding result is remote-write classified; the safe plan below requires inspection, backup, preview/review, explicit write authority, and post-action verification. No operation is silently invoked. |

## Repository WP-CLI Baseline

The generated [WP-CLI command index](generated/index-by-command.md) is the self-updating source-observed inventory. Phase 6 corrected the synopsis parser so it now retains:

- nested optional tokens such as `[--recapture-snapshots[=<ids>]]`;
- variadic positional tokens such as `<original_id>...`.

The baseline contains 10 leaf commands owned by eight curated records. `composer agent-docs:check` enforces ownership of every discovered command.

## Live WP-CLI Comparison

The live checkout exposes 13 leaf commands:

| Live command | Live synopsis | Comparison to repository baseline |
|---|---|---|
| `wp dbvc export` | `[--batch-size=<number>] [--chunk-size=<number>] [--job-id=<number>] [--baseline=<id\|latest>]` | Present; option order is non-semantic. |
| `wp dbvc import` | `[--batch-size=<number>]` | Present. |
| `wp dbvc snapshots` | `[--type=<type>] [--limit=<number>] [--offset=<number>]` | Present. |
| `wp dbvc proposals list` | `[--fields=<fields>] [--id=<proposal-id>] [--fail-on-pending] [--recapture-snapshots[=<ids>]] [--cleanup-duplicates]` | Present; live checkout adds proposal filtering. |
| `wp dbvc proposals upload` | `<zip> [--id=<id>] [--overwrite]` | Present. |
| `wp dbvc proposals apply` | `<proposal_id> [--mode=<mode>] [--ignore-missing-hash] [--force-reapply-new-posts]` | Present. |
| `wp dbvc resolver-rules list_` | `[--fields=<fields>]` | Intended baseline equivalent is `list`; live command-existence checks confirm only `list_` is registered. Treat as a live naming defect/parity mismatch. |
| `wp dbvc resolver-rules add` | `<original_id> <action> [--target=<attachment_id>] [--note=<text>]` | Present. |
| `wp dbvc resolver-rules delete` | `<original_id>...` | Present. |
| `wp dbvc resolver-rules import` | `<file>` | Present. |
| `wp dbvc media hydrate` | `--manifest=<path> [--dry-run] [--apply] [--confirm=<token>] [--package-root=<path>] [--limit=<number>] [--offset=<number>] [--no-repair-metadata] [--save-receipt] [--no-clone-confirmation] [--no-strict-hashes] [--match-policy=<policy>] [--format=<format>]` | Live-only. |
| `wp dbvc media inventory` | `[--limit=<number>] [--offset=<number>] [--ids=<ids>] [--mime-groups=<groups>] [--compute-hash] [--check-derivatives] [--format=<format>]` | Live-only. |
| `wp dbvc media mirror_export` | `[--with-files] [--zip] [--out=<path>] [--package-id=<slug>] [--ids=<ids>] [--mime-groups=<groups>] [--batch-size=<number>] [--check-derivatives] [--format=<format>]` | Live-only; command spelling is the live registration. |

Nine leaf names match exactly, one resolver-list capability has a live `list_` naming mismatch, and three media commands exist only in the newer live checkout. These are cross-checkout differences, not omissions from the `0e8233c` source snapshot.

`wp cli cmd-dump` is not sufficient for this plugin because it runs before the active plugin registers its commands. Runtime verification therefore used loaded `wp help dbvc` metadata plus `wp cli has-command` checks.

## Live REST Comparison

The comparison normalized registrations into unique route paths and method sets, excluded WordPress namespace-index routes, and compared the repository's active core/Bricks scopes with the loaded live `/dbvc/` registry.

| Measure | Count |
|---|---:|
| Repository baseline unique paths | 72 |
| Live unique paths | 124 |
| Common paths | 72 |
| Repository-only paths | 0 |
| Live-only paths | 52 |

All repository paths were registered in the newer runtime. The 52 live-only paths group as follows:

| Live-only family | Paths | Examples |
|---|---:|---|
| Bricks connected-site, package, portability, and protected-variant expansion | 16 | assisted merge, publish runs, portability sessions/apply/rollback, protected variants |
| Entity Editor intake, merge, sync-file, third-party, and delete expansion | 12 | raw intake, merge JSON, sync-file remediation, transfer preview |
| Media hydration | 12 | inventory, preflight, jobs, package export, receipts, apply |
| Proposal detail expansion | 2 | entity raw sides and readiness |
| Visual Editor | 10 | object/reference search, session descriptors, save/touch, collection/composite operations |

Five common Bricks paths have broader live methods: `configure/rules`, `configure/shared-rules-profile`, `connected-sites`, `packages`, and `proposals` are `GET` in the baseline snapshot and `GET` plus `POST` live.

No route callback was called. Registration presence confirms only that the newer checkout loaded the surface; it does not establish autonomous-use safety, target compatibility, or permission to mutate data.

## Safe New-Client-Site Automation Outline

1. Verify the exact target plugin checkout, version, enabled add-ons, site role, and canonical identity.
2. Query the manifest for the requested workflow and exclude non-active statuses.
3. Start with strict `safety:read_only` inspection records and record observed state.
4. Confirm selected object families, storage paths, domain transforms, media policy, and source/target authority.
5. Create and verify database/uploads backups appropriate to the intended writes; do not treat a DBVC snapshot alone as a full backup.
6. Generate or stage artifacts/proposals without applying them where the workflow supports separation.
7. Validate hashes, package provenance, schema compatibility, resolver decisions, and previews.
8. Require explicit authority for each filesystem, WordPress, destructive, or remote-write step; require confirmation tokens where supported.
9. Apply only the approved scope, then inspect receipts, logs, resulting entities, and rollback availability.

## Residual Gaps And Next Boundary

- Same-checkout live verification is unavailable because the runtime does not load commit `0e8233c`.
- The newer live checkout needs its own discovery/manifest refresh before its media, Visual Editor, portability, and other added surfaces can be represented as current agent capabilities.
- The live resolver-rule command naming mismatch should be diagnosed in the newer checkout before agents rely on documented `list` examples.
- CLI-specific automated tests are not referenced by this baseline's command records.

Phase 6 is complete because static retrieval passed and the authorized runtime inventory was reconciled with explicit differences. Phase 7 maintenance integration is the next boundary.
