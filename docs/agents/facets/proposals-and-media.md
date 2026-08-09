# DBVC Proposals And Media Facet

Load this facet for proposal intake, inspection, Accept/Keep decisions, masking, duplicates, media reconciliation, apply, or cleanup. Open the relevant record in [`manifest.json`](../manifest.json) before using a route or command.

## Review Sequence

| Stage | Records | Consequence |
|---|---|---|
| Stage | `proposal.core.intake`, `cli.core.proposals.upload` | Writes proposal files; does not apply to WordPress |
| Inspect | `proposal.core.inspect`, `cli.core.proposals.list` | Read-only unless CLI maintenance flags are used |
| Resolve media | `media.core.resolver_rules` | Preview is read-only; decisions/rules/downloads write |
| Mask | `proposal.core.masking` | Mutates staged artifacts; revert depends on snapshots |
| Decide | `proposal.core.decisions` | Persists review state, hashes, and snapshots |
| Apply | `proposal.core.apply`, `cli.core.proposals.apply` | Final WordPress/uploads mutation boundary |
| Clean up | `proposal.core.cleanup` | Deletes staged artifacts; does not undo an apply |

## Required Gates Before Apply

1. Verify the proposal package and target site belong together.
2. Inspect entity diffs, new-entity behavior, duplicate findings, and masking state.
3. Resolve all blocking media conflicts and validate environment-specific remap IDs.
4. Confirm Accept/Keep decisions and hash state.
5. Create and verify a recoverable database/uploads backup.
6. Use apply only with explicit mutation and rollback authority.

Global resolver rules affect later proposals, not just the current package. A successful stage, snapshot, or media preview is not authorization to apply.

## Common Gap Checks

- Is there a read-only inspection or preview route before this write?
- Does cleanup target staged files, WordPress data, or both?
- Are missing/new entities allowed for the selected post types?
- Does a resolver decision belong only to this proposal or globally?
- Can masking be reverted with the artifacts still present?
- Does CLI expose the same safety gate as REST/admin?

## Load Next

- Core proposal REST host: [`admin/class-admin-app.php`](../../../admin/class-admin-app.php)
- Apply/import engine: [`includes/class-sync-posts.php`](../../../includes/class-sync-posts.php)
- Media resolver: [`includes/Dbvc/Media/Resolver.php`](../../../includes/Dbvc/Media/Resolver.php)
- Media reconciler: [`includes/Dbvc/Media/Reconciler.php`](../../../includes/Dbvc/Media/Reconciler.php)
- Media design: [`docs/media-sync-design.md`](../../media-sync-design.md)
- Masking guide: [`docs/meta-masking.md`](../../meta-masking.md)
- Snapshot and identity dependencies: [Identity, Storage, And Observability](identity-storage-and-observability.md)
