# DBVC Core Import And Export Facet

Load this facet for JSON artifact generation, upload routing, posts/terms/options/menus/FSE transfer, filenames, masking at export, or sync-package transport. Use [`manifest.json`](../manifest.json) for current status and exact storage/safety metadata.

## Primary Records

| Record | Use |
|---|---|
| `engine.core.export` | Full, batch, differential, and chunked artifact generation |
| `engine.core.import` | JSON routing and WordPress import engines |
| `settings.core.import_export` | Object selection, filenames, paths, and domain transforms |
| `transport.core.sync_packages` | Sync upload/download, purge, backup download, and FTP window |
| `cli.core.export` | WP-CLI export invocation |
| `cli.core.import` | WP-CLI import invocation |
| `planned.core.universal_upload_intake` | Roadmap-only unified intake; not callable |
| `planned.core.granular_options` | Roadmap-only per-option controls; not callable |

## Safety Boundary

- Export writes the sync filesystem and may replace artifacts. Check masking and domain transformation before distributing a package.
- Direct import writes posts, terms, options, menus, metadata, and media. It is not a read-only validation path.
- Proposal intake is the preferred separation when review must occur before WordPress apply.
- Sync purge is destructive and not a rollback mechanism.
- A DBVC snapshot or manifest is not automatically a complete database/uploads backup.

## Data And Authority Questions

- Which object families are selected: posts/CPTs, terms, options groups, menus, or FSE?
- Which filename mode and portable UID rules will be used at the destination?
- Are new entities and automatic term creation allowed?
- Will bundled media, remote media, or automatic fallback be used?
- Must sensitive fields or source-domain URLs be transformed before export?
- Is the task staging artifacts, applying them, or both?

## Load Next

- Posts/options/menus/FSE engine: [`includes/class-sync-posts.php`](../../../includes/class-sync-posts.php)
- Taxonomy engine: [`includes/class-sync-taxonomies.php`](../../../includes/class-sync-taxonomies.php)
- Upload router: [`includes/class-import-router.php`](../../../includes/class-import-router.php)
- Options groups: [`includes/class-options-groups.php`](../../../includes/class-options-groups.php)
- Masking contract: [`docs/meta-masking.md`](../../meta-masking.md)
- Media transport: [`docs/media-sync-design.md`](../../media-sync-design.md)
- CLI invocation: [CLI And Automation](cli-and-automation.md)
- Human-reviewed apply: [Proposals And Media](proposals-and-media.md)
