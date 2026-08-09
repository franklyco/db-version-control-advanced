# DBVC Identity, Storage, And Observability Facet

Load this facet when work depends on portable identity, DBVC tables, snapshots, jobs, backup manifests, activity logs, or Official Collections. The database table presence and current support status are defined by [`manifest.json`](../manifest.json), not by older prose alone.

## Primary Records

| Record | Role |
|---|---|
| `identity.core.entities` | Portable post/term UIDs and local object mappings |
| `storage.core.snapshots` | Snapshots, items, jobs, activity, and backup manifests |
| `observability.core.client_logs` | Client-error and sync activity logging |
| `admin.core.capability_landscape` | Administrator-only manifest, CLI, safety, and automation-gap table |
| `storage.core.official_collections` | Experimental persistence scaffold without public workflow |
| `cli.core.snapshots.list` | Read-only snapshot history |
| `planned.core.canonical_authority` | Roadmap-only signed authority workflow |

## Identity Rules

- Treat `vf_object_uid` and entity-registry bindings as cross-environment identity contracts.
- Verify live bindings before changing a UID, subtype, or local object mapping.
- A collision can redirect import/apply behavior to the wrong entity.
- Official Collections tables do not establish the planned canonical-authority policy, signing, or REST contract.

## Recovery And Evidence Rules

- Snapshot rows, manifests, and logs are operational evidence; confirm whether they contain enough data for the intended rollback.
- Database recovery may also require uploads and sync/proposal filesystem restoration.
- Avoid logging secrets or whole sensitive payloads.
- Verify retention and cleanup behavior before depending on historical records.

## Common Gap Checks

- Is the target object identified by portable UID, local ID, slug, or fallback matching?
- Is the requested snapshot a comparison artifact or a recoverable backup?
- Which of the eight DBVC tables owns the required state?
- Does the operation also touch WordPress options or filesystem artifacts?
- Is an “official” or “canonical” workflow actually implemented, or only scaffolded/planned?

## Load Next

- Database schema/helpers: [`includes/class-database.php`](../../../includes/class-database.php)
- Snapshot manager: [`includes/class-snapshot-manager.php`](../../../includes/class-snapshot-manager.php)
- Backup manager: [`includes/class-backup-manager.php`](../../../includes/class-backup-manager.php)
- Identity implementations: [`includes/class-sync-posts.php`](../../../includes/class-sync-posts.php) and [`includes/class-sync-taxonomies.php`](../../../includes/class-sync-taxonomies.php)
- Official Collections scaffold: [`includes/Dbvc/Official/Collections.php`](../../../includes/Dbvc/Official/Collections.php)
- Entity editing: [Entity Editor](entity-editor.md)
- Capability landscape implementation: [`admin/capability-landscape.php`](../../../admin/capability-landscape.php)
