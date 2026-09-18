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
| Inspect one staged proposal structurally | `cli.proposals.inspect` | Read-only; conservative blockers and bounded sanitized rows, not authoritative apply readiness |
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
wp dbvc proposals show <reviewed-proposal-id> --format=json
wp dbvc proposals entities <reviewed-proposal-id> --limit=25 --format=json
```

`list` supports exact status, category, safety, surface, opportunity, and priority filters plus free-text search. `show` retrieves one canonical record by stable ID. `doctor` checks the packaged manifest and snapshot, strict discovery ownership, active checkout, DBVC REST registration, and explicit add-on gates without dispatching route callbacks.

## Reviewed Candidate Queue

There are currently no reviewed implementation candidates. The former Bricks control-plane, Content Migration run-inspection, and bounded proposal-summary candidates are covered by `cli.bricks.doctor`, `cli.content_migration.runs.inspect`, and `cli.proposals.inspect`. `configuration.core.portability` remains deferred until the implemented provider/admin workflow and its still-proposed guide have one supported-contract authority.

The next proposal boundary is safety remediation, not broader CLI parity: Phase 20 prevents entity-detail cleanup from pruning decisions without an authoritative trusted-snapshot diff, Phase 21 makes snapshot lookup non-creating while preserving explicit capture writes, Phase 22 makes backup-base lookup non-creating for proposal-list and manifest/payload readers, Phase 23 makes post/term current-state snapshot inspection read identity metadata without assigning it, and Phase 24 moves trusted stale-decision pruning behind an explicit administrator writer. The grouped `proposal.core.inspect` REST surface is now read-only. The next isolated boundary is **Explicit Decision-Pruning Operator Surface**; any future opportunity must be separately audited and recorded before it enters the candidate queue.

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
- Proposal structural-inspection commands: [`commands/class-proposal-inspection-cli.php`](../../../commands/class-proposal-inspection-cli.php)
- Entity Editor inspection command: [`commands/class-entity-editor-cli.php`](../../../commands/class-entity-editor-cli.php)
- Engine overview: [`docs/DBVC_ENGINE_INVENTORY.md`](../../DBVC_ENGINE_INVENTORY.md)
- Generated CLI views: [command signatures](../generated/index-by-command.md) and [surface index](../generated/index-by-surface.md)
- Add a new public command: update its implementation, manifest owner, discovery snapshot, applicable tests, and run the documented agent-docs checks.
