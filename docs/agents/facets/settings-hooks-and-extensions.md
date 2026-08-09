# DBVC Settings, Hooks, And Extensions Facet

Load this facet when changing configuration, adding an integration, or assessing whether a new CLI/API feature can reuse an existing hook. Exact option keys and hook occurrences are mapped through [`manifest.json`](../manifest.json) and the generated discovery snapshot.

## Primary Records

| Record | Ownership |
|---|---|
| `settings.core.import_export` | Core Configure UI and import/export settings |
| `hooks.core.lifecycle` | WordPress change and export-trigger extension points |
| `hooks.bricks.extensions` | Bricks-specific schemas, UI, audit, package, and job hooks |
| `planned.core.granular_options` | Planned per-option controls; not current behavior |

Behavior-specific settings are owned by their operational records, including `proposal.core.masking`, `media.core.resolver_rules`, `identity.core.entities`, `transport.core.sync_packages`, and `addon.bricks.control_plane`.

## Extension Rules

- Prefer an existing behavior-owned hook only when its payload and timing match the new use case.
- Filters can change filenames, schemas, masking, matching, limits, or authorization-adjacent behavior; cover contract changes with tests.
- Lifecycle listeners must guard against recursive export/update loops.
- A hook consumer may perform filesystem, WordPress, or remote writes even when the hook emitter itself is simple.
- Do not add a setting without assigning it to a capability record and documenting its operational consequence.

## New Surface Checklist

1. Identify the owning engine/add-on and risk boundary.
2. Decide whether PHP, hook, REST, CLI, or admin exposure is actually needed.
3. Add capability, nonce/authentication, validation, idempotency, and rollback handling as applicable.
4. Add tests and concise long-form documentation where the contract warrants it.
5. Run discovery; map the new discovery ID to an existing or new manifest record.
6. Rebuild indexes and run strict agent-docs validation.

## Load Next

- Core settings UI: [`admin/admin-page.php`](../../../admin/admin-page.php)
- Core lifecycle registrations: [`includes/hooks.php`](../../../includes/hooks.php)
- Import/export helpers: [`includes/functions.php`](../../../includes/functions.php)
- Bricks hook owners: [`addons/bricks/`](../../../addons/bricks/)
- All exact observed hooks/settings: [`generated/discovery-snapshot.json`](../generated/discovery-snapshot.json)
- Adding CLI parity: [CLI And Automation](cli-and-automation.md)
