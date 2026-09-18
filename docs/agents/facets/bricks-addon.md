# DBVC Bricks Add-on Facet

Load this facet for Bricks artifacts, drift, configuration, proposals, packages, connected sites, onboarding, signed commands, apply, or rollback. The add-on is source-loaded in this checkout; target-site activation and authenticated route availability still require runtime verification.

## Workflow Map

| Need | Record | Safety |
|---|---|---|
| Bounded status/schema/UI health | `cli.bricks.doctor` | Read-only; no stored diagnostics or telemetry |
| Full control plane/diagnostics/UI | `addon.bricks.control_plane` | Mixed; stored diagnostics read and UI telemetry writes |
| Artifact comparison | `addon.bricks.drift`, `cli.bricks.drift.inspect` | Read-only |
| Rules/profile distribution | `addon.bricks.configuration` | Local or remote write |
| Review state | `addon.bricks.proposals` | GET read-only; PATCH writes |
| Apply/restore/rollback | `addon.bricks.apply_restore` | WordPress write; backup required |
| Package lifecycle | `addon.bricks.packages` | Local and remote authority changes |
| Site identity/onboarding | `addon.bricks.connected_onboarding` | Local/remote identity writes |
| Signed orchestration | `addon.bricks.command_queue` | Consequence depends on command payload |
| Add-on hooks | `hooks.bricks.extensions` | Consumer-dependent |

## Safe Progression

1. Confirm add-on status, schema compatibility, and UI contract with `wp dbvc bricks doctor --format=json`.
2. Run a bounded drift scan/comparison before mutation; agents can use `wp dbvc bricks drift` with one reviewed stored package or local manifest.
3. Validate package provenance, connected-site identity, and target selection.
4. Create a recoverable restore point/database backup.
5. Review proposal/configuration changes.
6. Apply or distribute only with explicit local/remote write authority.

Signed command transport proves neither that a command is safe nor that an agent is authorized to execute it. Validate signature, target, expiry, replay protection, and command-specific consequence.

## Parity And Gap Questions

- Is the capability REST-only, or does core CLI provide a comparable path?
- Is the site a source, connected client, or remote package authority?
- Does the action change only local state or fan out to other sites?
- Is rollback supported for this exact artifact/configuration/package operation?
- Are schema and package versions supported by both ends?
- Is the requested behavior current, experimental, or only in planning docs?

## Load Next

- Runtime host/routes: [`addons/bricks/bricks-addon.php`](../../../addons/bricks/bricks-addon.php)
- Drift: [`addons/bricks/bricks-drift.php`](../../../addons/bricks/bricks-drift.php)
- Read-only doctor and drift CLI: [`commands/class-bricks-cli.php`](../../../commands/class-bricks-cli.php)
- Apply: [`addons/bricks/bricks-apply.php`](../../../addons/bricks/bricks-apply.php)
- Packages: [`addons/bricks/bricks-packages.php`](../../../addons/bricks/bricks-packages.php)
- Connected sites/onboarding: [`addons/bricks/bricks-connected-sites.php`](../../../addons/bricks/bricks-connected-sites.php) and [`addons/bricks/bricks-onboarding.php`](../../../addons/bricks/bricks-onboarding.php)
- Commands: [`addons/bricks/bricks-command-auth.php`](../../../addons/bricks/bricks-command-auth.php) and [`addons/bricks/bricks-command-queue.php`](../../../addons/bricks/bricks-command-queue.php)
- Current add-on tracker: [`addons/bricks/docs/BRICKS_ADDON_PROGRESS_TRACKER.md`](../../../addons/bricks/docs/BRICKS_ADDON_PROGRESS_TRACKER.md)
