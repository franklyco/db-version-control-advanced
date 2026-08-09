# DBVC CLI And Automation Facet

Load this facet when an agent needs WP-CLI discovery, headless execution, exit-code behavior, or CLI parity analysis. The canonical command arguments, consequences, and evidence remain in [`manifest.json`](../manifest.json); search for the record ID before invoking anything.

## Start Here

For the complete source-observed command and argument list, open the generated [WP-CLI command index](../generated/index-by-command.md). Its signatures come from the discovery snapshot; the linked manifest records provide reviewed status and safety context.

| Need | Record | Boundary |
|---|---|---|
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
2. For export/import semantics, load [Core Import And Export](core-import-export.md); the CLI records describe invocation boundaries, not the complete engine contract.
3. For proposal staging, review, media resolution, and apply gates, load [Proposals And Media](proposals-and-media.md).
4. Confirm the active plugin checkout with `wp cli cmd-dump` or equivalent before operational use. Repository-active does not mean live-verified.

Do not treat `wp dbvc proposals list` as unconditionally read-only: `--recapture-snapshots` and `--cleanup-duplicates` cross into write/delete behavior. Do not use force-reapply or hash-bypass options without explicit review and rollback authority.

## Common Gap Checks

- Does a new REST/PHP capability need CLI parity?
- Can the request use an existing read-only list command before mutation?
- Is a true dry-run available, or only staging/preview?
- Which command flags change safety or process exit behavior?
- Are backup, uploads, and target-site identity verified?

## Load Next

- Command implementations: [`commands/class-wp-cli-commands.php`](../../../commands/class-wp-cli-commands.php)
- Engine overview: [`docs/DBVC_ENGINE_INVENTORY.md`](../../DBVC_ENGINE_INVENTORY.md)
- Generated CLI views: [command signatures](../generated/index-by-command.md) and [surface index](../generated/index-by-surface.md)
- Add a new public command: update its implementation, manifest owner, discovery snapshot, applicable tests, and run the documented agent-docs checks.
