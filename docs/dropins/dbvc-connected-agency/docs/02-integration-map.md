# Integration map and migration from the previous package

## Verified source anchor

The previously reviewed branch `codex/visual-editor-r5-later-arc` still resolves to `977888535a306989e48c5a34104fb9d101298cf3` when rechecked on 2026-09-16. Its `db-version-control.php` explicitly requires Bricks and Visual Editor files and calls their bootstrap methods. No generic add-on loader is assumed here.

Source: [plugin bootstrap](https://github.com/franklyco/db-version-control-main/blob/977888535a306989e48c5a34104fb9d101298cf3/db-version-control.php), [Visual Editor bootstrap](https://github.com/franklyco/db-version-control-main/blob/977888535a306989e48c5a34104fb9d101298cf3/addons/visual-editor/bootstrap.php), [Bricks add-on](https://github.com/franklyco/db-version-control-main/blob/977888535a306989e48c5a34104fb9d101298cf3/addons/bricks/bricks-addon.php).

The pinned main-repository plugin header/updater refers to the advanced repository and a `main` update branch. Confirm the actual local distribution path and update channel before deployment. Do not silently correct that existing behavior as part of this feature.

## Adapt the scaffold

`scaffold/overlay-manifest.json` maps each template to a proposed path relative to the DBVC root. All source templates use `.stub` suffixes so placing this package in `docs/dropins` does not install a second plugin or activate runtime code. A coding agent must review/adapt each mapping before creating a real source file.

1. Confirm no destination collisions and reconcile namespace/style/PHP requirements.
2. Introduce the tiny module bootstrap at its destination, then add a reviewed `require_once` in the existing DBVC bootstrap. It reads its gate and returns before runtime dependencies are loaded when off.
3. Do not add a `Plugin Name` header or call `register_activation_hook` on an add-on file. DBVC is the WordPress plugin; module enablement and plugin activation are different events.
4. Integrate settings with the current add-on Configure/admin patterns. Setting a gate requires capability and nonce validation; schema readiness is checked before registering the feature.
5. Replace fail-closed Runtime starter methods only as implementations and tests become available. Do not make empty methods advertise active behavior.
6. Shared contracts must have a single owned autoload mapping available to either enabled role. Add that narrow mapping to current loading conventions; never depend on Visual Editor's autoloader.
7. Register only implemented surfaces and run capability-documentation maintenance after integration.

## New settings proposed here

| Name | Purpose | Default | Transfer |
|---|---|---|---|
| `dbvc_addon_connected_environments_enabled` | Enable connector runtime | `'0'` | Never content/config sync |
| `dbvc_addon_agency_control_enabled` | Enable hub runtime | `'0'` | Never content/config sync |
| `DBVC_CONNECTED_EMERGENCY_DISABLE` | Process-level connector kill switch | undefined/false | Local config only |
| `DBVC_AGENCY_EMERGENCY_DISABLE` | Process-level hub kill switch | undefined/false | Local config only |

These names are proposed, not existing DBVC settings. Resolve collisions and compatibility locally. Store operational settings separately from secrets. No secret values, credential IDs that grant access, or enrollment tokens belong in fixtures, screenshots, commits, error logs, or exported configuration.

## Existing integration surfaces

| Surface | Planned change | Constraint |
|---|---|---|
| Main bootstrap | Two bounded includes | No fleet/Bricks work while off |
| Existing settings/admin owner | Two optional gates and role-aware navigation | Hub-only must not require connector activation |
| Existing change hooks | Capture hints through reviewed listeners | Export-independent coverage; don't invent a completed-change hook |
| Existing export/config providers | Exclude operational state | Verify both generic option export and configuration portability |
| Existing database owner | Module-owned migration orchestration | No DDL on public requests; no automatic data deletion |
| Bricks adapter | Snapshot individual managed objects | Preserve collection neighbors and ordering |
| Capability inventory | Source ownership, tests, safety and evidence | Use current manifest schema, not package JSON as a replacement |
| Release packaging | Include integrated source and required contract assets | Exclude this intake scaffold/tests and private fixtures from runtime ZIP where current build conventions allow |

## Superseded decisions

| Earlier handoff | This package |
|---|---|
| Create `agency-control-hub` repository/plugin | Add hub module inside current DBVC repository |
| Standalone hub plugin header and activation | Existing DBVC plugin lifecycle plus explicit module enable/upgrade |
| Separate cross-repo contract delivery | Single owned shared contract directory, still versioned on the wire |
| Read prior package alongside the repository | Drop this folder under `docs/dropins` and reconcile in place |

If local work already followed the earlier handoff, inventory it and move/adapt only after preserving its history and current state. Do not discard a partially implemented hub because this package uses different proposed paths.
