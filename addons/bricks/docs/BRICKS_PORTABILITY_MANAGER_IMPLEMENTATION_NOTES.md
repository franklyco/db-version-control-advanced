# Bricks Portability Manager Implementation Notes

## MVP module location

- Runtime: `addons/bricks/portability/`
- Admin surface: dedicated Bricks-related submenu page `DBVC > ↳ Settings Portability`
- Page sections: internal `Workspace` and `History & Rollback` subtabs
- Review UX: collapsed domain summary at the top of the workbench plus modal row diff viewer on row click
- Review table: includes `Approved Action` visibility/filtering separate from the per-row action control
- Review table: includes warning-state filtering, a `Manual Decisions` filter option, and `Hide No Drift rows` enabled by default
- Review table: renders in client-side pages with a configurable rows-per-page selector to keep large review sessions responsive
- Review stats: live totals above the table for incoming package rows, current site rows, visible rows, and approved-action counts
- Review persistence: decisions can be saved back onto a review session as a draft and reloaded later
- Review session state: current-site compare freshness is surfaced as `Fresh` or `Stale`, sessions can be refreshed against the live site, and rollback state is surfaced after restore
- REST namespace: `dbvc/v1/bricks/portability/*`

## Supported MVP domains

- `settings` -> `bricks_global_settings`
- `color_palette` -> `bricks_color_palette`
- `global_classes` -> `bricks_global_classes`
- `global_variables` -> `bricks_global_variables`
- `pseudo_classes` -> `bricks_global_pseudo_classes`
- `theme_styles` -> `bricks_theme_styles`
- `components` -> `bricks_components`
- `breakpoints` -> `bricks_breakpoints` when present

## Related metadata included in domain review

- `bricks_global_classes_categories`
- `bricks_global_variables_categories`
- `bricks_breakpoints_last_generated` is classified as backup-only/derived, not canonical

## Current option classification decisions

- Canonical portable: the options mapped by the domain registry above
- Related metadata: categories options for classes and variables
- Backup-only: `bricks_global_classes_locked`, `bricks_global_classes_changes`, `bricks_global_classes_timestamp`, `bricks_global_classes_user`, `bricks_global_classes_trash`, `bricks_breakpoints_last_generated`
- Ignore for MVP: `bricks_remote_templates`, `bricks_panel_width`, `bricks_pinned_elements`
- Needs live verification before portability support: `bricks_global_elements`, `bricks_font_face_rules`, `bricks_icon_sets`, `bricks_custom_icons`

## Important implementation boundaries

- Compare is normalization-first, not raw JSON compare.
- Review UI is table/workbench based with bulk actions plus row overrides.
- CSS variable dependency warnings distinguish:
  - missing on current site but supplied by the incoming package
  - missing on both current site and incoming package
  - possibly external or outside the selected Bricks portability domains
- Category dependency warnings now also distinguish when related class/variable categories are missing on the current site but present in the incoming package, and the review modal can mark the related metadata row for incoming apply.
- MVP does not do destructive delete-sync of target-only objects.
- Apply rebuilds updated option payloads in memory and writes once per affected option.
- Apply always snapshots affected options first and rollback restores the pre-apply snapshot.
- Breakpoints now pass through a dedicated storage-shape verifier before export and before apply. Unrecognized breakpoint payloads are blocked instead of being blindly transported.

## Current assumptions worth re-checking against live Bricks installs

- Breakpoints are wired to `bricks_breakpoints` if that option exists locally. This remains the biggest live-storage verification point.
- `bricks_components` is treated as the canonical portable component domain for MVP.
- `bricks_global_elements` is treated as legacy storage. Newer Bricks versions can convert legacy global elements, but DBVC portability does not write that legacy option directly in MVP.
- Icon/font domains are excluded because portability may require additional asset/file transfer support beyond option payload transport.
