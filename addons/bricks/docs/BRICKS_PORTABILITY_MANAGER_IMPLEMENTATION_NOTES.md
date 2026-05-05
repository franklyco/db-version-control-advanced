# Bricks Portability Manager Implementation Notes

## MVP module location

- Runtime: `addons/bricks/portability/`
- Admin surface: `DBVC > Bricks > Portability`
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
- MVP does not do destructive delete-sync of target-only objects.
- Apply rebuilds updated option payloads in memory and writes once per affected option.
- Apply always snapshots affected options first and rollback restores the pre-apply snapshot.

## Current assumptions worth re-checking against live Bricks installs

- Breakpoints are wired to `bricks_breakpoints` if that option exists locally. This remains the biggest live-storage verification point.
- `bricks_global_elements` is intentionally excluded from MVP because it may overlap with `bricks_components`.
- Icon/font domains are excluded because portability may require additional asset/file transfer support beyond option payload transport.
