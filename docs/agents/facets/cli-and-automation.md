# DBVC CLI And Automation Facet

Load this facet when an agent needs WP-CLI discovery, headless execution, exit-code behavior, or CLI parity analysis. The canonical command arguments, consequences, and evidence remain in [`manifest.json`](../manifest.json); search for the record ID before invoking anything.

## Start Here

For the complete source-observed command and argument list, open the generated [WP-CLI command index](../generated/index-by-command.md). Its signatures come from the discovery snapshot; the linked manifest records provide reviewed status and safety context.

| Need | Record | Boundary |
|---|---|---|
| Discover capabilities and diagnose catalog/runtime registration | `cli.core.capabilities.inspect` | Read-only; does not dispatch listed capabilities |
| Inspect Bricks status, UI contract, and live schema health | `cli.bricks.doctor` | Read-only; no stored diagnostics, raw values, or mutation |
| Compare a Bricks package or manifest with local artifacts | `cli.bricks.drift.inspect` | Read-only; bounded rows and no raw payload values |
| List and inspect Content Migration V2 runs | `cli.content_migration.runs.inspect` | Read-only; existing artifacts only, no readiness or writers |
| List or structurally inspect cached Entity Editor files | `cli.entity_editor.inspect` | Read-only; no rebuild, locks, downloads, or raw values |
| Generate sync artifacts | `cli.core.export` | Filesystem write; no dry-run |
| Import staged artifacts | `cli.core.import` | WordPress write; backup required |
| List snapshot history | `cli.core.snapshots.list` | Read-only |
| Inspect proposal status | `cli.core.proposals.list` | Read-only by default; maintenance flags write/delete |
| Stage a proposal ZIP | `cli.core.proposals.upload` | Filesystem write; does not apply |
| Apply a proposal | `cli.core.proposals.apply` | WordPress/media write; backup required |
| List resolver rules | `cli.core.resolver_rules.list` | Read-only |
| Add/delete/import resolver rules | `cli.core.resolver_rules.mutate` | Persistent WordPress option write |

## Safe Routing

1. Prefer list or inspection records before selecting a write command.
2. Use `wp dbvc capabilities list` or `show` to retrieve canonical status and safety metadata before invoking another DBVC command. Use `doctor` to verify package ownership and runtime registration; a passing doctor is not authorization to invoke write capabilities.
3. For export/import semantics, load [Core Import And Export](core-import-export.md); the CLI records describe invocation boundaries, not the complete engine contract.
4. For proposal staging, review, media resolution, and apply gates, load [Proposals And Media](proposals-and-media.md).
5. Confirm the active plugin checkout with `wp cli cmd-dump` or equivalent before operational use. Repository-active does not mean live-verified.

## Capability Discovery Commands

```bash
wp dbvc capabilities list --safety=read_only
wp dbvc capabilities list --opportunity=candidate --format=json
wp dbvc capabilities show addon.bricks.drift --format=json
wp dbvc capabilities doctor
wp dbvc bricks doctor --format=json
wp dbvc bricks drift --package-id=<reviewed-package-id> --limit=25 --format=json
wp dbvc content-migration runs list --limit=25 --format=json
wp dbvc entity-editor list --max-age=900 --limit=25 --format=json
```

`list` supports exact status, category, safety, surface, opportunity, and priority filters plus free-text search. `show` retrieves one canonical record by stable ID. `doctor` checks the packaged manifest and snapshot, strict discovery ownership, active checkout, DBVC REST registration, and explicit add-on gates without dispatching route callbacks.

## Reviewed Candidate Queue

Current candidates are ranked and deliberately narrower than their owning mixed records:

1. `proposal.core.inspect` — medium priority, medium effort: exact-proposal readiness and bounded summaries only; raw/single-entity detail and decision pruning are excluded.

The former Bricks control-plane and Content Migration run-inspection candidates are covered by `cli.bricks.doctor` and `cli.content_migration.runs.inspect`. `configuration.core.portability` is deferred until the implemented provider/admin workflow and its still-proposed guide have one supported-contract authority. Load the remaining candidate record's `candidate_scope` and `excluded_operations` before planning implementation. A candidate is a reviewed backlog item, not permission to invoke its REST surface or build its CLI.

Do not treat `wp dbvc proposals list` as unconditionally read-only: `--recapture-snapshots` and `--cleanup-duplicates` cross into write/delete behavior. Do not use force-reapply or hash-bypass options without explicit review and rollback authority.

## Common Gap Checks

- Does a new REST/PHP capability need CLI parity?
- Can the request use an existing read-only list command before mutation?
- Is a true dry-run available, or only staging/preview?
- Which command flags change safety or process exit behavior?
- Are backup, uploads, and target-site identity verified?

## Load Next

- Command implementations: [`commands/class-wp-cli-commands.php`](../../../commands/class-wp-cli-commands.php)
- Bricks doctor and drift commands: [`commands/class-bricks-cli.php`](../../../commands/class-bricks-cli.php)
- Content Migration run commands: [`commands/class-content-migration-cli.php`](../../../commands/class-content-migration-cli.php)
- Entity Editor inspection command: [`commands/class-entity-editor-cli.php`](../../../commands/class-entity-editor-cli.php)
- Engine overview: [`docs/DBVC_ENGINE_INVENTORY.md`](../../DBVC_ENGINE_INVENTORY.md)
- Generated CLI views: [command signatures](../generated/index-by-command.md) and [surface index](../generated/index-by-surface.md)
- Add a new public command: update its implementation, manifest owner, discovery snapshot, applicable tests, and run the documented agent-docs checks.
